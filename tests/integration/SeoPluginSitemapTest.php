<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Compat\SeoPlugins;
use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SlugRecord;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Seo\StandaloneSitemap;
use PolyglotAI\Seo\TranslatedUrls;
use PolyglotAI\Translation\StatusPrecedence;
use SimpleXMLElement;
use WP_UnitTestCase;

/**
 * Los sitemaps por idioma cuando un plugin de SEO apaga el del núcleo.
 *
 * @covers \PolyglotAI\Seo\StandaloneSitemap
 * @covers \PolyglotAI\Compat\SeoPlugins
 */
final class SeoPluginSitemapTest extends WP_UnitTestCase {

	private StandaloneSitemap $sitemap;
	private SeoPlugins $compat;
	private SlugRepository $slugs;

	/**
	 * Idiomas, enlaces bonitos y tabla limpia.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$table}`" );

		$this->set_permalink_structure( '/%postname%/' );

		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English' ) )
		);

		$this->slugs = new SlugRepository( new StatusPrecedence() );

		$this->sitemap = new StandaloneSitemap(
			new TranslatedUrls(
				$languages,
				new UrlConverter( $languages, '/', false ),
				new SlugResolver( $this->slugs )
			)
		);

		$this->compat = new SeoPlugins( $this->sitemap );
	}

	/**
	 * Hace lo mismo que Yoast, Rank Math, SEOPress y All in One SEO: apagar el
	 * sitemap del núcleo con el filtro del propio núcleo.
	 */
	private function seo_plugin_takes_over(): void {
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
	}

