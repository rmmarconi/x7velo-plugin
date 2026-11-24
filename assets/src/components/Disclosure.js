/**
 * "▸ Show …" disclosure: link button with aria-expanded controlling a region.
 */
import { Button } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { chevronDown, chevronRight } from '@wordpress/icons';

export default function Disclosure( { label, children, open, onToggle } ) {
	const [ ownOpen, setOwnOpen ] = useState( false );
	const isOpen = open ?? ownOpen;
	const id = useInstanceId( Disclosure, 'x7velo-disclosure' );
	const toggle = onToggle || ( () => setOwnOpen( ! ownOpen ) );
	return (
		<div className="x7velo-disclosure">
			<Button
				variant="link"
				aria-expanded={ isOpen }
				aria-controls={ id }
				onClick={ toggle }
				icon={ isOpen ? chevronDown : chevronRight }
				className="x7velo-disclosure__toggle"
			>
				{ label }
			</Button>
			<div
				id={ id }
				hidden={ ! isOpen }
				className="x7velo-disclosure__panel"
			>
				{ isOpen && children }
			</div>
		</div>
	);
}
