<?php
/**
 * Traducción del sitio completo.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Jobs;

use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Engines\AsyncBatchEngineInterface;
use PolyglotAI\Engines\EngineException;
use PolyglotAI\Engines\TranslationRequest;
use PolyglotAI\Engines\Usage;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Options;
use PolyglotAI\Translation\DictionaryFactory;
use PolyglotAI\Translation\Memory;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StringType;

/**
 * Traduce todo lo pendiente de un idioma con la Batches API.
 *
 * El trabajo se hace en tres momentos separados por horas —enviar, preguntar,
 * recoger— y cada uno es una pasada de Action Scheduler. No es una elección
 * estética: una traducción de sitio completo puede tardar más que cualquier
 * límite de ejecución de PHP, así que **nada de esto puede vivir dentro de una
 * sola petición**.
 *
 * De ahí que el estado se guarde entre pasadas (`SiteRun`): es lo que hace que
 * el trabajo sea pausable y reanudable de verdad, y no solo que parezca que lo
 * es. Pausar deja el lote donde está —lo ya procesado se cobra igual— y
 * reanudar vuelve a preguntar por él.
 *
 * Se envía **un lote por pasada**, no el sitio entero de golpe: así el mapa de
 * trozos que hay que recordar tiene un tamaño acotado, y si algo va mal se
 * pierde una tanda y no el trabajo de un día.
 */
final class SiteTranslator {

	/** Acción de Action Scheduler. */
	public const HOOK = 'pgai_translate_site';

	/** Grupo de Action Scheduler. */
	private const GROUP = 'polyglot-ai';

	/** Cadenas como mucho por lote enviado. */
	private const STRINGS_PER_RUN = 2000;

	/** Segundos entre dos consultas del estado de un lote. */
	private const POLL_INTERVAL = 120;

	/**
	 * Constructor.
	 *
	 * @param AsyncBatchEngineInterface $engine       Motor con lotes asíncronos.
	 * @param TranslationRepository     $translations Repositorio de traducciones.
	 * @param SourceRepository          $sources      Repositorio de cadenas.
	 * @param ApiLogRepository          $log          Registro de consumo.
	 * @param LanguageRegistry          $languages    Idiomas del sitio.
	 * @param Options                   $options      Ajustes.
	 * @param Budget                    $budget       Tope mensual de consumo.
	 * @param ContextFactory            $contexts     Contexto lingüístico.
	 * @param Memory                    $memory       Memoria de traducción.
	 */
	public function __construct(
		private readonly AsyncBatchEngineInterface $engine,
		private readonly TranslationRepository $translations,
		private readonly SourceRepository $sources,
		private readonly ApiLogRepository $log,
		private readonly LanguageRegistry $languages,
		private readonly Options $options,
		private readonly Budget $budget,
		private readonly ContextFactory $contexts,
		private readonly Memory $memory
	) {}

	/**
	 * Engancha la acción programada.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Arranca una traducción de sitio completo.
	 *
	 * @param string $language Locale.
	 * @return SiteRun|null La nueva pasada, o null si no hay nada que traducir.
	 */
	public function start( string $language ): ?SiteRun {
		$target = $this->languages->by_locale( $language );

		if ( null === $target || ! $target->active || $this->languages->is_default( $target->slug ) ) {
			return null;
		}

		$pending = $this->translations->counts( $language )[ Status::Pending->value ] ?? 0;

		if ( 0 === $pending ) {
			return null;
		}

		$run = new SiteRun(
			$language,
			SiteRun::QUEUED,
			null,
			array(),
			$pending,
			0,
			0,
			current_time( 'mysql', true )
		);

		$run->save();

		$this->schedule( $language, 0 );

		return $run;
	}

