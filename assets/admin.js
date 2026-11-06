/**
 * BCA Media Cleaner : pilotage du scan par lots depuis la page d'administration.
 */
( function () {
	'use strict';

	var data = window.BCAMC || {};
	var t = data.i18n || {};
	var box = document.getElementById( 'bcamc-scan' );
	if ( ! box ) {
		return;
	}

	var progress = box.querySelector( '.bcamc-progress' );
	var bar = box.querySelector( '.bcamc-progress-bar' );
	var fill = bar ? bar.querySelector( 'span' ) : null;
	var label = box.querySelector( '.bcamc-progress-label' );
	var buttons = box.querySelectorAll( '[data-bcamc]' );
	var running = false;
	var failures = 0;

	function setButtons( disabled ) {
		Array.prototype.forEach.call( buttons, function ( b ) {
			b.disabled = disabled;
		} );
	}

	function show( p ) {
		progress.hidden = false;
		var percent = Math.max( 0, Math.min( 100, Number( p.percent ) || 0 ) );
		fill.style.width = percent + '%';
		bar.setAttribute( 'aria-valuenow', String( percent ) );
		label.textContent = p.label ? p.label + ' (' + Math.floor( percent ) + ' %)' : Math.floor( percent ) + ' %';
	}

	function warnOnLeave( e ) {
		if ( running ) {
			e.preventDefault();
			e.returnValue = t.leave || '';
			return e.returnValue;
		}
	}

	function call( op ) {
		var body = new URLSearchParams();
		body.append( 'action', 'bcamc_scan' );
		body.append( 'nonce', data.nonce );
		body.append( 'op', op );

		return fetch( data.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json().then( function ( json ) {
				if ( ! r.ok || ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || 'HTTP ' + r.status );
				}
				return json.data;
			} );
		} );
	}

	function loop( op ) {
		call( op ).then( function ( p ) {
			failures = 0;
			show( p );
			if ( 'running' === p.status ) {
				loop( 'step' );
				return;
			}
			running = false;
			label.textContent = t.done || '';
			window.location.reload();
		} ).catch( function ( err ) {
			// Une erreur passagère (délai dépassé, réseau) : on réessaie quelques fois.
			failures++;
			if ( failures <= 3 ) {
				window.setTimeout( function () {
					loop( 'step' );
				}, 2000 * failures );
				return;
			}
			running = false;
			setButtons( false );
			label.textContent = ( t.error || '' ) + ' (' + err.message + ')';
		} );
	}

	Array.prototype.forEach.call( buttons, function ( button ) {
		button.addEventListener( 'click', function () {
			var op = button.getAttribute( 'data-bcamc' );
			if ( 'start' === op && button.hasAttribute( 'data-confirm' ) && ! window.confirm( t.confirmNew || '' ) ) {
				return;
			}
			running = true;
			failures = 0;
			setButtons( true );
			show( { percent: 'start' === op ? 0 : ( data.progress && data.progress.percent ) || 0, label: t.starting } );
			loop( 'start' === op ? 'start' : 'step' );
		} );
	} );

	window.addEventListener( 'beforeunload', warnOnLeave );
}() );
