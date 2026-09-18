<?php
/**
 * Cliente del servidor de licencias y actualizaciones.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Licensing;

/**
 * Habla con nuestro propio servidor de licencias.
 *
 * El plugin no se distribuye por WordPress.org (ADR-14), así que las
 * actualizaciones las tiene que servir alguien. Es un servidor nuestro y no un
 * intermediario: sin comisiones, sin cuotas y sin meter el SDK de nadie en el
 * `vendor/` del sitio del cliente, que es justo lo que evita el ADR-05.
 *
 * **La dirección del servidor se fija al empaquetar**, con la constante
 * `PGAI_UPDATE_SERVER`. Si no está definida, no hay servidor y no se comprueba
 * nada: el plugin funciona igual, simplemente no se actualiza solo. Es lo que
 * queremos durante el desarrollo y en una copia del repositorio.
 *
 * **Nunca bloquea el escritorio.** Cinco segundos de espera y, si el servidor no
 * responde, no pasa nada: no se ofrece actualización, no se enseña ningún error
 * y se vuelve a intentar más tarde. Un servidor caído no puede dejar a nadie sin
 * poder administrar su sitio.
 */
final class LicenseServer {

	/** Constante con la dirección del servidor. */
	public const CONSTANT = 'PGAI_UPDATE_SERVER';

	/** Ruta base de la API del servidor. */
	private const PATH = 'wp-json/pgai/v1/';

	/**
	 * Constructor.
	 *
	 * @param string $base    Dirección del servidor. Vacía = sin servidor.
	 * @param int    $timeout Segundos de espera.
	 */
	public function __construct(
		private readonly string $base = '',
		private readonly int $timeout = 5
	) {}

	/**
	 * Crea el cliente a partir de la constante.
	 */
	public static function from_constant(): self {
		$base = defined( self::CONSTANT ) ? (string) constant( self::CONSTANT ) : '';

		return new self( $base );
	}

	/**
	 * Si hay servidor configurado.
	 */
	public function is_configured(): bool {
		return '' !== $this->host();
	}

	/**
	 * Host del servidor, en minúsculas.
	 *
	 * Es lo que decide qué descargas se aceptan: ver UpdateChecker.
	 */
	public function host(): string {
		if ( '' === $this->base || ! str_starts_with( $this->base, 'https://' ) ) {
			return '';
		}

		$host = wp_parse_url( $this->base, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Llama a una acción del servidor.
	 *
	 * @param string               $action Acción: activate, deactivate, status.
	 * @param array<string, mixed> $body   Cuerpo de la petición.
	 * @return array<string, mixed>|null Respuesta, o null si no se ha podido.
	 */
	public function post( string $action, array $body ): ?array {
		if ( ! $this->is_configured() ) {
			return null;
		}

		$response = wp_remote_post(
			trailingslashit( $this->base ) . self::PATH . $action,
			array(
				'timeout' => $this->timeout,
				'headers' => array( 'content-type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
