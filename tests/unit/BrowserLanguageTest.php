<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PolyglotAI\Detection\BrowserLanguage;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;

/**
 * @covers \PolyglotAI\Detection\BrowserLanguage
 */
final class BrowserLanguageTest extends TestCase {

	private BrowserLanguage $detector;

	protected function setUp(): void {
		$this->detector = new BrowserLanguage( $this->registry() );
	}

	/**
	 * Registro con español por defecto más inglés y portugués de Brasil.
	 */
	private function registry(): LanguageRegistry {
		return new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array(
				new Language( 'en_US', 'en', 'English' ),
				new Language( 'pt_BR', 'pt-br', 'Português' ),
			)
		);
	}

	/**
	 * Locale del idioma detectado, o null.
	 *
	 * @param string $header Cabecera.
	 */
	private function detect( string $header ): ?string {
		$language = $this->detector->preferred( $header );

		return null === $language ? null : $language->locale;
	}

	public function test_coincidencia_exacta(): void {
		$this->assertSame( 'pt_BR', $this->detect( 'pt-BR' ) );
	}

	public function test_coincidencia_por_idioma_base(): void {
		// Un portugués de Portugal lee mejor el de Brasil que el español.
		$this->assertSame( 'pt_BR', $this->detect( 'pt-PT' ) );
	}

	public function test_respeta_el_factor_de_calidad(): void {
		// El alemán va antes en la cabecera, pero el navegador prefiere inglés.
		$this->assertSame( 'en_US', $this->detect( 'de;q=0.9, en-US;q=1.0' ) );
	}

	public function test_a_igual_calidad_manda_el_orden(): void {
		$this->assertSame( 'en_US', $this->detect( 'en-US, pt-BR' ) );
		$this->assertSame( 'pt_BR', $this->detect( 'pt-BR, en-US' ) );
	}

	public function test_una_coincidencia_exacta_gana_a_una_parcial_anterior(): void {
		// «pt» aproximaría a pt_BR, pero «es-ES» exacto es mejor aunque vaya
		// después... salvo que el navegador lo prefiera menos. Aquí no hay q,
		// así que manda el orden y «pt» encaja exacto con nada: se agota la
		// lista buscando exactos antes de aproximar.
		$this->assertSame( 'es_ES', $this->detect( 'pt;q=0.9, es-ES;q=0.8' ) );
	}

	public function test_una_cabecera_vacia_no_detecta_nada(): void {
		$this->assertNull( $this->detect( '' ) );
		$this->assertNull( $this->detect( '   ' ) );
	}

	public function test_un_idioma_que_el_sitio_no_tiene_no_detecta_nada(): void {
		$this->assertNull( $this->detect( 'ja, ko;q=0.8' ) );
	}

	public function test_el_comodin_se_ignora(): void {
		// «*» significa «cualquiera», que no es una preferencia.
		$this->assertNull( $this->detect( '*' ) );
	}

	public function test_calidad_cero_significa_que_no_lo_quiere(): void {
		$this->assertNull( $this->detect( 'en;q=0' ) );
	}

	public function test_no_se_atraganta_con_una_cabecera_rara(): void {
		$this->assertNull( $this->detect( ';;;,,,q=' ) );
		$this->assertSame( 'en_US', $this->detect( 'en-US;q=nada' ) );
	}

	public function test_un_idioma_en_preparacion_no_se_detecta(): void {
		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English', '', false, true, false ) )
		);

		// Redirigir a un visitante a un idioma sin publicar sería enseñarle un
		// sitio a medio traducir.
		$this->assertNull( ( new BrowserLanguage( $languages ) )->preferred( 'en-US' ) );
	}

	public function test_devuelve_la_lista_ordenada(): void {
		$this->assertSame(
			array( 'en-us', 'pt-br', 'de' ),
			$this->detector->parse( 'de;q=0.5, pt-BR;q=0.8, en-US' )
		);
	}
}
