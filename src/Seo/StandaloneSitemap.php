<?php
/**
 * Sitemaps por idioma servidos por el propio plugin.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Seo;

/**
 * Sirve los sitemaps por idioma sin depender del sitemap del núcleo.
 *
 * Yoast, Rank Math, SEOPress y All in One SEO apagan el sitemap del núcleo
 * —los cuatro con el mismo filtro `wp_sitemaps_enabled`, comprobado en su
 * código— y con él se van las rutas `wp-sitemap-*.xml` y el proveedor del
 * ADR-16. Esta clase vuelve a publicar exactamente las mismas URLs, las de
 * TranslatedUrls, en rutas propias, y Compat\SeoPlugins las enlaza desde el
 * índice del plugin que esté activo.
 *
 * **Sin reglas de reescritura, a propósito.** Una regla nueva no existe hasta
 * que alguien vacía las reglas, y aquí el disparador es activar un plugin
 * ajeno: el sitio se quedaría sirviendo 404 hasta que al administrador se le
 * ocurriera volver a guardar los enlaces permanentes. Se resuelve en
 * `parse_request`, que corre antes de que WordPress decida el 404 y antes de
 * que se envíe ninguna cabecera, mirando la ruta como ya hace el enrutador del
 * ADR-09. El prefijo de idioma ya viene quitado de ahí, así que `/en/` no
 * necesita tratamiento aparte.
 */
final class StandaloneSitemap {

	/**
	 * Prefijo de todas nuestras rutas de sitemap.
	 */
	public const PREFIX = 'pgai-sitemap';

	/**
	 * Constructor.
	 *
	 * @param TranslatedUrls $urls Lista de URLs traducidas.
	 */
	public function __construct( private readonly TranslatedUrls $urls ) {}

