<?php
/**
 * Guion de traducción de contenido dinámico.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Frontend;

use PolyglotAI\Rest\Controller;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Support\Options;

/**
 * Carga en el frontal el observador que traduce lo que aparece después.
 *
 * Solo se carga cuando hace falta: en el idioma por defecto no hay nada que
 * traducir, y si el sitio no tiene contenido dinámico se puede desactivar desde
 * los ajustes para ahorrarse el guion entero.
 */
final class DynamicScript {

	/**
	 * Constructor.
	 *
	 * @param RequestContext $request Contexto de la petición.
	 * @param Options        $options Ajustes.
	 */
	public function __construct(
		private readonly RequestContext $request,
		private readonly Options $options
	) {}

	/**
	 * Engancha la carga del guion.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Encola el guion si procede.
	 */
	public function enqueue(): void {
		if ( $this->request->is_default() || ! (bool) $this->options->get( 'dynamic', true ) ) {
			return;
		}

		if ( ! is_readable( PGAI_DIR . 'assets/build/dynamic.js' ) ) {
			return;
		}

		wp_enqueue_script( 'pgai-dynamic', PGAI_URL . 'assets/build/dynamic.js', array(), PGAI_VERSION, true );

		wp_add_inline_script(
			'pgai-dynamic',
			'window.pgaiDynamic = ' . wp_json_encode(
				array(
					'restUrl'  => esc_url_raw( rest_url( Controller::NAMESPACE ) ),
					'language' => $this->request->language()->locale,
				)
			) . ';',
			'before'
		);
	}
}
