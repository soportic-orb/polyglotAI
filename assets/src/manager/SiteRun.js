/**
 * Panel de la traducción de sitio completo.
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';

import { commandSiteRun, estimateSiteRun, fetchSiteRun } from './api';

const boot = window.pgaiManager || {};

// Mientras hay un lote en vuelo el estado cambia cada muchos minutos: preguntar
// más a menudo no adelanta nada y carga el servidor con cada pestaña abierta.
const POLL_MS = 15000;

/**
 * Nombre legible de un estado.
 *
 * @param {string} status Estado.
 * @return {string} Etiqueta.
 */
function statusLabel( status ) {
	switch ( status ) {
		case 'queued':
			return __( 'Preparando el siguiente lote', 'polyglot-ai' );
		case 'waiting':
			return __( 'Esperando a la API', 'polyglot-ai' );
		case 'paused':
			return __( 'En pausa', 'polyglot-ai' );
		case 'done':
			return __( 'Terminada', 'polyglot-ai' );
		case 'failed':
			return __( 'Detenida por un error', 'polyglot-ai' );
		default:
			return status;
	}
}

/**
 * Traducción de sitio completo para un idioma.
 *
 * @param {Object}   props          Propiedades.
 * @param {string}   props.language Locale.
 * @param {Function} props.onFinish Se llama al terminar, para refrescar la lista.
 * @return {JSX.Element|null} Panel, o nada si no se puede usar.
 */
export default function SiteRun( { language, onFinish } ) {
	const [ supported, setSupported ] = useState( true );
	const [ run, setRun ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ estimate, setEstimate ] = useState( null );

	const load = useCallback( async () => {
		if ( ! language ) {
			return null;
		}

		try {
			const response = await fetchSiteRun( language );

			setSupported( response.supported !== false );
			setRun( response.run || null );

			return response.run || null;
		} catch ( failure ) {
			setError( failure.message || '' );

			return null;
		}
	}, [ language ] );

	useEffect( () => {
		load();
		setEstimate( null );
	}, [ load ] );

	/**
	 * Pide la estimación de coste.
	 */
	const askEstimate = async () => {
		setBusy( true );
		setError( '' );

		try {
			setEstimate( await estimateSiteRun( language ) );
		} catch ( failure ) {
			setError(
				failure.message ||
					__( 'No se ha podido estimar.', 'polyglot-ai' )
			);
		}

		setBusy( false );
	};

	// Solo se pregunta mientras hay algo en marcha: una pasada terminada no
	// cambia sola y seguir preguntando sería ruido.
	useEffect( () => {
		if ( ! run || ! run.active ) {
			return undefined;
		}

		const timer = setInterval( async () => {
			const fresh = await load();

			if ( fresh && ! fresh.active ) {
				onFinish();
			}
		}, POLL_MS );

		return () => clearInterval( timer );
	}, [ run, load, onFinish ] );

	/**
	 * Envía una orden.
	 *
	 * @param {string} command Orden.
	 */
	const send = async ( command ) => {
		if (
			command === 'start' &&
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'Se enviarán a la API todas las cadenas pendientes de este idioma. Esto consume presupuesto.',
					'polyglot-ai'
				)
			)
		) {
			return;
		}

		setBusy( true );
		setError( '' );

		try {
			const response = await commandSiteRun( command, language );

			setRun( response.run || null );
		} catch ( failure ) {
			setError(
				failure.message || __( 'No se ha podido hacer.', 'polyglot-ai' )
			);
		}

		setBusy( false );
	};

	if ( ! boot.can || ! boot.can.autoRun ) {
		return null;
	}

	if ( ! supported ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'El motor configurado no admite traducir el sitio entero en diferido.',
					'polyglot-ai'
				) }
			</Notice>
		);
	}

	return (
		<section className="pgai-site-run">
			<h2>{ __( 'Traducir todo el sitio', 'polyglot-ai' ) }</h2>

			<p className="description">
				{ __(
					'Envía lo pendiente en diferido, que cuesta la mitad. Tarda horas y se puede pausar y reanudar: no hace falta dejar esta pantalla abierta.',
					'polyglot-ai'
				) }
			</p>

			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ run && (
				<>
					<p className="pgai-site-run__status">
						{ statusLabel( run.status ) }
						{ run.message ? ` — ${ run.message }` : '' }
					</p>

					<progress
						className="pgai-site-run__bar"
						max={ 100 }
						value={ run.progress }
					>
						{ run.progress }%
					</progress>

					<p className="pgai-site-run__counts">
						{ sprintf(
							/* translators: 1: hechas, 2: total, 3: fallidas. */
							__(
								'%1$d de %2$d cadenas, %3$d con error',
								'polyglot-ai'
							),
							run.done,
							run.total,
							run.failed
						) }
					</p>
				</>
			) }

			{ estimate && (
				<p className="pgai-site-run__estimate">
					{ sprintf(
						/* translators: 1: cadenas, 2: tokens de entrada. */
						__(
							'Quedan %1$d cadenas, unos %2$d tokens de entrada. La lectura de caché y la salida se suman aparte.',
							'polyglot-ai'
						),
						estimate.strings || 0,
						estimate.input_tokens || 0
					) }
				</p>
			) }

			<div className="pgai-site-run__actions">
				{ ( ! run || ! run.active ) && (
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ askEstimate }
					>
						{ __( 'Estimar el coste', 'polyglot-ai' ) }
					</Button>
				) }

				{ ( ! run || ! run.active ) && run?.status !== 'paused' && (
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ () => send( 'start' ) }
					>
						{ __( 'Empezar', 'polyglot-ai' ) }
					</Button>
				) }

				{ run && run.active && (
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () => send( 'pause' ) }
					>
						{ __( 'Pausar', 'polyglot-ai' ) }
					</Button>
				) }

				{ run && run.status === 'paused' && (
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ () => send( 'resume' ) }
					>
						{ __( 'Reanudar', 'polyglot-ai' ) }
					</Button>
				) }

				{ run && (
					<Button
						isDestructive
						variant="secondary"
						disabled={ busy }
						onClick={ () => send( 'cancel' ) }
					>
						{ __( 'Cancelar', 'polyglot-ai' ) }
					</Button>
				) }
			</div>
		</section>
	);
}
