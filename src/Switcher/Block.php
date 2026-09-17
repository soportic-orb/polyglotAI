<?php
/**
 * Bloque del selector de idioma.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Switcher;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use WP_Block_Supports;

/**
 * Registra el bloque `pgai/language-switcher`.
 *
 * El bloque se pinta **en el servidor**, no se guarda como HTML en la entrada.
 * Tiene que ser así: los enlaces dependen de la página que se está viendo, de
 * los idiomas activos en ese momento y de los slugs traducidos de esa página.
 * Un HTML guardado en la base de datos se quedaría obsoleto en cuanto se
 * añadiera un idioma o se corrigiera un slug, y cada entrada tendría que
 * volver a editarse.
 */
final class Block {

	/** Nombre del bloque. */
	public const NAME = 'pgai/language-switcher';

	/** Handle de la hoja de estilos del selector. */
	public const STYLE_HANDLE = 'pgai-switcher';

	/**
	 * Constructor.
	 *
	 * @param SwitcherRenderer $renderer  Pintado del selector.
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 */
	public function __construct(
		private readonly SwitcherRenderer $renderer,
		private readonly LanguageRegistry $languages
	) {}

	/**
	 * Engancha el registro.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
	}

	/**
	 * Registra el bloque y la hoja de estilos compartida.
	 */
	public function register_block(): void {
		wp_register_style(
			self::STYLE_HANDLE,
			PGAI_URL . 'assets/build/style-switcher.css',
			array(),
			PGAI_VERSION
		);

		// El build genera la variante RTL; WordPress la sirve sola cuando el
		// idioma se escribe de derecha a izquierda.
		wp_style_add_data( self::STYLE_HANDLE, 'rtl', 'replace' );

		$block = PGAI_DIR . 'assets/src/block';

		if ( ! is_readable( $block . '/block.json' ) ) {
			return;
		}

		register_block_type(
			$block,
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Pinta el bloque.
	 *
	 * @param array<string, mixed> $attributes Atributos del bloque.
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		$html = $this->renderer->render(
			array(
				'display'      => (string) ( $attributes['display'] ?? 'name' ),
				'layout'       => (string) ( $attributes['layout'] ?? 'list' ),
				'hide_current' => ! empty( $attributes['hideCurrent'] ) ? 'yes' : 'no',
			)
		);

		if ( '' === $html ) {
			return '';
		}

		// get_block_wrapper_attributes() trae la alineación y las clases que el
		// editor haya puesto al bloque, pero solo funciona dentro del pintado de
		// un bloque: fuera de ahí lee un bloque en curso que no existe. Se
		// comprueba para que llamar a render() desde código no reviente.
		if ( null === WP_Block_Supports::$block_to_render ) {
			return sprintf( '<div class="wp-block-pgai-language-switcher">%s</div>', $html );
		}

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $html );
	}

	/**
	 * Pasa los idiomas al editor para la vista aproximada del bloque.
	 */
	public function editor_data(): void {
		$languages = array_map(
			static fn ( Language $language ): array => array(
				'locale' => $language->locale,
				'label'  => $language->label,
				'code'   => strtoupper( $language->code() ),
				'flag'   => $language->flag,
			),
			$this->languages->visible( true )
		);

		wp_add_inline_script(
			'pgai-language-switcher-editor-script',
			'window.pgaiBlock = ' . wp_json_encode( array( 'languages' => $languages ) ) . ';',
			'before'
		);
	}
}
