<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SlugRecord;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\SlugSync;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Routing\SlugSync
 * @covers \PolyglotAI\Database\SlugRepository::track
 */
final class SlugSyncTest extends WP_UnitTestCase {

	private SlugRepository $slugs;
	private SlugSync $sync;

	/**
	 * Dos idiomas traducibles, tabla limpia y sincronización enganchada.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$table}`" );

		delete_option( SlugSync::BASES_SIGNATURE_OPTION );

		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array(
				new Language( 'en_US', 'en', 'English' ),
				new Language( 'ca', 'ca', 'Català' ),
			)
		);

		$this->slugs = new SlugRepository( new StatusPrecedence() );
		$this->sync  = new SlugSync( $this->slugs, $languages );

		$this->sync->register();
	}

	/**
	 * Filas de la tabla.
	 */
	private function rows(): int {
		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	public function test_guardar_una_pagina_la_deja_anotada_en_cada_idioma(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		foreach ( array( 'en_US', 'ca' ) as $locale ) {
			$record = $this->slugs->find( 'post', 'page', $page_id, $locale );

			$this->assertNotNull( $record, "Falta la anotación en {$locale}." );
			$this->assertSame( 'contacto', $record->original_slug );
			$this->assertSame( '', $record->translated_slug );
			$this->assertSame( Status::Pending, $record->status );
		}
	}

	public function test_volver_a_guardar_sin_cambios_no_escribe_nada(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		$before = $this->rows();

		wp_update_post(
			array(
				'ID'         => $page_id,
				'post_title' => 'Otro título',
			)
		);

		$this->assertSame( $before, $this->rows() );
	}

	public function test_cambiar_el_slug_manda_a_retraducir_lo_automatico(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save(
			new SlugRecord( 'post', 'page', $page_id, 'en_US', 'contacto', 'contact-us', Status::Automatic ),
			true
		);

		wp_update_post(
			array(
				'ID'        => $page_id,
				'post_name' => 'contactar',
			)
		);

		$record = $this->slugs->find( 'post', 'page', $page_id, 'en_US' );

		$this->assertNotNull( $record );
		$this->assertSame( 'contactar', $record->original_slug );
		$this->assertSame( '', $record->translated_slug, 'La traducción anterior ya no le corresponde.' );
		$this->assertSame( Status::Pending, $record->status );
	}

	public function test_cambiar_el_slug_no_tira_lo_que_escribio_una_persona(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save(
			new SlugRecord( 'post', 'page', $page_id, 'en_US', 'contacto', 'get-in-touch', Status::Manual )
		);

		wp_update_post(
			array(
				'ID'        => $page_id,
				'post_name' => 'contactar',
			)
		);

		$record = $this->slugs->find( 'post', 'page', $page_id, 'en_US' );

		$this->assertNotNull( $record );
		$this->assertSame( 'contactar', $record->original_slug, 'El original sí se pone al día.' );
		$this->assertSame( 'get-in-touch', $record->translated_slug );
		$this->assertSame( Status::Manual, $record->status );
	}

	public function test_un_termino_tambien_queda_anotado(): void {
		$term_id = self::factory()->category->create( array( 'slug' => 'noticias' ) );

		$record = $this->slugs->find( 'term', 'category', $term_id, 'en_US' );

		$this->assertNotNull( $record );
		$this->assertSame( 'noticias', $record->original_slug );
		$this->assertSame( Status::Pending, $record->status );
	}

	public function test_borrar_una_entrada_olvida_sus_slugs(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		$this->assertNotNull( $this->slugs->find( 'post', 'page', $page_id, 'en_US' ) );

		wp_delete_post( $page_id, true );

		$this->assertNull( $this->slugs->find( 'post', 'page', $page_id, 'en_US' ) );
		$this->assertNull( $this->slugs->find( 'post', 'page', $page_id, 'ca' ) );
	}

	public function test_un_borrador_automatico_no_se_anota(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => '',
				'post_status' => 'auto-draft',
			)
		);

		$this->assertNull( $this->slugs->find( 'post', 'page', $page_id, 'en_US' ) );
	}

	public function test_un_tipo_de_contenido_privado_no_se_anota(): void {
		register_post_type( 'pgai_interno', array( 'public' => false ) );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'pgai_interno',
				'post_name'   => 'algo',
				'post_status' => 'publish',
			)
		);

		$this->assertNull( $this->slugs->find( 'post', 'pgai_interno', $post_id, 'en_US' ) );

		unregister_post_type( 'pgai_interno' );
	}

	public function test_las_bases_reescritas_se_anotan_una_sola_vez(): void {
		// Sin enlaces bonitos las taxonomías del núcleo se registran sin
		// reescritura, y entonces no hay base ninguna que anotar.
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();

		$this->sync->sync_bases();

		$record = $this->slugs->find( 'base', 'taxonomy:category', 0, 'en_US' );

		$this->assertNotNull( $record, 'No se ha anotado la base de las categorías.' );
		$this->assertSame( 'category', $record->original_slug );

		$after = $this->rows();

		// La segunda pasada no tiene nada que hacer y ni siquiera debería mirar.
		$this->sync->sync_bases();

		$this->assertSame( $after, $this->rows() );
	}
}
