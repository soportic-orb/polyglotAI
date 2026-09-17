<?php
/**
 * Arranque y ensamblaje del plugin.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI;

use PolyglotAI\Admin\SettingsPage;
use PolyglotAI\Bootstrap\Requirements;
use PolyglotAI\Cli\Commands;
use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Detection\BotDetector;
use PolyglotAI\Editor\AdminBar;
use PolyglotAI\Editor\EditMode;
use PolyglotAI\Editor\EditorPage;
use PolyglotAI\Editor\MarkerDecorator;
use PolyglotAI\Editor\PreviewAs;
use PolyglotAI\Editor\PreviewRenderer;
use PolyglotAI\Engines\Claude\ClaudeClient;
use PolyglotAI\Engines\Claude\ClaudeEngine;
use PolyglotAI\Engines\Claude\PromptBuilder;
use PolyglotAI\Engines\Claude\ResponseParser;
use PolyglotAI\Engines\Claude\RetryPolicy;
use PolyglotAI\Engines\EngineRegistry;
use PolyglotAI\Frontend\DynamicScript;
use PolyglotAI\Gettext\GettextTranslator;
use PolyglotAI\Html\BailConditions;
use PolyglotAI\Html\DocumentProcessor;
use PolyglotAI\Html\DriverFactory;
use PolyglotAI\Html\Escaper;
use PolyglotAI\Html\ExclusionRules;
use PolyglotAI\Html\OutputBuffer;
use PolyglotAI\Html\SafetyCheck;
use PolyglotAI\Html\Splicer;
use PolyglotAI\Html\TagScanner;
use PolyglotAI\Jobs\PendingTranslator;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Languages\UserLanguage;
use PolyglotAI\Mail\LanguageResolver;
use PolyglotAI\Mail\MailTranslator;
use PolyglotAI\Rest\DynamicController;
use PolyglotAI\Rest\MergesController;
use PolyglotAI\Rest\SlugsController;
use PolyglotAI\Seo\HeadUrls;
use PolyglotAI\Seo\StructuredData;
use PolyglotAI\Rest\StringsController;
use PolyglotAI\Rest\SuggestController;
use PolyglotAI\Routing\HeadTags;
use PolyglotAI\Routing\InternalUrl;
use PolyglotAI\Routing\LinkRewriter;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Routing\PermalinkTranslator;
use PolyglotAI\Routing\RequestRouter;
use PolyglotAI\Routing\SlugSync;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Support\ApiKey;
use PolyglotAI\Support\Options;
use PolyglotAI\Switcher\Shortcode;
use PolyglotAI\Translation\DictionaryFactory;
use PolyglotAI\Translation\Hasher;
use PolyglotAI\Html\MergingDriver;
use PolyglotAI\Translation\MergeRegistry;
use PolyglotAI\Translation\MissingQueue;
use PolyglotAI\Translation\Normalizer;
use PolyglotAI\Translation\StatusPrecedence;
use PolyglotAI\Translation\TranslationLookup;
use PolyglotAI\Translation\Validator;

/**
 * Contenedor y punto de arranque.
 *
 * El grafo de objetos se construye a mano y de forma perezosa. No se usa ningún
 * contenedor de inyección de dependencias: sería una dependencia más en el
 * vendor de un sitio ajeno a cambio de resolver un problema que aquí no existe.
 */
final class Plugin {

	/**
	 * Instancia única.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Servicios ya construidos.
	 *
	 * @var array<string, mixed>
	 */
	private array $services = array();

	/**
	 * Constructor privado.
	 */
	private function __construct() {}

