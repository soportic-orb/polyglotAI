<?php
/**
 * Endpoints de los slugs traducidos.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Rest;

use PolyglotAI\Database\SlugRecord;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Translation\Status;
use WP_Error;
use WP_Post;
use WP_Term;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Lee y escribe los slugs traducidos de una página.
 *
 * El editor visual pide los de la página que se está editando: el de la propia
 * entrada, los de sus ascendientes y los de las bases reescritas que aparecen en
 * su URL. Son justo los segmentos sobre los que el traductor puede actuar.
 */
final class SlugsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param SlugRepository   $slugs     Almacén de slugs.
	 * @param SlugResolver     $resolver  Traductor de rutas.
	 * @param UrlConverter     $converter Conversor de rutas.
	 */
	public function __construct(
		LanguageRegistry $languages,
		private readonly SlugRepository $slugs,
		private readonly SlugResolver $resolver,
		private readonly UrlConverter $converter
	) {
		parent::__construct( $languages );
	}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/slugs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->requires( Capabilities::TRANSLATE ),
					'args'                => array(
						'language' => array(
							'type'     => 'string',
							'required' => true,
						),
						'url'      => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
							'description'       => __( 'URL de la página cuyos slugs se quieren editar.', 'polyglot-ai' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $this->requires( Capabilities::TRANSLATE ),
					'args'                => array(
						'language'        => array(
							'type'     => 'string',
							'required' => true,
						),
						'object_type'     => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'post', 'term', 'base' ),
						),
						'object_subtype'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'object_id'       => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'translated_slug' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_title',
						),
					),
				),
			)
		);
	}

	/**
	 * Slugs editables de una página.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$language = $this->language_from( $request );

		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$path = (string) wp_parse_url( (string) $request->get_param( 'url' ), PHP_URL_PATH );
		$path = $this->converter->strip( '' === $path ? '/' : $path );

		// La URL puede venir con los slugs ya traducidos —es la que el traductor
		// está viendo—, así que primero se vuelve al original.
		$path = $this->resolver->to_original( $path, $language->locale );

		$items = array();

		foreach ( $this->objects_in( $path, $language->locale ) as $object ) {
			$record = $this->slugs->find( $object['type'], $object['subtype'], $object['id'], $language->locale );

			$items[] = array(
				'object_type'     => $object['type'],
				'object_subtype'  => $object['subtype'],
				'object_id'       => $object['id'],
				'label'           => $object['label'],
				'original_slug'   => null === $record ? $object['slug'] : $record->original_slug,
				'translated_slug' => null === $record ? '' : $record->translated_slug,
				'status'          => null === $record ? Status::Pending->value : $record->status->value,
			);
		}

		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * Guarda un slug escrito a mano.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$language = $this->language_from( $request );

		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$type    = (string) $request->get_param( 'object_type' );
		$subtype = (string) $request->get_param( 'object_subtype' );
		$id      = (int) $request->get_param( 'object_id' );
		$slug    = (string) $request->get_param( 'translated_slug' );

		if ( '' === $slug ) {
			return new WP_Error(
				'pgai_empty_slug',
				__( 'El slug no puede quedar vacío.', 'polyglot-ai' ),
				array( 'status' => 400 )
			);
		}

		$current = $this->slugs->find( $type, $subtype, $id, $language->locale );

		if ( null === $current ) {
			return new WP_Error(
				'pgai_unknown_slug',
				__( 'Ese elemento no tiene slug que traducir.', 'polyglot-ai' ),
				array( 'status' => 404 )
			);
		}

		// Un slug escrito a mano es manual: a partir de aquí ninguna traducción
		// automática lo tocará (ADR-07).
		$saved = $this->slugs->save(
			new SlugRecord( $type, $subtype, $id, $language->locale, $current->original_slug, $slug, Status::Manual )
		);

		if ( ! $saved ) {
			return new WP_Error(
				'pgai_slug_not_saved',
				__( 'No se ha podido guardar el slug.', 'polyglot-ai' ),
				array( 'status' => 500 )
			);
		}

		$stored = $this->slugs->find( $type, $subtype, $id, $language->locale );

		return new WP_REST_Response(
			array(
				// Puede diferir de lo pedido: si otro objeto ya usaba ese slug se
				// desambigua con un sufijo.
				'translated_slug' => null === $stored ? $slug : $stored->translated_slug,
				'status'          => Status::Manual->value,
			)
		);
	}

	/**
	 * Objetos cuyos slugs forman la ruta de una página.
	 *
	 * @param string $path     Ruta con los slugs originales.
	 * @param string $language Locale.
	 * @return array<int, array{type:string, subtype:string, id:int, slug:string, label:string}>
	 */
	private function objects_in( string $path, string $language ): array {
		$objects = array();
		$post_id = url_to_postid( home_url( $path ) );

		if ( $post_id > 0 ) {
			$chain   = array_reverse( get_post_ancestors( $post_id ) );
			$chain[] = $post_id;

			foreach ( $chain as $id ) {
				$post = get_post( (int) $id );

				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				$objects[] = array(
					'type'    => 'post',
					'subtype' => (string) $post->post_type,
					'id'      => (int) $post->ID,
					'slug'    => (string) $post->post_name,
					'label'   => (string) $post->post_title,
				);
			}
		}

		// Los términos y las bases reescritas de la ruta no se deducen de la URL:
		// hay que preguntar por los segmentos que quedan.
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );

		foreach ( $this->slugs->by_original_slugs( $language, $segments, array( 'term', 'base' ) ) as $record ) {
			$objects[] = array(
				'type'    => $record->object_type,
				'subtype' => $record->object_subtype,
				'id'      => $record->object_id,
				'slug'    => $record->original_slug,
				'label'   => $this->label_for( $record ),
			);
		}

		return $objects;
	}

	/**
	 * Nombre legible de un registro para la interfaz.
	 *
	 * @param SlugRecord $record Registro.
	 */
	private function label_for( SlugRecord $record ): string {
		if ( 'term' === $record->object_type ) {
			$term = get_term( $record->object_id, $record->object_subtype );

			if ( $term instanceof \WP_Term ) {
				return (string) $term->name;
			}
		}

		return $record->original_slug;
	}
}
