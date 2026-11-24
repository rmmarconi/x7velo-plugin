/**
 * Confirmation dialog copy (design §5.4). Every fixer change goes through one of these.
 * `crawl: true` = changes crawl access → Cancel is focused by default.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Builds the change set and dialog for a fixer action.
 *
 * @param {string}  action One of: robots, training, google_extended, experimental, jsonld, llms_txt, feed.
 * @param {boolean} on     Turning on (true) or off (false).
 * @param {Object}  ctx    { values, fixerStatus, boot, token, operator, partsReady }.
 * @return {{title: string, body: string[], confirmLabel: string, destructive: boolean, crawl: boolean, changes: Object, name: string, lines: string, linesLabel: string, list: string[], listLabel: string}} Dialog.
 */
export function getDialog( action, on, ctx ) {
	const dialog = getStoreDialog( action, on, ctx );
	if ( ! dialog || ctx?.boot?.woocommerceActive !== false ) {
		return dialog;
	}
	return { ...dialog, ...siteOverrides( action, on, ctx ) };
}

/**
 * Site-mode wording (no WooCommerce, spec §15.6) for the two fixers shown without a store.
 *
 * @param {string}  action Action.
 * @param {boolean} on     Turning on.
 * @param {Object}  ctx    Context.
 * @return {Object} Fields to override.
 */
function siteOverrides( action, on, ctx ) {
	const { fixerStatus = {}, boot = {} } = ctx;
	if ( action === 'robots' ) {
		const name = __( 'Crawler rules for AI search assistants', 'x7velo' );
		return on
			? {
					name,
					title: __(
						'Let AI search assistants read your site?',
						'x7velo'
					),
					body: [
						__(
							'x7velo will add rules to your robots.txt that allow assistants like ChatGPT search and Perplexity to read your site and find your sitemap. Your existing rules stay as they are. You can turn this off at any time on the Fixes tab.',
							'x7velo'
						),
					],
					confirmLabel: __( 'Allow search assistants', 'x7velo' ),
				}
			: { name };
	}
	if ( action === 'training' && on ) {
		return {
			body: [
				__(
					'This asks bots that collect content to train AI models, such as GPTBot and CCBot, not to read your site. It doesn’t block search assistants and doesn’t change your score. Most well-known bots follow these rules, but it isn’t a lock.',
					'x7velo'
				),
			],
		};
	}
	if ( action === 'google_extended' && on ) {
		return {
			body: [
				__(
					'This asks Google not to use your content to train its Gemini models. Blocking it can reduce your site’s visibility in the Gemini app. Google Search, AI Overviews and AI Mode are not affected. It doesn’t change your score.',
					'x7velo'
				),
			],
		};
	}
	if ( action === 'llms_txt' ) {
		const url =
			fixerStatus?.llms_txt?.url || boot.urls?.llmsTxt || '/llms.txt';
		const name = __( 'Site summary for AI', 'x7velo' );
		return on
			? {
					name,
					title: __( 'Publish a site summary?', 'x7velo' ),
					body: [
						sprintf(
							/* translators: %s: address of the llms.txt file. */
							__(
								'x7velo will publish a short file at %s that lists your main pages and recent posts. Anyone can open it, but it only contains things already public on your site.',
								'x7velo'
							),
							url
						),
					],
				}
			: {
					name,
					title: __( 'Remove your site summary?', 'x7velo' ),
				};
	}
	return {};
}

