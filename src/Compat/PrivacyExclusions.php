<?php
/**
 * Exclusiones de privacidad.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Compat;

/**
 * Impide que las páginas con datos personales lleguen a la API (ADR-12).
 *
 * Los ajustes traen una lista de rutas excluidas —«/checkout», «/my-account»…—
 * y funciona mientras el sitio use esos slugs. En un sitio multilingüe no los
 * usa: la versión catalana de la página de pago es «/ca/pagament/» y no se
 * parece a ninguna cadena de la lista. Eso dejaba fuera de la exclusión justo
 * las páginas que más datos personales enseñan.
 *
 * WooCommerce sabe cuáles son esas páginas sin mirar la URL, porque guarda sus
 * identificadores en sus propias opciones. Preguntárselo a él acierta con
 * cualquier slug, en cualquier idioma y aunque el administrador las haya
 * cambiado de sitio. La lista de rutas de los ajustes se queda como red para
 * los sitios que no llevan WooCommerce.
 *
 * **Las respuestas a un POST tampoco se traducen.** Una respuesta a un envío de
 * formulario es, por definición, contenido construido con lo que acaba de
 * escribir el visitante: su nombre en un «Gracias, …», el resumen de lo que ha
 * pedido, el mensaje que ha dejado. Distinguir dentro del HTML qué frase viene
 * del formulario y cuál es del tema no se puede hacer con garantías, así que no
 * se intenta: esas respuestas se sirven sin traducir. El visitante ve el
 * original, que es el criterio de toda la red de seguridad del ADR-01.
 */
final class PrivacyExclusions {

	/**
	 * Etiquetas condicionales de WooCommerce que marcan una página personal.
	 *
	 * `is_wc_endpoint_url()` sin argumentos cubre los endpoints de la cuenta
	 * —pedidos, direcciones, descargas— y el «pedido recibido» del pago.
	 */
	private const WOOCOMMERCE_TAGS = array(
		'is_cart',
		'is_checkout',
		'is_account_page',
		'is_wc_endpoint_url',
	);

	/**
	 * Engancha la exclusión.
	 */
	public function register(): void {
		add_filter( 'pgai_should_process_output', array( $this, 'filter' ), 5 );
	}

	/**
	 * Quita de en medio las peticiones con datos personales.
	 *
	 * @param mixed $process Si se iba a procesar.
	 */
	public function filter( $process ): bool {
		return (bool) $process && ! $this->is_personal();
	}

	/**
	 * Si la petición en curso enseña datos personales.
	 */
	public function is_personal(): bool {
		return $this->is_form_response() || $this->is_woocommerce_page();
	}

	/**
	 * Si es la respuesta a un envío de formulario.
	 */
	private function is_form_response(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		return 'POST' === $method;
	}

	/**
	 * Si es una página de cuenta, carrito o pago de WooCommerce.
	 */
	private function is_woocommerce_page(): bool {
		foreach ( self::WOOCOMMERCE_TAGS as $tag ) {
			// Se llaman por nombre para no declarar una dependencia de código
			// con WooCommerce: si no está instalado, aquí no hay nada que hacer.
			if ( function_exists( $tag ) && true === call_user_func( $tag ) ) {
				return true;
			}
		}

		return false;
	}
}
