/**
 * Fixes tab: one card per fixer, states from fixer_status (design §3.4).
 *
 * Toggles never change on click: the click opens the confirm dialog, and the stored value only
 * changes after PATCH /settings succeeds. Fixers missing from fixer_status (not built yet on
 * this install) render as unavailable instead of offering a switch that does nothing.
 */
import {
	Button,
	CheckboxControl,
	ExternalLink,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, info, published } from '@wordpress/icons';

import { isSiteMode } from '../bootstrap';
import { sourceName } from '../constants';
import { glaNote } from '../copy/dialogs';
import { managedText, reasonKind, unavailableReason } from '../copy/fixers';
import CopyBlock, { CopyButton } from './CopyBlock';
import Disclosure from './Disclosure';

const canTurnOn = ( fs ) =>
	!! fs && [ 'off', 'on', 'paused' ].includes( fs.state );

function displayUrl( url ) {
	return String( url || '' ).replace( /^https?:\/\//, '' );
}

function Card( { id, title, technical, description, children, notice } ) {
	return (
		<section
			className="x7velo-card x7velo-fixer"
			aria-labelledby={ `x7velo-fixer-${ id }` }
		>
			<h2 id={ `x7velo-fixer-${ id }` }>{ title }</h2>
			{ technical && <p className="x7velo-muted">{ technical }</p> }
			{ description && <p>{ description }</p> }
			{ notice && (
				<Notice status="warning" isDismissible={ false }>
					{ notice }
				</Notice>
			) }
			{ children }
		</section>
	);
}

function InfoLine( { children, ok = false } ) {
	return (
		<p className="x7velo-infoline">
			<Icon
				icon={ ok ? published : info }
				size={ 20 }
				aria-hidden="true"
				className={
					ok ? 'x7velo-status--pass' : 'x7velo-status--muted'
				}
			/>
			<span>{ children }</span>
		</p>
	);
}

/**
 * Client-side guess of whether the JSON-LD parts have the owner inputs they need (spec §8.1).
 * Only a fallback for servers that don't report `parts`: the server also checks that the
 * returns page is published and that the base-country shipping rate is a plain number.
 *
 * @param {Object} values Settings values.
 * @return {{returns: boolean, delivery: boolean}} Readiness.
 */
export function jsonldPartsFromSettings( values ) {
	const policyOk =
		values.return_policy === 'unlimited' ||
		values.return_policy === 'none' ||
		( values.return_policy === 'finite' && values.return_days > 0 );
	return {
		returns: values.returns_page_id > 0 && policyOk,
		delivery:
			values.delivery_days_min !== null &&
			values.delivery_days_max !== null,
	};
}

/**
 * Which JSON-LD parts are included: the server's `parts` per part when reported, the
 * settings-based derivation only for parts the server didn't report (older server).
 *
 * @param {Object|null} fs     jsonld fixer status.
 * @param {Object}      values Settings values.
 * @return {{returns: boolean, delivery: boolean, fromServer: Object, inputsReady: Object}} Parts;
 *         fromServer/inputsReady are {returns: boolean, delivery: boolean}.
 */
export function jsonldParts( fs, values ) {
	const local = jsonldPartsFromSettings( values );
	const server = fs?.parts || {};
	const pick = ( key ) =>
		typeof server[ key ] === 'boolean' ? server[ key ] : local[ key ];
	return {
		returns: pick( 'returns' ),
		delivery: pick( 'delivery' ),
		fromServer: {
			returns: typeof server.returns === 'boolean',
			delivery: typeof server.delivery === 'boolean',
		},
		// Owner inputs are filled in, yet the server still says "not yet" (unpublished page,
		// shipping rate that isn't a plain number…): Settings alone won't fix it.
		inputsReady: local,
	};
}

function ToggleRow( { fs, checked, label, help, onChange } ) {
	return (
		<ToggleControl
			__nextHasNoMarginBottom
			label={ label }
			help={ help }
			checked={ checked }
			disabled={ ! checked && ! canTurnOn( fs ) }
			onChange={ onChange }
		/>
	);
}

function JsonLdCard( { values, fs, boot, onChange, notice, goSettings } ) {
	const emit = fs?.mode === 'emit';
	const parts = jsonldParts( fs, values );
	const checked = values.fix_jsonld;
	let help = emit
		? __(
				'No other plugin adds product details to your pages, so x7velo adds price, stock and other details. It also adds your returns policy and delivery times when set in Settings.',
				'x7velo'
			)
		: __(
				'WooCommerce already adds price, stock, brand and barcode. x7velo adds the returns policy and delivery times you set in Settings.',
				'x7velo'
			);
	if ( ! fs || fs.state === 'managed' || fs.state === 'cannot_run' ) {
		help =
			fs?.state === 'managed'
				? sprintf(
						/* translators: %s: plugin name, e.g. "Rank Math". */
						__(
							'Managed by %s. x7velo stays off so product details aren’t added twice.',
							'x7velo'
						),
						sourceName( fs.managedBy ) ||
							/* translators: Stands in for a plugin name inside a sentence, e.g. “Managed by another plugin.” */
							__( 'another plugin', 'x7velo' )
					)
				: unavailableReason( fs, boot );
	} else if ( fs.state === 'paused' ) {
		const name = sourceName( fs.managedBy );
		help = name
			? sprintf(
					/* translators: %1$s: plugin name, e.g. "Rank Math". */
					__(
						'Paused. %1$s adds product details now, so x7velo isn’t adding its own. If %1$s stops, x7velo’s product details come back on automatically.',
						'x7velo'
					),
					name
				)
			: __(
					'Paused. Another plugin adds product details now, so x7velo isn’t adding its own. If that plugin stops, x7velo’s product details come back on automatically.',
					'x7velo'
				);
	}
	const returnsText = () => {
		if ( values.return_policy === 'unlimited' ) {
			return __( 'Returns policy: included (no time limit).', 'x7velo' );
		}
		if ( values.return_policy === 'none' ) {
			return __(
				'Returns policy: included (no returns accepted).',
				'x7velo'
			);
		}
		if ( values.return_policy === 'finite' && values.return_days > 0 ) {
			return sprintf(
				/* translators: %d: number of days. */
				_n(
					'Returns policy: included (%d day).',
					'Returns policy: included (%d days).',
					values.return_days,
					'x7velo'
				),
				values.return_days
			);
		}
		// Server says included but the local values are out of date (refetch pending).
		return __( 'Returns policy: included.', 'x7velo' );
	};
	const returnsMissing =
		parts.fromServer.returns && parts.inputsReady.returns
			? __(
					'Returns policy: not included yet. Check that your returns page is published, then check your return window in Settings.',
					'x7velo'
				)
			: __(
					'Returns policy: not included yet. Choose your returns page and return window in Settings to include it.',
					'x7velo'
				);
	const deliveryMissing =
		parts.fromServer.delivery && parts.inputsReady.delivery
			? deliveryNotYet( boot.baseCountry )
			: __(
					'Delivery times: not included yet. Add your delivery days in Settings to include them.',
					'x7velo'
				);
	const showParts = fs && [ 'off', 'on' ].includes( fs.state );
	return (
		<Card
			id="jsonld"
			title={ __( 'Product details for AI assistants', 'x7velo' ) }
			technical={ __( 'Product schema (JSON-LD)', 'x7velo' ) }
			description={ __(
				'Adds your returns policy and delivery times to the product information on your product pages, in a format AI assistants and search engines read.',
				'x7velo'
			) }
			notice={ notice }
		>
			<ToggleRow
				fs={ fs }
				checked={ checked }
				label={
					emit
						? __(
								'Add product information to product pages',
								'x7velo'
							)
						: __(
								'Add returns policy and delivery times to WooCommerce’s product information',
								'x7velo'
							)
				}
				help={ help }
				onChange={ ( on ) =>
					onChange( 'jsonld', on, { partsReady: parts } )
				}
			/>
			{ showParts && (
				<div className="x7velo-parts">
					{ checked && ! parts.returns && ! parts.delivery && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'x7velo isn’t adding anything yet. Answer the two questions in Settings first.',
								'x7velo'
							) }
						</Notice>
					) }
					{ parts.returns ? (
						<InfoLine ok>{ returnsText() }</InfoLine>
					) : (
						<InfoLine>
							{ returnsMissing }{ ' ' }
							<Button variant="link" onClick={ goSettings }>
								{ __( 'Go to Settings', 'x7velo' ) }
							</Button>
						</InfoLine>
					) }
					{ parts.delivery ? (
						<InfoLine ok>
							{ deliveryIncluded( boot.baseCountry ) }
						</InfoLine>
					) : (
						<InfoLine>
							{ deliveryMissing }{ ' ' }
							<Button variant="link" onClick={ goSettings }>
								{ __( 'Go to Settings', 'x7velo' ) }
							</Button>
						</InfoLine>
					) }
				</div>
			) }
		</Card>
	);
}

