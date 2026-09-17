<?php
/**
 * Detección de robots.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Detection;

/**
 * Reconoce el tráfico automatizado.
 *
 * Es una salvaguarda de presupuesto, no de seguridad: con la traducción en
 * segundo plano activada (ADR-13), un rastreo completo del sitio por un buscador
 * encolaría cada cadena de cada página. Sin este filtro, la primera visita de un
 * rastreador dispara la factura.
 */
final class BotDetector {

	/**
	 * Fragmentos de user-agent que identifican tráfico automatizado.
	 *
	 * @var string[]
	 */
	private const SIGNATURES = array(
		'bot',
		'crawl',
		'spider',
		'slurp',
		'search',
		'fetch',
		'monitor',
		'scan',
		'curl',
		'wget',
		'python-requests',
		'okhttp',
		'headless',
		'phantomjs',
		'lighthouse',
		'pagespeed',
		'gtmetrix',
		'pingdom',
		'uptime',
		'facebookexternalhit',
		'preview',
		'validator',
		'archiver',
		'feedfetcher',
		'mediapartners',
	);

	/**
	 * Si la petición actual parece de un robot.
	 */
	public function is_bot(): bool {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) )
			: '';

		// Un cliente sin user-agent no es un navegador.
		if ( '' === $agent ) {
			return true;
		}

		foreach ( self::SIGNATURES as $signature ) {
			if ( str_contains( $agent, $signature ) ) {
				return true;
			}
		}

		// Los navegadores siempre envían Accept-Language; casi ningún robot lo hace.
		if ( ! isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			return true;
		}

		/**
		 * Permite afinar la detección de robots.
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $is_bot Si se considera un robot.
		 * @param string $agent  User-agent en minúsculas.
		 */
		return (bool) apply_filters( 'pgai_is_bot', false, $agent );
	}
}
