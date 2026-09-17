<?php
/**
 * Construcción de la petición que se envía a la API.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines\Claude;

use PolyglotAI\Engines\EngineContext;
use PolyglotAI\Engines\TranslationRequest;

/**
 * Arma el cuerpo de la petición a /v1/messages.
 *
 * Es una función pura: entra un lote y un contexto, sale el array que se
 * serializa a JSON. Así se puede probar el prompt entero sin tocar la red.
 */
final class PromptBuilder {

	/**
	 * Mínimo de tokens que debe alcanzar el prefijo para que la caché de prompt
	 * llegue a activarse en claude-sonnet-5.
	 *
	 * Por debajo de esto la API NO cachea y no devuelve ningún error: el único
	 * síntoma es que cache_read_input_tokens se queda a cero. Se usa para avisar
	 * en el panel, no para bloquear la llamada.
	 */
	public const CACHE_MINIMUM_TOKENS = 1024;

	/**
	 * Constructor.
	 *
	 * @param string $model      Identificador del modelo.
	 * @param string $effort     Nivel de esfuerzo: low, medium, high, xhigh o max.
	 * @param bool   $thinking   Si se activa el razonamiento adaptativo.
	 * @param int    $cache_ttl  Minutos de vida de la caché de prompt: 5 o 60.
	 */
	public function __construct(
		private readonly string $model = 'claude-sonnet-5',
		private readonly string $effort = 'low',
		private readonly bool $thinking = false,
		private readonly int $cache_ttl = 5
	) {}

