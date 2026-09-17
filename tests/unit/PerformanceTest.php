<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests;

use PolyglotAI\Html\DocumentProcessor;
use PolyglotAI\Html\Escaper;
use PolyglotAI\Html\ExclusionRules;
use PolyglotAI\Html\ExtractedString;
use PolyglotAI\Html\HtmlApiDriver;
use PolyglotAI\Html\OffsetTagProcessor;
use PolyglotAI\Html\SafetyCheck;
use PolyglotAI\Html\Splicer;
use PolyglotAI\Html\TagScanner;
use PHPUnit\Framework\TestCase;

/**
 * Vigilancia del coste de procesar una página.
 *
 * Un test de reloj de pared depende de lo rápida que sea la máquina que lo
 * ejecuta, así que aquí el coste del driver se mide SIEMPRE en relación con el
 * del barrido en crudo de WP_HTML_Tag_Processor sobre ese mismo documento. Esa
 * razón no depende de la máquina: si el CI va a la mitad de velocidad, los dos
 * lados de la comparación se ralentizan igual.
 *
 * El umbral está calibrado con mediciones reales sobre un documento de 128 KB,
 * no a ojo: el barrido lineal da una razón de 3,6 y una regresión cuadrática
 * introducida a propósito la sube a 7,0. El límite de 5,0 deja margen de sobra
 * para el ruido y sigue separando ambos casos.
 *
 * @covers \PolyglotAI\Html\HtmlApiDriver
 */
final class PerformanceTest extends TestCase {

	/**
	 * Cuántas veces puede costar el driver lo que cuesta el barrido en crudo del
	 * mismo documento. Medido: 3,6 con coste lineal, 7,0 con coste cuadrático.
	 */
	private const MAX_OVERHEAD_RATIO = 5.0;

	/** Copias del bloque de producto: unos 128 KB, una página real grande. */
	private const COPIES = 256;

	/** Repeticiones por medición, para amortiguar el ruido. */
	private const RUNS = 3;

	private HtmlApiDriver $driver;
	private DocumentProcessor $processor;

	protected function setUp(): void {
		$this->driver    = new HtmlApiDriver( new TagScanner(), new ExclusionRules() );
		$this->processor = new DocumentProcessor(
			$this->driver,
			new Splicer(),
			new Escaper(),
			new SafetyCheck()
		);
	}

	/**
	 * Genera una página repitiendo su bloque de producto.
	 *
	 * @param int $copies Número de copias del bloque.
	 */
	private function page( int $copies ): string {
		$base  = (string) file_get_contents( PGAI_TESTS_DIR . '/fixtures/pagina.html' );
		$from  = strpos( $base, '<div class="producto">' );
		$to    = strpos( $base, '<section translate="no">' );
		$block = substr( $base, (int) $from, (int) $to - (int) $from );

		return substr_replace( $base, str_repeat( $block, $copies ), (int) $from, strlen( $block ) );
	}

	/**
	 * Milisegundos que cuesta una operación, promediados.
	 *
	 * @param callable $operation Operación a medir.
	 */
	private function measure( callable $operation ): float {
		$operation();

		$started = hrtime( true );

		for ( $run = 0; $run < self::RUNS; $run++ ) {
			$operation();
		}

		return ( hrtime( true ) - $started ) / self::RUNS / 1e6;
	}

	/**
	 * Coste del barrido en crudo del mismo documento, como referencia.
	 *
	 * @param string $html Documento.
	 */
	private function baseline( string $html ): float {
		return $this->measure(
			static function () use ( $html ): void {
				$processor = new OffsetTagProcessor( $html );

				while ( $processor->next_token() ) {
					continue;
				}
			}
		);
	}

	public function test_el_driver_no_multiplica_el_coste_del_barrido(): void {
		$html = $this->page( self::COPIES );

		$baseline = $this->baseline( $html );
		$extract  = $this->measure( fn() => $this->driver->extract( $html ) );
		$ratio    = $extract / max( $baseline, 0.001 );

		$this->assertLessThan(
			self::MAX_OVERHEAD_RATIO,
			$ratio,
			sprintf(
				'El driver cuesta %.1f veces el barrido en crudo (%.2f ms frente a %.2f ms sobre %.0f KB). ' .
				'Por encima de %.1f suele significar que algo ha dejado de ser lineal.',
				$ratio,
					$extract,
				$baseline,
				strlen( $html ) / 1024,
				self::MAX_OVERHEAD_RATIO
			)
		);
	}

	public function test_sustituir_no_cuesta_mas_que_extraer(): void {
		// El empalme debe ser marginal frente al barrido. Si un día cuesta más,
		// es que ha dejado de ser un empalme.
		$html = $this->page( 64 );

		$extract_ms = $this->measure( fn() => $this->driver->extract( $html ) );
		$full_ms    = $this->measure(
			fn() => $this->processor->translate( $html, static fn( ExtractedString $u ): string => $u->value . '.' )
		);

		$this->assertLessThan(
			$extract_ms * 2,
			$full_ms,
			sprintf( 'Traducir entero cuesta %.2f ms frente a %.2f ms de solo extraer.', $full_ms, $extract_ms )
		);
	}

	public function test_una_pagina_sin_traducciones_no_copia_el_documento(): void {
		// Cuando no hay nada que sustituir se devuelve la MISMA cadena, sin
		// reconstruirla: es el camino de la mayoría de las peticiones.
		$html   = $this->page( 8 );
		$result = $this->processor->translate( $html, static fn(): ?string => null );

		$this->assertSame( $html, $result );
	}
}
