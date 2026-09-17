<?php
/**
 * Estadísticas de traducción y de consumo.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Admin;

use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Support\Options;
use PolyglotAI\Translation\Status;

/**
 * Cuánto está traducido y cuánto se ha gastado.
 *
 * Son dos preguntas distintas y las dos se contestan aquí porque se miran a la
 * vez: «¿cuánto falta?» y «¿cuánto va a costar?».
 *
 * **La lectura de caché se cuenta aparte** de los tokens de entrada normales, y
 * no por pedantería: cuesta una fracción de su precio, y sumarla sin distinguir
 * daría un consumo que no se parece a la factura. Por la misma razón el tope
 * mensual tampoco la cuenta.
 *
 * Aquí no se estima dinero. El precio por token depende del modelo y de las
 * tarifas vigentes, que cambian sin avisarnos: escribir una cifra en euros
 * significaría mentir en cuanto cambiaran. Se dan los tokens, que es el dato
 * que no caduca, y cada cual lo multiplica por la tarifa de su contrato.
 */
final class StatsPage {

	/** Slug de la pantalla. */
	public const SLUG = 'polyglot-ai-stats';

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry      $languages    Idiomas del sitio.
	 * @param TranslationRepository $translations Repositorio de traducciones.
	 * @param ApiLogRepository      $log          Registro de consumo.
	 * @param Options               $options      Ajustes.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly TranslationRepository $translations,
		private readonly ApiLogRepository $log,
		private readonly Options $options
	) {}

	/**
	 * Engancha la pantalla.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 40 );
	}

	/**
	 * Añade la pantalla al menú del plugin.
	 */
	public function add_page(): void {
		add_submenu_page(
			'polyglot-ai',
			__( 'Estadísticas', 'polyglot-ai' ),
			__( 'Estadísticas', 'polyglot-ai' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Pinta la pantalla.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Estadísticas', 'polyglot-ai' ); ?></h1>

			<h2><?php esc_html_e( 'Progreso por idioma', 'polyglot-ai' ); ?></h2>
			<?php $this->render_progress(); ?>

			<h2><?php esc_html_e( 'Consumo de la API', 'polyglot-ai' ); ?></h2>
			<?php $this->render_usage(); ?>

			<?php $this->render_errors(); ?>
		</div>
		<?php
	}

	/**
	 * Tabla de progreso.
	 */
	private function render_progress(): void {
		if ( array() === $this->languages->translatable() ) {
			echo '<p>' . esc_html__( 'Todavía no hay idiomas a los que traducir.', 'polyglot-ai' ) . '</p>';

			return;
		}

		?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Idioma', 'polyglot-ai' ); ?></th>
					<?php foreach ( Status::cases() as $status ) : ?>
						<th><?php echo esc_html( $status->label() ); ?></th>
					<?php endforeach; ?>
					<th><?php esc_html_e( 'Hecho', 'polyglot-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $this->languages->translatable() as $language ) : ?>
					<?php $counts = $this->translations->counts( $language->locale ); ?>
					<tr>
						<th scope="row"><?php echo esc_html( $language->label ); ?></th>
						<?php foreach ( Status::cases() as $status ) : ?>
							<td><?php echo esc_html( number_format_i18n( (float) ( $counts[ $status->value ] ?? 0 ) ) ); ?></td>
						<?php endforeach; ?>
						<td><?php echo esc_html( $this->progress( $counts ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Tabla de consumo mensual.
	 */
	private function render_usage(): void {
		$months = $this->log->monthly();

		if ( array() === $months ) {
			echo '<p>' . esc_html__( 'Todavía no se ha llamado a la API.', 'polyglot-ai' ) . '</p>';

			return;
		}

		$limit = (int) $this->options->get( 'monthly_token_limit', 0 );

		?>
		<?php if ( $limit > 0 ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: número de tokens. */
					esc_html__( 'Tope mensual: %s tokens. No incluye la lectura de caché, que cuesta una fracción del token de entrada.', 'polyglot-ai' ),
					esc_html( number_format_i18n( $limit ) )
				);
				?>
			</p>
		<?php endif; ?>

		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Mes', 'polyglot-ai' ); ?></th>
					<th><?php esc_html_e( 'Llamadas', 'polyglot-ai' ); ?></th>
					<th><?php esc_html_e( 'Cadenas', 'polyglot-ai' ); ?></th>
					<th><?php esc_html_e( 'Entrada', 'polyglot-ai' ); ?></th>
					<th><?php esc_html_e( 'Salida', 'polyglot-ai' ); ?></th>
					<th><?php esc_html_e( 'Caché escrita', 'polyglot-ai' ); ?></th>
					<th><?php esc_html_e( 'Caché leída', 'polyglot-ai' ); ?></th>
					<th><?php esc_html_e( 'Errores', 'polyglot-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $months as $month ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $month['month'] ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (float) $month['calls'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $month['strings'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $month['input'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $month['output'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $month['cache_creation'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $month['cache_read'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $month['errors'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Últimos errores de la API.
	 */
	private function render_errors(): void {
		$errors = $this->log->recent_errors();

		if ( array() === $errors ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'Últimos errores', 'polyglot-ai' ); ?></h2>

		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Cuándo', 'polyglot-ai' ); ?></th>
					<th><?php esc_html_e( 'Idioma', 'polyglot-ai' ); ?></th>
					<th><?php esc_html_e( 'Modelo', 'polyglot-ai' ); ?></th>
					<th><?php esc_html_e( 'Error', 'polyglot-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $errors as $error ) : ?>
					<tr>
						<td><?php echo esc_html( $error['created_at'] ); ?></td>
						<td><?php echo esc_html( $this->language_label( $error['language'] ) ); ?></td>
						<td><?php echo esc_html( $error['model'] ); ?></td>
						<td><?php echo esc_html( $error['error'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Porcentaje traducido de un idioma.
	 *
	 * Cuenta como hecho lo automático, lo revisado y lo manual. Lo pendiente y
	 * lo que quedó en error son justamente lo que falta.
	 *
	 * @param array<string, int> $counts Recuento por estado.
	 */
	private function progress( array $counts ): string {
		$total = array_sum( $counts );

		if ( 0 === $total ) {
			return '—';
		}

		$done = ( $counts[ Status::Automatic->value ] ?? 0 )
			+ ( $counts[ Status::Reviewed->value ] ?? 0 )
			+ ( $counts[ Status::Manual->value ] ?? 0 );

		return number_format_i18n( round( $done / $total * 100 ) ) . ' %';
	}

	/**
	 * Nombre de un idioma a partir de su locale.
	 *
	 * @param string $locale Locale.
	 */
	private function language_label( string $locale ): string {
		$language = $this->languages->by_locale( $locale );

		return $language instanceof Language ? $language->label : $locale;
	}
}
