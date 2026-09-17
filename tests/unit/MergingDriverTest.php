<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests;

use PolyglotAI\Html\ExclusionRules;
use PolyglotAI\Html\HtmlApiDriver;
use PolyglotAI\Html\MergingDriver;
use PolyglotAI\Html\TagScanner;
use PolyglotAI\Translation\Hasher;
use PolyglotAI\Translation\MergeRegistry;
use PolyglotAI\Translation\Normalizer;
use PolyglotAI\Translation\StringType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PolyglotAI\Html\MergingDriver
 * @covers \PolyglotAI\Translation\MergeRegistry
 */
final class MergingDriverTest extends TestCase {

	private HtmlApiDriver $inner;
	private MergeRegistry $registry;
	private Hasher $hasher;

	protected function setUp(): void {
		$GLOBALS['pgai_test_options'] = array();

		$this->inner    = new HtmlApiDriver( new TagScanner(), new ExclusionRules() );
		$this->registry = new MergeRegistry();
		$this->hasher   = new Hasher( new Normalizer() );
	}

	private function driver(): MergingDriver {
		return new MergingDriver( $this->inner, $this->registry, $this->hasher );
	}

	/**
	 * Hash de una cadena de texto.
	 *
	 * @param string $value Texto.
	 */
	private function hash( string $value ): string {
		return $this->hasher->hash( $value, StringType::Text );
	}

	public function test_sin_fusiones_devuelve_lo_mismo_que_el_driver_interno(): void {
		$html = '<div><p>Uno</p><p>Dos</p></div>';

		$this->assertEquals( $this->inner->extract( $html ), $this->driver()->extract( $html ) );
	}

	public function test_funde_varias_cadenas_en_una_sola_unidad(): void {
		$html = '<div><p>Uno</p><p>Dos</p><p>Tres</p></div>';

		$this->registry->add( array( $this->hash( 'Uno' ), $this->hash( 'Dos' ) ) );

		$units = $this->driver()->extract( $html );

		$this->assertCount( 2, $units );
		$this->assertSame( StringType::Block, $units[0]->type );
		$this->assertSame( '<p>Uno</p><p>Dos</p>', $units[0]->value );
		$this->assertSame( 'Tres', $units[1]->value );
	}

	public function test_la_unidad_fusionada_cubre_el_marcado_intermedio(): void {
		// El intervalo va de la primera cadena a la última e incluye lo que hay
		// entre medias: sin eso, sustituir rompería la maquetación.
		$html = '<div><p>Uno</p><hr><p>Dos</p></div>';
		$this->registry->add( array( $this->hash( 'Uno' ), $this->hash( 'Dos' ) ) );

		$units = $this->driver()->extract( $html );

		$this->assertSame( '<p>Uno</p><hr><p>Dos</p>', $units[0]->value );
		$this->assertSame( $units[0]->value, substr( $html, $units[0]->start, $units[0]->length ) );
	}

	public function test_el_grupo_viaja_en_el_contexto_para_poder_deshacerlo(): void {
		$html  = '<div><p>Uno</p><p>Dos</p></div>';
		$first = $this->hash( 'Uno' );

		$this->registry->add( array( $first, $this->hash( 'Dos' ) ) );

		$units = $this->driver()->extract( $html );

		$this->assertSame( 'merge:' . $first, $units[0]->context );
	}

	public function test_no_funde_si_las_cadenas_ya_no_son_consecutivas(): void {
		// El contenido ha cambiado desde que se creó la fusión: deja de
		// aplicarse sola, sin romper nada.
		$html = '<div><p>Uno</p><p>Intercalada</p><p>Dos</p></div>';

		$this->registry->add( array( $this->hash( 'Uno' ), $this->hash( 'Dos' ) ) );

		$units = $this->driver()->extract( $html );

		$this->assertCount( 3, $units );
		$this->assertSame( 'Uno', $units[0]->value );
	}

	public function test_no_funde_si_falta_una_de_las_cadenas(): void {
		$html = '<div><p>Uno</p></div>';

		$this->registry->add( array( $this->hash( 'Uno' ), $this->hash( 'Dos' ) ) );

		$units = $this->driver()->extract( $html );

		$this->assertCount( 1, $units );
		$this->assertSame( 'Uno', $units[0]->value );
	}

	public function test_las_unidades_fusionadas_siguen_sin_solaparse(): void {
		$html = '<div><p>Uno</p><p>Dos</p><p>Tres</p><p>Cuatro</p></div>';

		$this->registry->add( array( $this->hash( 'Uno' ), $this->hash( 'Dos' ) ) );
		$this->registry->add( array( $this->hash( 'Tres' ), $this->hash( 'Cuatro' ) ) );

		$units = $this->driver()->extract( $html );
		$end   = 0;

		foreach ( $units as $unit ) {
			$this->assertGreaterThanOrEqual( $end, $unit->start );
			$end = $unit->end();
		}

		$this->assertCount( 2, $units );
	}

	public function test_fusionar_una_sola_cadena_no_hace_nada(): void {
		$this->assertNull( $this->registry->add( array( $this->hash( 'Uno' ) ) ) );
		$this->assertSame( array(), $this->registry->all() );
	}

	public function test_una_cadena_no_puede_estar_en_dos_fusiones(): void {
		$uno = $this->hash( 'Uno' );

		$this->registry->add( array( $uno, $this->hash( 'Dos' ) ) );
		$this->registry->add( array( $uno, $this->hash( 'Tres' ) ) );

		$groups = $this->registry->all();

		$this->assertCount( 1, $groups );
		$this->assertSame( array( $uno, $this->hash( 'Tres' ) ), $groups[ $uno ] );
	}

	public function test_deshacer_la_fusion_devuelve_las_cadenas_sueltas(): void {
		$html = '<div><p>Uno</p><p>Dos</p></div>';
		$uno  = $this->hash( 'Uno' );

		$this->registry->add( array( $uno, $this->hash( 'Dos' ) ) );
		$this->assertCount( 1, $this->driver()->extract( $html ) );

		$this->assertTrue( $this->registry->remove( $uno ) );
		$this->assertCount( 2, $this->driver()->extract( $html ) );
	}

	public function test_deshacer_funciona_desde_cualquier_miembro(): void {
		$dos = $this->hash( 'Dos' );

		$this->registry->add( array( $this->hash( 'Uno' ), $dos ) );

		$this->assertTrue( $this->registry->remove( $dos ) );
		$this->assertSame( array(), $this->registry->all() );
	}

	public function test_deshacer_algo_que_no_existe_no_falla(): void {
		$this->assertFalse( $this->registry->remove( $this->hash( 'Inexistente' ) ) );
	}
}
