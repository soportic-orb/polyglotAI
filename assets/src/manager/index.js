/**
 * Punto de entrada del gestor de cadenas.
 */

import { createRoot } from '@wordpress/element';

import App from './App';
import './style.scss';

const root = document.getElementById( 'pgai-manager-root' );

if ( root ) {
	createRoot( root ).render( <App /> );
}
