<?php
/**
 * Comprobación de requisitos mínimos.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Bootstrap;

/**
 * Verifica que el entorno cumple los mínimos antes de arrancar nada.
 *
 * Es preferible un aviso claro en el escritorio a un error fatal a mitad de una
 * página del frontal.
 */
final class Requirements {

	/**
	 * Constructor.
	 *
	 * @param string $min_php Versión mínima de PHP.
	 * @param string $min_wp  Versión mínima de WordPress.
	 */
	public function __construct(
		private readonly string $min_php = '8.1',
		private readonly string $min_wp = '6.6'
	) {}

	/**
	 * Si el entorno cumple los requisitos.
	 */
	public function are_met(): bool {
		return array() === $this->problems();
	}

	/**
	 * Problemas encontrados, ya traducidos.
	 *
	 * @return string[]
	 */
	public function problems(): array {
		$problems = array();

		if ( version_compare( PHP_VERSION, $this->min_php, '<' ) ) {
			$problems[] = sprintf(
				/* translators: 1: versión requerida, 2: versión instalada. */
				__( 'Polyglot AI necesita PHP %1$s o superior. Esta instalación usa PHP %2$s.', 'polyglot-ai' ),
				$this->min_php,
				PHP_VERSION
			);
		}

		if ( version_compare( (string) get_bloginfo( 'version' ), $this->min_wp, '<' ) ) {
			$problems[] = sprintf(
				/* translators: 1: versión requerida, 2: versión instalada. */
				__( 'Polyglot AI necesita WordPress %1$s o superior. Esta instalación usa WordPress %2$s.', 'polyglot-ai' ),
				$this->min_wp,
				(string) get_bloginfo( 'version' )
			);
		}

		return $problems;
	}

	/**
	 * Muestra los problemas como aviso en el escritorio.
	 */
	public function show_notice(): void {
		$problems = $this->problems();

		if ( array() === $problems || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( implode( ' ', $problems ) )
		);
	}
}
