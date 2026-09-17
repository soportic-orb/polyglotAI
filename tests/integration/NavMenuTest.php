<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Support\Options;
use PolyglotAI\Switcher\MenuLocations;
use PolyglotAI\Switcher\NavMenu;
use PolyglotAI\Switcher\SwitcherRenderer;
use PolyglotAI\Translation\StatusPrecedence;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Switcher\NavMenu
 * @covers \PolyglotAI\Switcher\MenuLocations
 */
final class NavMenuTest extends WP_UnitTestCase {

	private LanguageRegistry $languages;
	private RequestContext $request;
	private UrlConverter $converter;

	/**
	 * Idiomas y tabla de slugs limpia.
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
			array(
				new Language( 'en_US', 'en', 'English' ),
				new Language( 'ca', 'ca', 'Català' ),
			)
		);

		$this->converter = new UrlConverter( $this->languages, '/', false );
		$this->request   = new RequestContext( $this->languages, $this->converter );

		$this->set_permalink_structure( '/%postname%/' );
	}

	private function nav_menu(): NavMenu {
		$renderer = new SwitcherRenderer(
			$this->languages,
			$this->request,
			$this->converter,
			new SlugResolver( new SlugRepository( new StatusPrecedence() ) )
		);

		return new NavMenu( $renderer, $this->request );
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

	/**
	 * Un elemento de menú de mentira.
	 *
	 * @param string $url URL guardada.
	 * @param int    $id  Identificador.
	 */
	private function item( string $url, int $id = 5 ): object {
		return (object) array(
			'ID'               => $id,
			'db_id'            => $id,
			'menu_item_parent' => '0',
			'url'              => $url,
			'title'            => '',
			'classes'          => array( 'mi-clase' ),
		);
	}

	public function test_el_selector_se_convierte_en_un_padre_con_los_demas_idiomas(): void {
		$this->visit( '/contacto/', 'es' );

		$items = $this->nav_menu()->resolve( array( $this->item( NavMenu::SWITCHER_URL ) ) );

		$this->assertCount( 3, $items );
		$this->assertSame( 'Español', $items[0]->title, 'El padre es el idioma en curso.' );
		$this->assertSame( 'English', $items[1]->title );
		$this->assertSame( 'Català', $items[2]->title );
		$this->assertSame( home_url( '/en/contacto/' ), $items[1]->url );
	}

	public function test_los_hijos_cuelgan_del_padre_y_no_comparten_identificador(): void {
		// Dos elementos con el mismo ID harían que el menú se pintara con
		// clases y anidamiento cruzados.
		$this->visit( '/contacto/', 'es' );

		$items = $this->nav_menu()->resolve( array( $this->item( NavMenu::SWITCHER_URL ) ) );

		$ids = array_map( static fn ( object $item ): int => (int) $item->ID, $items );

		$this->assertSame( $ids, array_unique( $ids ) );
		$this->assertSame( (string) $items[0]->db_id, $items[1]->menu_item_parent );
		$this->assertSame( (string) $items[0]->db_id, $items[2]->menu_item_parent );
	}

	public function test_un_idioma_suelto_lleva_a_esta_misma_pagina(): void {
		$this->visit( '/contacto/', 'es' );

		$items = $this->nav_menu()->resolve( array( $this->item( NavMenu::LANGUAGE_URL_PREFIX . 'ca' ) ) );

		$this->assertCount( 1, $items );
		$this->assertSame( home_url( '/ca/contacto/' ), $items[0]->url );
		$this->assertSame( 'Català', $items[0]->title );
	}

	public function test_respeta_el_titulo_que_haya_escrito_el_administrador(): void {
		$this->visit( '/contacto/', 'es' );

		$item        = $this->item( NavMenu::LANGUAGE_URL_PREFIX . 'en' );
		$item->title = 'EN';

		$items = $this->nav_menu()->resolve( array( $item ) );

		$this->assertSame( 'EN', $items[0]->title );
	}

	public function test_conserva_las_clases_del_administrador(): void {
		$this->visit( '/contacto/', 'es' );

		$items = $this->nav_menu()->resolve( array( $this->item( NavMenu::LANGUAGE_URL_PREFIX . 'en' ) ) );

		$this->assertContains( 'mi-clase', $items[0]->classes );
		$this->assertContains( 'pgai-menu-language', $items[0]->classes );
	}

	public function test_un_idioma_que_ya_no_esta_no_deja_un_enlace_roto(): void {
		$this->visit( '/contacto/', 'es' );

		$items = $this->nav_menu()->resolve( array( $this->item( NavMenu::LANGUAGE_URL_PREFIX . 'zz' ) ) );

		$this->assertSame( array(), $items );
	}

	public function test_no_toca_los_elementos_normales(): void {
		$this->visit( '/contacto/', 'es' );

		$item  = $this->item( home_url( '/tienda/' ) );
		$items = $this->nav_menu()->resolve( array( $item ) );

		$this->assertSame( array( $item ), $items );
	}

	public function test_con_un_solo_idioma_el_selector_desaparece(): void {
		$solo      = new LanguageRegistry( new Language( 'es_ES', 'es', 'Español' ) );
		$converter = new UrlConverter( $solo, '/', false );
		$request   = new RequestContext( $solo, $converter );

		$request->set_path( '/contacto/' );
		$request->force( $solo->default_language() );

		$menu = new NavMenu(
			new SwitcherRenderer( $solo, $request, $converter, new SlugResolver( new SlugRepository( new StatusPrecedence() ) ) ),
			$request
		);

		$this->assertSame( array(), $menu->resolve( array( $this->item( NavMenu::SWITCHER_URL ) ) ) );
	}

	public function test_cada_idioma_puede_tener_su_propio_menu(): void {
		register_nav_menu( 'principal', 'Principal' );

		$spanish = wp_create_nav_menu( 'Menú español' );
		$english = wp_create_nav_menu( 'English menu' );

		$options = new Options();
		$options->update(
			array(
				MenuLocations::OPTION_KEY => array(
					'en_US' => array( 'principal' => $english ),
				),
			)
		);

		$locations = new MenuLocations( $options, $this->request );

		$this->visit( '/en/contacto/', 'en' );

		$args = $locations->swap( array( 'theme_location' => 'principal' ) );

		$this->assertSame( $english, $args['menu'] );

		$this->visit( '/contacto/', 'es' );

		$this->assertArrayNotHasKey(
			'menu',
			$locations->swap( array( 'theme_location' => 'principal' ) ),
			'El idioma por defecto usa el menú que ya tiene asignado la ubicación.'
		);

		// Sin asignación propia, el catalán también.
		$this->visit( '/ca/contacto/', 'ca' );

		$this->assertArrayNotHasKey( 'menu', $locations->swap( array( 'theme_location' => 'principal' ) ) );

		unset( $spanish );
	}

	public function test_un_menu_borrado_no_deja_el_sitio_sin_navegacion(): void {
		register_nav_menu( 'principal', 'Principal' );

		$menu_id = wp_create_nav_menu( 'Temporal' );

		$options = new Options();
		$options->update(
			array(
				MenuLocations::OPTION_KEY => array(
					'en_US' => array( 'principal' => $menu_id ),
				),
			)
		);

		wp_delete_nav_menu( $menu_id );

		$locations = new MenuLocations( $options, $this->request );

		$this->visit( '/en/contacto/', 'en' );

		// Con un menú inexistente wp_nav_menu() no pinta nada: mejor caer en el
		// de la ubicación que quedarse sin navegación.
		$this->assertArrayNotHasKey( 'menu', $locations->swap( array( 'theme_location' => 'principal' ) ) );
	}
}
