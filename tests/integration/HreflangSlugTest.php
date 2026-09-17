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
use PolyglotAI\Routing\HeadTags;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Translation\StatusPrecedence;
use WP_UnitTestCase;

/**
 * Cada hreflang tiene que llevar el slug de su propio idioma.
 *
 * @covers \PolyglotAI\Routing\HeadTags
 * @covers \PolyglotAI\Routing\SlugResolver::paths_by_language
 */
final class HreflangSlugTest extends WP_UnitTestCase {

	private LanguageRegistry $languages;
	private RequestContext $request;
	private SlugRepository $slugs;
	private UrlConverter $converter;

	/**
	 * Tres idiomas y la tabla limpia.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$table}`" );

		$this->languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array(
				new Language( 'en_US', 'en', 'English' ),
				new Language( 'ca', 'ca', 'Català' ),
			)
		);

		$this->converter = new UrlConverter( $this->languages, '/', false );
		$this->request   = new RequestContext( $this->languages, $this->converter );
		$this->slugs     = new SlugRepository( new StatusPrecedence() );

		$this->set_permalink_structure( '/%postname%/' );
	}

	private function head_tags(): HeadTags {
		return new HeadTags(
			$this->languages,
			$this->request,
			$this->converter,
			new SlugResolver( $this->slugs )
		);
	}

	/**
	 * Devuelve las etiquetas hreflang emitidas.
	 */
	private function render(): string {
		ob_start();
		$this->head_tags()->alternates();

		return (string) ob_get_clean();
	}

	/**
	 * Sitúa la petición en una ruta y un idioma.
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

	public function test_cada_alternativa_lleva_su_propio_slug(): void {
		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'en_US', 'contacto', 'contact-us' ) );
		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'ca', 'contacto', 'contacte' ) );

		$this->visit( '/contacto/', 'es' );

		$html = $this->render();

		$this->assertStringContainsString( 'hreflang="es-ES" href="' . home_url( '/contacto/' ) . '"', $html );
		$this->assertStringContainsString( 'hreflang="en-US" href="' . home_url( '/en/contact-us/' ) . '"', $html );
		$this->assertStringContainsString( 'hreflang="ca" href="' . home_url( '/ca/contacte/' ) . '"', $html );
		$this->assertStringContainsString( 'hreflang="x-default" href="' . home_url( '/contacto/' ) . '"', $html );
	}

	public function test_funciona_llegando_desde_un_idioma_traducido(): void {
		// Aquí estaba el fallo que este test vigila: la ruta llega con slugs
		// catalanes, y sin volver primero al original las alternativas inglesa y
		// española habrían salido con el slug catalán.
		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'en_US', 'contacto', 'contact-us' ) );
		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'ca', 'contacto', 'contacte' ) );

		$this->visit( '/ca/contacte/', 'ca' );

		$html = $this->render();

		$this->assertStringContainsString( 'hreflang="es-ES" href="' . home_url( '/contacto/' ) . '"', $html );
		$this->assertStringContainsString( 'hreflang="en-US" href="' . home_url( '/en/contact-us/' ) . '"', $html );
		$this->assertStringContainsString( 'hreflang="ca" href="' . home_url( '/ca/contacte/' ) . '"', $html );
	}

	public function test_un_idioma_sin_slug_traducido_usa_el_original(): void {
		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'en_US', 'contacto', 'contact-us' ) );

		$this->visit( '/contacto/', 'es' );

		$html = $this->render();

		$this->assertStringContainsString( 'hreflang="ca" href="' . home_url( '/ca/contacto/' ) . '"', $html );
	}

	public function test_todas_las_alternativas_son_una_sola_consulta(): void {
		global $wpdb;

		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'en_US', 'contacto', 'contact-us' ) );
		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'ca', 'contacto', 'contacte' ) );

		$this->visit( '/contacto/', 'es' );

		$before = $wpdb->num_queries;

		$this->render();

		// Una por volver al slug original y otra para todos los idiomas a la
		// vez. Ni una por idioma.
		$this->assertLessThanOrEqual( 2, $wpdb->num_queries - $before );
	}

	public function test_el_canonico_lleva_el_prefijo_de_idioma(): void {
		$this->visit( '/en/contact-us/', 'en' );

		$this->assertSame(
			home_url( '/en/contact-us/' ),
			$this->head_tags()->canonical( home_url( '/contact-us/' ) )
		);
	}

	public function test_el_canonico_del_idioma_por_defecto_no_se_toca(): void {
		$this->visit( '/contacto/', 'es' );

		$this->assertSame(
			home_url( '/contacto/' ),
			$this->head_tags()->canonical( home_url( '/contacto/' ) )
		);
	}
}
