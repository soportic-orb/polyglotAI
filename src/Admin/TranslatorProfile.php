<?php
/**
 * Asignación de idiomas a los traductores.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Admin;

use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Support\TranslatorLanguages;
use WP_User;

/**
 * Añade al perfil de usuario los idiomas que esa persona puede traducir.
 *
 * Lo edita quien puede gestionar usuarios, no la persona misma: si un traductor
 * pudiera asignarse idiomas, la restricción no restringiría nada.
 */
final class TranslatorProfile {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry    $languages Idiomas del sitio.
	 * @param TranslatorLanguages $access    Idiomas por usuario.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly TranslatorLanguages $access
	) {}

	/**
	 * Engancha los campos del perfil.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
	}

	/**
	 * Pinta el campo.
	 *
	 * @param WP_User $user Usuario que se está editando.
	 */
	public function render( WP_User $user ): void {
		if ( ! user_can( $user, Capabilities::TRANSLATE ) || array() === $this->languages->translatable() ) {
			return;
		}

		$editable = current_user_can( 'edit_users' );
		$assigned = $this->access->assigned( (int) $user->ID );

		?>
		<h2><?php esc_html_e( 'Traducción', 'polyglot-ai' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Idiomas asignados', 'polyglot-ai' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text">
							<?php esc_html_e( 'Idiomas que esta persona puede traducir', 'polyglot-ai' ); ?>
						</legend>

						<?php foreach ( $this->languages->translatable() as $language ) : ?>
							<label style="display:block">
								<input
									type="checkbox"
									name="pgai_languages[]"
									value="<?php echo esc_attr( $language->locale ); ?>"
									<?php checked( in_array( $language->locale, $assigned, true ) ); ?>
									<?php disabled( ! $editable ); ?>
								>
								<?php echo esc_html( $language->label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<p class="description">
						<?php esc_html_e( 'Sin ninguno marcado puede traducir a todos los idiomas.', 'polyglot-ai' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php

		if ( $editable ) {
			wp_nonce_field( 'pgai_save_translator_languages', 'pgai_translator_nonce' );
		}
	}

	/**
	 * Guarda el campo.
	 *
	 * @param int $user_id Usuario que se está editando.
	 */
	public function save( int $user_id ): void {
		// Quien no puede editar usuarios no puede asignarse idiomas a sí mismo.
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$nonce = isset( $_POST['pgai_translator_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['pgai_translator_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'pgai_save_translator_languages' ) ) {
			return;
		}

		$submitted = isset( $_POST['pgai_languages'] ) && is_array( $_POST['pgai_languages'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['pgai_languages'] ) )
			: array();

		$this->access->assign( $user_id, $submitted );
	}
}