/**
 * "Delivery times: not included yet" line (whole sentences, with or without a country name).
 *
 * @param {string} country Store base country name, or ''.
 * @return {string} Text.
 */
function deliveryNotYet( country ) {
	return country
		? sprintf(
				/* translators: %s: country name, e.g. Brazil. */
				__(
					'Delivery times: not included yet. They’re added only for products with free or flat-rate shipping in %s. Check your shipping settings in WooCommerce.',
					'x7velo'
				),
				country
			)
		: __(
				'Delivery times: not included yet. They’re added only for products with free or flat-rate shipping in your country. Check your shipping settings in WooCommerce.',
				'x7velo'
			);
}

/**
 * "Delivery times: included" line (whole sentences, with or without a country name).
 *
 * @param {string} country Store base country name, or ''.
 * @return {string} Text.
 */
function deliveryIncluded( country ) {
	return country
		? sprintf(
				/* translators: %s: country name, e.g. Brazil. */
				__(
					'Delivery times: included for products with free or flat-rate shipping in %s.',
					'x7velo'
				),
				country
			)
		: __(
				'Delivery times: included for products with free or flat-rate shipping in your country.',
				'x7velo'
			);
}

function LlmsCard( { values, fs, boot, onChange, notice } ) {
	const siteMode = isSiteMode( boot );
	const url = fs?.url || boot.urls.llmsTxt;
	const checked = values.fix_llms_txt;
	let help = null;
	const view = (
		<ExternalLink href={ url }>
			{ __( 'View llms.txt', 'x7velo' ) }
		</ExternalLink>
	);
	if ( ! fs ) {
		help = unavailableReason( fs, boot );
	} else if ( fs.state === 'managed' ) {
		const name = sourceName( fs.managedBy );
		help = (
			<>
				{ managedText( fs.managedBy ) }{ ' ' }
				{ ! [ 'file', 'physical', 'physical_file' ].includes(
					String( fs.managedBy ).toLowerCase()
				) &&
					( siteMode
						? sprintf(
								/* translators: %s: plugin name, e.g. "Yoast SEO". */
								__(
									'Your site already has an llms.txt from %s, so x7velo stays off to avoid two versions.',
									'x7velo'
								),
								name
							)
						: sprintf(
								/* translators: %s: plugin name, e.g. "Yoast SEO". */
								__(
									'Your store already has an llms.txt from %s, so x7velo stays off to avoid two versions.',
									'x7velo'
								),
								name
							) ) }{ ' ' }
				{ view }
			</>
		);
	} else if ( fs.state === 'paused' ) {
		help = (
			<>
				{ sourceName( fs.managedBy )
					? sprintf(
							/* translators: %1$s: plugin name, e.g. "Yoast SEO". */
							__(
								'Paused. %1$s creates your llms.txt now, so x7velo isn’t publishing its own. If %1$s stops, x7velo’s llms.txt comes back on automatically.',
								'x7velo'
							),
							sourceName( fs.managedBy )
						)
					: __(
							'Paused. Another plugin creates your llms.txt now, so x7velo isn’t publishing its own. If that plugin stops, x7velo’s llms.txt comes back on automatically.',
							'x7velo'
						) }{ ' ' }
				{ view }
			</>
		);
	} else if ( fs.state === 'cannot_run' ) {
		help = (
			<>
				{ unavailableReason( fs, boot ) }{ ' ' }
				{ reasonKind( fs.reason ) === 'permalinks' && (
					<Button variant="link" href={ boot.urls.permalinks }>
						{ __( 'Show me where', 'x7velo' ) }
					</Button>
				) }
			</>
		);
	} else if ( checked ) {
		help = view;
	}
	return (
		<Card
			id="llms"
			title={
				siteMode
					? __( 'Site summary for AI (llms.txt)', 'x7velo' )
					: __( 'Store summary for AI (llms.txt)', 'x7velo' )
			}
			description={
				siteMode
					? __(
							'A short public file that describes your site, its main pages and recent posts.',
							'x7velo'
						)
					: __(
							'A short public file that describes your store, categories and products.',
							'x7velo'
						)
			}
			notice={ notice }
		>
			<ToggleRow
				fs={ fs }
				checked={ checked }
				label={
					siteMode
						? sprintf(
								/* translators: %s: address, e.g. example.com/llms.txt. */
								__( 'Publish a site summary at %s', 'x7velo' ),
								displayUrl( url )
							)
						: sprintf(
								/* translators: %s: address, e.g. example.com/llms.txt. */
								__( 'Publish a store summary at %s', 'x7velo' ),
								displayUrl( url )
							)
				}
				help={ help }
				onChange={ ( on ) => onChange( 'llms_txt', on ) }
			/>
		</Card>
	);
}

