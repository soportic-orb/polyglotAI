<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Html\DocumentProcessor;
use PolyglotAI\Html\Escaper;
use PolyglotAI\Html\ExclusionRules;
use PolyglotAI\Html\HtmlApiDriver;
use PolyglotAI\Html\SafetyCheck;
use PolyglotAI\Html\Splicer;
use PolyglotAI\Html\TagScanner;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Mail\LanguageResolver;
use PolyglotAI\Mail\MailTranslator;
use PolyglotAI\Translation\Hasher;
use PolyglotAI\Translation\Normalizer;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use PolyglotAI\Translation\StringType;
use PolyglotAI\Translation\TranslationLookup;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Mail\MailTranslator
 * @covers \PolyglotAI\Mail\LanguageResolver
 */
final class MailTranslationTest extends WP_UnitTestCase {

	private LanguageRegistry $languages;
	private SourceRepository $sources;
	private TranslationRepository $translations;
	private Hasher $hasher;

	/**
	 * Prepara el escenario.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		foreach ( Schema::names() as $name ) {
			$table = Schema::table( $name );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM `{$table}`" );
		}

		$this->languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English' ) )
		);

		$this->sources      = new SourceRepository();
		$this->translations = new TranslationRepository( new StatusPrecedence() );
		$this->hasher       = new Hasher( new Normalizer() );
	}

	/**
	 * Construye el traductor de correos.
	 */
	private function translator(): MailTranslator {
		$lookup = new TranslationLookup(
			$this->sources,
			$this->translations,
			$this->hasher,
			new Normalizer()
		);

		return new MailTranslator(
			new LanguageResolver( $this->languages ),
			$this->languages,
			new DocumentProcessor(
				new HtmlApiDriver( new TagScanner(), new ExclusionRules() ),
				new Splicer(),
				new Escaper(),
				new SafetyCheck()
			),
			$lookup
		);
	}

	/**
	 * Registra una traducción lista para usarse.
	 *
	 * @param string     $original    Original.
	 * @param string     $translation Traducción.
	 * @param StringType $type        Tipo.
	 */
	private function given_translation( string $original, string $translation, StringType $type = StringType::Text ): void {
		$hash = $this->hasher->hash( $original, $type );
		$id   = $this->sources->remember( $hash, $original, $type );

		$this->translations->save( $id, 'en_US', $translation, Status::Manual );
	}

	/**
	 * Crea un usuario con un idioma preferido.
	 *
	 * @param string $email  Correo.
	 * @param string $locale Locale.
	 */
	private function given_user( string $email, string $locale ): int {
		$user_id = self::factory()->user->create( array( 'user_email' => $email ) );

		update_user_meta( $user_id, LanguageResolver::USER_META, $locale );

		return $user_id;
	}

	public function test_traduce_el_asunto_al_idioma_del_destinatario(): void {
		$this->given_user( 'cliente@ejemplo.com', 'en_US' );
		$this->given_translation( 'Tu pedido está en camino', 'Your order is on its way' );

		$mail = $this->translator()->translate(
			array(
				'to'      => 'cliente@ejemplo.com',
				'subject' => 'Tu pedido está en camino',
				'message' => '',
			)
		);

		$this->assertSame( 'Your order is on its way', $mail['subject'] );
	}

	public function test_el_idioma_es_el_del_destinatario_no_el_de_quien_envia(): void {
		// Un administrador cambia el estado de un pedido desde el escritorio en
		// español: el cliente debe recibir su aviso en inglés igualmente.
		$this->given_user( 'cliente@ejemplo.com', 'en_US' );
		$this->given_translation( 'Gracias por tu compra', 'Thanks for your purchase' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$mail = $this->translator()->translate(
			array(
				'to'      => 'cliente@ejemplo.com',
				'subject' => 'Gracias por tu compra',
				'message' => '',
			)
		);

		$this->assertSame( 'Thanks for your purchase', $mail['subject'] );
	}

	public function test_traduce_el_cuerpo_html(): void {
		$this->given_user( 'cliente@ejemplo.com', 'en_US' );
		$this->given_translation( 'Hola', 'Hello' );
		$this->given_translation( 'Tu pedido ya está listo', 'Your order is ready' );

		$mail = $this->translator()->translate(
			array(
				'to'      => 'cliente@ejemplo.com',
				'subject' => '',
				'message' => '<html><body><h1>Hola</h1><p>Tu pedido ya está listo</p></body></html>',
			)
		);

		$this->assertStringContainsString( '<h1>Hello</h1>', $mail['message'] );
		$this->assertStringContainsString( '<p>Your order is ready</p>', $mail['message'] );
	}

	public function test_traduce_el_cuerpo_de_texto_plano_linea_a_linea(): void {
		// Tratarlo como una sola cadena obligaría a traducir el mensaje entero
		// de nuevo cada vez que cambiara una palabra.
		$this->given_user( 'cliente@ejemplo.com', 'en_US' );
		$this->given_translation( 'Hola', 'Hello' );
		$this->given_translation( 'Gracias por tu compra', 'Thanks for your purchase' );

		$mail = $this->translator()->translate(
			array(
				'to'      => 'cliente@ejemplo.com',
				'subject' => '',
				'message' => "Hola\n\nGracias por tu compra",
			)
		);

		$this->assertSame( "Hello\n\nThanks for your purchase", $mail['message'] );
	}

	public function test_no_toca_el_correo_de_un_destinatario_sin_idioma_propio(): void {
		$this->given_translation( 'Hola', 'Hello' );

		$original = array(
			'to'      => 'desconocido@ejemplo.com',
			'subject' => 'Hola',
			'message' => 'Hola',
		);

		$this->assertSame( $original, $this->translator()->translate( $original ) );
	}

	public function test_reconoce_el_destinatario_con_nombre(): void {
		$this->given_user( 'cliente@ejemplo.com', 'en_US' );
		$this->given_translation( 'Hola', 'Hello' );

		$mail = $this->translator()->translate(
			array(
				'to'      => 'Ana Pérez <cliente@ejemplo.com>',
				'subject' => 'Hola',
				'message' => '',
			)
		);

		$this->assertSame( 'Hello', $mail['subject'] );
	}

	public function test_una_integracion_puede_imponer_el_idioma(): void {
		// Es la vía por la que WooCommerce aporta el idioma de un pedido, que
		// puede no corresponder a ningún usuario registrado.
		$this->given_translation( 'Hola', 'Hello' );

		add_filter(
			'pgai_recipient_language',
			fn() => $this->languages->by_locale( 'en_US' )
		);

		$mail = $this->translator()->translate(
			array(
				'to'      => 'invitado@ejemplo.com',
				'subject' => 'Hola',
				'message' => '',
			)
		);

		$this->assertSame( 'Hello', $mail['subject'] );
	}

	public function test_un_asunto_sin_traducir_queda_anotado_para_traducirlo(): void {
		// Así aparece en el gestor de cadenas la primera vez que se envía, en
		// lugar de no enterarse nunca de que existe.
		$this->given_user( 'cliente@ejemplo.com', 'en_US' );

		$this->translator()->translate(
			array(
				'to'      => 'cliente@ejemplo.com',
				'subject' => 'Un asunto que nadie ha traducido',
				'message' => '',
			)
		);

		$hash = $this->hasher->hash( 'Un asunto que nadie ha traducido', StringType::Text );

		$this->assertArrayHasKey( $hash, $this->sources->ids_by_hash( array( $hash ) ) );
	}
}
