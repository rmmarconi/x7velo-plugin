/**
 * Code block + copy button. Announces "Copied." for screen readers.
 */
import { speak } from '@wordpress/a11y';
import { Button } from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

export function CopyButton( {
	text,
	label,
	accessibleLabel,
	variant = 'secondary',
} ) {
	const [ copied, setCopied ] = useState( false );
	const ref = useCopyToClipboard( text, () => {
		setCopied( true );
		speak( __( 'Copied.', 'x7velo' ) );
		setTimeout( () => setCopied( false ), 3000 );
	} );
	return (
		<Button
			variant={ variant }
			ref={ ref }
			label={ accessibleLabel }
			showTooltip={ false }
		>
			{ copied
				? __( 'Copied.', 'x7velo' )
				: label || __( 'Copy lines', 'x7velo' ) }
		</Button>
	);
}

export default function CopyBlock( { text, label } ) {
	return (
		<div className="x7velo-copyblock">
			<pre className="x7velo-code" tabIndex={ 0 } aria-label={ label }>
				<code>{ text }</code>
			</pre>
			<CopyButton
				text={ text }
				label={ __( 'Copy lines', 'x7velo' ) }
				accessibleLabel={
					label
						? sprintf(
								/* translators: %s: what the lines are for, e.g. "Lines that let AI shopping assistants in". */
								__( 'Copy lines: %s', 'x7velo' ),
								label
							)
						: undefined
				}
			/>
		</div>
	);
}
