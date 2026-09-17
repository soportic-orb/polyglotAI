#!/usr/bin/env bash
# Descarga la HTML API del core de WordPress para que la suite unitaria pueda
# ejecutarse sin una instalación completa de WordPress ni Docker.
#
# Se fija deliberadamente en la versión MÍNIMA soportada (ver CLAUDE.md, ADR-01):
# así los tests verifican el contrato contra el WordPress más antiguo admitido,
# que es donde la API es más limitada.
set -euo pipefail

WP_VERSION="${PGAI_WP_VERSION:-6.6.2}"
DEST="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/tests/vendor-wp"
STAMP="${DEST}/.version"

if [[ -f "${STAMP}" && "$(cat "${STAMP}")" == "${WP_VERSION}" ]]; then
    exit 0
fi

echo "Descargando la HTML API de WordPress ${WP_VERSION}..."
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o "${TMP}/wp.tar.gz"

tar xzf "${TMP}/wp.tar.gz" -C "${TMP}" \
    wordpress/wp-includes/class-wp-token-map.php \
    wordpress/wp-includes/html-api

rm -rf "${DEST}"
mkdir -p "${DEST}"
cp "${TMP}/wordpress/wp-includes/class-wp-token-map.php" "${DEST}/"
cp -r "${TMP}/wordpress/wp-includes/html-api" "${DEST}/"
echo "${WP_VERSION}" > "${STAMP}"
echo "HTML API de WordPress ${WP_VERSION} instalada en tests/vendor-wp/"