	/**
	 * Para una pasada sin cancelar el lote en curso.
	 *
	 * @param string $language Locale.
	 */
	public function pause( string $language ): ?SiteRun {
		$run = SiteRun::load( $language );

		if ( null === $run || ! $run->is_active() ) {
			return $run;
		}

		// El lote sigue procesándose en el proveedor: pararlo aquí solo evita
		// que sigamos preguntando y enviando más. Lo ya enviado se cobra.
		$paused = $run->with( array( 'status' => SiteRun::PAUSED ) );
		$paused->save();

		return $paused;
	}

	/**
	 * Reanuda una pasada parada.
	 *
	 * @param string $language Locale.
	 */
	public function resume( string $language ): ?SiteRun {
		$run = SiteRun::load( $language );

		if ( null === $run || SiteRun::PAUSED !== $run->status ) {
			return $run;
		}

		// Si había un lote en vuelo se vuelve a esperar por él; si no, se
		// prepara uno nuevo con lo que quede pendiente.
		$resumed = $run->with(
			array( 'status' => null === $run->batch_id ? SiteRun::QUEUED : SiteRun::WAITING )
		);

		$resumed->save();

		$this->schedule( $language, 0 );

		return $resumed;
	}

	/**
	 * Cancela la pasada y el lote que hubiera en vuelo.
	 *
	 * @param string $language Locale.
	 */
	public function cancel( string $language ): void {
		$run = SiteRun::load( $language );

		if ( null !== $run && null !== $run->batch_id ) {
			try {
				$this->engine->cancel_batch( $run->batch_id );
			} catch ( EngineException $error ) {
				// Cancelar es una cortesía con el proveedor: si falla, lo que
				// importa —dejar de gastar aquí— ya está hecho al borrar la
				// pasada.
				unset( $error );
			}
		}

		SiteRun::forget( $language );
	}

	/**
	 * Estado actual.
	 *
	 * @param string $language Locale.
	 */
	public function status( string $language ): ?SiteRun {
		return SiteRun::load( $language );
	}

	/**
	 * Cuánto costaría traducir lo que queda pendiente.
	 *
	 * @param string $language Locale.
	 * @return array{strings:int, input_tokens:int}
	 */
	public function estimate( string $language ): array {
		$pending = $this->translations->counts( $language )[ Status::Pending->value ] ?? 0;

		if ( 0 === $pending ) {
			return array(
				'strings'      => 0,
				'input_tokens' => 0,
			);
		}

		// Se estima sobre una muestra del principio de la cola —un trozo— y se
		// multiplica por los trozos que harían falta. Traerse cien mil cadenas
		// de la base de datos para contarlas no afinaría: el prompt del sistema
		// es idéntico en todos los trozos y las cadenas son parecidas entre sí.
		$sample   = $this->translations->pending( $language, $this->engine->max_batch_size() );
		$requests = array();

		foreach ( $sample as $row ) {
			$requests[] = new TranslationRequest(
				(string) $row['source_id'],
				(string) $row['original'],
				StringType::tryFrom( (string) $row['type'] ) ?? StringType::Text,
				isset( $row['context'] ) ? (string) $row['context'] : null
			);
		}

		if ( array() === $requests ) {
			return array(
				'strings'      => $pending,
				'input_tokens' => 0,
			);
		}

		try {
			$per_chunk = $this->engine->estimate_input_tokens( $requests, $this->contexts->for_language( $language ) );
		} catch ( EngineException $error ) {
			unset( $error );

			return array(
				'strings'      => $pending,
				'input_tokens' => 0,
			);
		}

		$chunks = (int) ceil( $pending / max( 1, $this->engine->max_batch_size() ) );

		return array(
			'strings'      => $pending,
			'input_tokens' => $per_chunk * $chunks,
		);
	}

