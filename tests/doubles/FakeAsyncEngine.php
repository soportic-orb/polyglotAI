<?php
/**
 * Motor asíncrono de mentira para las pruebas.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Doubles;

use PolyglotAI\Engines\AsyncBatchEngineInterface;
use PolyglotAI\Engines\BatchResult;
use PolyglotAI\Engines\EngineContext;
use PolyglotAI\Engines\EngineException;
use PolyglotAI\Engines\TranslationRequest;
use PolyglotAI\Engines\Usage;

/**
 * Simula el ciclo completo de un lote: enviar, esperar y recoger.
 *
 * Guarda los trozos que se le envían para poder devolver resultados coherentes
 * con ellos, que es justo lo que una traducción de sitio completo necesita
 * comprobar: que lo que vuelve se empareja con lo que se mandó.
 */
final class FakeAsyncEngine implements AsyncBatchEngineInterface {

	/** @var array<string, array<string, TranslationRequest[]>> Trozos por lote. */
	public array $sent = array();

	/** @var array<string, string> Estado de cada lote. */
	public array $statuses = array();

	/** @var string[] Lotes cancelados. */
	public array $cancelled = array();

	/** @var array<string, string> Traducciones a devolver, por texto original. */
	public array $dictionary = array();

	/** @var string[] Textos que se devolverán como fallo. */
	public array $failing = array();

	/** @var string|null Mensaje de error a lanzar al crear un lote. */
	public ?string $fail_on_create = null;

	/**
	 * Cuántos lotes se han creado.
	 *
	 * @var int
	 */
	public int $created = 0;

	/**
	 * Identificador del motor.
	 */
	public function id(): string {
		return 'fake-async';
	}

	/**
	 * Nombre legible.
	 */
	public function label(): string {
		return 'Fake async';
	}

	/**
	 * Admite lotes asíncronos.
	 */
	public function supports_async_batch(): bool {
		return true;
	}

	/**
	 * Cadenas por trozo.
	 */
	public function max_batch_size(): int {
		return 2;
	}

	/**
	 * Traducción en línea, que aquí no se usa.
	 *
	 * @param TranslationRequest[] $requests Peticiones.
	 * @param EngineContext        $context  Contexto.
	 */
	public function translate( array $requests, EngineContext $context ): BatchResult {
		unset( $requests, $context );

		return new BatchResult();
	}

	/**
	 * Estima los tokens de entrada.
	 *
	 * @param TranslationRequest[] $requests Cadenas.
	 * @param EngineContext        $context  Contexto.
	 */
	public function estimate_input_tokens( array $requests, EngineContext $context ): int {
		unset( $context );

		// Diez por cadena: basta para comprobar que la cuenta llega al panel.
		return count( $requests ) * 10;
	}

	/**
	 * Guarda el lote y lo deja en curso.
	 *
	 * @param array<string, TranslationRequest[]> $chunks  Trozos.
	 * @param EngineContext                       $context Contexto.
	 *
	 * @throws EngineException Cuando se le ha pedido fallar.
	 */
	public function create_batch( array $chunks, EngineContext $context ): string {
		unset( $context );

		if ( null !== $this->fail_on_create ) {
			throw new EngineException( $this->fail_on_create, 0, false );
		}

		++$this->created;

		$batch_id = 'msgbatch_' . $this->created;

		$this->sent[ $batch_id ]     = $chunks;
		$this->statuses[ $batch_id ] = 'in_progress';

		return $batch_id;
	}

	/**
	 * Estado del lote.
	 *
	 * @param string $batch_id Identificador.
	 */
	public function batch_status( string $batch_id ): string {
		return $this->statuses[ $batch_id ] ?? 'in_progress';
	}

	/**
	 * Si el lote ha terminado.
	 *
	 * @param string $status Estado.
	 */
	public function batch_has_ended( string $status ): bool {
		return 'ended' === $status;
	}

	/**
	 * Marca un lote como terminado.
	 *
	 * @param string $batch_id Identificador.
	 */
	public function finish( string $batch_id ): void {
		$this->statuses[ $batch_id ] = 'ended';
	}

	/**
	 * Devuelve los resultados del lote.
	 *
	 * @param string                              $batch_id Identificador.
	 * @param array<string, TranslationRequest[]> $chunks   Trozos.
	 * @return array<string, BatchResult>
	 */
	public function collect_batch( string $batch_id, array $chunks ): array {
		unset( $batch_id );

		$results = array();

		foreach ( $chunks as $custom_id => $chunk ) {
			$translations = array();
			$failures     = array();

			foreach ( $chunk as $request ) {
				if ( in_array( $request->text, $this->failing, true ) ) {
					$failures[ $request->id ] = 'No ha pasado la validación';

					continue;
				}

				if ( isset( $this->dictionary[ $request->text ] ) ) {
					$translations[ $request->id ] = $this->dictionary[ $request->text ];
				}
			}

			$results[ (string) $custom_id ] = new BatchResult( $translations, $failures, new Usage( 10, 5 ) );
		}

		return $results;
	}

	/**
	 * Cancela un lote.
	 *
	 * @param string $batch_id Identificador.
	 */
	public function cancel_batch( string $batch_id ): void {
		$this->cancelled[]           = $batch_id;
		$this->statuses[ $batch_id ] = 'canceled';
	}
}
