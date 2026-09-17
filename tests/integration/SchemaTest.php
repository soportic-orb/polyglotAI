<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Translation\StringType;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Database\Schema
 */
final class SchemaTest extends WP_UnitTestCase {

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
	 * Vacía las tablas antes de cada test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		foreach ( Schema::names() as $name ) {
			$table = Schema::table( $name );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM `{$table}`" );
		}
	}

	public function test_no_falta_ninguna_tabla(): void {
		// dbDelta no informa de sus fallos: si no sabe analizar una sentencia,
		// simplemente no crea la tabla y no dice nada.
		$this->assertSame( array(), ( new Schema() )->missing_tables() );
	}

	public function test_instalar_es_idempotente(): void {
		$schema = new Schema();

		$this->assertSame( array(), $schema->install( true ) );
		$this->assertSame( array(), $schema->install( true ) );
		$this->assertSame( array(), $schema->missing_tables() );
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

	public function test_las_tablas_llevan_el_prefijo_del_sitio(): void {
		global $wpdb;

		$this->assertStringStartsWith( $wpdb->prefix . 'pgai_', Schema::table( 'sources' ) );
	}

	public function test_el_texto_original_admite_acentos_y_emoji(): void {
		global $wpdb;

		$original = 'Cafè, ñandú, «cometes» 😀';
		$id       = ( new SourceRepository() )->remember( md5( $original ), $original, StringType::Text );

		$this->assertGreaterThan( 0, $id, 'La cadena no se ha llegado a insertar.' );

		$table = Schema::table( 'sources' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT original FROM {$table} WHERE id = %d", $id ) );

		$this->assertSame( $original, $stored );
	}
}
