/**
 * Per-check copy that is the same whatever the result: title, technical name, "Why it matters",
 * "How to fix" steps and "What Fix it does" (design §4.2, spec §5 plain-language fix column).
 *
 * The "What we found" line is NOT here: it is the server-rendered `message` (viewer's locale).
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * @param {Object}  core     Core labels from bootstrap (translated by WordPress itself, §5.6).
 * @param {boolean} siteMode Site mode (no WooCommerce): site wording for VIS/CRAWL (spec §15.6).
 * @return {Object<string, Object>} Copy by check ID.
 */
export function getCheckCopy( core = {}, siteMode = false ) {
	const all = getStoreCheckCopy( core );
	if ( ! siteMode ) {
		return all;
	}
	return { ...all, ...getSiteCheckCopy( all ) };
}

/**
 * Site-mode overrides: no "store", "shop" or "product" wording (spec §15.6, AC 111).
 *
 * @param {Object} all Store copy (steps reused where they are already neutral).
 * @return {Object<string, Object>} Overrides by check ID.
 */
function getSiteCheckCopy( all ) {
	return {
		'VIS-03': {
			...all[ 'VIS-03' ],
			why: __(
				'Readable links help assistants, and x7velo needs them to publish your site summary (llms.txt).',
				'x7velo'
			),
		},
		'CRAWL-01': {
			...all[ 'CRAWL-01' ],
			title: __( 'AI assistants can visit', 'x7velo' ),
			why: __(
				'AI assistants that answer questions can only point people to sites they are allowed to read.',
				'x7velo'
			),
			fixDoes: __(
				'x7velo adds rules that let AI search assistants read your site. Your existing rules stay as they are. You can still block AI training bots: this only lets search assistants in.',
				'x7velo'
			),
		},
		'CRAWL-02': {
			...all[ 'CRAWL-02' ],
			why: __(
				'A sitemap is the list assistants use to find all your posts and pages.',
				'x7velo'
			),
			steps: [
				__( 'Open your sitemap settings.', 'x7velo' ),
				__(
					'Make sure the sitemap is on and includes posts and pages, then save.',
					'x7velo'
				),
			],
		},
		'CRAWL-03': {
			...all[ 'CRAWL-03' ],
			title: __( 'Site summary for AI', 'x7velo' ),
			why: __(
				'An llms.txt file gives AI assistants a short summary of your site, its main pages and recent posts.',
				'x7velo'
			),
			fixDoes: __(
				'x7velo publishes a short site summary at /llms.txt. It only lists things already public on your site. You can turn it off any time on the Fixes tab.',
				'x7velo'
			),
		},
	};
}

/**
 * @param {Object} core Core labels.
 * @return {Object<string, Object>} Store-mode copy by check ID.
 */
