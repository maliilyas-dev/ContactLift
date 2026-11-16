'use strict';

/*
 * Click-reporting client (plugins/contactlift-pro/assets/js/tracking.js) —
 * the browser half of AT19 ("analytics disabled or consent revoked: no
 * subsequent event request"), plus the REST URL handling that only a real
 * browser run exposed: with plain permalinks the REST base already contains
 * a query string (index.php?rest_route=/contactlift/v1/), so appending
 * "runtime?source_type=..." corrupted the route and every report silently
 * 404'd. The unmodified script runs here in a sandbox with stub browser APIs.
 *
 *   npm run test:js
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );

const SOURCE = fs.readFileSync(
	path.join( __dirname, '..', '..', 'plugins', 'contactlift-pro', 'assets', 'js', 'tracking.js' ),
	'utf8'
);

const PRETTY = 'https://example.test/wp-json/contactlift/v1/';
const PLAIN = 'https://example.test/index.php?rest_route=/contactlift/v1/';

function boot( { restUrl = PRETTY, mode = 'consent_required', gpc = false, sourceType = 'home', sourceId = null } = {} ) {
	const calls = { fetch: [], beacon: [] };
	let clickHandler = null;
	let now = 1000000;

	const link = { getAttribute: () => 'action_call' };

	const sandbox = {
		window: {
			matchMedia: () => ( { matches: true } ),
			location: { href: 'https://example.test/' },
		},
		document: {
			readyState: 'complete',
			addEventListener: ( type, handler ) => {
				if ( 'click' === type ) {
					clickHandler = handler;
				}
			},
		},
		navigator: {
			globalPrivacyControl: gpc,
			sendBeacon: ( url ) => {
				calls.beacon.push( String( url ) );
				return true;
			},
		},
		clift_tracking_i18n: { mode, restUrl, sourceType, sourceId },
		fetch: ( url ) => {
			calls.fetch.push( String( url ) );
			return Promise.resolve( { ok: true, json: () => Promise.resolve( { context: 'signed-context' } ) } );
		},
		Date: { now: () => now },
		URL,
		URLSearchParams,
		Blob,
		Promise,
		JSON,
	};

	vm.runInNewContext( SOURCE, sandbox );

	return {
		calls,
		consent: ( granted ) => sandbox.window.ContactLift.setAnalyticsConsent( granted ),
		click: () => clickHandler( { target: { closest: () => link } } ),
		advance: ( ms ) => {
			now += ms;
		},
		settle: () => new Promise( ( resolve ) => setImmediate( resolve ) ),
	};
}

test( 'pretty-permalink REST base: runtime request carries the context as query args', async () => {
	const page = boot( { restUrl: PRETTY } );
	page.consent( true );
	page.click();
	await page.settle();

	assert.equal( page.calls.fetch.length, 1 );
	const url = new URL( page.calls.fetch[ 0 ] );
	assert.equal( url.pathname, '/wp-json/contactlift/v1/runtime' );
	assert.equal( url.searchParams.get( 'source_type' ), 'home' );
	assert.equal( url.searchParams.get( 'device' ), 'desktop' );
} );

test( 'a page with no content ID (home, archive) sends no source_id at all', async () => {
	// The route declares source_id as an integer, so an empty "source_id="
	// is a 400 — which silently disabled reporting on exactly the pages
	// visitors land on most.
	const page = boot( { sourceType: 'home', sourceId: null } );
	page.consent( true );
	page.click();
	await page.settle();

	const url = new URL( page.calls.fetch[ 0 ] );
	assert.equal( url.searchParams.has( 'source_id' ), false );
	assert.equal( url.searchParams.get( 'source_type' ), 'home' );
} );

test( 'a single page or product sends its content ID', async () => {
	const page = boot( { sourceType: 'product', sourceId: 42 } );
	page.consent( true );
	page.click();
	await page.settle();

	const url = new URL( page.calls.fetch[ 0 ] );
	assert.equal( url.searchParams.get( 'source_type' ), 'product' );
	assert.equal( url.searchParams.get( 'source_id' ), '42' );
} );

test( 'plain-permalink REST base: rest_route stays intact when query args are added', async () => {
	const page = boot( { restUrl: PLAIN } );
	page.consent( true );
	page.click();
	await page.settle();

	assert.equal( page.calls.fetch.length, 1 );
	const url = new URL( page.calls.fetch[ 0 ] );
	assert.equal( url.searchParams.get( 'rest_route' ), '/contactlift/v1/runtime' );
	assert.equal( url.searchParams.get( 'source_type' ), 'home' );
	assert.equal( url.searchParams.get( 'device' ), 'desktop' );
} );

test( 'plain-permalink REST base: the event itself is posted to the events route', async () => {
	const page = boot( { restUrl: PLAIN } );
	page.consent( true );
	page.click();
	await page.settle();

	assert.equal( page.calls.beacon.length, 1 );
	assert.equal( new URL( page.calls.beacon[ 0 ] ).searchParams.get( 'rest_route' ), '/contactlift/v1/events' );
} );

test( 'consent required: nothing at all is requested until consent is granted', async () => {
	const page = boot();
	page.click();
	await page.settle();

	assert.deepEqual( page.calls, { fetch: [], beacon: [] } );
} );

test( 'consent granted: one event is sent', async () => {
	const page = boot();
	page.consent( true );
	page.click();
	await page.settle();

	assert.equal( page.calls.beacon.length, 1 );
} );

test( 'revocation stops subsequent events', async () => {
	const page = boot();
	page.consent( true );
	page.click();
	await page.settle();
	assert.equal( page.calls.beacon.length, 1 );

	page.advance( 5000 );
	page.consent( false );
	page.click();
	await page.settle();

	assert.equal( page.calls.beacon.length, 1, 'no event after consent was revoked' );
} );

test( 'consent revoked after the click but before the send cancels that event', async () => {
	const page = boot();
	page.consent( true );
	page.click(); // the event is queued behind the runtime-context fetch
	page.consent( false ); // revoked before the queued send gets to run
	await page.settle();

	assert.deepEqual( page.calls.beacon, [], 'revocation must win over an already-queued send' );
} );

test( 'Global Privacy Control blocks reporting even with consent granted', async () => {
	const page = boot( { gpc: true } );
	page.consent( true );
	page.click();
	await page.settle();

	assert.deepEqual( page.calls, { fetch: [], beacon: [] } );
} );

test( 'analytics off: nothing is requested', async () => {
	const page = boot( { mode: 'off' } );
	page.consent( true );
	page.click();
	await page.settle();

	assert.deepEqual( page.calls, { fetch: [], beacon: [] } );
} );

test( 'local aggregate mode needs no consent call', async () => {
	const page = boot( { mode: 'local_aggregate' } );
	page.click();
	await page.settle();

	assert.equal( page.calls.beacon.length, 1 );
} );

test( 'a rapid double click on one action is counted once', async () => {
	const page = boot( { mode: 'local_aggregate' } );
	page.click();
	page.click();
	await page.settle();
	assert.equal( page.calls.beacon.length, 1 );

	page.advance( 2000 );
	page.click();
	await page.settle();
	assert.equal( page.calls.beacon.length, 2, 'a click after the debounce window counts again' );
} );
