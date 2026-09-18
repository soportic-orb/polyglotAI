<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Html\BailConditions;
use PolyglotAI\Support\Options;
use WP_UnitTestCase;

/**
 * Qué peticiones no se traducen (ADR-04).
 *
 * @covers \PolyglotAI\Html\BailConditions
 */
final class BailConditionsTest extends WP_UnitTestCase {

	private BailConditions $bail;

	public function set_up(): void {
		parent::set_up();

		$this->bail             = new BailConditions( new Options() );
		$_SERVER['REQUEST_URI'] = '/una-pagina/';
	}

	public function test_una_pagina_normal_se_procesa(): void {
		$this->assertTrue( $this->bail->should_process() );
	}

	public function test_la_vista_previa_de_un_borrador_no_se_procesa(): void {
		// Es contenido sin publicar: procesarlo lo guardaría y la tarea de
		// fondo acabaría mandándolo a la API.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_name'   => 'borrador',
			)
		);

		$this->go_to( (string) get_preview_post_link( $post_id ) );

		$this->assertTrue( is_preview() );
		$this->assertFalse( $this->bail->should_process() );
	}

	public function test_una_ruta_excluida_no_se_procesa(): void {
		$_SERVER['REQUEST_URI'] = '/checkout/';

		$this->assertFalse( $this->bail->should_process() );
	}

	/**
	 * @dataProvider parametros_de_constructor
	 *
	 * @param string $parameter Parámetro que marca el modo edición.
	 */
	public function test_el_modo_edicion_de_un_constructor_no_se_procesa( string $parameter ): void {
		$_GET[ $parameter ] = '1';

		$this->assertFalse( $this->bail->should_process(), $parameter );

		unset( $_GET[ $parameter ] );
	}

	/**
	 * Los que se han podido comprobar en el código del propio constructor.
	 *
	 * @return array<string, string[]>
	 */
	public static function parametros_de_constructor(): array {
		return array(
			'Elementor'  => array( 'elementor-preview' ),
			'Beaver'     => array( 'fl_builder' ),
			// Brizy edita dentro de un iframe del frente y lo marca con esto,
			// no con «brizy-edit», que es lo que ponía antes esta lista.
			'Brizy'      => array( 'is-editor-iframe' ),
			'SiteOrigin' => array( 'siteorigin_panels_live_editor' ),
			'Customizer' => array( 'customize_changeset_uuid' ),
		);
	}

	public function test_el_filtro_manda_sobre_todo_lo_demas(): void {
		add_filter( 'pgai_should_process_output', '__return_false' );

		$this->assertFalse( $this->bail->should_process() );
	}
}
