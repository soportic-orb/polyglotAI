/**
 * Guion de la vista previa del editor visual.
 *
 * Corre dentro del iframe, sobre el sitio real. Su trabajo es señalar qué
 * cadena hay bajo el ratón, avisar al panel cuando se pulsa una y aplicar en el
 * acto las traducciones que el panel devuelve.
 *
 * No usa React a propósito: se carga sobre el sitio del cliente, junto a su
 * tema y sus plugins, así que cuanto menos pese y menos toque, mejor.
 */

import './style.scss';
import { keepEditMode, shouldNavigate } from './url';

const SELECTOR = '.pgai-string';
const ACTIVE_CLASS = 'pgai-string--active';

/**
 * Envía un mensaje al panel del editor.
 *
 * @param {string} type    Tipo de mensaje.
 * @param {Object} payload Datos.
 */
function send( type, payload = {} ) {
	if ( window.parent === window ) {
		return;
	}

	window.parent.postMessage(
		{ source: 'pgai-preview', type, ...payload },
		window.location.origin
	);
}

/**
 * Elemento marcado más cercano a un nodo.
 *
 * @param {EventTarget|null} target Nodo de partida.
 * @return {HTMLElement|null} Elemento marcado, si lo hay.
 */
function closestString( target ) {
	if ( ! ( target instanceof Element ) ) {
		return null;
	}

	return target.closest( SELECTOR );
}

/**
 * Marca visualmente la cadena seleccionada.
 *
 * @param {string|null} hash Hash de la cadena, o null para no marcar ninguna.
 */
function highlight( hash ) {
	document
		.querySelectorAll( `${ SELECTOR }.${ ACTIVE_CLASS }` )
		.forEach( ( element ) => {
			element.classList.remove( ACTIVE_CLASS );
		} );

	if ( ! hash ) {
		return;
	}

	const element = document.querySelector(
		`${ SELECTOR }[data-pgai-hash="${ hash }"]`
	);

	if ( ! element ) {
		return;
	}

	element.classList.add( ACTIVE_CLASS );
	element.scrollIntoView( { behavior: 'smooth', block: 'center' } );
}

/**
 * Aplica una traducción sobre la página sin recargarla.
 *
 * @param {string} hash        Hash de la cadena.
 * @param {string} translation Traducción.
 * @param {string} status      Estado resultante.
 */
function applyTranslation( hash, translation, status ) {
	document
		.querySelectorAll( `${ SELECTOR }[data-pgai-hash="${ hash }"]` )
		.forEach( ( element ) => {
			if ( element.dataset.pgaiType === 'block' ) {
				// El bloque ya viene saneado por el servidor con la lista blanca.
				element.innerHTML = translation;
			} else {
				element.textContent = translation;
			}

			if ( status ) {
				element.dataset.pgaiStatus = status;
			}
		} );
}

/**
 * Arranca la vista previa.
 */
function start() {
	const data = window.pgaiPreview;

	if ( ! data ) {
		return;
	}

	document.body.classList.add( 'pgai-preview' );

	send( 'ready', {
		strings: data.strings,
		language: data.language,
		url: window.location.href,
		path: window.location.pathname,
	} );

	// Seleccionar una cadena. Se usa la fase de captura y se detiene el evento
	// para que el clic no active lo que haya debajo: dentro del editor, pulsar
	// un enlace debe seleccionar su texto, no seguirlo.
	document.addEventListener(
		'click',
		( event ) => {
			const link =
				event.target instanceof Element
					? event.target.closest( 'a[href]' )
					: null;
			const string = closestString( event.target );

			if ( string ) {
				event.preventDefault();
				event.stopPropagation();

				highlight( string.dataset.pgaiHash );
				send( 'select', { hash: string.dataset.pgaiHash } );

				return;
			}

			// Un enlace fuera de una cadena sí navega, pero conservando el modo
			// de edición. Los anclas, los enlaces externos y los mailto no
			// navegan: sacarían al traductor del sitio que está traduciendo.
			if (
				link &&
				shouldNavigate(
					link.getAttribute( 'href' ),
					window.location.href
				)
			) {
				event.preventDefault();
				send( 'navigate', {
					url: keepEditMode( link.href, window.location.href ),
				} );
			}
		},
		true
	);

	// Los formularios no se envían desde la vista previa: enviarían el sitio a
	// otra página y sacarían al traductor del editor.
	document.addEventListener(
		'submit',
		( event ) => {
			event.preventDefault();
		},
		true
	);

	window.addEventListener( 'message', ( event ) => {
		if (
			event.origin !== window.location.origin ||
			! event.data ||
			event.data.source !== 'pgai-editor'
		) {
			return;
		}

		if ( event.data.type === 'update' ) {
			applyTranslation(
				event.data.hash,
				event.data.translation,
				event.data.status
			);
		}

		if ( event.data.type === 'highlight' ) {
			highlight( event.data.hash );
		}
	} );
}

if ( document.readyState === 'loading' ) {
	// Los datos se inyectan justo antes de </body>, después de wp_footer, así
	// que hay que esperar a que el documento esté completo para leerlos.
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
