<?php
/**
 * Clasificación de elementos HTML.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

/**
 * Tablas de elementos que necesita el barrido del documento.
 *
 * Los nombres van en mayúsculas porque es lo que devuelve
 * WP_HTML_Tag_Processor::get_tag().
 */
final class Elements {

	/**
	 * Elementos vacíos: no tienen etiqueta de cierre y no se apilan.
	 *
	 * @var string[]
	 */
	private const VOID = array( 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR' );

	/**
	 * Elementos que WP_HTML_Tag_Processor entrega como un único token, con su
	 * contenido y su etiqueta de cierre dentro del propio token.
	 *
	 * Verificado contra WP 6.6.2 (el switch de parse_next_tag que llama a
	 * skip_script_data, skip_rcdata y skip_rawtext). Apilar uno de estos
	 * descuadraría la pila, porque su cierre nunca llega como token propio.
	 *
	 * @var string[]
	 */
	private const SELF_CONTAINED = array( 'SCRIPT', 'STYLE', 'TITLE', 'TEXTAREA', 'IFRAME', 'NOEMBED', 'NOFRAMES', 'XMP' );

	/**
	 * Elementos de bloque. Interrumpen una unidad de traducción: el texto a un
	 * lado y al otro son cadenas distintas.
	 *
	 * @var string[]
	 */
	private const BLOCK_LEVEL = array(
		'ADDRESS',
		'ARTICLE',
		'ASIDE',
		'BLOCKQUOTE',
		'BODY',
		'CAPTION',
		'DD',
		'DETAILS',
		'DIALOG',
		'DIV',
		'DL',
		'DT',
		'FIELDSET',
		'FIGCAPTION',
		'FIGURE',
		'FOOTER',
		'FORM',
		'H1',
		'H2',
		'H3',
		'H4',
		'H5',
		'H6',
		'HEAD',
		'HEADER',
		'HGROUP',
		'HR',
		'HTML',
		'LI',
		'MAIN',
		'NAV',
		'OL',
		'OPTION',
		'P',
		'PRE',
		'SECTION',
		'SUMMARY',
		'TABLE',
		'TBODY',
		'TD',
		'TFOOT',
		'TH',
		'THEAD',
		'TR',
		'UL',
	);

	/**
	 * Elementos que cortan una unidad de traducción aunque no sean de bloque.
	 *
	 * Un control de formulario no forma parte de la frase que lo rodea: en
	 * <form><label>Cantidad</label><input placeholder="Nota"></form> hay dos
	 * cadenas independientes, no una. Sin esta lista el formulario entero se
	 * convertiría en una sola unidad y los atributos de sus controles quedarían
	 * absorbidos por ella.
	 *
	 * @var string[]
	 */
	private const BREAKS_RUN = array( 'AUDIO', 'BUTTON', 'EMBED', 'INPUT', 'LABEL', 'LEGEND', 'OPTGROUP', 'SELECT', 'VIDEO' );

	/**
	 * Elementos cuyo contenido no se traduce nunca.
	 *
	 * SCRIPT y STYLE no harían falta aquí, porque el tokenizador no desciende a
	 * su interior, pero se incluyen para que la regla siga siendo explícita si
	 * algún día cambia el driver.
	 *
	 * @var string[]
	 */
	private const NEVER_TRANSLATE = array( 'CANVAS', 'CODE', 'KBD', 'MATH', 'OBJECT', 'PRE', 'SAMP', 'SCRIPT', 'STYLE', 'SVG', 'TEMPLATE', 'VAR' );

	/**
	 * Si el elemento es vacío.
	 *
	 * @param string $tag Nombre de etiqueta en mayúsculas.
	 */
	public static function is_void( string $tag ): bool {
		return in_array( $tag, self::VOID, true );
	}

	/**
	 * Si el tokenizador entrega el elemento completo en un solo token.
	 *
	 * @param string $tag Nombre de etiqueta en mayúsculas.
	 */
	public static function is_self_contained( string $tag ): bool {
		return in_array( $tag, self::SELF_CONTAINED, true );
	}

	/**
	 * Si el elemento corta la unidad de traducción en curso.
	 *
	 * @param string $tag Nombre de etiqueta en mayúsculas.
	 */
	public static function breaks_run( string $tag ): bool {
		return in_array( $tag, self::BLOCK_LEVEL, true ) || in_array( $tag, self::BREAKS_RUN, true );
	}

	/**
	 * Si el contenido del elemento debe quedar fuera de la traducción.
	 *
	 * @param string $tag Nombre de etiqueta en mayúsculas.
	 */
	public static function never_translate( string $tag ): bool {
		return in_array( $tag, self::NEVER_TRANSLATE, true );
	}
}
