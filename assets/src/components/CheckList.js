/**
 * "All checks": groups by category, each check an expandable row with its detail (design §3.3).
 */
import { Button, Notice, VisuallyHidden } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, chevronDown, chevronRight } from '@wordpress/icons';

import { getPage, isManagedBy } from '../api';
import { isSiteMode } from '../bootstrap';
import {
	CATEGORY_ORDER,
	getCategoryLabels,
	isWooInactive,
	sourceName,
} from '../constants';
import { copyFor } from '../copy/checks';
import { reasonKind } from '../copy/fixers';
import {
	actionsFor,
	categorySummary,
	groupChecks,
	isSearchVisibilityBlock,
	turnOffFixerFor,
} from '../score';
import ActionButtons, { contextLabel } from './ActionButtons';
import CopyBlock from './CopyBlock';
import Disclosure from './Disclosure';
import StatusLabel from './StatusLabel';

export function summaryText( s ) {
	const parts = [];
	if ( s.toFix ) {
		parts.push(
			sprintf(
				/* translators: %d: number of checks. */
				_n( '%d to fix', '%d to fix', s.toFix, 'x7velo' ),
				s.toFix
			)
		);
	}
	if ( s.toImprove ) {
		parts.push(
			sprintf(
				/* translators: %d: number of checks. */
				_n( '%d to improve', '%d to improve', s.toImprove, 'x7velo' ),
				s.toImprove
			)
		);
	}
	if ( s.na ) {
		parts.push(
			sprintf(
				/* translators: %d: number of checks. */
				_n( '%d doesn’t apply', '%d don’t apply', s.na, 'x7velo' ),
				s.na
			)
		);
	}
	if ( s.errors ) {
		parts.push(
			sprintf(
				/* translators: %d: number of checks. */
				_n( '%d couldn’t run', '%d couldn’t run', s.errors, 'x7velo' ),
				s.errors
			)
		);
	}
	return parts.length ? parts.join( ' · ' ) : __( 'All good', 'x7velo' );
}

export default function CheckList( {
	record,
	fixerStatus,
	boot,
	copy,
	openIds,
	focusId,
	onToggle,
	onFocused,
	handlers,
	settingsValues,
} ) {
	const labels = getCategoryLabels();
	// Store checks that don't apply without WooCommerce are listed once, collapsed, at the
	// bottom, not inside their categories (spec §15.6).
	const storeOnly = record.checks.filter( isWooInactive );
	const groups = groupChecks(
		record.checks.filter( ( c ) => ! isWooInactive( c ) ),
		CATEGORY_ORDER
	);
	return (
		<section
			className="x7velo-checks"
			aria-labelledby="x7velo-checks-heading"
		>
			<h2 id="x7velo-checks-heading">{ __( 'All checks', 'x7velo' ) }</h2>
			{ record.state === 'incomplete' && (
				<p>
					{ __(
						'Checks that did run are listed below. They’re not scored.',
						'x7velo'
					) }
				</p>
			) }
			{ groups.map( ( group ) => (
				<div
					key={ group.category }
					className="x7velo-card x7velo-checks__group"
					id={ `x7velo-cat-${ group.category }` }
					tabIndex={ -1 }
				>
					<div className="x7velo-checks__group-head">
						<h3>{ labels[ group.category ] || group.category }</h3>
						<span className="x7velo-muted">
							{ summaryText( categorySummary( group.checks ) ) }
						</span>
					</div>
					<ul className="x7velo-checks__list">
						{ group.checks.map( ( check ) => (
							<CheckRow
								key={ check.id }
								check={ check }
								copy={ copyFor( copy, check.id ) }
								open={ openIds.includes( check.id ) }
								focus={ focusId === check.id }
								onToggle={ () => onToggle( check.id ) }
								onFocused={ onFocused }
								fixerStatus={ fixerStatus }
								boot={ boot }
								handlers={ handlers }
								settingsValues={ settingsValues }
								state={ record.state }
							/>
						) ) }
					</ul>
				</div>
			) ) }
			{ storeOnly.length > 0 && (
				<div className="x7velo-card x7velo-checks__group x7velo-checks__store">
					<Disclosure
						label={ sprintf(
							/* translators: %d: number of store checks. */
							__(
								'Store checks (%d) — for WooCommerce stores',
								'x7velo'
							),
							storeOnly.length
						) }
					>
						<ul className="x7velo-checks__list">
							{ storeOnly.map( ( check ) => (
								<li
									key={ check.id }
									className="x7velo-check"
									id={ `x7velo-check-${ check.id }` }
								>
									<h4 className="x7velo-check__heading">
										<span className="x7velo-check__toggle">
											<StatusLabel check={ check } />
											<span className="x7velo-check__title">
												{
													copyFor( copy, check.id )
														.title
												}
											</span>
										</span>
									</h4>
									{ check.message && (
										<p className="x7velo-check__summary">
											{ check.message }
										</p>
									) }
								</li>
							) ) }
						</ul>
					</Disclosure>
				</div>
			) }
		</section>
	);
}

