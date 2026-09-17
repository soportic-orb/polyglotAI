/**
 * Bloque del selector de idioma.
 *
 * El bloque no pinta el selector en el editor: se pinta en el servidor, porque
 * los enlaces dependen de la página que se esté viendo y de sus slugs
 * traducidos, y eso no se sabe hasta que alguien visita la página. En el editor
 * se muestra una vista aproximada con los idiomas del sitio.
 */

import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';

import metadata from './block.json';

const boot = window.pgaiBlock || {};

/**
 * Texto de un idioma según el modo de presentación.
 *
 * @param {Object} language Idioma.
 * @param {string} display  Modo.
 * @return {string} Etiqueta.
 */
function label( language, display ) {
	const code = ( language.code || '' ).toUpperCase();

	switch ( display ) {
		case 'code':
			return code;
		case 'both':
			return `${ language.label } (${ code })`;
		case 'flag':
			return language.flag || language.label;
		case 'flag_name':
			return language.flag
				? `${ language.flag } ${ language.label }`
				: language.label;
		default:
			return language.label;
	}
}

/**
 * Vista del bloque en el editor.
 *
 * @param {Object}   props               Propiedades del bloque.
 * @param {Object}   props.attributes    Atributos.
 * @param {Function} props.setAttributes Actualiza los atributos.
 * @return {JSX.Element} Bloque.
 */
function Edit( { attributes, setAttributes } ) {
	const { display, layout, hideCurrent } = attributes;
	const languages = boot.languages || [];

	const blockProps = useBlockProps( {
		className: `pgai-switcher pgai-switcher--${ layout }`,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Selector de idioma', 'polyglot-ai' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Mostrar', 'polyglot-ai' ) }
						value={ display }
						options={ [
							{
								value: 'name',
								label: __( 'Nombre', 'polyglot-ai' ),
							},
							{
								value: 'code',
								label: __( 'Código', 'polyglot-ai' ),
							},
							{
								value: 'both',
								label: __( 'Nombre y código', 'polyglot-ai' ),
							},
							{
								value: 'flag',
								label: __( 'Bandera', 'polyglot-ai' ),
							},
							{
								value: 'flag_name',
								label: __( 'Bandera y nombre', 'polyglot-ai' ),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { display: value } )
						}
					/>

					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Disposición', 'polyglot-ai' ) }
						value={ layout }
						options={ [
							{
								value: 'list',
								label: __( 'Lista', 'polyglot-ai' ),
							},
							{
								value: 'inline',
								label: __( 'En línea', 'polyglot-ai' ),
							},
							{
								value: 'dropdown',
								label: __( 'Desplegable', 'polyglot-ai' ),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
					/>

					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Ocultar el idioma en curso',
							'polyglot-ai'
						) }
						checked={ hideCurrent }
						onChange={ ( value ) =>
							setAttributes( { hideCurrent: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ languages.length === 0 ? (
					<p>
						{ __(
							'Añade algún idioma para que el selector tenga algo que mostrar.',
							'polyglot-ai'
						) }
					</p>
				) : (
					<ul className="pgai-switcher__list">
						{ languages.map( ( language ) => (
							<li
								key={ language.locale }
								className="pgai-switcher__item"
							>
								<span className="pgai-switcher__link">
									{ label( language, display ) }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	...metadata,
	edit: Edit,

	// El HTML lo pone el servidor en cada visita.
	save() {
		return null;
	},
} );
