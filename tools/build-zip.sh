#!/usr/bin/env bash
#
# Construye el paquete que se instala desde el panel de WordPress.
#
# Lo que sale de aquí es un zip que el administrador sube en Plugins → Añadir
# nuevo → Subir plugin, y que al activarse ya tiene todo: la autocarga, Action
# Scheduler, los recursos compilados del editor y las traducciones de la
# interfaz. **No hace falta tocar el servidor ni ejecutar nada por SSH.**
#
# Uso: bash tools/build-zip.sh [carpeta-de-salida]

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT="${1:-$ROOT/dist}"
SLUG="polyglot-ai"

cd "$ROOT"

VERSION="$( sed -n "s/^ \* Version: *\([0-9a-zA-Z.\-]*\).*/\1/p" "$SLUG.php" | head -1 )"

if [ -z "$VERSION" ]; then
	echo "No se ha podido leer la versión de $SLUG.php" >&2
	exit 1
fi

if [ -n "$( git status --porcelain )" ]; then
	echo "Aviso: hay cambios sin confirmar. El paquete se construye desde HEAD y no los incluirá."
fi

echo "==> Compilando los recursos del editor"
if [ ! -d node_modules ]; then
	npm ci --no-audit --no-fund
fi
npm run build

STAGE="$( mktemp -d )"
trap 'rm -rf "$STAGE"' EXIT
PKG="$STAGE/$SLUG"
mkdir -p "$PKG"

echo "==> Copiando el código confirmado"
git archive HEAD | tar -x -C "$PKG"

# assets/build no se versiona: se acaba de compilar y se copia aparte.
mkdir -p "$PKG/assets"
cp -R assets/build "$PKG/assets/build"

echo "==> Instalando las dependencias de producción"
( cd "$PKG" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet )

echo "==> Quitando lo que no hace falta en producción"
# Todo lo que solo sirve para desarrollar. Lo que quede es lo que se instala.
rm -rf \
	"$PKG/tests" \
	"$PKG/tools" \
	"$PKG/docs" \
	"$PKG/assets/src" \
	"$PKG/.github" \
	"$PKG/CLAUDE.md" \
	"$PKG/README.md" \
	"$PKG/composer.json" \
	"$PKG/composer.lock" \
	"$PKG/package.json" \
	"$PKG/package-lock.json" \
	"$PKG/phpcs.xml.dist" \
	"$PKG/phpstan.neon.dist" \
	"$PKG/phpunit.xml.dist" \
	"$PKG/phpunit-integration.xml.dist" \
	"$PKG/playwright.config.js" \
	"$PKG/.wp-env.json" \
	"$PKG/.gitignore" \
	"$PKG/.gitattributes" \
	"$PKG/.editorconfig"

# De Action Scheduler se queda solo lo que se ejecuta y su licencia, que tiene
# que viajar con él.
AS="$PKG/vendor/woocommerce/action-scheduler"
if [ -d "$AS" ]; then
	rm -rf \
		"$AS/tests" "$AS/docs" "$AS/.git" "$AS/.github" "$AS/node_modules" \
		"$AS/package.json" "$AS/package-lock.json" "$AS/composer.lock" \
		"$AS/phpcs.xml" "$AS/codecov.yml" "$AS/AGENTS.md" "$AS/CLAUDE.md" \
		"$AS/RELEASING.md" "$AS/README.md"
fi

find "$PKG/vendor" -name '.git' -maxdepth 4 -type d -prune -exec rm -rf {} + 2>/dev/null || true

echo "==> Comprobando que está lo imprescindible"
for required in \
	"$SLUG.php" \
	"uninstall.php" \
	"readme.txt" \
	"src/Plugin.php" \
	"vendor/autoload.php" \
	"vendor/woocommerce/action-scheduler/action-scheduler.php" \
	"assets/build/editor.js" \
	"languages/$SLUG.pot"
do
	if [ ! -e "$PKG/$required" ]; then
		echo "Falta $required en el paquete." >&2
		exit 1
	fi
done

mkdir -p "$OUT"
ZIP="$OUT/$SLUG-$VERSION.zip"
rm -f "$ZIP"

( cd "$STAGE" && zip -qr "$ZIP" "$SLUG" -x '*.DS_Store' )

echo
echo "Listo: $ZIP ($( du -h "$ZIP" | cut -f1 ))"
echo "Se instala en Plugins → Añadir nuevo → Subir plugin."
