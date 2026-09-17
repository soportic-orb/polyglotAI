<?php
/**
 * Desinstalación del plugin.
 *
 * Solo borra datos si el administrador lo ha marcado explícitamente en los
 * ajustes. Por defecto no se borra nada: quien desinstala para probar otra cosa
 * no debería perder meses de traducciones revisadas a mano.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$pgai_autoload = __DIR__ . '/vendor/autoload.php';

if ( ! is_readable( $pgai_autoload ) ) {
	return;
}

require_once $pgai_autoload;

$pgai_settings = get_option( \PolyglotAI\Support\Options::MAIN, array() );

if ( ! is_array( $pgai_settings ) || true !== ( $pgai_settings['uninstall_removes_data'] ?? false ) ) {
	return;
}

( new \PolyglotAI\Database\Schema() )->drop();
( new \PolyglotAI\Support\Capabilities() )->remove();

delete_option( \PolyglotAI\Support\Options::MAIN );
delete_option( \PolyglotAI\Support\Options::GLOSSARY );
delete_option( \PolyglotAI\Support\Options::DO_NOT_TRANSLATE );
delete_option( 'pgai_api_key' );
delete_option( 'pgai_dict_version' );
delete_transient( 'pgai_driver_probe' );
