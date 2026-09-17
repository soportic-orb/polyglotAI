<?php
/**
 * Anotación de los slugs que hay que traducir.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Routing;

use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Jobs\SlugTranslator;
use PolyglotAI\Languages\LanguageRegistry;
use WP_Post;
use WP_Term;

/**
 * Mantiene pgai_slugs al día con el contenido del sitio.
 *
 * No traduce nada: solo deja anotado qué hay que traducir. Anotar es una
 * inserción barata y traducir cuesta dinero, así que son dos cosas separadas
 * (mismo criterio que ADR-13 para las cadenas).
 *
 * Las bases reescritas —/category/, /product/— no dependen del contenido sino de
 * los tipos y taxonomías registrados, que son los mismos en toda la instalación.
 * Recorrerlas en cada petición sería una consulta por base y por idioma para no
 * hacer nada el 99,99 % de las veces, así que se guarda una firma de lo
 * registrado y solo se recorren cuando esa firma cambia.
 */
final class SlugSync {

	/**
	 * Opción con la firma del último recorrido de bases.
	 */
	public const BASES_SIGNATURE_OPTION = 'pgai_slug_bases_signature';

	/**
	 * Constructor.
	 *
	 * @param SlugRepository   $slugs     Almacén de slugs.
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 */
	public function __construct(
		private readonly SlugRepository $slugs,
		private readonly LanguageRegistry $languages
	) {}

	/**
	 * Engancha la sincronización.
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'created_term', array( $this, 'on_save_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_save_term' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'delete_term', array( $this, 'on_delete_term' ) );

		// Después de que todo el mundo haya registrado sus tipos y taxonomías.
		add_action( 'wp_loaded', array( $this, 'sync_bases' ) );
	}

	/**
	 * Anota el slug de una entrada al guardarla.
	 *
	 * @param int          $post_id Identificador.
	 * @param WP_Post|null $post    Entrada.
	 */
	public function on_save_post( $post_id, $post = null ): void {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( (int) $post_id );
		}

		if ( ! $post instanceof WP_Post || ! $this->is_trackable_post( $post ) ) {
			return;
		}

		foreach ( $this->languages->translatable() as $language ) {
			if ( $this->slugs->track( 'post', (string) $post->post_type, (int) $post->ID, $language->locale, (string) $post->post_name ) ) {
				$this->schedule( $language->locale );
			}
		}
	}

	/**
	 * Anota el slug de un término al guardarlo.
	 *
	 * @param int    $term_id  Identificador.
	 * @param int    $tt_id    Identificador de la relación. No se usa.
	 * @param string $taxonomy Taxonomía.
	 */
	public function on_save_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		unset( $tt_id );

		$term = get_term( (int) $term_id, (string) $taxonomy );

		if ( ! $term instanceof WP_Term || ! $this->is_public_taxonomy( (string) $taxonomy ) ) {
			return;
		}

		foreach ( $this->languages->translatable() as $language ) {
			if ( $this->slugs->track( 'term', (string) $taxonomy, (int) $term->term_id, $language->locale, (string) $term->slug ) ) {
				$this->schedule( $language->locale );
			}
		}
	}

	/**
	 * Olvida los slugs de una entrada borrada.
	 *
	 * @param int $post_id Identificador.
	 */
	public function on_delete_post( $post_id ): void {
		$this->slugs->delete_for_object( 'post', (int) $post_id );
	}

	/**
	 * Olvida los slugs de un término borrado.
	 *
	 * @param int $term_id Identificador.
	 */
	public function on_delete_term( $term_id ): void {
		$this->slugs->delete_for_object( 'term', (int) $term_id );
	}

	/**
	 * Anota las bases reescritas, si algo ha cambiado desde la última vez.
	 */
	public function sync_bases(): void {
		$bases = $this->current_bases();

		$encoded   = wp_json_encode( array( $bases, $this->locales() ) );
		$signature = md5( is_string( $encoded ) ? $encoded : '' );

		if ( get_option( self::BASES_SIGNATURE_OPTION, '' ) === $signature ) {
			return;
		}

		foreach ( $bases as $subtype => $slug ) {
			foreach ( $this->languages->translatable() as $language ) {
				if ( $this->slugs->track( 'base', (string) $subtype, 0, $language->locale, (string) $slug ) ) {
					$this->schedule( $language->locale );
				}
			}
		}

		update_option( self::BASES_SIGNATURE_OPTION, $signature, true );
	}

	/**
	 * Pide una pasada de traducción de slugs para un idioma.
	 *
	 * Anotar es lo que hace esta clase; traducir cuesta dinero y espera, así
	 * que ocurre fuera de la petición que guardó la entrada.
	 *
	 * @param string $language Locale.
	 */
	private function schedule( string $language ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( SlugTranslator::HOOK, array( $language ), 'polyglot-ai' ) ) {
			return;
		}

		as_enqueue_async_action( SlugTranslator::HOOK, array( $language ), 'polyglot-ai' );
	}

	/**
	 * Bases reescritas de los tipos y taxonomías públicos.
	 *
	 * @return array<string, string> Subtipo => slug de la base.
	 */
	public function current_bases(): array {
		$bases = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$slug = $this->rewrite_slug( $type->rewrite );

			if ( '' !== $slug ) {
				$bases[ 'post_type:' . $type->name ] = $slug;
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$slug = $this->rewrite_slug( $taxonomy->rewrite );

			if ( '' !== $slug ) {
				$bases[ 'taxonomy:' . $taxonomy->name ] = $slug;
			}
		}

		ksort( $bases );

		return $bases;
	}

	/**
	 * Slug de una definición de reescritura, o '' si no tiene.
	 *
	 * @param array<string, mixed>|bool $rewrite Definición.
	 */
	private function rewrite_slug( $rewrite ): string {
		if ( ! is_array( $rewrite ) || ! isset( $rewrite['slug'] ) ) {
			return '';
		}

		// Una base con barras dentro («tienda/productos») no es un segmento y no
		// se traduce por ahora: haría falta partirla y anotar cada tramo.
		$slug = trim( (string) $rewrite['slug'], '/' );

		return str_contains( $slug, '/' ) ? '' : $slug;
	}

	/**
	 * Locales de los idiomas traducibles.
	 *
	 * @return string[]
	 */
	private function locales(): array {
		return array_map(
			static fn ( $language ): string => $language->locale,
			$this->languages->translatable()
		);
	}

	/**
	 * Si la entrada merece una fila en la tabla.
	 *
	 * @param WP_Post $post Entrada.
	 */
	private function is_trackable_post( WP_Post $post ): bool {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return false;
		}

		if ( '' === (string) $post->post_name ) {
			return false;
		}

		$type = get_post_type_object( (string) $post->post_type );

		return null !== $type && (bool) $type->public;
	}

	/**
	 * Si la taxonomía es pública.
	 *
	 * @param string $taxonomy Taxonomía.
	 */
	private function is_public_taxonomy( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );

		return false !== $object && (bool) $object->public;
	}
}
