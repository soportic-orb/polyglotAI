<?php
/**
 * Driver de extracción basado en la HTML API del núcleo de WordPress.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

use PolyglotAI\Translation\StringType;

/**
 * Recorre el documento con WP_HTML_Tag_Processor y devuelve las unidades de
 * traducción con su posición exacta en bytes.
 *
 * Es el driver primario (ADR-01). Trabaja en una única pasada lineal y mantiene
 * su propia pila de elementos: WP_HTML_Processor, que sí construye un árbol, en
 * WordPress 6.6 solo analiza fragmentos de <body> y no sirve para una página
 * completa con doctype y <head>.
 *
 * El tokenizador entrega SCRIPT, STYLE y demás elementos de texto en crudo como
 * un único token y nunca desciende a su interior, de modo que su contenido
 * queda intacto por construcción, no por una comprobación posterior.
 */
final class HtmlApiDriver implements DocumentDriverInterface {

	/** Bytes que cuentan como espacio en blanco al recortar una unidad. */
	private const TRIM_BYTES = " \t\r\n\f";

	/**
	 * Atributos que ALGUNA etiqueta podría tener traducibles.
	 *
	 * Es un filtro previo barato. Si una etiqueta no lleva ninguno de estos, no
	 * se construye nada ni se vuelven a recorrer sus bytes: en una página real
	 * eso es la inmensa mayoría de las etiquetas.
	 *
	 * @var array<string, true>
	 */
	private const CANDIDATE_ATTRIBUTES = array(
		'alt'                  => true,
		'aria-label'           => true,
		'aria-placeholder'     => true,
		'aria-roledescription' => true,
		'content'              => true,
		'placeholder'          => true,
		'src'                  => true,
		'srcset'               => true,
		'title'                => true,
		'value'                => true,
	);

	/**
	 * Constructor.
	 *
	 * @param TagScanner     $scanner    Analizador de posiciones dentro de una etiqueta.
	 * @param ExclusionRules $exclusions Reglas de exclusión de contenido.
	 */
	public function __construct(
		private readonly TagScanner $scanner,
		private readonly ExclusionRules $exclusions
	) {}

	/**
	 * Identificador del driver.
	 */
	public function name(): string {
		return 'wp-html-api';
	}

	/**
	 * Extrae las unidades de traducción del documento.
	 *
	 * @param string $html Documento HTML completo.
	 * @return ExtractedString[]
	 */
	public function extract( string $html ): array {
		$processor = new OffsetTagProcessor( $html );
		$units     = array();
		$stack     = array( $this->frame( '#root', false, false, 0 ) );

		while ( $processor->next_token() ) {
			$span = $processor->token_span();

			if ( null === $span ) {
				continue;
			}

			list( $start, $length ) = $span;
			$token_type             = $processor->get_token_type();

			if ( '#text' === $token_type ) {
				$this->consume_text( $stack, (string) $processor->get_modifiable_text(), $start, $length );
				continue;
			}

			if ( '#tag' !== $token_type ) {
				// Comentarios, doctype y CDATA cortan la unidad en curso: así
				// no quedan dentro de una cadena y no pueden perderse.
				$this->flush_owner( $stack, $html, $units );
				continue;
			}

			$tag = (string) $processor->get_tag();

			if ( $processor->is_tag_closer() ) {
				$this->close_tag( $stack, $html, $units, $tag, $start, $length );
				continue;
			}

			$this->open_tag( $stack, $processor, $html, $units, $tag, $start, $length );
		}

		while ( array() !== $stack ) {
			$frame = array_pop( $stack );

			if ( false === $frame['suppressed'] ) {
				$this->flush_frame( $frame, $html, $units );
			}
		}

		usort(
			$units,
			static fn( ExtractedString $a, ExtractedString $b ): int => $a->start <=> $b->start
		);

		return $this->drop_contained( $units );
	}