	/**
	 * Una entrada publicada con su slug traducido.
	 */
	private function translated_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'mi-entrada',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'post', $post_id, 'en_US', 'mi-entrada', 'my-post' ) );
	}

	public function test_las_rutas_propias_no_responden_si_el_sitemap_del_nucleo_sigue_en_pie(): void {
		$this->translated_post();

		// Ahí las URLs traducidas ya están dentro del sitemap del núcleo.
		$this->assertFalse( $this->sitemap->available() );
	}

	public function test_las_rutas_propias_se_activan_cuando_un_plugin_de_seo_apaga_el_del_nucleo(): void {
		$this->translated_post();
		$this->seo_plugin_takes_over();

		$this->assertTrue( $this->sitemap->available() );
	}

	public function test_un_sitio_privado_no_publica_sitemap(): void {
		$this->translated_post();
		$this->seo_plugin_takes_over();

		update_option( 'blog_public', '0' );

		$this->assertFalse( $this->sitemap->available() );
	}

	public function test_el_indice_lista_los_archivos_de_cada_idioma(): void {
		$this->translated_post();

		$xml = (string) $this->sitemap->render( null );

		$index = new SimpleXMLElement( $xml );

		$found = $index->xpath( '//*[local-name()="loc"]' );
		$locs  = array_map( 'strval', is_array( $found ) ? $found : array() );

		// Uno para las entradas y otro para los términos: paginar una lista que
		// mezcla las dos consultas se descuadra en cuanto una cambia de tamaño.
		$this->assertSame(
			array( home_url( '/pgai-sitemap-en-1.xml' ), home_url( '/pgai-sitemap-en-tax-1.xml' ) ),
			$locs
		);
	}

	public function test_un_archivo_lista_las_urls_traducidas(): void {
		$this->translated_post();

		$xml = (string) $this->sitemap->render( 'en', 1 );

		$urlset = new SimpleXMLElement( $xml );

		$this->assertSame( home_url( '/en/my-post/' ), (string) $urlset->url[0]->loc );
		$this->assertNotSame( '', (string) $urlset->url[0]->lastmod );
	}

	public function test_un_subtipo_desconocido_no_tiene_archivo(): void {
		$this->translated_post();

		$this->assertNull( $this->sitemap->render( 'zz', 1 ) );
		$this->assertNull( $this->sitemap->render( 'es', 1 ) );
	}

	public function test_reconoce_sus_rutas_y_solo_las_suyas(): void {
		$cases = array(
			'/pgai-sitemap.xml'          => array( null, 0 ),
			'/pgai-sitemap-en-1.xml'     => array( 'en', 1 ),
			'/pgai-sitemap-pt-br-2.xml'  => array( 'pt-br', 2 ),
			'/pgai-sitemap-en-tax-1.xml' => array( 'en-tax', 1 ),
		);

		foreach ( $cases as $uri => $expected ) {
			$_SERVER['REQUEST_URI'] = $uri;

			$this->assertSame( $expected, $this->sitemap->requested(), $uri );
		}

		foreach ( array( '/', '/wp-sitemap.xml', '/pgai-sitemap-en-1.html', '/algo/pgai-sitemap.xml' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;

			$this->assertNull( $this->sitemap->requested(), $uri );
		}
	}

	public function test_yoast_recibe_los_archivos_en_su_formato(): void {
		$this->translated_post();
		$this->seo_plugin_takes_over();

		$links = $this->compat->yoast( array( array( 'loc' => 'https://example.org/post-sitemap.xml' ) ) );

		// Los suyos siguen ahí y los nuestros van detrás.
		$this->assertCount( 3, $links );
		$this->assertSame( 'https://example.org/post-sitemap.xml', $links[0]['loc'] );
		$this->assertSame( home_url( '/pgai-sitemap-en-1.xml' ), $links[1]['loc'] );
		$this->assertArrayHasKey( 'lastmod', $links[1] );
	}

	public function test_rank_math_recibe_xml_valido(): void {
		$this->translated_post();
		$this->seo_plugin_takes_over();

		$xml = $this->compat->rank_math( '' );

		$this->assertStringContainsString( home_url( '/pgai-sitemap-en-1.xml' ), $xml );

		// Rank Math lo concatena dentro de su <sitemapindex>: tiene que casar.
		$index = new SimpleXMLElement( '<sitemapindex>' . $xml . '</sitemapindex>' );

		$this->assertCount( 2, $index->sitemap );
	}

	public function test_seopress_recibe_los_archivos_en_su_formato(): void {
		$this->translated_post();
		$this->seo_plugin_takes_over();

		// SEOPress pasa null cuando nadie ha añadido nada.
		$sitemaps = $this->compat->seopress( null );

		$this->assertIsArray( $sitemaps );
		$this->assertSame( home_url( '/pgai-sitemap-en-1.xml' ), $sitemaps[0]['sitemap_url'] );
		$this->assertArrayHasKey( 'sitemap_last_mod', $sitemaps[0] );
	}

	public function test_aioseo_recibe_los_archivos_con_su_recuento(): void {
		$this->translated_post();
		$this->seo_plugin_takes_over();

		$indexes = $this->compat->aioseo( array() );

		$this->assertSame( home_url( '/pgai-sitemap-en-1.xml' ), $indexes[0]['loc'] );
		$this->assertSame( 1, $indexes[0]['count'] );
	}

	public function test_no_se_anuncia_lo_que_no_se_sirve(): void {
		$this->translated_post();

		// Sin plugin de SEO el sitemap del núcleo sigue en pie y estas rutas no
		// responden: anunciarlas sería publicar cuatro 404.
		$this->assertSame( array(), $this->compat->yoast( array() ) );
		$this->assertSame( '', $this->compat->rank_math( '' ) );
		$this->assertNull( $this->compat->seopress( null ) );
		$this->assertSame( array(), $this->compat->aioseo( array() ) );
	}

	public function test_robots_txt_anuncia_el_indice(): void {
		$this->translated_post();
		$this->seo_plugin_takes_over();

		$this->assertStringContainsString(
			'Sitemap: ' . home_url( '/pgai-sitemap.xml' ),
			$this->sitemap->announce( "User-agent: *\n", true )
		);
	}

	public function test_robots_txt_calla_si_las_rutas_no_estan_en_servicio(): void {
		$this->translated_post();

		$this->assertSame( "User-agent: *\n", $this->sitemap->announce( "User-agent: *\n", true ) );
	}
}
