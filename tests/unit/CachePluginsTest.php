<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PolyglotAI\Compat\CachePlugins;

/**
 * El vaciado de la caché de página se acumula y se hace una vez.
 *
 * @covers \PolyglotAI\Compat\CachePlugins
 */
final class CachePluginsTest extends TestCase {

	public function test_no_vacia_nada_si_no_ha_cambiado_ninguna_traduccion(): void {
		$cache = new CachePlugins();

		$this->assertFalse( $cache->is_dirty() );
	}

	public function test_guardar_una_traduccion_deja_la_cache_por_vaciar(): void {
		$cache = new CachePlugins();

		$cache->mark();

		$this->assertTrue( $cache->is_dirty() );
	}

	public function test_mil_cadenas_de_una_traduccion_de_sitio_completo_son_un_solo_vaciado(): void {
		$cache = new CachePlugins();

		for ( $i = 0; $i < 1000; $i++ ) {
			$cache->mark();
		}

		$cache->purge();

		// Vaciada una vez y no mil: lo que se anota es que hay que vaciar, no
		// cuántas veces.
		$this->assertFalse( $cache->is_dirty() );
	}

	public function test_vaciar_dos_veces_seguidas_no_hace_nada_la_segunda(): void {
		$cache = new CachePlugins();

		$cache->mark();
		$cache->purge();
		$cache->purge();

		$this->assertFalse( $cache->is_dirty() );
	}
}
