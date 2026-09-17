#!/usr/bin/env bash
# Instala WordPress y su biblioteca de tests para la suite de integración.
#
# No usa svn, a diferencia del script clásico de WordPress: descarga el paquete
# de wordpress.org y la biblioteca de tests desde el tarball de
# WordPress/wordpress-develop, que es una descarga HTTPS normal y funciona en
# entornos donde svn no está o la red está restringida.
#
# Uso: install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-root}"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-6.6.2}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

download() {
    curl -fsSL "$1" -o "$2"
}

install_wp() {
    if [[ -d "${WP_CORE_DIR}/wp-includes" ]]; then
        echo "WordPress ya está en ${WP_CORE_DIR}"
        return
    fi

    mkdir -p "${WP_CORE_DIR}"
    local tmp
    tmp="$(mktemp -d)"

    echo "Descargando WordPress ${WP_VERSION}..."
    download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "${tmp}/wp.tar.gz"
    tar --strip-components=1 -zxf "${tmp}/wp.tar.gz" -C "${WP_CORE_DIR}"
    rm -rf "${tmp}"
}

install_test_suite() {
    if [[ -f "${WP_TESTS_DIR}/includes/bootstrap.php" ]]; then
        echo "La biblioteca de tests ya está en ${WP_TESTS_DIR}"
    else
        mkdir -p "${WP_TESTS_DIR}"
        local tmp
        tmp="$(mktemp -d)"

        echo "Descargando la biblioteca de tests de WordPress ${WP_VERSION}..."
        download "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" "${tmp}/develop.tar.gz"
        tar -zxf "${tmp}/develop.tar.gz" -C "${tmp}"

        local src="${tmp}/wordpress-develop-${WP_VERSION}/tests/phpunit"
        cp -r "${src}/includes" "${WP_TESTS_DIR}/"
        cp -r "${src}/data" "${WP_TESTS_DIR}/"
        cp "${tmp}/wordpress-develop-${WP_VERSION}/wp-tests-config-sample.php" "${WP_TESTS_DIR}/wp-tests-config-sample.php"
        rm -rf "${tmp}"
    fi

    local config="${WP_TESTS_DIR}/wp-tests-config.php"

    if [[ ! -f "${config}" ]]; then
        sed \
            -e "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" \
            -e "s/youremptytestdbnamehere/${DB_NAME}/" \
            -e "s/yourusernamehere/${DB_USER}/" \
            -e "s/yourpasswordhere/${DB_PASS}/" \
            -e "s|localhost|${DB_HOST}|" \
            "${WP_TESTS_DIR}/wp-tests-config-sample.php" > "${config}"
    fi
}

install_wp
install_test_suite

echo "Listo. WP_TESTS_DIR=${WP_TESTS_DIR} WP_CORE_DIR=${WP_CORE_DIR}"
