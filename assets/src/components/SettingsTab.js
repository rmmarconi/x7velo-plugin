/**
 * Settings tab (design §3.5): returns page, return window, delivery times, own-brand products.
 * Classic form semantics: nothing is saved until "Save settings".
 *
 * Numbers: an empty field means "not set" and is sent as null; 0 is a valid same-day delivery
 * value (lead engineer decision, 2026-11-22).
 */
import {
	Button,
	CheckboxControl,
	ComboboxControl,
	Notice,
	RadioControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import {
	createInterpolateElement,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getPage, patchSettings, searchPages } from '../api';

const toField = ( v ) => ( v === null || v === undefined ? '' : String( v ) );

export function draftFromValues( values ) {
	return {
		returns_page_id: values.returns_page_id || 0,
		own_brand_products: !! values.own_brand_products,
		return_policy: values.return_policy || '',
		return_days: toField( values.return_days ),
		delivery_days_min: toField( values.delivery_days_min ),
		delivery_days_max: toField( values.delivery_days_max ),
	};
}

const isInt = ( s ) => /^\d+$/.test( String( s ).trim() );

/**
 * Client-side validation, same rules as PATCH /settings (design §3.5 table).
 *
 * @param {Object} draft Form draft.
 * @return {Object<string, string>} Errors by field key.
 */
export function validate( draft ) {
	const errors = {};
	if ( draft.return_policy === 'finite' ) {
		const d = draft.return_days.trim();
		if ( ! isInt( d ) || Number( d ) < 1 || Number( d ) > 365 ) {
			errors.return_days = __(
				'Enter a number of days from 1 to 365.',
				'x7velo'
			);
		}
	}
	const min = draft.delivery_days_min.trim();
	const max = draft.delivery_days_max.trim();
	const range = __( 'Enter a number from 0 to 60.', 'x7velo' );
	const inRange = ( s ) =>
		isInt( s ) && Number( s ) >= 0 && Number( s ) <= 60;
	if ( min !== '' && ! inRange( min ) ) {
		errors.delivery_days_min = range;
	}
	if ( max !== '' && ! inRange( max ) ) {
		errors.delivery_days_max = range;
	}
	if ( ! errors.delivery_days_min && ! errors.delivery_days_max ) {
		const pair = __(
			'Fill in both numbers, or leave both empty.',
			'x7velo'
		);
		if ( min === '' && max !== '' ) {
			errors.delivery_days_min = pair;
		} else if ( max === '' && min !== '' ) {
			errors.delivery_days_max = pair;
		} else if ( min !== '' && Number( min ) > Number( max ) ) {
			errors.delivery_days_max = __(
				'The first number can’t be bigger than the second.',
				'x7velo'
			);
		}
	}
	return errors;
}

/**
 * PATCH body for a draft: only the selected return option's data; empty → null.
 *
 * @param {Object} draft Form draft.
 * @return {Object} Changes.
 */
export function changesFromDraft( draft ) {
	const num = ( s ) => ( String( s ).trim() === '' ? null : Number( s ) );
	return {
		returns_page_id: draft.returns_page_id || 0,
		own_brand_products: draft.own_brand_products,
		return_policy: draft.return_policy,
		return_days:
			draft.return_policy === 'finite' ? num( draft.return_days ) : null,
		delivery_days_min: num( draft.delivery_days_min ),
		delivery_days_max: num( draft.delivery_days_max ),
	};
}

const FIELD_ORDER = [
	'returns_page_id',
	'return_policy',
	'return_days',
	'delivery_days_min',
	'delivery_days_max',
	'own_brand_products',
];

function ReturnsPagePicker( {
	value,
	onChange,
	error,
	suggestedId,
	newPageUrl,
} ) {
	const [ options, setOptions ] = useState( [] );
	const [ current, setCurrent ] = useState( null );
	const [ suggested, setSuggested ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ hasAny, setHasAny ] = useState( true );
	const timer = useRef( null );

	useEffect( () => {
		let live = true;
		searchPages( '' )
			.then( ( pages ) => {
				if ( live ) {
					setOptions( pages );
					setHasAny( pages.length > 0 );
				}
			} )
			.catch( () => {} )
			.finally( () => live && setLoading( false ) );
		return () => {
			live = false;
			clearTimeout( timer.current );
		};
	}, [] );

	useEffect( () => {
		let live = true;
		if ( value && ! options.some( ( p ) => p.id === value ) ) {
			getPage( value ).then( ( p ) => live && setCurrent( p ) );
		}
		return () => {
			live = false;
		};
	}, [ value, options ] );

	useEffect( () => {
		let live = true;
		if ( suggestedId ) {
			getPage( suggestedId ).then( ( p ) => live && setSuggested( p ) );
		}
		return () => {
			live = false;
		};
	}, [ suggestedId ] );

	const all = useMemo( () => {
		const map = new Map();
		[ current, suggested, ...options ]
			.filter( Boolean )
			.forEach( ( p ) => map.set( p.id, p ) );
		return [ ...map.values() ];
	}, [ current, suggested, options ] );

	const selected = all.find( ( p ) => p.id === value ) || null;

	const onFilter = ( search ) => {
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			searchPages( search )
				.then( setOptions )
				.catch( () => {} );
		}, 300 );
	};

	return (
		<>
			{ suggested && suggested.id !== value && (
				<Notice
					status="info"
					isDismissible={ false }
					className="x7velo-suggested"
				>
					<p>
						{ sprintf(
							/* translators: %s: page title. */
							__(
								'We found a page that looks like your returns policy: “%s”.',
								'x7velo'
							),
							suggested.title
						) }
					</p>
					<Button
						variant="secondary"
						onClick={ () => onChange( suggested.id ) }
					>
						{ __( 'Use this page', 'x7velo' ) }
					</Button>
				</Notice>
			) }
			{ ! loading && ! hasAny && ! value ? (
				<p>
					{ __(
						'You don’t have any published pages yet.',
						'x7velo'
					) }
				</p>
			) : (
				<ComboboxControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Returns policy page', 'x7velo' ) }
					value={ value ? String( value ) : null }
					options={ all.map( ( p ) => ( {
						value: String( p.id ),
						label: p.title || `#${ p.id }`,
					} ) ) }
					onChange={ ( v ) => onChange( v ? Number( v ) : 0 ) }
					onFilterValueChange={ onFilter }
					help={
						error ||
						__( 'Only published pages are listed.', 'x7velo' )
					}
				/>
			) }
			<p className="x7velo-links">
				{ selected?.link && (
					<a href={ selected.link }>
						{ __( 'View page', 'x7velo' ) }
					</a>
				) }
				<a href={ newPageUrl }>
					{ __( 'Create a returns page', 'x7velo' ) }
				</a>
			</p>
		</>
	);
}

