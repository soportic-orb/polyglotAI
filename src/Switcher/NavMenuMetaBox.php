<?php
/**
 * Caja de «Idiomas» en Apariencia → Menús.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Switcher;

use PolyglotAI\Languages\LanguageRegistry;

/**
 * Deja añadir el selector de idioma a un menú desde el editor de menús.
 *
 * Los elementos se guardan como enlaces personalizados con una URL centinela,
 * que NavMenu resuelve al pintar. Ver allí por qué no se guarda una URL real.
 */
final class NavMenuMetaBox {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 */
	public function __construct( private readonly LanguageRegistry $languages ) {}

	/**
	 * Engancha la caja.
	 */
	public function register(): void {
		add_action( 'admin_head-nav-menus.php', array( $this, 'add_meta_box' ) );
	}

	/**
	 * Registra la caja en el editor de menús.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'pgai-languages',
			__( 'Idiomas', 'polyglot-ai' ),
			array( $this, 'render' ),
			'nav-menus',
			'side',
			'default'
		);
	}

	/**
	 * Pinta la caja.
	 */
	public function render(): void {
		$entries = array(
			array(
				'key'   => -1,
				'title' => __( 'Selector de idioma', 'polyglot-ai' ),
				'url'   => NavMenu::SWITCHER_URL,
			),
		);

		$key = -2;

		foreach ( $this->languages->all() as $language ) {
			$entries[] = array(
				'key'   => $key,
				'title' => $language->label,
				'url'   => NavMenu::LANGUAGE_URL_PREFIX . $language->slug,
			);

			--$key;
		}

		?>
		<div id="pgai-languages" class="posttypediv">
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<?php foreach ( $entries as $entry ) : ?>
						<?php $field = 'menu-item[' . (int) $entry['key'] . ']'; ?>
						<li>
							<label class="menu-item-title">
								<input
									type="checkbox"
									class="menu-item-checkbox"
									name="<?php echo esc_attr( $field . '[menu-item-object-id]' ); ?>"
									value="<?php echo esc_attr( (string) $entry['key'] ); ?>"
								>
								<?php echo esc_html( $entry['title'] ); ?>
							</label>
							<input type="hidden" class="menu-item-type" name="<?php echo esc_attr( $field . '[menu-item-type]' ); ?>" value="custom">
							<input type="hidden" class="menu-item-title" name="<?php echo esc_attr( $field . '[menu-item-title]' ); ?>" value="<?php echo esc_attr( $entry['title'] ); ?>">
							<input type="hidden" class="menu-item-url" name="<?php echo esc_attr( $field . '[menu-item-url]' ); ?>" value="<?php echo esc_attr( $entry['url'] ); ?>">
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<p class="button-controls">
				<span class="add-to-menu">
					<input
						type="submit"
						class="button submit-add-to-menu right"
						value="<?php esc_attr_e( 'Añadir al menú', 'polyglot-ai' ); ?>"
						name="add-pgai-menu-item"
						id="submit-pgai-languages"
					>
					<span class="spinner"></span>
				</span>
			</p>

			<p class="description">
				<?php
				esc_html_e(
					'El selector se convierte en un desplegable con el idioma en curso y los demás debajo. Cada idioma suelto lleva a esta misma página en ese idioma.',
					'polyglot-ai'
				);
				?>
			</p>
		</div>
		<?php
	}
}
