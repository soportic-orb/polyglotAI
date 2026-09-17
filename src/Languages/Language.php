<?php
/**
 * Un idioma del sitio.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Languages;

/**
 * Definición de un idioma configurado.
 */
final class Language {

	/**
	 * Constructor.
	 *
	 * @param string $locale    Locale de WordPress (es_ES, pt_BR, ca).
	 * @param string $slug      Segmento de URL (en, pt-br).
	 * @param string $label     Nombre mostrado a los visitantes.
	 * @param string $flag      Identificador de la bandera.
	 * @param bool   $rtl       Si se escribe de derecha a izquierda.
	 * @param bool   $active    Si está activo. Desactivarlo NO borra sus traducciones.
	 * @param bool   $published Si lo ven los visitantes. En false solo lo ven
	 *                          quienes pueden traducir (idioma en preparación).
	 * @param string $formality Tratamiento: informal, formal o neutral.
	 */
	public function __construct(
		public readonly string $locale,
		public readonly string $slug,
		public readonly string $label,
		public readonly string $flag = '',
		public readonly bool $rtl = false,
		public readonly bool $active = true,
		public readonly bool $published = true,
		public readonly string $formality = 'neutral'
	) {}

	/**
	 * Código de idioma sin la variante regional: de es_MX devuelve es.
	 */
	public function code(): string {
		return strtolower( (string) strtok( str_replace( '-', '_', $this->locale ), '_' ) );
	}

	/**
	 * Valor para el atributo lang del HTML (es-MX).
	 */
	public function html_lang(): string {
		return str_replace( '_', '-', $this->locale );
	}

	/**
	 * Crea un idioma a partir de su forma almacenada.
	 *
	 * @param array<string, mixed> $data Datos guardados.
	 */
	public static function from_array( array $data ): self {
		$locale = (string) ( $data['locale'] ?? '' );

		return new self(
			$locale,
			(string) ( $data['slug'] ?? strtolower( str_replace( '_', '-', $locale ) ) ),
			(string) ( $data['label'] ?? $locale ),
			(string) ( $data['flag'] ?? '' ),
			(bool) ( $data['rtl'] ?? false ),
			(bool) ( $data['active'] ?? true ),
			(bool) ( $data['published'] ?? true ),
			(string) ( $data['formality'] ?? 'neutral' )
		);
	}

	/**
	 * Forma almacenable.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'locale'    => $this->locale,
			'slug'      => $this->slug,
			'label'     => $this->label,
			'flag'      => $this->flag,
			'rtl'       => $this->rtl,
			'active'    => $this->active,
			'published' => $this->published,
			'formality' => $this->formality,
		);
	}
}
