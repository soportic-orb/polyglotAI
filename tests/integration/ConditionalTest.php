<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Content\Conditional;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\UrlConverter;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Content\Conditional
 */
final class ConditionalTest extends WP_UnitTestCase {

	private LanguageRegistry $languages;
	private RequestContext $request;

	/**
	 * Idiomas y shortcodes registrados.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array(
				new Language( 'en_US', 'en', 'English' ),
				new Language( 'ca', 'ca', 'Català' ),
			)
		);

		$this->request = new RequestContext(
			$this->languages,
			new UrlConverter( $this->languages, '/', false )
		);

		( new Conditional( $this->languages, $this->request ) )->register();
	}

	/**
	 * Sitúa la petición en un idioma.
	 *
	 * @param string $slug Slug del idioma.
	 */
	private function speaking( string $slug ): void {
		$language = $this->languages->by_slug( $slug );

		$this->assertNotNull( $language );

		$this->request->set_path( '/' );
		$this->request->force( $language );
	}

	public function test_muestra_solo_en_los_idiomas_indicados(): void {
		$content = '[pgai_if lang="en,ca"]Hola[/pgai_if]';

		$this->speaking( 'en' );
		$this->assertSame( 'Hola', do_shortcode( $content ) );

		$this->speaking( 'ca' );
		$this->assertSame( 'Hola', do_shortcode( $content ) );

		$this->speaking( 'es' );
		$this->assertSame( '', do_shortcode( $content ) );
	}

	public function test_oculta_en_los_idiomas_excluidos(): void {
		$content = '[pgai_if not="es"]Hola[/pgai_if]';

		$this->speaking( 'es' );
		$this->assertSame( '', do_shortcode( $content ) );

		$this->speaking( 'en' );
		$this->assertSame( 'Hola', do_shortcode( $content ) );

		$content = '[pgai_unless lang="es"]Hola[/pgai_unless]';

		$this->speaking( 'es' );
		$this->assertSame( '', do_shortcode( $content ) );

		$this->speaking( 'en' );
		$this->assertSame( 'Hola', do_shortcode( $content ) );
	}

	public function test_acepta_slug_y_locale(): void {
		$this->speaking( 'en' );

		$this->assertSame( 'Hola', do_shortcode( '[pgai_if lang="en_US"]Hola[/pgai_if]' ) );
		$this->assertSame( 'Hola', do_shortcode( '[pgai_if lang="en"]Hola[/pgai_if]' ) );
	}

	public function test_se_pueden_combinar_las_dos_condiciones(): void {
		$content = '[pgai_if lang="en,ca" not="ca"]Hola[/pgai_if]';

		$this->speaking( 'en' );
		$this->assertSame( 'Hola', do_shortcode( $content ) );

		$this->speaking( 'ca' );
		$this->assertSame( '', do_shortcode( $content ) );
	}

	public function test_se_pueden_anidar_con_nombres_distintos(): void {
		// WordPress no sabe anidar dos shortcodes con el mismo nombre: su
		// expresión regular cierra en el primer [/...]. Tener la forma negativa
		// con otro nombre es lo que permite anidar una condición dentro de otra.
		$content = '[pgai_unless lang="es"]fuera[pgai_if lang="ca"] dentro[/pgai_if][/pgai_unless]';

		$this->speaking( 'ca' );
		$this->assertSame( 'fuera dentro', do_shortcode( $content ) );

		$this->speaking( 'en' );
		$this->assertSame( 'fuera', do_shortcode( $content ) );

		$this->speaking( 'es' );
		$this->assertSame( '', do_shortcode( $content ) );
	}

	public function test_lo_que_no_se_muestra_no_llega_a_la_salida(): void {
		// Ocultarlo con CSS lo habría dejado en el HTML, y entonces el barrido
		// lo registraría y se pagaría por traducir algo que nadie ve.
		$this->speaking( 'es' );

		$html = do_shortcode( '[pgai_if lang="en"]<p>Secret</p>[/pgai_if]' );

		$this->assertStringNotContainsString( 'Secret', $html );
		$this->assertStringNotContainsString( 'display:none', $html );
	}

	public function test_puede_pedirse_que_no_se_traduzca(): void {
		$this->speaking( 'en' );

		$html = do_shortcode( '[pgai_if lang="en" translate="no"]Already English[/pgai_if]' );

		$this->assertStringContainsString( 'translate="no"', $html );
		$this->assertStringContainsString( 'notranslate', $html );
		$this->assertStringContainsString( 'Already English', $html );
	}

	public function test_por_defecto_si_se_traduce(): void {
		$this->speaking( 'en' );

		$this->assertStringNotContainsString(
			'notranslate',
			do_shortcode( '[pgai_if lang="en"]Hola[/pgai_if]' )
		);
	}

	public function test_sin_contenido_no_pinta_nada(): void {
		$this->speaking( 'en' );

		$this->assertSame( '', do_shortcode( '[pgai_if lang="en"][/pgai_if]' ) );
	}

	public function test_escribe_el_idioma_en_curso(): void {
		$this->speaking( 'ca' );

		$html = do_shortcode( '[pgai_language]' );

		$this->assertStringContainsString( 'Català', $html );

		// El nombre de un idioma no se traduce: «English» es English en todas
		// partes.
		$this->assertStringContainsString( 'translate="no"', $html );

		$this->assertStringContainsString( 'CA', do_shortcode( '[pgai_language display="code"]' ) );
		$this->assertStringContainsString( 'ca', do_shortcode( '[pgai_language display="locale"]' ) );
	}

	public function test_un_codigo_base_no_pisa_a_una_variante_configurada_aparte(): void {
		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'es_MX', 'es-mx', 'Español de México' ) )
		);

		$request = new RequestContext( $languages, new UrlConverter( $languages, '/', false ) );

		$mexican = $languages->by_slug( 'es-mx' );
		$this->assertNotNull( $mexican );

		$request->set_path( '/' );
		$request->force( $mexican );

		remove_shortcode( 'pgai_if' );
		( new Conditional( $languages, $request ) )->register();

		// Con «es» y «es-mx» configurados a la vez, «es» tiene que referirse
		// solo al primero: si no, no habría forma de decir «solo en el español
		// de España».
		$this->assertSame( '', do_shortcode( '[pgai_if lang="es"]Hola[/pgai_if]' ) );
		$this->assertSame( 'Hola', do_shortcode( '[pgai_if lang="es-mx"]Hola[/pgai_if]' ) );
	}
}
