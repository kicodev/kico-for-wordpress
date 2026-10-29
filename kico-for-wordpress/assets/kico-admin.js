/*
 * Settings > Kico: counts a pairing down to its expiry, and when the admin
 * comes back from Kico's tab, reloads the page if the connection changed
 * (paired, cancelled or expired), so it shows where the site stands.
 */
( function () {
	'use strict';

	var l10n = window.kicoAdmin || {};
	var timers = document.querySelectorAll( '[data-kico-expires]' );
	var page = document.querySelector( '.kico[data-kico-state]' );

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	if ( timers.length ) {
		// The server's clock decides; this browser's may be off.
		var skew = parseInt( timers[ 0 ].getAttribute( 'data-kico-now' ), 10 ) * 1000 - Date.now();
		var tick = function () {
			var now = Date.now() + skew;
			var live = 0;
			timers.forEach( function ( el ) {
				var left = Math.round( ( parseInt( el.getAttribute( 'data-kico-expires' ), 10 ) * 1000 - now ) / 1000 );
				if ( left <= 0 ) {
					el.textContent = l10n.expired || '';
					el.classList.add( 'is-expired' );
					return;
				}
				live++;
				el.textContent = ( l10n.expiresIn || '%s' ).replace( '%s', Math.floor( left / 60 ) + ':' + pad( left % 60 ) );
			} );
			if ( live ) {
				window.setTimeout( tick, 1000 - ( now % 1000 ) );
			}
		};
		tick();
	}

	if ( page && window.fetch ) {
		var state = page.getAttribute( 'data-kico-state' );
		document.addEventListener( 'visibilitychange', function () {
			if ( 'visible' !== document.visibilityState ) {
				return;
			}
			window.fetch( window.location.href, { credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.ok ? r.text() : '';
				} )
				.then( function ( html ) {
					var m = html && html.match( /data-kico-state="([^"]*)"/ );
					if ( m && m[ 1 ] !== state ) {
						window.location.reload();
					}
				} )
				.catch( function () {} );
		} );
	}
}() );
