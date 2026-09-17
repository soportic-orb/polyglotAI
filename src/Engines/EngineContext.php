<?php
/**
 * Contexto lingüístico de una traducción.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines;

/**
 * Todo lo que condiciona cómo debe traducirse un lote.
 *
 * Es inmutable y se construye una vez por idioma de destino: es también la
 * unidad de caché del prompt del sistema.
 */
final class EngineContext {

	/**
	 * Constructor.
	 *
	 * @param string                $source_locale Locale de origen (es_ES).
	 * @param string                $target_locale Locale de destino (en_US).
	 * @param string                $source_label  Nombre del idioma de origen.
	 * @param string                $target_label  Nombre del idioma de destino.
	 * @param string                $formality     Tratamiento: informal, formal o neutral.
	 * @param string                $site_context  Descripción del sitio escrita
	 *                                             por el administrador.
	 * @param array<string, string> $glossary      Término de origen => traducción obligatoria.
	 * @param string[]              $do_not_translate Términos que se dejan tal cual.
	 * @param string[]              $nearby        Cadenas vecinas de la misma
	 *                                             página, solo como contexto.
	 */
	public function __construct(
		public readonly string $source_locale,
		public readonly string $target_locale,
		public readonly string $source_label,
		public readonly string $target_label,
		public readonly string $formality = 'neutral',
		public readonly string $site_context = '',
		public readonly array $glossary = array(),
		public readonly array $do_not_translate = array(),
		public readonly array $nearby = array()
	) {}

	/**
	 * Copia con otras cadenas vecinas.
	 *
	 * Las vecinas cambian en cada lote, así que van fuera del prompt cacheado.
	 *
	 * @param string[] $nearby Cadenas vecinas.
	 */
	public function with_nearby( array $nearby ): self {
		return new self(
			$this->source_locale,
			$this->target_locale,
			$this->source_label,
			$this->target_label,
			$this->formality,
			$this->site_context,
			$this->glossary,
			$this->do_not_translate,
			$nearby
		);
	}

	/**
	 * Clave que identifica el prompt cacheable de este contexto.
	 */
	public function cache_key(): string {
		return md5(
			implode(
				"\x1f",
				array(
					$this->source_locale,
					$this->target_locale,
					$this->formality,
					$this->site_context,
					wp_json_encode( $this->glossary ) ?: '',
					implode( ',', $this->do_not_translate ),
				)
			)
		);
	}
}
