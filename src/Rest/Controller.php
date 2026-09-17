<?php
/**
 * Base de los controladores REST.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Rest;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use WP_Error;
use WP_REST_Request;

/**
 * Comportamiento común de los endpoints del plugin.
 *
 * Ningún endpoint usa __return_true como permission_callback: todos comprueban
 * una capacidad concreta (ADR-10).
 */
abstract class Controller {

	/** Espacio de nombres de la API. */
	public const NAMESPACE = 'pgai/v1';

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 */
	public function __construct( protected readonly LanguageRegistry $languages ) {}

	/**
	 * Registra las rutas del controlador.
	 */
	abstract public function register_routes(): void;

	/**
	 * Devuelve una comprobación de permiso para una capacidad.
	 *
	 * @param string $capability Capacidad requerida.
	 * @return callable(): (true|WP_Error)
	 */
	protected function requires( string $capability ): callable {
		return static function () use ( $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}

			return new WP_Error(
				'pgai_forbidden',
				__( 'No tienes permiso para hacer esto.', 'polyglot-ai' ),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		};
	}

	/**
	 * Resuelve el idioma de una petición.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return Language|WP_Error
	 */
	protected function language_from( WP_REST_Request $request ): Language|WP_Error {
		$locale   = (string) $request->get_param( 'language' );
		$language = $this->languages->by_locale( $locale );

		if ( null === $language ) {
			return new WP_Error(
				'pgai_unknown_language',
				/* translators: %s: locale solicitado. */
				sprintf( __( 'El idioma «%s» no está configurado.', 'polyglot-ai' ), $locale ),
				array( 'status' => 400 )
			);
		}

		if ( $this->languages->is_default( $language->slug ) ) {
			return new WP_Error(
				'pgai_default_language',
				__( 'El idioma por defecto no se traduce.', 'polyglot-ai' ),
				array( 'status' => 400 )
			);
		}

		return $language;
	}

	/**
	 * Esquema del argumento de idioma, común a casi todos los endpoints.
	 *
	 * @return array<string, mixed>
	 */
	protected function language_argument(): array {
		return array(
			'language' => array(
				'type'              => 'string',
				'required'          => true,
				'description'       => __( 'Locale de destino.', 'polyglot-ai' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
