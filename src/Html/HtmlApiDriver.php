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
		$stack     = array( $this->frame( '#root', false, false ) );

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
		$excluded        = $parent_excluded || Elements::never_translate( $tag ) || $this->exclusions->excludes( $processor );

		// Elementos que llegan completos en un token: no se apilan nunca, porque
		// su etiqueta de cierre no llega como token independiente.
		if ( Elements::is_self_contained( $tag ) ) {
			$this->flush_owner( $stack, $html, $units );

			if ( ! $excluded ) {
				$this->emit_rcdata( $processor, $units, $html, $tag, $start, $length );
				$this->emit_attributes( $processor, $units, $html, $tag, $start, $length );
			}

			return;
		}

		if ( $excluded ) {
			// Cortar la unidad en curso para que el contenido excluido no quede
			// dentro de ella.
			$this->flush_owner( $stack, $html, $units );

			if ( ! Elements::is_void( $tag ) && ! $processor->has_self_closing_flag() ) {
				$stack[] = $this->frame( $tag, true, false );
			}

			return;
		}

		$this->emit_attributes( $processor, $units, $html, $tag, $start, $length );

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

		$stack[] = $this->frame( $tag, false, ! $breaks );
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
		while ( array_key_last( $stack ) >= $index ) {
			$frame = array_pop( $stack );

			if ( false === $frame['suppressed'] ) {
				$this->flush_frame( $frame, $html, $units );
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
	 * @param array<string, mixed> $frame Elemento.
	 * @param string               $html  Documento completo.
	 * @param ExtractedString[]    $units Unidades acumuladas.
	 */
	private function flush_frame( array $frame, string $html, array &$units ): void {
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

		if ( true === $frame['run_has_tags'] ) {
			$units[] = new ExtractedString(
				StringType::Block,
				substr( $html, $start, $end - $start ),
				$start,
				$end - $start
			);

			return;
		}

		$units[] = new ExtractedString(
			StringType::Text,
			trim( (string) $frame['run_text'] ),
			$start,
			$end - $start
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
		if ( 'TITLE' !== $tag && 'TEXTAREA' !== $tag ) {
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
	 * @param OffsetTagProcessor $processor Analizador.
	 * @param ExtractedString[]  $units     Unidades acumuladas.
	 * @param string             $html      Documento completo.
	 * @param string             $tag       Nombre de etiqueta en mayúsculas.
	 * @param int                $start     Inicio del token.
	 * @param int                $length    Longitud del token.
	 */
	private function emit_attributes( OffsetTagProcessor $processor, array &$units, string $html, string $tag, int $start, int $length ): void {
		$wanted = $this->translatable_attributes( $processor, $tag );

		if ( array() === $wanted ) {
			return;
		}

		$spans = $this->scanner->attribute_spans( substr( $html, $start, $length ) );

		foreach ( $wanted as $name => $meta ) {
			if ( ! isset( $spans[ $name ] ) ) {
				continue;
			}

			$value = $processor->get_attribute( $name );

			if ( ! is_string( $value ) || $this->is_blank( $value ) ) {
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
	 * Atributos traducibles de una etiqueta concreta.
	 *
	 * @param OffsetTagProcessor $processor Analizador.
	 * @param string             $tag       Nombre de etiqueta en mayúsculas.
	 * @return array<string, array{0:StringType, 1:string|null}> Nombre => [tipo, contexto].
	 */
	private function translatable_attributes( OffsetTagProcessor $processor, string $tag ): array {
		if ( 'META' === $tag ) {
			return $this->meta_attribute( $processor );
		}

		$wanted = array(
			'title'                => array( StringType::Attribute, 'title' ),
			'aria-label'           => array( StringType::Attribute, 'aria-label' ),
			'aria-placeholder'     => array( StringType::Attribute, 'aria-placeholder' ),
			'aria-roledescription' => array( StringType::Attribute, 'aria-roledescription' ),
		);

		if ( in_array( $tag, array( 'IMG', 'AREA', 'INPUT' ), true ) ) {
			$wanted['alt'] = array( StringType::Attribute, 'alt' );
		}

		if ( in_array( $tag, array( 'INPUT', 'TEXTAREA' ), true ) ) {
			$wanted['placeholder'] = array( StringType::Attribute, 'placeholder' );
		}

		// El value de un input solo es texto visible en los botones. En el resto
		// es un dato que no debe tocarse.
		if ( 'INPUT' === $tag ) {
			$type = $processor->get_attribute( 'type' );

			if ( is_string( $type ) && in_array( strtolower( $type ), array( 'submit', 'button', 'reset' ), true ) ) {
				$wanted['value'] = array( StringType::Attribute, 'button' );
			}
		}

		return $wanted;
	}

	/**
	 * Atributo traducible de una etiqueta META, si lo tiene.
	 *
	 * @param OffsetTagProcessor $processor Analizador.
	 * @return array<string, array{0:StringType, 1:string|null}>
	 */
	private function meta_attribute( OffsetTagProcessor $processor ): array {
		$name     = $processor->get_attribute( 'name' );
		$property = $processor->get_attribute( 'property' );
		$key      = is_string( $name ) ? strtolower( $name ) : ( is_string( $property ) ? strtolower( $property ) : '' );

		if ( '' === $key ) {
			return array();
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
			return array();
		}

		return array( 'content' => array( StringType::Meta, $key ) );
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
		return 1 === preg_match( '/^[\s\x{00A0}\x{200B}\x{FEFF}]*$/u', $text );
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
		$blocks = array();

		foreach ( $units as $unit ) {
			if ( StringType::Block === $unit->type ) {
				$blocks[] = $unit;
			}
		}

		if ( array() === $blocks ) {
			return array_values( $units );
		}

		$kept = array();

		foreach ( $units as $unit ) {
			$contained = false;

			foreach ( $blocks as $block ) {
				if ( $block === $unit ) {
					continue;
				}

				if ( $unit->start >= $block->start && $unit->end() <= $block->end() ) {
					$contained = true;
					break;
				}
			}

			if ( ! $contained ) {
				$kept[] = $unit;
			}
		}

		return $kept;
	}

	/**
	 * Crea un elemento de la pila.
	 *
	 * @param string $tag        Nombre de etiqueta.
	 * @param bool   $excluded   Si su contenido queda fuera de la traducción.
	 * @param bool   $suppressed Si cede su contenido a la unidad del contenedor.
	 * @return array<string, mixed>
	 */
	private function frame( string $tag, bool $excluded, bool $suppressed ): array {
		return array(
			'tag'          => $tag,
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
