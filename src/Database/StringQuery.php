<?php
/**
 * Criterios de búsqueda del gestor de cadenas.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Database;

use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StringType;

/**
 * Lo que el gestor de cadenas pide a la base de datos.
 *
 * Es un objeto y no una lista de argumentos porque son siete criterios y
 * llamadas como `search( $lang, '', null, null, 1, 50, 'last_seen' )` son
 * ilegibles y fáciles de descolocar.
 */
final class StringQuery {

	/**
	 * Constructor.
	 *
	 * @param string          $language Locale de destino.
	 * @param string          $search   Texto a buscar en el original y en la traducción.
	 * @param Status|null     $status   Estado, o null para todos.
	 * @param StringType|null $type     Tipo de cadena, o null para todos.
	 * @param int             $page     Página, desde 1.
	 * @param int             $per_page Cadenas por página.
	 * @param bool            $ascending Orden por fecha de última aparición.
	 */
	public function __construct(
		public readonly string $language,
		public readonly string $search = '',
		public readonly ?Status $status = null,
		public readonly ?StringType $type = null,
		public readonly int $page = 1,
		public readonly int $per_page = 50,
		public readonly bool $ascending = false
	) {}

	/**
	 * Desplazamiento de la consulta.
	 */
	public function offset(): int {
		return max( 0, ( max( 1, $this->page ) - 1 ) * $this->limit() );
	}

	/**
	 * Tamaño de página, acotado.
	 *
	 * El tope no es decorativo: el gestor devuelve el texto original y la
	 * traducción de cada fila, y una página de mil cadenas largas se sale de la
	 * memoria de PHP antes de llegar al navegador.
	 */
	public function limit(): int {
		return max( 1, min( 200, $this->per_page ) );
	}
}
