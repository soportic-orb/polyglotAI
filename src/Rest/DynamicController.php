<?php
/**
 * Traducción de contenido insertado por JavaScript.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Rest;

use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Translation\Hasher;
use PolyglotAI\Translation\Normalizer;
use PolyglotAI\Translation\StringType;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Devuelve traducciones ya existentes para textos que aparecen en la página
 * después de cargarla: resultados de un filtro por AJAX, un carrito que se
 * actualiza, un carrusel que monta su contenido con JavaScript.
 *
 * Es el único endpoint abierto a visitantes no identificados, y lo es porque
 * tiene que funcionar para cualquiera que navegue el sitio. Es de SOLO LECTURA
 * y solo devuelve traducciones que ya se muestran públicamente en las páginas:
 * no escribe nada, no llama a ninguna API y no revela nada que no esté ya en el
 * HTML del sitio.
 */
final class DynamicController extends Controller {

	/** Textos por petición. */
	private const MAX_TEXTS = 100;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry      $languages    Idiomas del sitio.
	 * @param TranslationRepository $translations Repositorio de traducciones.
	 * @param Hasher                $hasher       Calculador de hashes.
	 * @param Normalizer            $normalizer   Normalizador.
	 */
	public function __construct(
		LanguageRegistry $languages,
		private readonly TranslationRepository $translations,
		private readonly Hasher $hasher,
		private readonly Normalizer $normalizer
	) {
		parent::__construct( $languages );
	}

	/**
	 * Registra la ruta.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/dynamic',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'translate' ),
				'permission_callback' => array( $this, 'is_allowed' ),
				'args'                => array_merge(
					$this->language_argument(),
					array(
						'texts' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
						),
					)
				),
			)
		);
	}

	/**
	 * Comprobación de permiso del endpoint público.
	 *
	 * @return bool|WP_Error
	 */
	public function is_allowed() {
		/**
		 * Permite cerrar la traducción de contenido dinámico a visitantes.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $allowed Si se permite.
		 */
		$allowed = (bool) apply_filters( 'pgai_allow_dynamic_translation', true );

		if ( $allowed ) {
			return true;
		}

		return new WP_Error(
			'pgai_forbidden',
			__( 'La traducción de contenido dinámico está desactivada.', 'polyglot-ai' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Devuelve las traducciones que existan para los textos pedidos.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function translate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$language = $this->language_from( $request );

		if ( $language instanceof WP_Error ) {
			return $language;
		}

		/** @var string[] $texts */
		$texts = array_slice( array_unique( array_map( 'strval', (array) $request->get_param( 'texts' ) ) ), 0, self::MAX_TEXTS );

		$by_hash = array();

		foreach ( $texts as $text ) {
			$normalized = $this->normalizer->normalize( $text );

			if ( ! $this->normalizer->is_translatable( $normalized ) ) {
				continue;
			}

			// El mismo hash puede venir de dos textos que solo difieren en
			// espaciado: se guardan todos para poder devolver cada uno.
			$by_hash[ $this->hasher->hash( $text, StringType::Text ) ][] = $text;
		}

		if ( array() === $by_hash ) {
			return new WP_REST_Response( array( 'translations' => new \stdClass() ) );
		}

		$found        = $this->translations->lookup( array_keys( $by_hash ), $language->locale );
		$translations = array();

		foreach ( $found as $hash => $translation ) {
			foreach ( $by_hash[ $hash ] ?? array() as $text ) {
				$translations[ $text ] = $translation;
			}
		}

		return new WP_REST_Response( array( 'translations' => (object) $translations ) );
	}
}
