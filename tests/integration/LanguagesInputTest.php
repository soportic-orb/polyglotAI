<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Admin\LanguagesInput;
use WP_UnitTestCase;

/**
 * Los idiomas que se escriben en los ajustes.
 *
 * @covers \PolyglotAI\Admin\LanguagesInput
 */
final class LanguagesInputTest extends WP_UnitTestCase {

	/**
	 * @dataProvider codigos
	 *
	 * @param string $written  Lo que se escribe.
	 * @param string $expected Lo que se guarda.
	 */
	public function test_normaliza_el_codigo_de_idioma( string $written, string $expected ): void {
		// «es-es» y «es_ES» son el mismo idioma: si no se normalizaran, serían
		// dos y ninguno de los dos funcionaría del todo.
		$this->assertSame( $expected, LanguagesInput::locale( $written ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function codigos(): array {
		return array(
			'ya canónico'    => array( 'es_ES', 'es_ES' ),
			'con guion'      => array( 'pt-br', 'pt_BR' ),
			'todo mayúscula' => array( 'EN_US', 'en_US' ),
			'sin región'     => array( 'CA', 'ca' ),
			'con espacios'   => array( '  de_DE  ', 'de_DE' ),
			'con basura'     => array( 'en_US<script>', 'en_USSCRIPT' ),
			'vacío'          => array( '', '' ),
		);
	}

	public function test_deriva_el_segmento_corto_del_codigo(): void {
		// /en/, no /en-us/: es lo que espera cualquiera.
		$parsed = LanguagesInput::parse(
			array( array( 'locale' => 'en_US' ), array( 'locale' => 'pt_BR' ) ),
			array(),
			'es'
		);

		$this->assertSame( 'en', $parsed[0]['slug'] );
		$this->assertSame( 'pt', $parsed[1]['slug'] );
	}

	public function test_usa_la_variante_completa_cuando_el_corto_ya_esta_cogido(): void {
		// Con pt_BR y pt_PT a la vez sí hay que distinguirlos.
		$parsed = LanguagesInput::parse(
			array( array( 'locale' => 'pt_BR' ), array( 'locale' => 'pt_PT' ) ),
			array(),
			'es'
		);

		$this->assertSame( 'pt', $parsed[0]['slug'] );
		$this->assertSame( 'pt-pt', $parsed[1]['slug'] );
	}

	public function test_no_pisa_el_segmento_del_idioma_por_defecto_al_derivar(): void {
		// El por defecto es /es/: un es_MX nuevo no puede quedarse con él.
		$parsed = LanguagesInput::parse( array( array( 'locale' => 'es_MX' ) ), array(), 'es' );

		$this->assertSame( 'es-mx', $parsed[0]['slug'] );
	}

	public function test_ignora_las_filas_vacias(): void {
		// Las tres filas en blanco del formulario no pueden crear idiomas.
		$parsed = LanguagesInput::parse(
			array(
				array( 'locale' => 'en_US' ),
				array( 'locale' => '' ),
				array(
					'locale' => '',
					'label'  => '',
				),
			),
			array(),
			'es'
		);

		$this->assertCount( 1, $parsed );
	}

	public function test_quita_los_idiomas_marcados(): void {
		$parsed = LanguagesInput::parse(
			array(
				array( 'locale' => 'en_US' ),
				array(
					'locale' => 'ca',
					'remove' => '1',
				),
			),
			array(),
			'es'
		);

		$this->assertSame( array( 'en_US' ), array_column( $parsed, 'locale' ) );
	}

	public function test_rechaza_el_segmento_del_idioma_por_defecto(): void {
		// Dejaría la portada inalcanzable.
		$parsed = LanguagesInput::parse(
			array(
				array(
					'locale' => 'es_MX',
					'slug'   => 'es',
				),
				array( 'locale' => 'en_US' ),
			),
			array(),
			'es'
		);

		$this->assertSame( array( 'en_US' ), array_column( $parsed, 'locale' ) );
	}

	public function test_rechaza_dos_idiomas_con_el_mismo_segmento(): void {
		// El segundo no se podría alcanzar nunca.
		$parsed = LanguagesInput::parse(
			array(
				array(
					'locale' => 'en_US',
					'slug'   => 'en',
				),
				array(
					'locale' => 'en_GB',
					'slug'   => 'en',
				),
			),
			array(),
			'es'
		);

		$this->assertCount( 1, $parsed );
		$this->assertSame( 'en_US', $parsed[0]['locale'] );
	}

	public function test_conserva_la_bandera_que_ya_tenia(): void {
		// La pantalla no la enseña; guardarla a ciegas la habría borrado.
		$parsed = LanguagesInput::parse(
			array(
				array(
					'locale' => 'en_US',
					'label'  => 'English',
				),
			),
			array(
				array(
					'locale' => 'en_US',
					'flag'   => 'gb',
				),
			),
			'es'
		);

		$this->assertSame( 'gb', $parsed[0]['flag'] );
	}

	public function test_solo_admite_los_tratamientos_conocidos(): void {
		$parsed = LanguagesInput::parse(
			array(
				array(
					'locale'    => 'de_DE',
					'formality' => 'formal',
				),
				array(
					'locale'    => 'fr_FR',
					'formality' => 'lo-que-sea',
				),
			),
			array(),
			'es'
		);

		$this->assertSame( 'formal', $parsed[0]['formality'] );
		$this->assertSame( 'neutral', $parsed[1]['formality'] );
	}

	public function test_sin_nombre_se_usa_el_codigo(): void {
		$parsed = LanguagesInput::parse( array( array( 'locale' => 'en_US' ) ), array(), 'es' );

		$this->assertSame( 'en_US', $parsed[0]['label'] );
	}

	public function test_las_casillas_se_leen_como_lo_que_son(): void {
		$parsed = LanguagesInput::parse(
			array(
				array(
					'locale'    => 'ar',
					'rtl'       => '1',
					'published' => '1',
				),
				array( 'locale' => 'en_US' ),
			),
			array(),
			'es'
		);

		$this->assertTrue( $parsed[0]['rtl'] );
		$this->assertTrue( $parsed[0]['published'] );

		// Una casilla sin marcar no llega en el POST: el idioma queda en
		// preparación, que es lo que se ha pedido.
		$this->assertFalse( $parsed[1]['rtl'] );
		$this->assertFalse( $parsed[1]['published'] );
	}

	public function test_un_idioma_ya_publicado_no_cambia_de_url_por_dejar_el_campo_en_blanco(): void {
		// Derivar el segmento del código habría convertido /en/ en /en-us/ y,
		// con ello, roto todos los enlaces que ya estuvieran por ahí fuera.
		$parsed = LanguagesInput::parse(
			array(
				array(
					'locale' => 'en_US',
					'slug'   => '',
					'label'  => 'English',
				),
			),
			array(
				array(
					'locale' => 'en_US',
					'slug'   => 'en',
				),
			),
			'es'
		);

		$this->assertSame( 'en', $parsed[0]['slug'] );
	}

	public function test_el_idioma_por_defecto_se_puede_cambiar(): void {
		$parsed = LanguagesInput::parse_default(
			array(
				'locale' => 'ca',
				'slug'   => 'ca',
				'label'  => 'Català',
			),
			array(
				'locale' => 'es_ES',
				'slug'   => 'es',
				'label'  => 'Español',
			)
		);

		$this->assertSame(
			array(
				'locale' => 'ca',
				'slug'   => 'ca',
				'label'  => 'Català',
			),
			$parsed
		);
	}

	public function test_un_idioma_por_defecto_vacio_no_borra_el_que_habia(): void {
		$parsed = LanguagesInput::parse_default(
			array(
				'locale' => '',
				'slug'   => '',
				'label'  => '',
			),
			array(
				'locale' => 'es_ES',
				'slug'   => 'es',
				'label'  => 'Español',
			)
		);

		$this->assertSame( 'es_ES', $parsed['locale'] );
		$this->assertSame( 'es', $parsed['slug'] );
	}
}
