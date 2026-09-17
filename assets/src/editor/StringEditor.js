/**
 * Formulario de edición de una cadena.
 */

import { __ } from '@wordpress/i18n';
import {
	Button,
	Flex,
	Notice,
	Spinner,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Etiqueta legible de un estado.
 *
 * @param {string} status Estado.
 * @return {string} Etiqueta.
 */
function statusLabel( status ) {
	const labels = {
		pending: __( 'Pendiente', 'polyglot-ai' ),
		error: __( 'Error', 'polyglot-ai' ),
		automatic: __( 'Automática', 'polyglot-ai' ),
		reviewed: __( 'Revisada', 'polyglot-ai' ),
		manual: __( 'Manual', 'polyglot-ai' ),
	};

	return labels[ status ] || status;
}

/**
 * Panel de edición de la cadena seleccionada.
 *
 * @param {Object}   props             Propiedades.
 * @param {Object}   props.string      Cadena seleccionada.
 * @param {boolean}  props.canReview   Si el usuario puede marcar como revisada.
 * @param {boolean}  props.canSuggest  Si el usuario puede pedir traducción automática.
 * @param {boolean}  props.busy        Si hay una operación en curso.
 * @param {string}   props.error       Error a mostrar.
 * @param {Function} props.onSave      Guardar.
 * @param {Function} props.onSuggest   Sugerir con IA.
 * @param {Function} props.onPrevious  Cadena anterior.
 * @param {Function} props.onNext      Cadena siguiente.
 * @param {Function} props.onDraft     Cambio en el borrador.
 * @param {Function} props.onPickImage Abrir la mediateca.
 * @param {Function} props.onUnmerge   Deshacer la fusión de este bloque.
 * @param {string}   props.draft       Texto en edición.
 * @return {JSX.Element} Formulario.
 */
export default function StringEditor( {
	string,
	canReview,
	canSuggest,
	busy,
	error,
	onSave,
	onSuggest,
	onPrevious,
	onNext,
	onDraft,
	onPickImage,
	onUnmerge,
	draft,
} ) {
	const [ reviewed, setReviewed ] = useState( false );
	const textarea = useRef( null );

	useEffect( () => {
		setReviewed( string ? string.status === 'reviewed' : false );
	}, [ string ] );

	useEffect( () => {
		if ( textarea.current ) {
			textarea.current.focus();
		}
	}, [ string ] );

	if ( ! string ) {
		return (
			<div className="pgai-editor__empty">
				<p>
					{ __(
						'Pulsa cualquier texto de la vista previa para traducirlo.',
						'polyglot-ai'
					) }
				</p>
			</div>
		);
	}

	const dirty = draft !== ( string.translation || '' );

	/**
	 * Atajos de teclado del campo de traducción.
	 *
	 * @param {KeyboardEvent} event Evento.
	 */
	const onKeyDown = ( event ) => {
		if ( ( event.metaKey || event.ctrlKey ) && event.key === 'Enter' ) {
			event.preventDefault();
			onSave( reviewed, true );
		}

		if ( ( event.metaKey || event.ctrlKey ) && event.key === 's' ) {
			event.preventDefault();
			onSave( reviewed, false );
		}
	};

	return (
		<div className="pgai-editor__form">
			{ String( string.context || '' ).startsWith( 'merge:' ) && (
				<Notice
					status="info"
					isDismissible={ false }
					className="pgai-editor__notice"
				>
					{ __(
						'Este texto es un bloque fusionado: varias cadenas se traducen como una sola.',
						'polyglot-ai'
					) }{ ' ' }
					<Button variant="link" onClick={ onUnmerge }>
						{ __( 'Deshacer la fusión', 'polyglot-ai' ) }
					</Button>
				</Notice>
			) }

			<div className="pgai-editor__meta">
				<span className={ `pgai-badge pgai-badge--${ string.status }` }>
					{ statusLabel( string.status ) }
				</span>
				{ string.context && (
					<span className="pgai-editor__context">
						{ string.context }
					</span>
				) }
			</div>

			{ string.type === 'image' ? (
				<div className="pgai-editor__image">
					<p className="pgai-editor__label">
						{ __( 'Imagen original', 'polyglot-ai' ) }
					</p>
					<img
						src={ string.original }
						alt=""
						className="pgai-editor__thumb"
					/>

					<p className="pgai-editor__label">
						{ draft && draft !== string.original
							? __( 'Imagen de este idioma', 'polyglot-ai' )
							: __(
									'Este idioma usa la imagen original',
									'polyglot-ai'
							  ) }
					</p>

					{ draft && draft !== string.original && (
						<img
							src={ draft }
							alt=""
							className="pgai-editor__thumb"
						/>
					) }

					<Flex justify="flex-start" wrap>
						<Button variant="secondary" onClick={ onPickImage }>
							{ __( 'Elegir imagen…', 'polyglot-ai' ) }
						</Button>

						{ draft && draft !== string.original && (
							<Button
								variant="tertiary"
								onClick={ () => onDraft( string.original ) }
							>
								{ __( 'Usar la original', 'polyglot-ai' ) }
							</Button>
						) }
					</Flex>
				</div>
			) : (
				<>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Original', 'polyglot-ai' ) }
						value={ string.original }
						readOnly
						rows={ 3 }
						className="pgai-editor__original"
						onChange={ () => {} }
					/>

					<TextareaControl
						__nextHasNoMarginBottom
						ref={ textarea }
						label={ __( 'Traducción', 'polyglot-ai' ) }
						value={ draft }
						rows={ 5 }
						onChange={ onDraft }
						onKeyDown={ onKeyDown }
						help={ __(
							'Ctrl+S guarda. Ctrl+Intro guarda y pasa a la siguiente.',
							'polyglot-ai'
						) }
					/>
				</>
			) }

			{ canReview && (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Marcar como revisada', 'polyglot-ai' ) }
					checked={ reviewed }
					onChange={ setReviewed }
					help={ __(
						'Una traducción revisada no la sobrescribe ningún proceso automático.',
						'polyglot-ai'
					) }
				/>
			) }

			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
					className="pgai-editor__notice"
				>
					{ error }
				</Notice>
			) }

			<Flex className="pgai-editor__actions" justify="flex-start" wrap>
				<Button
					variant="primary"
					onClick={ () => onSave( reviewed, false ) }
					disabled={ busy || ! dirty }
				>
					{ __( 'Guardar', 'polyglot-ai' ) }
				</Button>

				{ canSuggest && string.type !== 'image' && (
					<Button
						variant="secondary"
						onClick={ onSuggest }
						disabled={ busy }
					>
						{ __( 'Sugerir con IA', 'polyglot-ai' ) }
					</Button>
				) }

				<Button
					variant="tertiary"
					onClick={ () => onDraft( string.translation || '' ) }
					disabled={ ! dirty }
				>
					{ __( 'Descartar cambios', 'polyglot-ai' ) }
				</Button>

				{ busy && <Spinner /> }
			</Flex>

			<Flex className="pgai-editor__nav" justify="space-between">
				<Button
					variant="tertiary"
					onClick={ onPrevious }
					disabled={ busy }
				>
					{ __( '← Anterior', 'polyglot-ai' ) }
				</Button>
				<Button variant="tertiary" onClick={ onNext } disabled={ busy }>
					{ __( 'Siguiente →', 'polyglot-ai' ) }
				</Button>
			</Flex>
		</div>
	);
}
