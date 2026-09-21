<?php
/**
 * Traducción de un documento HTML completo.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

use Throwable;

/**
 * Orquesta el ciclo extraer, buscar traducción, empalmar y verificar.
 *
 * Es el punto por el que pasa todo el HTML que sirve el sitio, así que su
 * contrato es estricto: ante cualquier fallo devuelve el documento original sin
 * tocar.
 */
final class DocumentProcessor {

	/**
	 * Constructor.
	 *
	 * @param DocumentDriverInterface $driver  Driver de extracción.
	 * @param Splicer                 $splicer Aplicador de sustituciones.
	 * @param Escaper                 $escaper Escapador por contexto.
	 * @param SafetyCheck             $safety  Comprobación de integridad.
	 */
	public function __construct(
		private readonly DocumentDriverInterface $driver,
		private readonly Splicer $splicer,
		private readonly Escaper $escaper,
		private readonly SafetyCheck $safety
	) {}

	/**
	 * Traduce un documento.
	 *
	 * @param string   $html   Documento original.
	 * @param callable $lookup fn(ExtractedString): string|RawHtml|null. Devuelve
	 *                         la traducción de una unidad, un RawHtml si ya es
	 *                         HTML listo, o null para dejarla como está.
	 * @return string Documento traducido, o el original si algo falla.
	 */
	public function translate( string $html, callable $lookup ): string {
		if ( '' === $html ) {
			return $html;
		}

		try {
			$replacements = array();

			foreach ( $this->driver->extract( $html ) as $unit ) {
				$translation = $lookup( $unit );

				if ( $translation instanceof RawHtml ) {
					$replacements[] = new Replacement( $unit->start, $unit->length, $translation->html );

					continue;
				}

				if ( ! is_string( $translation ) || $translation === $unit->value ) {
					continue;
				}

				$replacements[] = new Replacement(
					$unit->start,
					$unit->length,
					$this->escaper->escape( $unit->type, $translation )
				);
			}

			if ( array() === $replacements ) {
				return $html;
			}

			$result = $this->splicer->apply( $html, $replacements );
		} catch ( Throwable $error ) {
			/**
			 * Se dispara cuando la traducción de una página falla y se sirve el
			 * original.
			 *
			 * @since 2.0
			 *
			 * @param Throwable $error Excepción capturada.
			 */
			do_action( 'pgai_document_processing_failed', $error );

			return $html;
		}

		if ( ! $this->safety->passes( $html, $result ) ) {
			do_action( 'pgai_document_safety_check_failed', $this->driver->name() );

			return $html;
		}

		return $result;
	}
}
