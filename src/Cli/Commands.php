<?php
/**
 * Comandos de WP-CLI.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Cli;

use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Engines\EngineContext;
use PolyglotAI\Engines\EngineException;
use PolyglotAI\Engines\TranslationEngineInterface;
use PolyglotAI\Engines\TranslationRequest;
use PolyglotAI\Jobs\PendingTranslator;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\ApiKey;
use PolyglotAI\Support\Options;
use PolyglotAI\Translation\StringType;
use WP_CLI;

/**
 * Herramientas de línea de comandos.
 *
 * El comando `test` existe porque la traducción real depende de una clave de API
 * y de una llamada a un servicio externo: ningún test automático puede cubrir
 * ese camino sin gastar dinero de verdad. Esto permite comprobarlo en un
 * segundo, con una sola cadena, y ver qué se ha consumido.
 */
final class Commands {

	/**
	 * Constructor.
	 *
	 * @param TranslationEngineInterface $engine       Motor de traducción.
	 * @param TranslationRepository      $translations Repositorio de traducciones.
	 * @param ApiLogRepository           $log          Registro de consumo.
	 * @param LanguageRegistry           $languages    Idiomas del sitio.
	 * @param Options                    $options      Ajustes.
	 * @param ApiKey                     $api_key      Custodia de la clave.
	 * @param PendingTranslator          $pending      Traductor en segundo plano.
	 */
	public function __construct(
		private readonly TranslationEngineInterface $engine,
		private readonly TranslationRepository $translations,
		private readonly ApiLogRepository $log,
		private readonly LanguageRegistry $languages,
		private readonly Options $options,
		private readonly ApiKey $api_key,
		private readonly PendingTranslator $pending
	) {}

	/**
	 * Registra los comandos.
	 */
	public function register(): void {
		WP_CLI::add_command( 'pgai test', array( $this, 'test' ) );
		WP_CLI::add_command( 'pgai translate', array( $this, 'translate' ) );
		WP_CLI::add_command( 'pgai status', array( $this, 'status' ) );
	}

	/**
	 * Comprueba que la traducción automática funciona de extremo a extremo.
	 *
	 * ## OPTIONS
	 *
	 * [--language=<locale>]
	 * : Idioma de destino. Por defecto, el primero configurado.
	 *
	 * [--text=<texto>]
	 * : Cadena a traducir. Por defecto, una frase de prueba con marcado y un
	 * marcador de posición, para verificar también la validación estructural.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pgai test --language=en_US
	 *
	 * @param string[]              $args       Argumentos posicionales.
	 * @param array<string, string> $assoc_args Argumentos con nombre.
	 */
	public function test( array $args, array $assoc_args ): void {
		if ( ! $this->api_key->exists() ) {
			WP_CLI::error( 'No hay ninguna clave de API configurada. Define PGAI_API_KEY en wp-config.php.' );
		}

		$language = $this->resolve_language( $assoc_args['language'] ?? null );
		$text     = $assoc_args['text'] ?? 'Hola, <strong>%s</strong>. Bienvenido a nuestra tienda.';

		WP_CLI::log( sprintf( 'Modelo: %s', (string) $this->options->get( 'model' ) ) );
		WP_CLI::log( sprintf( 'Idioma: %s -> %s', $this->languages->default_language()->locale, $language ) );
		WP_CLI::log( sprintf( 'Original: %s', $text ) );

		$started = microtime( true );

		try {
			$result = $this->engine->translate(
				array( new TranslationRequest( '1', $text, StringType::Block ) ),
				$this->context( $language )
			);
		} catch ( EngineException $error ) {
			WP_CLI::error( sprintf( 'La llamada ha fallado: %s', $error->getMessage() ) );

			// WP_CLI::error termina la ejecución, pero dejarlo explícito evita
			// que el lector (y el análisis estático) tenga que saberlo.
			return;
		}

		$elapsed = ( microtime( true ) - $started ) * 1000;

		if ( isset( $result->failures['1'] ) ) {
			WP_CLI::warning( sprintf( 'La traducción no ha pasado la validación estructural: %s', $result->failures['1'] ) );
			WP_CLI::warning( 'Se habría descartado y marcado como error, sirviéndose el original.' );
		}

		if ( isset( $result->translations['1'] ) ) {
			WP_CLI::log( sprintf( 'Traducción: %s', $result->translations['1'] ) );
		}

		WP_CLI::log( sprintf( 'Tiempo: %.0f ms', $elapsed ) );
		WP_CLI::log(
			sprintf(
				'Tokens: %d de entrada, %d de salida, %d leídos de caché, %d escritos en caché.',
				$result->usage->input_tokens,
				$result->usage->output_tokens,
				$result->usage->cache_read_tokens,
				$result->usage->cache_creation_tokens
			)
		);

		if ( ! $result->usage->used_cache() ) {
			// La API no avisa de esto: el único síntoma es un cero.
			WP_CLI::log( 'Aviso: la caché de prompt no ha servido nada. En la primera llamada es normal; si se repite, el prompt del sistema no llega al mínimo cacheable del modelo.' );
		}

		if ( $result->is_empty() ) {
			WP_CLI::error( 'No se ha obtenido ninguna traducción utilizable.' );
		}

		WP_CLI::success( 'La traducción automática funciona.' );
	}

