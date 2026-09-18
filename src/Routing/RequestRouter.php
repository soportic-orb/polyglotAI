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
 * Deja la petición en términos que WordPress entienda antes de que la analice.
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
 * Lo mismo vale para los slugs: en /en/contact-us/ ni el prefijo ni el slug
 * existen para WordPress, así que se traduce la ruta entera a /contacto/ antes
 * de que empiece a resolverla (ver SlugResolver).
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
	 * @param SlugResolver     $slugs     Enrutado inverso de slugs.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly UrlConverter $converter,
		private readonly RequestContext $request,
		private readonly SlugResolver $slugs
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

		// Antes del búfer de salida (ADR-04, prioridad 1): si hay que redirigir
		// no tiene sentido haber empezado a capturar la página.
		add_action( 'template_redirect', array( $this, 'maybe_redirect_to_translated_slug' ), 0 );
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

		// Los slugs de la ruta también están traducidos: /en/contact-us/ tiene
		// que acabar siendo /contacto/, que es lo único que WordPress sabe
		// resolver.
		$original = $this->slugs->to_original( $stripped, $language->locale );

		if ( $original === $path ) {
			return;
		}

		// Se conserva la cadena de consulta: quitar solo el segmento de idioma.
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		$_SERVER['REQUEST_URI'] = $original . ( '' === $query ? '' : '?' . $query );

		// WordPress no resuelve la petición solo con REQUEST_URI: si el
		// servidor ha rellenado PATH_INFO, WP::parse_request() lo prefiere y
		// REQUEST_URI deja de contar. Dejarlo sin tocar hacía que el prefijo de
		// idioma sobreviviera ahí y que /en/contacto/ acabara en un 404 en toda
		// instalación que lo rellene —el servidor integrado de PHP siempre, y
		// Apache o nginx según cómo estén configurados—. Solo se toca cuando es
		// exactamente la ruta que se acaba de reescribir: en una instalación en
		// subdirectorio puede llevar otro trozo delante y no hay por qué
		// adivinarlo.
		if ( isset( $_SERVER['PATH_INFO'] ) ) {
			$info = esc_url_raw( wp_unslash( (string) $_SERVER['PATH_INFO'] ) );

			if ( $info === $path ) {
				$_SERVER['PATH_INFO'] = $original;
			}
		}
	}

	/**
	 * Redirige del slug sin traducir al traducido.
	 *
	 * Que /en/contacto/ funcione es cómodo, pero dos URLs que sirven la misma
	 * página son contenido duplicado. Se responde con un 301 a la buena.
	 */
	public function maybe_redirect_to_translated_slug(): void {
		$target = $this->translated_slug_redirect();

		if ( null === $target ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * URL a la que habría que redirigir, o null si no hace falta.
	 *
	 * Se separa del propio redirect para poder probarla sin matar el proceso.
	 */
	public function translated_slug_redirect(): ?string {
		if ( $this->request->is_default() || is_404() ) {
			return null;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		// Redirigir un POST le tira los datos al visitante.
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return null;
		}

		if ( is_preview() || is_customize_preview() || is_robots() ) {
			return null;
		}

		$language = $this->request->language();
		$path     = $this->converter->strip( $this->request->path() );

		$translated = $this->slugs->to_translated( $path, $language->locale );

		if ( $translated === $path ) {
			return null;
		}

		$query = isset( $_SERVER['QUERY_STRING'] )
			? (string) wp_parse_url( '/?' . wp_unslash( $_SERVER['QUERY_STRING'] ), PHP_URL_QUERY ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: '';

		$target = $this->converter->convert( $translated, $language );

		return home_url( $target . ( '' === $query ? '' : '?' . $query ) );
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
