/**
 * Edición de los slugs de la página que se está traduciendo.
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';

/**
 * Nombre legible de un estado.
 *
 * @param {string} status Estado.
 * @return {string} Etiqueta.
 */
function statusLabel( status ) {
	switch ( status ) {
		case 'manual':
			return __( 'Manual', 'polyglot-ai' );
		case 'reviewed':
			return __( 'Revisada', 'polyglot-ai' );
		case 'automatic':
			return __( 'Automática', 'polyglot-ai' );
		case 'error':
			return __( 'Error', 'polyglot-ai' );
		default:
			return __( 'Pendiente', 'polyglot-ai' );
	}
}

/**
 * Panel de slugs de la página.
 *
 * Un slug no es una cadena más: cambiarlo cambia la URL de la página en ese
 * idioma, así que se edita aparte y con su propio botón de guardar, no dentro
 * de la lista de cadenas.
 *
 * @param {Object}   props        Propiedades.
 * @param {Array}    props.slugs  Slugs de la página.
 * @param {boolean}  props.busy   Si hay una operación en curso.
 * @param {Function} props.onSave Guarda un slug. Recibe el slug completo.
 * @return {JSX.Element|null} Panel, o nada si la página no tiene slugs.
 */
export default function SlugPanel( { slugs, busy, onSave } ) {
	const [ drafts, setDrafts ] = useState( {} );
	const [ error, setError ] = useState( '' );

	if ( ! slugs || slugs.length === 0 ) {
		return null;
	}

	/**
	 * Clave estable de un slug.
	 *
	 * @param {Object} slug Slug.
	 * @return {string} Clave.
	 */
	const keyOf = ( slug ) =>
		`${ slug.object_type }:${ slug.object_subtype }:${ slug.object_id }`;

	/**
	 * Guarda un slug y refleja lo que haya devuelto el servidor.
	 *
	 * @param {Object} slug Slug.
	 */
	const save = async ( slug ) => {
		const key = keyOf( slug );
		const value = drafts[ key ] ?? slug.translated_slug;

		setError( '' );

		try {
			await onSave( { ...slug, translated_slug: value } );

			setDrafts( ( previous ) => {
				const next = { ...previous };

				delete next[ key ];

				return next;
			} );
		} catch ( failure ) {
			setError(
				failure.message ||
					__( 'No se ha podido guardar el slug.', 'polyglot-ai' )
			);
		}
	};

	return (
		<section className="pgai-slugs">
			<h2 className="pgai-slugs__title">
				{ __( 'Dirección de la página', 'polyglot-ai' ) }
			</h2>

			<p className="pgai-slugs__help">
				{ __(
					'Cambiar un slug cambia la URL de esta página en este idioma. La anterior redirige a la nueva.',
					'polyglot-ai'
				) }
			</p>

			{ error && <Notice status="error">{ error }</Notice> }

			{ slugs.map( ( slug ) => {
				const key = keyOf( slug );

				return (
					<div className="pgai-slugs__item" key={ key }>
						<TextControl
							__nextHasNoMarginBottom
							label={ sprintf(
								/* translators: 1: nombre del elemento, 2: slug original. */
								__( '%1$s (original: %2$s)', 'polyglot-ai' ),
								slug.label || slug.original_slug,
								slug.original_slug
							) }
							value={ drafts[ key ] ?? slug.translated_slug }
							placeholder={ slug.original_slug }
							onChange={ ( value ) =>
								setDrafts( ( previous ) => ( {
									...previous,
									[ key ]: value,
								} ) )
							}
							disabled={ busy }
						/>

						<div className="pgai-slugs__row">
							<span className="pgai-slugs__status">
								{ statusLabel( slug.status ) }
							</span>

							<Button
								variant="secondary"
								onClick={ () => save( slug ) }
								disabled={
									busy ||
									! ( drafts[ key ] ?? slug.translated_slug )
								}
							>
								{ __( 'Guardar slug', 'polyglot-ai' ) }
							</Button>
						</div>
					</div>
				);
			} ) }
		</section>
	);
}
