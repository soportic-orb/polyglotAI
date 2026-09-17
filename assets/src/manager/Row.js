/**
 * Una fila del gestor de cadenas.
 */

import { __ } from '@wordpress/i18n';
import { Button, TextareaControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';

/**
 * Fila editable.
 *
 * @param {Object}   props          Propiedades.
 * @param {Object}   props.item     Cadena.
 * @param {Object}   props.statuses Nombres de los estados.
 * @param {Object}   props.types    Nombres de los tipos.
 * @param {boolean}  props.checked  Si está seleccionada.
 * @param {boolean}  props.busy     Si hay una operación en curso.
 * @param {Function} props.onCheck  Marca o desmarca.
 * @param {Function} props.onSave   Guarda la traducción.
 * @return {JSX.Element} Fila.
 */
export default function Row( {
	item,
	statuses,
	types,
	checked,
	busy,
	onCheck,
	onSave,
} ) {
	const [ draft, setDraft ] = useState( item.translation );

	// Si la fila cambia por debajo —una acción en lote, otra página— el
	// borrador tiene que seguirla en vez de quedarse con lo anterior.
	useEffect( () => setDraft( item.translation ), [ item.translation ] );

	const dirty = draft !== item.translation;

	return (
		<tr>
			<th scope="row" className="check-column">
				<input
					type="checkbox"
					checked={ checked }
					onChange={ onCheck }
					aria-label={ __(
						'Seleccionar esta cadena',
						'polyglot-ai'
					) }
				/>
			</th>

			<td>
				<div className="pgai-manager__original">{ item.original }</div>

				<div className="pgai-manager__meta">
					{ types[ item.type ] || item.type }
					{ item.context ? ` · ${ item.context }` : '' }
					{ item.domain ? ` · ${ item.domain }` : '' }
				</div>
			</td>

			<td>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Traducción', 'polyglot-ai' ) }
					hideLabelFromVision
					value={ draft }
					rows={ 2 }
					onChange={ setDraft }
					disabled={ busy }
				/>

				{ dirty && (
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ () => onSave( draft ) }
					>
						{ __( 'Guardar', 'polyglot-ai' ) }
					</Button>
				) }
			</td>

			<td>
				<span
					className={ `pgai-manager__status pgai-manager__status--${ item.status }` }
				>
					{ statuses[ item.status ] || item.status }
				</span>
			</td>
		</tr>
	);
}
