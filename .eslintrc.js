/**
 * Configuración de ESLint.
 *
 * Parte de la recomendada de WordPress y solo añade lo imprescindible: el
 * entorno de navegador, porque el guion de la vista previa manipula el DOM
 * directamente y usa tipos globales como Element.
 */
module.exports = {
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	env: {
		browser: true,
	},
	globals: {
		wp: 'readonly',
	},
	overrides: [
		{
			files: [ '**/test/**/*.js' ],
			env: {
				jest: true,
			},
		},
	],
};