function CheckRow( {
	check,
	copy,
	open,
	focus,
	onToggle,
	onFocused,
	fixerStatus,
	boot,
	handlers,
	settingsValues,
	state,
} ) {
	const buttonRef = useRef( null );
	const regionId = `x7velo-check-detail-${ check.id }`;

	useEffect( () => {
		if ( focus && buttonRef.current ) {
			buttonRef.current.scrollIntoView?.( { block: 'start' } );
			buttonRef.current.focus();
			onFocused?.();
		}
	}, [ focus, onFocused ] );

	return (
		<li className="x7velo-check" id={ `x7velo-check-${ check.id }` }>
			<h4 className="x7velo-check__heading">
				<button
					type="button"
					ref={ buttonRef }
					className="x7velo-check__toggle"
					aria-expanded={ open }
					aria-controls={ regionId }
					onClick={ onToggle }
				>
					<Icon
						icon={ open ? chevronDown : chevronRight }
						size={ 20 }
					/>
					<StatusLabel check={ check } />
					<span className="x7velo-check__title">{ copy.title }</span>
				</button>
			</h4>
			{ ! open && check.message && (
				<p className="x7velo-check__summary">{ check.message }</p>
			) }
			<div
				id={ regionId }
				hidden={ ! open }
				className="x7velo-check__detail"
			>
				{ open && (
					<CheckDetail
						check={ check }
						copy={ copy }
						fixerStatus={ fixerStatus }
						boot={ boot }
						handlers={ handlers }
						settingsValues={ settingsValues }
						scored={ state === 'complete' }
					/>
				) }
			</div>
		</li>
	);
}

function Section( { title, children } ) {
	return (
		<div className="x7velo-detail__section">
			<h5>{ title }</h5>
			{ children }
		</div>
	);
}

function Steps( { steps } ) {
	if ( ! steps?.length ) {
		return null;
	}
	return (
		<ol>
			{ steps.map( ( s, i ) => (
				<li key={ i }>{ s }</li>
			) ) }
		</ol>
	);
}

function SuggestedPage( { pageId, handlers } ) {
	const [ page, setPage ] = useState( null );
	useEffect( () => {
		let live = true;
		getPage( pageId ).then( ( p ) => live && setPage( p ) );
		return () => {
			live = false;
		};
	}, [ pageId ] );
	if ( ! page ) {
		return null;
	}
	return (
		<Notice status="info" isDismissible={ false }>
			<p>
				{ sprintf(
					/* translators: %s: page title. */
					__(
						'We found a page that may be your returns policy: “%s”.',
						'x7velo'
					),
					page.title
				) }
			</p>
			<Button
				variant="secondary"
				onClick={ () => handlers.onGoSettings( page.id ) }
			>
				{ __( 'Use this page', 'x7velo' ) }
			</Button>
		</Notice>
	);
}

/**
 * Subfolder copy: domain and folder come from the current site address (page data), never the
 * stored record.
 *
 * @param {Object} boot Bootstrap data.
 * @return {{found: string, how: string}} Copy.
 */
function subfolderText( boot ) {
	const host = boot.siteHost || '';
	const folder = host + ( boot.homePath || '' ).replace( /\/$/, '' );
	if ( isSiteMode( boot ) ) {
		return {
			found: sprintf(
				/* translators: 1: site address with folder, e.g. example.com/blog, 2: main site, e.g. example.com. */
				__(
					'Your site is in a folder of a bigger site (%1$s), so its robots.txt belongs to the main site at %2$s. x7velo can’t check or change it from here.',
					'x7velo'
				),
				folder,
				host
			),
			how: sprintf(
				/* translators: %s: main site address, e.g. example.com. */
				__(
					'If AI search assistants should read your site, ask whoever manages %s to check its robots.txt.',
					'x7velo'
				),
				host
			),
		};
	}
	return {
		found: sprintf(
			/* translators: 1: store address with folder, e.g. example.com/shop, 2: main site, e.g. example.com. */
			__(
				'Your store is in a folder of a bigger site (%1$s), so its robots.txt belongs to the main site at %2$s. x7velo can’t check or change it from here.',
				'x7velo'
			),
			folder,
			host
		),
		how: sprintf(
			/* translators: %s: main site address, e.g. example.com. */
			__(
				'If AI shopping assistants should read your store, ask whoever manages %s to check its robots.txt.',
				'x7velo'
			),
			host
		),
	};
}