function FeedCard( { values, fs, boot, onChange, notice } ) {
	const url = fs?.url || boot.urls.feed;
	const checked = values.fix_feed;
	let help = null;
	if ( ! fs || fs.state === 'cannot_run' || fs.state === 'managed' ) {
		help =
			fs?.state === 'managed'
				? managedText( fs.managedBy )
				: unavailableReason( fs, boot );
	} else if ( checked ) {
		help = (
			<>
				<span className="x7velo-feedurl">
					{ sprintf(
						/* translators: %s: feed address. */
						__( 'Your feed address: %s', 'x7velo' ),
						url
					) }
				</span>{ ' ' }
				<CopyButton
					text={ url }
					label={ __( 'Copy', 'x7velo' ) }
					accessibleLabel={ __( 'Copy feed address', 'x7velo' ) }
				/>{ ' ' }
				<ExternalLink href={ url }>
					{ __( 'View feed', 'x7velo' ) }
				</ExternalLink>
			</>
		);
	}
	return (
		<Card
			id="feed"
			title={ __(
				'Product list for shopping assistants (product feed)',
				'x7velo'
			) }
			description={ __(
				'A public list of everything you sell, with prices and stock.',
				'x7velo'
			) }
			notice={ notice }
		>
			<ToggleRow
				fs={ fs }
				checked={ checked }
				label={ __( 'Publish a product feed', 'x7velo' ) }
				help={ help }
				onChange={ ( on ) => onChange( 'feed', on ) }
			/>
			<p className="x7velo-muted">{ glaNote() }</p>
		</Card>
	);
}