	/**
	 * Una pasada del trabajo.
	 *
	 * @param string $language Locale.
	 */
	public function run( string $language ): void {
		$run = SiteRun::load( $language );

		if ( null === $run || ! $run->is_active() ) {
			return;
		}

		if ( $this->budget->exhausted() ) {
			/** This action is documented in src/Jobs/PendingTranslator.php */
			do_action( 'pgai_budget_exhausted', $language );

			$this->fail( $run, __( 'Se ha alcanzado el tope mensual de tokens.', 'polyglot-ai' ) );

			return;
		}

		try {
			if ( SiteRun::QUEUED === $run->status ) {
				$this->send( $run );

				return;
			}

			$this->collect( $run );
		} catch ( EngineException $error ) {
			$this->log->record(
				$this->engine->id(),
				(string) $this->options->get( 'model' ),
				$language,
				0,
				new Usage(),
				'error',
				$error->getMessage(),
				$run->batch_id
			);

			$this->fail( $run, $error->getMessage() );
		}
	}

	/**
	 * Prepara y envía un lote.
	 *
	 * @param SiteRun $run Pasada.
	 *
	 * @throws EngineException Si el lote no se puede crear.
	 */
	private function send( SiteRun $run ): void {
		$pending = $this->translations->pending( $run->language, self::STRINGS_PER_RUN );

		if ( array() === $pending ) {
			$this->finish( $run );

			return;
		}

		// Antes de pagar por nada: lo que ya está traducido en otro sitio del
		// mismo texto se copia y no entra en el lote (ADR-05).
		$reused = $this->reuse( $pending, $run->language );

		$requests = array();

		foreach ( $pending as $row ) {
			$source_id = (int) $row['source_id'];

			if ( isset( $reused[ $source_id ] ) ) {
				continue;
			}

			$requests[] = new TranslationRequest(
				(string) $source_id,
				(string) $row['original'],
				StringType::tryFrom( (string) $row['type'] ) ?? StringType::Text,
				isset( $row['context'] ) ? (string) $row['context'] : null
			);
		}

		if ( array() === $requests ) {
			// Todo lo de esta tanda salió de la memoria: se sigue con la
			// siguiente sin llamar a la API.
			$run->with( array( 'done' => $run->done + count( $reused ) ) )->save();
			$this->schedule( $run->language, 0 );

			return;
		}

		$chunks = array();
		$map    = array();

		foreach ( array_chunk( $requests, $this->engine->max_batch_size() ) as $index => $chunk ) {
			$custom_id = 'pgai-' . $index;

			$chunks[ $custom_id ] = $chunk;
			$map[ $custom_id ]    = array_map(
				static fn ( TranslationRequest $request ): int => (int) $request->id,
				$chunk
			);
		}

		$batch_id = $this->engine->create_batch( $chunks, $this->contexts->for_language( $run->language ) );

		$run->with(
			array(
				'status'   => SiteRun::WAITING,
				'batch_id' => $batch_id,
				'chunks'   => $map,
				'done'     => $run->done + count( $reused ),
			)
		)->save();

		$this->schedule( $run->language, self::POLL_INTERVAL );
	}

	/**
	 * Copia las traducciones que ya existan del mismo texto.
	 *
	 * @param array<int, array<string, mixed>> $pending  Filas pendientes.
	 * @param string                           $language Locale.
	 * @return array<int, string> Identificador => traducción copiada.
	 */
	private function reuse( array $pending, string $language ): array {
		$ids = array_map(
			static fn ( array $row ): int => (int) $row['source_id'],
			$pending
		);

		$found = $this->memory->lookup( $ids, $language );
		$saved = array();

		foreach ( $found as $source_id => $translation ) {
			// Entra como automática: es una traducción de máquina, aunque esta
			// vez no haya habido que pedirla.
			if ( $this->translations->save(
				$source_id,
				$language,
				$translation,
				Status::Automatic,
				false,
				$this->engine->id(),
				'memoria'
			) ) {
				$saved[ $source_id ] = $translation;
			}
		}

		if ( array() !== $saved ) {
			DictionaryFactory::invalidate();
		}

		return $saved;
	}

