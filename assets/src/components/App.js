/**
 * AI Readiness app shell: tabs synced to the URL, shared audit + settings state, the confirm
 * dialog flow for every fixer change, and page-level notices.
 */
import { speak } from '@wordpress/a11y';
import { Notice, SnackbarList, TabPanel } from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, _x, sprintf } from '@wordpress/i18n';

import { getSettings, patchSettings } from '../api';
import { getBootstrap, isSiteMode } from '../bootstrap';
import { AUTO_FIXERS, bandFor, getBands, sourceName } from '../constants';
import { getCheckCopy } from '../copy/checks';
import { FIXER_ACTION, getDialog } from '../copy/dialogs';
import { unavailableReason } from '../copy/fixers';
import { useAudit } from '../useAudit';
import ConfirmModal from './ConfirmModal';
import FixesTab from './FixesTab';
import Overview from './Overview';
import SettingsTab from './SettingsTab';

const TABS = [ 'overview', 'fixes', 'settings' ];

/** Settings that feed the JSON-LD returns/delivery parts (server `fixer_status.jsonld.parts`). */
const PARTS_KEYS = [
	'returns_page_id',
	'return_policy',
	'return_days',
	'delivery_days_min',
	'delivery_days_max',
];

/** Dialog action → Fixes card that shows inline warnings for it. */
const ACTION_CARD = {
	jsonld: 'jsonld',
	llms_txt: 'llms_txt',
	feed: 'feed',
	robots: 'robots',
	training: 'robots',
	google_extended: 'robots',
	experimental: 'robots',
};

/**
 * Tabs for the current mode: no Settings tab without WooCommerce (all its fields are store inputs).
 *
 * @param {boolean} siteMode Site mode.
 * @return {string[]} Tab names.
 */
function tabNames( siteMode ) {
	return siteMode ? TABS.filter( ( t ) => t !== 'settings' ) : TABS;
}

function readUrl( siteMode = false ) {
	const params = new URLSearchParams( window.location.search );
	const tab = params.get( 'tab' );
	const check = params.get( 'check' );
	return {
		tab: tabNames( siteMode ).includes( tab ) ? tab : 'overview',
		check: check ? check.toUpperCase() : null,
	};
}

function writeUrl( tab ) {
	const url = new URL( window.location.href );
	if ( url.searchParams.get( 'tab' ) === tab ) {
		return;
	}
	url.searchParams.set( 'tab', tab );
	url.searchParams.delete( 'check' );
	window.history.pushState( { x7veloTab: tab }, '', url.toString() );
}

export function doneMessage( record, siteMode = false ) {
	if ( record.state === 'no_products' && ! siteMode ) {
		return __(
			'Audit finished. Add products first: your store has no published products yet.',
			'x7velo'
		);
	}
	if ( record.state !== 'complete' || record.score === null ) {
		return siteMode
			? __(
					'Audit finished. We couldn’t finish checking your site.',
					'x7velo'
				)
			: __(
					'Audit finished. We couldn’t finish checking your store.',
					'x7velo'
				);
	}
	const band = getBands( siteMode )[ bandFor( record.score ) ].label;
	const toFix = record.checks.filter( ( c ) =>
		[ 'fail', 'warn' ].includes( c.status )
	).length;
	return sprintf(
		/* translators: 1: score 0–100, 2: band label, 3: number of things to fix. */
		_n(
			'Audit finished. Your score is %1$d out of 100: %2$s. %3$d thing to fix.',
			'Audit finished. Your score is %1$d out of 100: %2$s. %3$d things to fix.',
			toFix,
			'x7velo'
		),
		record.score,
		band,
		toFix
	);
}

