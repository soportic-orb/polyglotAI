<?php
/**
 * Etiquetas condicionales de WooCommerce para la suite unitaria.
 *
 * PrivacyExclusions le pregunta a WooCommerce si la página en curso es el
 * carrito, el pago o la cuenta (ADR-12), y lo hace por nombre de función para
 * no declarar una dependencia con un plugin que puede no estar. Estos dobles
 * permiten probar esa decisión sin instalar WooCommerce: devuelven lo que diga
 * $GLOBALS['pgai_test_woocommerce'], que por defecto es «ninguna».
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

if ( ! function_exists( 'pgai_test_woocommerce_is' ) ) {
	/**
	 * @param string $tag Etiqueta condicional.
	 * @return bool
	 */
	function pgai_test_woocommerce_is( $tag ) { // phpcs:ignore
		$state = isset( $GLOBALS['pgai_test_woocommerce'] ) ? (array) $GLOBALS['pgai_test_woocommerce'] : array();

		return ! empty( $state[ $tag ] );
	}
}

if ( ! function_exists( 'is_cart' ) ) {
	/**
	 * @return bool
	 */
	function is_cart() { // phpcs:ignore
		return pgai_test_woocommerce_is( 'is_cart' );
	}
}

if ( ! function_exists( 'is_checkout' ) ) {
	/**
	 * @return bool
	 */
	function is_checkout() { // phpcs:ignore
		return pgai_test_woocommerce_is( 'is_checkout' );
	}
}

if ( ! function_exists( 'is_account_page' ) ) {
	/**
	 * @return bool
	 */
	function is_account_page() { // phpcs:ignore
		return pgai_test_woocommerce_is( 'is_account_page' );
	}
}

if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	/**
	 * @param string|false $endpoint Endpoint concreto.
	 * @return bool
	 */
	function is_wc_endpoint_url( $endpoint = false ) { // phpcs:ignore
		return pgai_test_woocommerce_is( 'is_wc_endpoint_url' );
	}
}