	/**
	 * Procesa una etiqueta de apertura.
	 *
	 * @param array<int, array<string, mixed>> $stack     Pila de elementos abiertos.
	 * @param OffsetTagProcessor               $processor Analizador.
	 * @param string                           $html      Documento completo.
	 * @param ExtractedString[]                $units     Unidades acumuladas.
	 * @param string                           $tag       Nombre de etiqueta en mayúsculas.
	 * @param int                              $start     Inicio del token.
	 * @param int                              $length    Longitud del token.
	 */
	private function open_tag( array &$stack, OffsetTagProcessor $processor, string $html, array &$units, string $tag, int $start, int $length ): void {
		$parent_excluded = (bool) $stack[ array_key_last( $stack ) ]['excluded'];

		// Los nombres de los atributos se leen UNA vez por etiqueta y se
		// reutilizan: tanto la comprobación de exclusión como la extracción de
		// atributos los necesitan, y consultarlos dos veces era medible.
		$present  = $this->attribute_names( $processor );
		$excluded = $parent_excluded
			|| Elements::never_translate( $tag )
			|| $this->exclusions->excludes( $processor, $present );

		// Elementos que llegan completos en un token: no se apilan nunca, porque
		// su etiqueta de cierre no llega como token independiente.
		if ( Elements::is_self_contained( $tag ) ) {
			$this->flush_owner( $stack, $html, $units );

			if ( ! $excluded ) {
				$this->emit_rcdata( $processor, $units, $html, $tag, $start, $length );
				$this->emit_attributes( $processor, $units, $html, $tag, $start, $length, $present );
			}

			return;
		}

		if ( $excluded ) {
			// Cortar la unidad en curso para que el contenido excluido no quede
			// dentro de ella.
			$this->flush_owner( $stack, $html, $units );

			if ( ! Elements::is_void( $tag ) && ! $processor->has_self_closing_flag() ) {
				$stack[] = $this->frame( $tag, true, false, $start );
			}

			return;
		}

		$this->emit_attributes( $processor, $units, $html, $tag, $start, $length, $present );

		$breaks = Elements::breaks_run( $tag );

		if ( $breaks ) {
			$this->flush_owner( $stack, $html, $units );
		} else {
			// Un elemento en línea forma parte de la unidad de su contenedor.
			$this->extend_run( $stack, $start, $length, true, null );
		}

		if ( Elements::is_void( $tag ) || $processor->has_self_closing_flag() ) {
			return;
		}

		$stack[] = $this->frame( $tag, false, ! $breaks, $start );
	}

	/**
	 * Procesa una etiqueta de cierre.
	 *
	 * @param array<int, array<string, mixed>> $stack  Pila de elementos abiertos.
	 * @param string                           $html   Documento completo.
	 * @param ExtractedString[]                $units  Unidades acumuladas.
	 * @param string                           $tag    Nombre de etiqueta en mayúsculas.
	 * @param int                              $start  Inicio del token.
	 * @param int                              $length Longitud del token.
	 */
	private function close_tag( array &$stack, string $html, array &$units, string $tag, int $start, int $length ): void {
		$index = null;

		for ( $i = array_key_last( $stack ); $i > 0; $i-- ) {
			if ( $stack[ $i ]['tag'] === $tag ) {
				$index = $i;
				break;
			}
		}

		if ( null === $index ) {
			// Cierre huérfano. Si es en línea se considera parte de la unidad en
			// curso; si es de bloque, la corta.
			if ( Elements::breaks_run( $tag ) ) {
				$this->flush_owner( $stack, $html, $units );
			} else {
				$this->extend_run( $stack, $start, $length, true, null );
			}

			return;
		}

		$was_suppressed = (bool) $stack[ $index ]['suppressed'];

		// Cerrar también lo que haya quedado abierto por encima (marcado mal anidado).
		$element_end = $start + $length;

		while ( array_key_last( $stack ) >= $index ) {
			$closing = array_key_last( $stack ) === $index;
			$frame   = array_pop( $stack );

			if ( false === $frame['suppressed'] ) {
				// El final del elemento solo se conoce para el que de verdad se
				// cierra; los que quedaban abiertos por encima son marcado mal
				// anidado y no tienen un cierre propio.
				$this->flush_frame( $frame, $html, $units, $closing ? $element_end : null );
			}
		}

		if ( $was_suppressed ) {
			$this->extend_run( $stack, $start, $length, true, null );
		}
	}

