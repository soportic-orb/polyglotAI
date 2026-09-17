<?php
/**
 * Traducción de las cadenas de temas y plugins.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Gettext;

use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Languages\Language;
use PolyglotAI\Translation\Hasher;
use PolyglotAI\Translation\Normalizer;
use PolyglotAI\Translation\StringType;

/**
 * Intercepta las cadenas que temas y plugins piden por gettext.
 *
 * Aunque la traducción del HTML final ya cubriría casi todas —acaban en la
 * página—, hacerlo aquí aporta dos cosas que allí se pierden:
 *
 * 1. La cadena llega ANTES del sprintf, con su «%s» todavía como marcador. En
 *    el HTML ya estaría sustituido por el nombre de un usuario concreto, y cada
 *    visitante generaría una cadena distinta que traducir y pagar.
 * 2. Alcanza las cadenas que nunca llegan a una página: correos, cabeceras,
 *    textos que el tema mete en atributos generados por JavaScript.
 *
 * Estos filtros se disparan miles de veces por petición, así que el diccionario
 * se carga UNA vez y se consulta en memoria, y lo no visto se anota en un solo
 * INSERT al final de la petición en lugar de uno por cadena.
 */
final class GettextTranslator {

	/** Grupo de caché de objeto. */
	private const CACHE_GROUP = 'pgai_gettext';

	/** Tope de cadenas nuevas anotadas por petición. */
	private const MAX_RECORDED = 200;

	/**
	 * Traducciones cargadas, por hash.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $dictionary = null;

	/**
	 * Cadenas vistas que aún no están en el diccionario.
	 *
	 * @var array<string, array{text:string, domain:string, context:string|null}>
	 */
	private array $unseen = array();

	/**
	 * Idioma en el que se está traduciendo.
	 *
	 * @var Language|null
	 */
	private ?Language $language = null;

	/**
	 * Textos que este traductor ya ha devuelto traducidos.
	 *
	 * El barrido del HTML los encontrará luego en la página y, al no reconocer
	 * su hash, los anotaría como cadenas nuevas: la misma frase aparecería dos
	 * veces en el gestor, una como gettext y otra como contenido. Con esta lista
	 * el barrido sabe que ya están resueltas.
	 *
	 * @var array<string, true>
	 */
	private array $applied = array();

	/**
	 * Constructor.
	 *
	 * @param SourceRepository      $sources      Repositorio de cadenas.
	 * @param TranslationRepository $translations Repositorio de traducciones.
	 * @param Hasher                $hasher       Calculador de hashes.
	 * @param Normalizer            $normalizer   Normalizador.
	 */
	public function __construct(
		private readonly SourceRepository $sources,
		private readonly TranslationRepository $translations,
		private readonly Hasher $hasher,
		private readonly Normalizer $normalizer
	) {}

	/**
	 * Engancha los filtros de gettext.
	 *
	 * @param Language $language Idioma de destino.
	 */
	public function register( Language $language ): void {
		$this->language = $language;

		add_filter( 'gettext', array( $this, 'filter_gettext' ), 10, 3 );
		add_filter( 'gettext_with_context', array( $this, 'filter_gettext_with_context' ), 10, 4 );
		add_filter( 'ngettext', array( $this, 'filter_ngettext' ), 10, 5 );
		add_filter( 'ngettext_with_context', array( $this, 'filter_ngettext_with_context' ), 10, 6 );

		add_action( 'shutdown', array( $this, 'flush' ), 0 );
		add_filter( 'pgai_record_string', array( $this, 'skip_already_translated' ), 10, 2 );
	}

	/**
	 * Quita los filtros. Lo usa la traducción de correos al terminar.
	 */
	public function unregister(): void {
		remove_filter( 'gettext', array( $this, 'filter_gettext' ), 10 );
		remove_filter( 'gettext_with_context', array( $this, 'filter_gettext_with_context' ), 10 );
		remove_filter( 'ngettext', array( $this, 'filter_ngettext' ), 10 );
		remove_filter( 'ngettext_with_context', array( $this, 'filter_ngettext_with_context' ), 10 );
	}

	/**
	 * Filtro de __() y _e().
	 *
	 * @param string $translation Traducción que devolvería WordPress.
	 * @param string $text        Texto original.
	 * @param string $domain      Dominio.
	 */
	public function filter_gettext( $translation, $text, $domain ): string {
		return $this->lookup( (string) $translation, (string) $domain, null );
	}

