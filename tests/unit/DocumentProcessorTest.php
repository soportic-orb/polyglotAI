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
use PolyglotAI\Html\SafetyCheck;
use PolyglotAI\Html\Splicer;
use PolyglotAI\Html\TagScanner;
use PolyglotAI\Translation\StringType;
use PHPUnit\Framework\TestCase;

/**
 * Prueba de ida y vuelta sobre el documento completo.
 *
 * Es la prueba que de verdad protege el requisito de ADR-01: traducir TODAS las
 * cadenas de una página real y comprobar que lo que no es una cadena sale
 * idéntico byte a byte.
 *
 * @covers \PolyglotAI\Html\DocumentProcessor
 */
final class DocumentProcessorTest extends TestCase {

	private DocumentProcessor $processor;

	protected function setUp(): void {
		$this->processor = new DocumentProcessor(
			new HtmlApiDriver( new TagScanner(), new ExclusionRules() ),
			new Splicer(),
			new Escaper(),
			new SafetyCheck()
		);
	}

	/**
	 * Traducción de mentira: envuelve cada cadena en marcas reconocibles.
	 *
	 * @return callable(ExtractedString): ?string
	 */
	private function traductor(): callable {
		return static function ( ExtractedString $unit ): ?string {
			// Las imágenes no pasan por el motor: su valor es una URL, no texto.
			// Se sustituyen a mano desde la mediateca.
			if ( StringType::Image === $unit->type ) {
				return null;
			}

			return '«' . $unit->value . '»';
		};
	}

	public function test_traduce_una_pagina_completa_sin_tocar_lo_que_no_es_texto(): void {
		$html   = (string) file_get_contents( PGAI_TESTS_DIR . '/fixtures/pagina.html' );
		$result = $this->processor->translate( $html, $this->traductor() );

		$this->assertNotSame( $html, $result, 'La página no se ha traducido.' );

		// El doctype, los scripts y los estilos salen intactos.
		$this->assertStringStartsWith( '<!DOCTYPE html>', $result );
		$this->assertStringContainsString( 'var config = {"moneda":"EUR","etiqueta":"<b>Oferta</b>","comparar":function(a,b){return a<b;}};', $result );
		$this->assertStringContainsString( '.cabecera::after { content: "—"; }', $result );
		$this->assertStringContainsString( '"description":"No traducir < esto > nunca"', $result );
		$this->assertStringContainsString( '<script src="/wp-includes/js/app.js"></script>', $result );

		// Las URLs y los atributos no traducibles no se tocan.
		$this->assertStringContainsString( 'href="/wp-content/themes/x/style.css"', $result );
		$this->assertStringContainsString( 'action="/carrito/" method="post"', $result );

		// El src de una imagen SÍ es sustituible, pero solo a mano: el motor no
		// lo ve, así que aquí sale intacto.
		$this->assertStringContainsString( 'src="/logo.png"', $result );

		// El contenido excluido sigue igual.
		$this->assertStringContainsString( '<p class="notranslate">ACME Corporation&reg;</p>', $result );
		$this->assertStringContainsString( '<p>Do not translate this legal boilerplate.</p>', $result );
		$this->assertStringContainsString( '<code>echo "hola mundo";</code>', $result );
		$this->assertStringContainsString( '<p>Texto de plantilla</p>', $result );
	}

	public function test_traduce_texto_atributos_metas_y_titulo(): void {
		$html   = (string) file_get_contents( PGAI_TESTS_DIR . '/fixtures/pagina.html' );
		$result = $this->processor->translate( $html, $this->traductor() );

		$this->assertStringContainsString( '<title>«Tienda de ejemplo — Inicio»</title>', $result );
		$this->assertStringContainsString( 'name="description" content="«Una tienda con productos de prueba y acentos: ñ, é, ü.»"', $result );
		$this->assertStringContainsString( 'alt="«Logotipo de la tienda»"', $result );
		$this->assertStringContainsString( 'placeholder="«Nota para el vendedor»"', $result );
		$this->assertStringContainsString( 'value="«Añadir al carrito»"', $result );
		$this->assertStringContainsString( '<h2>«Camiseta de algodón»</h2>', $result );
	}