function experimentalHelp( entry ) {
	if ( String( entry.token ).toLowerCase() === 'bytespider' ) {
		return __(
			'ByteDance doesn’t publish official details about this bot, and it may not follow these rules.',
			'x7velo'
		);
	}
	return sprintf(
		/* translators: %s: company, e.g. ByteDance. */
		__(
			'%s doesn’t publish official details about this bot, and it may not follow these rules.',
			'x7velo'
		),
		entry.operator || entry.token
	);
}

function RobotsCard( { values, fs, boot, onChange, notice } ) {
	const siteMode = isSiteMode( boot );
	const kind = fs?.state === 'cannot_run' ? reasonKind( fs.reason ) : null;
	const training = boot.crawlers.training || [];
	const experimental = boot.crawlers.experimental || [];
	const blocked = ( values.block_experimental || [] ).map( ( t ) =>
		t.toLowerCase()
	);
	const anyOn =
		values.fix_robots_search ||
		values.fix_robots_block_training ||
		values.block_google_extended ||
		blocked.length > 0;

	let explanation = null;
	if ( kind === 'physical_file' ) {
		explanation = (
			<>
				<InfoLine>
					{ __(
						'Your site has its own robots.txt file, so x7velo can’t add rules to it.',
						'x7velo'
					) }
				</InfoLine>
				{ fs.lines.allow && (
					<>
						<p>
							{ siteMode
								? __(
										'If you want to let AI search assistants in, ask your host or developer to add these lines:',
										'x7velo'
									)
								: __(
										'If you want to let AI shopping assistants in, ask your host or developer to add these lines:',
										'x7velo'
									) }
						</p>
						<CopyBlock
							text={ [ fs.lines.allow, fs.lines.sitemap ]
								.filter( Boolean )
								.join( '\n' ) }
							label={
								siteMode
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
					</>
				) }
				{ fs.lines.training && (
					<>
						<p>
							{ __(
								'Also want to block AI training bots? These lines do that too:',
								'x7velo'
							) }
						</p>
						<CopyBlock
							text={ fs.lines.training }
							label={ __(
								'Lines that block AI training bots',
								'x7velo'
							) }
						/>
					</>
				) }
			</>
		);
	} else if ( fs?.state === 'cannot_run' || ! fs ) {
		explanation = (
			<InfoLine>
				{ unavailableReason( fs, boot ) }{ ' ' }
				{ kind === 'search_visibility' && (
					<Button variant="secondary" href={ boot.urls.reading }>
						{ __( 'Show me where', 'x7velo' ) }
					</Button>
				) }
			</InfoLine>
		);
	}

	// When nothing can be switched, controls are replaced by the explanation; any option that is
	// still on stays visible so it can be turned off (turning off is always allowed).
	const showControls = ! explanation || anyOn;
	const show = ( on ) => ! explanation || on;
	const allowLines = [ fs?.lines?.allow, fs?.lines?.sitemap ]
		.filter( Boolean )
		.join( '\n' );

	return (
		<Card
			id="robots"
			title={ __( 'Crawler rules (robots.txt)', 'x7velo' ) }
			description={ __(
				'Rules that tell bots which parts of your site they may read.',
				'x7velo'
			) }
			notice={ notice }
		>
			{ explanation }
			{ showControls && (
				<div className="x7velo-robots">
					{ show( values.fix_robots_search ) && (
						<div className="x7velo-robots__row">
							<ToggleRow
								fs={ fs }
								checked={ values.fix_robots_search }
								label={
									siteMode
										? __(
												'Let AI search assistants in',
												'x7velo'
											)
										: __(
												'Let AI shopping assistants in',
												'x7velo'
											)
								}
								help={
									siteMode
										? __(
												'Adds rules that allow assistants like ChatGPT search and Perplexity to read your site, and points them to your sitemap. Your existing rules are not changed.',
												'x7velo'
											)
										: __(
												'Adds rules that allow assistants like ChatGPT search and Perplexity to read your shop, and points them to your sitemap. Your existing rules are not changed.',
												'x7velo'
											)
								}
								onChange={ ( on ) => onChange( 'robots', on ) }
							/>
							{ allowLines && ! explanation && (
								<Disclosure
									label={ __(
										'Show the exact lines',
										'x7velo'
									) }
								>
									<pre
										className="x7velo-code"
										tabIndex={ 0 }
										aria-label={ __(
											'Show the exact lines',
											'x7velo'
										) }
									>
										<code>{ allowLines }</code>
									</pre>
								</Disclosure>
							) }
						</div>
					) }
					{ show( values.fix_robots_block_training ) && (
						<div className="x7velo-robots__row">
							<ToggleRow
								fs={ fs }
								checked={ values.fix_robots_block_training }
								label={ __(
									'Also block AI training bots',
									'x7velo'
								) }
								help={
									siteMode
										? __(
												'Optional. Asks bots that collect content to train AI models (such as GPTBot and CCBot) not to read your site. This doesn’t affect your score or block search assistants.',
												'x7velo'
											)
										: __(
												'Optional. Asks bots that collect content to train AI models (such as GPTBot and CCBot) not to read your site. This doesn’t affect your score or block shopping assistants.',
												'x7velo'
											)
								}
								onChange={ ( on ) =>
									onChange( 'training', on )
								}
							/>
							{ training.length > 0 && (
								<Disclosure
									label={ __(
										'Which bots are these?',
										'x7velo'
									) }
								>
									<ul className="x7velo-bot-list">
										{ training.map( ( c ) => (
											<li key={ c.token }>
												{ c.operator
													? `${ c.token } (${ c.operator })`
													: c.token }
											</li>
										) ) }
									</ul>
								</Disclosure>
							) }
						</div>
					) }
					{ boot.crawlers.googleExtended &&
						show( values.block_google_extended ) && (
							<div className="x7velo-robots__row x7velo-robots__row--indent">
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ __(
										'Also block Google-Extended',
										'x7velo'
									) }
									help={
										siteMode
											? __(
													'Asks Google not to use your content to train its Gemini models. Blocking it can reduce your site’s visibility in the Gemini app. Google Search, AI Overviews and AI Mode are not affected.',
													'x7velo'
												)
											: __(
													'Asks Google not to use your content to train its Gemini models. Blocking it can reduce your store’s visibility in the Gemini app. Google Search, AI Overviews and AI Mode are not affected.',
													'x7velo'
												)
									}
									checked={ values.block_google_extended }
									disabled={
										! values.block_google_extended &&
										! canTurnOn( fs )
									}
									onChange={ ( on ) =>
										onChange( 'google_extended', on )
									}
								/>
							</div>
						) }
					{ experimental.map( ( entry ) => {
						const on = blocked.includes(
							String( entry.token ).toLowerCase()
						);
						if ( ! show( on ) ) {
							return null;
						}
						return (
							<div
								key={ entry.token }
								className="x7velo-robots__row x7velo-robots__row--indent"
							>
								<CheckboxControl
									__nextHasNoMarginBottom
									label={
										<>
											{ sprintf(
												/* translators: 1: bot name, e.g. Bytespider, 2: company, e.g. ByteDance. */
												__(
													'Also block %1$s (%2$s)',
													'x7velo'
												),
												entry.token,
												entry.operator || ''
											) }{ ' ' }
											<span className="x7velo-tag">
												{ __(
													'EXPERIMENTAL',
													'x7velo'
												) }
											</span>
										</>
									}
									help={ experimentalHelp( entry ) }
									checked={ on }
									disabled={ ! on && ! canTurnOn( fs ) }
									onChange={ ( next ) =>
										onChange( 'experimental', next, {
											token: entry.token,
											operator:
												entry.operator || entry.token,
										} )
									}
								/>
							</div>
						);
					} ) }
				</div>
			) }
		</Card>
	);
}

