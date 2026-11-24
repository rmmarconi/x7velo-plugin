/**
 * Overview tab: notices, score card (or running / incomplete / no-products card), top fixes,
 * category breakdown and all checks.
 */
import { Button, Notice, Spinner, VisuallyHidden } from '@wordpress/components';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, lock } from '@wordpress/icons';

import {
	bandFor,
	CATEGORY_ORDER,
	getBands,
	getCategoryLabels,
	isWooInactive,
} from '../constants';
import { isSiteMode } from '../bootstrap';
import { copyFor } from '../copy/checks';
import { reasonKind } from '../copy/fixers';
import {
	categorySummary,
	CRITICAL_ORDER,
	isSearchVisibilityBlock,
	topFixes,
} from '../score';
import ActionButtons, { contextLabel } from './ActionButtons';
import CheckList, { summaryText } from './CheckList';
import { CopyButton } from './CopyBlock';
import Disclosure from './Disclosure';
import StatusLabel from './StatusLabel';

/**
 * Formats a timestamp with the site's date and time formats, timezone and WordPress locale
 * (`wp-date` settings), like the rest of wp-admin, instead of the browser's locale.
 *
 * @param {string} iso ISO 8601 timestamp.
 * @return {string} Formatted date, '' when missing, the input when unparsable.
 */
export function formatDate( iso ) {
	if ( ! iso ) {
		return '';
	}
	if ( Number.isNaN( new Date( iso ).getTime() ) ) {
		return iso;
	}
	const { formats } = getDateSettings();
	const format = [ formats?.date, formats?.time ]
		.filter( Boolean )
		.join( ' ' );
	return dateI18n( format || formats?.datetime || 'F j, Y g:i a', iso );
}

export function RunningCard( { siteMode = false } ) {
	return (
		<div className="x7velo-card x7velo-running" aria-busy="true">
			<p className="x7velo-running__title" role="status">
				<Spinner />
				<span>
					{ siteMode
						? __( 'Checking your site…', 'x7velo' )
						: __( 'Checking your store…', 'x7velo' ) }
				</span>
			</p>
			<p>
				{ siteMode
					? __(
							'We’re looking at your settings and pages to see how easily AI assistants like ChatGPT and Perplexity can read your site.',
							'x7velo'
						)
					: __(
							'We’re looking at your settings and product details to see how easily AI assistants like ChatGPT and Perplexity can read your store.',
							'x7velo'
						) }
			</p>
			<p className="x7velo-running__privacy">
				<Icon icon={ lock } size={ 20 } aria-hidden="true" />
				<span>
					{ siteMode
						? __(
								'This check runs on your own site. Nothing is sent anywhere, and nothing on your site is changed.',
								'x7velo'
							)
						: __(
								'This check runs on your own site. Nothing is sent anywhere, and nothing on your store is changed.',
								'x7velo'
							) }
				</span>
			</p>
			<p>{ __( 'This usually takes a few seconds.', 'x7velo' ) }</p>
		</div>
	);
}

function RunButton( { onRun, waiting, primary = false } ) {
	return (
		<Button
			variant={ primary ? 'primary' : 'secondary' }
			onClick={ () => ! waiting && onRun() }
			aria-disabled={ waiting }
			isBusy={ waiting }
			className="x7velo-run"
		>
			{ __( 'Run audit again', 'x7velo' ) }
		</Button>
	);
}

/**
 * First failing critical check in root-cause order (for the cap line).
 *
 * @param {Array} checks UI checks.
 * @return {Object|null} Check.
 */
function firstCritical( checks ) {
	for ( const id of CRITICAL_ORDER ) {
		const c = checks.find( ( x ) => x.id === id && x.critical );
		if ( c ) {
			return c;
		}
	}
	return checks.find( ( c ) => c.critical ) || null;
}

/**
 * "Based on …" line in site mode (spec §15.6): checks that apply + store checks that don't.
 * Counts come from the payload.
 *
 * @param {Array} checks UI checks.
 * @return {string} Line.
 */