	/**
	 * Engancha el manejador.
	 */
	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_render' ), 0 );
		add_filter( 'robots_txt', array( $this, 'announce' ), 10, 2 );
	}

	/**
	 * Sirve el sitemap si la petición es para uno nuestro.
	 */
	public function maybe_render(): void {
		if ( ! $this->available() ) {
			return;
		}

		$file = $this->requested();

		if ( null === $file ) {
			return;
		}

		[ $subtype, $page ] = $file;

		$xml = $this->render( $subtype, $page );

		if ( null === $xml ) {
			return;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cada valor se escapa al construir el XML.
		echo $xml;

		exit;
	}

	/**
	 * XML de un sitemap nuestro, o null si no existe.
	 *
	 * @param string|null $subtype Subtipo, o null para el índice.
	 * @param int         $page    Página.
	 */
	public function render( ?string $subtype, int $page = 1 ): ?string {
		return null === $subtype ? $this->index_xml() : $this->urlset_xml( $subtype, $page );
	}

	/**
	 * Añade nuestro índice a robots.txt.
	 *
	 * Un plugin de SEO enlaza nuestros archivos desde su índice, pero si no hay
	 * ninguno activo y aun así el sitemap del núcleo está apagado, esta línea es
	 * lo único que los hace descubribles.
	 *
	 * @param string $output    Contenido de robots.txt.
	 * @param bool   $is_public Si el sitio es público.
	 */
	public function announce( $output, $is_public ): string {
		$output = (string) $output;

		if ( ! $is_public || ! $this->available() ) {
			return $output;
		}

		return $output . 'Sitemap: ' . esc_url_raw( $this->index_url() ) . "\n";
	}

	/**
	 * Si estas rutas están en servicio.
	 *
	 * Solo cuando el sitemap del núcleo no lo está: si lo está, el proveedor del
	 * ADR-16 ya publica estas mismas URLs dentro de él y duplicarlas en dos
	 * sitios no aporta nada.
	 */
	public function available(): bool {
		if ( array() === $this->urls->subtypes() ) {
			return false;
		}

		$public = (bool) get_option( 'blog_public' );

		if ( ! $public ) {
			return false;
		}

		/**
		 * Preguntarle al núcleo por su propio filtro es la única forma de saber
		 * si su sitemap sigue en pie: WP_Sitemaps::sitemaps_enabled() lo evalúa
		 * exactamente así y no expone el resultado. Los cuatro plugins de SEO
		 * lo apagan por aquí.
		 *
		 * This filter is documented in wp-includes/sitemaps/class-wp-sitemaps.php
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Es la pregunta del núcleo, no un hook nuestro.
		return ! (bool) apply_filters( 'wp_sitemaps_enabled', $public );
	}

	/**
	 * URL de nuestro índice.
	 */
	public function index_url(): string {
		return home_url( '/' . self::PREFIX . '.xml' );
	}

	/**
	 * URL de un archivo concreto.
	 *
	 * @param string $subtype Subtipo.
	 * @param int    $page    Página.
	 */
	public function file_url( string $subtype, int $page ): string {
		return home_url( sprintf( '/%s-%s-%d.xml', self::PREFIX, $subtype, $page ) );
	}

	/**
	 * Todos los archivos que publica, con su fecha.
	 *
	 * Es lo que Compat\SeoPlugins mete en el índice del plugin de SEO activo.
	 *
	 * @return array<int, array{loc:string, lastmod:string, count:int}>
	 */
	public function files(): array {
		$files   = array();
		$lastmod = $this->lastmod();

		$per_page = $this->urls->per_page();

		foreach ( $this->urls->subtypes() as $subtype ) {
			$pages = $this->urls->pages( $subtype );
			$total = $this->urls->total( $subtype );

			for ( $page = 1; $page <= $pages; $page++ ) {
				$files[] = array(
					'loc'     => $this->file_url( $subtype, $page ),
					'lastmod' => $lastmod,
					'count'   => max( 0, min( $per_page, $total - ( ( $page - 1 ) * $per_page ) ) ),
				);
			}
		}

		return $files;
	}

	/**
	 * Qué sitemap pide la petición en curso.
	 *
	 * @return array{0: string|null, 1: int}|null Subtipo (null para el índice) y página.
	 */
	public function requested(): ?array {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}

		$uri = sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );

		if ( ! str_contains( $uri, self::PREFIX ) ) {
			return null;
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( '' !== $base && '/' !== $base && str_starts_with( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) - 1 );
		}

		$matches = array();

		if ( 1 !== preg_match( '#^/' . self::PREFIX . '(?:-([a-z\d_-]+)-(\d+))?\.xml$#', $path, $matches ) ) {
			return null;
		}

		if ( ! isset( $matches[1] ) ) {
			return array( null, 0 );
		}

		return array( $matches[1], max( 1, (int) $matches[2] ) );
	}

	/**
	 * XML del índice.
	 */
	private function index_xml(): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $this->files() as $file ) {
			$xml .= "\t<sitemap>\n";
			$xml .= "\t\t<loc>" . esc_url( $file['loc'] ) . "</loc>\n";

			if ( '' !== $file['lastmod'] ) {
				$xml .= "\t\t<lastmod>" . esc_xml( $file['lastmod'] ) . "</lastmod>\n";
			}

			$xml .= "\t</sitemap>\n";
		}

		return $xml . '</sitemapindex>' . "\n";
	}

	/**
	 * XML de un archivo de URLs.
	 *
	 * @param string $subtype Subtipo.
	 * @param int    $page    Página.
	 */
	private function urlset_xml( string $subtype, int $page ): ?string {
		if ( null === $this->urls->resolve( $subtype ) ) {
			return null;
		}

		$list = $this->urls->urls( $subtype, $page );

		if ( array() === $list ) {
			return null;
		}

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $list as $url ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( (string) $url['loc'] ) . "</loc>\n";

			if ( isset( $url['lastmod'] ) && '' !== $url['lastmod'] ) {
				$xml .= "\t\t<lastmod>" . esc_xml( (string) $url['lastmod'] ) . "</lastmod>\n";
			}

			$xml .= "\t</url>\n";
		}

		return $xml . '</urlset>' . "\n";
	}

	/**
	 * Fecha de la última modificación del sitio, en formato W3C.
	 */
	private function lastmod(): string {
		$last = get_lastpostmodified( 'gmt' );

		if ( ! is_string( $last ) || '' === $last ) {
			return '';
		}

		$time = strtotime( $last . ' +0000' );

		return false === $time ? '' : gmdate( DATE_W3C, $time );
	}
}
