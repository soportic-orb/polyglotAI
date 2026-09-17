<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Engines\Usage;
use PolyglotAI\Html\DriverFactory;
use PolyglotAI\Html\ExclusionRules;
use PolyglotAI\Html\OffsetTagProcessor;
use PolyglotAI\Html\TagScanner;
use PolyglotAI\Support\ApiKey;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Translation\DictionaryFactory;
use PolyglotAI\Translation\Hasher;
use PolyglotAI\Translation\Normalizer;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use PolyglotAI\Translation\StringType;
use WP_UnitTestCase;

/**
 * Comprueba dentro de WordPress las piezas que no se pueden probar fuera:
 * la sonda del driver, la caché de objeto, los roles y la custodia de la clave.
 */
final class PluginIntegrationTest extends WP_UnitTestCase {

	/**
	 * Prepara las tablas.
	 */
	public function set_up(): void {
		parent::set_up();

		( new Schema() )->install( true );
	}

	public function test_la_sonda_del_driver_funciona_en_este_wordpress(): void {
		// Es la comprobación que decide si el plugin puede traducir: depende de
		// una propiedad protegida del núcleo y por eso se verifica de verdad.
		$this->assertTrue(
			OffsetTagProcessor::is_supported(),
			'El mecanismo de posiciones por marcadores ha dejado de funcionar en esta versión de WordPress.'
		);
	}

	public function test_la_fabrica_devuelve_un_driver_utilizable(): void {
		$driver = ( new DriverFactory() )->create( new TagScanner(), new ExclusionRules() );

		$this->assertNotNull( $driver );
		$this->assertSame( 'wp-html-api', $driver->name() );
	}

	public function test_el_diccionario_de_una_pagina_se_resuelve_en_una_consulta(): void {
		global $wpdb;

		$sources      = new SourceRepository();
		$translations = new TranslationRepository( new StatusPrecedence() );
		$hasher       = new Hasher( new Normalizer() );

		$driver = ( new DriverFactory() )->create( new TagScanner(), new ExclusionRules() );
		$this->assertNotNull( $driver );

		$html  = '<div><p>Hola</p><p>Adiós</p><p>Gracias</p></div>';
		$units = $driver->extract( $html );

		foreach ( $units as $unit ) {
			$id = $sources->remember( $hasher->hash( $unit->value, $unit->type, $unit->context ), $unit->value, $unit->type, $unit->context );
			$translations->save( $id, 'en_US', strtoupper( $unit->value ), Status::Automatic );
		}

		$factory = new DictionaryFactory( $translations, $hasher, new Normalizer() );

		$before     = $wpdb->num_queries;
		$dictionary = $factory->build( $units, 'en_US' );
		$queries    = $wpdb->num_queries - $before;

		$this->assertSame( 3, $dictionary->count() );
		$this->assertFalse( $dictionary->has_missing() );
		$this->assertLessThanOrEqual( 1, $queries, "El diccionario ha necesitado {$queries} consultas; debe bastar una." );
	}

	public function test_el_diccionario_indica_lo_que_falta_por_traducir(): void {
		$driver = ( new DriverFactory() )->create( new TagScanner(), new ExclusionRules() );
		$this->assertNotNull( $driver );

		$factory    = new DictionaryFactory(
			new TranslationRepository( new StatusPrecedence() ),
			new Hasher( new Normalizer() ),
			new Normalizer()
		);
		$dictionary = $factory->build( $driver->extract( '<p>Sin traducir</p>' ), 'en_US' );

		$this->assertTrue( $dictionary->has_missing() );
		$this->assertCount( 1, $dictionary->missing() );
	}

	public function test_invalidar_la_cache_cambia_la_version(): void {
		$before = (int) get_option( 'pgai_dict_version', 1 );

		DictionaryFactory::invalidate();

		$this->assertSame( $before + 1, (int) get_option( 'pgai_dict_version', 1 ) );
	}

	public function test_crea_el_rol_de_traductor_con_sus_capacidades(): void {
		( new Capabilities() )->install();

		$role = get_role( Capabilities::TRANSLATOR_ROLE );

		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( Capabilities::TRANSLATE ) );
		$this->assertTrue( $role->has_cap( Capabilities::REVIEW ) );

		// Un traductor no puede gastar presupuesto de API ni tocar los ajustes.
		$this->assertFalse( $role->has_cap( Capabilities::RUN_AUTO ) );
		$this->assertFalse( $role->has_cap( Capabilities::MANAGE_SETTINGS ) );

		$administrator = get_role( 'administrator' );
		$this->assertTrue( $administrator->has_cap( Capabilities::MANAGE_SETTINGS ) );

		( new Capabilities() )->remove();
		$this->assertNull( get_role( Capabilities::TRANSLATOR_ROLE ) );
	}

	public function test_la_clave_de_api_se_guarda_cifrada_y_no_en_claro(): void {
		$key = new ApiKey();

		$this->assertTrue( $key->save( 'sk-ant-secreto-12345' ) );
		$this->assertSame( 'sk-ant-secreto-12345', $key->get() );

		// Lo que queda en la base de datos no puede ser la clave.
		$stored = (string) get_option( 'pgai_api_key', '' );

		$this->assertNotSame( 'sk-ant-secreto-12345', $stored );
		$this->assertStringNotContainsString( 'sk-ant-secreto', $stored );

		// La pista para la interfaz solo revela el final.
		$this->assertSame( '…2345', $key->hint() );

		$key->save( '' );
		$this->assertNull( $key->get() );
	}

	public function test_registra_el_consumo_desglosado_de_la_api(): void {
		$log = new ApiLogRepository();

		$log->record( 'anthropic', 'claude-sonnet-5', 'en_US', 40, new Usage( 1000, 500, 900, 100 ) );
		$log->record( 'anthropic', 'claude-sonnet-5', 'ca', 20, new Usage( 500, 250, 0, 0 ) );

		$usage = $log->usage_since( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$this->assertSame( 1500, $usage['input'] );
		$this->assertSame( 750, $usage['output'] );

		// La lectura de caché va aparte: su precio es una fracción del token de
		// entrada normal y sumarla al total daría un gasto falso.
		$this->assertSame( 900, $usage['cache_read'] );
		$this->assertSame( 100, $usage['cache_creation'] );
	}

	public function test_el_hash_sobrevive_al_viaje_de_ida_y_vuelta_por_la_base_de_datos(): void {
		$hasher = new Hasher( new Normalizer() );
		$hash   = $hasher->hash( 'Cafè amb ñ i 😀', StringType::Text );

		$sources = new SourceRepository();
		$sources->remember( $hash, 'Cafè amb ñ i 😀', StringType::Text );

		$this->assertArrayHasKey( $hash, $sources->ids_by_hash( array( $hash ) ) );
	}
}
