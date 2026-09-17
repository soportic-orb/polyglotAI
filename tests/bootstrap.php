<?php
/**
 * Arranque de la suite unitaria.
 *
 * Los tests unitarios corren SIN WordPress. Cargan la HTML API real del core
 * (descargada por tests/bin/install-html-api.sh) sobre un puñado de stubs, de
 * modo que el driver primario se prueba contra el parser de verdad y no contra
 * una imitación.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

define( 'PGAI_TESTS_DIR', __DIR__ );
define( 'PGAI_VENDOR_WP', __DIR__ . '/vendor-wp' );

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_dir( PGAI_VENDOR_WP ) ) {
	fwrite( STDERR, "Falta tests/vendor-wp. Ejecuta: composer html-api\n" );
	exit( 1 );
}

require_once __DIR__ . '/stubs/wp-functions.php';
require_once PGAI_VENDOR_WP . '/class-wp-token-map.php';
require_once PGAI_VENDOR_WP . '/html-api/class-wp-html-span.php';
require_once PGAI_VENDOR_WP . '/html-api/class-wp-html-text-replacement.php';
require_once PGAI_VENDOR_WP . '/html-api/class-wp-html-attribute-token.php';
require_once PGAI_VENDOR_WP . '/html-api/html5-named-character-references.php';
require_once PGAI_VENDOR_WP . '/html-api/class-wp-html-decoder.php';
require_once PGAI_VENDOR_WP . '/html-api/class-wp-html-tag-processor.php';
