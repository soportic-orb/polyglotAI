<?php
/**
 * Acceso al editor desde la barra de administración.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Editor;

use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Support\Capabilities;
use WP_Admin_Bar;

/**
 * Añade «Traducir página» a la barra de administración.
 *
 * Abre el editor sobre la página que se está viendo, que es como se espera que
 * se use: el traductor navega el sitio y traduce lo que ve.
 */
final class AdminBar {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param UrlConverter     $converter Conversor de rutas.
	 * @param EditMode         $mode      Modo de edición.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly UrlConverter $converter,
		private readonly EditMode $mode
	) {}

	/**
	 * Engancha el menú.
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_items' ), 90 );
	}

	/**
	 * Añade el botón y un submenú por idioma.
	 *
	 * @param WP_Admin_Bar $bar Barra de administración.
	 */
	public function add_items( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( Capabilities::TRANSLATE ) || is_admin() ) {
			return;
		}

		// Dentro de la propia vista previa el botón no aporta nada y estorba.
		if ( $this->mode->is_active() ) {
			return;
		}

		$translatable = $this->languages->translatable();

		if ( array() === $translatable ) {
			return;
		}

		$path = $this->converter->strip( $this->current_path() );

		$bar->add_node(
			array(
				'id'    => 'pgai-translate',
				'title' => __( 'Traducir página', 'polyglot-ai' ),
				'href'  => EditorPage::url( $path, $translatable[0] ),
				'meta'  => array( 'title' => __( 'Abrir el editor de traducciones para esta página', 'polyglot-ai' ) ),
			)
		);

		foreach ( $translatable as $language ) {
			$bar->add_node(
				array(
					'parent' => 'pgai-translate',
					'id'     => 'pgai-translate-' . $language->slug,
					'title'  => $language->label,
					'href'   => EditorPage::url( $path, $language ),
				)
			);
		}
	}

	/**
	 * Ruta de la petición en curso.
	 */
	private function current_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}

		$path = wp_parse_url( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );

		return is_string( $path ) && '' !== $path ? $path : '/';
	}
}
