<?php
/**
 * Traducción de los datos estructurados JSON-LD.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Seo;

use PolyglotAI\Html\OffsetTagProcessor;
use PolyglotAI\Html\Replacement;
use PolyglotAI\Html\Splicer;
use PolyglotAI\Html\TagScanner;
use PolyglotAI\Languages\Language;
use PolyglotAI\Routing\InternalUrl;
use PolyglotAI\Translation\TextLookupInterface;

/**
 * Traduce el JSON-LD que emiten los plugins de SEO.
 *
 * El barrido de la salida no entra en los `<script>` —y hace bien: ahí dentro
 * hay código, y tocarlo es la forma más rápida de romper una página—. Pero el
 * JSON-LD no es código: es contenido, y lleva el nombre del sitio, el titular
 * del artículo, la descripción y las migas de pan. Si se queda sin traducir, un
 * buscador ve una ficha en español sobre una página en inglés.
 *
 * Por eso se trata aparte y **solo** los scripts declarados como
 * `application/ld+json`: se descodifica el JSON, se traducen las claves que se
 * sabe que llevan texto para personas, se reescriben las URLs internas y se
 * vuelve a codificar. Si el JSON no es válido o el resultado no se puede
 * codificar, el bloque se deja **exactamente** como estaba.
 *
 * La lista de claves es blanca a propósito. Traducir «todo lo que sea una
 * cadena» estropearía identificadores, fechas ISO, códigos de moneda, SKUs y
 * números de teléfono.
 */
final class StructuredData {

	/**
	 * Claves cuyo valor es texto escrito para personas.
	 *
	 * @var array<string, true>
	 */
	private const TEXT_KEYS = array(
		'name'                      => true,
		'headline'                  => true,
		'alternativeHeadline'       => true,
		'description'               => true,
		'alternateName'             => true,
		'caption'                   => true,
		'disambiguatingDescription' => true,
		'articleSection'            => true,
		'keywords'                  => true,
		'jobTitle'                  => true,
		'text'                      => true,
		'abstract'                  => true,
		'slogan'                    => true,
		'reviewBody'                => true,
		'recipeInstructions'        => true,
		'question'                  => true,
		'answerExplanation'         => true,
	);

	/**
	 * Claves cuyo valor es una URL de una página del sitio.
	 *
	 * @var array<string, true>
	 */
	private const URL_KEYS = array(
		'url'              => true,
		'@id'              => true,
		'item'             => true,
		'mainEntityOfPage' => true,
	);

	/**
	 * Constructor.
	 *
	 * @param TextLookupInterface $lookup  Búsqueda de traducciones.
	 * @param InternalUrl         $urls    Conversor de URLs internas.
	 * @param TagScanner          $scanner Analizador de etiquetas.
	 * @param Splicer             $splicer Aplicador de sustituciones.
	 */
	public function __construct(
		private readonly TextLookupInterface $lookup,
		private readonly InternalUrl $urls,
		private readonly TagScanner $scanner,
		private readonly Splicer $splicer
	) {}

	/**
	 * Traduce los bloques JSON-LD del documento.
	 *
	 * @param string   $html     Documento.
	 * @param Language $language Idioma de destino.
	 */
	public function rewrite( string $html, Language $language ): string {
		$blocks = $this->blocks( $html );

		if ( array() === $blocks ) {
			return $html;
		}

		// Primero se recogen los textos de todos los bloques y se traducen de
		// una vez: una página puede llevar varios JSON-LD y no vamos a
		// consultar la base de datos una vez por bloque.
		$texts = array();

		foreach ( $blocks as $block ) {
			$this->collect_texts( $block['data'], $texts );
		}

		$translations = $this->lookup->texts( array_values( array_unique( $texts ) ), $language->locale );
		$replacements = array();

		foreach ( $blocks as $block ) {
			$translated = $this->apply( $block['data'], $translations, $language );
			$encoded    = wp_json_encode( $translated, JSON_UNESCAPED_UNICODE );

			// Sin JSON_UNESCAPED_SLASHES a propósito: con las barras escapadas
			// es imposible que aparezca un </script> dentro del bloque.
			if ( ! is_string( $encoded ) || $encoded === $block['raw'] ) {
				continue;
			}

			$replacements[] = new Replacement( $block['start'], $block['length'], $encoded );
		}

		return array() === $replacements ? $html : $this->splicer->apply( $html, $replacements );
	}

	/**
	 * Bloques JSON-LD válidos del documento.
	 *
	 * @param string $html Documento.
	 * @return array<int, array{start:int, length:int, raw:string, data:mixed}>
	 */
	private function blocks( string $html ): array {
		$processor = new OffsetTagProcessor( $html );
		$blocks    = array();

		while ( $processor->next_token() ) {
			if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() || 'SCRIPT' !== $processor->get_tag() ) {
				continue;
			}

			$type = $processor->get_attribute( 'type' );

			if ( ! is_string( $type ) || 'application/ld+json' !== strtolower( trim( $type ) ) ) {
				continue;
			}

			$span = $processor->token_span();

			if ( null === $span ) {
				continue;
			}

			$inner = $this->scanner->inner_span( substr( $html, $span[0], $span[1] ), 'SCRIPT' );

			if ( null === $inner ) {
				continue;
			}

			$raw  = substr( $html, $span[0] + $inner[0], $inner[1] );
			$data = json_decode( $raw, true );

			// Un JSON que no se descodifica se deja tal cual: no se rompe una
			// página por un dato estructurado mal formado de otro plugin.
			if ( null === $data || ! is_array( $data ) ) {
				continue;
			}

			$blocks[] = array(
				'start'  => $span[0] + $inner[0],
				'length' => $inner[1],
				'raw'    => $raw,
				'data'   => $data,
			);
		}

		return $blocks;
	}

	/**
	 * Recoge recursivamente los textos traducibles de una estructura.
	 *
	 * @param mixed    $data  Estructura.
	 * @param string[] $texts Acumulador, por referencia.
	 */
	private function collect_texts( $data, array &$texts ): void {
		if ( ! is_array( $data ) ) {
			return;
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$this->collect_texts( $value, $texts );

				continue;
			}

			if ( is_string( $value ) && isset( self::TEXT_KEYS[ (string) $key ] ) && '' !== trim( $value ) ) {
				$texts[] = $value;
			}
		}
	}

	/**
	 * Devuelve la estructura con los textos traducidos y las URLs convertidas.
	 *
	 * @param mixed                 $data         Estructura.
	 * @param array<string, string> $translations Traducciones.
	 * @param Language              $language     Idioma de destino.
	 * @return mixed
	 */
	private function apply( $data, array $translations, Language $language ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->apply( $value, $translations, $language );

				continue;
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			$name = (string) $key;

			if ( isset( self::TEXT_KEYS[ $name ] ) && isset( $translations[ $value ] ) ) {
				$data[ $key ] = $translations[ $value ];

				continue;
			}

			if ( isset( self::URL_KEYS[ $name ] ) ) {
				$converted = $this->urls->convert( $value, $language );

				if ( null !== $converted ) {
					$data[ $key ] = $converted;
				}
			}
		}

		return $data;
	}
}
