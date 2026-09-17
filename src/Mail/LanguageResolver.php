<?php
/**
 * Idioma del destinatario de un correo.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Mail;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;

/**
 * Decide en qué idioma se envía un correo.
 *
 * El idioma del correo es el del DESTINATARIO, no el de quien provoca el envío:
 * un administrador que cambia el estado de un pedido desde el escritorio en
 * español no debe hacer que el cliente reciba su aviso en español.
 */
final class LanguageResolver {

	/** Metadato donde se guarda el idioma preferido del usuario. */
	public const USER_META = 'pgai_language';

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 */
	public function __construct( private readonly LanguageRegistry $languages ) {}

	/**
	 * Idioma en que debe enviarse un correo.
	 *
	 * @param string|string[] $to Destinatarios tal como los recibe wp_mail.
	 */
	public function for_recipient( string|array $to ): Language {
		$email = $this->first_email( $to );

		$language = null;

		if ( '' !== $email ) {
			$user = get_user_by( 'email', $email );

			if ( $user instanceof \WP_User ) {
				$stored = get_user_meta( $user->ID, self::USER_META, true );

				if ( is_string( $stored ) && '' !== $stored ) {
					$language = $this->languages->by_locale( $stored );
				}
			}
		}

		/**
		 * Permite decidir el idioma de un correo.
		 *
		 * Es el punto por el que WooCommerce o un plugin de formularios aportan
		 * el idioma del pedido o del envío, que puede no corresponder a ningún
		 * usuario registrado.
		 *
		 * @since 0.1.0
		 *
		 * @param Language|null   $language Idioma resuelto, o null.
		 * @param string          $email    Correo del destinatario.
		 * @param string|string[] $to       Destinatarios originales.
		 */
		$language = apply_filters( 'pgai_recipient_language', $language, $email, $to );

		return $language instanceof Language ? $language : $this->languages->default_language();
	}

	/**
	 * Primer correo de la lista de destinatarios.
	 *
	 * Un correo puede ir a varias personas con idiomas distintos; se toma el
	 * primero porque un solo mensaje solo puede ir en un idioma. Los envíos
	 * masivos multilingües son cosa de quien los dispara.
	 *
	 * @param string|string[] $to Destinatarios.
	 */
	private function first_email( string|array $to ): string {
		$list = is_array( $to ) ? $to : explode( ',', $to );

		foreach ( $list as $entry ) {
			$entry = trim( (string) $entry );

			// Admite la forma «Nombre <correo@ejemplo.com>».
			if ( 1 === preg_match( '/<([^>]+)>/', $entry, $matches ) ) {
				$entry = trim( $matches[1] );
			}

			if ( is_email( $entry ) ) {
				return $entry;
			}
		}

		return '';
	}
}