function ConflictNotices( { notices, onTurnOff, siteMode = false } ) {
	const [ dismissed, setDismissed ] = useState( [] );
	const visible = notices.filter(
		( n ) => n && n.id && ! dismissed.includes( n.id )
	);
	if ( ! visible.length ) {
		return null;
	}
	return visible.map( ( n ) => {
		const name = sourceName( n.source ) || __( 'Another plugin', 'x7velo' );
		const llms = n.area === 'llms_txt' || n.area === 'llms';
		let text;
		if ( n.type === 'resumed' ) {
			text = llms
				? sprintf(
						/* translators: %s: plugin name, e.g. "Yoast SEO". */
						__(
							'%s no longer creates your llms.txt, so x7velo’s llms.txt is active again.',
							'x7velo'
						),
						name
					)
				: sprintf(
						/* translators: %s: plugin name, e.g. "Rank Math". */
						__(
							'%s no longer adds product details, so x7velo’s product details are active again.',
							'x7velo'
						),
						name
					);
			text = `${ text } ${ __( 'You turned it on earlier, so it came back on its own.', 'x7velo' ) }`;
		} else {
			const takenOver = siteMode
				? sprintf(
						/* translators: %s: plugin name, e.g. "Yoast SEO". */
						__(
							'%s now creates your llms.txt, so x7velo paused its own. Your site has one llms.txt, as it should. You don’t need to do anything.',
							'x7velo'
						),
						name
					)
				: sprintf(
						/* translators: %s: plugin name, e.g. "Yoast SEO". */
						__(
							'%s now creates your llms.txt, so x7velo paused its own. Your store has one llms.txt, as it should. You don’t need to do anything.',
							'x7velo'
						),
						name
					);
			text = llms
				? takenOver
				: sprintf(
						/* translators: %1$s: plugin name, e.g. "Rank Math". */
						__(
							'%1$s now adds product details to your pages, so x7velo paused its own. Your products still have product details, just from %1$s.',
							'x7velo'
						),
						name
					);
		}
		const fixer = llms ? 'llms_txt' : 'jsonld';
		return (
			<Notice
				key={ n.id }
				status="info"
				onRemove={ () => setDismissed( ( d ) => [ ...d, n.id ] ) }
				actions={
					n.type === 'resumed'
						? [
								{
									label: __( 'Turn it off', 'x7velo' ),
									variant: 'secondary',
									onClick: () => onTurnOff( fixer ),
								},
							]
						: []
				}
			>
				{ text }
			</Notice>
		);
	} );
}

