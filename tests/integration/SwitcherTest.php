<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SlugRecord;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Switcher\Shortcode;
use PolyglotAI\Switcher\SwitcherRenderer;
use PolyglotAI\Translation\StatusPrecedence;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Switcher\SwitcherRenderer
 * @covers \PolyglotAI\Switcher\Shortcode
 */
final class SwitcherTest extends WP_UnitTestCase {

	private LanguageRegistry $languages;
	private RequestContext $request;
	private SlugRepository $slugs;
	private UrlConverter $converter;

	/**
	 * Tres idiomas y la tabla de slugs limpia.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$table}`" );

		$this->languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español', '🇪🇸' ),
			array(
				new Language( 'en_US', 'en', 'English', '🇬🇧' ),
				new Language( 'ca', 'ca', 'Català' ),
			)
		);

		$this->converter = new UrlConverter( $this->languages, '/', false );
		$this->request   = new RequestContext( $this->languages, $this->converter );
		$this->slugs     = new SlugRepository( new StatusPrecedence() );

		$this->set_permalink_structure( '/%postname%/' );
	}

	private function renderer(): SwitcherRenderer {
		return new SwitcherRenderer(
			$this->languages,
			$this->request,
			$this->converter,
			new SlugResolver( $this->slugs )
		);
	}

	/**
	 * Sitúa la petición.
	 *
	 * @param string $path Ruta con prefijo.
	 * @param string $slug Slug del idioma.
	 */
	private function visit( string $path, string $slug ): void {
		$language = $this->languages->by_slug( $slug );

		$this->assertNotNull( $language );

		$this->request->set_path( $path );
		$this->request->force( $language );
	}

	public function test_cada_idioma_apunta_a_su_propio_slug(): void {
		// Este es el fallo que el renderizador único existe para evitar: desde
		// catalán, el enlace al inglés no puede llevar el slug catalán.
		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'en_US', 'contacto', 'contact-us' ) );
		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'ca', 'contacto', 'contacte' ) );

		$this->visit( '/ca/contacte/', 'ca' );

		$html = $this->renderer()->render();

		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/en/contact-us/' ) ) . '"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/contacto/' ) ) . '"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/ca/contacte/' ) ) . '"', $html );
	}

	public function test_marca_el_idioma_en_curso(): void {
		$this->visit( '/en/contacto/', 'en' );

		$html = $this->renderer()->render();

		$this->assertStringContainsString( 'pgai-switcher__item--current', $html );
		$this->assertStringContainsString( 'aria-current="true"', $html );
	}

	public function test_puede_ocultar_el_idioma_en_curso(): void {
		$this->visit( '/en/contacto/', 'en' );

		$html = $this->renderer()->render( array( 'hide_current' => 'yes' ) );

		$this->assertStringNotContainsString( 'hreflang="en-US"', $html );
		$this->assertStringContainsString( 'hreflang="ca"', $html );
	}

	public function test_modos_de_presentacion(): void {
		$this->visit( '/contacto/', 'es' );

		$this->assertStringContainsString( 'English', $this->renderer()->render() );
		$this->assertStringContainsString( '>EN<', $this->renderer()->render( array( 'display' => 'code' ) ) );
		$this->assertStringContainsString( 'English (EN)', $this->renderer()->render( array( 'display' => 'both' ) ) );
	}

	public function test_la_bandera_no_sustituye_al_nombre_para_un_lector_de_pantalla(): void {
		// Una bandera no es un idioma: el español no es de España ni el inglés
		// de Estados Unidos, así que la bandera es decorativa y el nombre queda.
		$this->visit( '/contacto/', 'es' );

		$html = $this->renderer()->render( array( 'display' => 'flag' ) );

		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringContainsString( 'screen-reader-text">English', $html );
	}

	public function test_un_idioma_sin_bandera_cae_en_su_nombre(): void {
		$this->visit( '/contacto/', 'es' );

		$html = $this->renderer()->render( array( 'display' => 'flag' ) );

		// El catalán no tiene bandera configurada.
		$this->assertStringContainsString( 'Català', $html );
	}

	public function test_con_un_solo_idioma_no_se_pinta_nada(): void {
		$solo = new LanguageRegistry( new Language( 'es_ES', 'es', 'Español' ) );

		$renderer = new SwitcherRenderer(
			$solo,
			new RequestContext( $solo, new UrlConverter( $solo, '/', false ) ),
			new UrlConverter( $solo, '/', false ),
			new SlugResolver( $this->slugs )
		);

		$this->assertSame( '', $renderer->render() );
	}

	public function test_los_idiomas_en_preparacion_solo_los_ve_quien_traduce(): void {
		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English', '', false, true, false ) )
		);

		$converter = new UrlConverter( $languages, '/', false );
		$request   = new RequestContext( $languages, $converter );

		$request->set_path( '/contacto/' );
		$request->force( $languages->default_language() );

		$renderer = new SwitcherRenderer( $languages, $request, $converter, new SlugResolver( $this->slugs ) );

		wp_set_current_user( 0 );
		$this->assertSame( '', $renderer->render(), 'Un visitante no ve un idioma sin publicar.' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );

		$this->assertNotFalse( $user );
		$user->add_cap( Capabilities::TRANSLATE );

		wp_set_current_user( $user_id );
		$this->assertStringContainsString( 'English', $renderer->render() );
	}

	public function test_el_shortcode_pinta_lo_mismo(): void {
		$this->visit( '/contacto/', 'es' );

		$shortcode = new Shortcode( $this->renderer() );

		$this->assertSame(
			$this->renderer()->render( array( 'display' => 'code' ) ),
			$shortcode->render( array( 'display' => 'code' ) )
		);
	}

	public function test_la_clase_extra_se_sanea(): void {
		$this->visit( '/contacto/', 'es' );

		$html = $this->renderer()->render( array( 'class' => 'mi-clase "onclick=x' ) );

		$this->assertStringContainsString( 'mi-clase', $html );
		$this->assertStringNotContainsString( 'onclick=x"', $html );
	}
}
