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
use PolyglotAI\Routing\PermalinkTranslator;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Translation\StatusPrecedence;
use WP_UnitTestCase;

/**
 * Los enlaces que genera WordPress tienen que apuntar al slug traducido.
 *
 * @covers \PolyglotAI\Routing\PermalinkTranslator
 */
final class PermalinkTranslatorTest extends WP_UnitTestCase {

	private LanguageRegistry $languages;
	private RequestContext $request;
	private SlugRepository $slugs;

	/**
	 * Idiomas, enlaces bonitos, tabla limpia y filtros enganchados.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$table}`" );

		$this->languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English' ) )
		);

		$this->request = new RequestContext( $this->languages, new UrlConverter( $this->languages, '/', false ) );
		$this->slugs   = new SlugRepository( new StatusPrecedence() );

		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Engancha el traductor con el idioma dado.
	 *
	 * @param string $slug Slug del idioma.
	 */
	private function activate( string $slug = 'en' ): PermalinkTranslator {
		$language = 'en' === $slug
			? new Language( 'en_US', 'en', 'English' )
			: $this->languages->default();

		$this->request->force( $language );

		$translator = new PermalinkTranslator( $this->slugs, $this->request );
		$translator->register();

		return $translator;
	}

	public function test_el_enlace_de_una_pagina_usa_el_slug_traducido(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'page', $page_id, 'en_US', 'contacto', 'contact-us' ) );
		$this->activate();

		$this->assertSame( home_url( '/contact-us/' ), get_permalink( $page_id ) );
	}

	public function test_traduce_tambien_los_ascendientes(): void {
		$parent = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'empresa',
				'post_status' => 'publish',
			)
		);

		$child = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_parent' => $parent,
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'page', $parent, 'en_US', 'empresa', 'company' ) );
		$this->slugs->save( new SlugRecord( 'post', 'page', $child, 'en_US', 'contacto', 'contact-us' ) );
		$this->activate();

		$this->assertSame( home_url( '/company/contact-us/' ), get_permalink( $child ) );
	}

	public function test_el_enlace_de_una_entrada_usa_el_slug_traducido(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'mi-primera-entrada',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'post', $post_id, 'en_US', 'mi-primera-entrada', 'my-first-post' ) );
		$this->activate();

		$this->assertSame( home_url( '/my-first-post/' ), get_permalink( $post_id ) );
	}

	public function test_el_enlace_de_un_termino_traduce_slug_y_base(): void {
		$term_id = self::factory()->category->create( array( 'slug' => 'noticias' ) );

		$this->slugs->save( new SlugRecord( 'term', 'category', $term_id, 'en_US', 'noticias', 'news' ) );
		$this->slugs->save( new SlugRecord( 'base', 'category', 0, 'en_US', 'category', 'categoria' ) );
		$this->activate();

		$this->assertSame( home_url( '/categoria/news/' ), get_term_link( $term_id, 'category' ) );
	}

	public function test_sin_traduccion_el_enlace_no_cambia(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		$this->activate();

		$this->assertSame( home_url( '/contacto/' ), get_permalink( $page_id ) );
	}

	public function test_en_el_idioma_por_defecto_no_se_traduce_nada(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'page', $page_id, 'en_US', 'contacto', 'contact-us' ) );
		$this->activate( 'es' );

		$this->assertSame( home_url( '/contacto/' ), get_permalink( $page_id ) );
	}

	public function test_no_confunde_un_slug_con_un_trozo_de_otra_palabra(): void {
		// El slug «es» dentro de «/estudio/es/» solo puede sustituirse en el
		// segmento que le toca, no dentro de la otra palabra ni en el dominio.
		$parent = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'estudio',
				'post_status' => 'publish',
			)
		);

		$child = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'es',
				'post_parent' => $parent,
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'page', $child, 'en_US', 'es', 'spain' ) );
		$this->activate();

		$this->assertSame( home_url( '/estudio/spain/' ), get_permalink( $child ) );
	}

	public function test_una_lista_de_enlaces_no_es_una_consulta_por_enlace(): void {
		global $wpdb;

		$ids = array();

		for ( $index = 0; $index < 10; $index++ ) {
			$id    = self::factory()->post->create(
				array(
					'post_name'   => 'entrada-' . $index,
					'post_status' => 'publish',
				)
			);
			$ids[] = $id;

			$this->slugs->save( new SlugRecord( 'post', 'post', $id, 'en_US', 'entrada-' . $index, 'post-' . $index ) );
		}

		$translator = $this->activate();

		$posts = array_map( 'get_post', $ids );

		$translator->warm( $posts );

		$before = $wpdb->num_queries;

		foreach ( $ids as $index => $id ) {
			$this->assertSame( home_url( '/post-' . $index . '/' ), get_permalink( $id ) );
		}

		// Los slugs ya estaban precargados: pintar los diez enlaces no puede
		// haber costado ni una consulta de slugs más.
		$this->assertSame( 0, $wpdb->num_queries - $before, 'Pintar los enlaces ha vuelto a consultar la base de datos.' );
	}
}
