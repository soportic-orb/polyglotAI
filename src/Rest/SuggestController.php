<?php
/**
 * Endpoint de sugerencia automática de traducciones.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Rest;

use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Engines\EngineContext;
use PolyglotAI\Engines\EngineException;
use PolyglotAI\Engines\TranslationEngineInterface;
use PolyglotAI\Engines\TranslationRequest;
use PolyglotAI\Jobs\PendingTranslator;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Support\Options;
use PolyglotAI\Translation\DictionaryFactory;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StringType;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Traduce cadenas concretas a petición del editor visual.
 *
 * Exige la capacidad de lanzar traducción automática, no la de traducir: esto
 * gasta presupuesto de API y no tiene por qué poder hacerlo cualquiera que
 * pueda corregir un texto.
 */
final class SuggestController extends Controller {

	/** Cadenas por petición, para que una pulsación no dispare un lote enorme. */
	private const MAX_STRINGS = 60;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry           $languages    Idiomas del sitio.
	 * @param TranslationEngineInterface $engine       Motor de traducción.
	 * @param SourceRepository           $sources      Repositorio de cadenas.
	 * @param TranslationRepository      $translations Repositorio de traducciones.
	 * @param ApiLogRepository           $log          Registro de consumo.
	 * @param PendingTranslator          $pending      Traductor en segundo plano.
	 * @param Options                    $options      Ajustes.
	 */
	public function __construct(
		LanguageRegistry $languages,
		private readonly TranslationEngineInterface $engine,
		private readonly SourceRepository $sources,
		private readonly TranslationRepository $translations,
		private readonly ApiLogRepository $log,
		private readonly PendingTranslator $pending,
		private readonly Options $options
	) {
		parent::__construct( $languages );
	}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/suggest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => $this->requires( Capabilities::RUN_AUTO ),
				'args'                => array_merge(
					$this->language_argument(),
					array(
						'hashes' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
						),
						'save'   => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Si se guardan las traducciones además de devolverlas.', 'polyglot-ai' ),
						),
					)
				),
			)
		);
	}

	/**
	 * Traduce las cadenas pedidas.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function suggest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$language = $this->language_from( $request );

		if ( $language instanceof WP_Error ) {
			return $language;
		}

		if ( $this->pending->over_budget() ) {
			return new WP_Error(
				'pgai_budget_exhausted',
				__( 'Se ha alcanzado el tope mensual de tokens configurado.', 'polyglot-ai' ),
				array( 'status' => 429 )
			);
		}

		/** @var string[] $hashes */
		$hashes  = array_slice( array_unique( array_map( 'strval', (array) $request->get_param( 'hashes' ) ) ), 0, self::MAX_STRINGS );
		$details = $this->sources->details_by_hash( $hashes, $language->locale );

		if ( array() === $details ) {
			return new WP_REST_Response(
				array(
					'suggestions' => array(),
					'failures'    => array(),
				)
			);
		}

		$requests = array();
		$by_hash  = array();

		foreach ( $details as $hash => $detail ) {
			$id             = (string) $detail['source_id'];
			$by_hash[ $id ] = $hash;
			$requests[]     = new TranslationRequest(
				$id,
				(string) $detail['original'],
				StringType::tryFrom( (string) $detail['type'] ) ?? StringType::Text,
				$detail['context']
			);
		}

		try {
			$result = $this->engine->translate( $requests, $this->context( $language->locale ) );
		} catch ( EngineException $error ) {
			$this->log->record(
				$this->engine->id(),
				(string) $this->options->get( 'model' ),
				$language->locale,
				count( $requests ),
				new \PolyglotAI\Engines\Usage(),
				'error',
				$error->getMessage()
			);

			return new WP_Error(
				'pgai_engine_failed',
				$error->getMessage(),
				array( 'status' => $error->retryable ? 503 : 502 )
			);
		}

		$save        = (bool) $request->get_param( 'save' );
		$suggestions = array();
		$failures    = array();

		foreach ( $result->translations as $id => $translation ) {
			$hash = $by_hash[ (string) $id ] ?? null;

			if ( null === $hash ) {
				continue;
			}

			$suggestions[ $hash ] = $translation;

			if ( $save ) {
				// Guardar respeta la precedencia de estados: una sugerencia
				// automática no pisa una corrección manual ni aunque el
				// traductor pulse «traducir todo».
				$this->translations->save(
					(int) $id,
					$language->locale,
					$translation,
					Status::Automatic,
					false,
					$this->engine->id(),
					(string) $this->options->get( 'model' )
				);
			}
		}

		foreach ( $result->failures as $id => $reason ) {
			$hash = $by_hash[ (string) $id ] ?? null;

			if ( null !== $hash ) {
				$failures[ $hash ] = $reason;
			}
		}

		$this->log->record(
			$this->engine->id(),
			(string) $this->options->get( 'model' ),
			$language->locale,
			count( $requests ),
			$result->usage
		);

		if ( $save && array() !== $suggestions ) {
			DictionaryFactory::invalidate();
		}

		return new WP_REST_Response(
			array(
				'suggestions' => $suggestions,
				'failures'    => $failures,
				'usage'       => array(
					'input'     => $result->usage->input_tokens,
					'output'    => $result->usage->output_tokens,
					'cacheRead' => $result->usage->cache_read_tokens,
				),
			)
		);
	}

	/**
	 * Contexto lingüístico para un idioma de destino.
	 *
	 * @param string $locale Locale.
	 */
	private function context( string $locale ): EngineContext {
		$source = $this->languages->default_language();
		$target = $this->languages->by_locale( $locale ) ?? $source;

		return new EngineContext(
			$source->locale,
			$target->locale,
			$source->label,
			$target->label,
			$target->formality,
			(string) $this->options->get( 'site_context', '' ),
			$this->options->glossary( $locale ),
			$this->options->do_not_translate()
		);
	}
}
