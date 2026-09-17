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
use PolyglotAI\Seo\HeadUrls;

/**
 * @covers \PolyglotAI\Seo\HeadUrls
 */
final class HeadUrlsTest extends TestCase {

	private HeadUrls $rewriter;
	private Language $english;

	protected function setUp(): void {
		$this->english = new Language( 'en_US', 'en', 'English' );

		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( $this->english, new Language( 'ca', 'ca', 'Català' ) )
		);

		$this->rewriter = new HeadUrls(
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

	public function test_pone_el_prefijo_en_el_canonico(): void {
		$this->assertSame(
			'<link rel="canonical" href="https://example.org/en/contact-us/" />',
			$this->rewrite( '<link rel="canonical" href="https://example.org/contact-us/" />' )
		);
	}

	public function test_pone_el_prefijo_en_og_url(): void {
		$this->assertSame(
			'<meta property="og:url" content="https://example.org/en/contact-us/" />',
			$this->rewrite( '<meta property="og:url" content="https://example.org/contact-us/" />' )
		);
	}

	public function test_pone_el_prefijo_en_la_paginacion(): void {
		$html = '<link rel="prev" href="/blog/page/2/" /><link rel="next" href="/blog/page/4/" />';

		$this->assertSame(
			'<link rel="prev" href="/en/blog/page/2/" /><link rel="next" href="/en/blog/page/4/" />',
			$this->rewrite( $html )
		);
	}

	public function test_no_toca_los_hreflang(): void {
		// Son nuestros y apuntan a otros idiomas a propósito: convertirlos al
		// idioma en curso sería romper justo lo que se acaba de emitir bien.
		$html = '<link rel="alternate" hreflang="ca" href="https://example.org/ca/contacte/" />'
			. '<link rel="alternate" hreflang="x-default" href="https://example.org/contacto/" />';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_toca_el_feed(): void {
		$html = '<link rel="alternate" type="application/rss+xml" href="https://example.org/feed/" />';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_toca_la_hoja_de_estilos(): void {
		$html = '<link rel="stylesheet" href="https://example.org/wp-content/themes/x/style.css" />';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_toca_la_imagen_de_open_graph(): void {
		// og:image es un archivo, no una página: no tiene versión por idioma.
		$html = '<meta property="og:image" content="https://example.org/wp-content/uploads/foto.jpg" />';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_toca_la_descripcion(): void {
		$html = '<meta name="description" content="Una descripción cualquiera" />';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_toca_un_canonico_externo(): void {
		$html = '<link rel="canonical" href="https://otrositio.com/contacto/" />';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_no_duplica_un_prefijo_que_ya_esta(): void {
		$html = '<link rel="canonical" href="https://example.org/en/contact-us/" />';

		$this->assertSame( $html, $this->rewrite( $html ) );
	}

	public function test_deja_el_resto_del_documento_intacto(): void {
		$html = "<!DOCTYPE html>\n<html><head>"
			. '<title>Contact — Ñ</title>'
			. '<link rel="canonical" href="/contact-us/" />'
			. '<script type="application/ld+json">{"url":"/contact-us/"}</script>'
			. '</head><body><p>Hola</p></body></html>';

		$this->assertSame(
			str_replace( '<link rel="canonical" href="/contact-us/" />', '<link rel="canonical" href="/en/contact-us/" />', $html ),
			$this->rewrite( $html )
		);
	}
}
