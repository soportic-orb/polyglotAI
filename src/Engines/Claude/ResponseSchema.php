<?php
/**
 * Esquema JSON de la respuesta del motor.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines\Claude;

/**
 * Esquema de salida estructurada del lote de traducciones.
 *
 * Es deliberadamente FIJO: no lleva los identificadores del lote como
 * propiedades, sino una lista de pares. Un esquema estable entre lotes
 * aprovecha la caché de compilación de esquemas de la API; uno generado a medida
 * en cada lote pagaría esa compilación una y otra vez.
 *
 * El subconjunto de JSON Schema admitido por la API no acepta esquemas
 * recursivos, restricciones numéricas ni de longitud, y additionalProperties
 * solo puede valer false: no añadas nada de eso aquí.
 */
final class ResponseSchema {

	/**
	 * Esquema de la respuesta.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'translations' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'   => array(
								'type'        => 'string',
								'description' => 'El identificador exacto recibido en la petición.',
							),
							'text' => array(
								'type'        => 'string',
								'description' => 'La cadena traducida al idioma de destino.',
							),
						),
						'required'             => array( 'id', 'text' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'translations' ),
			'additionalProperties' => false,
		);
	}
}
