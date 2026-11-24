/**
 * Confirmation dialog for every fixer change (design §5.4).
 *
 * Cancel is focused for crawl-access changes; otherwise the primary action is focused.
 * Escape / close = Cancel. Focus returns to the element that opened the dialog.
 */
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { useViewportMatch } from '@wordpress/compose';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Disclosure from './Disclosure';

export default function ConfirmModal( {
	dialog,
	busy,
	error,
	onConfirm,
	onCancel,
} ) {
	const cancelRef = useRef( null );
	const confirmRef = useRef( null );
	const isSmall = useViewportMatch( 'small', '<' );
	const [ showList, setShowList ] = useState( false );

	useEffect( () => {
		// The opener still has focus here (focusOnMount is off), so remember it before moving focus.
		const opener = cancelRef.current?.ownerDocument?.activeElement || null;
		const target = dialog.crawl ? cancelRef.current : confirmRef.current;
		target?.focus();
		return () => {
			// Return focus to the trigger if it still exists, never to <body>.
			if (
				opener &&
				opener.isConnected &&
				typeof opener.focus === 'function'
			) {
				opener.focus();
			}
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const close = () => {
		if ( ! busy ) {
			onCancel();
		}
	};

	return (
		<Modal
			title={ dialog.title }
			onRequestClose={ close }
			focusOnMount={ false }
			isFullScreen={ isSmall }
			className="x7velo-modal"
			shouldCloseOnClickOutside={ ! busy }
		>
			{ dialog.body.map( ( p, i ) => (
				<p key={ i }>{ p }</p>
			) ) }
			{ dialog.lines && (
				<Disclosure label={ dialog.linesLabel }>
					<pre
						className="x7velo-code"
						tabIndex={ 0 }
						aria-label={ dialog.linesLabel }
					>
						<code>{ dialog.lines }</code>
					</pre>
				</Disclosure>
			) }
			{ dialog.list.length > 0 && (
				<Disclosure
					label={ dialog.listLabel }
					open={ showList }
					onToggle={ () => setShowList( ! showList ) }
				>
					<ul className="x7velo-bot-list">
						{ dialog.list.map( ( item ) => (
							<li key={ item }>{ item }</li>
						) ) }
					</ul>
				</Disclosure>
			) }
			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
					className="x7velo-modal__error"
				>
					{ error }
				</Notice>
			) }
			<div className="x7velo-modal__actions">
				<Button
					variant="tertiary"
					ref={ cancelRef }
					onClick={ close }
					disabled={ busy }
				>
					{ __( 'Cancel', 'x7velo' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive={ dialog.destructive }
					ref={ confirmRef }
					onClick={ onConfirm }
					aria-disabled={ busy }
					isBusy={ busy }
				>
					{ dialog.confirmLabel }
				</Button>
				{ busy && <Spinner /> }
			</div>
		</Modal>
	);
}