function getStoreDialog( action, on, ctx ) {
	const { values = {}, fixerStatus = {}, boot = {} } = ctx;
	const base = {
		destructive: false,
		crawl: false,
		lines: '',
		linesLabel: '',
		list: [],
		listLabel: '',
	};
	switch ( action ) {
		case 'robots': {
			const lines = fixerStatus?.robots?.lines?.allow || '';
			const sitemap = fixerStatus?.robots?.lines?.sitemap || '';
			return on
				? {
						...base,
						crawl: true,
						name: __(
							'Crawler rules for AI shopping assistants',
							'x7velo'
						),
						title: __(
							'Let AI shopping assistants read your store?',
							'x7velo'
						),
						body: [
							__(
								'x7velo will add rules to your robots.txt that allow assistants like ChatGPT search and Perplexity to read your shop and find your sitemap. Your existing rules stay as they are. You can turn this off at any time on the Fixes tab.',
								'x7velo'
							),
						],
						lines: [ lines, sitemap ]
							.filter( Boolean )
							.join( '\n' ),
						linesLabel: __( 'Show the exact lines', 'x7velo' ),
						confirmLabel: __(
							'Allow shopping assistants',
							'x7velo'
						),
						changes: { fix_robots_search: true },
					}
				: {
						...base,
						crawl: true,
						destructive: true,
						name: __(
							'Crawler rules for AI shopping assistants',
							'x7velo'
						),
						title: __( 'Remove x7velo’s crawler rules?', 'x7velo' ),
						body: [
							__(
								'Your robots.txt goes back to how it was before. Assistants that your other rules block will be blocked again.',
								'x7velo'
							),
						],
						confirmLabel: __( 'Remove rules', 'x7velo' ),
						changes: { fix_robots_search: false },
					};
		}
		case 'training':
			return on
				? {
						...base,
						crawl: true,
						name: __( 'Blocking AI training bots', 'x7velo' ),
						title: __( 'Block AI training bots?', 'x7velo' ),
						body: [
							__(
								'This asks bots that collect content to train AI models, such as GPTBot and CCBot, not to read your site. It doesn’t block shopping assistants and doesn’t change your score. Most well-known bots follow these rules, but it isn’t a lock.',
								'x7velo'
							),
						],
						list: ( boot.crawlers?.training || [] ).map( ( c ) =>
							c.operator
								? `${ c.token } (${ c.operator })`
								: c.token
						),
						listLabel: __( 'Which bots are these?', 'x7velo' ),
						confirmLabel: __( 'Block training bots', 'x7velo' ),
						changes: { fix_robots_block_training: true },
					}
				: {
						...base,
						crawl: true,
						name: __( 'Blocking AI training bots', 'x7velo' ),
						title: __(
							'Stop blocking AI training bots?',
							'x7velo'
						),
						body: [
							__(
								'Bots that collect content to train AI models will be allowed to read your site again, unless your other rules block them.',
								'x7velo'
							),
						],
						confirmLabel: __( 'Stop blocking', 'x7velo' ),
						changes: { fix_robots_block_training: false },
					};
		case 'google_extended':
			return on
				? {
						...base,
						crawl: true,
						name: __( 'Blocking Google-Extended', 'x7velo' ),
						title: __( 'Block Google-Extended?', 'x7velo' ),
						body: [
							__(
								'This asks Google not to use your content to train its Gemini models. Blocking it can reduce your store’s visibility in the Gemini app. Google Search, AI Overviews and AI Mode are not affected. It doesn’t change your score.',
								'x7velo'
							),
						],
						confirmLabel: __( 'Block Google-Extended', 'x7velo' ),
						changes: { block_google_extended: true },
					}
				: {
						...base,
						crawl: true,
						name: __( 'Blocking Google-Extended', 'x7velo' ),
						title: __( 'Stop blocking Google-Extended?', 'x7velo' ),
						body: [
							__(
								'Google will be allowed to use your content for its Gemini models again, unless your other rules block it.',
								'x7velo'
							),
						],
						confirmLabel: __( 'Stop blocking', 'x7velo' ),
						changes: { block_google_extended: false },
					};
		case 'experimental': {
			const { token, operator } = ctx;
			const current = values.block_experimental || [];
			const next = on
				? [ ...current.filter( ( t ) => t !== token ), token ]
				: current.filter(
						( t ) =>
							t.toLowerCase() !== String( token ).toLowerCase()
					);
			return on
				? {
						...base,
						crawl: true,
						name: sprintf(
							/* translators: %s: bot name, e.g. Bytespider. */
							__( 'Blocking %s', 'x7velo' ),
							token
						),
						title: sprintf(
							/* translators: 1: bot name, e.g. Bytespider, 2: company, e.g. ByteDance. "EXPERIMENTAL" is a label. */
							__( 'Block %1$s (%2$s)? EXPERIMENTAL', 'x7velo' ),
							token,
							operator
						),
						body: [
							sprintf(
								/* translators: 1: bot name, e.g. Bytespider, 2: company, e.g. ByteDance. */
								__(
									'This asks %1$s not to read your site. %2$s doesn’t publish official details about this bot, and it may not follow these rules. It doesn’t change your score.',
									'x7velo'
								),
								token,
								operator
							),
						],
						confirmLabel: sprintf(
							/* translators: %s: bot name, e.g. Bytespider. */
							__( 'Block %s', 'x7velo' ),
							token
						),
						changes: { block_experimental: next },
					}
				: {
						...base,
						crawl: true,
						name: sprintf(
							/* translators: %s: bot name, e.g. Bytespider. */
							__( 'Blocking %s', 'x7velo' ),
							token
						),
						title: sprintf(
							/* translators: %s: bot name, e.g. Bytespider. */
							__( 'Stop blocking %s?', 'x7velo' ),
							token
						),
						body: [
							sprintf(
								/* translators: %s: bot name, e.g. Bytespider. */
								__(
									'%s will be allowed to read your site again, unless your other rules block it.',
									'x7velo'
								),
								token
							),
						],
						confirmLabel: __( 'Stop blocking', 'x7velo' ),
						changes: { block_experimental: next },
					};
		}
		case 'jsonld': {
			const name = __( 'Product details for AI assistants', 'x7velo' );
			if ( ! on ) {
				return {
					...base,
					name,
					title: __( 'Stop adding product details?', 'x7velo' ),
					body: [
						__(
							'Your product pages go back to how they were before.',
							'x7velo'
						),
					],
					confirmLabel: __( 'Stop adding', 'x7velo' ),
					changes: { fix_jsonld: false },
				};
			}
			if ( fixerStatus?.jsonld?.mode === 'emit' ) {
				return {
					...base,
					name,
					title: __(
						'Add product details to your product pages?',
						'x7velo'
					),
					body: [
						__(
							'x7velo will add price, stock and other product details to each product page in a format AI assistants read. Nothing visible on your pages changes.',
							'x7velo'
						),
					],
					confirmLabel: __( 'Add product details', 'x7velo' ),
					changes: { fix_jsonld: true },
				};
			}
			const parts = ctx.partsReady || {};
			const body = [
				__(
					'x7velo will add your returns policy and delivery times to the product information WooCommerce already puts on your product pages. Nothing visible on your pages changes.',
					'x7velo'
				),
			];
			if ( ! parts.returns ) {
				body.push(
					__(
						'Returns policy will be added once you set it in Settings.',
						'x7velo'
					)
				);
			}
			if ( ! parts.delivery ) {
				body.push(
					__(
						'Delivery times will be added once you set them in Settings.',
						'x7velo'
					)
				);
			}
			return {
				...base,
				name,
				title: __( 'Add returns policy and delivery times?', 'x7velo' ),
				body,
				confirmLabel: __( 'Add details', 'x7velo' ),
				changes: { fix_jsonld: true },
			};
		}
		case 'llms_txt': {
			const url =
				fixerStatus?.llms_txt?.url || boot.urls?.llmsTxt || '/llms.txt';
			const name = __( 'Store summary for AI', 'x7velo' );
			return on
				? {
						...base,
						name,
						title: __( 'Publish a store summary?', 'x7velo' ),
						body: [
							sprintf(
								/* translators: %s: address of the llms.txt file. */
								__(
									'x7velo will publish a short file at %s that lists your shop pages, categories and products. Anyone can open it, but it only contains things already public on your site.',
									'x7velo'
								),
								url
							),
						],
						confirmLabel: __( 'Publish summary', 'x7velo' ),
						changes: { fix_llms_txt: true },
					}
				: {
						...base,
						name,
						title: __( 'Remove your store summary?', 'x7velo' ),
						body: [
							sprintf(
								/* translators: %s: address of the llms.txt file. */
								__(
									'The file at %s will stop working.',
									'x7velo'
								),
								url
							),
						],
						confirmLabel: __( 'Remove summary', 'x7velo' ),
						changes: { fix_llms_txt: false },
					};
		}
		case 'feed': {
			const name = __( 'Product feed', 'x7velo' );
			if ( on ) {
				const body = [
					__(
						'x7velo will publish a list of your products, with prices and stock, at a public address. It only contains things already public on your site.',
						'x7velo'
					),
				];
				if ( boot.glaActive ) {
					body.push( glaNote() );
				}
				return {
					...base,
					name,
					title: __( 'Publish a product feed?', 'x7velo' ),
					body,
					confirmLabel: __( 'Publish feed', 'x7velo' ),
					changes: { fix_feed: true },
				};
			}
			return {
				...base,
				name,
				title: __( 'Stop your product feed?', 'x7velo' ),
				body: [
					__(
						'The feed address will stop working. If you gave it to Google or another service, they’ll stop getting updates.',
						'x7velo'
					),
				],
				confirmLabel: __( 'Stop feed', 'x7velo' ),
				changes: { fix_feed: false },
			};
		}
	}
	return null;
}

export function glaNote() {
	return __(
		'If you already use Google for WooCommerce, you don’t need to send this feed to Google Merchant Center.',
		'x7velo'
	);
}

/** Fixer key → dialog action. */
export const FIXER_ACTION = {
	jsonld: 'jsonld',
	llms_txt: 'llms_txt',
	feed: 'feed',
	robots: 'robots',
};
