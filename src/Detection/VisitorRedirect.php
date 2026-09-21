<?php
/**
 * Redirección al idioma del visitante en su primera visita.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Detection;

use PolyglotAI\Languages\Language;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Support\Options;

/**
 * Lleva al visitante al idioma de su navegador la primera vez que llega.
 *
 * **Viene desactivado.** No es timidez: una redirección automática es agresiva
 * y tiene dos consecuencias que hay que aceptar a sabiendas.
 *
 * - **Rompe las cachés de página que no varían por cookie.** Si el sitio sirve
 *   HTML cacheado desde Varnish o desde un plugin de caché, la primera
 *   respuesta cacheada se queda con la redirección dentro y se la come todo el
 *   mundo. Por eso, además, la redirección nunca se emite si ya hay una cookie:
 *   la petición cacheable es siempre la misma.
 * - **Se lleva al visitante a donde no ha pedido ir.** Alguien que sigue un
 *   enlace a la versión española desde Twitter acaba en la inglesa porque su
 *   navegador está en inglés.
 *
 * Quien lo active sabrá lo que hace; quien no, tiene el selector.
 *
 * La elección se recuerda en una cookie, y navegar a un idioma concreto —por el
 * selector, o por un enlace compartido— la actualiza: a partir de ahí manda lo
 * que el visitante ha hecho, no lo que dice su navegador.
 */
final class VisitorRedirect {

	/** Nombre de la cookie. */
	public const COOKIE = 'pgai_language';

	/** Duración de la cookie. */
	private const LIFETIME = YEAR_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Options         $options   Ajustes.
	 * @param RequestContext  $request   Contexto de la petición.
	 * @param UrlConverter    $converter Conversor de rutas.
	 * @param SlugResolver    $slugs     Traductor de slugs.
	 * @param BrowserLanguage $browser   Lectura de Accept-Language.
	 * @param BotDetector     $bots      Detector de robots.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly RequestContext $request,
		private readonly UrlConverter $converter,
		private readonly SlugResolver $slugs,
		private readonly BrowserLanguage $browser,
		private readonly BotDetector $bots
	) {}

	/**
	 * Engancha la redirección.
	 */
	public function register(): void {
		// Antes del búfer de salida: si hay que redirigir no tiene sentido
		// haber empezado a capturar la página.
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 0 );
	}

	/**
	 * Redirige si procede.
	 */
	public function maybe_redirect(): void {
		$target = $this->target();

		if ( null === $target ) {
			$this->remember_current();

			return;
		}

		$this->remember( $target );

		wp_safe_redirect( $this->url_for( $target ), 302 );
		exit;
	}

	/**
	 * Idioma al que habría que redirigir, o null si no hay que hacer nada.
	 *
	 * Se separa del propio redirect para poder probarla sin matar el proceso.
	 */
	public function target(): ?Language {
		if ( ! (bool) $this->options->get( 'detect_visitor_language', false ) ) {
			return null;
		}

		// Solo desde la URL sin prefijo: si el visitante ha pedido /en/, ya ha
		// dicho en qué idioma quiere el sitio.
		if ( ! $this->request->is_default() ) {
			return null;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return null;
		}

		if ( is_404() || is_feed() || is_preview() || is_customize_preview() || is_robots() ) {
			return null;
		}

		// Ya ha elegido antes: manda su elección, no su navegador.
		if ( null !== $this->cookie() ) {
			return null;
		}

		// Un robot no tiene idioma preferido: redirigirlo solo consigue que
		// indexe la versión equivocada de la portada.
		if ( $this->bots->is_bot() ) {
			return null;
		}

		$header = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) )
			: '';

		$preferred = $this->browser->preferred( $header );

		if ( null === $preferred || $preferred->slug === $this->request->language()->slug ) {
			return null;
		}

		/**
		 * Filtra el idioma al que se redirige a un visitante nuevo.
		 *
		 * Devolver null cancela la redirección.
		 *
		 * @since 2.0
		 *
		 * @param Language|null $preferred Idioma detectado.
		 * @param string        $header    Cabecera Accept-Language recibida.
		 */
		$filtered = apply_filters( 'pgai_detected_language', $preferred, $header );

		return $filtered instanceof Language ? $filtered : null;
	}

	/**
	 * URL equivalente a la actual en otro idioma.
	 *
	 * @param Language $language Idioma de destino.
	 */
	public function url_for( Language $language ): string {
		$path = $this->converter->strip( $this->request->path() );

		$translated = $this->slugs->to_translated( $path, $language->locale );

		$query = isset( $_SERVER['QUERY_STRING'] )
			? (string) wp_parse_url( '/?' . wp_unslash( $_SERVER['QUERY_STRING'] ), PHP_URL_QUERY ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: '';

		return home_url(
			$this->converter->convert( $translated, $language ) . ( '' === $query ? '' : '?' . $query )
		);
	}

	/**
	 * Idioma guardado en la cookie, si es uno de los del sitio.
	 */
	public function cookie(): ?string {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}

		$value = sanitize_key( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) );

		return '' === $value ? null : $value;
	}

	/**
	 * Recuerda el idioma en curso cuando el visitante ha llegado a él.
	 *
	 * Navegar a /en/ es una elección tan válida como pulsar el selector, así
	 * que a partir de ahí no se le vuelve a redirigir.
	 */
	private function remember_current(): void {
		if ( $this->request->is_default() ) {
			return;
		}

		$this->remember( $this->request->language() );
	}

	/**
	 * Guarda la elección en una cookie.
	 *
	 * @param Language $language Idioma.
	 */
	private function remember( Language $language ): void {
		if ( headers_sent() || $this->cookie() === $language->slug ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$language->slug,
			array(
				'expires'  => time() + self::LIFETIME,
				'path'     => (string) ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?? '/' ),
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ self::COOKIE ] = $language->slug;
	}
}
