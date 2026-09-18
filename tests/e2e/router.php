<?php
/**
 * Enrutador para el servidor integrado de PHP.
 *
 * Las pruebas de extremo a extremo necesitan un WordPress servido de verdad, y
 * en este entorno no hay Docker para `wp-env`. El servidor integrado de PHP
 * basta, pero no sabe de enlaces permanentes: sin esto, `/en/una-pagina/`
 * devuelve un 404 del servidor antes de que WordPress llegue a verlo.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

$pgai_path = (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$pgai_file = __DIR__ . '/../..' . $pgai_path;

// Un archivo que existe se sirve tal cual: los estáticos los manda el servidor
// y los PHP (wp-admin, wp-login) se ejecutan en su sitio.
if ( '/' !== $pgai_path && file_exists( $pgai_file ) ) {
	if ( is_dir( $pgai_file ) ) {
		if ( file_exists( $pgai_file . '/index.php' ) ) {
			$_SERVER['SCRIPT_NAME'] = rtrim( $pgai_path, '/' ) . '/index.php';

			require $pgai_file . '/index.php';

			return true;
		}

		return false;
	}

	if ( str_ends_with( $pgai_file, '.php' ) ) {
		$_SERVER['SCRIPT_NAME'] = $pgai_path;

		require $pgai_file;

		return true;
	}

	return false;
}

// Todo lo demás lo resuelve WordPress.
$_SERVER['SCRIPT_NAME'] = '/index.php';

require __DIR__ . '/../../index.php';

return true;
