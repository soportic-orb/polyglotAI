#!/usr/bin/env bash
#
# Deja un WordPress servido con el plugin activo para las pruebas de extremo a
# extremo.
#
# En esta máquina no hay un demonio de Docker, así que `wp-env` no arranca. El
# servidor integrado de PHP sirve igual de bien para lo que estas pruebas
# comprueban —enrutado, hreflang, selector, sitemap y que una traducción
# guardada acabe en la página— y no necesita nada más que PHP y MySQL.
#
# Con wp-env se puede saltar este script: basta con exportar PGAI_E2E_URL.
#
# Uso: bash tests/e2e/install.sh

set -euo pipefail

PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/../.." && pwd )"
WP_DIR="${PGAI_E2E_DIR:-/tmp/wp-e2e}"
WP_PORT="${PGAI_E2E_PORT:-8889}"
WP_URL="http://127.0.0.1:${WP_PORT}"
DB_NAME="${PGAI_E2E_DB:-pgai_e2e}"
DB_USER="${PGAI_E2E_DB_USER:-root}"
DB_PASS="${PGAI_E2E_DB_PASS:-root}"
DB_HOST="${PGAI_E2E_DB_HOST:-127.0.0.1}"
DB_PORT="${PGAI_E2E_DB_PORT:-3306}"
WP_VERSION="6.6.2"
CLI="${PGAI_WP_CLI:-/tmp/wp-cli.phar}"

wp() { php "$CLI" --allow-root --path="$WP_DIR" "$@"; }

if [ ! -f "$CLI" ]; then
	curl -sSL -o "$CLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi

if [ ! -f "$WP_DIR/wp-load.php" ]; then
	echo "Descargando WordPress ${WP_VERSION}..."
	tmp="$( mktemp -d )"
	curl -sSL -o "$tmp/wp.zip" "https://wordpress.org/wordpress-${WP_VERSION}.zip"
	unzip -q "$tmp/wp.zip" -d "$tmp"
	mkdir -p "$( dirname "$WP_DIR" )"
	mv "$tmp/wordpress" "$WP_DIR"
	rm -rf "$tmp"
fi

ln -sfn "$PLUGIN_DIR" "$WP_DIR/wp-content/plugins/polyglot-ai"
cp "$PLUGIN_DIR/tests/e2e/router.php" "$WP_DIR/pgai-router.php"
# El enrutador vive en tests/e2e dentro del repo y en la raíz del WordPress
# servido, así que las rutas relativas cambian.
sed -i "s#__DIR__ . '/../..' . \$pgai_path#__DIR__ . \$pgai_path#; s#__DIR__ . '/../../index.php'#__DIR__ . '/index.php'#" "$WP_DIR/pgai-router.php"

mysql -h "$DB_HOST" -P "$DB_PORT" --protocol=TCP -u"$DB_USER" -p"$DB_PASS" -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4;"

wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="${DB_HOST}:${DB_PORT}" --skip-check --force > /dev/null
wp core install --url="$WP_URL" --title="Polyglot E2E" --admin_user=admin --admin_password=admin --admin_email=e2e@example.test --skip-email
# WordPress adivina la URL a partir de la carpeta cuando se instala por CLI, y
# se queda con el nombre del directorio dentro. Se fija a mano.
wp option update home "$WP_URL" > /dev/null
wp option update siteurl "$WP_URL" > /dev/null

wp rewrite structure '/%postname%/' > /dev/null
wp plugin activate polyglot-ai

wp option set pgai_settings --format=json '{"languages":[{"locale":"en_US","slug":"en","label":"English"},{"locale":"ca","slug":"ca","label":"Catala"}]}' > /dev/null

# Una página con texto reconocible y el selector de idioma dentro.
wp post create --post_type=page --post_status=publish --post_title='Sobre nosotros' \
	--post_name='sobre-nosotros' \
	--post_content='<p>Vendemos cajas de madera.</p>[pgai_language_switcher display="both"]' > /dev/null

wp rewrite flush > /dev/null

# Arrancar el servidor si no está.
if ! curl -sf -o /dev/null "$WP_URL/"; then
	PHP_CLI_SERVER_WORKERS=8 nohup php -S "127.0.0.1:${WP_PORT}" -t "$WP_DIR" "$WP_DIR/pgai-router.php" > /tmp/pgai-e2e-server.log 2>&1 &
	for _ in $(seq 1 30); do
		sleep 1
		curl -sf -o /dev/null "$WP_URL/" && break
	done
fi

# La primera visita en inglés registra las cadenas de la página (ADR-13). Solo
# después existe la fila que se puede traducir. Las cabeceras de navegador son
# necesarias: sin Accept-Language el filtro de robots la toma por un rastreador
# y no encola nada, que es justo lo que tiene que hacer.
curl -sfL -o /dev/null \
	-H 'Accept-Language: en-US,en;q=0.9' \
	-A 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36' \
	"$WP_URL/en/sobre-nosotros/"

# Se guarda una traducción a mano, como la habría guardado el editor visual.
wp eval '
global $wpdb;
$table = PolyglotAI\Database\Schema::table( "sources" );
$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE original = %s LIMIT 1", "Vendemos cajas de madera." ) );
if ( 0 === $id ) { WP_CLI::error( "No se ha registrado la cadena al visitar la página en inglés." ); }
$translations = new PolyglotAI\Database\TranslationRepository( new PolyglotAI\Translation\StatusPrecedence() );
$translations->save( $id, "en_US", "We sell wooden boxes.", PolyglotAI\Translation\Status::Manual );
WP_CLI::success( "Traducción de prueba guardada." );
'

echo
echo "Listo: ${WP_URL}"
echo "Ahora: npm run test:e2e"
