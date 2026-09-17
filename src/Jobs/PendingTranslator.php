<?php
/**
 * Traducción en segundo plano de las cadenas pendientes.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Jobs;

use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Engines\EngineContext;
use PolyglotAI\Engines\EngineException;
use PolyglotAI\Engines\TranslationEngineInterface;
use PolyglotAI\Engines\TranslationRequest;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Options;
use PolyglotAI\Translation\DictionaryFactory;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StringType;

/**
 * Consume la cola de cadenas pendientes y las traduce por lotes.
 *
 * Es el paso que cierra el ADR-13: la petición del visitante solo anota lo que
 * falta, y el gasto y la espera ocurren aquí, fuera de esa petición.
 */
final class PendingTranslator {

	/** Nombre de la acción programada. */
	public const HOOK = 'pgai_translate_pending';

	/** Grupo de Action Scheduler. */
	private const GROUP = 'polyglot-ai';

	/**
	 * Constructor.
	 *
	 * @param TranslationEngineInterface $engine       Motor de traducción.
	 * @param TranslationRepository      $translations Repositorio de traducciones.
	 * @param ApiLogRepository           $log          Registro de consumo.
	 * @param LanguageRegistry           $languages    Idiomas del sitio.
	 * @param Options                    $options      Ajustes.
	 */
	public function __construct(
		private readonly TranslationEngineInterface $engine,
		private readonly TranslationRepository $translations,
		private readonly ApiLogRepository $log,
		private readonly LanguageRegistry $languages,
		private readonly Options $options
	) {}

	/**
	 * Engancha la acción programada.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Traduce un lote de cadenas pendientes de un idioma.
	 *
	 * @param string $language Locale de destino.
	 * @return int Cadenas traducidas con éxito.
	 */
	public function run( string $language ): int {
		$target = $this->languages->by_locale( $language );

		if ( null === $target || ! $target->active ) {
			return 0;
		}

		if ( $this->over_budget() ) {
			/**
			 * Se dispara cuando el tope mensual de tokens detiene la traducción.
			 *
			 * @since 0.1.0
			 *
			 * @param string $language Locale de destino.
			 */
			do_action( 'pgai_budget_exhausted', $language );

			return 0;
		}

		$pending = $this->translations->pending( $language, $this->engine->max_batch_size() );

		if ( array() === $pending ) {
			return 0;
		}

		$requests = array();

		foreach ( $pending as $row ) {
			$requests[] = new TranslationRequest(
				(string) $row['source_id'],
				$row['original'],
				StringType::tryFrom( $row['type'] ) ?? StringType::Text,
				$row['context']
			);
		}

		try {
			$result = $this->engine->translate( $requests, $this->context( $language ) );
		} catch ( EngineException $error ) {
			$this->log->record(
				$this->engine->id(),
				(string) $this->options->get( 'model' ),
				$language,
				count( $requests ),
				new \PolyglotAI\Engines\Usage(),
				'error',
				$error->getMessage()
			);

			return 0;
		}

		$saved = 0;

		foreach ( $result->translations as $id => $translation ) {
			if ( $this->translations->save(
				(int) $id,
				$language,
				$translation,
				Status::Automatic,
				false,
				$this->engine->id(),
				(string) $this->options->get( 'model' )
			) ) {
				++$saved;
			}
		}

		foreach ( $result->failures as $id => $reason ) {
			$this->translations->mark_error( (int) $id, $language, $reason );
		}

		$this->log->record(
			$this->engine->id(),
			(string) $this->options->get( 'model' ),
			$language,
			count( $requests ),
			$result->usage
		);

		// Las traducciones nuevas invalidan los diccionarios ya cacheados.
		DictionaryFactory::invalidate();

		// Si queda más pendiente se encadena otro lote, en vez de traducirlo
		// todo aquí: así una tarea no se eterniza ni topa con el tiempo máximo
		// de ejecución.
		if ( array() !== $this->translations->pending( $language, 1 ) ) {
			$this->schedule( $language );
		}

		return $saved;
	}

	/**
	 * Programa una pasada para un idioma, sin duplicarla.
	 *
	 * @param string $language Locale.
	 */
	public function schedule( string $language ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array( $language ), self::GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::HOOK, array( $language ), self::GROUP );
	}

	/**
	 * Si se ha alcanzado el tope mensual de tokens.
	 *
	 * El tope corta también el gasto que provoca el tráfico de visitantes, no
	 * solo el de las traducciones lanzadas a mano.
	 */
	public function over_budget(): bool {
		$limit = (int) $this->options->get( 'monthly_token_limit', 0 );

		if ( $limit <= 0 ) {
			return false;
		}

		$usage = $this->log->usage_since( gmdate( 'Y-m-01 00:00:00' ) );

		// La lectura de caché no se suma: su precio es una fracción del token de
		// entrada normal y contarla como tal falsearía el tope.
		return ( $usage['input'] + $usage['output'] + $usage['cache_creation'] ) >= $limit;
	}

	/**
	 * Contexto lingüístico para un idioma de destino.
	 *
	 * @param string $language Locale.
	 */
	private function context( string $language ): EngineContext {
		$source = $this->languages->default_language();
		$target = $this->languages->by_locale( $language ) ?? $source;

		return new EngineContext(
			$source->locale,
			$target->locale,
			$source->label,
			$target->label,
			$target->formality,
			(string) $this->options->get( 'site_context', '' ),
			$this->options->glossary( $language ),
			$this->options->do_not_translate()
		);
	}
}
