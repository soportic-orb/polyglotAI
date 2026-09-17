/**
 * Aplicación del editor visual.
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import StringEditor from './StringEditor';
import StringList from './StringList';
import { buildSrcset, openMediaLibrary } from './media';
import { createMerge, removeMerge, saveStrings, suggestStrings } from './api';

const boot = window.pgaiEditor || {};

/**
 * Editor visual: vista previa a la derecha, panel a la izquierda.
 *
 * @return {JSX.Element} Aplicación.
 */
export default function App() {
	const [ language, setLanguage ] = useState( boot.initial || '' );
	const [ previewUrl, setPreviewUrl ] = useState( boot.previewUrl || '' );
	const [ strings, setStrings ] = useState( [] );
	const [ selected, setSelected ] = useState( null );
	const [ draft, setDraft ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ message, setMessage ] = useState( '' );

	// Al cambiar una imagen hay que cambiar también su srcset, o la traducción
	// solo se vería en algunos tamaños de pantalla.
	const [ companion, setCompanion ] = useState( null );

	// Cadenas marcadas para fusionar en un solo bloque de traducción.
	const [ checked, setChecked ] = useState( [] );

	const iframe = useRef( null );

	const current = useMemo(
		() => strings.find( ( string ) => string.hash === selected ) || null,
		[ strings, selected ]
	);

	/**
	 * Envía un mensaje a la vista previa.
	 *
	 * @param {string} type    Tipo.
	 * @param {Object} payload Datos.
	 */
	const toPreview = useCallback( ( type, payload = {} ) => {
		if ( iframe.current && iframe.current.contentWindow ) {
			iframe.current.contentWindow.postMessage(
				{ source: 'pgai-editor', type, ...payload },
				window.location.origin
			);
		}
	}, [] );

	// Mensajes que llegan del iframe.
	useEffect( () => {
		/**
		 * @param {MessageEvent} event Evento.
		 */
		const onMessage = ( event ) => {
			if (
				event.origin !== window.location.origin ||
				! event.data ||
				event.data.source !== 'pgai-preview'
			) {
				return;
			}

			if ( event.data.type === 'ready' ) {
				setStrings( event.data.strings || [] );
				setLoading( false );
				setSelected( null );
				setChecked( [] );
				setError( '' );
			}

			if ( event.data.type === 'select' ) {
				setSelected( event.data.hash );
			}

			if ( event.data.type === 'navigate' ) {
				// Navegar dentro de la vista previa recarga el iframe; las
				// cadenas de la página nueva llegarán con el siguiente «ready».
				setLoading( true );
				setPreviewUrl( event.data.url );
			}
		};

		window.addEventListener( 'message', onMessage );

		return () => window.removeEventListener( 'message', onMessage );
	}, [] );

	// Al cambiar de cadena, el borrador parte de su traducción actual.
	useEffect( () => {
		setDraft( current ? current.translation || '' : '' );
		setCompanion( null );
		setError( '' );
	}, [ current ] );

	/**
	 * Abre la mediateca para sustituir la imagen seleccionada.
	 */
	const pickImage = useCallback( () => {
		if ( ! current ) {
			return;
		}

		openMediaLibrary( ( attachment ) => {
			setDraft( attachment.url );

			// El srcset de esta imagen viaja como cadena aparte, enlazada por
			// su contexto; se prepara para guardarse junto con ella.
			const srcset = strings.find(
				( string ) =>
					string.type === 'image' &&
					string.context === `srcset:${ current.original }`
			);

			setCompanion(
				srcset
					? {
							hash: srcset.hash,
							translation: buildSrcset( attachment ),
					  }
					: null
			);
		} );
	}, [ current, strings ] );

	/**
	 * Selecciona una cadena y la resalta en la vista previa.
	 */
	const select = useCallback(
		( hash ) => {
			setSelected( hash );
			toPreview( 'highlight', { hash } );
		},
		[ toPreview ]
	);

	/**
	 * Mueve la selección por la lista.
	 *
	 * @param {number} delta Desplazamiento.
	 */
	const move = useCallback(
		( delta ) => {
			if ( strings.length === 0 ) {
				return;
			}

			const index = strings.findIndex(
				( string ) => string.hash === selected
			);
			const next =
				strings[ ( index + delta + strings.length ) % strings.length ];

			if ( next ) {
				select( next.hash );
			}
		},
		[ strings, selected, select ]
	);

	/**
	 * Guarda la cadena en edición.
	 *
	 * @param {boolean} reviewed Si se marca como revisada.
	 * @param {boolean} advance  Si se pasa a la siguiente al terminar.
	 */
	const save = useCallback(
		async ( reviewed, advance ) => {
			if ( ! current ) {
				return;
			}

			setBusy( true );
			setError( '' );

			try {
				const status = reviewed ? 'reviewed' : 'manual';
				const payload = [
					{ hash: current.hash, translation: draft, status },
				];

				if ( companion ) {
					payload.push( { ...companion, status } );
				}

				const response = await saveStrings( payload, language );

				if ( response.rejected && response.rejected[ current.hash ] ) {
					// El servidor aplica a lo escrito a mano la misma validación
					// estructural que a lo que devuelve el motor.
					setError(
						sprintf(
							/* translators: %s: motivo del rechazo. */
							__(
								'La traducción no conserva la estructura del original (%s).',
								'polyglot-ai'
							),
							response.rejected[ current.hash ]
						)
					);
					setBusy( false );

					return;
				}

				const saved = response.saved[ current.hash ];

				if ( saved ) {
					setStrings( ( previous ) =>
						previous.map( ( string ) =>
							string.hash === current.hash
								? {
										...string,
										translation: saved.translation,
										status: saved.status,
								  }
								: string
						)
					);

					toPreview( 'update', {
						hash: current.hash,
						translation: saved.translation,
						status: saved.status,
					} );
				}

				// El srcset se aplica en la vista previa igual que la imagen.
				if ( companion && response.saved[ companion.hash ] ) {
					setStrings( ( previous ) =>
						previous.map( ( string ) =>
							string.hash === companion.hash
								? {
										...string,
										translation:
											response.saved[ companion.hash ]
												.translation,
								  }
								: string
						)
					);
				}

				setCompanion( null );

				if ( advance ) {
					move( 1 );
				}
			} catch ( failure ) {
				setError(
					failure.message ||
						__( 'No se ha podido guardar.', 'polyglot-ai' )
				);
			}

			setBusy( false );
		},
		[ current, draft, language, move, toPreview, companion ]
	);

	/**
	 * Pide una sugerencia para la cadena en edición.
	 */
	const suggest = useCallback( async () => {
		if ( ! current ) {
			return;
		}

		setBusy( true );
		setError( '' );

		try {
			const response = await suggestStrings(
				[ current.hash ],
				language,
				false
			);

			if (
				response.suggestions &&
				response.suggestions[ current.hash ]
			) {
				// La sugerencia solo rellena el campo: no se guarda hasta que el
				// traductor la acepta.
				setDraft( response.suggestions[ current.hash ] );
			} else if (
				response.failures &&
				response.failures[ current.hash ]
			) {
				setError(
					sprintf(
						/* translators: %s: motivo del fallo. */
						__(
							'El motor no ha devuelto una traducción válida (%s).',
							'polyglot-ai'
						),
						response.failures[ current.hash ]
					)
				);
			}
		} catch ( failure ) {
			setError(
				failure.message ||
					__( 'No se ha podido pedir la sugerencia.', 'polyglot-ai' )
			);
		}

		setBusy( false );
	}, [ current, language ] );

	/**
	 * Traduce con IA todas las cadenas pendientes de la página.
	 */
	const translatePending = useCallback( async () => {
		const pending = strings.filter(
			( string ) =>
				string.status === 'pending' || string.status === 'error'
		);

		if ( pending.length === 0 ) {
			setMessage(
				__( 'No queda nada pendiente en esta página.', 'polyglot-ai' )
			);

			return;
		}

		setBusy( true );
		setError( '' );
		setMessage( '' );

		try {
			const response = await suggestStrings(
				pending.map( ( string ) => string.hash ),
				language,
				true
			);

			const suggestions = response.suggestions || {};

			setStrings( ( previous ) =>
				previous.map( ( string ) =>
					suggestions[ string.hash ]
						? {
								...string,
								translation: suggestions[ string.hash ],
								status: 'automatic',
						  }
						: string
				)
			);

			Object.entries( suggestions ).forEach(
				( [ hash, translation ] ) => {
					toPreview( 'update', {
						hash,
						translation,
						status: 'automatic',
					} );
				}
			);

			setMessage(
				sprintf(
					/* translators: 1: cadenas traducidas, 2: cadenas que han fallado. */
					__(
						'%1$d cadenas traducidas, %2$d descartadas por no validar.',
						'polyglot-ai'
					),
					Object.keys( suggestions ).length,
					Object.keys( response.failures || {} ).length
				)
			);
		} catch ( failure ) {
			setError(
				failure.message ||
					__( 'No se ha podido traducir la página.', 'polyglot-ai' )
			);
		}

		setBusy( false );
	}, [ strings, language, toPreview ] );

	/**
	 * Recarga la vista previa.
	 *
	 * Fusionar o deshacer una fusión cambia cómo se trocea la página, así que
	 * las cadenas hay que volver a leerlas del servidor.
	 */
	const reloadPreview = useCallback( () => {
		setLoading( true );

		if ( iframe.current && iframe.current.contentWindow ) {
			iframe.current.contentWindow.location.reload();
		}
	}, [] );

	/**
	 * Marca o desmarca una cadena para fusionarla.
	 *
	 * @param {string} hash Hash.
	 */
	const toggleCheck = useCallback( ( hash ) => {
		setChecked( ( previous ) =>
			previous.includes( hash )
				? previous.filter( ( item ) => item !== hash )
				: [ ...previous, hash ]
		);
	}, [] );

	/**
	 * Fusiona las cadenas marcadas.
	 */
	const mergeChecked = useCallback( async () => {
		setBusy( true );
		setError( '' );

		try {
			// Se manda en el orden en que aparecen en la página, no en el que se
			// marcaron: la fusión solo se aplica a cadenas consecutivas.
			const ordered = strings
				.filter( ( string ) => checked.includes( string.hash ) )
				.map( ( string ) => string.hash );

			await createMerge( ordered );
			setChecked( [] );
			reloadPreview();
		} catch ( failure ) {
			setError(
				failure.message ||
					__( 'No se han podido fusionar.', 'polyglot-ai' )
			);
		}

		setBusy( false );
	}, [ strings, checked, reloadPreview ] );

	/**
	 * Deshace la fusión del bloque seleccionado.
	 */
	const unmerge = useCallback( async () => {
		if (
			! current ||
			! String( current.context || '' ).startsWith( 'merge:' )
		) {
			return;
		}

		setBusy( true );
		setError( '' );

		try {
			await removeMerge( current.context );
			reloadPreview();
		} catch ( failure ) {
			setError(
				failure.message ||
					__( 'No se ha podido deshacer.', 'polyglot-ai' )
			);
		}

		setBusy( false );
	}, [ current, reloadPreview ] );

	/**
	 * Cambia el idioma que se está traduciendo.
	 *
	 * @param {string} locale Locale.
	 */
	const changeLanguage = ( locale ) => {
		setLanguage( locale );
		setLoading( true );

		const url = new URL( previewUrl, window.location.origin );

		url.searchParams.set( 'pgai-lang', locale );
		setPreviewUrl( url.toString() );
	};

	const languageOptions = ( boot.languages || [] ).map( ( item ) => ( {
		value: item.locale,
		label: item.published
			? item.label
			: `${ item.label } (${ __( 'en preparación', 'polyglot-ai' ) })`,
	} ) );

	return (
		<div className="pgai-editor">
			<header className="pgai-editor__bar">
				<strong className="pgai-editor__brand">
					{ __( 'Editor de traducciones', 'polyglot-ai' ) }
				</strong>

				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Idioma', 'polyglot-ai' ) }
					hideLabelFromVision
					value={ language }
					options={ languageOptions }
					onChange={ changeLanguage }
					disabled={ busy }
				/>

				{ boot.can && boot.can.autoRun && (
					<Button
						variant="secondary"
						onClick={ translatePending }
						disabled={ busy || loading }
					>
						{ __( 'Traducir lo pendiente con IA', 'polyglot-ai' ) }
					</Button>
				) }

				<a className="pgai-editor__exit" href={ boot.settingsUrl }>
					{ __( 'Salir', 'polyglot-ai' ) }
				</a>
			</header>

			{ message && (
				<Notice status="info" onRemove={ () => setMessage( '' ) }>
					{ message }
				</Notice>
			) }

			<div className="pgai-editor__body">
				<aside className="pgai-editor__panel">
					{ loading ? (
						<div className="pgai-editor__loading">
							<Spinner />
							<p>
								{ __(
									'Leyendo las cadenas de la página…',
									'polyglot-ai'
								) }
							</p>
						</div>
					) : (
						<>
							<StringEditor
								string={ current }
								draft={ draft }
								onDraft={ setDraft }
								canReview={ Boolean(
									boot.can && boot.can.review
								) }
								canSuggest={ Boolean(
									boot.can && boot.can.autoRun
								) }
								busy={ busy }
								error={ error }
								onSave={ save }
								onSuggest={ suggest }
								onPickImage={ pickImage }
								onUnmerge={ unmerge }
								onPrevious={ () => move( -1 ) }
								onNext={ () => move( 1 ) }
							/>

							<StringList
								strings={ strings }
								selected={ selected }
								onSelect={ select }
								checked={ checked }
								onCheck={ toggleCheck }
								onMerge={ mergeChecked }
							/>
						</>
					) }
				</aside>

				<main className="pgai-editor__preview">
					<iframe
						ref={ iframe }
						src={ previewUrl }
						title={ __( 'Vista previa del sitio', 'polyglot-ai' ) }
						className="pgai-editor__iframe"
					/>
				</main>
			</div>
		</div>
	);
}
