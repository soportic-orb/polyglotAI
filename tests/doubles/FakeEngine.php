<?php
/**
 * Motor de traducción de mentira para las pruebas.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Doubles;

use PolyglotAI\Engines\BatchResult;
use PolyglotAI\Engines\EngineContext;
use PolyglotAI\Engines\EngineException;
use PolyglotAI\Engines\TranslationEngineInterface;
use PolyglotAI\Engines\TranslationRequest;
use PolyglotAI\Engines\Usage;

/**
 * Motor de mentira: devuelve lo que se le diga y anota lo que recibió.
 */
final class FakeEngine implements TranslationEngineInterface {

	/** @var array<int, string> Textos recibidos, en orden. */
	public array $received = array();

	/** @var array<string, string> Frase => traducción. */
	public array $dictionary = array();

	/** @var string|null Mensaje de error a lanzar, si lo hay. */
	public ?string $fail_with = null;

	/**
	 * Identificador del motor.
	 */
	public function id(): string {
		return 'fake';
	}

	/**
	 * Nombre legible.
	 */
	public function label(): string {
		return 'Fake';
	}

	/**
	 * @param array<int, TranslationRequest> $requests Peticiones.
	 * @param EngineContext                  $context  Contexto.
	 * @throws EngineException Cuando se le ha pedido fallar.
	 */
	public function translate( array $requests, EngineContext $context ): BatchResult {
		if ( null !== $this->fail_with ) {
			throw new EngineException( $this->fail_with );
		}

		$translations = array();

		foreach ( $requests as $request ) {
			$this->received[] = $request->text;

			if ( isset( $this->dictionary[ $request->text ] ) ) {
				$translations[ $request->id ] = $this->dictionary[ $request->text ];
			}
		}

		return new BatchResult( $translations, array(), new Usage( 10, 5 ) );
	}

	/**
	 * Si admite lotes asíncronos.
	 */
	public function supports_async_batch(): bool {
		return false;
	}

	/**
	 * Cadenas por lote.
	 */
	public function max_batch_size(): int {
		return 50;
	}
}
