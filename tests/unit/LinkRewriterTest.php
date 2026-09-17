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
use PolyglotAI\Routing\LinkRewriter;
use PolyglotAI\Routing\UrlConverter;

/**
 * La reescritura de enlaces toca todos los enlaces de todas las páginas del
 * sitio, así que conviene saber exactamente qué toca y qué no.
 *
 * @covers \PolyglotAI\Routing\LinkRewriter
 * @covers \PolyglotAI\Routing\InternalUrl
 */
final class LinkRewriterTest extends TestCase {

	private LinkRewriter $rewriter;
	private Language $english;

	protected function setUp(): void {
		$this->english = new Language( 'en_US', 'en', 'English' );

		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( $this->english, new Language( 'ca', 'ca', 'Català' ) )
		);

		$this->rewriter = new LinkRewriter(
			new InternalUrl( new UrlConverter( $languages, '/', false ), 'example.org' ),
			new TagScanner(),
			new Splicer()
		);
	}

	/**
	 * Reescribe un fragmento al inglés.
	 *
	 * @param string $html Fragmento.
	 */
	private function rewrite( string $html ): string {
		return $this->rewriter->rewrite( $html, $this->english );
	}

	public function test_pone_el_prefijo_en_un_enlace_relativo(): void {
		$this->assertSame(
			'<a href="/en/contacto/">Ir</a>',
			$this->rewrite( '<a href="/contacto/">Ir</a>' )
		);
	}

	public function test_pone_el_prefijo_en_un_enlace_absoluto_del_sitio(): void {
		$this->assertSame(
			'<a href="https://example.org/en/contacto/">Ir</a>',
			$this->rewrite( '<a href="https://example.org/contacto/">Ir</a>' )
		);
	}

	public function test_no_toca_un_enlace_externo(): void {
		$html = '<a href="https://otrositio.com/contacto/">Ir</a>';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_toca_correos_ni_telefonos(): void {
		$html = '<a href="mailto:hola@example.org">Correo</a><a href="tel:+34600000000">Tel</a>';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_toca_un_ancla(): void {
		$html = '<a href="#seccion">Ir</a>';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_toca_el_escritorio_ni_la_api(): void {
		$html = '<a href="/wp-admin/">Panel</a><a href="/wp-json/wp/v2/posts">API</a><a href="/wp-login.php">Entrar</a>';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_toca_los_recursos(): void {
		// src apunta a archivos, que no tienen versión por idioma.
		$html = '<img src="/wp-content/uploads/foto.jpg" alt="Foto"><script src="/app.js"></script>';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_reescribe_la_accion_de_un_formulario(): void {
		$this->assertSame(
			'<form action="/en/buscar/" method="get"></form>',
			$this->rewrite( '<form action="/buscar/" method="get"></form>' )
		);
	}

	public function test_conserva_la_consulta_y_el_fragmento(): void {
		$this->assertSame(
			'<a href="/en/tienda/?orden=precio#lista">Ir</a>',
			$this->rewrite( '<a href="/tienda/?orden=precio#lista">Ir</a>' )
		);
	}

	public function test_no_duplica_un_prefijo_que_ya_esta(): void {
		$html = '<a href="/en/contacto/">Ir</a>';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_cambia_el_prefijo_de_otro_idioma(): void {
		// Todos los enlaces internos llevan al idioma en curso.
		$this->assertSame(
			'<a href="/en/contacto/">Ir</a>',
			$this->rewrite( '<a href="/ca/contacto/">Ir</a>' )
		);
	}

	public function test_deja_en_paz_los_link_de_la_cabecera(): void {
		// De los <link> se encarga la pasada de SEO, que mira el rel: aquí no se
		// puede distinguir un canónico de un hreflang.
		$html = '<link rel="canonical" href="/contacto/"><link rel="alternate" hreflang="ca" href="/ca/contacte/">';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_toca_el_contenido_de_un_script(): void {
		$html = '<script>var url = "/contacto/";</script>';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_toca_una_url_relativa_al_documento(): void {
		// El navegador ya la resuelve dentro del idioma en curso.
		$html = '<a href="siguiente/">Ir</a>';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_deja_el_resto_del_documento_byte_a_byte(): void {
		$html = "<!DOCTYPE html>\n<html><head><title>Ñandú €</title></head><body>"
			. '<p>Texto con &amp; y &lt;</p><a href="/contacto/">Ir</a>'
			. '<style>a{content:"/contacto/"}</style></body></html>';

		$this->assertSame(
			str_replace( '<a href="/contacto/">', '<a href="/en/contacto/">', $html ),
			$this->rewrite( $html )
		);
	}
}
