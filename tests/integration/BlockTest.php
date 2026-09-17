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
use PolyglotAI\Switcher\Block;
use PolyglotAI\Switcher\FloatingSwitcher;
use PolyglotAI\Switcher\SwitcherRenderer;
use PolyglotAI\Translation\StatusPrecedence;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Switcher\Block
 * @covers \PolyglotAI\Switcher\FloatingSwitcher
 */
final class BlockTest extends WP_UnitTestCase {

	private LanguageRegistry $languages;
	private RequestContext $request;
	private UrlConverter $converter;

	/**
	 * Idiomas, ajustes limpios y una petición situada.
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

		$this->request->set_path( '/contacto/' );
		$this->request->force( $this->languages->default_language() );

		$this->set_permalink_structure( '/%postname%/' );
	}

	private function renderer(): SwitcherRenderer {
		return new SwitcherRenderer(
			$this->languages,
			$this->request,
			$this->converter,
			new SlugResolver( new SlugRepository( new StatusPrecedence() ) )
		);
	}

	public function test_el_bloque_se_registra(): void {
		// El plugin entero se carga en la suite y ya lo ha registrado: se
		// deshace primero para comprobar el registro de verdad y no el suyo.
		if ( WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) ) {
			unregister_block_type( Block::NAME );
		}

		( new Block( $this->renderer(), $this->languages ) )->register_block();

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) );
	}

	public function test_el_bloque_se_pinta_en_el_servidor(): void {
		$block = new Block( $this->renderer(), $this->languages );

		$html = $block->render( array( 'display' => 'code' ) );

		$this->assertStringContainsString( 'pgai-switcher', $html );
		$this->assertStringContainsString( '>EN<', $html );
		$this->assertStringContainsString( home_url( '/en/contacto/' ), $html );
	}

	public function test_el_bloque_respeta_ocultar_el_idioma_en_curso(): void {
		$block = new Block( $this->renderer(), $this->languages );

		$html = $block->render( array( 'hideCurrent' => true ) );

		$this->assertStringNotContainsString( 'Español', $html );
		$this->assertStringContainsString( 'English', $html );
	}

	public function test_con_un_solo_idioma_el_bloque_no_pinta_nada(): void {
		$solo      = new LanguageRegistry( new Language( 'es_ES', 'es', 'Español' ) );
		$converter = new UrlConverter( $solo, '/', false );
		$request   = new RequestContext( $solo, $converter );

		$request->set_path( '/contacto/' );
		$request->force( $solo->default_language() );

		$block = new Block(
			new SwitcherRenderer( $solo, $request, $converter, new SlugResolver( new SlugRepository( new StatusPrecedence() ) ) ),
			$solo
		);

		$this->assertSame( '', $block->render() );
	}

	public function test_el_selector_flotante_viene_desactivado(): void {
		$floating = new FloatingSwitcher( $this->renderer(), new Options() );

		ob_start();
		$floating->render();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_el_selector_flotante_se_pinta_al_activarlo(): void {
		$options = new Options();
		$options->update( array( FloatingSwitcher::OPTION_KEY => true ) );

		$floating = new FloatingSwitcher( $this->renderer(), $options );

		ob_start();
		$floating->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'pgai-switcher-floating', $html );
		$this->assertStringContainsString( home_url( '/en/contacto/' ), $html );
	}
}