export function CheckDetail( {
	check,
	copy,
	fixerStatus,
	boot,
	handlers,
	settingsValues,
	scored,
} ) {
	const technical = sprintf(
		/* translators: %s: technical name, e.g. "DATA-01 · price". */
		__( 'Technical name: %s', 'x7velo' ),
		`${ check.id } · ${ copy.technical }`
	);
	const footer = <p className="x7velo-detail__technical">{ technical }</p>;
	const isSubfolder =
		check.id === 'CRAWL-01' && String( check.code ).includes( 'subfolder' );

	if ( check.status === 'error' ) {
		const memory = check.code === 'scan_memory_limit';
		return (
			<div className="x7velo-detail">
				<Section title={ __( 'What we found', 'x7velo' ) }>
					<p>
						{ memory
							? __(
									'Couldn’t check — stopped to protect your site’s memory.',
									'x7velo'
								)
							: __( 'We couldn’t run this check.', 'x7velo' ) }
					</p>
					<p>
						{ __(
							'It’s left out of your score. Run the audit again. If it keeps happening, copy the details for support.',
							'x7velo'
						) }
					</p>
				</Section>
				<Button variant="secondary" onClick={ handlers.onRun }>
					{ __( 'Run audit again', 'x7velo' ) }
				</Button>
				{ footer }
			</div>
		);
	}

	if ( check.status === 'not_applicable' ) {
		const sub = isSubfolder ? subfolderText( boot ) : null;
		return (
			<div className="x7velo-detail">
				<Section title={ __( 'What we found', 'x7velo' ) }>
					<p>{ sub ? sub.found : check.message }</p>
				</Section>
				{ sub && (
					<Section title={ __( 'How to fix', 'x7velo' ) }>
						<p>{ sub.how }</p>
					</Section>
				) }
				<p>
					{ __( 'This check is left out of your score.', 'x7velo' ) }
				</p>
				{ footer }
			</div>
		);
	}

	const turnOff = turnOffFixerFor( check, fixerStatus );
	const extras = (
		<>
			{ check.id === 'SCHEMA-01' && check.details?.brand_hint && (
				<Notice status="info" isDismissible={ false }>
					<p>
						{ __(
							'Tip: your products have brands, but Rank Math doesn’t include them yet. In Rank Math → General Settings → WooCommerce, set “Brand” to “Product brands”.',
							'x7velo'
						) }
					</p>
					{ check.guidedUrl && (
						<Button variant="secondary" href={ check.guidedUrl }>
							{ __( 'Show me where', 'x7velo' ) }
						</Button>
					) }
				</Notice>
			) }
			{ check.id === 'SCHEMA-01' &&
				check.code === 'managed_by_unverified_plugin' && (
					<p>
						{ sprintf(
							/* translators: %s: plugin name. */
							__(
								'Another plugin (%s) seems to add product details. x7velo can’t confirm it, so it stays off.',
								'x7velo'
							),
							check.sourceLabel || sourceName( check.source )
						) }
					</p>
				) }
			{ check.id === 'DATA-04' && settingsValues?.own_brand_products && (
				<p>
					{ __(
						'Barcodes not checked because you make your own products. Change in Settings.',
						'x7velo'
					) }
				</p>
			) }
			{ check.id === 'STORE-02' &&
				check.details?.suggested_page_id > 0 &&
				check.status !== 'pass' && (
					<SuggestedPage
						pageId={ Number( check.details.suggested_page_id ) }
						handlers={ handlers }
					/>
				) }
		</>
	);

	if ( check.status === 'pass' ) {
		return (
			<div className="x7velo-detail">
				<Section title={ __( 'What we found', 'x7velo' ) }>
					<p>{ check.message }</p>
					{ extras }
				</Section>
				{ turnOff && (
					<Button
						variant="secondary"
						onClick={ ( e ) =>
							handlers.onTurnOff(
								turnOff,
								check,
								e.currentTarget
							)
						}
						aria-label={ contextLabel(
							__( 'Turn off', 'x7velo' ),
							copy.title
						) }
					>
						{ __( 'Turn off', 'x7velo' ) }
					</Button>
				) }
				{ ! turnOff && isManagedBy( check ) && (
					<p className="x7velo-muted">
						{ __(
							'x7velo stays off here so nothing is added twice.',
							'x7velo'
						) }
					</p>
				) }
				{ footer }
			</div>
		);
	}

	// fail / warn.
	const actions = actionsFor( check, fixerStatus );
	const robotsStatus = fixerStatus?.robots;
	const physicalRobots =
		check.id === 'CRAWL-01' &&
		robotsStatus?.state === 'cannot_run' &&
		reasonKind( robotsStatus.reason ) === 'physical_file';
	const why =
		check.id === 'SCHEMA-01' && check.status === 'warn'
			? copy.whyWarn
			: copy.why;

	let how;
	if ( isSearchVisibilityBlock( check ) ) {
		how = (
			<Section title={ __( 'How to fix', 'x7velo' ) }>
				<p>
					{ __(
						'This is the same setting as “Visible to search engines” above. Fix that one and this check passes too.',
						'x7velo'
					) }
				</p>
				{ check.guidedUrl && (
					<Button variant="secondary" href={ check.guidedUrl }>
						{ __( 'Show me where', 'x7velo' ) }
					</Button>
				) }
				<p className="x7velo-muted">
					{ __(
						'x7velo won’t use robots.txt rules to undo your Reading setting.',
						'x7velo'
					) }
				</p>
			</Section>
		);
	} else if ( physicalRobots ) {
		how = (
			<Section title={ __( 'How to fix', 'x7velo' ) }>
				<p>
					{ __(
						'Your site has its own robots.txt file, so x7velo can’t add rules to it. Ask your host or developer to add these lines to the robots.txt file in your site’s main folder:',
						'x7velo'
					) }
				</p>
				{ robotsStatus.lines.allow ? (
					<CopyBlock
						text={ [
							robotsStatus.lines.allow,
							robotsStatus.lines.sitemap,
						]
							.filter( Boolean )
							.join( '\n' ) }
						label={
							isSiteMode( boot )
								? __(
										'Lines that let AI search assistants in',
										'x7velo'
									)
								: __(
										'Lines that let AI shopping assistants in',
										'x7velo'
									)
						}
					/>
				) : (
					<Steps steps={ copy.steps } />
				) }
			</Section>
		);
	} else if ( actions.primary === 'fix' || actions.secondary === 'fix' ) {
		how = (
			<Section title={ __( 'What “Fix it” does', 'x7velo' ) }>
				<p>{ copy.fixDoes }</p>
				<div className="x7velo-actions">
					<ActionButtons
						actions={ actions }
						check={ check }
						title={ copy.title }
						handlers={ handlers }
						includeHow={ false }
					/>
				</div>
			</Section>
		);
	} else {
		how = (
			<Section title={ __( 'How to fix', 'x7velo' ) }>
				<Steps steps={ copy.steps } />
				<div className="x7velo-actions">
					<ActionButtons
						actions={ actions }
						check={ check }
						title={ copy.title }
						handlers={ handlers }
						includeHow={ false }
					/>
				</div>
				{ check.id === 'SCHEMA-01' && check.status === 'warn' && (
					<p className="x7velo-muted">
						{ __(
							'x7velo never turns off another plugin’s settings for you.',
							'x7velo'
						) }
					</p>
				) }
			</Section>
		);
	}

	return (
		<div className="x7velo-detail">
			<Section title={ __( 'What we found', 'x7velo' ) }>
				<p>{ check.message }</p>
				{ extras }
			</Section>
			{ why && (
				<Section title={ __( 'Why it matters', 'x7velo' ) }>
					<p>{ why }</p>
				</Section>
			) }
			{ how }
			<Examples check={ check } boot={ boot } />
			{ actions.primary === 'how' && scored && (
				<p className="x7velo-muted">
					{ __(
						'Made changes? Run the audit again to update your score.',
						'x7velo'
					) }
				</p>
			) }
			{ footer }
		</div>
	);
}

function Examples( { check, boot } ) {
	if ( ! check.examples.length ) {
		return null;
	}
	const total = check.missing ?? check.examples.length;
	return (
		<Section
			title={ sprintf(
				/* translators: 1: number of examples shown, 2: number of products with the problem. */
				__( 'Examples (%1$d of %2$d)', 'x7velo' ),
				check.examples.length,
				total
			) }
		>
			<ul className="x7velo-examples">
				{ check.examples.map( ( ex ) => (
					<li key={ ex.id }>
						<span>{ ex.title || `#${ ex.id }` }</span>
						{ ex.editUrl && (
							<a
								href={ ex.editUrl }
								className="x7velo-examples__edit"
							>
								{ __( 'Edit', 'x7velo' ) }
								<VisuallyHidden as="span">{ ` ${ ex.title || `#${ ex.id }` }` }</VisuallyHidden>
							</a>
						) }
					</li>
				) ) }
			</ul>
			{ total > check.examples.length && (
				<p>
					<a href={ boot.urls.products }>
						{ __( 'View products', 'x7velo' ) }
					</a>
				</p>
			) }
		</Section>
	);
}
