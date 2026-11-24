/**
 * Status icon + text (status is never shown by colour alone; design §4.1).
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import {
	Icon,
	caution,
	error as errorIcon,
	notAllowed,
	help,
	lineSolid,
	published,
} from '@wordpress/icons';

import { isFixedByX7velo, isManagedBy } from '../api';
import { sourceName } from '../constants';

/**
 * @param {Object} check UI check.
 * @return {{label: string, icon: Object, tone: string}} Presentation.
 */
export function statusPresentation( check ) {
	switch ( check.status ) {
		case 'fail':
			return check.critical
				? {
						label: __( 'Critical · Needs fixing', 'x7velo' ),
						icon: errorIcon,
						tone: 'fail',
					}
				: {
						label: __( 'Needs fixing', 'x7velo' ),
						icon: notAllowed,
						tone: 'fail',
					};
		case 'warn':
			return {
				label: __( 'Worth improving', 'x7velo' ),
				icon: caution,
				tone: 'warn',
			};
		case 'pass':
			if ( isFixedByX7velo( check ) ) {
				return {
					label: __( 'Passed · Fixed by x7velo', 'x7velo' ),
					icon: published,
					tone: 'pass',
				};
			}
			if ( isManagedBy( check ) ) {
				const name = check.sourceLabel || sourceName( check.source );
				return {
					label: name
						? sprintf(
								/* translators: %s: plugin name, e.g. "Yoast SEO". */
								__( 'Passed · Managed by %s', 'x7velo' ),
								name
							)
						: _x( 'Passed', 'check status', 'x7velo' ),
					icon: published,
					tone: 'pass',
				};
			}
			return {
				label: _x( 'Passed', 'check status', 'x7velo' ),
				icon: published,
				tone: 'pass',
			};
		case 'not_applicable':
			return {
				label: __( 'Doesn’t apply', 'x7velo' ),
				icon: lineSolid,
				tone: 'muted',
			};
		default:
			return {
				label: __( 'Couldn’t check', 'x7velo' ),
				icon: help,
				tone: 'muted',
			};
	}
}

export default function StatusLabel( { check } ) {
	const p = statusPresentation( check );
	return (
		<span className={ `x7velo-status x7velo-status--${ p.tone }` }>
			<Icon
				icon={ p.icon }
				size={ 20 }
				className="x7velo-status__icon"
				aria-hidden="true"
			/>
			<span className="x7velo-status__text">{ p.label }</span>
		</span>
	);
}