	/**
	 * Incorpora un nodo de texto a la unidad en curso.
	 *
	 * @param array<int, array<string, mixed>> $stack  Pila de elementos abiertos.
	 * @param string                           $text   Texto ya decodificado.
	 * @param int                              $start  Inicio del token.
	 * @param int                              $length Longitud del token.
	 */
	private function consume_text( array &$stack, string $text, int $start, int $length ): void {
		$index = $this->owner_index( $stack );

		if ( true === $stack[ $index ]['excluded'] ) {
			return;
		}

		$is_blank = $this->is_blank( $text );

		// El espacio en blanco no abre una unidad, pero sí la continúa.
		if ( $is_blank && null === $stack[ $index ]['run_start'] ) {
			return;
		}

		$this->extend_run( $stack, $start, $length, false, $text, ! $is_blank );
	}

	/**
	 * Amplía la unidad en curso del elemento propietario.
	 *
	 * @param array<int, array<string, mixed>> $stack    Pila de elementos abiertos.
	 * @param int                              $start    Inicio del token.
	 * @param int                              $length   Longitud del token.
	 * @param bool                             $has_tags Si el token aporta marcado.
	 * @param string|null                      $text     Texto decodificado, si lo hay.
	 * @param bool                             $has_text Si el texto es traducible.
	 */
	private function extend_run( array &$stack, int $start, int $length, bool $has_tags, ?string $text, bool $has_text = false ): void {
		$index = $this->owner_index( $stack );

		if ( true === $stack[ $index ]['excluded'] ) {
			return;
		}

		if ( null === $stack[ $index ]['run_start'] ) {
			$stack[ $index ]['run_start'] = $start;
		}

		$stack[ $index ]['run_end'] = $start + $length;

		if ( $has_tags ) {
			$stack[ $index ]['run_has_tags'] = true;
		}

		if ( null !== $text ) {
			$stack[ $index ]['run_text'] .= $text;
		}

		if ( $has_text ) {
			$stack[ $index ]['run_has_text'] = true;
		}
	}

	/**
	 * Cierra la unidad en curso del elemento propietario sin cerrar el elemento.
	 *
	 * @param array<int, array<string, mixed>> $stack Pila de elementos abiertos.
	 * @param string                           $html  Documento completo.
	 * @param ExtractedString[]                $units Unidades acumuladas.
	 */
	private function flush_owner( array &$stack, string $html, array &$units ): void {
		$index = $this->owner_index( $stack );

		$this->flush_frame( $stack[ $index ], $html, $units );

		$stack[ $index ]['run_start']    = null;
		$stack[ $index ]['run_end']      = null;
		$stack[ $index ]['run_text']     = '';
		$stack[ $index ]['run_has_tags'] = false;
		$stack[ $index ]['run_has_text'] = false;
	}

