<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PolyglotAI\Html\Splicer;
use PolyglotAI\Html\TagScanner;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\InternalUrl;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Seo\StructuredData;
use PolyglotAI\Translation\TextLookupInterface;

/**
 * @covers \PolyglotAI\Seo\StructuredData
 */
final class StructuredDataTest extends TestCase {

	private StructuredData $rewriter;
	private Language $english;

	protected function setUp(): void {
		$this->english = new Language( 'en_US', 'en', 'English' );

		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( $this->english )
		);

		$lookup = new class() implements TextLookupInterface {

			/**
			 * Diccionario de mentira, suficiente para las pruebas.
			 *
			 * @param string[] $texts    Textos.
			 * @param string   $language Locale.
			 * @return array<string, string>
			 */
			public function texts( array $texts, string $language ): array {
				$known = array(
					'Cómo hacer pan'   => 'How to make bread',
					'Una receta fácil' => 'An easy recipe',
					'Inicio'           => 'Home',
					'Recetas'          => 'Recipes',
				);

				$result = array();

				foreach ( $texts as $text ) {
					$result[ $text ] = $known[ $text ] ?? $text;
				}

				return $result;
			}
		};

		$this->rewriter = new StructuredData(
			$lookup,
			new InternalUrl( new UrlConverter( $languages, '/', false ), 'example.org' ),
			new TagScanner(),
			new Splicer()
		);
	}

	/**
	 * Envuelve un JSON en su script y lo reescribe.
	 *
	 * @param string $json JSON-LD.
	 */
	private function rewrite( string $json ): string {
		$html = '<script type="application/ld+json">' . $json . '</script>';

		return $this->rewriter->rewrite( $html, $this->english );
	}

	/**
	 * Descodifica el JSON-LD resultante.
	 *
	 * @param string $json JSON-LD de entrada.
	 * @return array<string, mixed>
	 */
	private function decode( string $json ): array {
		$html = $this->rewrite( $json );

		$start = strpos( $html, '>' );
		$end   = strripos( $html, '</script' );

		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );

		$decoded = json_decode( substr( $html, $start + 1, $end - $start - 1 ), true );

		$this->assertIsArray( $decoded );

		return $decoded;
	}

	public function test_traduce_el_titular_y_la_descripcion(): void {
		$data = $this->decode( '{"@type":"Article","headline":"Cómo hacer pan","description":"Una receta fácil"}' );

		$this->assertSame( 'How to make bread', $data['headline'] );
		$this->assertSame( 'An easy recipe', $data['description'] );
	}

	public function test_traduce_dentro_de_estructuras_anidadas(): void {
		$json = '{"@type":"BreadcrumbList","itemListElement":['
			. '{"@type":"ListItem","position":1,"name":"Inicio"},'
			. '{"@type":"ListItem","position":2,"name":"Recetas"}]}';

		$data = $this->decode( $json );

		$this->assertSame( 'Home', $data['itemListElement'][0]['name'] );
		$this->assertSame( 'Recipes', $data['itemListElement'][1]['name'] );
	}

	public function test_pone_el_prefijo_en_las_urls_internas(): void {
		$data = $this->decode( '{"@type":"WebPage","@id":"https://example.org/pan/","url":"https://example.org/pan/"}' );

		$this->assertSame( 'https://example.org/en/pan/', $data['@id'] );
		$this->assertSame( 'https://example.org/en/pan/', $data['url'] );
	}

	public function test_no_toca_las_urls_externas(): void {
		$data = $this->decode( '{"@type":"Organization","sameAs":["https://facebook.com/x"],"url":"https://otro.com/"}' );

		$this->assertSame( 'https://otro.com/', $data['url'] );
		$this->assertSame( 'https://facebook.com/x', $data['sameAs'][0] );
	}

	public function test_no_toca_lo_que_no_es_texto_para_personas(): void {
		// Fechas, códigos, precios e identificadores no se traducen.
		$json = '{"@type":"Product","sku":"ABC-123","datePublished":"2026-01-31",'
			. '"priceCurrency":"EUR","price":"19.90","@context":"https://schema.org"}';

		$data = $this->decode( $json );

		$this->assertSame( 'ABC-123', $data['sku'] );
		$this->assertSame( '2026-01-31', $data['datePublished'] );
		$this->assertSame( 'EUR', $data['priceCurrency'] );
		$this->assertSame( 'https://schema.org', $data['@context'] );
	}

	public function test_un_json_invalido_se_deja_exactamente_igual(): void {
		$html = '<script type="application/ld+json">{esto no es json}</script>';

		$this->assertSame( $html, $this->rewriter->rewrite( $html, $this->english ) );
	}

	public function test_no_toca_los_scripts_que_no_son_datos_estructurados(): void {
		$html = '<script>var headline = "Cómo hacer pan";</script>'
			. '<script type="text/javascript">var x = {"name":"Inicio"};</script>';

		$this->assertSame( $html, $this->rewriter->rewrite( $html, $this->english ) );
	}

	public function test_no_puede_colarse_un_cierre_de_script(): void {
		// Con las barras escapadas es imposible que el JSON reabra el documento.
		$html = $this->rewrite( '{"@type":"Article","headline":"Cómo hacer pan","url":"https://example.org/a/"}' );

		$this->assertSame( 1, substr_count( $html, '</script>' ) );
		$this->assertStringContainsString( '\\/', $html );
	}

	public function test_conserva_los_acentos_sin_escapar(): void {
		// Escapar el UTF-8 haría el bloque ilegible y engordaría la página.
		$html = $this->rewrite( '{"@type":"Article","headline":"Sin traducción","description":"Ñandú"}' );

		$this->assertStringContainsString( 'Ñandú', $html );
		$this->assertStringNotContainsString( '\\u00d1', $html );
	}

	public function test_deja_el_resto_del_documento_intacto(): void {
		$html = "<!DOCTYPE html>\n<html><head>"
			. '<script type="application/ld+json">{"@type":"Article","headline":"Cómo hacer pan"}</script>'
			. '</head><body><p>Hola</p></body></html>';

		$result = $this->rewriter->rewrite( $html, $this->english );

		$this->assertStringStartsWith( "<!DOCTYPE html>\n<html><head>", $result );
		$this->assertStringEndsWith( '</head><body><p>Hola</p></body></html>', $result );
		$this->assertStringContainsString( 'How to make bread', $result );
	}

	public function test_varios_bloques_en_la_misma_pagina(): void {
		$html = '<script type="application/ld+json">{"@type":"Article","headline":"Cómo hacer pan"}</script>'
			. '<script type="application/ld+json">{"@type":"WebSite","name":"Recetas"}</script>';

		$result = $this->rewriter->rewrite( $html, $this->english );

		$this->assertStringContainsString( 'How to make bread', $result );
		$this->assertStringContainsString( 'Recipes', $result );
	}
}
