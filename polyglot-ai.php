<?php
/**
 * Plugin Name:       Polyglot AI
 * Plugin URI:        https://github.com/soportic-orb/polyglotAI
 * Description:       Traducción multilingüe del sitio con motor de inteligencia artificial, editor visual y URLs por idioma.
 * Version:           0.1.0
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

const VERSION = '0.1.0';

define( 'PGAI_VERSION', VERSION );
define( 'PGAI_FILE', __FILE__ );
define( 'PGAI_DIR', plugin_dir_path( __FILE__ ) );
define( 'PGAI_URL', plugin_dir_url( __FILE__ ) );
define( 'PGAI_MIN_WP', '6.6' );
define( 'PGAI_MIN_PHP', '8.1' );

$pgai_autoload = PGAI_DIR . 'vendor/autoload.php';

if ( ! is_readable( $pgai_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Polyglot AI no encuentra sus dependencias. Ejecuta «composer install» en la carpeta del plugin.', 'polyglot-ai' )
			);
		}
	);

	return;
}

require_once $pgai_autoload;
require_once PGAI_DIR . 'src/functions.php';

register_activation_hook( __FILE__, array( Bootstrap\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Bootstrap\Deactivator::class, 'deactivate' ) );

Plugin::instance()->boot();