	/**
	 * Emite la unidad acumulada en un elemento, si contiene texto traducible.
	 *
	 * @param array<string, mixed> $frame       Elemento.
	 * @param string               $html        Documento completo.
	 * @param ExtractedString[]    $units       Unidades acumuladas.
	 * @param int|null             $element_end Fin del elemento, si se conoce.
	 *                                          Solo se sabe al cerrarlo.
	 */
	private function flush_frame( array $frame, string $html, array &$units, ?int $element_end = null ): void {
		if ( null === $frame['run_start'] || false === $frame['run_has_text'] ) {
			return;
		}

		$start = (int) $frame['run_start'];
		$end   = (int) $frame['run_end'];

		// El espacio en blanco de los extremos se deja fuera de la unidad: no
		// hay que traducirlo y conservarlo evita saltos de maquetación con
		// elementos en línea.
		while ( $start < $end && false !== strpbrk( $html[ $start ], self::TRIM_BYTES ) ) {
			++$start;
		}

		while ( $end > $start && false !== strpbrk( $html[ $end - 1 ], self::TRIM_BYTES ) ) {
			--$end;
		}

		if ( $end <= $start ) {
			return;
		}

		$outer_start  = null;
		$outer_length = null;

		if ( null !== $element_end && '#root' !== $frame['tag'] ) {
			$outer_start  = (int) $frame['open_start'];
			$outer_length = $element_end - $outer_start;
		}

		$units[] = new ExtractedString(
			true === $frame['run_has_tags'] ? StringType::Block : StringType::Text,
			true === $frame['run_has_tags']
				? substr( $html, $start, $end - $start )
				: trim( (string) $frame['run_text'] ),
			$start,
			$end - $start,
			null,
			null,
			$outer_start,
			$outer_length
		);
	}

	/**
	 * Emite el texto interior de un elemento RCDATA traducible (TITLE, TEXTAREA).
	 *
	 * @param OffsetTagProcessor $processor Analizador.
	 * @param ExtractedString[]  $units     Unidades acumuladas.
	 * @param string             $html      Documento completo.
	 * @param string             $tag       Nombre de etiqueta en mayúsculas.
	 * @param int                $start     Inicio del token.
	 * @param int                $length    Longitud del token.
	 */
	private function emit_rcdata( OffsetTagProcessor $processor, array &$units, string $html, string $tag, int $start, int $length ): void {
		if ( 'TEXTAREA' === $tag ) {
			/**
			 * Si se traduce el contenido de los TEXTAREA.
			 *
			 * Viene desactivado porque lo que hay dentro de un TEXTAREA suele
			 * ser lo que ha escrito el visitante, no texto del tema: un
			 * comentario que vuelve tras un error de validación, las notas de
			 * un pedido, el mensaje de un formulario de contacto. Traducirlo
			 * significaría guardarlo y mandarlo a la API, que es justo lo que
			 * prohíbe el ADR-12. El texto que sí es de la interfaz va en
			 * `placeholder`, que sí se traduce.
			 *
			 * @since 0.1.0
			 *
			 * @param bool $translate Si se traduce.
			 */
			if ( ! (bool) apply_filters( 'pgai_translate_textarea', false ) ) {
				return;
			}
		} elseif ( 'TITLE' !== $tag ) {
			return;
		}

		$value = (string) $processor->get_modifiable_text();

		if ( $this->is_blank( $value ) ) {
			return;
		}

		$inner = $this->scanner->inner_span( substr( $html, $start, $length ), $tag );

		if ( null === $inner ) {
			return;
		}

		$units[] = new ExtractedString(
			StringType::Rcdata,
			trim( $value ),
			$start + $inner[0],
			$inner[1],
			strtolower( $tag )
		);
	}

	/**
	 * Emite los atributos traducibles de una etiqueta.
	 *
	 * @param OffsetTagProcessor  $processor Analizador.
	 * @param ExtractedString[]   $units     Unidades acumuladas.
	 * @param string              $html      Documento completo.
	 * @param string              $tag       Nombre de etiqueta en mayúsculas.
	 * @param int                 $start     Inicio del token.
	 * @param int                 $length    Longitud del token.
	 * @param array<string, true> $present   Atributos presentes, en minúsculas.
	 */
	private function emit_attributes( OffsetTagProcessor $processor, array &$units, string $html, string $tag, int $start, int $length, array $present ): void {
		$candidates = array_intersect_key( $present, self::CANDIDATE_ATTRIBUTES );

		if ( array() === $candidates ) {
			return;
		}

		$candidates = array_keys( $candidates );

		$spans = null;

		foreach ( $candidates as $name ) {
			$meta = $this->attribute_meta( $processor, $tag, $name );

			if ( null === $meta ) {
				continue;
			}

			$value = $processor->get_attribute( $name );

			if ( ! is_string( $value ) || $this->is_blank( $value ) ) {
				continue;
			}

			if ( null === $spans ) {
				$spans = $this->scanner->attribute_spans( substr( $html, $start, $length ) );
			}

			if ( ! isset( $spans[ $name ] ) ) {
				continue;
			}

			$units[] = new ExtractedString(
				$meta[0],
				$value,
				$start + $spans[ $name ][0],
				$spans[ $name ][1],
				$meta[1],
				$name
			);
		}
	}

