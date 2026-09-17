<?php
/**
 * Captura y traducción de la salida del frontal.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

use PolyglotAI\Editor\PreviewRenderer;
use PolyglotAI\Routing\LinkRewriter;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Translation\DictionaryFactory;
use PolyglotAI\Translation\MissingQueue;

/**
 * Traduce el HTML renderizado de cada página.
 *
 * Se engancha en template_redirect con prioridad 1, antes de que se emita
 * wp_head, para que el buffer capture el documento entero incluida la cabecera.
 */
final class OutputBuffer {

	/**
	 * Constructor.
	 *
	 * @param BailConditions          $bail       Condiciones de abandono.
	 * @param RequestContext          $request    Contexto de la petición.
	 * @param DocumentProcessor       $processor  Traductor de documentos.
	 * @param DocumentDriverInterface $driver Driver de extracción.
	 * @param DictionaryFactory       $dictionary Constructor de diccionarios.
	 * @param MissingQueue            $queue      Cola de cadenas sin traducir.
	 * @param LinkRewriter            $links      Reescritor de enlaces internos.
	 * @param PreviewRenderer|null    $preview    Vista previa del editor visual.
	 */
	public function __construct(
		private readonly BailConditions $bail,
		private readonly RequestContext $request,
		private readonly DocumentProcessor $processor,
		private readonly DocumentDriverInterface $driver,
		private readonly DictionaryFactory $dictionary,
		private readonly MissingQueue $queue,
		private readonly LinkRewriter $links,
		private readonly ?PreviewRenderer $preview = null
	) {}

	/**
	 * Engancha la captura de la salida.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'start' ), 1 );
	}

	/**
	 * Arranca el buffer si procede.
	 */
	public function start(): void {
		// La vista previa del editor fija su propio idioma, así que se comprueba
		// antes que nada: sin esto, editar una página cuya URL es la del idioma
		// por defecto abriría un iframe sin marcar.
		if ( null !== $this->preview && $this->preview->is_active() ) {
			ob_start( array( $this, 'filter' ) );

			return;
		}

		// En el idioma por defecto el contenido ya está en su idioma: ni se
		// arranca el buffer, para no pagar la copia del HTML.
		if ( $this->request->is_default() || ! $this->bail->should_process() ) {
			return;
		}

		ob_start( array( $this, 'filter' ) );
	}

	/**
	 * Traduce el HTML capturado.
	 *
	 * @param string $html Salida renderizada.
	 * @return string Salida traducida, o la original ante cualquier problema.
	 */
	public function filter( string $html ): string {
		if ( ! $this->is_html_response( $html ) ) {
			return $html;
		}

		$editing  = null !== $this->preview && $this->preview->is_active();
		$language = $this->request->language();
		$units    = $this->driver->extract( $html );

		if ( array() !== $units ) {
			$dictionary = $this->dictionary->build( $units, $language->locale, $editing );

			if ( $editing && null !== $this->preview ) {
				$preview = $this->preview;

				$html = $preview->inject(
					$this->processor->translate(
						$html,
						static fn( ExtractedString $unit ) => $preview->decorate( $unit, $dictionary )
					)
				);
			} else {
				// Las cadenas que faltan se anotan para traducirlas en segundo
				// plano. Aquí NO se llama a la API: la carga del visitante no se
				// bloquea nunca.
				if ( $dictionary->has_missing() ) {
					$this->queue->enqueue( $dictionary->missing(), $language->locale );
				}

				$html = $this->processor->translate(
					$html,
					static fn( ExtractedString $unit ): ?string => $dictionary->get( $unit )
				);
			}
		}

		// Segunda pasada, independiente: un enlace puede vivir dentro de una
		// unidad de bloque ya traducida, y hacer ambas cosas a la vez produciría
		// sustituciones solapadas.
		return $this->links->rewrite( $html, $language );
	}

	/**
	 * Si la respuesta es un documento HTML.
	 *
	 * Un buffer de salida también captura JSON, XML y descargas; procesarlos
	 * como HTML los corrompería.
	 *
	 * @param string $html Salida capturada.
	 */
	private function is_html_response( string $html ): bool {
		if ( '' === trim( $html ) ) {
			return false;
		}

		foreach ( headers_list() as $header ) {
			if ( ! str_starts_with( strtolower( $header ), 'content-type:' ) ) {
				continue;
			}

			return str_contains( strtolower( $header ), 'text/html' );
		}

		// Sin cabecera explícita, WordPress sirve text/html por defecto.
		return true;
	}
}