export function siteBasisText( checks ) {
	const naWoo = checks.filter( isWooInactive ).length;
	const na = checks.filter( ( c ) => c.status === 'not_applicable' ).length;
	const errors = checks.filter( ( c ) => c.status === 'error' ).length;
	const applicable = checks.length - na - errors;
	const otherNa = na - naWoo;
	return [
		sprintf(
			/* translators: %d: number of checks used in the score. */
			_n(
				'Based on %d check that applies to your site.',
				'Based on %d checks that apply to your site.',
				applicable,
				'x7velo'
			),
			applicable
		),
		naWoo > 0 &&
			sprintf(
				/* translators: %d: number of store checks. */
				_n(
					'%d store check doesn’t apply because WooCommerce isn’t active on this site.',
					'%d store checks don’t apply because WooCommerce isn’t active on this site.',
					naWoo,
					'x7velo'
				),
				naWoo
			),
		otherNa > 0 &&
			sprintf(
				/* translators: %d: number of checks. */
				_n(
					'%d other check didn’t apply to your site.',
					'%d other checks didn’t apply to your site.',
					otherNa,
					'x7velo'
				),
				otherNa
			),
		errors > 0 &&
			sprintf(
				/* translators: %d: number of checks. */
				_n(
					'%d check couldn’t run.',
					'%d checks couldn’t run.',
					errors,
					'x7velo'
				),
				errors
			),
	]
		.filter( Boolean )
		.join( ' ' );
}

export function ScoreCard( {
	record,
	stale,
	onRun,
	waiting,
	siteMode = false,
} ) {
	const score = record.score;
	const bandId = [ 'low', 'medium', 'high' ].includes( record.band )
		? record.band
		: bandFor( score );
	const band = getBands( siteMode )[ bandId ];
	const total = record.checks.length;
	const na = record.checks.filter(
		( c ) => c.status === 'not_applicable'
	).length;
	const errors = record.checks.filter( ( c ) => c.status === 'error' ).length;
	const cap = record.capped ? firstCritical( record.checks ) : null;

	return (
		<section
			className="x7velo-card x7velo-score"
			aria-labelledby="x7velo-score-heading"
		>
			<h2 id="x7velo-score-heading" className="x7velo-score__heading">
				{ __( 'Your score', 'x7velo' ) }
			</h2>
			<VisuallyHidden as="span">
				{ sprintf(
					/* translators: 1: score 0–100, 2: band label, e.g. "Partly readable". */
					__(
						'Your AI readiness score is %1$d out of 100: %2$s.',
						'x7velo'
					),
					score,
					band.label
				) }
			</VisuallyHidden>
			<div className="x7velo-score__main">
				<p className="x7velo-score__number" aria-hidden="true">
					<span className="x7velo-score__value">{ score }</span>
					<span className="x7velo-score__max"> / 100</span>
				</p>
				<div className="x7velo-score__band">
					<p className="x7velo-score__band-label" aria-hidden="true">
						{ band.label }
					</p>
					<p>{ band.text }</p>
				</div>
			</div>
			<div className="x7velo-bar" aria-hidden="true">
				<span
					style={ {
						width: `${ Math.max( 0, Math.min( 100, score ) ) }%`,
					} }
				/>
			</div>
			{ cap && (
				<p className="x7velo-score__cap">
					{ sprintf(
						/* translators: %s: what is wrong, e.g. "Your site asks search engines and AI assistants to stay away." */
						__(
							'Your score is limited to 20 until this is fixed: %s',
							'x7velo'
						),
						cap.message
					) }{ ' ' }
					{ cap.guidedUrl && (
						<Button variant="link" href={ cap.guidedUrl }>
							{ __( 'Show me where', 'x7velo' ) }
						</Button>
					) }
				</p>
			) }
			{ stale && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Your fixes or plugins changed since the last check. Run the audit again to update your score.',
						'x7velo'
					) }
				</Notice>
			) }
			<div className="x7velo-score__meta">
				<p>
					{ sprintf(
						/* translators: %s: date and time. */
						__( 'Last checked: %s', 'x7velo' ),
						formatDate( record.generatedAt )
					) }
				</p>
				<RunButton onRun={ onRun } waiting={ waiting } />
			</div>
			{ siteMode && (
				<p className="x7velo-muted x7velo-score__basis">
					{ siteBasisText( record.checks ) }
				</p>
			) }
			{ ! siteMode && na + errors > 0 && (
				<p className="x7velo-muted">
					{ [
						sprintf(
							/* translators: 1: checks used in the score, 2: all checks. */
							__( 'Based on %1$d of %2$d checks.', 'x7velo' ),
							total - na - errors,
							total
						),
						na > 0 &&
							sprintf(
								/* translators: %d: number of checks. */
								_n(
									'%d check didn’t apply to your store.',
									'%d checks didn’t apply to your store.',
									na,
									'x7velo'
								),
								na
							),
						errors > 0 &&
							sprintf(
								/* translators: %d: number of checks. */
								_n(
									'%d check couldn’t run.',
									'%d checks couldn’t run.',
									errors,
									'x7velo'
								),
								errors
							),
					]
						.filter( Boolean )
						.join( ' ' ) }
				</p>
			) }
		</section>
	);
}

