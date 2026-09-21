<?php
/**
 * Glosario y términos que no se traducen.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Admin;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Support\Options;
use PolyglotAI\Translation\DictionaryFactory;

/**
 * Pantalla del glosario.
 *
 * Son dos listas con propósitos opuestos y por eso viven juntas:
 *
 * - **Glosario**: «esto se traduce así y no de otra manera». Por idioma, porque
 *   la traducción correcta de un término depende del idioma de destino.
 * - **No traducir**: «esto se queda como está». Sin idioma, porque el nombre de
 *   una marca o de un producto es el mismo en todos.
 *
 * Las dos entran en el prompt del sistema (ADR-05), que es la parte cacheable:
 * cambiarlas invalida la caché de prompt y las traducciones ya hechas siguen
 * como estaban, así que después de tocar el glosario hay que retraducir lo que
 * se quiera corregir. Se avisa en la propia pantalla.
 */
final class GlossaryPage {

	/** Slug de la pantalla. */
	public const SLUG = 'polyglot-ai-glossary';

	/**
	 * Constructor.
	 *
	 * @param Options          $options   Ajustes.
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly LanguageRegistry $languages
	) {}

	/**
	 * Engancha la pantalla.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 30 );
		add_action( 'admin_post_pgai_save_glossary', array( $this, 'save' ) );
	}

	/**
	 * Añade la pantalla al menú del plugin.
	 */
	public function add_page(): void {
		add_submenu_page(
			'polyglot-ai',
			__( 'Glosario', 'polyglot-ai' ),
			__( 'Glosario', 'polyglot-ai' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Guarda las dos listas.
	 */
	public function save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'No tienes permiso para cambiar el glosario.', 'polyglot-ai' ) );
		}

		check_admin_referer( 'pgai_save_glossary' );

		$glossary = array();

		foreach ( $this->languages->translatable() as $language ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$raw = $_POST[ 'glossary_' . $language->locale ] ?? '';

			$entries = $this->parse_glossary( is_string( $raw ) ? wp_unslash( $raw ) : '' );

			if ( array() !== $entries ) {
				$glossary[ $language->locale ] = $entries;
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$raw_terms = $_POST['do_not_translate'] ?? '';

		update_option( Options::GLOSSARY, $glossary, false );
		update_option(
			Options::DO_NOT_TRANSLATE,
			$this->parse_terms( is_string( $raw_terms ) ? wp_unslash( $raw_terms ) : '' ),
			false
		);

		// El glosario va dentro del prompt, así que lo cacheado ya no vale.
		DictionaryFactory::invalidate();

		wp_safe_redirect( Redirect::to_page( self::SLUG, array( 'pgai-saved' => '1' ) ) );
		exit;
	}

	/**
	 * Pinta la pantalla.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Glosario', 'polyglot-ai' ); ?></h1>

			<?php if ( isset( $_GET['pgai-saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Glosario guardado.', 'polyglot-ai' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="notice notice-info">
				<p>
					<?php
					esc_html_e(
						'El glosario solo afecta a lo que se traduzca a partir de ahora. Para aplicarlo a lo ya traducido, selecciona esas cadenas en el gestor y usa «Volver a traducir».',
						'polyglot-ai'
					);
					?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pgai_save_glossary">
				<?php wp_nonce_field( 'pgai_save_glossary' ); ?>

				<h2><?php esc_html_e( 'Términos que no se traducen', 'polyglot-ai' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Uno por línea. Nombres de marca, de producto o técnicos que tienen que salir igual en todos los idiomas.', 'polyglot-ai' ); ?>
				</p>
				<textarea name="do_not_translate" rows="6" class="large-text code"><?php echo esc_textarea( implode( "\n", $this->options->do_not_translate() ) ); ?></textarea>

				<h2><?php esc_html_e( 'Traducciones obligatorias', 'polyglot-ai' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Una por línea, con el formato «original = traducción».', 'polyglot-ai' ); ?>
				</p>

				<?php if ( array() === $this->languages->translatable() ) : ?>
					<p><?php esc_html_e( 'Añade algún idioma para poder escribir su glosario.', 'polyglot-ai' ); ?></p>
				<?php else : ?>
					<?php foreach ( $this->languages->translatable() as $language ) : ?>
						<?php $field = 'glossary_' . $language->locale; ?>
						<h3><?php echo esc_html( $language->label ); ?></h3>
						<textarea
							name="<?php echo esc_attr( $field ); ?>"
							id="<?php echo esc_attr( $field ); ?>"
							rows="6"
							class="large-text code"
						><?php echo esc_textarea( $this->format_glossary( $language ) ); ?></textarea>
					<?php endforeach; ?>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Glosario de un idioma en el formato del formulario.
	 *
	 * @param Language $language Idioma.
	 */
	private function format_glossary( Language $language ): string {
		$lines = array();

		foreach ( $this->options->glossary( $language->locale ) as $original => $translation ) {
			$lines[] = $original . ' = ' . $translation;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Convierte el texto del formulario en pares original => traducción.
	 *
	 * @param string $raw Texto escrito.
	 * @return array<string, string>
	 */
	private function parse_glossary( string $raw ): array {
		$entries = array();

		$lines = preg_split( '/\R/', $raw );

		foreach ( false === $lines ? array() : $lines as $line ) {
			// El separador es el primer «=»: una traducción puede llevar otro
			// dentro y partir por todos dejaría la mitad fuera.
			$position = strpos( $line, '=' );

			if ( false === $position ) {
				continue;
			}

			$original    = sanitize_text_field( trim( substr( $line, 0, $position ) ) );
			$translation = sanitize_text_field( trim( substr( $line, $position + 1 ) ) );

			if ( '' !== $original && '' !== $translation ) {
				$entries[ $original ] = $translation;
			}
		}

		return $entries;
	}

	/**
	 * Convierte el texto del formulario en una lista de términos.
	 *
	 * @param string $raw Texto escrito.
	 * @return string[]
	 */
	private function parse_terms( string $raw ): array {
		$lines = preg_split( '/\R/', $raw );

		$terms = array_map(
			static fn ( string $line ): string => sanitize_text_field( trim( $line ) ),
			false === $lines ? array() : $lines
		);

		return array_values( array_unique( array_filter( $terms, static fn ( string $term ): bool => '' !== $term ) ) );
	}
}
