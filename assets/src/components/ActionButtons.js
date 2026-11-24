/**
 * Fix it / Show me where / How to fix / Choose returns page buttons for one check.
 * Repeated buttons get the check title in their accessible name so each is unique (design §5.3).
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Accessible name "<visible label>: <check title>" (keeps the visible text first, WCAG 2.5.3).
 *
 * @param {string} label Visible button label.
 * @param {string} title Check title.
 * @return {string} Accessible name.
 */
export function contextLabel( label, title ) {
	return sprintf(
		/* translators: 1: button label, e.g. "Fix it", 2: check title, e.g. "Store summary for AI". */
		__( '%1$s: %2$s', 'x7velo' ),
		label,
		title
	);
}

export function ActionButton( { kind, primary, check, title, handlers } ) {
	const variant = primary ? 'primary' : 'secondary';
	switch ( kind ) {
		case 'fix':
			return (
				<Button
					variant={ variant }
					onClick={ ( e ) =>
						handlers.onFix( check, e.currentTarget )
					}
					aria-label={ contextLabel(
						__( 'Fix it', 'x7velo' ),
						title
					) }
				>
					{ __( 'Fix it', 'x7velo' ) }
				</Button>
			);
		case 'where':
			return (
				<Button
					variant={ primary ? 'secondary' : 'tertiary' }
					href={ check.guidedUrl }
					aria-label={ contextLabel(
						__( 'Show me where', 'x7velo' ),
						title
					) }
				>
					{ __( 'Show me where', 'x7velo' ) }
				</Button>
			);
		case 'settings':
			return (
				<Button
					variant="secondary"
					onClick={ () => handlers.onGoSettings() }
				>
					{ __( 'Choose returns page', 'x7velo' ) }
				</Button>
			);
		case 'how':
			return (
				<Button
					variant="secondary"
					onClick={ () => handlers.onOpenCheck( check.id ) }
					aria-label={ contextLabel(
						__( 'How to fix', 'x7velo' ),
						title
					) }
				>
					{ __( 'How to fix', 'x7velo' ) }
				</Button>
			);
	}
	return null;
}

export default function ActionButtons( {
	actions,
	check,
	title,
	handlers,
	includeHow = true,
} ) {
	const kinds = [ actions.primary, actions.secondary ].filter(
		( k ) => k && ( includeHow || k !== 'how' )
	);
	return kinds.map( ( kind, i ) => (
		<ActionButton
			key={ kind }
			kind={ kind }
			primary={ i === 0 }
			check={ check }
			title={ title }
			handlers={ handlers }
		/>
	) );
}
