<?php
/**
 * Marcado de las cadenas en la vista previa del editor.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Editor;

use PolyglotAI\Html\ExtractedString;
use PolyglotAI\Html\RawHtml;
use PolyglotAI\Translation\Hasher;
use PolyglotAI\Translation\StringType;

/**
 * Envuelve cada cadena de la vista previa en un elemento con su identificador.
 *
 * Es lo que permite al editor saber qué cadena hay bajo el ratón. Solo las
 * cadenas de texto y de bloque pueden envolverse; un atributo o una meta no
 * tienen un lugar en la página donde colgar la marca, así que esas se editan
 * desde la lista del panel lateral. Todas, envueltas o no, se anotan para
 * enviarlas al editor.
 *
 * El marcado altera el HTML, y por eso existe únicamente en la vista previa:
 * una visita normal nunca lo ve (ver EditMode).
 */
final class MarkerDecorator {

	/**
	 * Cadenas encontradas en la página, por hash.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $collected = array();

	/**
	 * Constructor.
	 *
	 * @param Hasher $hasher Calculador de hashes.
	 */
	public function __construct( private readonly Hasher $hasher ) {}

	/**
	 * Devuelve la sustitución marcada de una unidad.
	 *
	 * @param ExtractedString $unit        Unidad extraída.
	 * @param string|null     $translation Traducción disponible, si la hay.
	 * @param string          $status      Estado de la traducción.
	 * @return RawHtml|string|null
	 */
	public function decorate( ExtractedString $unit, ?string $translation, string $status = 'pending' ): RawHtml|string|null {
		$hash = $this->hasher->hash( $unit->value, $unit->type, $unit->context );

		$this->collected[ $hash ] = array(
			'hash'        => $hash,
			'type'        => $unit->type->value,
			'context'     => $unit->context,
			'attribute'   => $unit->attribute,
			'original'    => $unit->value,
			'translation' => $translation ?? '',
			'status'      => $status,
		);

		$shown = $translation ?? $unit->value;

		if ( ! $this->can_wrap( $unit->type ) ) {
			// Atributos, metas y RCDATA se editan desde el panel: aquí solo se
			// aplica la traducción, sin marca.
			return $translation;
		}

		$inner = StringType::Block === $unit->type
			? $shown
			: str_replace( array( '&', '<' ), array( '&amp;', '&lt;' ), $shown );

		return new RawHtml(
			sprintf(
				'<span class="pgai-string" data-pgai-hash="%s" data-pgai-type="%s" data-pgai-status="%s">%s</span>',
				esc_attr( $hash ),
				esc_attr( $unit->type->value ),
				esc_attr( $status ),
				$inner
			)
		);
	}

	/**
	 * Cadenas encontradas, listas para enviarlas al editor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function collected(): array {
		return array_values( $this->collected );
	}

	/**
	 * Si una cadena de este tipo puede envolverse en un elemento.
	 *
	 * @param StringType $type Tipo de cadena.
	 */
	private function can_wrap( StringType $type ): bool {
		return StringType::Text === $type || StringType::Block === $type;
	}
}
