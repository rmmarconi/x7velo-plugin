/**
 * Audit state machine: first-run auto-run, re-run, 409 "already running" polling.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { getAudit, runAudit } from './api';

export const POLL_MS = 5000;
export const LOCK_MS = 5 * 60 * 1000;

/**
 * @param {Object}                                      options
 * @param {() => void}                                  options.onStart                  Called when a run starts.
 * @param {boolean}                                     [options.woocommerceActive=true] WooCommerce active now.
 *                                                                                       A stored record from the other mode is
 *                                                                                       re-run automatically (spec §15.4).
 * @param {(record: Object, previous: ?Object) => void} options.onFinished               Called with (record, previousRecord) after a run or a
 *                                                                                       finished foreign run.
 * @return {Object} { phase, record, error, waiting, otherFailed, run }
 *   phase: loading | running | ready | error.
 */
export function useAudit( { onFinished, onStart, woocommerceActive = true } ) {
	const [ state, setState ] = useState( {
		phase: 'loading',
		record: null,
		error: null,
		waiting: false,
		otherFailed: false,
	} );
	const recordRef = useRef( null );
	const timer = useRef( null );
	const mounted = useRef( true );
	const finishedRef = useRef( onFinished );
	finishedRef.current = onFinished;
	const startRef = useRef( onStart );
	startRef.current = onStart;

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
			clearTimeout( timer.current );
		};
	}, [] );

	const set = useCallback( ( patch ) => {
		if ( mounted.current ) {
			setState( ( s ) => ( { ...s, ...patch } ) );
		}
	}, [] );

	const finish = useCallback(
		( record, previous ) => {
			recordRef.current = record;
			set( {
				phase: 'ready',
				record,
				error: null,
				waiting: false,
				otherFailed: false,
			} );
			finishedRef.current?.( record, previous );
		},
		[ set ]
	);

	const waitForOther = useCallback( () => {
		const started = Date.now();
		const before = recordRef.current?.generatedAt || '';
		set( { waiting: true, otherFailed: false, error: null } );
		const tick = async () => {
			if ( ! mounted.current ) {
				return;
			}
			try {
				const latest = await getAudit();
				if (
					latest &&
					latest.generatedAt &&
					latest.generatedAt !== before
				) {
					finish( latest, recordRef.current );
					return;
				}
			} catch {
				// Keep waiting; a transient failure shouldn't end the wait.
			}
			if ( Date.now() - started >= LOCK_MS ) {
				set( {
					waiting: false,
					otherFailed: true,
					phase: recordRef.current ? 'ready' : 'error',
				} );
				return;
			}
			timer.current = setTimeout( tick, POLL_MS );
		};
		timer.current = setTimeout( tick, POLL_MS );
	}, [ finish, set ] );

	const run = useCallback( async () => {
		clearTimeout( timer.current );
		const previous = recordRef.current;
		set( { phase: 'running', error: null, otherFailed: false } );
		startRef.current?.();
		try {
			const record = await runAudit();
			finish( record, previous );
			return record;
		} catch ( error ) {
			if ( error.code === 'x7velo_audit_running' ) {
				set( { phase: previous ? 'ready' : 'running' } );
				waitForOther();
				return null;
			}
			set( { phase: previous ? 'ready' : 'error', error } );
			return null;
		}
	}, [ finish, set, waitForOther ] );

	// Load stored results; never audited (or v1 record) → run automatically (AC 80).
	useEffect( () => {
		let cancelled = false;
		( async () => {
			try {
				const record = await getAudit();
				if ( cancelled ) {
					return;
				}
				// WooCommerce turned on or off since that audit: its results describe a different
				// kind of site, so re-run (read-only, no confirmation) as on a first visit.
				if (
					record &&
					( record.woocommerceActive !== false ) !==
						( woocommerceActive !== false )
				) {
					run();
				} else if ( record ) {
					recordRef.current = record;
					set( { phase: 'ready', record } );
				} else {
					run();
				}
			} catch ( error ) {
				if ( ! cancelled ) {
					set( { phase: 'error', error } );
				}
			}
		} )();
		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return { ...state, run };
}
