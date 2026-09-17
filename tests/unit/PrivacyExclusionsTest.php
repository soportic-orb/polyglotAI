<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PolyglotAI\Compat\PrivacyExclusions;

/**
 * Las páginas con datos personales no llegan a la API (ADR-12).
 *
 * @covers \PolyglotAI\Compat\PrivacyExclusions
 */
final class PrivacyExclusionsTest extends TestCase {

	private PrivacyExclusions $exclusions;

	protected function setUp(): void {
		parent::setUp();

		$this->exclusions                 = new PrivacyExclusions();
		$_SERVER['REQUEST_METHOD']        = 'GET';
		$GLOBALS['pgai_test_woocommerce'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['pgai_test_woocommerce'] = array();

		parent::tearDown();
	}

	public function test_una_pagina_normal_se_traduce(): void {
		$this->assertFalse( $this->exclusions->is_personal() );
		$this->assertTrue( $this->exclusions->filter( true ) );
	}

	public function test_la_respuesta_a_un_post_no_se_traduce(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Lo que se devuelve tras un envío está hecho con lo que ha escrito el
		// visitante, y no hay forma fiable de separarlo del texto del tema.
		$this->assertTrue( $this->exclusions->is_personal() );
		$this->assertFalse( $this->exclusions->filter( true ) );
	}

	/**
	 * @dataProvider paginas_de_woocommerce
	 *
	 * @param string $tag Etiqueta condicional de WooCommerce.
	 */
	public function test_las_paginas_personales_de_woocommerce_no_se_traducen( string $tag ): void {
		$GLOBALS['pgai_test_woocommerce'] = array( $tag => true );

		$this->assertTrue( $this->exclusions->is_personal(), $tag );
		$this->assertFalse( $this->exclusions->filter( true ), $tag );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function paginas_de_woocommerce(): array {
		return array(
			'carrito'   => array( 'is_cart' ),
			'pago'      => array( 'is_checkout' ),
			'cuenta'    => array( 'is_account_page' ),
			'endpoints' => array( 'is_wc_endpoint_url' ),
		);
	}

	public function test_no_reactiva_lo_que_otro_ya_habia_excluido(): void {
		// El filtro solo sabe quitar, nunca poner: si alguien antes ha dicho
		// que no, aquí no se le lleva la contraria.
		$this->assertFalse( $this->exclusions->filter( false ) );
	}

	public function test_una_tienda_sin_paginas_personales_a_la_vista_se_traduce(): void {
		$GLOBALS['pgai_test_woocommerce'] = array( 'is_shop' => true );

		$this->assertFalse( $this->exclusions->is_personal() );
	}
}
