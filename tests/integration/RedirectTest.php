<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Admin\Redirect;
use WP_UnitTestCase;

/**
 * La vuelta a la pantalla después de guardar.
 *
 * @covers \PolyglotAI\Admin\Redirect
 */
final class RedirectTest extends WP_UnitTestCase {

	public function test_devuelve_una_url_absoluta(): void {
		$url = Redirect::to_page( 'polyglot-ai' );

		$this->assertStringStartsWith( admin_url(), $url );
		$this->assertStringContainsString( 'page=polyglot-ai', $url );
	}

	public function test_la_url_lleva_host_y_apunta_a_la_pantalla(): void {
		// Este es el test que faltaba. El destino tiene que ser absoluto: un
		// «Location» relativo lo resuelve el navegador contra admin-post.php,
		// que sin parámetro «action» no hace nada y devuelve una página en
		// blanco. Los ajustes se guardaban bien; lo que fallaba era la vuelta.
		$url = Redirect::to_page( 'polyglot-ai', array( 'pgai-saved' => '1' ) );

		$parts = (array) wp_parse_url( $url );

		$this->assertArrayHasKey( 'host', $parts );
		$this->assertStringContainsString( 'admin.php', (string) $parts['path'] );
	}

	public function test_lo_que_se_usaba_antes_no_lleva_ni_host_ni_ruta(): void {
		// menu_page_url() solo conoce páginas registradas, y admin-post.php
		// dispara admin_init pero NO admin_menu: cuando corre el manejador no
		// hay ningún menú registrado y devuelve cadena vacía.
		$this->assertSame( '', menu_page_url( 'polyglot-ai', false ) );

		$broken = add_query_arg( 'pgai-saved', '1', menu_page_url( 'polyglot-ai', false ) );

		$this->assertSame( '?pgai-saved=1', $broken );
		$this->assertArrayNotHasKey( 'host', (array) wp_parse_url( $broken ) );
	}

	public function test_lleva_los_parametros_que_se_le_pasan(): void {
		$url = Redirect::to_page(
			'polyglot-ai-strings',
			array(
				'pgai-lang' => 'en_US',
				'saved'     => '3',
			)
		);

		$this->assertStringContainsString( 'pgai-lang=en_US', $url );
		$this->assertStringContainsString( 'saved=3', $url );
	}
}