export default function FixesTab( {
	settings,
	boot,
	onChange,
	cardNotices,
	goSettings,
	onRetry,
} ) {
	if ( settings.status === 'loading' ) {
		return (
			<p>
				<Spinner />
			</p>
		);
	}
	if ( settings.status === 'missing' ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Fixes aren’t available in this version of x7velo yet.',
					'x7velo'
				) }
			</Notice>
		);
	}
	if ( settings.status === 'error' ) {
		return (
			<Notice
				status="error"
				isDismissible={ false }
				actions={ [
					{ label: __( 'Try again', 'x7velo' ), onClick: onRetry },
				] }
			>
				{ __(
					'Your fixes couldn’t be loaded because the page lost contact with your site. Nothing was changed.',
					'x7velo'
				) }
			</Notice>
		);
	}
	const { values, fixerStatus } = settings;
	// Without WooCommerce only the llms.txt and crawler cards exist (spec §15.3): no teaser.
	const siteMode = isSiteMode( boot );
	const props = { values, boot, onChange };
	return (
		<div className="x7velo-fixes">
			<p className="x7velo-intro">
				{ __(
					'These are the changes x7velo can make for you. Nothing is on until you turn it on. Turning something off puts your site back the way it was.',
					'x7velo'
				) }
			</p>
			{ ! siteMode && (
				<JsonLdCard
					{ ...props }
					fs={ fixerStatus.jsonld }
					notice={ cardNotices.jsonld }
					goSettings={ goSettings }
				/>
			) }
			<LlmsCard
				{ ...props }
				fs={ fixerStatus.llms_txt }
				notice={ cardNotices.llms_txt }
			/>
			{ ! siteMode && (
				<FeedCard
					{ ...props }
					fs={ fixerStatus.feed }
					notice={ cardNotices.feed }
				/>
			) }
			<RobotsCard
				{ ...props }
				fs={ fixerStatus.robots }
				notice={ cardNotices.robots }
			/>
		</div>
	);
}
