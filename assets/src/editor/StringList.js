/**
 * Lista buscable de las cadenas de la página.
 */

import { __, _x, sprintf } from '@wordpress/i18n';
import { SearchControl } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';

/**
 * Nombre legible de un tipo de cadena.
 *
 * @param {string} type Tipo.
 * @return {string} Etiqueta.
 */
function typeLabel( type ) {
	const labels = {
		text: __( 'Contenido', 'polyglot-ai' ),
		block: __( 'Contenido', 'polyglot-ai' ),
		attribute: __( 'Imágenes y atributos', 'polyglot-ai' ),
		meta: __( 'SEO y metadatos', 'polyglot-ai' ),
		rcdata: __( 'Título de la página', 'polyglot-ai' ),
		gettext: __( 'Cadenas del tema y plugins', 'polyglot-ai' ),
		slug: __( 'Slugs', 'polyglot-ai' ),
	};

	return labels[ type ] || __( 'Otras', 'polyglot-ai' );
}

/**
 * Resumen corto de una cadena para la lista.
 *
 * @param {Object} string Cadena.
 * @return {string} Resumen.
 */
function summarize( string ) {
	const plain = ( string.translation || string.original || '' )
		.replace( /<[^>]*>/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim();

	return plain.length > 70 ? `${ plain.slice( 0, 70 ) }…` : plain;
}

/**
 * Lista lateral de cadenas, agrupada por tipo y filtrable.
 *
 * @param {Object}   props          Propiedades.
 * @param {Array}    props.strings  Cadenas de la página.
 * @param {string}   props.selected Hash seleccionado.
 * @param {Function} props.onSelect Devolución al seleccionar.
 * @return {JSX.Element} Lista.
 */
export default function StringList( { strings, selected, onSelect } ) {
	const [ search, setSearch ] = useState( '' );

	const groups = useMemo( () => {
		const term = search.trim().toLowerCase();

		const filtered = strings.filter( ( string ) => {
			if ( ! term ) {
				return true;
			}

			return (
				( string.original || '' ).toLowerCase().includes( term ) ||
				( string.translation || '' ).toLowerCase().includes( term )
			);
		} );

		return filtered.reduce( ( accumulator, string ) => {
			const label = typeLabel( string.type );

			accumulator[ label ] = accumulator[ label ] || [];
			accumulator[ label ].push( string );

			return accumulator;
		}, {} );
	}, [ strings, search ] );

	const pending = strings.filter(
		( string ) => string.status === 'pending'
	).length;

	return (
		<div className="pgai-list">
			<SearchControl
				__nextHasNoMarginBottom
				value={ search }
				onChange={ setSearch }
				label={ __( 'Buscar cadenas', 'polyglot-ai' ) }
				placeholder={ __( 'Buscar en esta página…', 'polyglot-ai' ) }
			/>

			<p className="pgai-list__count">
				{ sprintf(
					/* translators: 1: cadenas totales, 2: cadenas sin traducir. */
					_x(
						'%1$d cadenas, %2$d sin traducir',
						'resumen de la página',
						'polyglot-ai'
					),
					strings.length,
					pending
				) }
			</p>

			{ Object.keys( groups ).length === 0 && (
				<p className="pgai-list__empty">
					{ __( 'Ninguna cadena coincide.', 'polyglot-ai' ) }
				</p>
			) }

			{ Object.entries( groups ).map( ( [ label, items ] ) => (
				<div className="pgai-list__group" key={ label }>
					<h3 className="pgai-list__title">{ label }</h3>
					<ul className="pgai-list__items">
						{ items.map( ( string ) => (
							<li key={ string.hash }>
								<button
									type="button"
									className={ `pgai-list__item pgai-list__item--${
										string.status
									}${
										string.hash === selected
											? ' is-selected'
											: ''
									}` }
									onClick={ () => onSelect( string.hash ) }
									aria-current={ string.hash === selected }
								>
									<span className="pgai-list__text">
										{ summarize( string ) }
									</span>
								</button>
							</li>
						) ) }
					</ul>
				</div>
			) ) }
		</div>
	);
}
