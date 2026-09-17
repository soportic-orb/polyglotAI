<?php
/**
 * Reglas que marcan contenido como no traducible.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

use WP_HTML_Tag_Processor;

/**
 * Decide si un elemento y su contenido quedan fuera de la traducción.
 *
 * La exclusión es hereditaria: si un elemento está excluido, todo lo que hay
 * dentro también lo está.
 */
final class ExclusionRules {

	/**
	 * Constructor.
	 *
	 * @param string[] $classes    Clases que excluyen el elemento.
	 * @param string[] $attributes Atributos cuya mera presencia excluye el elemento.
	 */
	public function __construct(
		private readonly array $classes = array( 'notranslate' ),
		private readonly array $attributes = array( 'data-no-translation', 'data-pgai-skip' )
	) {}

	/**
	 * Si el elemento sobre el que está posicionado el analizador está excluido.
	 *
	 * @param WP_HTML_Tag_Processor    $processor Analizador posicionado en la etiqueta.
	 * @param array<string, true>|null $present Atributos presentes en la
	 *                                          etiqueta, en minúsculas. Pasarlo
	 *                                          evita consultar atributos que la
	 *                                          etiqueta no tiene, que es el caso
	 *                                          de casi todas.
	 */
	public function excludes( WP_HTML_Tag_Processor $processor, ?array $present = null ): bool {
		if ( null !== $present && ! $this->may_exclude( $present ) ) {
			return false;
		}

		foreach ( $this->classes as $class ) {
			if ( true === $processor->has_class( $class ) ) {
				return true;
			}
		}

		foreach ( $this->attributes as $attribute ) {
			if ( null !== $processor->get_attribute( $attribute ) ) {
				return true;
			}
		}

		// El atributo estándar de HTML para esto.
		$translate = $processor->get_attribute( 'translate' );

		return is_string( $translate ) && 'no' === strtolower( $translate );
	}

	/**
	 * Si la etiqueta lleva algún atributo capaz de excluirla.
	 *
	 * @param array<string, true> $present Atributos presentes, en minúsculas.
	 */
	private function may_exclude( array $present ): bool {
		if ( isset( $present['class'] ) || isset( $present['translate'] ) ) {
			return true;
		}

		foreach ( $this->attributes as $attribute ) {
			if ( isset( $present[ $attribute ] ) ) {
				return true;
			}
		}

		return false;
	}
}
