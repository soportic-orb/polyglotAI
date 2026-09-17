<?php
/**
 * Endpoints de lectura y escritura de traducciones.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Rest;

use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Translation\DictionaryFactory;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StringType;
use PolyglotAI\Translation\Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Sirve al editor visual las cadenas de una página y guarda sus correcciones.
 */
final class StringsController extends Controller {

	/**
	 * Etiquetas HTML permitidas en una traducción escrita a mano.
	 *
	 * Es una lista blanca propia y deliberadamente corta: en una traducción solo
	 * caben marcas de estilo en línea y enlaces. Nada de wp_kses_post, que
	 * admite iframes, scripts embebidos y bastante más de lo que un traductor
	 * necesita (ADR-12).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		$allowed = array(
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
				'class'  => true,
			),
			'strong' => array( 'class' => true ),
			'b'      => array( 'class' => true ),
			'em'     => array( 'class' => true ),
			'i'      => array( 'class' => true ),
			'u'      => array( 'class' => true ),
			's'      => array( 'class' => true ),
			'span'   => array(
				'class' => true,
				'style' => true,
				'lang'  => true,
			),
			'br'     => array(),
			'sup'    => array(),
			'sub'    => array(),
			'small'  => array(),
			'code'   => array(),
			'img'    => array(
				'src'     => true,
				'srcset'  => true,
				'sizes'   => true,
				'alt'     => true,
				'width'   => true,
				'height'  => true,
				'class'   => true,
				'loading' => true,
			),
		);

		/**
		 * Permite ajustar el HTML admitido en una traducción manual.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, array<string, bool>> $allowed Etiquetas y atributos.
		 */
		return (array) apply_filters( 'pgai_allowed_html', $allowed );
	}

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry      $languages    Idiomas del sitio.
	 * @param SourceRepository      $sources      Repositorio de cadenas originales.
	 * @param TranslationRepository $translations Repositorio de traducciones.
	 * @param Validator             $validator    Validador estructural.
	 */
	public function __construct(
		LanguageRegistry $languages,
		private readonly SourceRepository $sources,
		private readonly TranslationRepository $translations,
		private readonly Validator $validator
	) {
		parent::__construct( $languages );
	}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/strings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_strings' ),
					'permission_callback' => $this->requires( Capabilities::TRANSLATE ),
					'args'                => array_merge(
						$this->language_argument(),
						array(
							'hashes' => array(
								'type'        => 'array',
								'required'    => true,
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Hashes de las cadenas a recuperar.', 'polyglot-ai' ),
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_strings' ),
					'permission_callback' => $this->requires( Capabilities::TRANSLATE ),
					'args'                => array_merge(
						$this->language_argument(),
						array(
							'translations' => array(
								'type'        => 'array',
								'required'    => true,
								'description' => __( 'Traducciones a guardar.', 'polyglot-ai' ),
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Devuelve las cadenas pedidas con su traducción y su estado.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_strings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$language = $this->language_from( $request );

		if ( $language instanceof WP_Error ) {
			return $language;
		}

		/** @var string[] $hashes */
		$hashes = array_map( 'strval', (array) $request->get_param( 'hashes' ) );
		$hashes = array_slice( array_unique( $hashes ), 0, 500 );

		return new WP_REST_Response(
			array(
				'language' => $language->locale,
				'strings'  => $this->sources->details_by_hash( $hashes, $language->locale ),
			)
		);
	}

	/**
	 * Guarda las traducciones enviadas desde el editor.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_strings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$language = $this->language_from( $request );

		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$can_review = current_user_can( Capabilities::REVIEW );
		$saved      = array();
		$rejected   = array();

		/** @var array<int, array<string, mixed>> $incoming */
		$incoming = (array) $request->get_param( 'translations' );

		foreach ( $incoming as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['hash'] ?? null ) || ! is_string( $entry['translation'] ?? null ) ) {
				continue;
			}

			$hash   = $entry['hash'];
			$detail = $this->sources->details_by_hash( array( $hash ), $language->locale )[ $hash ] ?? null;

			if ( null === $detail ) {
				$rejected[ $hash ] = 'unknown_string';
				continue;
			}

			$type        = StringType::tryFrom( (string) $detail['type'] ) ?? StringType::Text;
			$translation = $this->sanitize( $entry['translation'], $type );

			// La misma validación estructural que se aplica a lo que devuelve el
			// motor: una persona también puede perder una etiqueta sin querer.
			$validation = $this->validator->validate( (string) $detail['original'], $translation, $type );

			if ( ! $validation->is_valid ) {
				$rejected[ $hash ] = $validation->summary();
				continue;
			}

			$status = ( $can_review && 'reviewed' === ( $entry['status'] ?? '' ) )
				? Status::Reviewed
				: Status::Manual;

			$this->translations->save( (int) $detail['source_id'], $language->locale, $translation, $status );

			$saved[ $hash ] = array(
				'translation' => $translation,
				'status'      => $status->value,
			);
		}

		if ( array() !== $saved ) {
			DictionaryFactory::invalidate();
		}

		return new WP_REST_Response(
			array(
				'saved'    => $saved,
				'rejected' => $rejected,
			)
		);
	}

	/**
	 * Limpia una traducción según su tipo.
	 *
	 * @param string     $translation Traducción entrante.
	 * @param StringType $type        Tipo de cadena.
	 */
	private function sanitize( string $translation, StringType $type ): string {
		if ( $type->is_html() ) {
			return wp_kses( $translation, self::allowed_html() );
		}

		// Una cadena de texto plano no puede traer marcado: se escapa entero en
		// lugar de intentar limpiarlo.
		return wp_strip_all_tags( $translation );
	}
}
