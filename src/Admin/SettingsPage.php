<?php
/**
 * Pantalla de ajustes.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Admin;

use PolyglotAI\Engines\EngineRegistry;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\ApiKey;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Switcher\MenuLocations;
use PolyglotAI\Support\Options;
use PolyglotAI\Translation\DictionaryFactory;

/**
 * Ajustes generales del plugin.
 *
 * La fase 6 añade el gestor de cadenas, el de slugs, el glosario y las
 * estadísticas. Aquí está lo imprescindible para dejar el plugin configurado y
 * funcionando.
 */
final class SettingsPage {

	/** Slug de la página. */
	private const SLUG = 'polyglot-ai';

	/**
	 * Constructor.
	 *
	 * @param Options          $options        Ajustes.
	 * @param ApiKey           $api_key        Custodia de la clave.
	 * @param EngineRegistry   $engines        Motores disponibles.
	 * @param LanguageRegistry $languages      Idiomas configurados.
	 * @param MenuLocations    $menu_locations Menús por idioma.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly ApiKey $api_key,
		private readonly EngineRegistry $engines,
		private readonly LanguageRegistry $languages,
		private readonly MenuLocations $menu_locations
	) {}

	/**
	 * Engancha la página.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_pgai_save_settings', array( $this, 'save' ) );
	}

	/**
	 * Añade la entrada de menú.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Polyglot AI', 'polyglot-ai' ),
			__( 'Polyglot AI', 'polyglot-ai' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-translation',
			66
		);
	}

	/**
	 * Guarda los ajustes.
	 */
	public function save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'No tienes permiso para cambiar estos ajustes.', 'polyglot-ai' ) );
		}

		check_admin_referer( 'pgai_save_settings' );

		$values = array(
			'engine'                    => sanitize_key( wp_unslash( (string) ( $_POST['engine'] ?? 'anthropic' ) ) ),
			'model'                     => sanitize_text_field( wp_unslash( (string) ( $_POST['model'] ?? 'claude-sonnet-5' ) ) ),
			'effort'                    => sanitize_key( wp_unslash( (string) ( $_POST['effort'] ?? 'low' ) ) ),
			'site_context'              => sanitize_textarea_field( wp_unslash( (string) ( $_POST['site_context'] ?? '' ) ) ),
			'prefix_default'            => isset( $_POST['prefix_default'] ),
			'realtime'                  => isset( $_POST['realtime'] ),
			'detect_visitor_language'   => isset( $_POST['detect_visitor_language'] ),
			'floating_switcher'         => isset( $_POST['floating_switcher'] ),
			'floating_switcher_display' => sanitize_key( wp_unslash( (string) ( $_POST['floating_switcher_display'] ?? 'name' ) ) ),
			'monthly_token_limit'       => absint( wp_unslash( (string) ( $_POST['monthly_token_limit'] ?? 0 ) ) ),
			'uninstall_removes_data'    => isset( $_POST['uninstall_removes_data'] ),
		);

		$values[ MenuLocations::OPTION_KEY ] = $this->submitted_menus();

		if ( ! in_array( $values['effort'], array( 'low', 'medium', 'high', 'xhigh', 'max' ), true ) ) {
			$values['effort'] = 'low';
		}

		if ( ! $this->engines->has( $values['engine'] ) ) {
			$values['engine'] = 'anthropic';
		}

		$this->options->update( $values );

		// La clave nunca se muestra, así que un campo vacío significa «no la
		// cambies», no «bórrala».
		$submitted_key = sanitize_text_field( wp_unslash( (string) ( $_POST['api_key'] ?? '' ) ) );

		if ( '' !== $submitted_key && ! $this->api_key->is_locked() ) {
			$this->api_key->save( $submitted_key );
		}

		// Cambiar el modelo o el contexto invalida las traducciones cacheadas.
		DictionaryFactory::invalidate();

		wp_safe_redirect( add_query_arg( 'pgai-saved', '1', menu_page_url( self::SLUG, false ) ) );
		exit;
	}

	/**
	 * Asignaciones de menú enviadas en el formulario.
	 *
	 * El nonce ya se ha comprobado en save(), que es el único que llama aquí.
	 *
	 * @return array<string, array<string, int>>
	 */
	private function submitted_menus(): array {
		// Los valores se sanean uno a uno más abajo: son identificadores de menú
		// y nombres de ubicación, no texto libre.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$submitted = $_POST['pgai_menus'] ?? array();

		if ( ! is_array( $submitted ) ) {
			return array();
		}

		$known = array_keys( get_registered_nav_menus() );
		$menus = array();

		foreach ( $this->languages->translatable() as $language ) {
			$locations = $submitted[ $language->locale ] ?? array();

			if ( ! is_array( $locations ) ) {
				continue;
			}

			foreach ( $locations as $location => $menu ) {
				$location = sanitize_key( (string) $location );
				$menu     = absint( $menu );

				// Solo ubicaciones que el tema declara: un nombre inventado en
				// el formulario no tiene por qué acabar en la base de datos.
				if ( 0 === $menu || ! in_array( $location, $known, true ) ) {
					continue;
				}

				$menus[ $language->locale ][ $location ] = $menu;
			}
		}

		return $menus;
	}

	/**
	 * Pinta la pantalla.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		$options = $this->options->all();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Polyglot AI', 'polyglot-ai' ); ?></h1>

			<?php if ( isset( $_GET['pgai-saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Ajustes guardados.', 'polyglot-ai' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! $this->api_key->exists() ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Todavía no hay una clave de API configurada. Sin ella no se puede traducir automáticamente.', 'polyglot-ai' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pgai_save_settings">
				<?php wp_nonce_field( 'pgai_save_settings' ); ?>

				<h2><?php esc_html_e( 'Idiomas', 'polyglot-ai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Idioma por defecto', 'polyglot-ai' ); ?></th>
						<td><code><?php echo esc_html( $this->languages->default_language()->locale ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Idiomas adicionales', 'polyglot-ai' ); ?></th>
						<td>
							<?php if ( array() === $this->languages->translatable() ) : ?>
								<p class="description"><?php esc_html_e( 'Aún no hay idiomas adicionales.', 'polyglot-ai' ); ?></p>
							<?php else : ?>
								<ul>
									<?php foreach ( $this->languages->translatable() as $language ) : ?>
										<li>
											<?php
											printf(
												'%s <code>/%s/</code>',
												esc_html( $language->label ),
												esc_html( $language->slug )
											);
											?>
											<?php if ( ! $language->published ) : ?>
												<em><?php esc_html_e( '(en preparación)', 'polyglot-ai' ); ?></em>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'URL del idioma por defecto', 'polyglot-ai' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="prefix_default" <?php checked( (bool) $options['prefix_default'] ); ?>>
								<?php esc_html_e( 'Usar también un subdirectorio para el idioma por defecto.', 'polyglot-ai' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Motor de traducción', 'polyglot-ai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pgai-engine"><?php esc_html_e( 'Motor', 'polyglot-ai' ); ?></label></th>
						<td>
							<select name="engine" id="pgai-engine">
								<?php foreach ( $this->engines->choices() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $options['engine'], $id ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pgai-model"><?php esc_html_e( 'Modelo', 'polyglot-ai' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="model" id="pgai-model" value="<?php echo esc_attr( (string) $options['model'] ); ?>">
							<p class="description"><?php esc_html_e( 'Por defecto claude-sonnet-5. Para abaratar el coste, claude-haiku-4-5.', 'polyglot-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pgai-api-key"><?php esc_html_e( 'Clave de API', 'polyglot-ai' ); ?></label></th>
						<td>
							<?php if ( $this->api_key->is_locked() ) : ?>
								<p>
									<code><?php echo esc_html( $this->api_key->hint() ); ?></code>
									<?php esc_html_e( 'Definida en wp-config.php mediante PGAI_API_KEY.', 'polyglot-ai' ); ?>
								</p>
							<?php else : ?>
								<input type="password" class="regular-text" name="api_key" id="pgai-api-key" autocomplete="off"
									placeholder="<?php echo esc_attr( $this->api_key->exists() ? $this->api_key->hint() : 'sk-ant-…' ); ?>">
								<p class="description">
									<?php esc_html_e( 'Recomendado: define PGAI_API_KEY en wp-config.php para que la clave no se guarde en la base de datos.', 'polyglot-ai' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pgai-context"><?php esc_html_e( 'Contexto del sitio', 'polyglot-ai' ); ?></label></th>
						<td>
							<textarea name="site_context" id="pgai-context" rows="4" class="large-text"><?php echo esc_textarea( (string) $options['site_context'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'De qué va el sitio, a quién se dirige y con qué tono. Mejora mucho la calidad de la traducción.', 'polyglot-ai' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Selector de idioma', 'polyglot-ai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Selector flotante', 'polyglot-ai' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="floating_switcher" <?php checked( (bool) $options['floating_switcher'] ); ?>>
								<?php esc_html_e( 'Mostrarlo fijo en una esquina de todas las páginas.', 'polyglot-ai' ); ?>
							</label>
							<p class="description">
								<?php
								esc_html_e(
									'Para temas donde no hay dónde poner el bloque. Ocupa la misma esquina que los avisos de cookies y los chats de soporte.',
									'polyglot-ai'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="pgai-floating-display"><?php esc_html_e( 'Qué muestra', 'polyglot-ai' ); ?></label>
						</th>
						<td>
							<select name="floating_switcher_display" id="pgai-floating-display">
								<?php
								$display_choices = array(
									'name'      => __( 'Nombre', 'polyglot-ai' ),
									'code'      => __( 'Código', 'polyglot-ai' ),
									'both'      => __( 'Nombre y código', 'polyglot-ai' ),
									'flag'      => __( 'Bandera', 'polyglot-ai' ),
									'flag_name' => __( 'Bandera y nombre', 'polyglot-ai' ),
								);
								?>
								<?php foreach ( $display_choices as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['floating_switcher_display'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Visitantes', 'polyglot-ai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Detectar el idioma del navegador', 'polyglot-ai' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="detect_visitor_language" <?php checked( (bool) $options['detect_visitor_language'] ); ?>>
								<?php esc_html_e( 'Llevar al visitante a su idioma la primera vez que llega.', 'polyglot-ai' ); ?>
							</label>
							<p class="description">
								<?php
								esc_html_e(
									'Actívalo solo si sabes que tu caché de página varía por cookie: si no, la primera respuesta cacheada se queda con la redirección dentro y se la lleva todo el mundo. Además manda sobre la intención de quien sigue un enlace a un idioma concreto.',
									'polyglot-ai'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Menús por idioma', 'polyglot-ai' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php $locations = get_registered_nav_menus(); ?>
					<?php if ( array() === $locations || array() === $this->languages->translatable() ) : ?>
						<tr>
							<td>
								<p class="description">
									<?php esc_html_e( 'Aparecerá aquí cuando el tema declare ubicaciones de menú y haya algún idioma añadido.', 'polyglot-ai' ); ?>
								</p>
							</td>
						</tr>
					<?php else : ?>
						<?php $menus = wp_get_nav_menus(); ?>
						<?php $assigned = $this->menu_locations->map(); ?>
						<?php foreach ( $this->languages->translatable() as $language ) : ?>
							<?php foreach ( $locations as $location => $description ) : ?>
								<?php $field = 'pgai_menus[' . $language->locale . '][' . $location . ']'; ?>
								<?php $id = 'pgai-menu-' . sanitize_html_class( $language->locale . '-' . $location ); ?>
								<tr>
									<th scope="row">
										<label for="<?php echo esc_attr( $id ); ?>">
											<?php
											printf(
												/* translators: 1: nombre del idioma, 2: ubicación del menú en el tema. */
												esc_html__( '%1$s — %2$s', 'polyglot-ai' ),
												esc_html( $language->label ),
												esc_html( (string) $description )
											);
											?>
										</label>
									</th>
									<td>
										<select name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $id ); ?>">
											<option value="0"><?php esc_html_e( 'El mismo que el idioma por defecto', 'polyglot-ai' ); ?></option>
											<?php foreach ( $menus as $menu ) : ?>
												<option value="<?php echo esc_attr( (string) $menu->term_id ); ?>"
													<?php selected( $assigned[ $language->locale ][ $location ] ?? 0, $menu->term_id ); ?>>
													<?php echo esc_html( $menu->name ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</table>

				<h2><?php esc_html_e( 'Consumo', 'polyglot-ai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Traducción en segundo plano', 'polyglot-ai' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="realtime" <?php checked( (bool) $options['realtime'] ); ?>>
								<?php esc_html_e( 'Traducir automáticamente las cadenas nuevas que aparezcan al visitarse una página.', 'polyglot-ai' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Nunca bloquea la carga: se muestra el original y la traducción aparece en la visita siguiente. El tráfico de robots no encola nada.', 'polyglot-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pgai-limit"><?php esc_html_e( 'Tope mensual de tokens', 'polyglot-ai' ); ?></label></th>
						<td>
							<input type="number" min="0" step="1000" name="monthly_token_limit" id="pgai-limit" value="<?php echo esc_attr( (string) $options['monthly_token_limit'] ); ?>">
							<p class="description"><?php esc_html_e( '0 significa sin tope.', 'polyglot-ai' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Desinstalación', 'polyglot-ai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Al desinstalar', 'polyglot-ai' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="uninstall_removes_data" <?php checked( (bool) $options['uninstall_removes_data'] ); ?>>
								<?php esc_html_e( 'Borrar las tablas y los ajustes al desinstalar el plugin.', 'polyglot-ai' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Desactivar el plugin nunca borra nada. Esto solo afecta a la desinstalación.', 'polyglot-ai' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
