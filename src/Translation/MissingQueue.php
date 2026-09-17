<?php
/**
 * Registro de cadenas pendientes de traducir.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Detection\BotDetector;
use PolyglotAI\Jobs\PendingTranslator;
use PolyglotAI\Support\Options;

/**
 * Anota las cadenas que una página necesita y todavía no tienen traducción.
 *
 * Implementa el ADR-13: encolar es una inserción barata, no una llamada a la
 * API. La traducción la hace después una tarea en segundo plano, de modo que la
 * carga del visitante nunca se bloquea.
 */
final class MissingQueue {

	/**
	 * Constructor.
	 *
	 * @param SourceRepository      $sources      Repositorio de cadenas originales.
	 * @param TranslationRepository $translations Repositorio de traducciones.
	 * @param BotDetector           $bots         Detector de robots.
	 * @param Options               $options      Ajustes.
	 */
	public function __construct(
		private readonly SourceRepository $sources,
		private readonly TranslationRepository $translations,
		private readonly BotDetector $bots,
		private readonly Options $options
	) {}

	/**
	 * Anota un conjunto de cadenas sin traducir.
	 *
	 * @param array<string, array{unit:\PolyglotAI\Html\ExtractedString, hash:string}> $missing  Cadenas que faltan.
	 * @param string                                                                   $language Locale de destino.
	 */
	public function enqueue( array $missing, string $language ): void {
		if ( array() === $missing || ! (bool) $this->options->get( 'realtime', true ) ) {
			return;
		}

		// Un robot no encola nunca: rastrear el sitio entero multiplicaría el
		// gasto sin que nadie llegue a leer esas traducciones.
		if ( $this->bots->is_bot() ) {
			return;
		}

		$limit   = max( 1, (int) $this->options->get( 'realtime_max_queued', 50 ) );
		$missing = array_slice( $missing, 0, $limit, true );

		$this->record( $missing, $language );
		$this->schedule( $language );
	}

	/**
	 * Registra unas cadenas como pendientes, sin programar nada.
	 *
	 * Lo usa el editor visual: un traductor tiene que poder corregir cualquier
	 * cadena de la página, incluida una que nadie ha visto todavía, y eso exige
	 * que exista en el diccionario. A diferencia de enqueue(), esto no depende
	 * del interruptor de traducción en segundo plano ni del filtro de bots:
	 * aquí no se gasta nada, solo se anota.
	 *
	 * @param array<string, array{unit:\PolyglotAI\Html\ExtractedString, hash:string}> $strings  Cadenas.
	 * @param string                                                                   $language Locale de destino.
	 * @return int[] Identificadores de las cadenas registradas.
	 */
	public function record( array $strings, string $language ): array {
		$source_ids = array();

		foreach ( $strings as $hash => $entry ) {
			$unit = $entry['unit'];

			$source_ids[] = $this->sources->remember(
				$hash,
				$unit->value,
				$unit->type,
				$unit->context
			);
		}

		$this->translations->mark_pending( $source_ids, $language );

		return $source_ids;
	}

	/**
	 * Programa la tarea que traduce lo pendiente.
	 *
	 * @param string $language Locale.
	 */
	private function schedule( string $language ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			// Sin Action Scheduler la traducción de lo pendiente se lanza desde
			// el panel. No se llama a la API dentro de la petición del visitante.
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( PendingTranslator::HOOK, array( $language ), 'polyglot-ai' ) ) {
			return;
		}

		as_enqueue_async_action( PendingTranslator::HOOK, array( $language ), 'polyglot-ai' );
	}
}