export default function SettingsTab( {
	settings,
	boot,
	suggestedPageId,
	pendingPageId,
	onConsumePending,
	onSaved,
	onRun,
	onSessionExpired,
} ) {
	const values = settings.values;
	const [ draft, setDraft ] = useState( () =>
		values ? draftFromValues( values ) : null
	);
	const [ errors, setErrors ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ pageError, setPageError ] = useState( '' );
	const refs = useRef( {} );
	const errorRef = useRef( null );

	useEffect( () => {
		if ( values && ! draft ) {
			setDraft( draftFromValues( values ) );
		}
	}, [ values, draft ] );

	// "Use this page" from the Overview: pre-select (not saved).
	useEffect( () => {
		if ( pendingPageId && draft ) {
			setDraft( ( d ) => ( { ...d, returns_page_id: pendingPageId } ) );
			onConsumePending?.();
		}
	}, [ pendingPageId, draft, onConsumePending ] );

	const dirty = useMemo( () => {
		if ( ! values || ! draft ) {
			return false;
		}
		return (
			JSON.stringify( changesFromDraft( draft ) ) !==
			JSON.stringify( changesFromDraft( draftFromValues( values ) ) )
		);
	}, [ draft, values ] );

	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}
		const handler = ( e ) => {
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener( 'beforeunload', handler );
		return () => window.removeEventListener( 'beforeunload', handler );
	}, [ dirty ] );

	if (
		settings.status === 'loading' ||
		( settings.status === 'ready' && ! draft )
	) {
		return (
			<p>
				<Spinner />
			</p>
		);
	}
	if ( settings.status !== 'ready' ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ settings.status === 'missing'
					? __(
							'Settings aren’t available in this version of x7velo yet.',
							'x7velo'
						)
					: __(
							'Your settings couldn’t be loaded because the page lost contact with your site. Nothing was changed.',
							'x7velo'
						) }
			</Notice>
		);
	}

	const update = ( patch ) => {
		setSaved( false );
		setDraft( ( d ) => ( { ...d, ...patch } ) );
	};

	const focusFirstError = ( errs ) => {
		const first = FIELD_ORDER.find(
			( k ) => errs[ k ] && refs.current[ k ]
		);
		if ( first ) {
			refs.current[ first ].focus();
		} else {
			errorRef.current?.focus();
		}
	};

	const save = async ( event ) => {
		event.preventDefault();
		if ( saving ) {
			return;
		}
		setPageError( '' );
		setSaved( false );
		const errs = validate( draft );
		setErrors( errs );
		if ( Object.keys( errs ).length ) {
			setTimeout( () => focusFirstError( errs ) );
			return;
		}
		setSaving( true );
		try {
			const next = await patchSettings( changesFromDraft( draft ) );
			setDraft( draftFromValues( next.values ) );
			setSaved( true );
			speak(
				__(
					'Settings saved. Run the audit again to update your score.',
					'x7velo'
				)
			);
			onSaved( next );
		} catch ( error ) {
			if ( error.isSessionExpired ) {
				onSessionExpired();
			} else if ( error.status === 400 ) {
				const fieldErrs = {};
				Object.entries( error.fieldErrors ).forEach( ( [ k, msg ] ) => {
					if ( FIELD_ORDER.includes( k ) ) {
						fieldErrs[ k ] =
							msg || __( 'Check this value.', 'x7velo' );
					}
				} );
				setErrors( fieldErrs );
				setPageError(
					__(
						'Settings weren’t saved. Check the highlighted fields and try again.',
						'x7velo'
					)
				);
				setTimeout( () => focusFirstError( fieldErrs ) );
			} else {
				setPageError(
					__(
						'Settings weren’t saved because the page lost contact with your site. Try again.',
						'x7velo'
					)
				);
				setTimeout( () => errorRef.current?.focus() );
			}
		} finally {
			setSaving( false );
		}
	};

	const fixerOffNote = ! values.fix_jsonld && (
		<p className="x7velo-muted">
			{ __(
				'x7velo will add this to your product details when “Product details for AI assistants” is on.',
				'x7velo'
			) }
		</p>
	);
	const country = boot.baseCountry;
	const DELIVERY_ERRORS_ID = 'x7velo-delivery-errors';
	const numberProps = ( key, min, max, errorsBelow = false ) => ( {
		type: 'number',
		min,
		max,
		inputMode: 'numeric',
		value: draft[ key ],
		onChange: ( v ) => update( { [ key ]: v } ),
		ref: ( el ) => ( refs.current[ key ] = el ),
		'aria-invalid': errors[ key ] ? true : undefined,
		// Delivery fields share one error area under the row (their inputs are narrow).
		help: errorsBelow ? undefined : errors[ key ] || undefined,
		'aria-describedby':
			errorsBelow && errors[ key ] ? DELIVERY_ERRORS_ID : undefined,
		className: errors[ key ] ? 'x7velo-field--invalid' : undefined,
		__nextHasNoMarginBottom: true,
		__next40pxDefaultSize: true,
	} );

	return (
		<form className="x7velo-settings" onSubmit={ save } noValidate>
			<div ref={ errorRef } tabIndex={ -1 }>
				{ pageError && (
					<Notice status="error" isDismissible={ false }>
						{ pageError }
					</Notice>
				) }
				{ saved && (
					<Notice
						status="success"
						isDismissible={ false }
						actions={ [
							{
								label: __( 'Run audit again', 'x7velo' ),
								onClick: onRun,
							},
						] }
					>
						{ __(
							'Settings saved. Run the audit again to update your score.',
							'x7velo'
						) }
					</Notice>
				) }
			</div>

			<section
				className="x7velo-card"
				aria-labelledby="x7velo-set-returns"
			>
				<h2 id="x7velo-set-returns">
					{ __( 'Returns policy', 'x7velo' ) }
				</h2>
				<p>
					{ __(
						'Shoppers and AI assistants look for a returns policy before recommending a store. Which page explains your returns?',
						'x7velo'
					) }
				</p>
				<ReturnsPagePicker
					value={ draft.returns_page_id }
					onChange={ ( id ) => update( { returns_page_id: id } ) }
					error={ errors.returns_page_id }
					suggestedId={ suggestedPageId }
					newPageUrl={ boot.urls.newPage }
				/>
				{ fixerOffNote }
			</section>

			<section
				className="x7velo-card"
				aria-labelledby="x7velo-set-window"
			>
				<h2 id="x7velo-set-window">
					{ __( 'Return window', 'x7velo' ) }
				</h2>
				<p>
					{ __(
						'x7velo adds this to your product details so AI assistants can answer “can I return it?”.',
						'x7velo'
					) }
				</p>
				<RadioControl
					label={ __(
						'How long do customers have to return a product?',
						'x7velo'
					) }
					selected={ draft.return_policy }
					options={ [
						{
							label: __(
								'Not set (don’t add returns details)',
								'x7velo'
							),
							value: '',
						},
						{
							label: __(
								'Within a set number of days',
								'x7velo'
							),
							value: 'finite',
						},
						{
							label: __( 'Any time, no limit', 'x7velo' ),
							value: 'unlimited',
						},
						{
							label: __( 'We don’t accept returns', 'x7velo' ),
							value: 'none',
						},
					] }
					onChange={ ( v ) => update( { return_policy: v } ) }
					help={
						! draft.returns_page_id
							? __(
									'x7velo adds returns details only when a returns policy page is also chosen above.',
									'x7velo'
								)
							: undefined
					}
				/>
				{ draft.return_policy === 'finite' && (
					<div className="x7velo-indent x7velo-number">
						<TextControl
							label={ __( 'Number of days', 'x7velo' ) }
							{ ...numberProps( 'return_days', 1, 365 ) }
						/>
						<span className="x7velo-muted" aria-hidden="true">
							{ __( '(1–365)', 'x7velo' ) }
						</span>
					</div>
				) }
				{ fixerOffNote }
			</section>

			<section
				className="x7velo-card"
				aria-labelledby="x7velo-set-delivery"
			>
				<h2 id="x7velo-set-delivery">
					{ __( 'Delivery times', 'x7velo' ) }
				</h2>
				<fieldset className="x7velo-delivery">
					<legend>
						{ country
							? sprintf(
									/* translators: %s: country name, e.g. Brazil. */
									__(
										'How many days does delivery usually take in %s?',
										'x7velo'
									),
									country
								)
							: __(
									'How many days does delivery usually take in your country?',
									'x7velo'
								) }
					</legend>
					<p>
						{ __(
							'Count from the order to the day it arrives. Leave both empty to not add delivery details.',
							'x7velo'
						) }
					</p>
					<div className="x7velo-delivery__row">
						{ createInterpolateElement(
							/* translators: Delivery time row, e.g. "From [2] to [5] days (0–60)". Keep the tags; <min /> and <max /> are the two number fields, text inside <from>, <to> and <unit> is shown next to them. */
							__(
								'<from>From</from> <min /> <to>to</to> <max /> <unit>days (0–60)</unit>',
								'x7velo'
							),
							{
								from: <span aria-hidden="true" />,
								to: <span aria-hidden="true" />,
								unit: <span aria-hidden="true" />,
								min: (
									<TextControl
										label={ __(
											'Fastest delivery, in days',
											'x7velo'
										) }
										hideLabelFromVision
										{ ...numberProps(
											'delivery_days_min',
											0,
											60,
											true
										) }
									/>
								),
								max: (
									<TextControl
										label={ __(
											'Slowest delivery, in days',
											'x7velo'
										) }
										hideLabelFromVision
										{ ...numberProps(
											'delivery_days_max',
											0,
											60,
											true
										) }
									/>
								),
							}
						) }
					</div>
					<div
						id={ DELIVERY_ERRORS_ID }
						className="x7velo-field-errors"
					>
						{ [
							...new Set(
								[
									errors.delivery_days_min,
									errors.delivery_days_max,
								].filter( Boolean )
							),
						].map( ( msg ) => (
							<p key={ msg }>{ msg }</p>
						) ) }
					</div>
					<p className="x7velo-infoline">
						{ country
							? sprintf(
									/* translators: %s: country name, e.g. Brazil. */
									__(
										'Added only for products whose shipping to %s is free or a fixed flat rate. Other products are left as they are.',
										'x7velo'
									),
									country
								)
							: __(
									'Added only for products whose shipping to your country is free or a fixed flat rate. Other products are left as they are.',
									'x7velo'
								) }
					</p>
				</fieldset>
				{ fixerOffNote }
			</section>

			<section
				className="x7velo-card"
				aria-labelledby="x7velo-set-products"
			>
				<h2 id="x7velo-set-products">
					{ __( 'Your products', 'x7velo' ) }
				</h2>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'I make my own products', 'x7velo' ) }
					help={ __(
						'Choose this if you sell handmade or own-brand products that don’t have barcodes (GTIN, EAN, UPC). We’ll stop asking for barcodes. We’ll still check that each product has a SKU.',
						'x7velo'
					) }
					checked={ draft.own_brand_products }
					onChange={ ( v ) => update( { own_brand_products: v } ) }
				/>
			</section>

			<p>
				<Button
					variant="primary"
					type="submit"
					isBusy={ saving }
					aria-disabled={ saving }
					__next40pxDefaultSize
				>
					{ __( 'Save settings', 'x7velo' ) }
				</Button>
			</p>
		</form>
	);
}
