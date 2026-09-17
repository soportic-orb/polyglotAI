<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests;

use PolyglotAI\Translation\Hasher;
use PolyglotAI\Translation\Normalizer;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use PolyglotAI\Translation\StringType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PolyglotAI\Translation\Normalizer
 * @covers \PolyglotAI\Translation\Hasher
 * @covers \PolyglotAI\Translation\StatusPrecedence
 */
final class TranslationRulesTest extends TestCase {

	private Normalizer $normalizer;
	private Hasher $hasher;
	private StatusPrecedence $precedence;

	protected function setUp(): void {
		$this->normalizer = new Normalizer();
		$this->hasher     = new Hasher( $this->normalizer );
		$this->precedence = new StatusPrecedence();
	}

	/**
	 * @param string $input    Entrada.
	 * @param string $expected Salida esperada.
	 *
	 * @dataProvider proveedor_de_normalizacion
	 */
	public function test_normaliza_el_espacio_en_blanco( string $input, string $expected ): void {
		$this->assertSame( $expected, $this->normalizer->normalize( $input ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function proveedor_de_normalizacion(): array {
		return array(
			'recorta extremos'     => array( '  Hola  ', 'Hola' ),
			'colapsa saltos'       => array( "Hola\n\n  món", 'Hola món' ),
			'colapsa tabuladores'  => array( "Hola\t\tmón", 'Hola món' ),
			'espacio duro'         => array( "Hola\u{00A0}\u{00A0}món", 'Hola món' ),
			'conserva el html'     => array( '  Hola <b>món</b>  ', 'Hola <b>món</b>' ),
			'conserva los acentos' => array( ' Cafè ñandú ', 'Cafè ñandú' ),
			'ya normalizada'       => array( 'Hola món', 'Hola món' ),
		);
	}

	public function test_el_espaciado_distinto_produce_el_mismo_hash(): void {
		// Es la razón de ser de la normalización: sin ella el diccionario se
		// llena de duplicados que hay que traducir y pagar por separado.
		$this->assertSame(
			$this->hasher->hash( "  Hola\n  món  ", StringType::Text ),
			$this->hasher->hash( 'Hola món', StringType::Text )
		);
	}

	public function test_el_tipo_y_el_contexto_cambian_el_hash(): void {
		$base = $this->hasher->hash( 'Inicio', StringType::Text );

		$this->assertNotSame( $base, $this->hasher->hash( 'Inicio', StringType::Slug ) );
		$this->assertNotSame( $base, $this->hasher->hash( 'Inicio', StringType::Text, 'menu' ) );
		$this->assertNotSame( $base, $this->hasher->hash( 'Inicio', StringType::Gettext, null, 'woocommerce' ) );
	}

	public function test_el_hash_es_estable_entre_llamadas(): void {
		$this->assertSame(
			$this->hasher->hash( 'Hola', StringType::Text ),
			$this->hasher->hash( 'Hola', StringType::Text )
		);
	}

	/**
	 * @param string $value    Cadena.
	 * @param bool   $expected Si es traducible.
	 *
	 * @dataProvider proveedor_de_traducibilidad
	 */
	public function test_descarta_las_cadenas_sin_contenido_traducible( string $value, bool $expected ): void {
		$this->assertSame( $expected, $this->normalizer->is_translatable( $value ) );
	}

	/**
	 * @return array<string, array{0:string, 1:bool}>
	 */
	public static function proveedor_de_traducibilidad(): array {
		return array(
			'texto normal'   => array( 'Hola', true ),
			'con acentos'    => array( 'Añadir al carrito', true ),
			'vacia'          => array( '', false ),
			'solo numeros'   => array( '19,99', false ),
			'solo simbolos'  => array( '— · ©', false ),
			'una letra'      => array( 'a', false ),
			'dos letras'     => array( 'Sí', true ),
			'precio'         => array( '19,99 €', false ),
			'alfabeto arabe' => array( 'مرحبا', true ),
		);
	}

	/**
	 * @param Status|null $current  Estado actual.
	 * @param Status      $incoming Estado entrante.
	 * @param bool        $forced   Si se ha pedido retraducir.
	 * @param bool        $expected Si debe permitirse.
	 *
	 * @dataProvider proveedor_de_precedencia
	 */
	public function test_precedencia_de_estados( ?Status $current, Status $incoming, bool $forced, bool $expected ): void {
		$this->assertSame( $expected, $this->precedence->can_overwrite( $current, $incoming, $forced ) );
	}

	/**
	 * @return array<string, array{0:Status|null, 1:Status, 2:bool, 3:bool}>
	 */
	public static function proveedor_de_precedencia(): array {
		return array(
			'sin traduccion previa'         => array( null, Status::Automatic, false, true ),
			'automatica sobre pendiente'    => array( Status::Pending, Status::Automatic, false, true ),
			'automatica sobre error'        => array( Status::Error, Status::Automatic, false, true ),
			'automatica sobre automatica'   => array( Status::Automatic, Status::Automatic, false, false ),
			'retraduccion sobre automatica' => array( Status::Automatic, Status::Automatic, true, true ),

			// El criterio de aceptación: lo que ha tocado una persona no lo pisa
			// una máquina, ni siquiera forzando la retraducción.
			'automatica sobre revisada'     => array( Status::Reviewed, Status::Automatic, false, false ),
			'automatica sobre manual'       => array( Status::Manual, Status::Automatic, false, false ),
			'retraduccion sobre revisada'   => array( Status::Reviewed, Status::Automatic, true, false ),
			'retraduccion sobre manual'     => array( Status::Manual, Status::Automatic, true, false ),

			// Una persona sí puede corregir cualquier cosa desde el editor.
			'manual sobre automatica'       => array( Status::Automatic, Status::Manual, false, true ),
			'manual sobre manual'           => array( Status::Manual, Status::Manual, false, true ),
			'revisada sobre manual'         => array( Status::Manual, Status::Reviewed, false, true ),
		);
	}
}
