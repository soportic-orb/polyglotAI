<?php
/**
 * Gestor de cadenas.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Admin;

use PolyglotAI\Languages\Language;
use PolyglotAI\Rest\Controller;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Support\TranslatorLanguages;

/**
 * Pantalla que lista todas las cadenas del sitio.
 *
 * Cuelga del menú del plugin y no del editor visual porque responde a otra
 * pregunta: el editor es «arregla lo que veo en esta página» y esto es «dónde
 * está la cadena que busco».
 */
final class StringsPage {

	/** Slug de la pantalla. */
	public const SLUG = 'polyglot-ai-strings';

	/**
	 * Constructor.
	 *
	 * @param TranslatorLanguages $access Idiomas asignados a cada traductor.
	 */
	public function __construct( private readonly TranslatorLanguages $access ) {}

	/**
	 * Engancha la pantalla.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Añade la pantalla al menú del plugin.
	 */
	public function add_page(): void {
		add_submenu_page(
			'polyglot-ai',
			__( 'Cadenas', 'polyglot-ai' ),
			__( 'Cadenas', 'polyglot-ai' ),
			Capabilities::TRANSLATE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Carga los recursos de la pantalla.
	 *
	 * @param string $hook Identificador de la pantalla actual.
	 */
	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		$asset_file = PGAI_DIR . 'assets/build/manager.asset.php';

		/** @var array{dependencies: string[], version: string} $asset */
		$asset = is_readable( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
				'version'      => PGAI_VERSION,
			);

		wp_enqueue_script(
			'pgai-manager',
			PGAI_URL . 'assets/build/manager.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'pgai-manager', PGAI_URL . 'assets/build/style-manager.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( 'pgai-manager', 'rtl', 'replace' );

		wp_set_script_translations( 'pgai-manager', 'polyglot-ai' );

		wp_add_inline_script(
			'pgai-manager',
			'window.pgaiManager = ' . wp_json_encode( $this->boot_data() ) . ';',
			'before'
		);
	}

	/**
	 * Pinta el contenedor de la aplicación.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::TRANSLATE ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Cadenas', 'polyglot-ai' ) . '</h1>';

		if ( is_readable( PGAI_DIR . 'assets/build/manager.js' ) ) {
			echo '<div id="pgai-manager-root"></div>';
		} else {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Faltan los recursos compilados. Ejecuta «npm install && npm run build» en la carpeta del plugin.',
					'polyglot-ai'
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Datos iniciales que necesita la aplicación.
	 *
	 * @return array<string, mixed>
	 */
	private function boot_data(): array {
		$languages = array_map(
			static fn ( Language $language ): array => array(
				'locale' => $language->locale,
				'label'  => $language->label,
			),
			$this->access->for_user( get_current_user_id() )
		);

		return array(
			'restUrl'   => esc_url_raw( rest_url( Controller::NAMESPACE ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'languages' => array_values( $languages ),
			'can'       => array(
				'review'  => current_user_can( Capabilities::REVIEW ),
				'autoRun' => current_user_can( Capabilities::RUN_AUTO ),
			),
		);
	}
}