	/**
	 * Filtro de _x().
	 *
	 * @param string $translation Traducción que devolvería WordPress.
	 * @param string $text        Texto original.
	 * @param string $context     Contexto.
	 * @param string $domain      Dominio.
	 */
	public function filter_gettext_with_context( $translation, $text, $context, $domain ): string {
		return $this->lookup( (string) $translation, (string) $domain, (string) $context );
	}

	/**
	 * Filtro de _n().
	 *
	 * @param string $translation Forma que devolvería WordPress.
	 * @param string $single      Singular.
	 * @param string $plural      Plural.
	 * @param int    $number      Cantidad.
	 * @param string $domain      Dominio.
	 */
	public function filter_ngettext( $translation, $single, $plural, $number, $domain ): string {
		return $this->lookup( (string) $translation, (string) $domain, null );
	}

	/**
	 * Filtro de _nx().
	 *
	 * @param string $translation Forma que devolvería WordPress.
	 * @param string $single      Singular.
	 * @param string $plural      Plural.
	 * @param int    $number      Cantidad.
	 * @param string $context     Contexto.
	 * @param string $domain      Dominio.
	 */
	public function filter_ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ): string {
		return $this->lookup( (string) $translation, (string) $domain, (string) $context );
	}

	/**
	 * Busca la traducción de una cadena.
	 *
	 * @param string      $text    Texto tal como se mostraría.
	 * @param string      $domain  Dominio.
	 * @param string|null $context Contexto.
	 */
	private function lookup( string $text, string $domain, ?string $context ): string {
		if ( null === $this->language || ! $this->normalizer->is_translatable( $this->normalizer->normalize( $text ) ) ) {
			return $text;
		}

		/**
		 * Permite excluir dominios de gettext enteros.
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $skip   Si se omite el dominio.
		 * @param string $domain Dominio.
		 */
		if ( (bool) apply_filters( 'pgai_skip_gettext_domain', false, $domain ) ) {
			return $text;
		}

		$hash = $this->hasher->hash( $text, StringType::Gettext, $context, $domain );

		if ( isset( $this->dictionary()[ $hash ] ) ) {
			$translated = $this->dictionary()[ $hash ];

			$this->applied[ $this->normalizer->normalize( $translated ) ] = true;

			return $translated;
		}

		if ( count( $this->unseen ) < self::MAX_RECORDED ) {
			$this->unseen[ $hash ] = array(
				'text'    => $text,
				'domain'  => $domain,
				'context' => $context,
			);
		}

		return $text;
	}

	/**
	 * Evita anotar de nuevo una cadena que ya se ha traducido por gettext.
	 *
	 * @param bool                             $record Si se anota.
	 * @param \PolyglotAI\Html\ExtractedString $unit   Unidad encontrada en el HTML.
	 */
	public function skip_already_translated( $record, $unit ): bool {
		if ( ! $record ) {
			return false;
		}

		return ! isset( $this->applied[ $this->normalizer->normalize( $unit->value ) ] );
	}

	/**
	 * Diccionario de gettext del idioma en curso.
	 *
	 * Se carga entero una vez: consultarlo por cadena sería una consulta por
	 * cada llamada a __(), y son miles por petición.
	 *
	 * @return array<string, string>
	 */
	private function dictionary(): array {
		if ( null !== $this->dictionary ) {
			return $this->dictionary;
		}

		$locale = null === $this->language ? '' : $this->language->locale;
		$key    = 'gettext:' . $locale;
		$cached = wp_cache_get( $key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			/** @var array<string, string> $cached */
			$this->dictionary = $cached;

			return $this->dictionary;
		}

		$this->dictionary = $this->translations->lookup_by_type( $locale, StringType::Gettext->value );

		wp_cache_set( $key, $this->dictionary, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $this->dictionary;
	}

	/**
	 * Anota las cadenas nuevas al terminar la petición.
	 *
	 * Se hace aquí y no en cada llamada: un INSERT por cada __() dejaría el
	 * sitio inservible.
	 */
	public function flush(): void {
		if ( array() === $this->unseen || null === $this->language ) {
			return;
		}

		$ids = array();

		foreach ( $this->unseen as $hash => $entry ) {
			$ids[] = $this->sources->remember(
				$hash,
				$entry['text'],
				StringType::Gettext,
				$entry['context'],
				$entry['domain']
			);
		}

		$this->translations->mark_pending( $ids, $this->language->locale );

		$this->unseen = array();
	}
}
