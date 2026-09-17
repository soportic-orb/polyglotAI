<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Database\Schema
 */
final class SchemaTest extends WP_UnitTestCase {

	/**
	 * Si una tabla existe.
	 *
	 * @param string $name Nombre corto de la tabla.
	 */
	private function table_exists( string $name ): bool {
		global $wpdb;

		$table = Schema::table( $name );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Índices de una tabla, por nombre.
	 *
	 * @param string $name Nombre corto de la tabla.
	 * @return string[]
	 */
	private function index_names( string $name ): array {
		global $wpdb;

		$table = Schema::table( $name );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		// phpcs:enable

		return array_values( array_unique( array_column( (array) $rows, 'Key_name' ) ) );
	}

	/**
	 * Crea las tablas antes de cada test.
	 */
	public function set_up(): void {
		parent::set_up();

		( new Schema() )->install( true );
	}

	public function test_crea_las_cuatro_tablas(): void {
		foreach ( array( 'sources', 'translations', 'slugs', 'api_log' ) as $table ) {
			$this->assertTrue( $this->table_exists( $table ), "Falta la tabla {$table}." );
		}
	}

	public function test_el_hash_de_una_cadena_es_unico(): void {
		$this->assertContains( 'hash', $this->index_names( 'sources' ) );
	}

	public function test_una_cadena_solo_tiene_una_traduccion_por_idioma(): void {
		$this->assertContains( 'source_language', $this->index_names( 'translations' ) );
	}

	public function test_existe_el_indice_que_sirve_el_enrutado_inverso(): void {
		// Sin KEY(language, translated_slug) resolver /en/contact-us/ hasta la
		// entrada cuyo slug original es /contacto/ sería un recorrido completo
		// de la tabla en cada petición.
		$this->assertContains( 'language_translated', $this->index_names( 'slugs' ) );
	}

	public function test_instalar_dos_veces_no_rompe_nada(): void {
		$schema = new Schema();

		$schema->install( true );
		$schema->install( true );

		$this->assertTrue( $this->table_exists( 'sources' ) );
	}

	public function test_las_tablas_llevan_el_prefijo_del_sitio(): void {
		global $wpdb;

		$this->assertStringStartsWith( $wpdb->prefix . 'pgai_', Schema::table( 'sources' ) );
	}

	public function test_borrar_elimina_todas_las_tablas(): void {
		( new Schema() )->drop();

		foreach ( array( 'sources', 'translations', 'slugs', 'api_log' ) as $table ) {
			$this->assertFalse( $this->table_exists( $table ), "La tabla {$table} sigue existiendo." );
		}

		( new Schema() )->install( true );
	}

	public function test_el_texto_original_admite_acentos_y_emoji(): void {
		global $wpdb;

		$repository = new \PolyglotAI\Database\SourceRepository();
		$original   = 'Cafè, ñandú, «cometes» 😀';

		$id    = $repository->remember( md5( $original ), $original, \PolyglotAI\Translation\StringType::Text );
		$table = Schema::table( 'sources' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT original FROM {$table} WHERE id = %d", $id ) );

		$this->assertSame( $original, $stored );
	}
}
