<?php
/**
 * Estados de una traducción.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

/**
 * Estado de una traducción, en orden de precedencia ascendente.
 *
 * El orden importa: define qué puede sobrescribir qué (ver StatusPrecedence).
 */
enum Status: string {
	/** Detectada pero sin traducir. */
	case Pending = 'pending';

	/** El motor devolvió algo que no pasó la validación estructural. */
	case Error = 'error';

	/** Traducida por un motor automático, sin revisar. */
	case Automatic = 'automatic';

	/** Traducción automática revisada y aprobada por una persona. */
	case Reviewed = 'reviewed';

	/** Escrita o corregida a mano por una persona. */
	case Manual = 'manual';

	/**
	 * Peso de precedencia. Mayor gana.
	 */
	public function weight(): int {
		return match ( $this ) {
			self::Pending   => 0,
			self::Error     => 1,
			self::Automatic => 2,
			self::Reviewed  => 3,
			self::Manual    => 4,
		};
	}

	/**
	 * Si el estado representa una intervención humana deliberada.
	 */
	public function is_human(): bool {
		return self::Reviewed === $this || self::Manual === $this;
	}

	/**
	 * Etiqueta traducible para la interfaz.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Pending   => __( 'Pendiente', 'polyglot-ai' ),
			self::Error     => __( 'Error', 'polyglot-ai' ),
			self::Automatic => __( 'Automática', 'polyglot-ai' ),
			self::Reviewed  => __( 'Revisada', 'polyglot-ai' ),
			self::Manual    => __( 'Manual', 'polyglot-ai' ),
		};
	}
}
