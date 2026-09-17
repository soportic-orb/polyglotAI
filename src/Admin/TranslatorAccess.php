<?php
/**
 * Acceso del rol de traductor.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Admin;

use PolyglotAI\Editor\EditorPage;
use PolyglotAI\Support\Capabilities;

/**
 * Lleva al traductor al editor visual en lugar de al escritorio.
 *
 * El rol de traductor necesita `read` para poder iniciar sesión, y `read` abre
 * el escritorio de WordPress. Ahí no tiene nada que hacer —ni entradas, ni
 * ajustes, ni comentarios— y lo único que consigue es confundirse y preguntar.
 *
 * No se le bloquea el acceso: se le lleva a su sitio. Un bloqueo con `wp_die()`
 * habría dejado sin salida a quien llega a `/wp-admin/` desde un marcador.
 *
 * El perfil sí queda accesible: cambiar la contraseña o el correo es algo que
 * cada cual tiene que poder hacer.
 */
final class TranslatorAccess {

	/**
	 * Páginas del escritorio que un traductor sí puede abrir.
	 *
	 * @var string[]
	 */
	private const ALLOWED = array( 'profile.php', 'user-edit.php', 'admin-ajax.php', 'admin-post.php' );

	/**
	 * Engancha la redirección.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'redirect' ) );
		add_filter( 'show_admin_bar', array( $this, 'admin_bar' ) );
	}

	/**
	 * Redirige al editor visual.
	 */
	public function redirect(): void {
		if ( ! $this->is_translator_only() || wp_doing_ajax() ) {
			return;
		}

		global $pagenow;

		if ( in_array( (string) $pagenow, self::ALLOWED, true ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . EditorPage::SLUG ) );
		exit;
	}

	/**
	 * Deja la barra superior, que es por donde vuelve al sitio.
	 *
	 * @param bool|mixed $show Si WordPress la mostraría.
	 * @return bool|mixed
	 */
	public function admin_bar( $show ) {
		return $this->is_translator_only() ? true : $show;
	}

	/**
	 * Si quien mira puede traducir y nada más.
	 */
	private function is_translator_only(): bool {
		return is_user_logged_in()
			&& current_user_can( Capabilities::TRANSLATE )
			&& ! current_user_can( 'edit_posts' )
			&& ! current_user_can( Capabilities::MANAGE_SETTINGS );
	}
}
