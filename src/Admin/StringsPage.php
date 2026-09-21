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
use PolyglotAI\Translation\Status;

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

		$this->render_transfer();

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
	 * Caja de importación y exportación.
	 *
	 * Va en PHP y no en la aplicación de React porque lleva una subida de
	 * archivo y una descarga: las dos cosas que un formulario de toda la vida
	 * hace bien y que por fetch obligan a inventar un camino de vuelta.
	 */
	private function render_transfer(): void {
		$languages = $this->access->for_user( get_current_user_id() );

		if ( array() === $languages ) {
			return;
		}

		$this->render_import_result();

		?>
		<div class="pgai-transfer">
			<h2><?php esc_html_e( 'Importar y exportar', 'polyglot-ai' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pgai-transfer__form">
				<input type="hidden" name="action" value="pgai_export">
				<?php wp_nonce_field( 'pgai_export' ); ?>

				<label for="pgai-export-language"><?php esc_html_e( 'Exportar a CSV', 'polyglot-ai' ); ?></label>

				<select name="language" id="pgai-export-language">
					<?php foreach ( $languages as $language ) : ?>
						<option value="<?php echo esc_attr( $language->locale ); ?>">
							<?php echo esc_html( $language->label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="status">
					<option value=""><?php esc_html_e( 'Todos los estados', 'polyglot-ai' ); ?></option>
					<?php foreach ( Status::cases() as $status ) : ?>
						<option value="<?php echo esc_attr( $status->value ); ?>">
							<?php echo esc_html( $status->label() ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<?php submit_button( __( 'Descargar', 'polyglot-ai' ), 'secondary', 'submit', false ); ?>
			</form>

			<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				enctype="multipart/form-data"
				class="pgai-transfer__form"
			>
				<input type="hidden" name="action" value="pgai_import">
				<?php wp_nonce_field( 'pgai_import' ); ?>

				<label for="pgai-import-language"><?php esc_html_e( 'Importar un CSV', 'polyglot-ai' ); ?></label>

				<select name="language" id="pgai-import-language">
					<?php foreach ( $languages as $language ) : ?>
						<option value="<?php echo esc_attr( $language->locale ); ?>">
							<?php echo esc_html( $language->label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<input type="file" name="pgai_file" accept=".csv,text/csv" required>

				<label>
					<input type="checkbox" name="pgai_overwrite">
					<?php esc_html_e( 'Sobrescribir también lo revisado y lo escrito a mano', 'polyglot-ai' ); ?>
				</label>

				<?php submit_button( __( 'Importar', 'polyglot-ai' ), 'secondary', 'submit', false ); ?>
			</form>

			<p class="description">
				<?php
				esc_html_e(
					'El archivo se empareja por la columna «hash». Cambiar el texto original en la hoja de cálculo no rompe nada, pero tampoco sirve para nada: lo que se guarda es la columna «translation».',
					'polyglot-ai'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Avisa de cómo ha ido la última importación.
	 */
	private function render_import_result(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'No se ha podido leer el archivo.', 'polyglot-ai' )
			);

			return;
		}

		if ( ! isset( $_GET['saved'] ) ) {
			return;
		}

		$saved   = absint( wp_unslash( (string) $_GET['saved'] ) );
		$skipped = absint( wp_unslash( (string) ( $_GET['skipped'] ?? 0 ) ) );
		$unknown = absint( wp_unslash( (string) ( $_GET['unknown'] ?? 0 ) ) );
		// phpcs:enable

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: guardadas, 2: omitidas, 3: desconocidas. */
					__( 'Importación terminada: %1$d guardadas, %2$d omitidas y %3$d que ya no existen en el sitio.', 'polyglot-ai' ),
					$saved,
					$skipped,
					$unknown
				)
			)
		);
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
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'languages' => array_values( $languages ),
			'can'       => array(
				'review'  => current_user_can( Capabilities::REVIEW ),
				'autoRun' => current_user_can( Capabilities::RUN_AUTO ),
			),
		);
	}
}
