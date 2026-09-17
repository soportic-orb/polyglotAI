<?php
/**
 * Arranque de la suite de integración.
 *
 * A diferencia de la unitaria, esta corre DENTRO de WordPress y contra una base
 * de datos MySQL real: es la única forma de comprobar de verdad el esquema, las
 * consultas y los hooks.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

$pgai_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! is_string( $pgai_tests_dir ) || '' === $pgai_tests_dir ) {
	$pgai_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $pgai_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "No se encuentra la biblioteca de tests de WordPress en {$pgai_tests_dir}.\n" );
	fwrite( STDERR, "Ejecuta: composer test:install\n" );
	exit( 1 );
}

require_once $pgai_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/polyglot-ai.php';
	}
);

require $pgai_tests_dir . '/includes/bootstrap.php';
