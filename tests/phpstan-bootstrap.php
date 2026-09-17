<?php
/**
 * Constantes que PHPStan necesita conocer para analizar el plugin fuera de
 * una instalación de WordPress.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

define( 'PGAI_VERSION', '0.1.0' );
define( 'PGAI_FILE', '' );
define( 'PGAI_DIR', '' );
define( 'PGAI_URL', '' );
define( 'PGAI_MIN_WP', '6.6' );
define( 'PGAI_MIN_PHP', '8.1' );

// Constantes del núcleo de WordPress que el análisis necesita conocer.
define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'YEAR_IN_SECONDS', 31536000 );
