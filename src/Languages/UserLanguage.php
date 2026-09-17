<?php
/**
 * Idioma preferido de cada usuario.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Languages;

use PolyglotAI\Mail\LanguageResolver;
use PolyglotAI\Routing\RequestContext;

/**
 * Recuerda en qué idioma navega cada usuario registrado.
 *
 * Es lo que permite que sus correos le lleguen en ese idioma aunque quien los
 * dispare sea otra persona desde el escritorio.
 */
final class UserLanguage {

	/**
	 * Constructor.
	 *
	 * @param RequestContext   $request   Contexto de la petición.
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 */
	public function __construct(
		private readonly RequestContext $request,
		private readonly LanguageRegistry $languages
	) {}

	/**
	 * Engancha el guardado y el campo del perfil.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'remember' ), 5 );
		add_action( 'show_user_profile', array( $this, 'render_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_field' ) );
	}

	/**
	 * Guarda el idioma en que el usuario está navegando.
	 */
	public function remember(): void {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return;
		}

		$locale = $this->request->language()->locale;
		$stored = get_user_meta( $user_id, LanguageResolver::USER_META, true );

		// Solo se escribe cuando cambia: esto corre en cada petición del
		// frontal y una escritura por visita no tendría justificación.
		if ( $stored === $locale ) {
			return;
		}

		update_user_meta( $user_id, LanguageResolver::USER_META, $locale );
	}

	/**
	 * Campo de idioma en el perfil.
	 *
	 * @param \WP_User $user Usuario.
	 */
	public function render_field( $user ): void {
		$current = (string) get_user_meta( $user->ID, LanguageResolver::USER_META, true );

		?>
		<h2><?php esc_html_e( 'Polyglot AI', 'polyglot-ai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="pgai-user-language"><?php esc_html_e( 'Idioma para los correos', 'polyglot-ai' ); ?></label>
				</th>
				<td>
					<select name="pgai_user_language" id="pgai-user-language">
						<option value=""><?php esc_html_e( 'El del sitio', 'polyglot-ai' ); ?></option>
						<?php foreach ( $this->languages->all() as $language ) : ?>
							<option value="<?php echo esc_attr( $language->locale ); ?>" <?php selected( $current, $language->locale ); ?>>
								<?php echo esc_html( $language->label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Se actualiza solo al navegar por el sitio en otro idioma.', 'polyglot-ai' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Guarda el campo del perfil.
	 *
	 * @param int $user_id Identificador del usuario.
	 */
	public function save_field( $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		check_admin_referer( 'update-user_' . $user_id );

		$locale = isset( $_POST['pgai_user_language'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['pgai_user_language'] ) )
			: '';

		if ( '' === $locale ) {
			delete_user_meta( (int) $user_id, LanguageResolver::USER_META );

			return;
		}

		if ( null !== $this->languages->by_locale( $locale ) ) {
			update_user_meta( (int) $user_id, LanguageResolver::USER_META, $locale );
		}
	}
}
