<?php
/**
 * Vista previa del editor visual.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Editor;

use PolyglotAI\Html\ExtractedString;
use PolyglotAI\Html\RawHtml;
use PolyglotAI\Translation\MissingQueue;
use PolyglotAI\Translation\PageDictionary;

/**
 * Prepara la página que se muestra dentro del iframe del editor.
 *
 * Hace tres cosas que una visita normal no hace: marcar cada cadena para que el
 * panel pueda localizarla, registrar en el diccionario las cadenas que aún no
 * existen —un traductor tiene que poder corregir también lo que nadie ha visto
 * todavía— y adjuntar el guion que comunica el iframe con el panel.
 */
final class PreviewRenderer {

	/**
	 * Cadenas registradas en esta página, por hash.
	 *
	 * @var array<string, array{unit:ExtractedString, hash:string}>
	 */
	private array $seen = array();

	/**
	 * Constructor.
	 *
	 * @param EditMode        $mode      Detector del modo de edición.
	 * @param MarkerDecorator $decorator Marcador de cadenas.
	 * @param MissingQueue    $queue     Registro de cadenas.
	 */
	public function __construct(
		private readonly EditMode $mode,
		private readonly MarkerDecorator $decorator,
		private readonly MissingQueue $queue
	) {}

	/**
	 * Si la petición actual es la vista previa del editor.
	 */
	public function is_active(): bool {
		return $this->mode->is_active();
	}

	/**
	 * Encola los recursos de la vista previa.
	 *
	 * Se encolan por la vía normal de WordPress; lo único que se inyecta a mano
	 * es el JSON con las cadenas, porque no se conocen hasta que el documento
	 * entero se ha recorrido, y para entonces wp_footer ya ha pasado. Por eso el
	 * guion espera a DOMContentLoaded antes de leerlo.
	 */
	public function enqueue(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		wp_enqueue_script( 'pgai-preview', PGAI_URL . 'assets/build/preview.js', array(), PGAI_VERSION, true );
		wp_enqueue_style( 'pgai-preview', PGAI_URL . 'assets/build/style-preview.css', array(), PGAI_VERSION );

		// Un idioma RTL se traduce dentro de la propia vista previa, así que la
		// hoja invertida hace falta también aquí.
		wp_style_add_data( 'pgai-preview', 'rtl', 'replace' );
	}

	/**
	 * Devuelve la sustitución marcada de una unidad.
	 *
	 * @param ExtractedString $unit       Unidad extraída.
	 * @param PageDictionary  $dictionary Diccionario de la página.
	 * @return RawHtml|string|null
	 */
	public function decorate( ExtractedString $unit, PageDictionary $dictionary ): RawHtml|string|null {
		$translation = $dictionary->get( $unit );

		$this->seen[ $dictionary->hash_of( $unit ) ] = array(
			'unit' => $unit,
			'hash' => $dictionary->hash_of( $unit ),
		);

		return $this->decorator->decorate( $unit, $translation, $dictionary->status_of( $unit ) );
	}

	/**
	 * Adjunta al documento los datos y el guion del editor.
	 *
	 * @param string $html Documento ya traducido y marcado.
	 */
	public function inject( string $html ): string {
		$language = $this->mode->language();

		if ( null === $language ) {
			return $html;
		}

		// Registrar aquí, y no durante el barrido, permite hacerlo en una sola
		// operación por página en lugar de una por cadena.
		$this->queue->record( $this->seen, $language->locale );

		$payload = array(
			'language' => $language->locale,
			'rtl'      => $language->rtl,
			'strings'  => $this->decorator->collected(),
		);

		$script = sprintf(
			'<script id="pgai-preview-data">window.pgaiPreview = %s;</script>',
			wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		$position = strripos( $html, '</body>' );

		if ( false === $position ) {
			return $html . $script;
		}

		return substr_replace( $html, $script . "\n", $position, 0 );
	}
}
