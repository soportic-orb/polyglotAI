<?php
/**
 * Ajustes del plugin.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Support;

/**
 * Lee y escribe los ajustes.
 *
 * Todo lo que se consulta en cada petición vive en una sola opción autocargada;
 * lo voluminoso (glosario, exclusiones) va en opciones aparte sin autocarga,
 * para no engordar el arranque de WordPress.
 */
final class Options {

	/** Opción principal, autocargada. */
	public const MAIN = 'pgai_settings';

	/** Glosario, sin autocarga. */
	public const GLOSSARY = 'pgai_glossary';

	/** Términos que no se traducen, sin autocarga. */
	public const DO_NOT_TRANSLATE = 'pgai_do_not_translate';

	/**
	 * Ajustes en memoria durante la petición.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Valores por defecto.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'default_language'        => array(
				'locale' => 'es_ES',
				'slug'   => 'es',
				'label'  => 'Español',
			),
			'languages'               => array(),
			'prefix_default'          => false,
			'engine'                  => 'anthropic',
			'model'                   => 'claude-sonnet-5',
			'effort'                  => 'low',
			'thinking'                => false,
			'cache_ttl'               => 5,
			'site_context'            => '',

			// Traducción en tiempo real en segundo plano (ADR-13): activada,
			// pero nunca bloquea la carga ni traduce para bots.
			'realtime'                => true,
			'realtime_max_queued'     => 50,

			// Redirección al idioma del navegador en la primera visita.
			// Desactivada a propósito: ver Detection\VisitorRedirect.
			'detect_visitor_language' => false,

			// Menús por idioma: locale => (ubicación => id de menú).
			'menus'                   => array(),

			// Traducción del texto que aparece después de cargar la página.
			'dynamic'                 => true,

			// Tope mensual de tokens. 0 = sin tope.
			'monthly_token_limit'     => 0,

			'excluded_paths'          => array(
				// Exclusiones de privacidad por defecto (ADR-12): estas rutas
				// contienen datos personales que no deben salir del sitio.
				'/mi-cuenta',
				'/my-account',
				'/carrito',
				'/cart',
				'/finalizar-compra',
				'/checkout',
				'/wp-admin',
			),
			'excluded_selectors'      => array(),
			'uninstall_removes_data'  => false,
		);
	}

	/**
	 * Todos los ajustes, con los valores por defecto aplicados.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored = get_option( self::MAIN, array() );

			$this->cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return $this->cache;
	}

	/**
	 * Un ajuste concreto.
	 *
	 * @param string $key      Clave.
	 * @param mixed  $fallback Valor si el ajuste no existe.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->all()[ $key ] ?? $fallback;
	}

	/**
	 * Guarda un conjunto de ajustes.
	 *
	 * @param array<string, mixed> $values Ajustes a fusionar con los actuales.
	 */
	public function update( array $values ): void {
		$merged = array_merge( $this->all(), $values );

		update_option( self::MAIN, $merged, true );

		$this->cache = $merged;
	}

	/**
	 * Glosario: término de origen => traducción obligatoria, por idioma.
	 *
	 * @param string $language Locale.
	 * @return array<string, string>
	 */
	public function glossary( string $language ): array {
		$all = get_option( self::GLOSSARY, array() );

		if ( ! is_array( $all ) || ! is_array( $all[ $language ] ?? null ) ) {
			return array();
		}

		/** @var array<string, string> $entries */
		$entries = $all[ $language ];

		return $entries;
	}

	/**
	 * Términos que nunca se traducen.
	 *
	 * @return string[]
	 */
	public function do_not_translate(): array {
		$terms = get_option( self::DO_NOT_TRANSLATE, array() );

		return is_array( $terms ) ? array_values( array_filter( array_map( 'strval', $terms ) ) ) : array();
	}
}
