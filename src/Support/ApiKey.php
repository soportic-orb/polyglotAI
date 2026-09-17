<?php
/**
 * Custodia de la clave de la API.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Support;

/**
 * Lee y guarda la clave de la API sin exponerla nunca al navegador.
 *
 * Se prefiere la constante PGAI_API_KEY en wp-config.php: así la clave no está
 * en la base de datos y no viaja en copias de seguridad ni en exportaciones. Si
 * se guarda desde el panel, se cifra con las sales de la instalación.
 */
final class ApiKey {

	/** Opción donde se guarda la clave cifrada. */
	private const OPTION = 'pgai_api_key';

	/**
	 * La clave en claro, o null si no hay ninguna configurada.
	 */
	public function get(): ?string {
		if ( defined( 'PGAI_API_KEY' ) && is_string( constant( 'PGAI_API_KEY' ) ) && '' !== constant( 'PGAI_API_KEY' ) ) {
			return (string) constant( 'PGAI_API_KEY' );
		}

		$stored = get_option( self::OPTION, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		return $this->decrypt( $stored );
	}

	/**
	 * Si hay una clave configurada.
	 */
	public function exists(): bool {
		return null !== $this->get();
	}

	/**
	 * Si la clave viene de la constante y por tanto no es editable desde el panel.
	 */
	public function is_locked(): bool {
		return defined( 'PGAI_API_KEY' ) && is_string( constant( 'PGAI_API_KEY' ) ) && '' !== constant( 'PGAI_API_KEY' );
	}

	/**
	 * Pista mostrable en la interfaz.
	 *
	 * Solo los cuatro últimos caracteres: lo justo para reconocer qué clave
	 * está puesta sin llegar a revelarla.
	 */
	public function hint(): string {
		$key = $this->get();

		if ( null === $key ) {
			return '';
		}

		return '…' . substr( $key, -4 );
	}

	/**
	 * Guarda la clave cifrada.
	 *
	 * @param string $key Clave en claro. Una cadena vacía la borra.
	 */
	public function save( string $key ): bool {
		if ( '' === trim( $key ) ) {
			return delete_option( self::OPTION );
		}

		return update_option( self::OPTION, $this->encrypt( trim( $key ) ), false );
	}

	/**
	 * Cifra la clave con las sales de la instalación.
	 *
	 * @param string $value Clave en claro.
	 */
	private function encrypt( string $value ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, $this->secret() ) );
	}

	/**
	 * Descifra la clave.
	 *
	 * @param string $value Clave cifrada en base64.
	 */
	private function decrypt( string $value ): ?string {
		$raw = base64_decode( $value, true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain      = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->secret() );

		// Un false aquí significa que han cambiado las sales de la instalación:
		// la clave guardada ya no se puede recuperar y hay que volver a pedirla.
		return false === $plain ? null : $plain;
	}

	/**
	 * Clave de cifrado derivada de las sales de WordPress.
	 */
	private function secret(): string {
		$salt = ( defined( 'AUTH_KEY' ) ? (string) constant( 'AUTH_KEY' ) : '' )
			. ( defined( 'SECURE_AUTH_SALT' ) ? (string) constant( 'SECURE_AUTH_SALT' ) : '' );

		return hash( 'sha256', 'pgai|' . $salt, true );
	}
}
