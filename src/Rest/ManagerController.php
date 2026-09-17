<?php
/**
 * Endpoints del gestor de cadenas.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Rest;

use PolyglotAI\Database\StringManagerRepository;
use PolyglotAI\Database\StringQuery;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Translation\DictionaryFactory;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StringType;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Busca cadenas y actúa sobre ellas en lote.
 *
 * El editor visual sirve para corregir lo que se ve en una página. Esto sirve
 * para lo demás: las cadenas de gettext que nunca llegan a una página, las que
 * se quedaron en error, las de una página que ya no existe, y para las
 * operaciones que no tienen sentido de una en una.
 */
final class ManagerController extends Controller {

	/**
	 * Acciones en lote admitidas.
	 *
	 * @var string[]
	 */
	private const ACTIONS = array( 'review', 'retranslate', 'delete' );

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry        $languages    Idiomas del sitio.
	 * @param StringManagerRepository $strings      Consultas del gestor.
	 * @param TranslationRepository   $translations Repositorio de traducciones.
	 */
	public function __construct(
		LanguageRegistry $languages,
		private readonly StringManagerRepository $strings,
		private readonly TranslationRepository $translations
	) {
		parent::__construct( $languages );
	}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/manager',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $this->requires( Capabilities::TRANSLATE ),
				'args'                => array(
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static fn ( $value ): string => sanitize_text_field( (string) $value ),
					),
					'status'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'type'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/manager/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $this->requires( Capabilities::TRANSLATE ),
				'args'                => array(
					'language'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'action'     => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => self::ACTIONS,
					),
					'source_ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * Lista de cadenas.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$language = $this->language_from( $request );

		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$query = new StringQuery(
			$language->locale,
			(string) $request->get_param( 'search' ),
			Status::tryFrom( (string) $request->get_param( 'status' ) ),
			StringType::tryFrom( (string) $request->get_param( 'type' ) ),
			(int) $request->get_param( 'page' ),
			(int) $request->get_param( 'per_page' )
		);

		$total = $this->strings->count( $query );

		return new WP_REST_Response(
			array(
				'items'    => $this->strings->search( $query ),
				'total'    => $total,
				'pages'    => (int) ceil( $total / $query->limit() ),
				'page'     => max( 1, $query->page ),
				'counts'   => $this->translations->counts( $language->locale ),
				'statuses' => $this->status_labels(),
				'types'    => $this->type_labels(),
			)
		);
	}

	/**
	 * Acción sobre varias cadenas.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$language = $this->language_from( $request );

		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$action     = (string) $request->get_param( 'action' );
		$source_ids = array_map( 'intval', (array) $request->get_param( 'source_ids' ) );

		if ( array() === $source_ids ) {
			return new WP_Error(
				'pgai_nothing_selected',
				__( 'No has seleccionado ninguna cadena.', 'polyglot-ai' ),
				array( 'status' => 400 )
			);
		}

		// Marcar como revisada es un acto de aprobación, no de edición: quien
		// solo puede traducir puede corregir el texto, pero no dar por bueno lo
		// que ha escrito una máquina.
		if ( 'review' === $action && ! current_user_can( Capabilities::REVIEW ) ) {
			return new WP_Error(
				'pgai_forbidden',
				__( 'No tienes permiso para dar traducciones por revisadas.', 'polyglot-ai' ),
				array( 'status' => 403 )
			);
		}

		$affected = match ( $action ) {
			'review'      => $this->strings->mark_reviewed( $source_ids, $language->locale, get_current_user_id() ),
			'retranslate' => $this->strings->mark_for_retranslation( $source_ids, $language->locale ),
			'delete'      => $this->strings->delete_translations( $source_ids, $language->locale ),
			default       => 0,
		};

		if ( $affected > 0 ) {
			DictionaryFactory::invalidate();
		}

		return new WP_REST_Response(
			array(
				'action'   => $action,
				'affected' => $affected,
			)
		);
	}

	/**
	 * Nombres de los estados para los filtros.
	 *
	 * @return array<string, string>
	 */
	private function status_labels(): array {
		$labels = array();

		foreach ( Status::cases() as $status ) {
			$labels[ $status->value ] = $status->label();
		}

		return $labels;
	}

	/**
	 * Nombres de los tipos para los filtros.
	 *
	 * @return array<string, string>
	 */
	private function type_labels(): array {
		$labels = array();

		foreach ( StringType::cases() as $type ) {
			$labels[ $type->value ] = $type->label();
		}

		return $labels;
	}
}