/**
 * Support details: versions, check IDs and codes only. No paths, nothing sent anywhere.
 *
 * @param {Object} record UI record.
 * @param {Object} boot   Bootstrap data.
 * @return {string} Plain text for the clipboard.
 */
export function supportDetails( record, boot ) {
	const v = boot.versions;
	const lines = [
		`x7velo ${ v.plugin || record.pluginVersion }`,
		`WordPress ${ v.wp }`,
		boot.woocommerceActive === false
			? 'WooCommerce not active'
			: `WooCommerce ${ v.wc }`,
		`PHP ${ v.php }`,
	];
	if ( v.memoryLimit ) {
		lines.push( `memory_limit ${ v.memoryLimit }` );
	}
	record.checks
		.filter( ( c ) => c.status === 'error' )
		.forEach( ( c ) => lines.push( `${ c.id }: ${ c.code || 'error' }` ) );
	return lines.join( '\n' );
}

export function IncompleteCard( {
	record,
	boot,
	copy,
	onRun,
	waiting,
	siteMode = false,
} ) {
	const errored = record.checks.filter( ( c ) => c.status === 'error' );
	const memory = errored.some( ( c ) => c.code === 'scan_memory_limit' );
	return (
		<section
			className="x7velo-card x7velo-incomplete"
			aria-labelledby="x7velo-inc-heading"
		>
			<h2 id="x7velo-inc-heading">
				{ siteMode
					? __( 'We couldn’t finish checking your site', 'x7velo' )
					: __( 'We couldn’t finish checking your store', 'x7velo' ) }
			</h2>
			{ memory ? (
				<>
					<p>
						{ __(
							'Your store has more products than your hosting’s memory allows us to check in one go, so we stopped early to keep your site running. We’re not showing a score, because product checks are missing. Your store wasn’t changed.',
							'x7velo'
						) }
					</p>
					<p>
						{ __(
							'Running it again at a quiet time may work. If it keeps happening, your host can raise the PHP memory limit for your site.',
							'x7velo'
						) }
					</p>
				</>
			) : (
				<>
					<p>
						{ sprintf(
							/* translators: 1: checks with an error, 2: all checks. */
							__(
								'%1$d of %2$d checks stopped with an error, so we’re not showing a score. A score based on so few checks wouldn’t be reliable.',
								'x7velo'
							),
							errored.length,
							record.checks.length
						) }
					</p>
					<p>
						{ siteMode
							? __(
									'This is usually caused by a temporary problem or a conflict with another plugin. Your site wasn’t changed.',
									'x7velo'
								)
							: __(
									'This is usually caused by a temporary problem or a conflict with another plugin. Your store wasn’t changed.',
									'x7velo'
								) }
					</p>
				</>
			) }
			<RunButton onRun={ onRun } waiting={ waiting } primary />
			<Disclosure label={ __( 'Details for support', 'x7velo' ) }>
				<p>
					{ sprintf(
						/* translators: %s: list of check names. */
						__( 'Checks that couldn’t run: %s', 'x7velo' ),
						errored
							.map( ( c ) => copyFor( copy, c.id ).title )
							.join( ', ' )
					) }
				</p>
				<p>
					<CopyButton
						text={ supportDetails( record, boot ) }
						label={ __( 'Copy details', 'x7velo' ) }
					/>{ ' ' }
					{ __(
						'Paste these when you ask for help on the support forum.',
						'x7velo'
					) }{ ' ' }
					<a href={ boot.urls.supportForum }>
						{ __( 'Support forum', 'x7velo' ) }
					</a>
				</p>
			</Disclosure>
		</section>
	);
}

