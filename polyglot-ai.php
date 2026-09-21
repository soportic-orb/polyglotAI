<?php
/**
 * Plugin Name:       Polyglot AI
 * Plugin URI:        https://github.com/soportic-orb/polyglotAI
 * Description:       Traducción multilingüe del sitio con motor de inteligencia artificial, editor visual y URLs por idioma.
 * Version:           2.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Soportic
 * Text Domain:       polyglot-ai
 * Domain Path:       /languages
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '2.0';

define( 'PGAI_VERSION', VERSION );
define( 'PGAI_FILE', __FILE__ );
define( 'PGAI_DIR', plugin_dir_path( __FILE__ ) );
define( 'PGAI_URL', plugin_dir_url( __FILE__ ) );
define( 'PGAI_MIN_WP', '6.6' );
define( 'PGAI_MIN_PHP', '8.1' );

/*
 * Autocarga.
 *
 * Se prefiere la de Composer cuando está, que es lo que hay en el paquete que
 * se distribuye y en una copia de trabajo con `composer install`. Si no está
 * —alguien se ha bajado el repositorio y no ha ejecutado nada—, se registra una
 * PSR-4 propia: el plugin no tiene ninguna dependencia de producción aparte de
 * Action Scheduler, así que no hay motivo para que no arranque.
 */
$pgai_autoload = PGAI_DIR . 'vendor/autoload.php';

if ( is_readable( $pgai_autoload ) ) {
	require_once $pgai_autoload;
} else {
	spl_autoload_register(
		static function ( $class_name ): void {
			if ( ! is_string( $class_name ) || ! str_starts_with( $class_name, 'PolyglotAI\\' ) ) {
				return;
			}

			$relative = str_replace( '\\', '/', substr( $class_name, strlen( 'PolyglotAI\\' ) ) );
			$file     = PGAI_DIR . 'src/' . $relative . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

/*
 * Action Scheduler, que es lo que mueve la traducción en segundo plano
 * (ADR-13) y la de sitio completo (ADR-19). Va dentro del paquete: el
 * administrador instala un zip y no tiene que buscar nada más.
 *
 * Se carga aquí arriba a propósito. Action Scheduler negocia su propia versión
 * entre todas las copias que haya en el sitio —WooCommerce trae la suya— y para
 * entrar en esa negociación tiene que haberse cargado antes de `plugins_loaded`.
 */
$pgai_scheduler = PGAI_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

if ( is_readable( $pgai_scheduler ) ) {
	require_once $pgai_scheduler;
}

require_once PGAI_DIR . 'src/functions.php';

register_activation_hook( __FILE__, array( Bootstrap\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Bootstrap\Deactivator::class, 'deactivate' ) );

Plugin::instance()->boot();