	/**
	 * Construye el cuerpo de la petición.
	 *
	 * @param TranslationRequest[] $requests Cadenas a traducir.
	 * @param EngineContext        $context  Contexto lingüístico.
	 * @return array<string, mixed>
	 */
	public function build( array $requests, EngineContext $context ): array {
		$body = array(
			'model'         => $this->model,
			'max_tokens'    => $this->max_tokens( $requests ),
			'system'        => $this->system( $context ),
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $this->user_message( $requests, $context ),
				),
			),
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => ResponseSchema::get(),
				),
				'effort' => $this->effort,
			),
		);

		// Traducir no requiere razonamiento extendido y encarece cada lote. Se
		// deja disponible para la pasada de reintento de cadenas difíciles.
		$body['thinking'] = $this->thinking
			? array( 'type' => 'adaptive' )
			: array( 'type' => 'disabled' );

		/**
		 * Permite ajustar el cuerpo de la petición antes de enviarla.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $body     Cuerpo de la petición.
		 * @param TranslationRequest[] $requests Cadenas del lote.
		 * @param EngineContext        $context  Contexto lingüístico.
		 */
		return apply_filters( 'pgai_claude_request_body', $body, $requests, $context );
	}

	/**
	 * Bloques del prompt del sistema.
	 *
	 * El orden de renderizado de la API es tools, system y messages, así que
	 * todo lo estable va aquí y el punto de corte de caché se pone en el último
	 * bloque: las cadenas del lote, que cambian siempre, viajan en messages y
	 * quedan después del corte.
	 *
	 * @param EngineContext $context Contexto lingüístico.
	 * @return array<int, array<string, mixed>>
	 */
	private function system( EngineContext $context ): array {
		$blocks = array(
			array(
				'type' => 'text',
				'text' => $this->rules( $context ),
			),
		);

		$reference = $this->reference( $context );

		if ( '' !== $reference ) {
			$blocks[] = array(
				'type' => 'text',
				'text' => $reference,
			);
		}

		$cache_control = array( 'type' => 'ephemeral' );

		if ( 5 !== $this->cache_ttl ) {
			$cache_control['ttl'] = '1h';
		}

		$blocks[ array_key_last( $blocks ) ]['cache_control'] = $cache_control;

		return $blocks;
	}

	/**
	 * Instrucciones del traductor.
	 *
	 * @param EngineContext $context Contexto lingüístico.
	 */
	private function rules( EngineContext $context ): string {
		$formality = match ( $context->formality ) {
			'formal'   => 'Usa siempre el tratamiento formal de cortesía del idioma de destino (usted, Sie, vous).',
			'informal' => 'Usa siempre el tratamiento informal del idioma de destino (tú, du, tu).',
			default    => 'Usa el tratamiento habitual en sitios web comerciales del idioma de destino.',
		};

		return implode(
			"\n",
			array(
				sprintf(
					'Eres un traductor profesional de sitios web. Traduces de %s (%s) a %s (%s).',
					$context->source_label,
					$context->source_locale,
					$context->target_label,
					$context->target_locale
				),
				$formality,
				'',
				'Reglas de obligado cumplimiento:',
				'1. Conserva TODAS las etiquetas HTML y sus atributos exactamente como están. Puedes traducir el contenido de alt, title, placeholder y aria-label, y nada más.',
				'2. Conserva los marcadores de posición tal cual: %s, %d, %1$s, {nombre}, {{nombre}} y ###CLAVE###. Puedes reordenarlos si el idioma lo exige, pero no puedes añadir ni eliminar ninguno.',
				'3. Conserva los shortcodes entre corchetes sin traducir su nombre ni sus atributos.',
				'4. No modifiques URLs, direcciones de correo, números, precios ni códigos.',
				'5. Conserva el espaciado inicial y final del original.',
				'6. Traduce el sentido, no palabra por palabra. Adapta lo que sea idiomático.',
				'7. Si una cadena no necesita traducción (un nombre propio, una marca), devuélvela idéntica.',
				'',
				'Devuelve una entrada por cada cadena recibida, con su identificador exacto. No añadas comentarios ni explicaciones.',
			)
		);
	}

	/**
	 * Contexto del sitio, glosario y términos intraducibles.
	 *
	 * @param EngineContext $context Contexto lingüístico.
	 */
	private function reference( EngineContext $context ): string {
		$parts = array();

		if ( '' !== trim( $context->site_context ) ) {
			$parts[] = "Contexto del sitio:\n" . trim( $context->site_context );
		}

		if ( array() !== $context->glossary ) {
			$lines = array( 'Glosario de uso obligatorio. Traduce estos términos exactamente así:' );

			foreach ( $context->glossary as $source => $target ) {
				$lines[] = sprintf( '- %s => %s', $source, $target );
			}

			$parts[] = implode( "\n", $lines );
		}

		if ( array() !== $context->do_not_translate ) {
			$parts[] = "No traduzcas nunca estos términos; déjalos idénticos:\n- " . implode( "\n- ", $context->do_not_translate );
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Mensaje de usuario con el lote.
	 *
	 * @param TranslationRequest[] $requests Cadenas a traducir.
	 * @param EngineContext        $context  Contexto lingüístico.
	 */
	private function user_message( array $requests, EngineContext $context ): string {
		$strings = array();

		foreach ( $requests as $request ) {
			$entry = array(
				'id'   => $request->id,
				'type' => $request->type->value,
				'text' => $request->text,
			);

			if ( null !== $request->context && '' !== $request->context ) {
				$entry['context'] = $request->context;
			}

			$strings[] = $entry;
		}

		$payload = array( 'strings' => $strings );

		// Las cadenas vecinas dan coherencia al lote (mismo producto, misma
		// sección) y no se traducen: son solo contexto.
		if ( array() !== $context->nearby ) {
			$payload['nearby_for_context_only'] = array_values( $context->nearby );
		}

		return (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Calcula el techo de tokens de salida del lote.
	 *
	 * La traducción tiene un tamaño parecido al original, pero hay idiomas que
	 * se alargan bastante; se deja margen y se acota por arriba para que la
	 * respuesta quepa en el tiempo de espera de wp_remote_post, que es
	 * bloqueante y no admite streaming.
	 *
	 * @param TranslationRequest[] $requests Cadenas a traducir.
	 */
	private function max_tokens( array $requests ): int {
		$characters = 0;

		foreach ( $requests as $request ) {
			$characters += strlen( $request->text ) + strlen( $request->id ) + 32;
		}

		// Aproximación conservadora de 3 caracteres por token, por 3 de margen.
		$estimate = (int) ceil( $characters / 3 ) * 3;

		return max( 4096, min( 16000, $estimate ) );
	}
}