	/**
	 * Tipo y contexto de un atributo concreto, o null si en esta etiqueta no es
	 * traducible.
	 *
	 * @param OffsetTagProcessor $processor Analizador.
	 * @param string             $tag       Nombre de etiqueta en mayúsculas.
	 * @param string             $name      Nombre del atributo en minúsculas.
	 * @return array{0:StringType, 1:string|null}|null
	 */
	private function attribute_meta( OffsetTagProcessor $processor, string $tag, string $name ): ?array {
		if ( 'META' === $tag ) {
			return 'content' === $name ? $this->meta_content( $processor ) : null;
		}

		switch ( $name ) {
			case 'title':
			case 'aria-label':
			case 'aria-placeholder':
			case 'aria-roledescription':
				return array( StringType::Attribute, $name );

			case 'alt':
				return in_array( $tag, array( 'IMG', 'AREA', 'INPUT' ), true )
					? array( StringType::Attribute, 'alt' )
					: null;

			case 'placeholder':
				return in_array( $tag, array( 'INPUT', 'TEXTAREA' ), true )
					? array( StringType::Attribute, 'placeholder' )
					: null;

			case 'src':
				// Solo las imágenes: el src de un script o un iframe no se
				// cambia por idioma.
				return 'IMG' === $tag ? array( StringType::Image, 'src' ) : null;

			case 'srcset':
				// El srcset se enlaza con su imagen por el contexto: así el
				// editor sabe que, al cambiar la imagen, tiene que cambiar
				// también sus variantes responsive. Sin esto, la imagen
				// traducida solo se vería en algunos tamaños de pantalla.
				if ( 'IMG' !== $tag ) {
					return null;
				}

				$source = $processor->get_attribute( 'src' );

				return array( StringType::Image, is_string( $source ) ? 'srcset:' . $source : 'srcset' );

			case 'value':
				// El value de un input solo es texto visible en los botones. En
				// el resto es un dato que no debe tocarse.
				if ( 'INPUT' !== $tag ) {
					return null;
				}

				$type = $processor->get_attribute( 'type' );

				return is_string( $type ) && in_array( strtolower( $type ), array( 'submit', 'button', 'reset' ), true )
					? array( StringType::Attribute, 'button' )
					: null;
		}

		return null;
	}

	/**
	 * Tipo y contexto del atributo content de una etiqueta META, si es
	 * traducible.
	 *
	 * @param OffsetTagProcessor $processor Analizador.
	 * @return array{0:StringType, 1:string|null}|null
	 */
	private function meta_content( OffsetTagProcessor $processor ): ?array {
		$name     = $processor->get_attribute( 'name' );
		$property = $processor->get_attribute( 'property' );
		$key      = is_string( $name ) ? strtolower( $name ) : ( is_string( $property ) ? strtolower( $property ) : '' );

		if ( '' === $key ) {
			return null;
		}

		$translatable = 'description' === $key
			|| str_starts_with( $key, 'og:title' )
			|| str_starts_with( $key, 'og:description' )
			|| str_starts_with( $key, 'og:site_name' )
			|| str_starts_with( $key, 'og:image:alt' )
			|| str_starts_with( $key, 'twitter:title' )
			|| str_starts_with( $key, 'twitter:description' )
			|| str_starts_with( $key, 'twitter:image:alt' );

		if ( ! $translatable ) {
			return null;
		}

		return array( StringType::Meta, $key );
	}

