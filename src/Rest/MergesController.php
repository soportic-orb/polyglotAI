<?php
/**
 * Endpoints de los bloques de traducción fusionados.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Rest;

use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Translation\MergeRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Crea y deshace fusiones de cadenas.
 *
 * Una fusión cambia cómo se trocea la página para todos los idiomas, no solo
 * para el que se está editando: por eso no recibe un idioma.
 */
final class MergesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param MergeRegistry    $merges    Registro de fusiones.
	 */
	public function __construct( LanguageRegistry $languages, private readonly MergeRegistry $merges ) {
		parent::__construct( $languages );
	}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/merges',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->requires( Capabilities::TRANSLATE ),
					'args'                => array(
						'hashes' => array(
							'type'        => 'array',
							'required'    => true,
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Hashes a fusionar, en el orden en que aparecen.', 'polyglot-ai' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $this->requires( Capabilities::TRANSLATE ),
					'args'                => array(
						'hash' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Hash de cualquiera de las cadenas fusionadas.', 'polyglot-ai' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Fusiona varias cadenas en una sola unidad.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		/** @var string[] $hashes */
		$hashes = array_map( 'sanitize_text_field', (array) $request->get_param( 'hashes' ) );
		$group  = $this->merges->add( $hashes );

		if ( null === $group ) {
			return new WP_Error(
				'pgai_merge_too_small',
				__( 'Hacen falta al menos dos cadenas para fusionarlas.', 'polyglot-ai' ),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response( array( 'group' => $group ) );
	}

	/**
	 * Deshace una fusión.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$hash = (string) $request->get_param( 'hash' );

		// El bloque fusionado lleva el grupo en su contexto; el editor puede
		// mandar tanto ese identificador como el hash de un miembro.
		if ( str_starts_with( $hash, 'merge:' ) ) {
			$hash = substr( $hash, strlen( 'merge:' ) );
		}

		if ( ! $this->merges->remove( $hash ) ) {
			return new WP_Error(
				'pgai_merge_not_found',
				__( 'Esa fusión ya no existe.', 'polyglot-ai' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'removed' => true ) );
	}
}
