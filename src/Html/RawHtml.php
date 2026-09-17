<?php
/**
 * Sustitución que ya es HTML y no debe escaparse.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

/**
 * Envoltorio explícito para una sustitución que ya viene lista.
 *
 * Existe para que saltarse el escapado sea una decisión visible en el código y
 * no un efecto secundario: quien devuelve esto está afirmando que el contenido
 * ya es HTML seguro. Lo usa el editor visual, que envuelve cada cadena en un
 * elemento con sus marcas para que el panel pueda localizarla.
 */
final class RawHtml {

	/**
	 * Constructor.
	 *
	 * @param string $html HTML listo para insertar.
	 */
	public function __construct( public readonly string $html ) {}
}
