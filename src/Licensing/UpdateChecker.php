<?php
/**
 * Actualizaciones servidas desde nuestro propio servidor.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Licensing;

use stdClass;

/**
 * Le cuenta a WordPress que hay una versión nueva y de dónde bajarla.
 *
 * **Lo que responde el servidor no se cree a ciegas.** WordPress instala sin
 * rechistar el zip que diga `package`, así que una respuesta manipulada —un DNS
 * envenenado, un proxy de por medio, un servidor comprometido— podría hacer que
 * el sitio del cliente instalara cualquier cosa bajo el nombre de este plugin.
 * Por eso la descarga tiene que ser `https://` **y estar en el mismo host que el
 * servidor de licencias**; cualquier otra cosa se descarta entera, no se
 * recorta. Es la única defensa real que hay aquí, porque el actualizador del
 * núcleo no verifica firmas de plugins de terceros.
 *
 * **No se llama a casa sin licencia.** Si no hay clave configurada no se
 * contacta con el servidor: un sitio que nunca ha comprado nada no tiene por qué
 * mandar su dirección a ninguna parte.
 *
 * **La respuesta se guarda medio día**, y una hora cuando falla, para que
 * WordPress pueda preguntar por actualizaciones tantas veces como quiera sin
 * convertirlo en una petición de red cada vez.
 */
final class UpdateChecker {

	/** Transitorio donde se guarda la respuesta. */
	public const TRANSIENT = 'pgai_update';

	/** Doce horas. */
	private const TTL = 43200;

	/** Una hora: lo que se espera antes de volver a intentarlo tras un fallo. */
	private const TTL_FAILED = 3600;

	/**
	 * Constructor.
	 *
	 * @param LicenseServer $server   Servidor de licencias.
	 * @param License       $license  Licencia guardada.
	 * @param string        $basename Ruta del plugin: carpeta/archivo.php.
	 * @param string        $slug     Slug del plugin.
	 * @param string        $version  Versión instalada.
	 */
	public function __construct(
		private readonly LicenseServer $server,
		private readonly License $license,
		private readonly string $basename,
		private readonly string $slug,
		private readonly string $version
	) {}

	/**
	 * Engancha la comprobación.
	 */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check' ) );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ) );
	}

	/**
	 * Añade nuestra actualización a la lista de WordPress.
	 *
	 * @param mixed $transient Transitorio de actualizaciones.
	 * @return mixed
	 */
	public function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$update = $this->update();

		if ( null === $update ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $this->basename ] = $this->item( $update );

		return $transient;
	}

	/**
	 * Rellena la ventana de «Ver detalles».
	 *
	 * @param mixed $result Resultado que traiga otro.
	 * @param mixed $action Acción pedida.
	 * @param mixed $args   Argumentos.
	 * @return mixed
	 */
	public function details( $result, $action = '', $args = null ) {
		$slug = is_object( $args ) && isset( $args->slug ) ? (string) $args->slug : '';

		if ( 'plugin_information' !== $action || $slug !== $this->slug ) {
			return $result;
		}

		$update = $this->update();

		if ( null === $update ) {
			return $result;
		}

		$information                = $this->item( $update );
		$information->name          = 'Polyglot AI';
		$information->sections      = $update['sections'];
		$information->download_link = $update['package'];

		return $information;
	}

	/**
	 * Olvida lo que se sabía, para volver a preguntar.
	 */
	public function flush(): void {
		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * La actualización disponible, o null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function update(): ?array {
		$cached = get_site_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			// Un fallo se guarda como array vacío para no repetir la llamada.
			return array() === $cached ? null : $cached;
		}

		$update = $this->fetch();

		set_site_transient(
			self::TRANSIENT,
			null === $update ? array() : $update,
			null === $update ? self::TTL_FAILED : self::TTL
		);

		return $update;
	}

	/**
	 * Pregunta al servidor.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fetch(): ?array {
		$key = $this->license->key();

		if ( '' === $key ) {
			return null;
		}

		$response = $this->server->post(
			'status',
			array(
				'license' => $key,
				'site'    => home_url( '/' ),
				'slug'    => $this->slug,
				'version' => $this->version,
			)
		);

		if ( null === $response ) {
			return null;
		}

		if ( isset( $response['license'] ) && is_array( $response['license'] ) ) {
			$this->license->remember( $response['license'] );
		}

		return isset( $response['update'] ) && is_array( $response['update'] )
			? $this->accept( $response['update'] )
			: null;
	}

	/**
	 * Comprueba que una actualización se puede aceptar y la limpia.
	 *
	 * Todo lo que no cuadre descarta la actualización ENTERA. Recortar la parte
	 * sospechosa y quedarse con el resto sería quedarse con una respuesta que ya
	 * se sabe que no es de quien dice ser.
	 *
	 * @param array<string, mixed> $update Parte «update» de la respuesta.
	 * @return array<string, mixed>|null
	 */
	private function accept( array $update ): ?array {
		$version = isset( $update['version'] ) ? (string) $update['version'] : '';
		$package = isset( $update['package'] ) ? (string) $update['package'] : '';

		if ( '' === $version || ! version_compare( $version, $this->version, '>' ) ) {
			return null;
		}

		if ( ! str_starts_with( $package, 'https://' ) ) {
			return null;
		}

		$host = wp_parse_url( $package, PHP_URL_HOST );

		if ( ! is_string( $host ) || strtolower( $host ) !== $this->server->host() ) {
			return null;
		}

		$sections = isset( $update['sections'] ) && is_array( $update['sections'] ) ? $update['sections'] : array();

		return array(
			'version'      => $version,
			'package'      => $package,
			'requires'     => isset( $update['requires'] ) ? (string) $update['requires'] : '',
			'requires_php' => isset( $update['requires_php'] ) ? (string) $update['requires_php'] : '',
			'tested'       => isset( $update['tested'] ) ? (string) $update['tested'] : '',
			'last_updated' => isset( $update['last_updated'] ) ? (string) $update['last_updated'] : '',
			'sections'     => array_map(
				static fn ( $section ): string => wp_kses_post( (string) $section ),
				$sections
			),
		);
	}

	/**
	 * La actualización en la forma que espera WordPress.
	 *
	 * @param array<string, mixed> $update Actualización ya aceptada.
	 */
	private function item( array $update ): stdClass {
		$item = new stdClass();

		$item->slug         = $this->slug;
		$item->plugin       = $this->basename;
		$item->new_version  = (string) $update['version'];
		$item->version      = (string) $update['version'];
		$item->package      = (string) $update['package'];
		$item->url          = 'https://' . $this->server->host();
		$item->requires     = (string) $update['requires'];
		$item->requires_php = (string) $update['requires_php'];
		$item->tested       = (string) $update['tested'];
		$item->last_updated = (string) $update['last_updated'];

		return $item;
	}
}
