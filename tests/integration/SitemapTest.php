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
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Seo\TranslatedSitemapProvider;
use PolyglotAI\Translation\StatusPrecedence;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Seo\TranslatedSitemapProvider
 */
final class SitemapTest extends WP_UnitTestCase {

	private TranslatedSitemapProvider $provider;
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
			array(
				new Language( 'en_US', 'en', 'English' ),
				new Language( 'ca', 'ca', 'Català' ),
			)
		);

		$this->slugs = new SlugRepository( new StatusPrecedence() );

		$this->provider = new TranslatedSitemapProvider(
			$languages,
			new UrlConverter( $languages, '/', false ),
			new SlugResolver( $this->slugs )
		);
	}

	/**
	 * Devuelve solo las URLs de una lista del sitemap.
	 *
	 * @param string $subtype Subtipo.
	 * @param int    $page    Página.
	 * @return string[]
	 */
	private function locs( string $subtype, int $page = 1 ): array {
		return array_map(
			static fn ( array $entry ): string => (string) $entry['loc'],
			$this->provider->get_url_list( $page, $subtype )
		);
	}

	public function test_hay_un_subtipo_por_idioma_traducible(): void {
		$subtypes = array_keys( $this->provider->get_object_subtypes() );

		sort( $subtypes );

		$this->assertSame( array( 'ca', 'ca-tax', 'en', 'en-tax' ), $subtypes );
	}

	public function test_el_idioma_por_defecto_no_tiene_sitemap_propio(): void {
		// Sus URLs ya están en el sitemap normal de WordPress.
		$this->assertArrayNotHasKey( 'es', $this->provider->get_object_subtypes() );
		$this->assertSame( array(), $this->locs( 'es' ) );
	}

	public function test_lista_las_entradas_con_prefijo_de_idioma(): void {
		self::factory()->post->create(
			array(
				'post_name'   => 'mi-entrada',
				'post_status' => 'publish',
			)
		);

		$this->assertSame( array( home_url( '/en/mi-entrada/' ) ), $this->locs( 'en' ) );
	}

	public function test_usa_el_slug_traducido_cuando_lo_hay(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'mi-entrada',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'post', $post_id, 'en_US', 'mi-entrada', 'my-post' ) );

		$this->assertSame( array( home_url( '/en/my-post/' ) ), $this->locs( 'en' ) );
	}

	public function test_cada_idioma_lleva_su_propio_slug(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'mi-entrada',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'post', $post_id, 'en_US', 'mi-entrada', 'my-post' ) );
		$this->slugs->save( new SlugRecord( 'post', 'post', $post_id, 'ca', 'mi-entrada', 'la-meva-entrada' ) );

		$this->assertSame( array( home_url( '/en/my-post/' ) ), $this->locs( 'en' ) );
		$this->assertSame( array( home_url( '/ca/la-meva-entrada/' ) ), $this->locs( 'ca' ) );
	}

	public function test_no_lista_borradores(): void {
		self::factory()->post->create(
			array(
				'post_name'   => 'borrador',
				'post_status' => 'draft',
			)
		);

		$this->assertSame( array(), $this->locs( 'en' ) );
	}

	public function test_las_entradas_llevan_fecha_de_modificacion(): void {
		self::factory()->post->create(
			array(
				'post_name'   => 'mi-entrada',
				'post_status' => 'publish',
			)
		);

		$entries = $this->provider->get_url_list( 1, 'en' );

		$this->assertArrayHasKey( 'lastmod', $entries[0] );
		$this->assertNotSame( '', $entries[0]['lastmod'] );
	}

	public function test_los_terminos_van_en_su_propio_subtipo(): void {
		global $wp_rewrite;

		create_initial_taxonomies();
		$wp_rewrite->flush_rules();

		$term_id = self::factory()->category->create( array( 'slug' => 'noticias' ) );

		self::factory()->post->create(
			array(
				'post_name'     => 'mi-entrada',
				'post_status'   => 'publish',
				'post_category' => array( $term_id ),
			)
		);

		$this->slugs->save( new SlugRecord( 'term', 'category', $term_id, 'en_US', 'noticias', 'news' ) );

		$this->assertContains( home_url( '/en/category/news/' ), $this->locs( 'en-tax' ) );
	}

	public function test_un_subtipo_desconocido_no_devuelve_nada(): void {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( array(), $this->locs( 'zz' ) );
		$this->assertSame( array(), $this->locs( '' ) );
		$this->assertSame( 0, $this->provider->get_max_num_pages( 'zz' ) );
	}

	public function test_las_urls_del_sitemap_encajan_con_la_ruta_del_nucleo(): void {
		// La regla del núcleo captura el nombre del proveedor con [a-z]+, así
		// que un idioma dentro del nombre habría dado un 404. Va en el subtipo.
		$url = $this->provider->get_sitemap_url( 'en', 1 );

		$this->assertSame( home_url( '/wp-sitemap-pgai-en-1.xml' ), $url );
		$this->assertSame( 1, preg_match( '#^wp-sitemap-([a-z]+?)-([a-z\d_-]+?)-(\d+?)\.xml$#', ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ) );
	}
}
