<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests;

use PolyglotAI\Translation\StringType;
use PolyglotAI\Translation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PolyglotAI\Translation\Validator
 */
final class ValidatorTest extends TestCase {

	private Validator $validator;

	protected function setUp(): void {
		$this->validator = new Validator();
	}

	/**
	 * @param string     $original    Original.
	 * @param string     $translation Traducción.
	 * @param StringType $type        Tipo.
	 *
	 * @dataProvider proveedor_de_traducciones_validas
	 */
	public function test_acepta_traducciones_correctas( string $original, string $translation, StringType $type ): void {
		$result = $this->validator->validate( $original, $translation, $type );

		$this->assertTrue( $result->is_valid, 'Rechazada por: ' . $result->summary() );
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:StringType}>
	 */
	public static function proveedor_de_traducciones_validas(): array {
		return array(
			'texto simple'            => array( 'Hola', 'Hello', StringType::Text ),
			'etiquetas conservadas'   => array( 'Hola <b>món</b>', 'Hello <b>world</b>', StringType::Block ),
			'placeholders reordenados' => array( '%1$s de %2$s', '%2$s of %1$s', StringType::Text ),
			'misma url'               => array( 'Ver <a href="/x">aquí</a>', 'See <a href="/x">here</a>', StringType::Block ),
			'alt traducido en bloque' => array( 'Un <img alt="gat" src="a.png"> aquí', 'A <img alt="cat" src="a.png"> here', StringType::Block ),
			'shortcode intacto'       => array( 'Mira [precio id="3"] esto', 'Look at [precio id="3"] this', StringType::Text ),
			'email intacto'           => array( 'Escribe a info@x.com', 'Write to info@x.com', StringType::Text ),
			'sin cambios'             => array( 'ACME', 'ACME', StringType::Text ),
		);
	}

	/**
	 * @param string     $original    Original.
	 * @param string     $translation Traducción.
	 * @param StringType $type        Tipo.
	 * @param string     $problem     Problema esperado.
	 *
	 * @dataProvider proveedor_de_traducciones_invalidas
	 */
	public function test_rechaza_traducciones_que_rompen_la_estructura( string $original, string $translation, StringType $type, string $problem ): void {
		$result = $this->validator->validate( $original, $translation, $type );

		$this->assertFalse( $result->is_valid );
		$this->assertContains( $problem, $result->problems );
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:StringType, 3:string}>
	 */
	public static function proveedor_de_traducciones_invalidas(): array {
		return array(
			'etiqueta perdida'      => array( 'Hola <b>món</b>', 'Hello world', StringType::Block, 'tag_count_mismatch' ),
			'etiqueta cambiada'     => array( 'Hola <b>món</b>', 'Hello <i>world</i>', StringType::Block, 'tag_sequence_mismatch' ),
			'href modificado'       => array( '<a href="/x">aquí</a>', '<a href="/y">here</a>', StringType::Block, 'attribute_value_mismatch' ),
			'atributo inventado'    => array( '<a href="/x">aquí</a>', '<a href="/x" target="_blank">here</a>', StringType::Block, 'attribute_set_mismatch' ),
			'placeholder perdido'   => array( 'Hola %s', 'Hello', StringType::Text, 'placeholders_mismatch' ),
			'placeholder inventado' => array( 'Hola', 'Hello %s', StringType::Text, 'placeholders_mismatch' ),
			'url cambiada'          => array( 'Ver https://a.com', 'See https://b.com', StringType::Text, 'urls_mismatch' ),
			'email cambiado'        => array( 'Escribe a info@x.com', 'Write to hello@x.com', StringType::Text, 'emails_mismatch' ),
			'shortcode traducido'   => array( 'Mira [precio]', 'Look at [price]', StringType::Text, 'shortcodes_mismatch' ),
			'marcado inyectado'     => array( 'Hola', 'Hello <b>there</b>', StringType::Text, 'unexpected_markup' ),
			'traduccion vacia'      => array( 'Hola', '   ', StringType::Text, 'empty_translation' ),
		);
	}

	public function test_permite_traducir_el_alt_pero_no_el_src(): void {
		$valido = $this->validator->validate(
			'<img alt="gat" src="a.png" class="foto">',
			'<img alt="cat" src="a.png" class="foto">',
			StringType::Block
		);

		$invalido = $this->validator->validate(
			'<img alt="gat" src="a.png" class="foto">',
			'<img alt="cat" src="b.png" class="foto">',
			StringType::Block
		);

		$this->assertTrue( $valido->is_valid );
		$this->assertFalse( $invalido->is_valid );
	}

	public function test_una_traduccion_vacia_de_un_original_vacio_es_valida(): void {
		$this->assertTrue( $this->validator->validate( '', '', StringType::Text )->is_valid );
	}
}