export function NoProductsCard( { boot, onRun, waiting } ) {
	return (
		<section
			className="x7velo-card x7velo-noproducts"
			aria-labelledby="x7velo-np-heading"
		>
			<h2 id="x7velo-np-heading">
				{ __( 'Add products first', 'x7velo' ) }
			</h2>
			<p>
				{ __(
					'Your store doesn’t have any published products yet, so there’s nothing for AI assistants to read and no score to show. Once you publish products, run the audit again.',
					'x7velo'
				) }
			</p>
			<div className="x7velo-actions">
				<Button variant="primary" href={ boot.urls.newProduct }>
					{ __( 'Add a product', 'x7velo' ) }
				</Button>
				<RunButton onRun={ onRun } waiting={ waiting } />
			</div>
			<p>
				{ __(
					'You can already fix your store settings below.',
					'x7velo'
				) }
			</p>
		</section>
	);
}

/**
 * One non-dismissible warning per critical root cause: VIS-01 → VIS-04 → CRAWL-01 (design §3.8).
 *
 * @param {Object}  props             Props.
 * @param {Object}  props.record      UI record.
 * @param {Object}  props.fixerStatus Fixer status map or null.
 * @param {Object}  props.handlers    Action handlers.
 * @param {boolean} props.siteMode    Site mode (no WooCommerce).
 * @return {Element|null} Notices.
 */
export function CriticalNotices( {
	record,
	fixerStatus,
	handlers,
	siteMode = false,
} ) {
	const notices = [];
	const failing = ( id ) =>
		record.checks.find( ( c ) => c.id === id && c.critical );
	const vis01 = failing( 'VIS-01' );
	const vis04 = failing( 'VIS-04' );
	const crawl01 = failing( 'CRAWL-01' );
	if ( vis01 ) {
		notices.push( {
			id: 'VIS-01',
			title: __(
				'Critical: your site asks search engines and AI assistants to stay away',
				'x7velo'
			),
			body: __(
				'This is usually a setting left on by mistake after building a site. It limits your score to 20.',
				'x7velo'
			),
			check: vis01,
		} );
	}
	if ( vis04 ) {
		notices.push( {
			id: 'VIS-04',
			title: __(
				'Critical: your store is in “Coming soon” mode',
				'x7velo'
			),
			body: __(
				'Shoppers, search engines and AI assistants see a placeholder page instead of your products. You see the normal store only because you’re logged in. This limits your score to 20.',
				'x7velo'
			),
			extra:
				vis04.details?.store_pages_only ||
				vis04.code === 'coming_soon_store_pages'
					? __(
							'Your other pages are visible, but your shop and product pages are not.',
							'x7velo'
						)
					: null,
			check: vis04,
		} );
	}
	if ( crawl01 && ! isSearchVisibilityBlock( crawl01 ) ) {
		notices.push( {
			id: 'CRAWL-01',
			title: siteMode
				? __(
						'Critical: your crawler rules block all AI search assistants',
						'x7velo'
					)
				: __(
						'Critical: your crawler rules block all AI shopping assistants',
						'x7velo'
					),
			body: siteMode
				? __(
						'Your robots.txt asks every AI search assistant to stay away from your site. This limits your score to 20.',
						'x7velo'
					)
				: __(
						'Your robots.txt asks every AI shopping assistant to stay away from your store. This limits your score to 20.',
						'x7velo'
					),
			check: crawl01,
		} );
	}
	if ( ! notices.length ) {
		return null;
	}
	return notices.map( ( n ) => {
		const robots = fixerStatus?.robots;
		let action = null;
		if ( n.id === 'CRAWL-01' ) {
			if ( robots?.state === 'off' ) {
				action = (
					<Button
						variant="secondary"
						onClick={ ( e ) =>
							handlers.onFix( n.check, e.currentTarget )
						}
					>
						{ __( 'Fix it', 'x7velo' ) }
					</Button>
				);
			} else if (
				robots?.state === 'cannot_run' &&
				reasonKind( robots.reason ) === 'physical_file'
			) {
				action = (
					<Button
						variant="secondary"
						onClick={ () => handlers.onOpenCheck( 'CRAWL-01' ) }
					>
						{ __( 'Show the lines to add', 'x7velo' ) }
					</Button>
				);
			}
		} else if ( n.check.guidedUrl ) {
			action = (
				<Button variant="secondary" href={ n.check.guidedUrl }>
					{ __( 'Show me where', 'x7velo' ) }
				</Button>
			);
		}
		return (
			<Notice
				key={ n.id }
				status="warning"
				isDismissible={ false }
				className="x7velo-critical"
			>
				<p>
					<strong>{ n.title }</strong>
				</p>
				<p>{ n.body }</p>
				{ n.extra && <p>{ n.extra }</p> }
				{ action }
			</Notice>
		);
	} );
}

