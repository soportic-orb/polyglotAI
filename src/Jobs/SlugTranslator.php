<?php
/**
 * Traducción en segundo plano de los slugs pendientes.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Jobs;

use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Database\SlugRecord;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Engines\EngineException;
use PolyglotAI\Engines\TranslationEngineInterface;
use PolyglotAI\Engines\TranslationRequest;
use PolyglotAI\Engines\Usage;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Options;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StringType;

/**
 * Consume la cola de slugs pendientes y los traduce por lotes.
 *
 * Va aparte de la traducción de cadenas porque un slug no es una cadena
 * cualquiera:
 *
 * - **Se envía como frase, no como slug.** Pedirle a un modelo que traduzca
 *   «mi-primera-entrada» es pedirle que adivine; se le manda «mi primera
 *   entrada» y lo que vuelve se convierte en slug con `sanitize_title()`, que
 *   es además quien se encarga de los acentos y de la puntuación.
 * - **El resultado se normaliza y se desambigua**, cosa que no aplica a
 *   ninguna otra cadena del sitio.
 * - **Un slug traducido cambia URLs.** Por eso solo se escribe sobre lo que
 *   nadie ha tocado a mano, como manda el ADR-07, y el repositorio se ocupa de
 *   que no haya dos iguales en un idioma.
 */
final class SlugTranslator {

	/** Nombre de la acción programada. */
	public const HOOK = 'pgai_translate_slugs';

	/** Grupo de Action Scheduler. */
	private const GROUP = 'polyglot-ai';

	/**
	 * Constructor.
	 *
	 * @param TranslationEngineInterface $engine    Motor de traducción.
	 * @param SlugRepository             $slugs     Almacén de slugs.
	 * @param ApiLogRepository           $log       Registro de consumo.
	 * @param LanguageRegistry           $languages Idiomas del sitio.
	 * @param Options                    $options   Ajustes.
	 * @param Budget                     $budget    Tope mensual de consumo.
	 * @param ContextFactory             $contexts  Contexto lingüístico.
	 */
	public function __construct(
		private readonly TranslationEngineInterface $engine,
		private readonly SlugRepository $slugs,
		private readonly ApiLogRepository $log,
		private readonly LanguageRegistry $languages,
		private readonly Options $options,
		private readonly Budget $budget,
		private readonly ContextFactory $contexts
	) {}

	/**
	 * Engancha la acción programada.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Traduce un lote de slugs pendientes de un idioma.
	 *
	 * @param string $language Locale de destino.
	 * @return int Slugs traducidos con éxito.
	 */
	public function run( string $language ): int {
		$target = $this->languages->by_locale( $language );

		if ( null === $target || ! $target->active ) {
			return 0;
		}

		if ( $this->budget->exhausted() ) {
			/** This action is documented in src/Jobs/PendingTranslator.php */
			do_action( 'pgai_budget_exhausted', $language );

			return 0;
		}

		$pending = $this->slugs->pending( $language, $this->engine->max_batch_size() );

		if ( array() === $pending ) {
			return 0;
		}

		$requests = array();
		$records  = array();

		foreach ( $pending as $index => $record ) {
			$id = (string) $index;

			$records[ $id ] = $record;
			$requests[]     = new TranslationRequest(
				$id,
				$this->phrase( $record->original_slug ),
				StringType::Slug,
				$record->object_type
			);
		}

		try {
			$result = $this->engine->translate( $requests, $this->contexts->for_language( $language ) );
		} catch ( EngineException $error ) {
			$this->log->record(
				$this->engine->id(),
				(string) $this->options->get( 'model' ),
				$language,
				count( $requests ),
				new Usage(),
				'error',
				$error->getMessage()
			);

			return 0;
		}

		$saved = 0;

		foreach ( $result->translations as $id => $translation ) {
			$record = $records[ (string) $id ] ?? null;

			if ( null === $record ) {
				continue;
			}

			$slug = sanitize_title( $translation );

			if ( '' === $slug ) {
				// Una traducción que no deja nada utilizable no se guarda: es
				// preferible seguir sirviendo el slug original.
				continue;
			}

			if ( $this->save( $record, $slug ) ) {
				++$saved;
			}
		}

		$this->log->record(
			$this->engine->id(),
			(string) $this->options->get( 'model' ),
			$language,
			count( $requests ),
			$result->usage
		);

		// Si queda más pendiente se encadena otro lote en vez de traducirlo todo
		// aquí: así una tarea no se eterniza ni topa con el tiempo máximo de
		// ejecución.
		if ( array() !== $this->slugs->pending( $language, 1 ) ) {
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
	 * Convierte un slug en la frase que se manda a traducir.
	 *
	 * @param string $slug Slug original.
	 */
	private function phrase( string $slug ): string {
		return trim( str_replace( array( '-', '_' ), ' ', rawurldecode( $slug ) ) );
	}

	/**
	 * Guarda el slug traducido.
	 *
	 * @param SlugRecord $record Registro pendiente.
	 * @param string     $slug   Slug traducido y normalizado.
	 */
	private function save( SlugRecord $record, string $slug ): bool {
		return $this->slugs->save(
			new SlugRecord(
				$record->object_type,
				$record->object_subtype,
				$record->object_id,
				$record->language,
				$record->original_slug,
				$slug,
				Status::Automatic
			)
		);
	}
}
