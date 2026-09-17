<?php
/**
 * Previsualización de la página como otro rol o como visitante.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Editor;

/**
 * Renderiza la vista previa con los permisos de otro rol, o sin ninguno.
 *
 * Sirve para comprobar qué ve de verdad un visitante: un tema puede mostrar
 * cosas distintas a quien ha iniciado sesión, y traducir lo que ve un
 * administrador no garantiza haber traducido lo que ve el público.
 *
 * Solo puede QUITAR permisos, nunca darlos, y únicamente dentro de una vista
 * previa que ya ha superado la comprobación de capacidad y de nonce.
 */
final class PreviewAs {

	/** Parámetro que elige el punto de vista. */
	public const PARAM = 'pgai-as';

	/** Valor que representa a un visitante no conectado. */
	public const VISITOR = 'visitor';

	/**
	 * Constructor.
	 *
	 * @param EditMode $mode Detector del modo de edición.
	 */
	public function __construct( private readonly EditMode $mode ) {}

	/**
	 * Aplica el punto de vista pedido.
	 *
	 * Se engancha DESPUÉS de que el modo de edición se haya comprobado y haya
	 * quedado memorizado: si se hiciera antes, quitarle los permisos al usuario
	 * cerraría la propia vista previa.
	 */
	public function apply(): void {
		if ( ! $this->mode->is_active() ) {
			return;
		}

		$as = $this->requested();

		if ( null === $as ) {
			return;
		}

		if ( self::VISITOR === $as ) {
			// Sin usuario: es lo que ve alguien que llega desde un buscador.
			wp_set_current_user( 0 );

			return;
		}

		$role = get_role( $as );

		if ( null === $role ) {
			return;
		}

		add_filter(
			'user_has_cap',
			static function () use ( $role ): array {
				// Se sustituyen las capacidades, no se añaden: el resultado
				// nunca puede ser más permisivo que el rol elegido.
				return array_map( static fn(): bool => true, $role->capabilities );
			},
			PHP_INT_MAX
		);
	}

	/**
	 * Punto de vista pedido, si es válido.
	 */
	private function requested(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = sanitize_key( wp_unslash( (string) $_GET[ self::PARAM ] ) );

		if ( self::VISITOR === $value ) {
			return $value;
		}

		return array_key_exists( $value, wp_roles()->roles ) ? $value : null;
	}

	/**
	 * Puntos de vista disponibles, para el desplegable del editor.
	 *
	 * @return array<int, array{value:string, label:string}>
	 */
	public static function choices(): array {
		$choices = array(
			array(
				'value' => '',
				'label' => __( 'Como yo', 'polyglot-ai' ),
			),
			array(
				'value' => self::VISITOR,
				'label' => __( 'Como visitante no conectado', 'polyglot-ai' ),
			),
		);

		foreach ( wp_roles()->role_names as $slug => $name ) {
			$choices[] = array(
				'value' => (string) $slug,
				/* translators: %s: nombre del rol. */
				'label' => sprintf( __( 'Como %s', 'polyglot-ai' ), translate_user_role( (string) $name ) ),
			);
		}

		return $choices;
	}
}
