<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Support\TranslatorLanguages;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Support\TranslatorLanguages
 */
final class TranslatorLanguagesTest extends WP_UnitTestCase {

	private LanguageRegistry $languages;
	private TranslatorLanguages $access;

	/**
	 * Tres idiomas traducibles.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array(
				new Language( 'en_US', 'en', 'English' ),
				new Language( 'ca', 'ca', 'Català' ),
				new Language( 'de_DE', 'de', 'Deutsch' ),
			)
		);

		$this->access = new TranslatorLanguages( $this->languages );
	}

	/**
	 * Crea un traductor.
	 */
	private function translator(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );

		$this->assertNotFalse( $user );
		$user->add_cap( Capabilities::TRANSLATE );

		return $user_id;
	}

	public function test_sin_asignar_nada_puede_con_todos(): void {
		// Quien no configure nada se encuentra el comportamiento de siempre.
		$user_id = $this->translator();

		$this->assertSame( array(), $this->access->assigned( $user_id ) );
		$this->assertTrue( $this->access->allows( $user_id, 'en_US' ) );
		$this->assertTrue( $this->access->allows( $user_id, 'de_DE' ) );
		$this->assertCount( 3, $this->access->for_user( $user_id ) );
	}

	public function test_asignar_idiomas_deja_fuera_los_demas(): void {
		$user_id = $this->translator();

		$this->access->assign( $user_id, array( 'en_US', 'ca' ) );

		$this->assertTrue( $this->access->allows( $user_id, 'en_US' ) );
		$this->assertTrue( $this->access->allows( $user_id, 'ca' ) );
		$this->assertFalse( $this->access->allows( $user_id, 'de_DE' ) );

		$locales = array_map(
			static fn ( Language $language ): string => $language->locale,
			$this->access->for_user( $user_id )
		);

		$this->assertSame( array( 'en_US', 'ca' ), $locales );
	}

	public function test_no_se_guarda_un_idioma_que_el_sitio_no_tiene(): void {
		$user_id = $this->translator();

		$this->access->assign( $user_id, array( 'en_US', 'ja', 'es_ES' ) );

		// «ja» no está configurado y «es_ES» es el idioma por defecto, que no se
		// traduce.
		$this->assertSame( array( 'en_US' ), $this->access->assigned( $user_id ) );
	}

	public function test_quitar_un_idioma_del_sitio_no_deja_al_traductor_sin_nada(): void {
		$user_id = $this->translator();

		$this->access->assign( $user_id, array( 'de_DE' ) );

		$reduced = new TranslatorLanguages(
			new LanguageRegistry(
				new Language( 'es_ES', 'es', 'Español' ),
				array( new Language( 'en_US', 'en', 'English' ) )
			)
		);

		// El alemán ya no existe: la asignación deja de contar y vuelve a
		// poder con lo que haya, en vez de quedarse restringido a nada.
		$this->assertSame( array(), $reduced->assigned( $user_id ) );
		$this->assertTrue( $reduced->allows( $user_id, 'en_US' ) );
	}

	public function test_asignar_una_lista_vacia_borra_la_restriccion(): void {
		$user_id = $this->translator();

		$this->access->assign( $user_id, array( 'en_US' ) );
		$this->access->assign( $user_id, array() );

		$this->assertSame( array(), $this->access->assigned( $user_id ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, TranslatorLanguages::META_KEY, true ) );
	}

	public function test_a_un_administrador_no_se_le_restringe_nunca(): void {
		// Un administrador que se quedara fuera de un idioma por un descuido no
		// tendría cómo volver a entrar.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user  = get_user_by( 'id', $admin );

		$this->assertNotFalse( $user );
		$user->add_cap( Capabilities::MANAGE_SETTINGS );

		$this->access->assign( $admin, array( 'en_US' ) );

		$this->assertTrue( $this->access->allows( $admin, 'de_DE' ) );
		$this->assertCount( 3, $this->access->for_user( $admin ) );
	}
}
