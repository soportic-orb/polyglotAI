/**
 * Punto de entrada del editor visual.
 */

import { createRoot } from '@wordpress/element';

import App from './App';
import './style.scss';

const container = document.getElementById( 'pgai-editor-root' );

if ( container ) {
	createRoot( container ).render( <App /> );
}
