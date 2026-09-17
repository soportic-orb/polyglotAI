<?php
/**
 * Resolución del idioma a partir de la URL.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Routing;

use PolyglotAI\Languages\LanguageRegistry;

/**
 * Quita el segmento de idioma de la petición antes de que WordPress la analice.
 *
 * WordPress no sabe nada de /en/: si viera esa ruta buscaría una entrada
 * llamada «en» y devolvería un 404. Hay dos formas de resolverlo:
 *
 * 1. Duplicar todas las reglas de reescritura por cada idioma. Habría que
 *    duplicar también las que añade cualquier otro plugin, y volver a hacerlo
 *    cada vez que una se registre después de nosotros.
 * 2. Quitar el prefijo de la petición y dejar que WordPress resuelva el resto
 *    exactamente como lo haría en el idioma por defecto.
 *
 * Se elige la segunda: deja intacta la tabla de reescrituras y funciona con las
 * reglas de cualquier plugin, presentes y futuras. A cambio, home_url() y
 * compañía devuelven URLs sin prefijo, y por eso los enlaces se reescriben en la
 * salida (ver LinkRewriter) y la URL canónica se emite aparte.
 *
 * La ruta original se guarda antes de tocar nada: todo lo que necesita saber
 * «dónde está el visitante» —hreflang, selector de idioma, editor— la pide aquí
 * y no vuelve a leer REQUEST_URI.
 */
final class RequestRouter {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param UrlConverter     $converter Conversor de rutas.
	 * @param RequestContext   $request   Contexto de la petición.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly UrlConverter $converter,
		private readonly RequestContext $request
	) {}

	/**
	 * Engancha la resolución.
	 *
	 * Se hace en plugins_loaded porque WordPress no lee REQUEST_URI hasta
	 * analizar la petición, bastante después.
	 */
	public function register(): void {
		$this->resolve();

		// WordPress redirige a la URL «canónica» que él conoce, que es la de
		// sin prefijo: sin esto, /en/contacto/ rebotaría a /contacto/ y el
		// idioma se perdería en cada visita.
		add_filter( 'redirect_canonical', array( $this, 'keep_language_prefix' ) );
	}

	/**
	 * Detecta el idioma y quita su prefijo de la petición.
	 */
	public function resolve(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '/';

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;

		$this->request->set_path( $path );

		$language = $this->converter->detect( $path );

		$this->request->force( $language );

		if ( $this->languages->is_default( $language->slug ) ) {
			return;
		}

		$stripped = $this->converter->strip( $path );

		if ( $stripped === $path ) {
			return;
		}

		// Se conserva la cadena de consulta: quitar solo el segmento de idioma.
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		$_SERVER['REQUEST_URI'] = $stripped . ( '' === $query ? '' : '?' . $query );
	}

	/**
	 * Impide que la redirección canónica de WordPress se coma el idioma.
	 *
	 * @param string|false $redirect URL a la que WordPress quiere redirigir.
	 * @return string|false
	 */
	public function keep_language_prefix( $redirect ) {
		if ( ! is_string( $redirect ) || $this->request->is_default() ) {
			return $redirect;
		}

		$path = (string) wp_parse_url( $redirect, PHP_URL_PATH );

		// WordPress razona sobre la ruta sin prefijo, así que su destino hay que
		// devolverlo al idioma en curso antes de seguirlo.
		$converted = $this->converter->convert( $path, $this->request->language() );

		if ( $converted === $path ) {
			return $redirect;
		}

		return str_replace( $path, $converted, $redirect );
	}
}
