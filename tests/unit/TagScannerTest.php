<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests;

use PolyglotAI\Html\TagScanner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PolyglotAI\Html\TagScanner
 */
final class TagScannerTest extends TestCase {

	private TagScanner $scanner;

	protected function setUp(): void {
		$this->scanner = new TagScanner();
	}

	/**
	 * @param string $raw      Etiqueta en crudo.
	 * @param string $name     Atributo buscado.
	 * @param string $expected Fragmento que debe cubrir el intervalo.
	 *
	 * @dataProvider proveedor_de_atributos
	 */
	public function test_localiza_el_valor_de_un_atributo( string $raw, string $name, string $expected ): void {
		$spans = $this->scanner->attribute_spans( $raw );

		$this->assertArrayHasKey( $name, $spans );
		$this->assertSame( $expected, substr( $raw, $spans[ $name ][0], $spans[ $name ][1] ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:string}>
	 */
	public static function proveedor_de_atributos(): array {
		return array(
			'comillas dobles'      => array( '<img alt="Un gat" src="a.png">', 'alt', '"Un gat"' ),
			'comillas simples'     => array( "<img alt='Un gat'>", 'alt', "'Un gat'" ),
			'sin comillas'         => array( '<img alt=gat>', 'alt', 'gat' ),
			'mayusculas'           => array( '<IMG ALT="Un gat">', 'alt', '"Un gat"' ),
			'espacios alrededor'   => array( '<img  alt = "Un gat" >', 'alt', '"Un gat"' ),
			'tras booleano'        => array( '<input disabled placeholder="Nom">', 'placeholder', '"Nom"' ),
			'con entidades'        => array( '<img alt="Tom &amp; Jerry">', 'alt', '"Tom &amp; Jerry"' ),
			'comillas en el valor' => array( '<img alt="Diu \'hola\'">', 'alt', '"Diu \'hola\'"' ),
			'autocerrada'          => array( '<img alt="Un gat"/>', 'alt', '"Un gat"' ),
			'con salto de linea'   => array( "<img\n  alt=\"Un gat\"\n>", 'alt', '"Un gat"' ),
		);
	}

	public function test_el_primer_atributo_duplicado_gana(): void {
		$raw   = '<img alt="primero" alt="segundo">';
		$spans = $this->scanner->attribute_spans( $raw );

		$this->assertSame( '"primero"', substr( $raw, $spans['alt'][0], $spans['alt'][1] ) );
	}

	public function test_ignora_los_atributos_booleanos(): void {
		$spans = $this->scanner->attribute_spans( '<input disabled required>' );

		$this->assertSame( array(), $spans );
	}

	public function test_localiza_el_contenido_de_un_elemento_rcdata(): void {
		$raw   = '<title>Hola &amp; adéu</title>';
		$inner = $this->scanner->inner_span( $raw, 'TITLE' );

		$this->assertNotNull( $inner );
		$this->assertSame( 'Hola &amp; adéu', substr( $raw, $inner[0], $inner[1] ) );
	}

	public function test_localiza_el_contenido_de_un_rcdata_con_atributos(): void {
		$raw   = '<textarea rows="2" placeholder="a>b">Hola</textarea>';
		$inner = $this->scanner->inner_span( $raw, 'TEXTAREA' );

		$this->assertNotNull( $inner );
		$this->assertSame( 'Hola', substr( $raw, $inner[0], $inner[1] ) );
	}

	public function test_devuelve_null_si_el_rcdata_no_esta_cerrado(): void {
		$this->assertNull( $this->scanner->inner_span( '<title>Sin cierre', 'TITLE' ) );
	}

	public function test_no_entra_en_bucle_con_marcado_malformado(): void {
		$this->assertSame( array(), $this->scanner->attribute_spans( '<img ="roto" <<>' ) );
	}
}
