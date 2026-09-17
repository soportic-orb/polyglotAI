#!/usr/bin/env bash
# Instala PHPStan como phar.
#
# No se instala por Composer a propósito: su distribución es un phar publicado
# como release de GitHub, y en entornos cuyo acceso a la API de GitHub está
# restringido eso bloquea la instalación entera del proyecto. El phar oficial se
# descarga por HTTPS normal y es además la vía que recomienda el propio PHPStan.
set -euo pipefail

VERSION="${PHPSTAN_VERSION:-1.12.34}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${ROOT}/tools/phpstan.phar"
STAMP="${ROOT}/tools/.phpstan-version"

if [[ -f "${TARGET}" && -f "${STAMP}" && "$(cat "${STAMP}")" == "${VERSION}" ]]; then
    exit 0
fi

echo "Descargando PHPStan ${VERSION}..."
curl -fsSL "https://github.com/phpstan/phpstan/releases/download/${VERSION}/phpstan.phar" -o "${TARGET}.tmp"
mv "${TARGET}.tmp" "${TARGET}"
echo "${VERSION}" > "${STAMP}"
