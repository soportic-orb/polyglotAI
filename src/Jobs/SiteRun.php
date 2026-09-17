<?php
/**
 * Estado de una traducción de sitio completo.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Jobs;

/**
 * Lo que hay que recordar de una traducción masiva entre petición y petición.
 *
 * Vive en una opción por idioma y no en una tabla nueva: es **una** fila por
 * idioma activo, se lee solo cuando alguien mira el progreso o cuando corre la
 * tarea, y una tabla habría significado migrar el esquema para guardar como
 * mucho ocho filas.
 *
 * Lo voluminoso es el mapa de trozos —qué cadenas van en cada `custom_id`—, y
 * por eso la opción va **sin autocarga**: no tiene por qué estar en memoria en
 * cada visita de cada visitante.
 */
final class SiteRun {

	/** Prefijo de la opción. */
	private const OPTION_PREFIX = 'pgai_site_run_';

	/** Recién creada; toca preparar y enviar el lote. */
	public const QUEUED = 'queued';

	/** Enviada a la API; toca esperar. */
	public const WAITING = 'waiting';

	/** Terminada. */
	public const DONE = 'done';

	/** Parada a petición de una persona. */
	public const PAUSED = 'paused';

	/** Parada por un fallo. */
	public const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param string                    $language   Locale.
	 * @param string                    $status     Estado.
	 * @param string|null               $batch_id   Lote en curso.
	 * @param array<string, array<int>> $chunks     custom_id => identificadores de cadena.
	 * @param int                       $total      Cadenas al empezar.
	 * @param int                       $done       Cadenas traducidas.
	 * @param int                       $failed     Cadenas que fallaron.
	 * @param string                    $started_at Cuándo empezó.
	 * @param string                    $updated_at Último cambio.
	 * @param string                    $message    Último mensaje de error.
	 */
	public function __construct(
		public readonly string $language,
		public readonly string $status = self::QUEUED,
		public readonly ?string $batch_id = null,
		public readonly array $chunks = array(),
		public readonly int $total = 0,
		public readonly int $done = 0,
		public readonly int $failed = 0,
		public readonly string $started_at = '',
		public readonly string $updated_at = '',
		public readonly string $message = ''
	) {}

	/**
	 * Carga el estado guardado de un idioma.
	 *
	 * @param string $language Locale.
	 */
	public static function load( string $language ): ?self {
		$stored = get_option( self::OPTION_PREFIX . $language, null );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		/** @var array<string, array<int>> $chunks */
		$chunks = is_array( $stored['chunks'] ?? null ) ? $stored['chunks'] : array();

		return new self(
			$language,
			(string) ( $stored['status'] ?? self::QUEUED ),
			isset( $stored['batch_id'] ) && is_string( $stored['batch_id'] ) ? $stored['batch_id'] : null,
			$chunks,
			(int) ( $stored['total'] ?? 0 ),
			(int) ( $stored['done'] ?? 0 ),
			(int) ( $stored['failed'] ?? 0 ),
			(string) ( $stored['started_at'] ?? '' ),
			(string) ( $stored['updated_at'] ?? '' ),
			(string) ( $stored['message'] ?? '' )
		);
	}

	/**
	 * Guarda el estado.
	 */
	public function save(): void {
		update_option(
			self::OPTION_PREFIX . $this->language,
			array(
				'status'     => $this->status,
				'batch_id'   => $this->batch_id,
				'chunks'     => $this->chunks,
				'total'      => $this->total,
				'done'       => $this->done,
				'failed'     => $this->failed,
				'started_at' => $this->started_at,
				'updated_at' => current_time( 'mysql', true ),
				'message'    => $this->message,
			),
			// Sin autocarga: el mapa de trozos puede ocupar, y no tiene por qué
			// estar en memoria en cada visita de cada visitante.
			false
		);
	}

	/**
	 * Borra el estado de un idioma.
	 *
	 * @param string $language Locale.
	 */
	public static function forget( string $language ): void {
		delete_option( self::OPTION_PREFIX . $language );
	}

	/**
	 * Copia con otros valores.
	 *
	 * @param array<string, mixed> $changes Campos a cambiar.
	 */
	public function with( array $changes ): self {
		/** @var array<string, array<int>> $chunks */
		$chunks = $changes['chunks'] ?? $this->chunks;

		return new self(
			$this->language,
			(string) ( $changes['status'] ?? $this->status ),
			array_key_exists( 'batch_id', $changes ) ? $changes['batch_id'] : $this->batch_id,
			$chunks,
			(int) ( $changes['total'] ?? $this->total ),
			(int) ( $changes['done'] ?? $this->done ),
			(int) ( $changes['failed'] ?? $this->failed ),
			(string) ( $changes['started_at'] ?? $this->started_at ),
			(string) ( $changes['updated_at'] ?? $this->updated_at ),
			(string) ( $changes['message'] ?? $this->message )
		);
	}

	/**
	 * Si la traducción sigue su curso.
	 */
	public function is_active(): bool {
		return in_array( $this->status, array( self::QUEUED, self::WAITING ), true );
	}

	/**
	 * Porcentaje hecho, de 0 a 100.
	 */
	public function progress(): int {
		if ( $this->total <= 0 ) {
			return self::DONE === $this->status ? 100 : 0;
		}

		return (int) min( 100, round( ( $this->done + $this->failed ) / $this->total * 100 ) );
	}

	/**
	 * Forma en que se le pasa al navegador.
	 *
	 * El mapa de trozos no sale: puede tener decenas de miles de
	 * identificadores y al panel no le dice nada.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'language'   => $this->language,
			'status'     => $this->status,
			'batch_id'   => $this->batch_id,
			'total'      => $this->total,
			'done'       => $this->done,
			'failed'     => $this->failed,
			'progress'   => $this->progress(),
			'active'     => $this->is_active(),
			'started_at' => $this->started_at,
			'updated_at' => $this->updated_at,
			'message'    => $this->message,
		);
	}
}
