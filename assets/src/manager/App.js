/**
 * Gestor de cadenas: busca entre todas las del sitio y actúa en lote.
 */

import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';

import Row from './Row';
import SiteRun from './SiteRun';
import { applyBulk, fetchStrings, saveTranslation } from './api';

const boot = window.pgaiManager || {};

const PER_PAGE = 50;

/**
 * Pantalla del gestor.
 *
 * @return {JSX.Element} Aplicación.
 */
export default function App() {
	const languages = boot.languages || [];

	const [ language, setLanguage ] = useState(
		languages.length > 0 ? languages[ 0 ].locale : ''
	);
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ type, setType ] = useState( '' );
	const [ page, setPage ] = useState( 1 );

	const [ items, setItems ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ pages, setPages ] = useState( 1 );
	const [ statuses, setStatuses ] = useState( {} );
	const [ types, setTypes ] = useState( {} );

	const [ selected, setSelected ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ message, setMessage ] = useState( '' );

	const load = useCallback( async () => {
		if ( ! language ) {
			setLoading( false );

			return;
		}

		setLoading( true );
		setError( '' );

		try {
			const response = await fetchStrings( {
				language,
				search: query,
				status,
				type,
				page,
				perPage: PER_PAGE,
			} );

			setItems( response.items || [] );
			setTotal( response.total || 0 );
			setPages( response.pages || 1 );
			setStatuses( response.statuses || {} );
			setTypes( response.types || {} );
			setSelected( [] );
		} catch ( failure ) {
			setError(
				failure.message ||
					__( 'No se han podido leer las cadenas.', 'polyglot-ai' )
			);
		}

		setLoading( false );
	}, [ language, query, status, type, page ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// Cualquier cambio de filtro devuelve a la primera página: quedarse en la
	// siete de un listado que ahora tiene dos páginas enseña un vacío.
	const filter = ( setter ) => ( value ) => {
		setter( value );
		setPage( 1 );
	};

	/**
	 * Marca o desmarca una fila.
	 *
	 * @param {number} sourceId Identificador.
	 */
	const toggle = ( sourceId ) =>
		setSelected( ( previous ) =>
			previous.includes( sourceId )
				? previous.filter( ( id ) => id !== sourceId )
				: [ ...previous, sourceId ]
		);

	const allSelected = items.length > 0 && selected.length === items.length;

	/**
	 * Guarda una traducción escrita a mano.
	 *
	 * @param {Object} item        Fila.
	 * @param {string} translation Texto.
	 */
	const save = async ( item, translation ) => {
		setBusy( true );
		setError( '' );

		try {
			await saveTranslation( item.hash, translation, language );

			setItems( ( previous ) =>
				previous.map( ( row ) =>
					row.hash === item.hash
						? { ...row, translation, status: 'manual' }
						: row
				)
			);

			setMessage( __( 'Traducción guardada.', 'polyglot-ai' ) );
		} catch ( failure ) {
			setError(
				failure.message ||
					__( 'No se ha podido guardar.', 'polyglot-ai' )
			);
		}

		setBusy( false );
	};

	/**
	 * Aplica una acción en lote.
	 *
	 * @param {string} action Acción.
	 */
	const bulk = async ( action ) => {
		if ( selected.length === 0 ) {
			return;
		}

		if (
			action === 'delete' &&
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'Se borrarán las traducciones seleccionadas en este idioma. El texto original se conserva.',
					'polyglot-ai'
				)
			)
		) {
			return;
		}

		setBusy( true );
		setError( '' );

		try {
			const response = await applyBulk( action, selected, language );

			setMessage(
				sprintf(
					/* translators: %d: número de cadenas afectadas. */
					__( '%d cadenas actualizadas.', 'polyglot-ai' ),
					response.affected || 0
				)
			);

			await load();
		} catch ( failure ) {
			setError(
				failure.message ||
					__( 'No se ha podido aplicar la acción.', 'polyglot-ai' )
			);
		}

		setBusy( false );
	};

	if ( languages.length === 0 ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'Añade algún idioma para tener cadenas que traducir.',
					'polyglot-ai'
				) }
			</Notice>
		);
	}

	return (
		<div className="pgai-manager">
			<div className="pgai-manager__filters">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Idioma', 'polyglot-ai' ) }
					value={ language }
					options={ languages.map( ( item ) => ( {
						value: item.locale,
						label: item.label,
					} ) ) }
					onChange={ filter( setLanguage ) }
				/>

				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Estado', 'polyglot-ai' ) }
					value={ status }
					options={ [
						{ value: '', label: __( 'Todos', 'polyglot-ai' ) },
						...Object.entries( statuses ).map(
							( [ value, label ] ) => ( { value, label } )
						),
					] }
					onChange={ filter( setStatus ) }
				/>

				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Tipo', 'polyglot-ai' ) }
					value={ type }
					options={ [
						{ value: '', label: __( 'Todos', 'polyglot-ai' ) },
						...Object.entries( types ).map(
							( [ value, label ] ) => ( { value, label } )
						),
					] }
					onChange={ filter( setType ) }
				/>

				<form
					className="pgai-manager__search"
					onSubmit={ ( event ) => {
						event.preventDefault();
						setQuery( search );
						setPage( 1 );
					} }
				>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Buscar', 'polyglot-ai' ) }
						value={ search }
						onChange={ setSearch }
					/>
					<Button variant="secondary" type="submit">
						{ __( 'Buscar', 'polyglot-ai' ) }
					</Button>
				</form>
			</div>

			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ message && (
				<Notice status="success" onRemove={ () => setMessage( '' ) }>
					{ message }
				</Notice>
			) }

			<SiteRun language={ language } onFinish={ load } />

			<div className="pgai-manager__bulk">
				<span>
					{ sprintf(
						/* translators: 1: seleccionadas, 2: total. */
						__( '%1$d de %2$d seleccionadas', 'polyglot-ai' ),
						selected.length,
						total
					) }
				</span>

				{ boot.can && boot.can.review && (
					<Button
						variant="secondary"
						disabled={ busy || selected.length === 0 }
						onClick={ () => bulk( 'review' ) }
					>
						{ __( 'Dar por revisadas', 'polyglot-ai' ) }
					</Button>
				) }

				<Button
					variant="secondary"
					disabled={ busy || selected.length === 0 }
					onClick={ () => bulk( 'retranslate' ) }
				>
					{ __( 'Volver a traducir', 'polyglot-ai' ) }
				</Button>

				<Button
					isDestructive
					variant="secondary"
					disabled={ busy || selected.length === 0 }
					onClick={ () => bulk( 'delete' ) }
				>
					{ __( 'Borrar traducción', 'polyglot-ai' ) }
				</Button>
			</div>

			{ loading ? (
				<p className="pgai-manager__loading">
					<Spinner />
					{ __( 'Buscando…', 'polyglot-ai' ) }
				</p>
			) : (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td className="check-column">
								<input
									type="checkbox"
									checked={ allSelected }
									aria-label={ __(
										'Seleccionar todas las cadenas de esta página',
										'polyglot-ai'
									) }
									onChange={ () =>
										setSelected(
											allSelected
												? []
												: items.map(
														( item ) =>
															item.source_id
												  )
										)
									}
								/>
							</td>
							<th>{ __( 'Original', 'polyglot-ai' ) }</th>
							<th>{ __( 'Traducción', 'polyglot-ai' ) }</th>
							<th>{ __( 'Estado', 'polyglot-ai' ) }</th>
						</tr>
					</thead>

					<tbody>
						{ items.length === 0 ? (
							<tr>
								<td colSpan={ 4 }>
									{ __(
										'No hay cadenas que encajen con estos filtros.',
										'polyglot-ai'
									) }
								</td>
							</tr>
						) : (
							items.map( ( item ) => (
								<Row
									key={ item.hash }
									item={ item }
									statuses={ statuses }
									types={ types }
									checked={ selected.includes(
										item.source_id
									) }
									busy={ busy }
									onCheck={ () => toggle( item.source_id ) }
									onSave={ ( value ) => save( item, value ) }
								/>
							) )
						) }
					</tbody>
				</table>
			) }

			{ pages > 1 && (
				<div className="pgai-manager__pagination">
					<Button
						variant="secondary"
						disabled={ page <= 1 || loading }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Anterior', 'polyglot-ai' ) }
					</Button>

					<span>
						{ sprintf(
							/* translators: 1: página actual, 2: total de páginas. */
							__( 'Página %1$d de %2$d', 'polyglot-ai' ),
							page,
							pages
						) }
					</span>

					<Button
						variant="secondary"
						disabled={ page >= pages || loading }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Siguiente', 'polyglot-ai' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
