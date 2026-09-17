<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests;

use PolyglotAI\Editor\MarkerDecorator;
use PolyglotAI\Html\ExtractedString;
use PolyglotAI\Html\RawHtml;
use PolyglotAI\Translation\Hasher;
use PolyglotAI\Translation\Normalizer;
use PolyglotAI\Translation\StringType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PolyglotAI\Editor\MarkerDecorator
 */
final class MarkerDecoratorTest extends TestCase {

	private MarkerDecorator $decorator;
	private Hasher $hasher;

	protected function setUp(): void {
		$this->hasher    = new Hasher( new Normalizer() );
		$this->decorator = new MarkerDecorator( $this->hasher );
	}

	/**
	 * Crea una unidad extraída.
	 *
	 * @param string     $value Valor.
	 * @param StringType $type  Tipo.
	 */
	private function unit( string $value, StringType $type = StringType::Text ): ExtractedString {
		return new ExtractedString( $type, $value, 0, strlen( $value ) );
	}

	public function test_envuelve_una_cadena_de_texto_con_su_hash(): void {
		$unit   = $this->unit( 'Hola món' );
		$result = $this->decorator->decorate( $unit, 'Hello world', 'automatic' );

		$this->assertInstanceOf( RawHtml::class, $result );
		$this->assertStringContainsString( 'data-pgai-hash="' . $this->hasher->hash( 'Hola món', StringType::Text ) . '"', $result->html );
		$this->assertStringContainsString( 'data-pgai-type="text"', $result->html );
		$this->assertStringContainsString( 'data-pgai-status="automatic"', $result->html );
		$this->assertStringContainsString( '>Hello world<', $result->html );
	}

	public function test_escapa_el_texto_pero_no_el_bloque(): void {
		$texto  = $this->decorator->decorate( $this->unit( 'A & B' ), 'C & <D>' );
		$bloque = $this->decorator->decorate(
			$this->unit( 'Hola <b>món</b>', StringType::Block ),
			'Hello <b>world</b>',
			'manual'
		);

		$this->assertInstanceOf( RawHtml::class, $texto );
		$this->assertInstanceOf( RawHtml::class, $bloque );

		// Una cadena de texto no puede aportar marcado. Se escapan & y <, que
		// son los que cambiarían la estructura; un > suelto en texto es HTML
		// válido y escaparlo solo ensuciaría la salida.
		$this->assertStringContainsString( 'C &amp; &lt;D>', $texto->html );
		$this->assertStringNotContainsString( '<D>', $texto->html );

		// Un bloque SÍ es HTML y se conserva.
		$this->assertStringContainsString( 'Hello <b>world</b>', $bloque->html );
	}

	public function test_muestra_el_original_mientras_no_haya_traduccion(): void {
		$result = $this->decorator->decorate( $this->unit( 'Sin traducir' ), null );

		$this->assertInstanceOf( RawHtml::class, $result );
		$this->assertStringContainsString( '>Sin traducir<', $result->html );
		$this->assertStringContainsString( 'data-pgai-status="pending"', $result->html );
	}

	/**
	 * @param StringType $type Tipo no envolvible.
	 *
	 * @dataProvider proveedor_de_tipos_no_envolvibles
	 */
	public function test_no_envuelve_lo_que_no_tiene_donde_colgar_la_marca( StringType $type ): void {
		// Un atributo o una meta no tienen un lugar en la página donde poner el
		// marcador: se traducen igual, pero se editan desde la lista lateral.
		$result = $this->decorator->decorate( $this->unit( 'Un gato', $type ), 'A cat' );

		$this->assertSame( 'A cat', $result );
	}

	/**
	 * @return array<string, array{0:StringType}>
	 */
	public static function proveedor_de_tipos_no_envolvibles(): array {
		return array(
			'atributo' => array( StringType::Attribute ),
			'meta'     => array( StringType::Meta ),
			'titulo'   => array( StringType::Rcdata ),
		);
	}

	public function test_recoge_todas_las_cadenas_incluidas_las_no_envueltas(): void {
		$this->decorator->decorate( $this->unit( 'Texto' ), 'Text', 'automatic' );
		$this->decorator->decorate( $this->unit( 'Un gato', StringType::Attribute ), 'A cat', 'manual' );
		$this->decorator->decorate( $this->unit( 'Sin traducir' ), null );

		$collected = $this->decorator->collected();

		$this->assertCount( 3, $collected );
		$this->assertSame(
			array( 'Texto', 'Un gato', 'Sin traducir' ),
			array_column( $collected, 'original' )
		);
		$this->assertSame(
			array( 'automatic', 'manual', 'pending' ),
			array_column( $collected, 'status' )
		);
	}

	public function test_una_cadena_repetida_se_recoge_una_sola_vez(): void {
		$this->decorator->decorate( $this->unit( 'Inicio' ), 'Home' );
		$this->decorator->decorate( $this->unit( 'Inicio' ), 'Home' );

		$this->assertCount( 1, $this->decorator->collected() );
	}

	public function test_el_marcado_no_se_puede_inyectar_por_el_hash(): void {
		// El hash es siempre hexadecimal, pero el atributo se escapa igualmente:
		// el marcado del editor no puede ser una vía de entrada.
		$result = $this->decorator->decorate( $this->unit( '"><script>alert(1)</script>' ), null );

		$this->assertInstanceOf( RawHtml::class, $result );
		$this->assertStringNotContainsString( '<script>', $result->html );
	}
}
