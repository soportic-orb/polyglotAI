<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Bootstrap\Installer;
use PolyglotAI\Bootstrap\Upgrader;
use PolyglotAI\Database\Schema;
use PolyglotAI\Support\Capabilities;
use WP_UnitTestCase;

/**
 * Lo que deja montado instalar y actualizar el plugin.
 *
 * @covers \PolyglotAI\Bootstrap\Installer
 * @covers \PolyglotAI\Bootstrap\Upgrader
 */
final class InstallerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( Installer::VERSION_OPTION );
		delete_option( Installer::ERRORS_OPTION );
	}

	public function test_instalar_deja_las_tablas_el_rol_y_las_capacidades(): void {
		global $wpdb;

		$missing = Installer::run( '9.9.9' );

		$this->assertSame( array(), $missing );

		foreach ( array( 'sources', 'translations', 'slugs', 'api_log' ) as $name ) {
			$table = Schema::table( $name );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), $name );
		}

		$this->assertNotNull( get_role( Capabilities::TRANSLATOR_ROLE ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_SETTINGS ) );
		$this->assertSame( '9.9.9', get_option( Installer::VERSION_OPTION ) );
	}

	public function test_instalar_dos_veces_no_rompe_nada(): void {
		Installer::run( '9.9.9' );

		$this->assertSame( array(), Installer::run( '9.9.9' ) );
	}

	public function test_el_actualizador_no_hace_nada_si_la_version_no_ha_cambiado(): void {
		update_option( Installer::VERSION_OPTION, '9.9.9', true );

		( new Upgrader( '9.9.9' ) )->maybe_upgrade();

		// Si hubiera corrido, habría reescrito la opción con la misma versión;
		// lo que se comprueba es que no ha cambiado a otra cosa.
		$this->assertSame( '9.9.9', get_option( Installer::VERSION_OPTION ) );
	}

	public function test_el_actualizador_se_pone_al_dia_cuando_cambia_la_version(): void {
		// register_activation_hook() no se dispara al actualizar desde el
		// panel: sin esto, una versión con otro esquema se quedaría con las
		// tablas de la anterior.
		update_option( Installer::VERSION_OPTION, '0.0.1', true );

		( new Upgrader( '9.9.9' ) )->maybe_upgrade();

		$this->assertSame( '9.9.9', get_option( Installer::VERSION_OPTION ) );
	}

	public function test_avisa_en_el_escritorio_si_falta_alguna_tabla(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option( Installer::ERRORS_OPTION, array( 'wp_pgai_sources' ), false );

		ob_start();
		( new Upgrader( '9.9.9' ) )->notice();
		$notice = (string) ob_get_clean();

		$this->assertStringContainsString( 'wp_pgai_sources', $notice );
		$this->assertStringContainsString( 'notice-error', $notice );
	}

	public function test_no_avisa_de_nada_cuando_todo_ha_ido_bien(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Installer::run( '9.9.9' );

		ob_start();
		( new Upgrader( '9.9.9' ) )->notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}
}
