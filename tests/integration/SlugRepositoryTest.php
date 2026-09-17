<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SlugRecord;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Database\SlugRepository
 * @covers \PolyglotAI\Database\SlugRecord
 */
final class SlugRepositoryTest extends WP_UnitTestCase {

	private SlugRepository $repository;

	/**
	 * Vacía la tabla antes de cada test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$table}`" );

		$this->repository = new SlugRepository( new StatusPrecedence() );
	}

	/**
	 * Registro de ejemplo.
	 *
	 * @param string $translated Slug traducido.
	 * @param int    $object_id  Identificador.
	 * @param Status $status     Estado.
	 */
	private function record( string $translated = 'contact-us', int $object_id = 12, Status $status = Status::Automatic ): SlugRecord {
		return new SlugRecord( 'post', 'page', $object_id, 'en_US', 'contacto', $translated, $status );
	}

	public function test_guarda_y_recupera_un_slug(): void {
		$this->assertTrue( $this->repository->save( $this->record() ) );

		$found = $this->repository->find( 'post', 'page', 12, 'en_US' );

		$this->assertNotNull( $found );
		$this->assertSame( 'contact-us', $found->translated_slug );
		$this->assertSame( 'contacto', $found->original_slug );
		$this->assertSame( Status::Automatic, $found->status );
	}

	public function test_retraducir_actualiza_en_vez_de_duplicar(): void {
		$this->repository->save( $this->record() );
		$this->repository->save( $this->record( 'contact' ), true );

		global $wpdb;
		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		$this->assertSame( 1, $rows );

		$found = $this->repository->find( 'post', 'page', 12, 'en_US' );
		$this->assertNotNull( $found );
		$this->assertSame( 'contact', $found->translated_slug );
	}

	public function test_una_traduccion_automatica_no_repite_el_trabajo_ya_hecho(): void {
		// Sobre una traducción automática previa solo se escribe si alguien ha
		// pedido la retraducción: si no, cada barrido del sitio pagaría otra vez
		// por el mismo slug.
		$this->repository->save( $this->record( 'contact-us' ) );

		$this->assertFalse( $this->repository->save( $this->record( 'contact' ) ) );

		$found = $this->repository->find( 'post', 'page', 12, 'en_US' );
		$this->assertNotNull( $found );
		$this->assertSame( 'contact-us', $found->translated_slug );
	}

	public function test_lo_automatico_no_pisa_lo_manual(): void {
		$this->repository->save( $this->record( 'contact-us', 12, Status::Manual ) );

		$this->assertFalse(
			$this->repository->save( $this->record( 'automatico', 12, Status::Automatic ) ),
			'Una traducción automática no puede sobrescribir un slug escrito a mano.'
		);

		$found = $this->repository->find( 'post', 'page', 12, 'en_US' );
		$this->assertNotNull( $found );
		$this->assertSame( 'contact-us', $found->translated_slug );
	}

	public function test_ni_siquiera_al_forzar_la_retraduccion(): void {
		$this->repository->save( $this->record( 'contact-us', 12, Status::Reviewed ) );

		$this->assertFalse( $this->repository->save( $this->record( 'otro', 12, Status::Automatic ), true ) );
	}

	public function test_una_persona_si_puede_corregir(): void {
		$this->repository->save( $this->record( 'contact-us', 12, Status::Automatic ) );

		$this->assertTrue( $this->repository->save( $this->record( 'get-in-touch', 12, Status::Manual ) ) );

		$found = $this->repository->find( 'post', 'page', 12, 'en_US' );
		$this->assertNotNull( $found );
		$this->assertSame( 'get-in-touch', $found->translated_slug );
	}

	public function test_normaliza_el_slug_como_wordpress(): void {
		$this->repository->save( $this->record( 'Contacta Con Nosotros' ) );

		$found = $this->repository->find( 'post', 'page', 12, 'en_US' );
		$this->assertNotNull( $found );
		$this->assertSame( 'contacta-con-nosotros', $found->translated_slug );
	}

	public function test_evita_que_dos_objetos_compartan_slug_traducido(): void {
		// Si dos páginas acabaran en /en/contact-us/, el enrutado inverso no
		// sabría a cuál ir.
		$this->repository->save( $this->record( 'contact-us', 12 ) );
		$this->repository->save( $this->record( 'contact-us', 34 ) );

		$segundo = $this->repository->find( 'post', 'page', 34, 'en_US' );

		$this->assertNotNull( $segundo );
		$this->assertSame( 'contact-us-2', $segundo->translated_slug );
	}

	public function test_reguardar_el_mismo_objeto_no_se_desambigua_contra_si_mismo(): void {
		$this->repository->save( $this->record( 'contact-us', 12 ) );
		$this->repository->save( $this->record( 'contact-us', 12 ) );

		$found = $this->repository->find( 'post', 'page', 12, 'en_US' );

		$this->assertNotNull( $found );
		$this->assertSame( 'contact-us', $found->translated_slug );
	}

	public function test_resuelve_varios_objetos_de_una_vez(): void {
		$this->repository->save( $this->record( 'contact-us', 12 ) );
		$this->repository->save( $this->record( 'about-us', 34 ) );

		$map = $this->repository->for_objects( 'post', array( 12, 34, 99 ), 'en_US' );

		$this->assertSame(
			array(
				12 => 'contact-us',
				34 => 'about-us',
			),
			$map
		);
	}

	public function test_enrutado_inverso_por_lote(): void {
		$this->repository->save( $this->record( 'contact-us', 12 ) );
		$this->repository->save( new SlugRecord( 'term', 'category', 7, 'en_US', 'noticias', 'news' ) );

		$map = $this->repository->originals( 'en_US', array( 'news', 'contact-us', 'algo-que-no-existe' ) );

		$this->assertSame(
			array(
				'contact-us' => 'contacto',
				'news'       => 'noticias',
			),
			$map
		);
	}

	public function test_el_enrutado_inverso_no_cruza_idiomas(): void {
		$this->repository->save( $this->record( 'contact-us', 12 ) );

		$this->assertSame( array(), $this->repository->originals( 'ca', array( 'contact-us' ) ) );
	}

	public function test_cada_base_tiene_su_propia_fila(): void {
		// Todas las bases comparten object_id = 0: sin el subtipo en la clave
		// única, la segunda pisaría a la primera.
		$this->repository->save( new SlugRecord( 'base', 'category', 0, 'en_US', 'categoria', 'category' ) );
		$this->repository->save( new SlugRecord( 'base', 'shop', 0, 'en_US', 'tienda', 'shop' ) );

		$this->assertSame(
			array(
				'category' => 'category',
				'shop'     => 'shop',
			),
			$this->repository->bases( 'en_US' )
		);
	}

	public function test_borra_todos_los_idiomas_de_un_objeto(): void {
		$this->repository->save( $this->record( 'contact-us', 12 ) );
		$this->repository->save( new SlugRecord( 'post', 'page', 12, 'ca', 'contacto', 'contacte' ) );

		$this->assertSame( 2, $this->repository->delete_for_object( 'post', 12 ) );
		$this->assertNull( $this->repository->find( 'post', 'page', 12, 'en_US' ) );
		$this->assertNull( $this->repository->find( 'post', 'page', 12, 'ca' ) );
	}

	public function test_lista_los_slugs_pendientes(): void {
		$this->repository->save( $this->record( 'contacto', 12, Status::Pending ) );
		$this->repository->save( $this->record( 'about-us', 34, Status::Automatic ) );

		$pending = $this->repository->pending( 'en_US' );

		$this->assertCount( 1, $pending );
		$this->assertSame( 12, $pending[0]->object_id );
	}
}
