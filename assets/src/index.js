/**
 * Entry point: mounts the AI Readiness app into #x7velo-admin-root, replacing the PHP no-JS
 * fallback that is rendered inside it.
 */
import { createRoot } from '@wordpress/element';

import App from './components/App';
import './style.scss';

function mount() {
	const root = document.getElementById( 'x7velo-admin-root' );
	if ( ! root ) {
		return;
	}
	root.classList.add( 'x7velo-has-js' );
	createRoot( root ).render( <App /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
