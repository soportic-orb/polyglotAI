<?php
/**
 * Condiciones para no procesar la salida.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

use PolyglotAI\Support\Options;

/**
 * Decide si una petición debe pasar por el traductor.
 *
 * Es el primer sitio donde mirar cuando un constructor de páginas se rompe:
 * casi siempre es que falta su modo de edición en esta lista.
 */
final class BailConditions {

	/**
	 * Parámetros de consulta que indican que un constructor está en modo edición.
	 *
	 * Traducir la salida en ese modo rompe el editor, porque el constructor
	 * compara el HTML con su propia estructura guardada.
	 *
	 * @var string[]
	 */
	private const BUILDER_PARAMETERS = array(
		'et_fb',                    // Divi.
		'elementor-preview',        // Elementor.
		'fl_builder',               // Beaver Builder.
		'bricks',                   // Bricks.
		'vc_action',                // WPBakery.
		'tve',                      // Thrive Architect.
		'ct_builder',               // Oxygen.
		'customize_changeset_uuid', // Personalizador.
		'brizy-edit',               // Brizy.
		'siteorigin_panels_live_editor',
	);

	/**
	 * Constructor.
	 *
	 * @param Options $options Ajustes.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Si la petición actual debe procesarse.
	 */
	public function should_process(): bool {
		$process = ! $this->is_excluded();

		/**
		 * Permite decidir si se traduce la salida de esta petición.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $process Si se procesa.
		 */
		return (bool) apply_filters( 'pgai_should_process_output', $process );
	}

	/**
	 * Si alguna condición impide procesar.
	 */
	private function is_excluded(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}

		if ( $this->is_builder_editing() ) {
			return true;
		}

		// La vista previa de un borrador es contenido sin publicar. Procesarla
		// lo guardaría en pgai_sources y la tarea de fondo acabaría mandándolo
		// a la API (ADR-13), de modo que un anuncio con fecha o una página de
		// producto sin estrenar saldrían del sitio antes de estar publicados.
		// Además cambia en cada revisión, así que se pagaría por traducir
		// borradores que luego se tiran.
		if ( is_preview() || is_customize_preview() ) {
			return true;
		}

		return $this->is_excluded_path();
	}

	/**
	 * Si un constructor de páginas está en modo edición.
	 */
	private function is_builder_editing(): bool {
		foreach ( self::BUILDER_PARAMETERS as $parameter ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $parameter ] ) ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';

		return in_array( $action, array( 'elementor', 'bricks_render_element' ), true );
	}

	/**
	 * Si la ruta está excluida en los ajustes.
	 *
	 * Por defecto incluye las rutas con datos personales: cuenta, carrito y
	 * finalización de compra no salen nunca hacia la API (ADR-12).
	 */
	private function is_excluded_path(): bool {
		$path = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_parse_url( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
			: '';

		if ( '' === $path ) {
			return false;
		}

		/** @var string[] $excluded */
		$excluded = (array) $this->options->get( 'excluded_paths', array() );

		foreach ( $excluded as $prefix ) {
			if ( '' !== $prefix && str_contains( $path, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
