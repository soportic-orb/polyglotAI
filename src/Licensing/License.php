<?php
/**
 * Clave de licencia y su estado.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Licensing;

/**
 * Guarda la clave de licencia y lo último que dijo el servidor sobre ella.
 *
 * **La clave no se cifra, a diferencia de la de la API** (ADR-05). No es un
 * descuido: son dos cosas distintas. La clave de Anthropic gasta dinero en un
 * tercero y el administrador no tiene por qué volver a verla nunca; la de
 * licencia es suya, la copia de su factura, tiene que poder leerla para
 * pegarla en otro sitio, y lo peor que puede hacer quien la robe es recibir
 * actualizaciones de un plugin que ya ha pagado alguien. Cifrarla solo añadiría
 * fricción.
 *
 * Sin autocarga: solo se lee en el escritorio y cuando WordPress busca
 * actualizaciones, no en cada visita de cada visitante.
 */
final class License {

	/** Opción donde vive todo lo de la licencia. */
	public const OPTION = 'pgai_license';

	/** Constante que gana sobre lo guardado, para instalaciones gestionadas. */
	public const CONSTANT = 'PGAI_LICENSE_KEY';

	/** Estados que puede devolver el servidor. */
	public const VALID   = 'valid';
	public const INVALID = 'invalid';
	public const EXPIRED = 'expired';
	public const UNKNOWN = 'unknown';

	/**
	 * La clave configurada.
	 */
	public function key(): string {
		if ( defined( self::CONSTANT ) ) {
			$constant = constant( self::CONSTANT );

			if ( is_string( $constant ) && '' !== $constant ) {
				return $constant;
			}
		}

		return (string) ( $this->stored()['key'] ?? '' );
	}

	/**
	 * Si la clave viene de una constante y no se puede cambiar desde el panel.
	 */
	public function is_locked(): bool {
		return defined( self::CONSTANT ) && '' !== (string) constant( self::CONSTANT );
	}

	/**
	 * Guarda una clave nueva y olvida lo que se sabía de la anterior.
	 *
	 * @param string $key Clave.
	 */
	public function set_key( string $key ): void {
		$this->save(
			array(
				'key'     => sanitize_text_field( $key ),
				'status'  => self::UNKNOWN,
				'expires' => '',
			)
		);
	}

	/**
	 * Estado de la licencia según la última respuesta del servidor.
	 */
	public function status(): string {
		if ( '' === $this->key() ) {
			return self::INVALID;
		}

		$status = (string) ( $this->stored()['status'] ?? self::UNKNOWN );

		return in_array( $status, array( self::VALID, self::INVALID, self::EXPIRED, self::UNKNOWN ), true )
			? $status
			: self::UNKNOWN;
	}

	/**
	 * Fecha de caducidad, si el servidor la ha dado.
	 */
	public function expires(): string {
		return (string) ( $this->stored()['expires'] ?? '' );
	}

	/**
	 * Si la licencia da derecho a actualizaciones.
	 */
	public function is_active(): bool {
		return self::VALID === $this->status();
	}

	/**
	 * Anota lo que ha dicho el servidor.
	 *
	 * @param array<string, mixed> $license Parte «license» de la respuesta.
	 */
	public function remember( array $license ): void {
		$stored = $this->stored();

		$stored['status']  = sanitize_key( (string) ( $license['status'] ?? self::UNKNOWN ) );
		$stored['expires'] = sanitize_text_field( (string) ( $license['expires'] ?? '' ) );

		$this->save( $stored );
	}

	/**
	 * Olvida la licencia entera.
	 */
	public function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Los cuatro últimos caracteres, para enseñarlos sin enseñar la clave.
	 */
	public function last_four(): string {
		$key = $this->key();

		return '' === $key ? '' : '…' . substr( $key, -4 );
	}

	/**
	 * Lo guardado en la opción.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Guarda la opción.
	 *
	 * @param array<string, mixed> $data Datos.
	 */
	private function save( array $data ): void {
		update_option( self::OPTION, $data, false );
	}
}
