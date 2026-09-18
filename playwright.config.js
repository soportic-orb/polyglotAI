/**
 * Configuración de las pruebas de extremo a extremo.
 *
 * El sitio tiene que estar servido y con el plugin activo antes de arrancar:
 * `bash tests/e2e/install.sh` lo deja listo con el servidor integrado de PHP,
 * que es lo que hay cuando no se puede levantar Docker para `wp-env`. Con
 * `wp-env` basta con apuntar PGAI_E2E_URL a su puerto.
 */

const { defineConfig, devices } = require( '@playwright/test' );

const baseURL = process.env.PGAI_E2E_URL || 'http://127.0.0.1:8889';

// Cuando el navegador ya está instalado fuera de node_modules y su versión no
// es exactamente la que espera esta versión de Playwright, se le dice dónde
// está en vez de descargar otro. Sin PGAI_E2E_CHROMIUM se usa el que Playwright
// gestione por su cuenta, que es lo normal en CI.
const executablePath = process.env.PGAI_E2E_CHROMIUM || undefined;

module.exports = defineConfig( {
	testDir: './tests/e2e',
	testMatch: '**/*.spec.js',
	// Un sitio de pruebas compartido no admite que dos pruebas lo cambien a la
	// vez: se ejecutan en serie a propósito.
	workers: 1,
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? 'list' : [ [ 'list' ] ],
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				launchOptions: executablePath ? { executablePath } : {},
			},
		},
	],
} );
