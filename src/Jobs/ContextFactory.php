<?php
/**
 * Construcción del contexto lingüístico de un lote.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Jobs;

use PolyglotAI\Engines\EngineContext;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Options;

/**
 * Arma el contexto que acompaña a cada lote enviado al motor.
 *
 * Lo comparten los trabajos en segundo plano porque el glosario, los términos
 * que no se traducen y el contexto del sitio tienen que ser los mismos vaya lo
 * que vaya en el lote: si un camino enviara un glosario y otro no, el sitio
 * acabaría con dos traducciones distintas del mismo término.
 */
final class ContextFactory {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param Options          $options   Ajustes.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly Options $options
	) {}

	/**
	 * Contexto lingüístico para un idioma de destino.
	 *
	 * @param string $language Locale.
	 */
	public function for_language( string $language ): EngineContext {
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
