<?php
/**
 * API pública del plugin.
 *
 * Todo lo que hay aquí es contrato con los desarrolladores: se mantiene entre
 * versiones menores.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

use PolyglotAI\Plugin;

if ( ! function_exists( 'pgai_current_language' ) ) {
	/**
	 * Locale del idioma en que se está sirviendo la petición.
	 *
	 * @return string Por ejemplo «en_US».
	 */
	function pgai_current_language(): string {
		return Plugin::instance()->request()->language()->locale;
	}
}

if ( ! function_exists( 'pgai_is_default_language' ) ) {
	/**
	 * Si la petición se sirve en el idioma original del sitio.
	 */
	function pgai_is_default_language(): bool {
		return Plugin::instance()->request()->is_default();
	}
}

if ( ! function_exists( 'pgai_languages' ) ) {
	/**
	 * Idiomas visibles del sitio.
	 *
	 * @return array<int, array{locale:string, slug:string, label:string, rtl:bool}>
	 */
	function pgai_languages(): array {
		$languages = array();

		foreach ( Plugin::instance()->languages()->visible() as $language ) {
			$languages[] = array(
				'locale' => $language->locale,
				'slug'   => $language->slug,
				'label'  => $language->label,
				'rtl'    => $language->rtl,
			);
		}

		return $languages;
	}
}

if ( ! function_exists( 'pgai_translate' ) ) {
	/**
	 * Traduce un texto suelto al idioma indicado.
	 *
	 * Si no hay traducción todavía, devuelve el original y anota la cadena para
	 * que se traduzca en segundo plano.
	 *
	 * @param string      $text   Texto.
	 * @param string|null $locale Locale de destino, o el de la petición.
	 */
	function pgai_translate( string $text, ?string $locale = null ): string {
		return Plugin::instance()->lookup()->text( $text, $locale ?? pgai_current_language() );
	}
}

if ( ! function_exists( 'pgai_with_language' ) ) {
	/**
	 * Ejecuta un bloque de código como si la petición fuese de otro idioma.
	 *
	 * Es el punto de entrada para generar contenido en un idioma concreto:
	 * el correo de un pedido en el idioma del cliente, por ejemplo. Dentro del
	 * bloque, las cadenas de gettext y pgai_current_language() responden en ese
	 * idioma; al salir, todo vuelve a como estaba, también si el bloque lanza
	 * una excepción.
	 *
	 * @param string   $locale   Locale de destino.
	 * @param callable $callback Código a ejecutar.
	 * @return mixed Lo que devuelva el callback.
	 */
	function pgai_with_language( string $locale, callable $callback ) {
		return Plugin::instance()->with_language( $locale, $callback );
	}
}
