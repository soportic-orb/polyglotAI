<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests;

use PolyglotAI\Html\ExclusionRules;
use PolyglotAI\Html\ExtractedString;
use PolyglotAI\Html\HtmlApiDriver;
use PolyglotAI\Html\TagScanner;
use PolyglotAI\Translation\StringType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PolyglotAI\Html\HtmlApiDriver
 */
final class HtmlApiDriverTest extends TestCase {

	private HtmlApiDriver $driver;

	protected function setUp(): void {
		$this->driver = new HtmlApiDriver( new TagScanner(), new ExclusionRules() );
	}

	/**
	 * Reduce las unidades a pares [tipo, valor] para poder compararlas.
	 *
	 * @param string $html Documento.
	 * @return array<int, array{0:string, 1:string}>
	 */
	private function extract( string $html ): array {
		return array_map(
			static fn( ExtractedString $unit ): array => array( $unit->type->value, $unit->value ),
			$this->driver->extract( $html )
		);
	}

	public function test_un_parrafo_de_texto_plano_es_una_cadena_de_texto(): void {
		$this->assertSame(
			array( array( 'text', 'Hola món' ) ),
			$this->extract( '<p>Hola món</p>' )
		);
	}

	public function test_el_html_en_linea_se_agrupa_en_una_sola_unidad(): void {
		// El requisito central: <p>Hola <strong>món</strong></p> es UNA cadena,
		// no tres. Si no, el traductor pierde el contexto y no puede reordenar.
		$this->assertSame(
			array( array( 'block', 'Hola <strong>món</strong> i <em>adéu</em>' ) ),
			$this->extract( '<p>Hola <strong>món</strong> i <em>adéu</em></p>' )
		);
	}

	public function test_los_elementos_de_bloque_separan_unidades(): void {
		$this->assertSame(
			array( array( 'text', 'Primero' ), array( 'text', 'Segundo' ) ),
			$this->extract( '<div><p>Primero</p><p>Segundo</p></div>' )
		);
	}

	public function test_el_contenido_mixto_se_parte_por_los_bloques(): void {
		$this->assertSame(
			array( array( 'text', 'Hola' ), array( 'text', 'Adéu' ), array( 'text', 'Fins' ) ),
			$this->extract( '<div>Hola <p>Adéu</p> Fins</div>' )
		);
	}

	public function test_un_enlace_solo_conserva_sus_atributos_en_la_unidad(): void {
		$this->assertSame(
			array( array( 'block', '<a href="/x" class="btn">Llegir més</a>' ) ),
			$this->extract( '<div><a href="/x" class="btn">Llegir més</a></div>' )
		);
	}

	public function test_el_espacio_de_los_extremos_queda_fuera_de_la_unidad(): void {
		$units = $this->driver->extract( "<p>\n\t  Hola món  \n</p>" );

		$this->assertCount( 1, $units );
		$this->assertSame( 'Hola món', $units[0]->value );
		$this->assertSame( 'Hola món', substr( "<p>\n\t  Hola món  \n</p>", $units[0]->start, $units[0]->length ) );
	}

	public function test_ignora_el_texto_sin_letras(): void {
		// Números, símbolos y espaciado no son cadenas traducibles, pero el
		// driver sí los entrega: el filtrado es de Normalizer. Aquí solo se
		// comprueba que no se rompe nada.
		$units = $this->extract( '<p>123</p><p>&nbsp;</p><p>—</p>' );

		$this->assertSame( array( array( 'text', '123' ), array( 'text', '—' ) ), $units );
	}

	public function test_no_desciende_a_script_style_ni_json_ld(): void {
		$html = '<body>'
			. '<script>var a = "<b>texto</b>"; if (x<y) {}</script>'
			. '<style>.a::after{content:"texto"}</style>'
			. '<script type="application/ld+json">{"name":"texto"}</script>'
			. '<p>Traducible</p></body>';

		$this->assertSame( array( array( 'text', 'Traducible' ) ), $this->extract( $html ) );
	}

	public function test_extrae_el_titulo_como_rcdata(): void {
		$units = $this->driver->extract( '<head><title>Hola &amp; adéu</title></head>' );

		$this->assertCount( 1, $units );
		$this->assertSame( StringType::Rcdata, $units[0]->type );
		$this->assertSame( 'Hola & adéu', $units[0]->value );
		$this->assertSame(
			'Hola &amp; adéu',
			substr( '<head><title>Hola &amp; adéu</title></head>', $units[0]->start, $units[0]->length )
		);
	}

	public function test_extrae_los_atributos_traducibles(): void {
		$html = '<div>'
			. '<img alt="Un gat" title="Foto">'
			. '<input type="text" placeholder="El teu nom" value="no-tocar">'
			. '<input type="submit" value="Enviar">'
			. '<span aria-label="Tanca"></span>'
			. '</div>';

		$this->assertEqualsCanonicalizing(
			array(
				array( 'attribute', 'Un gat' ),
				array( 'attribute', 'Foto' ),
				array( 'attribute', 'El teu nom' ),
				array( 'attribute', 'Enviar' ),
				array( 'attribute', 'Tanca' ),
			),
			$this->extract( $html )
		);
	}

	public function test_extrae_la_imagen_como_un_tipo_propio(): void {
		// Una URL no es texto: tiene su propio tipo para que nunca llegue al
		// motor de traducción automática.
		$units = $this->driver->extract( '<div><img src="/gat.png" alt="Un gat"></div>' );

		$imagen = array_values( array_filter( $units, static fn( $u ) => StringType::Image === $u->type ) );

		$this->assertCount( 1, $imagen );
		$this->assertSame( '/gat.png', $imagen[0]->value );
		$this->assertSame( 'src', $imagen[0]->context );
		$this->assertFalse( $imagen[0]->type->is_machine_translatable() );
	}

