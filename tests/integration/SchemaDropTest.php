<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use WP_UnitTestCase;

/**
 * El borrado de tablas vive en su propia clase porque es destructivo y, al ser
 * DDL, escapa a la transacción con la que WP_UnitTestCase aísla cada test: hay
 * que rehacer el esquema a mano al terminar.
 *
 * @covers \PolyglotAI\Database\Schema
 */
final class SchemaDropTest extends WP_UnitTestCase {

	/**
	 * Rehace el esquema pase lo que pase, para no dejar la suite sin tablas.
	 */
	public function tear_down(): void {
		( new Schema() )->install( true );

		parent::tear_down();
	}

	public function test_borrar_elimina_todas_las_tablas_y_se_puede_rehacer(): void {
		$schema = new Schema();

		$schema->drop();

		$this->assertSame( Schema::names(), $schema->missing_tables(), 'Alguna tabla ha sobrevivido al borrado.' );

		$this->assertSame( array(), $schema->install( true ) );
		$this->assertSame( array(), $schema->missing_tables() );
	}
}