	/**
	 * Instancia única.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Arranca el plugin.
	 */
	public function boot(): void {
		$requirements = new Requirements( PGAI_MIN_PHP, PGAI_MIN_WP );

		if ( ! $requirements->are_met() ) {
			add_action( 'admin_notices', array( $requirements, 'show_notice' ) );

			return;
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'register_services' ), 5 );
	}

	/**
	 * Carga las traducciones de la interfaz del propio plugin.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'polyglot-ai', false, dirname( plugin_basename( PGAI_FILE ) ) . '/languages' );
	}

	/**
	 * Engancha los servicios que actúan sobre las peticiones.
	 */
	public function register_services(): void {
		// Lo primero de todo: WordPress no sabe nada de /en/, así que hay que
		// quitarle el prefijo a la petición antes de que la analice.
		( new RequestRouter( $this->languages(), $this->url_converter(), $this->request(), $this->slug_resolver() ) )->register();

		// Y la vuelta: los enlaces que genere WordPress tienen que apuntar al
		// slug traducido, también los que no pasan por el HTML de la página.
		$this->permalink_translator()->register();

		// Y quien anota qué slugs quedan por traducir. Va tanto en el escritorio
		// como en el frente: las entradas se guardan en el escritorio.
		$this->slug_sync()->register();

		if ( is_admin() ) {
			$this->settings_page()->register();
			$this->editor_page()->register();
		}

		$this->switcher()->register();
		$this->admin_bar()->register();

		if ( ! is_admin() ) {
			( new DynamicScript( $this->request(), $this->options() ) )->register();
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Los correos se traducen siempre, también los que dispara el
		// escritorio: el idioma del correo es el del destinatario.
		$this->mail_translator()->register();

		( new UserLanguage( $this->request(), $this->languages() ) )->register();

		// La traducción en segundo plano se registra siempre, también cuando no
		// hay driver: puede haber cadenas pendientes de una visita anterior.
		$this->pending_translator()->register();

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( \WP_CLI::class ) ) {
			$this->commands()->register();
		}

		// Las cadenas de temas y plugins se traducen en el frontal, donde las ve
		// el visitante. En el escritorio se dejan como están: traducir la
		// interfaz de administración confundiría a quien la usa.
		if ( ! is_admin() && ! $this->request()->is_default() ) {
			$this->gettext()->register( $this->request()->language() );
		}

		// Sin driver viable no se procesa nada: es preferible servir el sitio
		// sin traducir a servirlo corrupto (ver DriverFactory).
		if ( null === $this->driver() ) {
			return;
		}

		// La vista previa del editor fija el idioma que se está traduciendo
		// antes de que nada resuelva la URL.
		add_action( 'template_redirect', array( $this->edit_mode(), 'apply_language' ), 0 );

		// Después de que el modo de edición haya quedado comprobado: quitarle
		// los permisos al usuario antes cerraría la propia vista previa.
		add_action( 'template_redirect', array( new PreviewAs( $this->edit_mode() ), 'apply' ), 2 );
		add_action( 'wp_enqueue_scripts', array( $this->preview(), 'enqueue' ) );

		$this->head_tags()->register();
		$this->output_buffer()->register();
	}

	/**
	 * Registra los endpoints REST.
	 */
	public function register_rest_routes(): void {
		( new StringsController(
			$this->languages(),
			$this->sources(),
			$this->translations(),
			new Validator()
		) )->register_routes();

		( new MergesController( $this->languages(), $this->merges() ) )->register_routes();

		( new SlugsController(
			$this->languages(),
			$this->slugs(),
			$this->slug_resolver(),
			$this->url_converter()
		) )->register_routes();

		( new DynamicController(
			$this->languages(),
			$this->translations(),
			$this->hasher(),
			$this->normalizer()
		) )->register_routes();

		( new SuggestController(
			$this->languages(),
			$this->engine(),
			$this->sources(),
			$this->translations(),
			$this->api_log(),
			$this->pending_translator(),
			$this->options()
		) )->register_routes();
	}

	/**
	 * Recupera o construye un servicio.
	 *
	 * @param string   $key     Clave del servicio.
	 * @param callable $factory Constructor.
	 * @return mixed
	 */
	private function service( string $key, callable $factory ): mixed {
		if ( ! array_key_exists( $key, $this->services ) ) {
			$this->services[ $key ] = $factory();
		}

		return $this->services[ $key ];
	}

	/**
	 * Ajustes.
	 */
	public function options(): Options {
		return $this->service( 'options', static fn(): Options => new Options() );
	}

	/**
	 * Idiomas configurados.
	 */
	public function languages(): LanguageRegistry {
		return $this->service(
			'languages',
			function (): LanguageRegistry {
				$options = $this->options();

				/** @var array<string, mixed> $default */
				$default = (array) $options->get( 'default_language', array() );

				/** @var array<int, array<string, mixed>> $additional */
				$additional = (array) $options->get( 'languages', array() );

				return new LanguageRegistry(
					Language::from_array( $default ),
					array_map( static fn( array $data ): Language => Language::from_array( $data ), $additional )
				);
			}
		);
	}

	/**
	 * Conversor de rutas entre idiomas.
	 */
	public function url_converter(): UrlConverter {
		return $this->service(
			'url_converter',
			function (): UrlConverter {
				$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

				return new UrlConverter(
					$this->languages(),
					'' === $base ? '/' : $base,
					(bool) $this->options()->get( 'prefix_default', false )
				);
			}
		);
	}

	/**
	 * Contexto de la petición.
	 */
	public function request(): RequestContext {
		return $this->service(
			'request',
			fn(): RequestContext => new RequestContext( $this->languages(), $this->url_converter() )
		);
	}

	/**
	 * Driver de análisis de HTML, o null si ninguno es viable.
	 */
	public function driver(): ?Html\DocumentDriverInterface {
		return $this->service(
			'driver',
			function (): ?Html\DocumentDriverInterface {
				/** @var string[] $classes */
				$classes = (array) apply_filters( 'pgai_exclusion_classes', array( 'notranslate' ) );

				$driver = ( new DriverFactory() )->create( new TagScanner(), new ExclusionRules( $classes ) );

				if ( null === $driver ) {
					return null;
				}

				// La fusión de bloques va por fuera del extractor: extraer es
				// una operación sobre el documento y fusionar es una decisión
				// del traductor.
				return new MergingDriver( $driver, $this->merges(), $this->hasher() );
			}
		);
	}

	/**
	 * Motor de traducción activo.
	 */
	public function engine(): Engines\TranslationEngineInterface {
		return $this->engines()->get( (string) $this->options()->get( 'engine', 'anthropic' ) );
	}

	/**
	 * Registro de motores.
	 */
	public function engines(): EngineRegistry {
		return $this->service(
			'engines',
			function (): EngineRegistry {
				$registry = new EngineRegistry();
				$options  = $this->options();

				$registry->register(
					new ClaudeEngine(
						new ClaudeClient( new ApiKey(), new RetryPolicy() ),
						new PromptBuilder(
							(string) $options->get( 'model', 'claude-sonnet-5' ),
							(string) $options->get( 'effort', 'low' ),
							(bool) $options->get( 'thinking', false ),
							(int) $options->get( 'cache_ttl', 5 )
						),
						new ResponseParser( new Validator() )
					)
				);

				/**
				 * Permite registrar motores de traducción adicionales.
				 *
				 * @since 0.1.0
				 *
				 * @param EngineRegistry $registry Registro de motores.
				 */
				do_action( 'pgai_register_engines', $registry );

				return $registry;
			}
		);
	}

	/**
	 * Calculador de hashes.
	 */
	public function hasher(): Hasher {
		return $this->service( 'hasher', fn(): Hasher => new Hasher( $this->normalizer() ) );
	}

	/**
	 * Normalizador de cadenas.
	 */
	public function normalizer(): Normalizer {
		return $this->service( 'normalizer', static fn(): Normalizer => new Normalizer() );
	}

	/**
	 * Repositorio de cadenas originales.
	 */
	public function sources(): SourceRepository {
		return $this->service( 'sources', static fn(): SourceRepository => new SourceRepository() );
	}

	/**
	 * Repositorio de traducciones.
	 */
	public function translations(): TranslationRepository {
		return $this->service(
			'translations',
			static fn(): TranslationRepository => new TranslationRepository( new StatusPrecedence() )
		);
	}

	/**
	 * Repositorio de slugs traducidos.
	 */
	public function slugs(): SlugRepository {
		return $this->service(
			'slugs',
			static fn(): SlugRepository => new SlugRepository( new StatusPrecedence() )
		);
	}

	/**
	 * Enrutado de slugs traducidos.
	 */
	public function slug_resolver(): SlugResolver {
		return $this->service(
			'slug_resolver',
			fn(): SlugResolver => new SlugResolver( $this->slugs() )
		);
	}

	/**
	 * Conversor de URLs internas.
	 */
	public function internal_urls(): InternalUrl {
		return $this->service(
			'internal_urls',
			fn(): InternalUrl => new InternalUrl(
				$this->url_converter(),
				(string) wp_parse_url( home_url(), PHP_URL_HOST )
			)
		);
	}

	/**
	 * Traductor de enlaces permanentes.
	 */
	public function permalink_translator(): PermalinkTranslator {
		return $this->service(
			'permalink_translator',
			fn(): PermalinkTranslator => new PermalinkTranslator( $this->slugs(), $this->request() )
		);
	}

	/**
	 * Sincronización de slugs con el contenido.
	 */
	public function slug_sync(): SlugSync {
		return $this->service(
			'slug_sync',
			fn(): SlugSync => new SlugSync( $this->slugs(), $this->languages() )
		);
	}

	/**
	 * Registro de consumo de la API.
	 */
	public function api_log(): ApiLogRepository {
		return $this->service( 'api_log', static fn(): ApiLogRepository => new ApiLogRepository() );
	}

	/**
	 * Constructor de diccionarios por página.
	 */
	public function dictionary(): DictionaryFactory {
		return $this->service(
			'dictionary',
			fn(): DictionaryFactory => new DictionaryFactory( $this->translations(), $this->hasher(), $this->normalizer() )
		);
	}

	/**
	 * Captura de la salida del frontal.
	 *
	 * @throws \LogicException Si no hay ningún driver de análisis disponible.
	 */
	private function output_buffer(): OutputBuffer {
		$driver = $this->driver();

		if ( null === $driver ) {
			throw new \LogicException( 'No se puede capturar la salida sin un driver de análisis.' );
		}

		return $this->service(
			'output_buffer',
			fn(): OutputBuffer => new OutputBuffer(
				new BailConditions( $this->options() ),
				$this->request(),
				new DocumentProcessor( $driver, new Splicer(), new Escaper(), new SafetyCheck() ),
				$driver,
				$this->dictionary(),
				$this->missing_queue(),
				new LinkRewriter( $this->internal_urls(), new TagScanner(), new Splicer() ),
				new HeadUrls( $this->internal_urls(), new TagScanner(), new Splicer() ),
				new StructuredData( $this->lookup(), $this->internal_urls(), new TagScanner(), new Splicer() ),
				$this->preview()
			)
		);
	}

	/**
	 * Búsqueda de traducciones de cadenas sueltas.
	 */
	public function lookup(): TranslationLookup {
		return $this->service(
			'lookup',
			fn(): TranslationLookup => new TranslationLookup(
				$this->sources(),
				$this->translations(),
				$this->hasher(),
				$this->normalizer()
			)
		);
	}

	/**
	 * Traductor de correos salientes.
	 */
	public function mail_translator(): MailTranslator {
		return $this->service(
			'mail_translator',
			fn(): MailTranslator => new MailTranslator(
				new LanguageResolver( $this->languages() ),
				$this->languages(),
				new DocumentProcessor(
					$this->driver() ?? new Html\HtmlApiDriver( new TagScanner(), new ExclusionRules() ),
					new Splicer(),
					new Escaper(),
					new SafetyCheck()
				),
				$this->lookup()
			)
		);
	}

	/**
	 * Ejecuta un bloque de código en otro idioma.
	 *
	 * Restaura el idioma anterior pase lo que pase: si el bloque lanza una
	 * excepción y no se restaurara, el resto de la petición se serviría en el
	 * idioma equivocado.
	 *
	 * @param string   $locale   Locale de destino.
	 * @param callable $callback Código a ejecutar.
	 * @return mixed
	 */
	public function with_language( string $locale, callable $callback ) {
		$language = $this->languages()->by_locale( $locale );

		if ( null === $language ) {
			return $callback();
		}

		$previous = $this->request()->language();

		$this->request()->force( $language );
		$this->gettext()->register( $language );

		try {
			return $callback();
		} finally {
			$this->gettext()->unregister();
			$this->request()->force( $previous );
		}
	}

	/**
	 * Traductor de las cadenas de temas y plugins.
	 */
	public function gettext(): GettextTranslator {
		return $this->service(
			'gettext',
			fn(): GettextTranslator => new GettextTranslator(
				$this->sources(),
				$this->translations(),
				$this->hasher(),
				$this->normalizer()
			)
		);
	}

	/**
	 * Registro de bloques de traducción fusionados.
	 */
	public function merges(): MergeRegistry {
		return $this->service( 'merges', static fn(): MergeRegistry => new MergeRegistry() );
	}

	/**
	 * Detector del modo de edición.
	 */
	public function edit_mode(): EditMode {
		return $this->service(
			'edit_mode',
			fn(): EditMode => new EditMode( $this->languages(), $this->request() )
		);
	}

	/**
	 * Vista previa del editor visual.
	 */
	public function preview(): PreviewRenderer {
		return $this->service(
			'preview',
			fn(): PreviewRenderer => new PreviewRenderer(
				$this->edit_mode(),
				new MarkerDecorator( $this->hasher() ),
				$this->missing_queue()
			)
		);
	}

	/**
	 * Registro de cadenas pendientes.
	 */
	private function missing_queue(): MissingQueue {
		return $this->service(
			'missing_queue',
			fn(): MissingQueue => new MissingQueue(
				$this->sources(),
				$this->translations(),
				new BotDetector(),
				$this->options()
			)
		);
	}

	/**
	 * Pantalla del editor visual.
	 */
	private function editor_page(): EditorPage {
		return $this->service(
			'editor_page',
			fn(): EditorPage => new EditorPage( $this->languages(), $this->edit_mode() )
		);
	}

	/**
	 * Botón del editor en la barra de administración.
	 */
	private function admin_bar(): AdminBar {
		return $this->service(
			'admin_bar',
			fn(): AdminBar => new AdminBar(
				$this->languages(),
				$this->url_converter(),
				$this->edit_mode(),
				$this->request()
			)
		);
	}

	/**
	 * Traductor de las cadenas pendientes en segundo plano.
	 */
	public function pending_translator(): PendingTranslator {
		return $this->service(
			'pending_translator',
			fn(): PendingTranslator => new PendingTranslator(
				$this->engine(),
				$this->translations(),
				$this->api_log(),
				$this->languages(),
				$this->options()
			)
		);
	}

	/**
	 * Comandos de WP-CLI.
	 */
	private function commands(): Commands {
		return $this->service(
			'commands',
			fn(): Commands => new Commands(
				$this->engine(),
				$this->translations(),
				$this->api_log(),
				$this->languages(),
				$this->options(),
				new ApiKey(),
				$this->pending_translator()
			)
		);
	}

	/**
	 * Etiquetas de idioma de la cabecera.
	 */
	private function head_tags(): HeadTags {
		return $this->service(
			'head_tags',
			fn(): HeadTags => new HeadTags( $this->languages(), $this->request(), $this->url_converter(), $this->slug_resolver() )
		);
	}

	/**
	 * Selector de idioma.
	 */
	private function switcher(): Shortcode {
		return $this->service(
			'switcher',
			fn(): Shortcode => new Shortcode( $this->languages(), $this->request(), $this->url_converter() )
		);
	}

	/**
	 * Pantalla de ajustes.
	 */
	private function settings_page(): SettingsPage {
		return $this->service(
			'settings_page',
			fn(): SettingsPage => new SettingsPage( $this->options(), new ApiKey(), $this->engines(), $this->languages() )
		);
	}
}