export function TopFixes( {
	record,
	fixerStatus,
	copy,
	handlers,
	siteMode = false,
} ) {
	const items = topFixes( record.checks, fixerStatus, 3 );
	return (
		<section
			className="x7velo-card x7velo-top"
			aria-labelledby="x7velo-top-heading"
		>
			<h2 id="x7velo-top-heading">
				{ items.length === 3 || items.length === 0
					? __( 'Top 3 fixes', 'x7velo' )
					: __( 'Top fixes', 'x7velo' ) }
			</h2>
			{ items.length === 0 ? (
				<p>
					{ siteMode
						? __(
								'Nothing to fix right now. Your site passes every check we can run on it.',
								'x7velo'
							)
						: __(
								'Nothing to fix right now. Your store passes every check we can run on your site.',
								'x7velo'
							) }
				</p>
			) : (
				<ol className="x7velo-top__list">
					{ items.map( ( { check, points, actions } ) => {
						const c = copyFor( copy, check.id );
						return (
							<li key={ check.id } className="x7velo-top__item">
								<h3 className="x7velo-top__title">
									{ c.title }
								</h3>
								<p className="x7velo-top__status">
									<StatusLabel check={ check } />
									{ points >= 1 && (
										<span className="x7velo-top__points">
											{ sprintf(
												/* translators: %d: score points. */
												__(
													'Up to +%d points',
													'x7velo'
												),
												points
											) }
										</span>
									) }
								</p>
								<p>{ check.message }</p>
								<div className="x7velo-actions">
									<ActionButtons
										actions={ actions }
										check={ check }
										title={ c.title }
										handlers={ handlers }
									/>
									<Button
										variant="link"
										onClick={ () =>
											handlers.onOpenCheck( check.id )
										}
										aria-label={ contextLabel(
											__( 'Details', 'x7velo' ),
											c.title
										) }
									>
										{ __( 'Details', 'x7velo' ) }
									</Button>
								</div>
							</li>
						);
					} ) }
				</ol>
			) }
		</section>
	);
}

