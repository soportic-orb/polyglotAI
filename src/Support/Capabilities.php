<?php
/**
 * Capacidades y roles del plugin.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Support;

/**
 * Capacidades propias y el rol de traductor.
 *
 * Son granulares a propósito: lanzar una traducción automática gasta dinero y no
 * tiene por qué poder hacerlo cualquiera que pueda corregir un texto.
 */
final class Capabilities {

	/** Editar traducciones en el editor visual. */
	public const TRANSLATE = 'pgai_translate';

	/** Marcar cadenas como revisadas. */
	public const REVIEW = 'pgai_review';

	/** Lanzar traducción automática. Gasta presupuesto de API. */
	public const RUN_AUTO = 'pgai_run_auto_translate';

	/** Añadir, quitar y activar idiomas. */
	public const MANAGE_LANGUAGES = 'pgai_manage_languages';

	/** Ajustes generales, clave de API y límites. */
	public const MANAGE_SETTINGS = 'pgai_manage_settings';

	/** Identificador del rol de traductor. */
	public const TRANSLATOR_ROLE = 'pgai_translator';

	/**
	 * Todas las capacidades.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::TRANSLATE, self::REVIEW, self::RUN_AUTO, self::MANAGE_LANGUAGES, self::MANAGE_SETTINGS );
	}

	/**
	 * Crea el rol de traductor y asigna las capacidades a los administradores.
	 */
	public function install(): void {
		add_role(
			self::TRANSLATOR_ROLE,
			__( 'Traductor', 'polyglot-ai' ),
			array(
				'read'          => true,
				self::TRANSLATE => true,
				self::REVIEW    => true,
			)
		);

		$administrator = get_role( 'administrator' );

		if ( null === $administrator ) {
			return;
		}

		foreach ( self::all() as $capability ) {
			$administrator->add_cap( $capability );
		}
	}

	/**
	 * Quita el rol y las capacidades. Solo en la desinstalación.
	 */
	public function remove(): void {
		remove_role( self::TRANSLATOR_ROLE );

		$administrator = get_role( 'administrator' );

		if ( null === $administrator ) {
			return;
		}

		foreach ( self::all() as $capability ) {
			$administrator->remove_cap( $capability );
		}
	}
}
