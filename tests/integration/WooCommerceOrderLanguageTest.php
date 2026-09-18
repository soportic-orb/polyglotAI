<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Compat\WooCommerce;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Mail\LanguageResolver;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\UrlConverter;
use WP_UnitTestCase;

/**
 * Los correos de un pedido salen en el idioma en que se hizo.
 *
 * WooCommerce no está instalado en la suite, y no hace falta: la integración
 * habla con él solo por nombres de hook y de método, así que un pedido y un
 * correo de mentira con esos mismos métodos ejercitan exactamente el mismo
 * camino. Lo que se comprueba aquí es nuestra lógica, no la suya.
 *
 * @covers \PolyglotAI\Compat\WooCommerce
 */
final class WooCommerceOrderLanguageTest extends WP_UnitTestCase {

	private WooCommerce $compat;
	private LanguageResolver $resolver;
	private RequestContext $request;
	private LanguageRegistry $languages;

	public function set_up(): void {
		parent::set_up();

		$this->languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English' ) )
		);

		$this->request = new RequestContext(
			$this->languages,
			new UrlConverter( $this->languages, '/', false )
		);

		$this->compat   = new WooCommerce( $this->languages, $this->request );
		$this->resolver = new LanguageResolver( $this->languages );

		$this->compat->register();
	}

	/**
	 * Un pedido de mentira con la parte de la API de WooCommerce que se usa.
	 *
	 * @param string $locale Idioma ya guardado, o vacío.
	 */
	private function order( string $locale = '' ): object {
		return new class( $locale ) {
			/** @var array<string, string> */
			public array $meta = array();

			/**
			 * @param string $locale Idioma guardado.
			 */
			public function __construct( string $locale ) {
				if ( '' !== $locale ) {
					$this->meta[ WooCommerce::ORDER_META ] = $locale;
				}
			}

			/**
			 * @param string $key   Clave.
			 * @param string $value Valor.
			 */
			public function update_meta_data( $key, $value ): void {
				$this->meta[ (string) $key ] = (string) $value;
			}

			/**
			 * @param string $key Clave.
			 */
			public function get_meta( $key ): string {
				return $this->meta[ (string) $key ] ?? '';
			}
		};
	}

	/**
	 * Un WC_Email de mentira: lo único que se le mira es ->object.
	 *
	 * @param object|null $order Pedido.
	 */
	private function email( ?object $order ): object {
		$email         = new \stdClass();
		$email->object = $order;

		return $email;
	}

	public function test_anota_en_el_pedido_el_idioma_en_que_se_compra(): void {
		$this->request->force( $this->languages->by_locale( 'en_US' ) );

		$order = $this->order();

		$this->compat->remember( $order );

		$this->assertSame( 'en_US', $order->get_meta( WooCommerce::ORDER_META ) );
	}

	public function test_el_correo_de_un_pedido_sale_en_el_idioma_del_pedido(): void {
		// Un invitado no tiene usuario ni preferencia guardada: sin esto su
		// aviso salía en el idioma por defecto del sitio.
		$this->compat->capture( array(), $this->email( $this->order( 'en_US' ) ) );

		$this->assertSame( 'en_US', $this->resolver->for_recipient( 'invitado@example.test' )->locale );
	}

	public function test_el_idioma_del_pedido_manda_sobre_el_del_usuario(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'cliente@example.test' ) );

		update_user_meta( $user_id, LanguageResolver::USER_META, 'es_ES' );

		$this->compat->capture( array(), $this->email( $this->order( 'en_US' ) ) );

		// El del pedido es el de la compra de la que habla ese correo; el del
		// usuario puede ser solo la última página que miró.
		$this->assertSame( 'en_US', $this->resolver->for_recipient( 'cliente@example.test' )->locale );
	}

	public function test_el_pedido_se_consume_una_sola_vez(): void {
		$this->compat->capture( array(), $this->email( $this->order( 'en_US' ) ) );

		$this->assertSame( 'en_US', $this->resolver->for_recipient( 'uno@example.test' )->locale );

		// El siguiente correo del sitio no es de ese pedido: uno de recuperar
		// contraseña no puede salir en el idioma del último pedido enviado.
		$this->assertSame( 'es_ES', $this->resolver->for_recipient( 'otro@example.test' )->locale );
	}

	public function test_un_pedido_sin_idioma_anotado_no_cambia_nada(): void {
		$this->compat->capture( array(), $this->email( $this->order() ) );

		$this->assertSame( 'es_ES', $this->resolver->for_recipient( 'quien@example.test' )->locale );
	}

	public function test_un_correo_que_no_es_de_un_pedido_no_se_ve_afectado(): void {
		$this->compat->capture( array(), $this->email( null ) );

		$this->assertSame( 'es_ES', $this->resolver->for_recipient( 'quien@example.test' )->locale );
	}

	public function test_no_rompe_si_le_llega_algo_que_no_es_un_pedido(): void {
		$this->compat->remember( 'esto no es un pedido' );

		$this->assertIsArray( $this->compat->capture( array( 'a' ), new \stdClass() ) );
	}
}
