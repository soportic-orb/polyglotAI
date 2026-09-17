<?php
/**
 * Endpoints de la traducción de sitio completo.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Rest;

use PolyglotAI\Jobs\SiteTranslator;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Arranca, para, reanuda y consulta una traducción de sitio completo.
 *
 * Exige `pgai_run_auto_translate` y no `pgai_translate`: esto gasta dinero, y
 * mucho de golpe. Corregir un texto y lanzar la traducción de un sitio entero
 * no son el mismo permiso (ADR-10).
 */
final class SiteController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry    $languages  Idiomas del sitio.
	 * @param SiteTranslator|null $translator Traductor de sitio completo.
	 */
	public function __construct(
		LanguageRegistry $languages,
		private readonly ?SiteTranslator $translator
	) {
		parent::__construct( $languages );
	}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/site',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => $this->requires( Capabilities::RUN_AUTO ),
					'args'                => $this->language_argument(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'command' ),
					'permission_callback' => $this->requires( Capabilities::RUN_AUTO ),
					'args'                => array_merge(
						$this->language_argument(),
						array(
							'command' => array(
								'type'     => 'string',
								'required' => true,
								'enum'     => array( 'start', 'pause', 'resume', 'cancel' ),
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Estado de la traducción de un idioma.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$language = $this->language_from( $request );

		if ( $language instanceof WP_Error ) {
			return $language;
		}

		if ( null === $this->translator ) {
			return new WP_REST_Response( array( 'supported' => false ) );
		}

		$run = $this->translator->status( $language->locale );

		return new WP_REST_Response(
			array(
				'supported' => true,
				'run'       => null === $run ? null : $run->to_array(),
			)
		);
	}

	/**
	 * Arranca, para, reanuda o cancela.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function command( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$language = $this->language_from( $request );

		if ( $language instanceof WP_Error ) {
			return $language;
		}

		if ( null === $this->translator ) {
			return new WP_Error(
				'pgai_no_async_engine',
				__( 'El motor configurado no admite traducir el sitio entero en diferido.', 'polyglot-ai' ),
				array( 'status' => 400 )
			);
		}

		$command = (string) $request->get_param( 'command' );
		$locale  = $language->locale;

		$run = match ( $command ) {
			'start'  => $this->translator->start( $locale ),
			'pause'  => $this->translator->pause( $locale ),
			'resume' => $this->translator->resume( $locale ),
			default  => null,
		};

		if ( 'cancel' === $command ) {
			$this->translator->cancel( $locale );
		}

		if ( 'start' === $command && null === $run ) {
			return new WP_Error(
				'pgai_nothing_pending',
				__( 'No queda nada por traducir en este idioma.', 'polyglot-ai' ),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response(
			array(
				'supported' => true,
				'run'       => null === $run ? null : $run->to_array(),
			)
		);
	}
}
