import '@testing-library/jest-dom';

// jsdom does not implement window.scrollTo: its stub logs "Not implemented: window.scrollTo"
// through console.error. framer-motion (behind @wordpress/components animations) calls
// window.scrollTo from a requestAnimationFrame callback after measuring keyframes, so whether that
// frame lands inside a test or after it depends on machine load, and @wordpress/jest-console then
// fails whichever test happens to be running (seen as an intermittent failure of
// "renders one EXPERIMENTAL checkbox…"). Scrolling has no meaning in jsdom; make it a no-op.
window.scrollTo = () => {};