	/**
	 * Si un texto no contiene nada visible.
	 *
	 * No vale trim(): opera sobre bytes y no reconoce el espacio duro, de modo
	 * que un <p>&nbsp;</p> se colaría como cadena traducible. La HTML API ya ha
	 * decodificado &nbsp; a U+00A0 cuando el texto llega hasta aquí.
	 *
	 * @param string $text Texto decodificado.
	 */
	private function is_blank( string $text ): bool {
		if ( '' === $text ) {
			return true;
		}

		// El caso abrumadoramente mayoritario se resuelve con trim, que opera
		// sobre bytes. Solo si queda algo se pagan las sustituciones de los
		// espacios multibyte, que trim no reconoce.
		$trimmed = trim( $text, " \t\r\n\f\v\0" );

		if ( '' === $trimmed ) {
			return true;
		}

		// U+00A0 (espacio duro), U+200B (espacio de ancho cero) y U+FEFF.
		return '' === trim( str_replace( array( "\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF" ), '', $trimmed ) );
	}

	/**
	 * Nombres en minúsculas de los atributos de la etiqueta actual.
	 *
	 * @param OffsetTagProcessor $processor Analizador.
	 * @return array<string, true>
	 */
	private function attribute_names( OffsetTagProcessor $processor ): array {
		$names = $processor->get_attribute_names_with_prefix( '' );

		if ( null === $names || array() === $names ) {
			return array();
		}

		$present = array();

		foreach ( $names as $name ) {
			$present[ strtolower( $name ) ] = true;
		}

		return $present;
	}

	/**
	 * Índice del elemento que es propietario de la unidad en curso.
	 *
	 * Es el más interno que no esté marcado como absorbido: los elementos en
	 * línea ceden su contenido al contenedor para que <p>Hola <b>món</b></p> sea
	 * una sola cadena y no tres.
	 *
	 * @param array<int, array<string, mixed>> $stack Pila de elementos abiertos.
	 */
	private function owner_index( array $stack ): int {
		for ( $i = array_key_last( $stack ); $i > 0; $i-- ) {
			if ( false === $stack[ $i ]['suppressed'] ) {
				return $i;
			}
		}

		return 0;
	}

	/**
	 * Descarta las unidades contenidas dentro de una unidad de bloque.
	 *
	 * El alt de una imagen dentro de un párrafo ya viaja dentro del HTML del
	 * bloque; emitirlo además por separado produciría dos sustituciones
	 * solapadas sobre los mismos bytes.
	 *
	 * @param ExtractedString[] $units Unidades acumuladas.
	 * @return ExtractedString[]
	 */
	private function drop_contained( array $units ): array {
		// Las unidades llegan ordenadas y no se solapan entre sí, así que basta
		// un barrido recordando hasta dónde llega el último bloque: no hace
		// falta comparar cada unidad contra todos los bloques.
		$kept      = array();
		$block_end = -1;

		foreach ( $units as $unit ) {
			if ( StringType::Block === $unit->type ) {
				$kept[]    = $unit;
				$block_end = max( $block_end, $unit->end() );

				continue;
			}

			if ( $unit->end() <= $block_end ) {
				continue;
			}

			$kept[] = $unit;
		}

		return $kept;
	}

	/**
	 * Crea un elemento de la pila.
	 *
	 * @param string $tag        Nombre de etiqueta.
	 * @param bool   $excluded   Si su contenido queda fuera de la traducción.
	 * @param bool   $suppressed Si cede su contenido a la unidad del contenedor.
	 * @param int    $open_start Byte donde empieza su etiqueta de apertura.
	 * @return array<string, mixed>
	 */
	private function frame( string $tag, bool $excluded, bool $suppressed, int $open_start = 0 ): array {
		return array(
			'tag'          => $tag,
			'open_start'   => $open_start,
			'excluded'     => $excluded,
			'suppressed'   => $suppressed,
			'run_start'    => null,
			'run_end'      => null,
			'run_text'     => '',
			'run_has_tags' => false,
			'run_has_text' => false,
		);
	}
}
