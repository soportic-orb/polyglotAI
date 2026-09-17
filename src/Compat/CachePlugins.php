<?php
/**
 * Vaciado de la caché de página al cambiar una traducción.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Compat;

/**
 * Le dice al plugin de caché de página que lo que tiene guardado ya no vale.
 *
 * Sin esto, la promesa del ADR-13 —«la traducción aparece en la visita
 * siguiente»— es falsa en cuanto el sitio lleva un plugin de caché: la tarea de
 * fondo traduce, pero el visitante sigue recibiendo la copia en HTML que se
 * guardó cuando todavía no estaba traducida, y así hasta que la copia caduque.
 * No es un detalle de rendimiento: es lo que hace que la función principal del
 * plugin funcione o no en un sitio de producción, que es donde siempre hay
 * caché.
 *
 * **Se vacía una vez por petición, no una por cadena.** Una traducción de sitio
 * completo guarda miles de cadenas en una pasada; vaciar la caché en cada una
 * dejaría el sitio sin caché durante horas y con el disco echando humo. Se anota
 * que hay que vaciar y se vacía en `shutdown`.
 *
 * **Se vacía entero, no por URL.** Una misma cadena puede salir en cualquier
 * página del sitio —un texto del menú, del pie, de un widget— y averiguar en
 * cuáles costaría más que volver a generarlas.
 *
 * Los nombres están comprobados en el código de cada plugin, salvo el de WP
 * Rocket, que es de pago y no se ha podido descargar aquí: `rocket_clean_domain()`
 * es su API pública documentada desde hace años.
 */
final class CachePlugins {

	/**
	 * Funciones de vaciado total, por plugin.
	 */
	private const PURGE_FUNCTIONS = array(
		'rocket_clean_domain',        // WP Rocket.
		'w3tc_flush_all',             // W3 Total Cache.
		'wp_cache_clear_cache',       // WP Super Cache.
		'sg_cachepress_purge_cache',  // SiteGround Optimizer.
	);

	/**
	 * Acciones de vaciado total, por plugin.
	 *
	 * Al ser acciones no hace falta comprobar si el plugin está: si no está,
	 * nadie las escucha y no pasa nada.
	 */
	private const PURGE_ACTIONS = array(
		'litespeed_purge_all',                 // LiteSpeed Cache.
		'cache_enabler_clear_complete_cache',  // Cache Enabler.
	);

	/**
	 * Si hay algo que vaciar al terminar la petición.
	 *
	 * @var bool
	 */
	private bool $dirty = false;

	/**
	 * Engancha el vaciado.
	 */
	public function register(): void {
		add_action( 'pgai_translation_saved', array( $this, 'mark' ) );
		add_action( 'pgai_slugs_changed', array( $this, 'mark' ) );
		add_action( 'shutdown', array( $this, 'purge' ), 100 );
	}

	/**
	 * Anota que hay que vaciar.
	 */
	public function mark(): void {
		$this->dirty = true;
	}

	/**
	 * Si queda algo por vaciar.
	 */
	public function is_dirty(): bool {
		return $this->dirty;
	}

	/**
	 * Vacía la caché de página si hace falta.
	 */
	public function purge(): void {
		if ( ! $this->dirty ) {
			return;
		}

		$this->dirty = false;

		/**
		 * Permite no vaciar la caché de página.
		 *
		 * Útil en sitios que prefieren esperar a que caduque sola o que vacían
		 * desde fuera.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $purge Si se vacía.
		 */
		if ( ! (bool) apply_filters( 'pgai_purge_page_cache', true ) ) {
			return;
		}

		foreach ( self::PURGE_FUNCTIONS as $purge ) {
			if ( function_exists( $purge ) ) {
				call_user_func( $purge );
			}
		}

		foreach ( self::PURGE_ACTIONS as $purge ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Son los hooks de cada plugin de caché, no nuestros.
			do_action( $purge );
		}

		$this->purge_wp_fastest_cache();

		/**
		 * Se dispara tras pedir el vaciado de la caché de página.
		 *
		 * Es el sitio donde enganchar el vaciado de una caché que el plugin no
		 * conozca: la de un alojamiento, un CDN, un proxy inverso.
		 *
		 * @since 0.1.0
		 */
		do_action( 'pgai_page_cache_purged' );
	}

	/**
	 * WP Fastest Cache, que vacía desde un objeto global.
	 */
	private function purge_wp_fastest_cache(): void {
		$cache = $GLOBALS['wp_fastest_cache'] ?? null;

		if ( is_object( $cache ) && method_exists( $cache, 'deleteCache' ) ) {
			$cache->deleteCache( true );
		}
	}
}
