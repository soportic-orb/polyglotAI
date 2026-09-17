<?php
/**
 * Traducción de los correos salientes.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Mail;

use PolyglotAI\Html\DocumentProcessor;
use PolyglotAI\Html\ExtractedString;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Translation\TranslationLookup;

/**
 * Traduce el asunto y el cuerpo de los correos al idioma del destinatario.
 *
 * Actúa sobre el mensaje ya construido, que es lo único que un plugin puede
 * hacer de forma genérica: cuando wp_mail recibe el correo, quien lo generó ya
 * ha resuelto sus textos. Las integraciones que sí pueden intervenir antes
 * —WooCommerce y su plantilla de pedido— usan pgai_with_language().
 */
final class MailTranslator {

	/**
	 * Constructor.
	 *
	 * @param LanguageResolver  $resolver   Resolutor del idioma del destinatario.
	 * @param LanguageRegistry  $languages  Idiomas del sitio.
	 * @param DocumentProcessor $processor  Traductor de documentos.
	 * @param TranslationLookup $lookup     Búsqueda de cadenas sueltas.
	 */
	public function __construct(
		private readonly LanguageResolver $resolver,
		private readonly LanguageRegistry $languages,
		private readonly DocumentProcessor $processor,
		private readonly TranslationLookup $lookup
	) {}

	/**
	 * Engancha la traducción.
	 */
	public function register(): void {
		add_filter( 'wp_mail', array( $this, 'translate' ) );
	}

	/**
	 * Traduce un correo.
	 *
	 * @param array<string, mixed> $mail Argumentos de wp_mail.
	 * @return array<string, mixed>
	 */
	public function translate( $mail ): array {
		if ( ! is_array( $mail ) ) {
			return $mail;
		}

		/** @var string|string[] $to */
		$to       = $mail['to'] ?? '';
		$language = $this->resolver->for_recipient( $to );

		if ( $this->languages->is_default( $language->slug ) ) {
			return $mail;
		}

		if ( isset( $mail['subject'] ) && is_string( $mail['subject'] ) ) {
			$mail['subject'] = $this->lookup->text( $mail['subject'], $language->locale );
		}

		if ( isset( $mail['message'] ) && is_string( $mail['message'] ) && '' !== $mail['message'] ) {
			$mail['message'] = $this->translate_body( $mail['message'], $language->locale );
		}

		/**
		 * Se dispara tras traducir un correo.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $mail     Argumentos ya traducidos.
		 * @param string               $language Locale de destino.
		 */
		return (array) apply_filters( 'pgai_translated_mail', $mail, $language->locale );
	}

	/**
	 * Traduce el cuerpo del mensaje.
	 *
	 * @param string $body     Cuerpo.
	 * @param string $language Locale.
	 */
	private function translate_body( string $body, string $language ): string {
		// Un correo en HTML se trata como cualquier otro documento. Uno de texto
		// plano no tiene estructura que recorrer, así que se traduce por líneas:
		// tratarlo como una sola cadena obligaría a traducir el mensaje entero
		// de nuevo cada vez que cambiara una palabra.
		if ( str_contains( $body, '<' ) ) {
			return $this->processor->translate(
				$body,
				fn( ExtractedString $unit ): ?string => $this->lookup->for_unit( $unit, $language )
			);
		}

		$lines = preg_split( '/(\R)/u', $body, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $lines ) {
			return $body;
		}

		foreach ( $lines as $index => $line ) {
			if ( '' !== trim( $line ) ) {
				$lines[ $index ] = $this->lookup->text( $line, $language );
			}
		}

		return implode( '', $lines );
	}
}