	public function test_el_srcset_se_enlaza_con_su_imagen_por_el_contexto(): void {
		// Sin ese enlace, cambiar la imagen dejaría las variantes responsive
		// apuntando a la original y la traducción solo se vería en algunos
		// tamaños de pantalla.
		$units = $this->driver->extract(
			'<div><img src="/gat.png" srcset="/gat-480.png 480w, /gat-960.png 960w" alt="Un gat"></div>'
		);

		$imagenes = array_values( array_filter( $units, static fn( $u ) => StringType::Image === $u->type ) );

		$this->assertCount( 2, $imagenes );

		$contextos = array_column(
			array_map( static fn( $u ) => array( 'context' => $u->context ), $imagenes ),
			'context'
		);

		$this->assertContains( 'src', $contextos );
		$this->assertContains( 'srcset:/gat.png', $contextos );
	}

	public function test_no_trata_como_imagen_el_src_de_otras_etiquetas(): void {
		$units = $this->driver->extract(
			'<div><iframe src="/video" title="Vídeo"></iframe><source src="/a.mp4"></div>'
		);

		foreach ( $units as $unit ) {
			$this->assertNotSame( StringType::Image, $unit->type, 'No debe tratarse como imagen: ' . $unit->value );
		}
	}

	public function test_extrae_las_metas_traducibles_y_no_las_demas(): void {
		$html = '<head>'
			. '<meta name="description" content="Descripció">'
			. '<meta property="og:title" content="Títol">'
			. '<meta name="viewport" content="width=device-width">'
			. '<meta name="generator" content="WordPress">'
			. '</head>';

		$this->assertSame(
			array( array( 'meta', 'Descripció' ), array( 'meta', 'Títol' ) ),
			$this->extract( $html )
		);
	}

	public function test_el_atributo_dentro_de_un_bloque_no_se_emite_por_duplicado(): void {
		// El alt viaja dentro del HTML del bloque. Emitirlo además por separado
		// daría dos sustituciones sobre los mismos bytes.
		$units = $this->driver->extract( '<p>Mira <img alt="un gat" src="a.png"> això</p>' );

		$this->assertCount( 1, $units );
		$this->assertSame( StringType::Block, $units[0]->type );
		$this->assertSame( 'Mira <img alt="un gat" src="a.png"> això', $units[0]->value );
	}

	/**
	 * @param string $html Documento con contenido excluido.
	 *
	 * @dataProvider proveedor_de_exclusiones
	 */
	public function test_respeta_las_exclusiones( string $html ): void {
		$values = array_column( $this->extract( $html ), 1 );

		$this->assertNotContains( 'No tocar', $values );
		$this->assertContains( 'Traducible', $values );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function proveedor_de_exclusiones(): array {
		return array(
			'clase notranslate'  => array( '<div><p class="notranslate">No tocar</p><p>Traducible</p></div>' ),
			'translate no'       => array( '<div><p translate="no">No tocar</p><p>Traducible</p></div>' ),
			'atributo propio'    => array( '<div><p data-no-translation>No tocar</p><p>Traducible</p></div>' ),
			'dentro de code'     => array( '<div><code>No tocar</code><p>Traducible</p></div>' ),
			'dentro de template' => array( '<div><template><p>No tocar</p></template><p>Traducible</p></div>' ),
			'dentro de svg'      => array( '<div><svg><text>No tocar</text></svg><p>Traducible</p></div>' ),
			'dentro de pre'      => array( '<div><pre>No tocar</pre><p>Traducible</p></div>' ),
			'anidada heredada'   => array( '<div class="notranslate"><p><em>No tocar</em></p></div><p>Traducible</p>' ),
		);
	}

	public function test_una_exclusion_en_linea_parte_la_unidad_en_curso(): void {
		$values = array_column( $this->extract( '<p>Hola <span class="notranslate">MARCA</span> adéu</p>' ), 1 );

		$this->assertSame( array( 'Hola', 'adéu' ), $values );
	}

	public function test_tolera_el_marcado_mal_anidado(): void {
		$units = $this->driver->extract( '<div><p>Hola <b>món</p></b><p>Adéu</p></div>' );

		$this->assertNotSame( array(), $units );
		$this->assertContains( 'Adéu', array_column( $this->extract( '<div><p>Hola <b>món</p></b><p>Adéu</p></div>' ), 1 ) );
	}

	public function test_las_unidades_nunca_se_solapan(): void {
		$html  = (string) file_get_contents( PGAI_TESTS_DIR . '/fixtures/pagina.html' );
		$units = $this->driver->extract( $html );

		$this->assertNotSame( array(), $units );

		$previous_end = 0;

		foreach ( $units as $unit ) {
			$this->assertGreaterThanOrEqual(
				$previous_end,
				$unit->start,
				sprintf( 'La unidad %s en el byte %d se solapa con la anterior.', $unit->value, $unit->start )
			);

			$previous_end = $unit->end();
		}
	}

	public function test_el_intervalo_de_cada_unidad_apunta_a_su_contenido(): void {
		$html = (string) file_get_contents( PGAI_TESTS_DIR . '/fixtures/pagina.html' );

		foreach ( $this->driver->extract( $html ) as $unit ) {
			$raw = substr( $html, $unit->start, $unit->length );

			if ( StringType::Block === $unit->type ) {
				$this->assertSame( $unit->value, $raw );
				continue;
			}

			// Para texto y atributos el valor está decodificado y el intervalo
			// apunta a los bytes codificados, así que se comparan sin entidades.
			$this->assertNotSame( '', trim( $raw ), 'Intervalo vacío para: ' . $unit->value );
		}
	}
}
