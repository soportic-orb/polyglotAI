<?php
/**
 * Pantalla del editor visual.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Editor;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Rest\Controller;
use PolyglotAI\Support\Capabilities;

/**
 * Aloja la aplicación del editor visual.
 *
 * Es una pantalla del escritorio sin menú visible: se llega a ella desde el
 * botón de la barra de administración o desde el listado de entradas, siempre
 * con la URL que se quiere traducir.
 */
final class EditorPage {

	/** Slug de la pantalla. */
	public const SLUG = 'polyglot-ai-editor';

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param EditMode         $mode      Modo de edición.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly EditMode $mode
	) {}

	/**
	 * Engancha la pantalla.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * URL del editor para una ruta y un idioma.
	 *
	 * @param string        $path     Ruta del sitio a traducir.
	 * @param Language|null $language Idioma, o el primero disponible.
	 */
	public static function url( string $path = '/', ?Language $language = null ): string {
		$arguments = array(
			'page'     => self::SLUG,
			'pgai-url' => rawurlencode( $path ),
		);

		if ( null !== $language ) {
			$arguments['pgai-lang'] = $language->locale;
		}

		return add_query_arg( $arguments, admin_url( 'admin.php' ) );
	}

	/**
	 * Registra la pantalla, oculta del menú.
	 */
	public function add_page(): void {
		add_submenu_page(
			'',
			__( 'Editor de traducciones', 'polyglot-ai' ),
			__( 'Editor de traducciones', 'polyglot-ai' ),
			Capabilities::TRANSLATE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Carga la aplicación solo en esta pantalla.
	 *
	 * @param string $hook Identificador de la pantalla actual.
	 */
	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		$asset_file = PGAI_DIR . 'assets/build/editor.asset.php';

		/** @var array{dependencies: string[], version: string} $asset */
		$asset = is_readable( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
				'version'      => PGAI_VERSION,
			);

		wp_enqueue_script(
			'pgai-editor',
			PGAI_URL . 'assets/build/editor.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'pgai-editor', PGAI_URL . 'assets/build/style-editor.css', array( 'wp-components' ), $asset['version'] );

		// El build genera la variante RTL; WordPress la sirve sola cuando el
		// idioma del escritorio se escribe de derecha a izquierda.
		wp_style_add_data( 'pgai-editor', 'rtl', 'replace' );

		wp_set_script_translations( 'pgai-editor', 'polyglot-ai' );

		wp_add_inline_script(
			'pgai-editor',
			'window.pgaiEditor = ' . wp_json_encode( $this->boot_data() ) . ';',
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

		if ( ! is_readable( PGAI_DIR . 'assets/build/editor.js' ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Faltan los recursos compilados del editor. Ejecuta «npm install && npm run build» en la carpeta del plugin.',
					'polyglot-ai'
				)
			);

			return;
		}

		echo '<div id="pgai-editor-root" class="pgai-editor-root"></div>';
	}

	/**
	 * Datos iniciales que necesita la aplicación.
	 *
	 * @return array<string, mixed>
	 */
	private function boot_data(): array {
		$languages = array();

		foreach ( $this->languages->translatable() as $language ) {
			$languages[] = array(
				'locale'    => $language->locale,
				'slug'      => $language->slug,
				'label'     => $language->label,
				'rtl'       => $language->rtl,
				'published' => $language->published,
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested_path = isset( $_GET['pgai-url'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['pgai-url'] ) ) : '/';

		// Del valor recibido solo interesa la ruta: así una URL absoluta a otro
		// sitio no puede colarse como destino de la vista previa.
		$path = (string) wp_parse_url( rawurldecode( $requested_path ), PHP_URL_PATH );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['pgai-lang'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['pgai-lang'] ) ) : '';
		$initial   = $this->languages->by_locale( $requested ) ?? ( $languages[0]['locale'] ?? null );
		$initial   = $initial instanceof Language ? $initial : $this->languages->by_locale( (string) $initial );

		return array(
			'restUrl'     => esc_url_raw( rest_url( Controller::NAMESPACE ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'homeUrl'     => esc_url_raw( home_url( '/' ) ),
			'path'        => '' === $path ? '/' : $path,
			'languages'   => $languages,
			'initial'     => null === $initial ? null : $initial->locale,
			'previewUrl'  => null === $initial ? '' : $this->mode->preview_url( '' === $path ? '/' : $path, $initial ),
			'can'         => array(
				'review'  => current_user_can( Capabilities::REVIEW ),
				'autoRun' => current_user_can( Capabilities::RUN_AUTO ),
			),
			'settingsUrl' => esc_url_raw( admin_url( 'admin.php?page=polyglot-ai' ) ),
		);
	}
}