	/**
	 * Traduce las cadenas pendientes de un idioma.
	 *
	 * ## OPTIONS
	 *
	 * [--language=<locale>]
	 * : Idioma de destino. Por defecto, todos los activos.
	 *
	 * [--batches=<n>]
	 * : Número máximo de lotes. Por defecto 10.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pgai translate --language=en_US --batches=50
	 *
	 * @param string[]              $args       Argumentos posicionales.
	 * @param array<string, string> $assoc_args Argumentos con nombre.
	 */
	public function translate( array $args, array $assoc_args ): void {
		$batches   = max( 1, (int) ( $assoc_args['batches'] ?? 10 ) );
		$languages = isset( $assoc_args['language'] )
			? array( $assoc_args['language'] )
			: array_map( static fn( $language ) => $language->locale, $this->languages->translatable() );

		foreach ( $languages as $language ) {
			$total = 0;

			for ( $batch = 0; $batch < $batches; $batch++ ) {
				if ( $this->pending->over_budget() ) {
					WP_CLI::warning( 'Se ha alcanzado el tope mensual de tokens.' );
					break;
				}

				$saved = $this->pending->run( $language );

				if ( 0 === $saved ) {
					break;
				}

				$total += $saved;
				WP_CLI::log( sprintf( '%s: %d cadenas traducidas (total %d).', $language, $saved, $total ) );
			}

			WP_CLI::success( sprintf( '%s: %d cadenas traducidas.', $language, $total ) );
		}
	}

	/**
	 * Muestra el progreso de traducción y el consumo del mes.
	 *
	 * @param string[]              $args       Argumentos posicionales.
	 * @param array<string, string> $assoc_args Argumentos con nombre.
	 */
	public function status( array $args, array $assoc_args ): void {
		$rows = array();

		foreach ( $this->languages->translatable() as $language ) {
			$counts = $this->translations->counts( $language->locale );
			$total  = array_sum( $counts );

			$rows[] = array(
				'idioma'     => $language->locale,
				'total'      => $total,
				'traducidas' => ( $counts['automatic'] ?? 0 ) + ( $counts['reviewed'] ?? 0 ) + ( $counts['manual'] ?? 0 ),
				'manuales'   => ( $counts['manual'] ?? 0 ) + ( $counts['reviewed'] ?? 0 ),
				'pendientes' => $counts['pending'] ?? 0,
				'errores'    => $counts['error'] ?? 0,
			);
		}

		if ( array() === $rows ) {
			WP_CLI::warning( 'No hay idiomas adicionales configurados.' );

			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'idioma', 'total', 'traducidas', 'manuales', 'pendientes', 'errores' ) );

		$usage = $this->log->usage_since( gmdate( 'Y-m-01 00:00:00' ) );

		WP_CLI::log(
			sprintf(
				'Consumo del mes: %d tokens de entrada, %d de salida, %d leídos de caché.',
				$usage['input'],
				$usage['output'],
				$usage['cache_read']
			)
		);
	}

	/**
	 * Resuelve el idioma de destino.
	 *
	 * @param string|null $requested Locale pedido.
	 */
	private function resolve_language( ?string $requested ): string {
		if ( null !== $requested ) {
			return $requested;
		}

		$translatable = $this->languages->translatable();

		if ( array() === $translatable ) {
			WP_CLI::error( 'No hay idiomas adicionales configurados. Añade uno en Polyglot AI -> Ajustes.' );
		}

		return $translatable[0]->locale;
	}

	/**
	 * Contexto lingüístico para un idioma de destino.
	 *
	 * @param string $language Locale.
	 */
	private function context( string $language ): EngineContext {
		$source = $this->languages->default_language();
		$target = $this->languages->by_locale( $language ) ?? $source;

		return new EngineContext(
			$source->locale,
			$target->locale,
			$source->label,
			$target->label,
			$target->formality,
			(string) $this->options->get( 'site_context', '' ),
			$this->options->glossary( $language ),
			$this->options->do_not_translate()
		);
	}
}
