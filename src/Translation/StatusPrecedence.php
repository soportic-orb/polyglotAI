<?php
/**
 * Reglas de sobrescritura entre estados de traducción.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

/**
 * Única autoridad sobre si una escritura puede pisar una traducción existente.
 *
 * Es un criterio de aceptación del producto que las correcciones manuales no se
 * pierdan NUNCA por una traducción automática. Toda ruta de escritura —editor,
 * lote, sitio completo, reimportación, cambio de modelo— pasa por aquí. No
 * dupliques esta lógica en ningún otro sitio.
 */
final class StatusPrecedence {

	/**
	 * Si una escritura con estado $incoming puede sustituir a $current.
	 *
	 * @param Status|null $current  Estado actual, o null si no hay traducción.
	 * @param Status      $incoming Estado de la escritura entrante.
	 * @param bool        $forced   True si una persona ha pedido explícitamente
	 *                              retraducir esta cadena.
	 */
	public function can_overwrite( ?Status $current, Status $incoming, bool $forced = false ): bool {
		if ( null === $current ) {
			return true;
		}

		// Una escritura humana siempre manda: el editor visual puede corregir
		// cualquier cosa, incluida otra traducción manual.
		if ( $incoming->is_human() ) {
			return true;
		}

		// A partir de aquí la escritura es automática.

		// Lo que ha tocado una persona no lo pisa una máquina. Ni siquiera
		// cuando se fuerza una retraducción: para eso hay que cambiar antes el
		// estado a mano.
		if ( $current->is_human() ) {
			return false;
		}

		// Pendiente y error se reescriben siempre: no hay nada que perder.
		if ( Status::Pending === $current || Status::Error === $current ) {
			return true;
		}

		// Sobre una traducción automática previa solo se escribe si alguien ha
		// pedido la retraducción de forma explícita.
		return $forced;
	}
}