function getStoreCheckCopy( core ) {
	const discourage =
		core.discourage ||
		__( 'Discourage search engines from indexing this site', 'x7velo' );
	const saveChanges = core.saveChanges || __( 'Save Changes', 'x7velo' );
	const postName = core.postName || __( 'Post name', 'x7velo' );

	return {
		'VIS-01': {
			title: __( 'Visible to search engines', 'x7velo' ),
			technical: __( 'search visibility', 'x7velo' ),
			why: __(
				'Your site is telling every search engine and AI assistant to stay away. This is usually left on by mistake after building the site.',
				'x7velo'
			),
			steps: [
				sprintf(
					/* translators: %s: WordPress core checkbox label, e.g. "Discourage search engines from indexing this site". */
					__( 'In Settings → Reading, untick “%s”.', 'x7velo' ),
					discourage
				),
				sprintf(
					/* translators: %s: WordPress core button label "Save Changes". */
					__( 'Click “%s”.', 'x7velo' ),
					saveChanges
				),
			],
		},
		'VIS-02': {
			title: __( 'Secure address (https)', 'x7velo' ),
			technical: __( 'HTTPS', 'x7velo' ),
			why: __(
				'Browsers and AI assistants treat addresses that start with http:// as less safe.',
				'x7velo'
			),
			steps: [
				__(
					'Ask your host to turn on a free SSL certificate for your site.',
					'x7velo'
				),
				__(
					'Ask them to change your site address to start with https:// too.',
					'x7velo'
				),
			],
		},
		'VIS-03': {
			title: __( 'Readable links', 'x7velo' ),
			technical: __( 'permalinks', 'x7velo' ),
			why: __(
				'Readable links help assistants, and x7velo needs them to publish your store summary (llms.txt).',
				'x7velo'
			),
			steps: [
				__( 'Go to Settings → Permalinks.', 'x7velo' ),
				sprintf(
					/* translators: 1: WordPress core option label "Post name", 2: core button label "Save Changes". */
					__( 'Choose “%1$s”, then click “%2$s”.', 'x7velo' ),
					postName,
					saveChanges
				),
			],
		},
		'VIS-04': {
			title: __( 'Store is open to visitors', 'x7velo' ),
			technical: __( 'coming soon mode', 'x7velo' ),
			why: __(
				'You see the normal store only because you’re logged in.',
				'x7velo'
			),
			steps: [
				__(
					'In WooCommerce → Settings → Site visibility, choose “Live”.',
					'x7velo'
				),
				__( 'Click “Save changes”.', 'x7velo' ),
			],
		},
		'CRAWL-01': {
			title: __( 'AI shopping assistants can visit', 'x7velo' ),
			technical: __( 'AI crawler rules', 'x7velo' ),
			why: __(
				'AI assistants that recommend products can only suggest stores they are allowed to read.',
				'x7velo'
			),
			steps: [
				__(
					'Ask your host or developer to add the lines shown on the Fixes tab to the robots.txt file in your site’s main folder.',
					'x7velo'
				),
			],
			fixDoes: __(
				'x7velo adds rules that let AI shopping assistants read your shop. Your existing rules stay as they are. You can still block AI training bots: this only lets shopping and search assistants in.',
				'x7velo'
			),
		},
		'CRAWL-02': {
			title: __( 'Sitemap', 'x7velo' ),
			technical: __( 'sitemap', 'x7velo' ),
			why: __(
				'A sitemap is the list assistants use to find all your products.',
				'x7velo'
			),
			steps: [
				__( 'Open your sitemap settings.', 'x7velo' ),
				__(
					'Make sure the sitemap is on and includes products, then save.',
					'x7velo'
				),
			],
			fixDoes: __(
				'x7velo adds a line to your robots.txt that points assistants to your sitemap, as part of its crawler rules.',
				'x7velo'
			),
		},
		'CRAWL-03': {
			title: __( 'Store summary for AI', 'x7velo' ),
			technical: __( 'llms.txt', 'x7velo' ),
			why: __(
				'An llms.txt file gives AI assistants a short summary of your store, categories and products.',
				'x7velo'
			),
			steps: [
				__(
					'Turn on the llms.txt feature of your SEO plugin, or let x7velo publish one.',
					'x7velo'
				),
			],
			fixDoes: __(
				'x7velo publishes a short store summary at /llms.txt. It only lists things already public on your site. You can turn it off any time on the Fixes tab.',
				'x7velo'
			),
		},
		'CRAWL-04': {
			title: __( 'Product list for shopping assistants', 'x7velo' ),
			technical: __( 'product feed', 'x7velo' ),
			why: __(
				'Shopping assistants use product feeds to read prices and stock without visiting every page.',
				'x7velo'
			),
			steps: [
				__(
					'Turn on a product feed, for example x7velo’s product feed on the Fixes tab.',
					'x7velo'
				),
			],
			fixDoes: __(
				'x7velo publishes a list of your products, with prices and stock, at a public address. You can turn it off any time on the Fixes tab.',
				'x7velo'
			),
		},
		'SCHEMA-01': {
			title: __( 'Product details for AI assistants', 'x7velo' ),
			technical: __( 'Product schema (JSON-LD)', 'x7velo' ),
			why: __(
				'Without product details, assistants have to guess your prices and stock, or skip you.',
				'x7velo'
			),
			whyWarn: __(
				'When the details appear twice, assistants may read the wrong price.',
				'x7velo'
			),
			steps: [],
			fixDoes: __(
				'x7velo adds this information to each product page. You can turn it off any time on the Fixes tab.',
				'x7velo'
			),
		},
		'DATA-01': {
			title: __( 'Prices', 'x7velo' ),
			technical: __( 'price', 'x7velo' ),
			why: __(
				'AI assistants skip products they can’t quote a price for.',
				'x7velo'
			),
			steps: [
				__( 'Open each product below.', 'x7velo' ),
				__(
					'In “Product data”, fill in “Regular price”. For variable products, set a price on each variation.',
					'x7velo'
				),
				__( 'Update the product, then run the audit again.', 'x7velo' ),
			],
		},
		'DATA-02': {
			title: __( 'Main images', 'x7velo' ),
			technical: __( 'image', 'x7velo' ),
			why: __(
				'Assistants and shopping results show products with a picture first.',
				'x7velo'
			),
			steps: [
				__( 'Open each product below.', 'x7velo' ),
				__( 'In the “Product image” box, choose an image.', 'x7velo' ),
				__( 'Update the product, then run the audit again.', 'x7velo' ),
			],
		},
		'DATA-03': {
			title: __( 'Descriptions', 'x7velo' ),
			technical: __( 'description', 'x7velo' ),
			why: __(
				'Assistants quote your description to explain what a product is. Very short descriptions give them little to say.',
				'x7velo'
			),
			steps: [
				__( 'Open each product below.', 'x7velo' ),
				__(
					'Write at least a few sentences (150 characters or more) in the description or short description.',
					'x7velo'
				),
				__( 'Update the product, then run the audit again.', 'x7velo' ),
			],
		},
		'DATA-04': {
			title: __( 'Barcodes and brands', 'x7velo' ),
			technical: __( 'identifiers', 'x7velo' ),
			why: __(
				'A SKU plus a barcode (GTIN, EAN, UPC) or a brand helps assistants match your product to what shoppers ask for.',
				'x7velo'
			),
			steps: [
				__( 'Open each product below.', 'x7velo' ),
				__(
					'In “Product data” → “Inventory”, fill in the SKU and the barcode (GTIN, UPC, EAN or ISBN), or choose a brand.',
					'x7velo'
				),
				__( 'Update the product, then run the audit again.', 'x7velo' ),
			],
		},
		'DATA-05': {
			title: __( 'Categories', 'x7velo' ),
			technical: __( 'categories', 'x7velo' ),
			why: __(
				'Categories help assistants understand what you sell.',
				'x7velo'
			),
			steps: [
				__( 'Open each product below.', 'x7velo' ),
				__(
					'In the “Product categories” box, tick a category.',
					'x7velo'
				),
				__( 'Update the product, then run the audit again.', 'x7velo' ),
			],
		},
		'STORE-01': {
			title: __( 'Store details', 'x7velo' ),
			technical: __( 'identity', 'x7velo' ),
			why: __(
				'Assistants check who is behind a store before recommending it.',
				'x7velo'
			),
			steps: [
				__(
					'In WooCommerce → Settings → General, fill in your store address, country and currency.',
					'x7velo'
				),
				__(
					'In Settings → General, check your site title and email address.',
					'x7velo'
				),
			],
		},
		'STORE-02': {
			title: __( 'Returns policy', 'x7velo' ),
			technical: __( 'returns policy', 'x7velo' ),
			why: __(
				'Shoppers and AI assistants look for a returns policy before recommending a store.',
				'x7velo'
			),
			steps: [
				__(
					'Publish a page that explains your returns, if you don’t have one.',
					'x7velo'
				),
				__(
					'Choose it as your returns page on the Settings tab.',
					'x7velo'
				),
			],
		},
		'STORE-03': {
			title: __( 'Shipping', 'x7velo' ),
			technical: __( 'shipping', 'x7velo' ),
			why: __(
				'Assistants tell shoppers whether you ship to them. Without shipping options, they can’t.',
				'x7velo'
			),
			steps: [
				__( 'Go to WooCommerce → Settings → Shipping.', 'x7velo' ),
				__(
					'Add a shipping zone for your country with at least one shipping method.',
					'x7velo'
				),
			],
		},
		'STORE-04': {
			title: __( 'Guest checkout', 'x7velo' ),
			technical: __( 'guest checkout', 'x7velo' ),
			why: __(
				'Some shoppers and buying assistants skip stores that require an account. This is your choice. Some stores need accounts.',
				'x7velo'
			),
			steps: [
				__(
					'In WooCommerce → Settings → Accounts & Privacy, allow customers to place orders without an account.',
					'x7velo'
				),
				__( 'Click “Save changes”.', 'x7velo' ),
			],
		},
	};
}

/**
 * Copy for a check, with a generic fallback for third-party checks.
 *
 * @param {Object} all getCheckCopy() result.
 * @param {string} id  Check ID.
 * @return {Object} Copy.
 */
export function copyFor( all, id ) {
	return (
		all[ id ] || {
			title: id,
			technical: id,
			why: '',
			steps: [],
		}
	);
}
