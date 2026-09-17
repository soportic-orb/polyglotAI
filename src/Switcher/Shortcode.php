<?php
/**
 * Selector de idioma como shortcode.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Switcher;

/**
 * Shortcode `[pgai_language_switcher]`.
 *
 * Solo traduce atributos de shortcode a opciones de presentación: los enlaces
 * los calcula SwitcherRenderer, igual que para el bloque, el menú y el selector
 * flotante.
 */
final class Shortcode {

	/**
	 * Constructor.
	 *
	 * @param SwitcherRenderer $renderer Pintado del selector.
	 */
	public function __construct( private readonly SwitcherRenderer $renderer ) {}

	/**
	 * Registra el shortcode.
	 */
	public function register(): void {
		add_shortcode( 'pgai_language_switcher', array( $this, 'render' ) );
	}

	/**
	 * Genera el selector.
	 *
	 * @param array<string, string>|string $attributes Atributos del shortcode.
	 * @return string HTML del selector.
	 */
	public function render( array|string $attributes = array() ): string {
		$attributes = shortcode_atts(
			array(
				'display'      => 'name',
				'layout'       => 'list',
				'hide_current' => 'no',
				'class'        => '',
			),
			is_array( $attributes ) ? $attributes : array(),
			'pgai_language_switcher'
		);

		return $this->renderer->render( $attributes );
	}
}
