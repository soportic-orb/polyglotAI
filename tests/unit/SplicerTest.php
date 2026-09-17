<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests;

use InvalidArgumentException;
use PolyglotAI\Html\Replacement;
use PolyglotAI\Html\Splicer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PolyglotAI\Html\Splicer
 */
final class SplicerTest extends TestCase {

	private Splicer $splicer;

	protected function setUp(): void {
		$this->splicer = new Splicer();
	}

	public function test_devuelve_el_original_sin_sustituciones(): void {
		$this->assertSame( 'hola', $this->splicer->apply( 'hola', array() ) );
	}

	public function test_aplica_varias_sustituciones_de_longitud_distinta(): void {
		// Las posiciones se calculan sobre el ORIGINAL: si se aplicaran de
		// izquierda a derecha, la segunda quedaría desplazada.
		$subject      = 'AAA BBB CCC';
		$replacements = array(
			new Replacement( 0, 3, 'largooooo' ),
			new Replacement( 4, 3, 'x' ),
			new Replacement( 8, 3, 'mediano' ),
		);

		$this->assertSame( 'largooooo x mediano', $this->splicer->apply( $subject, $replacements ) );
	}

	public function test_el_orden_de_entrada_es_indiferente(): void {
		$subject = 'AAA BBB CCC';

		$forward = $this->splicer->apply(
			$subject,
			array( new Replacement( 0, 3, '1' ), new Replacement( 8, 3, '2' ) )
		);

		$backward = $this->splicer->apply(
			$subject,
			array( new Replacement( 8, 3, '2' ), new Replacement( 0, 3, '1' ) )
		);

		$this->assertSame( $forward, $backward );
		$this->assertSame( '1 BBB 2', $forward );
	}

	public function test_conserva_los_bytes_multibyte_no_tocados(): void {
		$subject = 'Ñandú «test» 😀 final';
		$result  = $this->splicer->apply( $subject, array( new Replacement( strlen( $subject ) - 5, 5, 'FINAL' ) ) );

		$this->assertSame( 'Ñandú «test» 😀 FINAL', $result );
	}

	public function test_rechaza_sustituciones_solapadas(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->splicer->apply( 'AAAABBBB', array( new Replacement( 0, 5, 'x' ), new Replacement( 3, 3, 'y' ) ) );
	}

	public function test_rechaza_sustituciones_fuera_de_rango(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->splicer->apply( 'corta', array( new Replacement( 3, 99, 'x' ) ) );
	}
}
