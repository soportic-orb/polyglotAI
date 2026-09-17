<?php
/**
 * Contenido condicionado al idioma.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Content;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\RequestContext;

/**
 * Shortcodes para mostrar u ocultar contenido según el idioma.
 *
 * ```
 * [pgai_if lang="en,ca"]Solo en inglés y catalán[/pgai_if]
 * [pgai_unless lang="es"]En todos menos en español[/pgai_unless]
 * ```
 *
 * `pgai_unless` no es azúcar: el analizador de shortcodes de WordPress no sabe
 * anidar dos shortcodes con el mismo nombre —su expresión regular cierra en el
 * primer `[/...]` que encuentra—, así que tener la forma negativa con otro
 * nombre es lo que permite anidar una condición dentro de otra.
 *
 * Lo que no se muestra **no llega a la salida**, así que tampoco entra en el
 * barrido: no se registra como cadena, no se encola y no se paga por traducirlo.
 * Ocultarlo con CSS habría hecho justo lo contrario.
 *
 * Por defecto el contenido se traduce como cualquier otro, porque está escrito
 * en el idioma del sitio. Con `translate="no"` se envuelve en un contenedor que
 * el barrido salta, para el caso en que el texto ya esté escrito en el idioma
 * al que se condiciona —un aviso legal que solo aplica al mercado británico y
 * que ya está en inglés— y traducirlo desde el español sería traducirlo dos
 * veces.
 */
final class Conditional {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param RequestContext   $request   Contexto de la petición.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly RequestContext $request
	) {}

	/**
	 * Registra los shortcodes.
	 */
	public function register(): void {
		add_shortcode( 'pgai_if', array( $this, 'conditional' ) );
		add_shortcode( 'pgai_unless', array( $this, 'unless' ) );
		add_shortcode( 'pgai_language', array( $this, 'language' ) );
	}

	/**
	 * Muestra el contenido solo si el idioma encaja.
	 *
	 * @param array<string, string>|string $attributes Atributos.
	 * @param string|null                  $content    Contenido interior.
	 * @return string
	 */
	public function conditional( array|string $attributes = array(), ?string $content = null ): string {
		$attributes = shortcode_atts(
			array(
				'lang'      => '',
				'not'       => '',
				'translate' => 'yes',
			),
			is_array( $attributes ) ? $attributes : array(),
			'pgai_if'
		);

		if ( null === $content || '' === $content ) {
			return '';
		}

		if ( ! $this->matches( (string) $attributes['lang'], (string) $attributes['not'] ) ) {
			return '';
		}

		// El contenido puede llevar otros shortcodes dentro, incluido otro
		// pgai_if: se procesan aquí y no antes, para que los de una rama que no
		// se muestra no lleguen a ejecutarse.
		$rendered = do_shortcode( $content );

		if ( 'no' !== strtolower( (string) $attributes['translate'] ) ) {
			return $rendered;
		}

		return sprintf(
			'<div class="pgai-conditional notranslate" translate="no">%s</div>',
			$rendered
		);
	}

	/**
	 * Muestra el contenido salvo en los idiomas indicados.
	 *
	 * @param array<string, string>|string $attributes Atributos.
	 * @param string|null                  $content    Contenido interior.
	 * @return string
	 */
	public function unless( array|string $attributes = array(), ?string $content = null ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();

		// `lang` aquí significa «salvo en estos», que es como se lee el nombre
		// del shortcode; `not` se acepta también para quien venga de pgai_if.
		$excluded = trim( (string) ( $attributes['lang'] ?? '' ) . ',' . (string) ( $attributes['not'] ?? '' ), ',' );

		return $this->conditional(
			array(
				'lang'      => '',
				'not'       => $excluded,
				'translate' => (string) ( $attributes['translate'] ?? 'yes' ),
			),
			$content
		);
	}

	/**
	 * Escribe el idioma en curso.
	 *
	 * @param array<string, string>|string $attributes Atributos.
	 * @return string
	 */
	public function language( array|string $attributes = array() ): string {
		$attributes = shortcode_atts(
			array( 'display' => 'name' ),
			is_array( $attributes ) ? $attributes : array(),
			'pgai_language'
		);

		$language = $this->request->language();

		$value = match ( (string) $attributes['display'] ) {
			'code'   => strtoupper( $language->code() ),
			'locale' => $language->locale,
			'slug'   => $language->slug,
			default  => $language->label,
		};

		// El nombre de un idioma no se traduce: «English» es English en todas
		// partes, y traducirlo convertiría el selector en un galimatías.
		return sprintf(
			'<span class="pgai-language notranslate" translate="no">%s</span>',
			esc_html( $value )
		);
	}

	/**
	 * Si el idioma en curso encaja con las condiciones.
	 *
	 * @param string $allowed  Lista separada por comas de idiomas permitidos.
	 * @param string $excluded Lista separada por comas de idiomas excluidos.
	 */
	private function matches( string $allowed, string $excluded ): bool {
		$current = $this->request->language();

		if ( '' !== $excluded && $this->contains( $excluded, $current ) ) {
			return false;
		}

		// Sin lista de permitidos, vale cualquiera que no esté excluido.
		return '' === $allowed || $this->contains( $allowed, $current );
	}

	/**
	 * Si un idioma aparece en una lista escrita por el administrador.
	 *
	 * Se aceptan tanto el slug de la URL como el locale, porque quien escribe
	 * el shortcode no tiene por qué saber cuál de los dos esperamos.
	 *
	 * @param string   $entries  Lista separada por comas.
	 * @param Language $language Idioma en curso.
	 */
	private function contains( string $entries, Language $language ): bool {
		foreach ( explode( ',', $entries ) as $entry ) {
			$entry = strtolower( trim( $entry ) );

			if ( '' === $entry ) {
				continue;
			}

			if ( strtolower( $language->slug ) === $entry || strtolower( $language->locale ) === $entry ) {
				return true;
			}

			// «en» también encaja con en_US: un administrador que escribe el
			// código de idioma no está pensando en variantes regionales.
			if ( $language->code() === $entry && ! $this->is_slug_of_another( $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Si un código coincide con el slug de otro idioma distinto.
	 *
	 * Con `es` y `es-mx` configurados a la vez, escribir «es» tiene que
	 * referirse solo al primero: si el código base valiera para los dos, no
	 * habría forma de decir «solo en el español de España».
	 *
	 * @param string $code Código escrito en el shortcode.
	 */
	private function is_slug_of_another( string $code ): bool {
		foreach ( $this->languages->all() as $language ) {
			if ( strtolower( $language->slug ) === $code ) {
				return true;
			}
		}

		return false;
	}
}
