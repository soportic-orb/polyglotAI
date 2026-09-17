<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Compat\CachePlugins;
use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use PolyglotAI\Translation\StringType;
use WP_UnitTestCase;

/**
 * Guardar una traducción deja la caché de página por vaciar (ADR-13).
 *
 * @covers \PolyglotAI\Compat\CachePlugins
 */
final class CachePurgeTest extends WP_UnitTestCase {

	private CachePlugins $cache;
	private int $purges = 0;

	public function set_up(): void {
		parent::set_up();

		$this->cache  = new CachePlugins();
		$this->purges = 0;

		$this->cache->register();

		add_action(
			'pgai_page_cache_purged',
			function (): void {
				++$this->purges;
			}
		);
	}

	public function test_guardar_una_traduccion_pide_vaciar_la_cache(): void {
		$sources      = new SourceRepository();
		$translations = new TranslationRepository( new StatusPrecedence() );

		$source_id = $sources->remember( md5( 'hola' ), 'Hola', StringType::Text );

		$translations->save( $source_id, 'en_US', 'Hello', Status::Automatic );

		$this->assertTrue( $this->cache->is_dirty() );

		$this->cache->purge();

		$this->assertSame( 1, $this->purges );
	}

	public function test_cambiar_un_slug_pide_vaciar_la_cache(): void {
		// Cambiar un slug cambia la URL: lo cacheado en la anterior ya no vale.
		do_action( 'pgai_slugs_changed' );

		$this->assertTrue( $this->cache->is_dirty() );
	}

	public function test_una_peticion_sin_cambios_no_vacia_nada(): void {
		$this->cache->purge();

		$this->assertSame( 0, $this->purges );
	}

	public function test_se_puede_desactivar_el_vaciado(): void {
		add_filter( 'pgai_purge_page_cache', '__return_false' );

		do_action( 'pgai_slugs_changed' );

		$this->cache->purge();

		$this->assertSame( 0, $this->purges );
	}
}