	/**
	 * Pregunta por el lote y aplica lo que haya llegado.
	 *
	 * @param SiteRun $run Pasada.
	 *
	 * @throws EngineException Si no se puede consultar ni recoger el lote.
	 */
	private function collect( SiteRun $run ): void {
		if ( null === $run->batch_id ) {
			$run->with( array( 'status' => SiteRun::QUEUED ) )->save();
			$this->schedule( $run->language, 0 );

			return;
		}

		if ( ! $this->engine->batch_has_ended( $this->engine->batch_status( $run->batch_id ) ) ) {
			$this->schedule( $run->language, self::POLL_INTERVAL );

			return;
		}

		// Se piden todos los originales de una vez: para emparejar lo que
		// devuelve la API con lo que se envió hace horas hace falta el texto
		// otra vez, y pedirlo de uno en uno serían miles de consultas.
		$all = array();

		foreach ( $run->chunks as $source_ids ) {
			foreach ( $source_ids as $id ) {
				$all[] = (int) $id;
			}
		}

		$originals = $this->sources->by_ids( $all );
		$chunks    = array();

		foreach ( $run->chunks as $custom_id => $source_ids ) {
			$requests = array();

			foreach ( $source_ids as $id ) {
				$id  = (int) $id;
				$row = $originals[ $id ] ?? null;

				// Una cadena que ya no existe no se puede emparejar: se deja
				// fuera y lo que devuelva la API para ella se descarta solo.
				if ( null === $row ) {
					continue;
				}

				$requests[] = new TranslationRequest(
					(string) $id,
					$row['original'],
					StringType::tryFrom( $row['type'] ) ?? StringType::Text,
					$row['context']
				);
			}

			$chunks[ (string) $custom_id ] = $requests;
		}

		$results = $this->engine->collect_batch( $run->batch_id, $chunks );
		$saved   = 0;
		$failed  = 0;
		$usage   = new Usage();

		foreach ( $results as $result ) {
			foreach ( $result->translations as $id => $translation ) {
				if ( $this->translations->save(
					(int) $id,
					$run->language,
					$translation,
					Status::Automatic,
					false,
					$this->engine->id(),
					(string) $this->options->get( 'model' )
				) ) {
					++$saved;
				}
			}

			foreach ( $result->failures as $id => $reason ) {
				$this->translations->mark_error( (int) $id, $run->language, $reason );
				++$failed;
			}

			$usage = $usage->plus( $result->usage );
		}

		$this->log->record(
			$this->engine->id(),
			(string) $this->options->get( 'model' ),
			$run->language,
			$saved + $failed,
			$usage,
			'ok',
			null,
			$run->batch_id
		);

		DictionaryFactory::invalidate();

		// Vuelta a empezar con lo que quede: el lote siguiente se prepara en la
		// pasada siguiente, no aquí, para no encadenar dos llamadas caras
		// dentro de la misma ejecución.
		$next = $run->with(
			array(
				'status'   => SiteRun::QUEUED,
				'batch_id' => null,
				'chunks'   => array(),
				'done'     => $run->done + $saved,
				'failed'   => $run->failed + $failed,
			)
		);

		$next->save();

		$this->schedule( $run->language, 0 );
	}

	/**
	 * Da la pasada por terminada.
	 *
	 * @param SiteRun $run Pasada.
	 */
	private function finish( SiteRun $run ): void {
		$run->with(
			array(
				'status'   => SiteRun::DONE,
				'batch_id' => null,
				'chunks'   => array(),
			)
		)->save();
	}

	/**
	 * Para la pasada por un fallo.
	 *
	 * @param SiteRun $run     Pasada.
	 * @param string  $message Motivo.
	 */
	private function fail( SiteRun $run, string $message ): void {
		$run->with(
			array(
				'status'  => SiteRun::FAILED,
				'message' => $message,
			)
		)->save();
	}

	/**
	 * Programa la siguiente pasada.
	 *
	 * @param string $language Locale.
	 * @param int    $delay    Segundos de espera.
	 */
	private function schedule( string $language, int $delay ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array( $language ), self::GROUP ) ) {
			return;
		}

		as_schedule_single_action( time() + max( 0, $delay ), self::HOOK, array( $language ), self::GROUP );
	}
}
