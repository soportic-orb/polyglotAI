<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SlugRecord;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Detection\BotDetector;
use PolyglotAI\Detection\BrowserLanguage;
use PolyglotAI\Detection\VisitorRedirect;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Support\Options;
use PolyglotAI\Translation\StatusPrecedence;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Detection\VisitorRedirect
 */
final class VisitorRedirectTest extends WP_UnitTestCase {

	private LanguageRegistry $languages;
	private RequestContext $request;
	private UrlConverter $converter;
	private SlugRepository $slugs;
	private Options $options;

	/**
	 * Idiomas, ajustes limpios y una petición de navegador normal.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$table}`" );

		delete_option( Options::MAIN );

		$this->languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English' ) )
		);

		$this->converter = new UrlConverter( $this->languages, '/', false );
		$this->request   = new RequestContext( $this->languages, $this->converter );
		$this->slugs     = new SlugRepository( new StatusPrecedence() );
		$this->options   = new Options();

		$this->set_permalink_structure( '/%postname%/' );

		unset( $_COOKIE[ VisitorRedirect::COOKIE ] );

		$_SERVER['REQUEST_METHOD']       = 'GET';
		$_SERVER['QUERY_STRING']         = '';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
		$_SERVER['HTTP_USER_AGENT']      = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36';

		$this->options->update( array( 'detect_visitor_language' => true ) );
	}

	/**
	 * Deja el entorno como estaba.
	 */
	public function tear_down(): void {
		unset(
			$_COOKIE[ VisitorRedirect::COOKIE ],
			$_SERVER['HTTP_ACCEPT_LANGUAGE'],
			$_SERVER['HTTP_USER_AGENT']
		);

		parent::tear_down();
	}

	private function redirect(): VisitorRedirect {
		return new VisitorRedirect(
			$this->options,
			$this->request,
			$this->converter,
			new SlugResolver( $this->slugs ),
			new BrowserLanguage( $this->languages ),
			new BotDetector()
		);
	}

	/**
	 * Sitúa la petición en una página existente.
	 *
	 * @param string $path Ruta con prefijo.
	 * @param string $slug Slug del idioma.
	 */
	private function visit( string $path, string $slug ): void {
		$language = $this->languages->by_slug( $slug );

		$this->assertNotNull( $language );

		$this->request->set_path( $path );
		$this->request->force( $language );

		$this->go_to( $this->converter->strip( $path ) );
	}

	/**
	 * Crea la página que se va a visitar.
	 */
	private function page(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);
	}

	public function test_lleva_al_visitante_a_su_idioma(): void {
		$this->page();
		$this->visit( '/contacto/', 'es' );

		$target = $this->redirect()->target();

		$this->assertNotNull( $target );
		$this->assertSame( 'en_US', $target->locale );
		$this->assertSame( home_url( '/en/contacto/' ), $this->redirect()->url_for( $target ) );
	}

	public function test_la_redireccion_usa_el_slug_traducido(): void {
		$page_id = $this->page();

		$this->slugs->save( new SlugRecord( 'post', 'page', $page_id, 'en_US', 'contacto', 'contact-us' ) );
		$this->visit( '/contacto/', 'es' );

		$target = $this->redirect()->target();

		$this->assertNotNull( $target );
		$this->assertSame( home_url( '/en/contact-us/' ), $this->redirect()->url_for( $target ) );
	}

	public function test_viene_desactivada(): void {
		delete_option( Options::MAIN );

		$this->page();
		$this->visit( '/contacto/', 'es' );

		$this->assertNull(
			( new VisitorRedirect(
				new Options(),
				$this->request,
				$this->converter,
				new SlugResolver( $this->slugs ),
				new BrowserLanguage( $this->languages ),
				new BotDetector()
			) )->target()
		);
	}

	public function test_no_redirige_a_quien_ya_ha_elegido(): void {
		$this->page();
		$this->visit( '/contacto/', 'es' );

		$_COOKIE[ VisitorRedirect::COOKIE ] = 'es';

		$this->assertNull( $this->redirect()->target(), 'Manda su elección, no su navegador.' );
	}

	public function test_no_redirige_si_ya_ha_pedido_un_idioma(): void {
		$this->page();
		$this->visit( '/en/contacto/', 'en' );

		$this->assertNull( $this->redirect()->target() );
	}

	public function test_no_redirige_a_un_robot(): void {
		$this->page();
		$this->visit( '/contacto/', 'es' );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

		// Redirigir a un rastreador solo consigue que indexe la versión
		// equivocada de la portada.
		$this->assertNull( $this->redirect()->target() );
	}

	public function test_no_redirige_un_envio_de_formulario(): void {
		$this->page();
		$this->visit( '/contacto/', 'es' );

		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->assertNull( $this->redirect()->target() );

		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function test_no_redirige_un_404(): void {
		$this->visit( '/no-existe/', 'es' );

		$this->assertTrue( is_404() );
		$this->assertNull( $this->redirect()->target() );
	}

	public function test_no_redirige_si_el_navegador_ya_quiere_el_idioma_del_sitio(): void {
		$this->page();
		$this->visit( '/contacto/', 'es' );

		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'es-ES,es;q=0.9';

		$this->assertNull( $this->redirect()->target() );
	}

	public function test_sin_cabecera_no_se_redirige(): void {
		$this->page();
		$this->visit( '/contacto/', 'es' );

		unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );

		$this->assertNull( $this->redirect()->target() );
	}

	public function test_un_filtro_puede_cancelar_la_redireccion(): void {
		$this->page();
		$this->visit( '/contacto/', 'es' );

		add_filter( 'pgai_detected_language', '__return_null' );

		$this->assertNull( $this->redirect()->target() );

		remove_filter( 'pgai_detected_language', '__return_null' );
	}

	public function test_la_redireccion_conserva_la_cadena_de_consulta(): void {
		$this->page();
		$this->visit( '/contacto/', 'es' );

		$_SERVER['QUERY_STRING'] = 'utm_source=boletin';

		$target = $this->redirect()->target();

		$this->assertNotNull( $target );
		$this->assertSame(
			home_url( '/en/contacto/?utm_source=boletin' ),
			$this->redirect()->url_for( $target )
		);

		$_SERVER['QUERY_STRING'] = '';
	}
}
