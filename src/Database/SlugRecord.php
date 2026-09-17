<?php
/**
 * Un slug traducido.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Database;

use PolyglotAI\Translation\Status;

/**
 * Slug de un objeto en un idioma.
 *
 * `object_type` dice de qué se habla y `object_subtype` lo concreta:
 *
 * | object_type | object_id | object_subtype                          |
 * |---|---|---|
 * | `post`      | ID        | tipo de contenido (`page`, `product`…)  |
 * | `term`      | term_id   | taxonomía (`category`, `product_cat`…)  |
 * | `base`      | 0         | base reescrita (`category`, `shop`…)    |
 *
 * Las bases comparten `object_id = 0`, así que el subtipo es lo único que las
 * distingue: por eso entra en la clave única de la tabla.
 */
final class SlugRecord {

	/**
	 * @param string $object_type     post, term o base.
	 * @param string $object_subtype  Tipo de contenido, taxonomía o base.
	 * @param int    $object_id       Identificador, 0 en las bases.
	 * @param string $language        Locale.
	 * @param string $original_slug   Slug en el idioma por defecto.
	 * @param string $translated_slug Slug en $language.
	 * @param Status $status          Procedencia de la traducción.
	 */
	public function __construct(
		public readonly string $object_type,
		public readonly string $object_subtype,
		public readonly int $object_id,
		public readonly string $language,
		public readonly string $original_slug,
		public readonly string $translated_slug,
		public readonly Status $status = Status::Automatic
	) {}

	/**
	 * Construye un registro a partir de una fila de la tabla.
	 *
	 * @param array<string, mixed> $row Fila.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(string) ( $row['object_type'] ?? '' ),
			(string) ( $row['object_subtype'] ?? '' ),
			(int) ( $row['object_id'] ?? 0 ),
			(string) ( $row['language'] ?? '' ),
			(string) ( $row['original_slug'] ?? '' ),
			(string) ( $row['translated_slug'] ?? '' ),
			Status::tryFrom( (string) ( $row['status'] ?? '' ) ) ?? Status::Automatic
		);
	}

	/**
	 * Copia del registro con otro slug traducido.
	 *
	 * @param string $slug Slug nuevo.
	 */
	public function with_translated_slug( string $slug ): self {
		return new self(
			$this->object_type,
			$this->object_subtype,
			$this->object_id,
			$this->language,
			$this->original_slug,
			$slug,
			$this->status
		);
	}
}
