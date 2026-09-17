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
 *
 * Están escritas como mapas con la etiqueta por clave, y no como listas, porque
 * se consultan varias veces por cada etiqueta del documento: con in_array cada
 * consulta recorría hasta cuarenta y cinco cadenas, y en una página real eso
 * suponía más tiempo que el propio analizador.
 */
final class Elements {

	/**
	 * Elementos vacíos: no tienen etiqueta de cierre y no se apilan.
	 *
	 * @var array<string, true>
	 */
	private const VOID = array(
		'AREA'   => true,
		'BASE'   => true,
		'BR'     => true,
		'COL'    => true,
		'EMBED'  => true,
		'HR'     => true,
		'IMG'    => true,
		'INPUT'  => true,
		'LINK'   => true,
		'META'   => true,
		'PARAM'  => true,
		'SOURCE' => true,
		'TRACK'  => true,
		'WBR'    => true,
	);

	/**
	 * Elementos que WP_HTML_Tag_Processor entrega como un único token, con su
	 * contenido y su etiqueta de cierre dentro del propio token.
	 *
	 * Verificado contra WP 6.6.2 (el switch de parse_next_tag que llama a
	 * skip_script_data, skip_rcdata y skip_rawtext). Apilar uno de estos
	 * descuadraría la pila, porque su cierre nunca llega como token propio.
	 *
	 * @var array<string, true>
	 */
	private const SELF_CONTAINED = array(
		'SCRIPT'   => true,
		'STYLE'    => true,
		'TITLE'    => true,
		'TEXTAREA' => true,
		'IFRAME'   => true,
		'NOEMBED'  => true,
		'NOFRAMES' => true,
		'XMP'      => true,
	);

	/**
	 * Elementos de bloque. Interrumpen una unidad de traducción: el texto a un
	 * lado y al otro son cadenas distintas.
	 *
	 * @var array<string, true>
	 */
	private const BLOCK_LEVEL = array(
		'ADDRESS'    => true,
		'ARTICLE'    => true,
		'ASIDE'      => true,
		'BLOCKQUOTE' => true,
		'BODY'       => true,
		'CAPTION'    => true,
		'DD'         => true,
		'DETAILS'    => true,
		'DIALOG'     => true,
		'DIV'        => true,
		'DL'         => true,
		'DT'         => true,
		'FIELDSET'   => true,
		'FIGCAPTION' => true,
		'FIGURE'     => true,
		'FOOTER'     => true,
		'FORM'       => true,
		'H1'         => true,
		'H2'         => true,
		'H3'         => true,
		'H4'         => true,
		'H5'         => true,
		'H6'         => true,
		'HEAD'       => true,
		'HEADER'     => true,
		'HGROUP'     => true,
		'HR'         => true,
		'HTML'       => true,
		'LI'         => true,
		'MAIN'       => true,
		'NAV'        => true,
		'OL'         => true,
		'OPTION'     => true,
		'P'          => true,
		'PRE'        => true,
		'SECTION'    => true,
		'SUMMARY'    => true,
		'TABLE'      => true,
		'TBODY'      => true,
		'TD'         => true,
		'TFOOT'      => true,
		'TH'         => true,
		'THEAD'      => true,
		'TR'         => true,
		'UL'         => true,
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
	 * @var array<string, true>
	 */
	private const BREAKS_RUN = array(
		'AUDIO'    => true,
		'BUTTON'   => true,
		'EMBED'    => true,
		'INPUT'    => true,
		'LABEL'    => true,
		'LEGEND'   => true,
		'OPTGROUP' => true,
		'SELECT'   => true,
		'VIDEO'    => true,
	);

	/**
	 * Elementos cuyo contenido no se traduce nunca.
	 *
	 * SCRIPT y STYLE no harían falta aquí, porque el tokenizador no desciende a
	 * su interior, pero se incluyen para que la regla siga siendo explícita si
	 * algún día cambia el driver.
	 *
	 * @var array<string, true>
	 */
	private const NEVER_TRANSLATE = array(
		'CANVAS'   => true,
		'CODE'     => true,
		'KBD'      => true,
		'MATH'     => true,
		'OBJECT'   => true,
		'PRE'      => true,
		'SAMP'     => true,
		'SCRIPT'   => true,
		'STYLE'    => true,
		'SVG'      => true,
		'TEMPLATE' => true,
		'VAR'      => true,
	);

	/**
	 * Si el elemento es vacío.
	 *
	 * @param string $tag Nombre de etiqueta en mayúsculas.
	 */
	public static function is_void( string $tag ): bool {
		return isset( self::VOID[ $tag ] );
	}

	/**
	 * Si el tokenizador entrega el elemento completo en un solo token.
	 *
	 * @param string $tag Nombre de etiqueta en mayúsculas.
	 */
	public static function is_self_contained( string $tag ): bool {
		return isset( self::SELF_CONTAINED[ $tag ] );
	}

	/**
	 * Si el elemento corta la unidad de traducción en curso.
	 *
	 * @param string $tag Nombre de etiqueta en mayúsculas.
	 */
	public static function breaks_run( string $tag ): bool {
		return isset( self::BLOCK_LEVEL[ $tag ] ) || isset( self::BREAKS_RUN[ $tag ] );
	}

	/**
	 * Si el contenido del elemento debe quedar fuera de la traducción.
	 *
	 * @param string $tag Nombre de etiqueta en mayúsculas.
	 */
	public static function never_translate( string $tag ): bool {
		return isset( self::NEVER_TRANSLATE[ $tag ] );
	}
}