export default function App() {
	const boot = useMemo( getBootstrap, [] );
	const siteMode = isSiteMode( boot );
	const copy = useMemo(
		() => getCheckCopy( boot.coreLabels, siteMode ),
		[ boot, siteMode ]
	);
	const initial = useMemo( () => readUrl( siteMode ), [ siteMode ] );

	const [ tab, setTab ] = useState(
		initial.check ? 'overview' : initial.tab
	);
	const [ navKey, setNavKey ] = useState( 0 );
	const [ openIds, setOpenIds ] = useState(
		initial.check ? [ initial.check ] : []
	);
	const [ focusId, setFocusId ] = useState( initial.check );
	const [ settings, setSettings ] = useState( {
		status: 'loading',
		values: null,
		fixerStatus: {},
	} );
	const [ stale, setStale ] = useState( false );
	const [ dialog, setDialog ] = useState( null );
	const [ cardNotices, setCardNotices ] = useState( {} );
	const [ sessionExpired, setSessionExpired ] = useState( false );
	const [ snackbars, setSnackbars ] = useState( [] );
	const [ pendingPageId, setPendingPageId ] = useState( 0 );
	const pendingFix = useRef( null );

	const onFinished = useCallback(
		( record, previous ) => {
			setStale( false );
			const fix = pendingFix.current;
			pendingFix.current = null;
			if ( fix ) {
				const parts = [
					sprintf(
						/* translators: %s: name of the fix, e.g. "Product feed". */
						__( '%s turned on.', 'x7velo' ),
						fix.name
					),
				];
				if (
					previous?.score !== null &&
					previous?.score !== undefined &&
					record.score !== null
				) {
					parts.push(
						sprintf(
							/* translators: 1: old score, 2: new score. */
							__( 'Score went from %1$d to %2$d.', 'x7velo' ),
							previous.score,
							record.score
						)
					);
				}
				speak( parts.join( ' ' ) );
				if ( fix.checkId ) {
					setOpenIds( ( ids ) =>
						ids.includes( fix.checkId )
							? ids
							: [ ...ids, fix.checkId ]
					);
					setFocusId( fix.checkId );
				}
				return;
			}
			speak( doneMessage( record, siteMode ) );
		},
		[ siteMode ]
	);

	const onStart = useCallback(
		() =>
			speak(
				siteMode
					? __( 'Checking your site…', 'x7velo' )
					: __( 'Checking your store…', 'x7velo' )
			),
		[ siteMode ]
	);
	const audit = useAudit( {
		onFinished,
		onStart,
		woocommerceActive: ! siteMode,
	} );

	useEffect( () => {
		if ( audit.error?.isSessionExpired ) {
			setSessionExpired( true );
		}
	}, [ audit.error ] );

	const settingsSeq = useRef( 0 );
	const loadSettings = useCallback( async () => {
		settingsSeq.current++;
		setSettings( ( s ) => ( { ...s, status: 'loading' } ) );
		try {
			const result = await getSettings();
			setSettings(
				result
					? { status: 'ready', ...result }
					: { status: 'missing', values: null, fixerStatus: {} }
			);
			return result;
		} catch ( error ) {
			if ( error.isSessionExpired ) {
				setSessionExpired( true );
			}
			setSettings( { status: 'error', values: null, fixerStatus: {} } );
			return null;
		}
	}, [] );

	useEffect( () => {
		loadSettings();
	}, [ loadSettings ] );

	/*
	 * After a save that changed return/delivery inputs, read settings again so the JSON-LD
	 * `parts` reflect the stored values (they're computed server-side from more than the form).
	 * Quiet: no loading state, and dropped if anything else updated settings in the meantime.
	 */
	const refreshParts = useCallback( async () => {
		const seq = ++settingsSeq.current;
		try {
			const result = await getSettings();
			if ( result && seq === settingsSeq.current ) {
				setSettings( { status: 'ready', ...result } );
			}
		} catch ( error ) {
			if ( error.isSessionExpired ) {
				setSessionExpired( true );
			}
		}
	}, [] );

	const onSettingsSaved = ( next ) => {
		settingsSeq.current++;
		const before = settings.values || {};
		setSettings( { status: 'ready', ...next } );
		setStale( true );
		if (
			PARTS_KEYS.some( ( key ) => before[ key ] !== next.values?.[ key ] )
		) {
			refreshParts();
		}
	};

	useEffect( () => {
		const onPop = () => {
			const { tab: t } = readUrl( siteMode );
			setTab( t );
			setNavKey( ( k ) => k + 1 );
		};
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [ siteMode ] );

	const selectTab = useCallback( ( name ) => {
		setTab( ( current ) => {
			if ( current !== name ) {
				writeUrl( name );
			}
			return name;
		} );
	}, [] );

	const goTab = useCallback(
		( name ) => {
			selectTab( name );
			setNavKey( ( k ) => k + 1 );
		},
		[ selectTab ]
	);

	const openDialog = useCallback(
		( spec ) => setDialog( { ...spec, busy: false, error: '' } ),
		[]
	);

	const handlers = useMemo(
		() => ( {
			onRun: () => audit.run(),
			onFix: ( check ) => {
				const fixer = AUTO_FIXERS[ check.fixer ];
				if ( ! fixer ) {
					return;
				}
				openDialog( {
					action: FIXER_ACTION[ fixer ],
					on: true,
					extra: {},
					rerun: true,
					checkId: check.id,
				} );
			},
			onTurnOff: ( fixer, check ) =>
				openDialog( {
					action: FIXER_ACTION[ fixer ],
					on: false,
					extra: {},
					rerun: false,
					checkId: check?.id || null,
				} ),
			onGoSettings: ( pageId ) => {
				if ( pageId ) {
					setPendingPageId( pageId );
				}
				if ( ! siteMode ) {
					goTab( 'settings' );
				}
			},
			onOpenCheck: ( id ) => {
				if ( tab !== 'overview' ) {
					goTab( 'overview' );
				}
				setOpenIds( ( ids ) =>
					ids.includes( id ) ? ids : [ ...ids, id ]
				);
				setFocusId( id );
			},
		} ),
		[ audit, openDialog, goTab, tab, siteMode ]
	);

	const dialogDef =
		dialog &&
		settings.values &&
		getDialog( dialog.action, dialog.on, {
			values: settings.values,
			fixerStatus: settings.fixerStatus,
			boot,
			...dialog.extra,
		} );

	const confirm = async () => {
		if ( ! dialog || dialog.busy || ! dialogDef ) {
			return;
		}
		const card = ACTION_CARD[ dialog.action ];
		setDialog( ( d ) => ( { ...d, busy: true, error: '' } ) );
		try {
			const next = await patchSettings( dialogDef.changes );
			settingsSeq.current++;
			setSettings( { status: 'ready', ...next } );
			setCardNotices( ( n ) => ( { ...n, [ card ]: null } ) );
			setDialog( null );
			if ( dialog.rerun ) {
				pendingFix.current = {
					name: dialogDef.name,
					checkId: dialog.checkId,
				};
				audit.run();
			} else {
				setStale( true );
				const msg = dialog.on
					? sprintf(
							/* translators: %s: name of the fix, e.g. "Product feed". */
							__( '%s turned on.', 'x7velo' ),
							dialogDef.name
						)
					: sprintf(
							/* translators: %s: name of the fix, e.g. "Product feed". */
							__( '%s turned off.', 'x7velo' ),
							dialogDef.name
						);
				setSnackbars( ( s ) => [
					...s,
					{ id: `${ Date.now() }`, content: msg },
				] );
			}
		} catch ( error ) {
			if ( error.isSessionExpired ) {
				setDialog( null );
				setSessionExpired( true );
				return;
			}
			if ( error.code === 'x7velo_fixer_unavailable' ) {
				const fresh = await loadSettings();
				const fixerKey = card === 'robots' ? 'robots' : card;
				const reason = unavailableReason(
					fresh?.fixerStatus?.[ fixerKey ] ?? null,
					boot
				);
				const msg = sprintf(
					/* translators: %s: reason, e.g. "Managed by Yoast SEO." */
					__( 'x7velo can’t turn this on right now: %s', 'x7velo' ),
					reason
				);
				if ( dialog.rerun ) {
					setDialog( ( d ) => ( { ...d, busy: false, error: msg } ) );
				} else {
					setDialog( null );
					setCardNotices( ( n ) => ( { ...n, [ card ]: msg } ) );
				}
				return;
			}
			const reason =
				error.message ||
				__( 'The page lost contact with your site.', 'x7velo' );
			setDialog( ( d ) => ( {
				...d,
				busy: false,
				error: d.on
					? sprintf(
							/* translators: 1: name of the fix, 2: reason. */
							__(
								'x7velo couldn’t turn on %1$s. Nothing was changed. Reason: %2$s',
								'x7velo'
							),
							dialogDef.name,
							reason
						)
					: sprintf(
							/* translators: 1: name of the fix, 2: reason. */
							__(
								'x7velo couldn’t turn off %1$s. Nothing was changed. Reason: %2$s',
								'x7velo'
							),
							dialogDef.name,
							reason
						),
			} ) );
		}
	};

	const record = audit.record;
	const store02 = record?.checks.find( ( c ) => c.id === 'STORE-02' );
	const suggestedPageId =
		store02 && store02.status !== 'pass'
			? Number( store02.details?.suggested_page_id ) || 0
			: 0;

	const tabs = [
		{ name: 'overview', title: __( 'Overview', 'x7velo' ) },
		{ name: 'fixes', title: _x( 'Fixes', 'tab name (noun)', 'x7velo' ) },
		{ name: 'settings', title: __( 'Settings', 'x7velo' ) },
	].filter( ( t ) => tabNames( siteMode ).includes( t.name ) );

	return (
		<div className="x7velo-app">
			{ sessionExpired && (
				<Notice
					status="warning"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Reload page', 'x7velo' ),
							variant: 'secondary',
							onClick: () => window.location.reload(),
						},
					] }
				>
					<span role="alert">
						{ __(
							'Your login session expired. Reload the page and try again.',
							'x7velo'
						) }
					</span>
				</Notice>
			) }
			{ audit.error && ! audit.error.isSessionExpired && (
				<Notice
					status="error"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Try again', 'x7velo' ),
							variant: 'secondary',
							onClick: () => audit.run(),
						},
					] }
				>
					{ __(
						'The audit didn’t finish because the page lost contact with your site. Nothing was changed.',
						'x7velo'
					) }
				</Notice>
			) }
			<ConflictNotices
				siteMode={ siteMode }
				notices={ boot.notices }
				onTurnOff={ ( fixer ) => handlers.onTurnOff( fixer, null ) }
			/>
			<TabPanel
				key={ navKey }
				className="x7velo-tabs"
				tabs={ tabs }
				initialTabName={ tab }
				onSelect={ selectTab }
			>
				{ ( t ) => (
					<div className="x7velo-tab">
						{ t.name === 'overview' && (
							<Overview
								audit={ audit }
								settings={
									settings.status === 'ready'
										? settings
										: null
								}
								boot={ boot }
								copy={ copy }
								stale={ stale }
								handlers={ handlers }
								openIds={ openIds }
								focusId={ focusId }
								onToggle={ ( id ) =>
									setOpenIds( ( ids ) =>
										ids.includes( id )
											? ids.filter( ( x ) => x !== id )
											: [ ...ids, id ]
									)
								}
								onFocused={ () => setFocusId( null ) }
							/>
						) }
						{ t.name === 'fixes' && (
							<FixesTab
								settings={ settings }
								boot={ boot }
								cardNotices={ cardNotices }
								goSettings={ () => goTab( 'settings' ) }
								onRetry={ loadSettings }
								onChange={ ( action, on, extra = {} ) =>
									openDialog( {
										action,
										on,
										extra,
										rerun: false,
										checkId: null,
									} )
								}
							/>
						) }
						{ t.name === 'settings' && ! siteMode && (
							<SettingsTab
								settings={ settings }
								boot={ boot }
								suggestedPageId={ suggestedPageId }
								pendingPageId={ pendingPageId }
								onConsumePending={ () => setPendingPageId( 0 ) }
								onSaved={ onSettingsSaved }
								onRun={ () => {
									goTab( 'overview' );
									audit.run();
								} }
								onSessionExpired={ () =>
									setSessionExpired( true )
								}
							/>
						) }
					</div>
				) }
			</TabPanel>
			{ dialog && dialogDef && (
				<ConfirmModal
					dialog={ dialogDef }
					busy={ dialog.busy }
					error={ dialog.error }
					onConfirm={ confirm }
					onCancel={ () => setDialog( null ) }
				/>
			) }
			<SnackbarList
				className="x7velo-snackbars"
				notices={ snackbars }
				onRemove={ ( id ) =>
					setSnackbars( ( s ) => s.filter( ( n ) => n.id !== id ) )
				}
			/>
		</div>
	);
}
