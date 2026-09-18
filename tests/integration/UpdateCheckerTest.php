<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Licensing\License;
use PolyglotAI\Licensing\LicenseServer;
use PolyglotAI\Licensing\UpdateChecker;
use WP_UnitTestCase;

/**
 * Actualizaciones desde nuestro propio servidor (ADR-20).
 *
 * @covers \PolyglotAI\Licensing\UpdateChecker
 * @covers \PolyglotAI\Licensing\License
 * @covers \PolyglotAI\Licensing\LicenseServer
 */
final class UpdateCheckerTest extends WP_UnitTestCase {

	private const SERVER = 'https://licencias.example.test';

	private License $license;
	private UpdateChecker $checker;
	private int $requests = 0;

	/** @var array<string, mixed>|null */
	private ?array $reply = null;

	public function set_up(): void {
		parent::set_up();

		delete_site_transient( UpdateChecker::TRANSIENT );
		delete_option( License::OPTION );

		$this->requests = 0;
		$this->reply    = null;

		$this->license = new License();
		$this->checker = new UpdateChecker(
			new LicenseServer( self::SERVER ),
			$this->license,
			'polyglot-ai/polyglot-ai.php',
			'polyglot-ai',
			'0.1.0'
		);

		// El servidor de licencias, de mentira: se intercepta la petición HTTP
		// antes de que salga.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( ! str_starts_with( (string) $url, self::SERVER ) ) {
					return $preempt;
				}

				++$this->requests;

				if ( null === $this->reply ) {
					return new \WP_Error( 'http_request_failed', 'Servidor caído.' );
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode( $this->reply ),
				);
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		delete_site_transient( UpdateChecker::TRANSIENT );

		parent::tear_down();
	}

	/**
	 * Una respuesta con una actualización, cambiando lo que haga falta.
	 *
	 * @param array<string, mixed> $update Cambios sobre la actualización.
	 */
	private function offering( array $update = array() ): void {
		$this->reply = array(
			'license' => array(
				'status'  => License::VALID,
				'expires' => '2027-01-01',
			),
			'update'  => array_merge(
				array(
					'version'  => '0.2.0',
					'package'  => self::SERVER . '/descargas/polyglot-ai-0.2.0.zip',
					'requires' => '6.6',
					'tested'   => '6.6',
					'sections' => array( 'changelog' => '<p>Cosas nuevas.</p>' ),
				),
				$update
			),
		);
	}

	/**
	 * La actualización que WordPress acabaría instalando, si la hay.
	 */
	private function offered(): ?object {
		$transient = $this->checker->check( new \stdClass() );

		return $transient->response['polyglot-ai/polyglot-ai.php'] ?? null;
	}

	public function test_sin_clave_de_licencia_no_se_llama_a_nadie(): void {
		// Un sitio que nunca ha comprado nada no tiene por qué mandar su
		// dirección a ninguna parte.
		$this->offering();

		$this->assertNull( $this->offered() );
		$this->assertSame( 0, $this->requests );
	}

	public function test_ofrece_la_actualizacion_cuando_el_servidor_la_da(): void {
		$this->license->set_key( 'PGAI-1234-5678-ABCD' );
		$this->offering();

		$offered = $this->offered();

		$this->assertNotNull( $offered );
		$this->assertSame( '0.2.0', $offered->new_version );
		$this->assertSame( self::SERVER . '/descargas/polyglot-ai-0.2.0.zip', $offered->package );
	}

	public function test_rechaza_una_descarga_de_otro_host(): void {
		// Es la defensa que importa: WordPress instala sin rechistar el zip que
		// diga «package», así que una respuesta manipulada podría colar
		// cualquier cosa bajo el nombre de este plugin.
		$this->license->set_key( 'PGAI-1234-5678-ABCD' );
		$this->offering( array( 'package' => 'https://malo.example.test/cualquier-cosa.zip' ) );

		$this->assertNull( $this->offered() );
	}

	public function test_rechaza_una_descarga_sin_cifrar(): void {
		$this->license->set_key( 'PGAI-1234-5678-ABCD' );
		$this->offering( array( 'package' => 'http://licencias.example.test/polyglot-ai.zip' ) );

		$this->assertNull( $this->offered() );
	}

	public function test_no_ofrece_una_version_que_no_es_mas_nueva(): void {
		$this->license->set_key( 'PGAI-1234-5678-ABCD' );
		$this->offering( array( 'version' => '0.1.0' ) );

		$this->assertNull( $this->offered() );
	}

	public function test_un_servidor_caido_no_ofrece_nada_ni_molesta(): void {
		$this->license->set_key( 'PGAI-1234-5678-ABCD' );

		$this->reply = null;

		$this->assertNull( $this->offered() );
	}

	public function test_se_queda_con_lo_que_dice_el_servidor_de_la_licencia(): void {
		$this->license->set_key( 'PGAI-1234-5678-ABCD' );
		$this->offering();

		$this->offered();

		$this->assertTrue( $this->license->is_active() );
		$this->assertSame( '2027-01-01', $this->license->expires() );
	}

	public function test_no_pregunta_dos_veces_seguidas(): void {
		$this->license->set_key( 'PGAI-1234-5678-ABCD' );
		$this->offering();

		$this->offered();
		$this->offered();

		// WordPress mira las actualizaciones muchas veces por sesión; cada una
		// no puede ser una petición de red.
		$this->assertSame( 1, $this->requests );
	}

	public function test_la_ventana_de_detalles_se_rellena(): void {
		$this->license->set_key( 'PGAI-1234-5678-ABCD' );
		$this->offering();

		$args = (object) array( 'slug' => 'polyglot-ai' );

		$information = $this->checker->details( false, 'plugin_information', $args );

		$this->assertIsObject( $information );
		$this->assertSame( '0.2.0', $information->version );
		$this->assertStringContainsString( 'Cosas nuevas', $information->sections['changelog'] );
	}

	public function test_la_ventana_de_detalles_de_otro_plugin_no_se_toca(): void {
		$this->license->set_key( 'PGAI-1234-5678-ABCD' );
		$this->offering();

		$args = (object) array( 'slug' => 'otro-plugin' );

		$this->assertFalse( $this->checker->details( false, 'plugin_information', $args ) );
	}

	public function test_cambiar_la_clave_olvida_lo_que_se_sabia(): void {
		$this->license->set_key( 'PGAI-1234-5678-ABCD' );
		$this->offering();
		$this->offered();

		$this->assertTrue( $this->license->is_active() );

		$this->license->set_key( 'PGAI-OTRA-CLAVE-9999' );

		$this->assertFalse( $this->license->is_active() );
	}

	public function test_sin_servidor_configurado_no_hay_actualizaciones(): void {
		$checker = new UpdateChecker(
			new LicenseServer( '' ),
			$this->license,
			'polyglot-ai/polyglot-ai.php',
			'polyglot-ai',
			'0.1.0'
		);

		$this->license->set_key( 'PGAI-1234-5678-ABCD' );
		$this->offering();

		$transient = $checker->check( new \stdClass() );

		$this->assertArrayNotHasKey( 'polyglot-ai/polyglot-ai.php', (array) ( $transient->response ?? array() ) );
		$this->assertSame( 0, $this->requests );
	}
}
