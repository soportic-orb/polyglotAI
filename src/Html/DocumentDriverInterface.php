<?php
/**
 * Contrato de los drivers de análisis de HTML.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

/**
 * Localiza las cadenas traducibles de un documento HTML.
 *
 * Un driver NO modifica el documento: solo dice qué hay que traducir y en qué
 * bytes está. La sustitución la hace siempre Splicer sobre el original, para que
 * todo byte no tocado salga idéntico (ver CLAUDE.md, ADR-01).
 */
interface DocumentDriverInterface {

	/**
	 * Extrae las unidades de traducción de un documento.
	 *
	 * Las unidades devueltas no se solapan entre sí.
	 *
	 * @param string $html Documento HTML completo.
	 * @return ExtractedString[] Unidades en orden de aparición.
	 */
	public function extract( string $html ): array;

	/**
	 * Identificador del driver, para registro y diagnóstico.
	 */
	public function name(): string;
}
