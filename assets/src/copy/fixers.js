/**
 * Copy derived from fixer_status (managed / paused / cannot_run reasons).
 */
import { __, sprintf } from '@wordpress/i18n';

import { sourceName } from '../constants';

/**
 * Normalises cannot_run reason codes the engine may use.
 *
 * @param {string|null} reason Raw reason.
 * @return {string} physical_file | search_visibility | subfolder | permalinks | coming_soon | public_output | other.
 */
export function reasonKind( reason ) {
	const r = String( reason || '' ).toLowerCase();
	if ( r === 'coming_soon' ) {
		return 'coming_soon';
	}
	if ( r === 'public_output_disallowed' ) {
		return 'public_output';
	}
	if ( r.includes( 'physical' ) || r.includes( 'file' ) ) {
		return 'physical_file';
	}
	if (
		r.includes( 'search_visibility' ) ||
		r.includes( 'blog_public' ) ||
		r === 'vis-01'
	) {
		return 'search_visibility';
	}
	if ( r.includes( 'subfolder' ) || r.includes( 'subdirectory' ) ) {
		return 'subfolder';
	}
	if ( r.includes( 'permalink' ) ) {
		return 'permalinks';
	}
	return 'other';
}

/**
 * Plain-language reason a fixer can't be turned on.
 *
 * @param {Object|null} fs   Fixer status.
 * @param {Object}      boot Bootstrap data.
 * @return {string} Reason.
 */
export function unavailableReason( fs, boot ) {
	if ( ! fs ) {
		return __(
			'This option isn’t available in this version of x7velo yet.',
			'x7velo'
		);
	}
	if ( fs.state === 'managed' || fs.state === 'paused' ) {
		return managedText( fs.managedBy );
	}
	if ( fs.state === 'cannot_run' ) {
		switch ( reasonKind( fs.reason ) ) {
			case 'physical_file':
				return __(
					'Your site has its own robots.txt file, so x7velo can’t add rules to it.',
					'x7velo'
				);
			case 'search_visibility':
				return __(
					'Your site is set to discourage search engines (Settings → Reading). x7velo won’t add crawler rules that go against that setting. Change it first, then come back here.',
					'x7velo'
				);
			case 'subfolder':
				return sprintf(
					/* translators: %s: main site address, e.g. example.com. */
					__(
						'Your robots.txt is managed by the main site at %s. x7velo can’t add rules to it from here.',
						'x7velo'
					),
					boot.siteHost || boot.mainSiteUrl
				);
			case 'permalinks':
				return __(
					'Needs readable links first. Change to “Post name” in Settings → Permalinks.',
					'x7velo'
				);
			case 'coming_soon':
				return __(
					'Your store is in “Coming soon” mode (WooCommerce → Settings → Site visibility), so x7velo keeps this private until your store is live.',
					'x7velo'
				);
			case 'public_output':
				return __(
					'Another plugin is hiding your store from visitors right now, so x7velo keeps this private too.',
					'x7velo'
				);
		}
		return __(
			'A setting on your site prevents this right now.',
			'x7velo'
		);
	}
	return '';
}

/**
 * @param {string|null} managedBy Source slug or name.
 * @return {string} "Managed by X."
 */
export function managedText( managedBy ) {
	const slug = String( managedBy || '' ).toLowerCase();
	if ( [ 'file', 'physical', 'physical_file' ].includes( slug ) ) {
		return sprintf(
			/* translators: %s: file name, e.g. llms.txt. */
			__( 'Managed by a file on your server (%s).', 'x7velo' ),
			'llms.txt'
		);
	}
	return sprintf(
		/* translators: %s: plugin name, e.g. "Yoast SEO". */
		__( 'Managed by %s.', 'x7velo' ),
		sourceName( managedBy ) ||
			/* translators: Stands in for a plugin name inside a sentence, e.g. “Managed by another plugin.” */
			__( 'another plugin', 'x7velo' )
	);
}
