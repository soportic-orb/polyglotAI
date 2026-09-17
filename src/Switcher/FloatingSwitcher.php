<?php
/**
 * Selector de idioma flotante.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Switcher;

use PolyglotAI\Support\Options;

/**
 * Selector fijo en una esquina, para temas que no dejan sitio donde ponerlo.
 *
 * Viene desactivado: un elemento fijo tapa contenido y compite con los avisos
 * de cookies y los chats de soporte, que suelen ocupar la misma esquina. Quien
 * lo necesite lo activa; quien tenga dónde poner el bloque o el shortcode, no
 * lo verá nunca.
 */
final class FloatingSwitcher {

	/** Clave de los ajustes. */
	public const OPTION_KEY = 'floating_switcher';

	/**
	 * Constructor.
	 *
	 * @param SwitcherRenderer $renderer Pintado del selector.
	 * @param Options          $options  Ajustes.
	 */
	public function __construct(
		private readonly SwitcherRenderer $renderer,
		private readonly Options $options
	) {}

	/**
	 * Engancha el selector al pie.
	 */
	public function register(): void {
		add_action( 'wp_footer', array( $this, 'render' ), 100 );
	}

	/**
	 * Pinta el selector.
	 */
	public function render(): void {
		if ( ! (bool) $this->options->get( self::OPTION_KEY, false ) ) {
			return;
		}

		$html = $this->renderer->render(
			array(
				'display' => (string) $this->options->get( 'floating_switcher_display', 'name' ),
				'layout'  => 'dropdown',
			)
		);

		if ( '' === $html ) {
			return;
		}

		printf(
			'<div class="pgai-switcher-floating">%s</div>',
			// El HTML lo genera el renderizador, que ya escapa cada pieza.
			$html // phpcs:ignore WordPress.Security.EscapeOutputNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
}
