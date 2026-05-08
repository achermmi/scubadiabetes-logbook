/* global sdTwintData */
( function () {
	'use strict';

	var POLL_INTERVAL_MS = 3000;   // 3 secondi
	var MAX_POLL_MS      = 600000; // 10 minuti
	var pollTimer        = null;
	var startTime        = Date.now();

	function getStatusContainer() {
		return document.getElementById( 'sd-twint-polling-status' );
	}

	function showMessage( msg, isError ) {
		var el = getStatusContainer();
		if ( ! el ) {
			return;
		}
		el.innerHTML = msg;
		if ( isError ) {
			el.style.color = '#c00';
		}
	}

	function stopPolling() {
		if ( pollTimer ) {
			clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	function poll() {
		if ( Date.now() - startTime > MAX_POLL_MS ) {
			stopPolling();
			showMessage(
				'<span style="color:#c00;">&#x26A0; Timeout: nessuna risposta entro 10 minuti. ' +
				'<a href="' + window.location.href + '">Riprova</a> oppure scegli un altro metodo.</span>',
				true
			);
			return;
		}

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', sdTwintData.ajaxUrl, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

		xhr.onreadystatechange = function () {
			if ( 4 !== xhr.readyState ) {
				return;
			}

			if ( 200 !== xhr.status ) {
				// Errore HTTP: riprova comunque dopo l'intervallo.
				pollTimer = setTimeout( poll, POLL_INTERVAL_MS );
				return;
			}

			var data;
			try {
				data = JSON.parse( xhr.responseText );
			} catch ( e ) {
				pollTimer = setTimeout( poll, POLL_INTERVAL_MS );
				return;
			}

			if ( ! data.success ) {
				stopPolling();
				showMessage(
					'<span style="color:#c00;">&#x26A0; ' +
					( ( data.data && data.data.message ) ? data.data.message : 'Errore TWINT.' ) +
					'</span>',
					true
				);
				return;
			}

			var status = data.data && data.data.status ? data.data.status : 'IN_PROGRESS';

			if ( 'SUCCESS' === status ) {
				stopPolling();
				showMessage( '&#x2705; Pagamento confermato! Reindirizzamento...' );
				if ( data.data.redirect_url ) {
					window.location.href = data.data.redirect_url;
				}
				return;
			}

			if ( 'FAILURE' === status ) {
				stopPolling();
				showMessage(
					'<span style="color:#c00;">&#x26A0; Pagamento non riuscito. ' +
					'<a href="' + window.location.href.replace( /&twint_order=[^&]*/, '' ).replace( /\?twint_order=[^&]*&?/, '?' ) + '">Torna al checkout</a>.</span>',
					true
				);
				return;
			}

			// IN_PROGRESS: continua.
			pollTimer = setTimeout( poll, POLL_INTERVAL_MS );
		};

		xhr.send(
			'action=sd_twint_poll' +
			'&nonce=' + encodeURIComponent( sdTwintData.nonce ) +
			'&sdpt='  + encodeURIComponent( sdTwintData.token )
		);
	}

	// Avvia polling solo se il contenitore esiste (cioè siamo in fase QR).
	if ( getStatusContainer() && sdTwintData && sdTwintData.token ) {
		pollTimer = setTimeout( poll, POLL_INTERVAL_MS );
	}
}() );
