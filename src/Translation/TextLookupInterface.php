<?php
/**
 * Búsqueda de traducciones de textos sueltos.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

/**
 * Traduce textos sueltos por lotes.
 *
 * Existe para que lo que solo necesita traducir un puñado de cadenas no
 * dependa del repositorio entero: los datos estructurados, por ejemplo, no
 * tienen nada que ver con la base de datos y así se pueden probar sin ella.
 */
interface TextLookupInterface {

	/**
	 * Traducción de varios textos con una sola consulta.
	 *
	 * Lo que no tenga traducción queda anotado como pendiente y se devuelve tal
	 * cual: nunca se deja un hueco.
	 *
	 * @param string[] $texts    Textos.
	 * @param string   $language Locale.
	 * @return array<string, string> Texto original => traducción (o el original).
	 */
	public function texts( array $texts, string $language ): array;
}
