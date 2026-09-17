<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\UrlConverter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PolyglotAI\Routing\UrlConverter
 * @covers \PolyglotAI\Languages\LanguageRegistry
 * @covers \PolyglotAI\Languages\Language
 */
final class UrlConverterTest extends TestCase {

	private LanguageRegistry $registry;

	protected function setUp(): void {
		$this->registry = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array(
				new Language( 'en_US', 'en', 'English' ),
				new Language( 'ca', 'ca', 'Català' ),
				new Language( 'pt_BR', 'pt-br', 'Português do Brasil' ),
				new Language( 'de_DE', 'de', 'Deutsch', '', false, false ),
				new Language( 'fr_FR', 'fr', 'Français', '', false, true, false ),
			)
		);
	}

	private function converter( bool $prefix_default = false, string $base = '/' ): UrlConverter {
		return new UrlConverter( $this->registry, $base, $prefix_default );
	}

	/**
	 * @param string $path     Ruta.
	 * @param string $expected Locale esperado.
	 *
	 * @dataProvider proveedor_de_deteccion
	 */
	public function test_detecta_el_idioma_de_la_ruta( string $path, string $expected ): void {
		$this->assertSame( $expected, $this->converter()->detect( $path )->locale );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function proveedor_de_deteccion(): array {
		return array(
			'raiz'                    => array( '/', 'es_ES' ),
			'sin prefijo'             => array( '/contacto/', 'es_ES' ),
			'ingles'                  => array( '/en/contact-us/', 'en_US' ),
			'ingles raiz'             => array( '/en/', 'en_US' ),
			'ingles sin barra final'  => array( '/en', 'en_US' ),
			'variante regional'       => array( '/pt-br/loja/', 'pt_BR' ),
			'con cadena de consulta'  => array( '/en?s=hola', 'en_US' ),
			'idioma desactivado'      => array( '/de/kontakt/', 'es_ES' ),
			'segmento desconocido'    => array( '/tienda/producto/', 'es_ES' ),
			'colision con una pagina' => array( '/entrada-de-blog/', 'es_ES' ),
		);
	}

	public function test_el_idioma_por_defecto_sin_prefijo_no_reclama_su_slug(): void {
		// Con prefix_default desactivado, /es/ no es la URL canónica del idioma
		// por defecto aunque «es» sea un slug conocido.
		$this->assertSame( 'es_ES', $this->converter()->detect( '/es/contacto/' )->locale );
		$this->assertSame( 'es_ES', $this->converter( true )->detect( '/es/contacto/' )->locale );
	}

	/**
	 * @param string $path     Ruta de entrada.
	 * @param string $expected Ruta sin idioma.
	 *
	 * @dataProvider proveedor_de_limpieza
	 */
	public function test_quita_el_segmento_de_idioma( string $path, string $expected ): void {
		$this->assertSame( $expected, $this->converter()->strip( $path ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function proveedor_de_limpieza(): array {
		return array(
			'con idioma'        => array( '/en/contact-us/', '/contact-us/' ),
			'solo el idioma'    => array( '/en/', '/' ),
			'sin barra final'   => array( '/en', '/' ),
			'variante regional' => array( '/pt-br/loja/', '/loja/' ),
			'sin idioma'        => array( '/contacto/', '/contacto/' ),
			'raiz'              => array( '/', '/' ),
		);
	}

	/**
	 * @param string $path     Ruta de entrada.
	 * @param string $slug     Slug de destino.
	 * @param string $expected Ruta esperada.
	 *
	 * @dataProvider proveedor_de_conversion
	 */
	public function test_convierte_entre_idiomas( string $path, string $slug, string $expected ): void {
		$language = $this->registry->by_slug( $slug );

		$this->assertNotNull( $language );
		$this->assertSame( $expected, $this->converter()->convert( $path, $language ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:string}>
	 */
	public static function proveedor_de_conversion(): array {
		return array(
			'de defecto a ingles' => array( '/contacto/', 'en', '/en/contacto/' ),
			'de ingles a defecto' => array( '/en/contacto/', 'es', '/contacto/' ),
			'entre dos idiomas'   => array( '/en/contacto/', 'ca', '/ca/contacto/' ),
			'raiz a ingles'       => array( '/', 'en', '/en/' ),
			'raiz a defecto'      => array( '/en/', 'es', '/' ),
			'variante regional'   => array( '/contacto/', 'pt-br', '/pt-br/contacto/' ),
		);
	}

	public function test_convierte_con_el_idioma_por_defecto_prefijado(): void {
		$converter = $this->converter( true );
		$defecto   = $this->registry->default_language();

		$this->assertSame( '/es/contacto/', $converter->convert( '/contacto/', $defecto ) );
		$this->assertSame( '/es/', $converter->convert( '/', $defecto ) );
	}

	public function test_funciona_en_una_instalacion_en_subdirectorio(): void {
		$converter = $this->converter( false, '/blog/' );
		$ingles    = $this->registry->by_slug( 'en' );

		$this->assertNotNull( $ingles );
		$this->assertSame( 'en_US', $converter->detect( '/blog/en/post/' )->locale );
		$this->assertSame( '/blog/post/', $converter->strip( '/blog/en/post/' ) );
		$this->assertSame( '/blog/en/post/', $converter->convert( '/blog/post/', $ingles ) );
	}

	public function test_convertir_es_idempotente(): void {
		$ingles = $this->registry->by_slug( 'en' );

		$this->assertNotNull( $ingles );

		$una = $this->converter()->convert( '/contacto/', $ingles );
		$dos = $this->converter()->convert( $una, $ingles );

		$this->assertSame( $una, $dos );
	}

	public function test_los_idiomas_en_preparacion_no_son_visibles_para_visitantes(): void {
		$visibles = array_map( static fn( Language $l ): string => $l->slug, $this->registry->visible() );

		$this->assertContains( 'en', $visibles );
		$this->assertNotContains( 'fr', $visibles, 'El idioma en preparación no debe verse.' );
		$this->assertNotContains( 'de', $visibles, 'El idioma desactivado no debe verse.' );

		$para_traductores = array_map( static fn( Language $l ): string => $l->slug, $this->registry->visible( true ) );

		$this->assertContains( 'fr', $para_traductores );
		$this->assertNotContains( 'de', $para_traductores );
	}

	public function test_el_codigo_y_el_atributo_lang_separan_la_variante_regional(): void {
		$idioma = new Language( 'pt_BR', 'pt-br', 'Português do Brasil' );

		$this->assertSame( 'pt', $idioma->code() );
		$this->assertSame( 'pt-BR', $idioma->html_lang() );
	}
}