	public function test_el_utf8_se_conserva_sin_convertir_a_entidades(): void {
		$html   = '<p>Cafè, ñandú, «cometes», 😀</p>';
		$result = $this->processor->translate( $html, static fn( ExtractedString $u ): string => strtoupper( $u->value ) );

		$this->assertStringNotContainsString( '&#', $result );
		$this->assertStringContainsString( '😀', $result );
	}

	public function test_reescapa_las_entidades_al_escribir_de_vuelta(): void {
		// La HTML API entrega el texto decodificado; al escribirlo hay que
		// volver a codificar & y < o el documento deja de ser válido.
		$result = $this->processor->translate(
			'<p>Tom &amp; Jerry</p>',
			static fn( ExtractedString $u ): string => $u->value . ' & <co>'
		);

		$this->assertSame( '<p>Tom &amp; Jerry &amp; &lt;co></p>', $result );
	}

	public function test_escapa_las_comillas_en_los_atributos(): void {
		$result = $this->processor->translate(
			'<img alt=sencillo src="a.png">',
			static fn( ExtractedString $u ): ?string => StringType::Attribute === $u->type
				? 'diu "hola" & adéu'
				: null
		);

		$this->assertSame( '<img alt="diu &quot;hola&quot; &amp; adéu" src="a.png">', $result );
	}

	public function test_devuelve_el_original_si_el_traductor_no_devuelve_nada(): void {
		$html = (string) file_get_contents( PGAI_TESTS_DIR . '/fixtures/pagina.html' );

		$this->assertSame( $html, $this->processor->translate( $html, static fn(): ?string => null ) );
	}

	public function test_devuelve_el_original_si_el_traductor_lanza_una_excepcion(): void {
		$html = '<p>Hola</p>';

		$this->assertSame(
			$html,
			$this->processor->translate( $html, static fn(): string => throw new \RuntimeException( 'motor caído' ) )
		);
	}

	public function test_neutraliza_el_marcado_inyectado_en_una_cadena_de_texto(): void {
		// En una unidad de texto el marcado se escapa, que es la defensa
		// correcta: la página sigue traducida y el script nunca se ejecuta.
		$result = $this->processor->translate(
			'<p>Hola</p>',
			static fn( ExtractedString $u ): string => '<script>alert(1)</script>'
		);

		$this->assertSame( '<p>&lt;script>alert(1)&lt;/script></p>', $result );
		$this->assertStringNotContainsString( '<script', $result );
	}

	public function test_descarta_la_pagina_si_un_bloque_inyecta_un_script(): void {
		// Una unidad de bloque SÍ se escribe como HTML, así que aquí la defensa
		// es la comprobación de integridad: cambia el recuento de <script> y se
		// sirve el original sin traducir.
		$html   = '<p>Hola <b>món</b></p>';
		$result = $this->processor->translate(
			$html,
			static fn( ExtractedString $u ): string => $u->value . '<script>alert(1)</script>'
		);

		$this->assertSame( $html, $result );
	}

	public function test_el_resultado_se_puede_volver_a_analizar_sin_perder_cadenas(): void {
		// Traducir dos veces no debe degradar el documento: la segunda pasada
		// tiene que encontrar exactamente las mismas unidades que la primera.
		$html   = (string) file_get_contents( PGAI_TESTS_DIR . '/fixtures/pagina.html' );
		$driver = new HtmlApiDriver( new TagScanner(), new ExclusionRules() );

		$primera = $this->processor->translate( $html, $this->traductor() );

		$this->assertCount( count( $driver->extract( $html ) ), $driver->extract( $primera ) );
	}
}