export function CategoryBreakdown( { record } ) {
	const labels = getCategoryLabels();
	// Store checks that don't apply without WooCommerce are listed apart (spec §15.6).
	const checksIn = ( cat ) =>
		record.checks.filter(
			( c ) => c.category === cat && ! isWooInactive( c )
		);
	const cats = CATEGORY_ORDER.filter( ( cat ) => checksIn( cat ).length > 0 );
	const focusCategory = ( cat ) => {
		const el = document.getElementById( `x7velo-cat-${ cat }` );
		if ( el ) {
			el.scrollIntoView?.( { block: 'start' } );
			el.focus();
		}
	};
	return (
		<section
			className="x7velo-card x7velo-cats"
			aria-labelledby="x7velo-cats-heading"
		>
			<h2 id="x7velo-cats-heading">{ __( 'By category', 'x7velo' ) }</h2>
			<ul className="x7velo-cats__list">
				{ cats.map( ( cat ) => {
					const s = categorySummary( checksIn( cat ) );
					return (
						<li key={ cat } className="x7velo-cats__row">
							<a
								href={ `#x7velo-cat-${ cat }` }
								onClick={ ( e ) => {
									e.preventDefault();
									focusCategory( cat );
								} }
								className="x7velo-cats__name"
							>
								{ labels[ cat ] }
							</a>
							<span
								className="x7velo-bar x7velo-bar--small"
								aria-hidden="true"
							>
								<span
									style={ {
										width: `${ Math.round( s.share * 100 ) }%`,
									} }
								/>
							</span>
							<span className="x7velo-cats__summary">
								{ summaryText( s ) }
							</span>
						</li>
					);
				} ) }
			</ul>
		</section>
	);
}

export default function Overview( props ) {
	const {
		audit,
		settings,
		boot,
		copy,
		stale,
		handlers,
		openIds,
		focusId,
		onToggle,
		onFocused,
	} = props;
	const { phase, record, waiting, otherFailed } = audit;
	const fixerStatus = settings?.fixerStatus || null;
	const siteMode = isSiteMode( boot );
	// Busy while our own re-run or someone else's run is in progress; results stay on screen so
	// focus can stay on "Run audit again" (design §6.3).
	const busy = waiting || phase === 'running';

	return (
		<div className="x7velo-overview">
			{ waiting && (
				<Notice
					status="info"
					isDismissible={ false }
					className="x7velo-waiting"
				>
					<Spinner />
					{ __(
						'An audit is already running, maybe from another tab or another admin. We’ll show the results when it finishes.',
						'x7velo'
					) }
				</Notice>
			) }
			{ otherFailed && (
				<Notice
					status="warning"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Run audit again', 'x7velo' ),
							onClick: handlers.onRun,
						},
					] }
				>
					{ __( 'The other audit didn’t finish.', 'x7velo' ) }
				</Notice>
			) }
			{ record && (
				<CriticalNotices
					siteMode={ siteMode }
					record={ record }
					fixerStatus={ fixerStatus }
					handlers={ handlers }
				/>
			) }
			{ phase === 'running' && ! record && (
				<RunningCard siteMode={ siteMode } />
			) }
			{ record && (
				<>
					{ record.state === 'complete' && record.score !== null && (
						<ScoreCard
							siteMode={ siteMode }
							record={ record }
							stale={ stale || record.stale }
							onRun={ handlers.onRun }
							waiting={ busy }
						/>
					) }
					{ record.state === 'incomplete' && (
						<IncompleteCard
							siteMode={ siteMode }
							record={ record }
							boot={ boot }
							copy={ copy }
							onRun={ handlers.onRun }
							waiting={ busy }
						/>
					) }
					{ ! siteMode && record.state === 'no_products' && (
						<NoProductsCard
							boot={ boot }
							onRun={ handlers.onRun }
							waiting={ busy }
						/>
					) }
					{ record.state === 'complete' && record.score !== null && (
						<>
							<TopFixes
								siteMode={ siteMode }
								record={ record }
								fixerStatus={ fixerStatus }
								copy={ copy }
								handlers={ handlers }
							/>
							<CategoryBreakdown record={ record } />
						</>
					) }
					<CheckList
						record={ record }
						fixerStatus={ fixerStatus }
						boot={ boot }
						copy={ copy }
						openIds={ openIds }
						focusId={ focusId }
						onToggle={ onToggle }
						onFocused={ onFocused }
						handlers={ handlers }
						settingsValues={ settings?.values }
					/>
				</>
			) }
		</div>
	);
}
