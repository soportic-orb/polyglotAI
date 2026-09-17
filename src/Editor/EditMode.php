<?php
/**
 * Modo de edición del editor visual.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Editor;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Support\Capabilities;

/**
 * Detecta si la petición es la vista previa del editor visual.
 *
 * La vista previa es el propio sitio cargado dentro de un iframe, con cada
 * cadena traducible envuelta en una marca para que el panel pueda localizarla.
 * Ese marcado adicional solo existe aquí: una visita normal recibe el HTML
 * limpio.
 */
final class EditMode {

	/** Parámetro que activa el modo de edición. */
	public const PARAM = 'pgai-edit';

	/** Parámetro con el idioma que se está editando. */
	public const LANGUAGE_PARAM = 'pgai-lang';

	/** Acción del nonce. */
	public const NONCE_ACTION = 'pgai_edit_preview';

	/**
	 * Resultado memorizado de la comprobación.
	 *
	 * @var bool|null
	 */
	private ?bool $active = null;

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
	 * Si la petición actual es la vista previa del editor.
	 */
	public function is_active(): bool {
		if ( null !== $this->active ) {
			return $this->active;
		}

		$this->active = $this->check();

		return $this->active;
	}

	/**
	 * Idioma que se está editando, o null si no estamos en modo edición.
	 */
	public function language(): ?Language {
		if ( ! $this->is_active() ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$locale = isset( $_GET[ self::LANGUAGE_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::LANGUAGE_PARAM ] ) ) : '';

		return $this->languages->by_locale( $locale );
	}

	/**
	 * Fija el idioma de la petición al que se está editando.
	 *
	 * Sin esto, la vista previa mostraría el idioma que corresponda a la URL, no
	 * el que el traductor ha elegido en el panel.
	 */
	public function apply_language(): void {
		$language = $this->language();

		if ( null !== $language ) {
			$this->request->force( $language );
		}
	}

	/**
	 * URL de la vista previa para una ruta y un idioma.
	 *
	 * @param string   $path     Ruta del sitio.
	 * @param Language $language Idioma a editar.
	 */
	public function preview_url( string $path, Language $language ): string {
		return add_query_arg(
			array(
				self::PARAM          => '1',
				self::LANGUAGE_PARAM => $language->locale,
				'_wpnonce'           => wp_create_nonce( self::NONCE_ACTION ),
			),
			home_url( $path )
		);
	}

	/**
	 * Comprueba las tres condiciones del modo de edición.
	 */
	private function check(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return false;
		}

		// Quien no puede traducir no recibe el marcado del editor, ni aunque
		// adivine la URL.
		if ( ! current_user_can( Capabilities::TRANSLATE ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';

		return false !== wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}
}
