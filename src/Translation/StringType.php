<?php
/**
 * Tipos de cadena traducible.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

/**
 * Naturaleza de la cadena, que determina cómo se escribe de vuelta en el HTML.
 */
enum StringType: string {
	/** Nodo de texto plano. Se vuelve a escribir escapando & y <. */
	case Text = 'text';

	/** Bloque con HTML en línea, tratado como una sola unidad. Se escribe tal cual. */
	case Block = 'block';

	/** Valor de un atributo (alt, title, placeholder, aria-label, value). */
	case Attribute = 'attribute';

	/** Contenido de un elemento RCDATA: title, textarea. */
	case Rcdata = 'rcdata';

	/** Metadato de la cabecera: description, Open Graph, Twitter. */
	case Meta = 'meta';

	/** Slug de URL. */
	case Slug = 'slug';

	/**
	 * Imagen: el valor es una URL o un srcset, no texto.
	 *
	 * Nunca se envía al motor de traducción. Una URL no se traduce: se
	 * sustituye a mano por otra imagen desde la mediateca.
	 */
	case Image = 'image';

	/** Cadena de gettext capturada de un tema o plugin. */
	case Gettext = 'gettext';

	/**
	 * Nombre traducible para la interfaz.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Text      => __( 'Texto', 'polyglot-ai' ),
			self::Block     => __( 'Bloque', 'polyglot-ai' ),
			self::Attribute => __( 'Atributo', 'polyglot-ai' ),
			self::Rcdata    => __( 'Título o área de texto', 'polyglot-ai' ),
			self::Meta      => __( 'Metaetiqueta', 'polyglot-ai' ),
			self::Slug      => __( 'Slug', 'polyglot-ai' ),
			self::Image     => __( 'Imagen', 'polyglot-ai' ),
			self::Gettext   => __( 'Cadena del tema o de un plugin', 'polyglot-ai' ),
		};
	}

	/**
	 * Si el valor almacenado es HTML (y por tanto no se escapa al escribirlo).
	 */
	public function is_html(): bool {
		return self::Block === $this;
	}

	/**
	 * Si el contenido es texto que tiene sentido enviar a un motor de
	 * traducción automática.
	 *
	 * Una URL de imagen no lo es, y mandarla solo conseguiría que el motor la
	 * «tradujera» y rompiera la imagen.
	 */
	public function is_machine_translatable(): bool {
		return self::Image !== $this;
	}
}
