<?php
/**
 * Elección del driver de análisis de HTML.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

/**
 * Elige el driver disponible en esta instalación.
 *
 * El driver primario depende de una propiedad protegida del núcleo para obtener
 * las posiciones de los tokens (ver OffsetTagProcessor). La sonda de capacidad
 * comprueba en tiempo de ejecución que sigue funcionando: si una versión futura
 * de WordPress lo cambia, aquí no hay driver y el plugin deja de procesar la
 * salida en lugar de generar sustituciones en posiciones equivocadas.
 */
final class DriverFactory {

	/** Transitorio donde se cachea el resultado de la sonda. */
	private const PROBE_TRANSIENT = 'pgai_driver_probe';

	/**
	 * Driver a utilizar, o null si ninguno es viable.
	 *
	 * @param TagScanner     $scanner    Analizador de etiquetas.
	 * @param ExclusionRules $exclusions Reglas de exclusión.
	 */
	public function create( TagScanner $scanner, ExclusionRules $exclusions ): ?DocumentDriverInterface {
		if ( ! $this->html_api_works() ) {
			return null;
		}

		return new HtmlApiDriver( $scanner, $exclusions );
	}

	/**
	 * Si el mecanismo de posiciones de la HTML API funciona.
	 *
	 * El resultado se cachea por versión de WordPress: la sonda es barata, pero
	 * ejecutarla en cada petición no aporta nada.
	 */
	private function html_api_works(): bool {
		$key    = get_bloginfo( 'version' );
		$cached = get_transient( self::PROBE_TRANSIENT );

		if ( is_array( $cached ) && ( $cached['version'] ?? '' ) === $key ) {
			return (bool) $cached['works'];
		}

		$works = OffsetTagProcessor::is_supported();

		set_transient(
			self::PROBE_TRANSIENT,
			array(
				'version' => $key,
				'works'   => $works,
			),
			WEEK_IN_SECONDS
		);

		if ( ! $works ) {
			/**
			 * Se dispara cuando ningún driver de análisis es viable y el plugin
			 * deja de traducir la salida.
			 *
			 * @since 2.0
			 */
			do_action( 'pgai_no_driver_available' );
		}

		return $works;
	}
}
