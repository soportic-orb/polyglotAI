<?php
/**
 * Procesador de etiquetas que además expone la posición de cada token.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

use WP_HTML_Span;
use WP_HTML_Tag_Processor;

/**
 * Añade a WP_HTML_Tag_Processor lo único que le falta para nuestro caso:
 * saber en qué bytes está el token actual.
 *
 * El core no expone esa posición de forma directa ($token_starts_at es privado),
 * pero sí a través de los marcadores: set_bookmark() es público y guarda un
 * WP_HTML_Span con el inicio y la longitud del token actual en $bookmarks, que
 * es protected y por tanto accesible desde aquí.
 *
 * Es la única dependencia del plugin sobre una propiedad protegida del core.
 * Está aislada en esta clase a propósito, y DriverFactory comprueba en tiempo de
 * ejecución que el mecanismo sigue funcionando antes de elegir este driver; si
 * una versión futura de WordPress lo cambia, la comprobación falla y el
 * procesado se desactiva en vez de corromper páginas.
 */
final class OffsetTagProcessor extends WP_HTML_Tag_Processor {

	/** Nombre del marcador de trabajo. Se crea y se libera en cada consulta. */
	private const BOOKMARK = 'pgai_span';

	/**
	 * Posición del token actual.
	 *
	 * @return array{0:int, 1:int}|null [inicio, longitud] en bytes, o null si el
	 *                                  analizador no está sobre un token concreto.
	 */
	public function token_span(): ?array {
		if ( ! $this->set_bookmark( self::BOOKMARK ) ) {
			return null;
		}

		$span = $this->bookmarks[ '_' . self::BOOKMARK ] ?? $this->bookmarks[ self::BOOKMARK ] ?? null;

		$this->release_bookmark( self::BOOKMARK );

		if ( ! $span instanceof WP_HTML_Span ) {
			return null;
		}

		return array( (int) $span->start, (int) $span->length );
	}

	/**
	 * Comprueba que el mecanismo de posiciones funciona en esta instalación.
	 *
	 * Se usa como sonda de capacidad: si una versión de WordPress cambiara la
	 * visibilidad o la forma de $bookmarks, esto devuelve false y el plugin
	 * elige otro driver en vez de generar sustituciones en posiciones erróneas.
	 */
	public static function is_supported(): bool {
		if ( ! class_exists( WP_HTML_Tag_Processor::class ) ) {
			return false;
		}

		$probe = new self( '<b>x</b>' );

		if ( ! $probe->next_token() ) {
			return false;
		}

		$span = $probe->token_span();

		return array( 0, 3 ) === $span;
	}
}
