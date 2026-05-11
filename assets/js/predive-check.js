/**
 * JS: Semaforo Pre-Immersione
 *
 * Recupera la valutazione via AJAX (sd_predive_evaluate) e aggiorna il DOM.
 * Rinfresca automaticamente ogni 5 minuti.
 *
 * Dipendenze: jQuery, sdPredive (localizzato da SD_Predive_Check::enqueue_assets)
 *
 * @package SD_Logbook
 */

/* global sdPredive */

( function ( $ ) {
	'use strict';

	var REFRESH_MS  = 5 * 60 * 1000; // 5 minuti
	var $widget     = $( '#sd-predive-widget' );
	var refreshTimer = null;

	if ( ! $widget.length ) {
		return;
	}

	// ================================================================
	// RIFERIMENTI DOM
	// ================================================================

	var $loading       = $( '#sd-predive-loading' );
	var $body          = $( '#sd-predive-body' );
	var $error         = $( '#sd-predive-error' );
	var $lightRed      = $( '#sd-light-red' );
	var $lightYellow   = $( '#sd-light-yellow' );
	var $lightGreen    = $( '#sd-light-green' );
	var $glucose       = $( '#sd-predive-glucose' );
	var $arrow         = $( '#sd-predive-arrow' );
	var $age           = $( '#sd-predive-age' );
	var $statusLabel   = $( '#sd-predive-status-label' );
	var $recommendation = $( '#sd-predive-recommendation' );
	var $alerts        = $( '#sd-predive-alerts' );
	var $timestamp     = $( '#sd-predive-timestamp' );
	var $refresh       = $( '#sd-predive-refresh' );

	// ================================================================
	// STATO LUCI
	// ================================================================

	var statusConfig = {
		red: {
			label: '🚫 Non immergerti',
			labelClass: 'sd-predive-status-red',
			wrapClass:  'sd-predive-red',
			lights:     { red: true, yellow: false, green: false },
		},
		yellow: {
			label: '⚠️ Procedi con cautela',
			labelClass: 'sd-predive-status-yellow',
			wrapClass:  'sd-predive-yellow',
			lights:     { red: false, yellow: true, green: false },
		},
		green: {
			label: '✅ Ok per immergerti',
			labelClass: 'sd-predive-status-green',
			wrapClass:  'sd-predive-green',
			lights:     { red: false, yellow: false, green: true },
		},
	};

	// ================================================================
	// ICONE SVG AVVISI
	// ================================================================

	var alertIcons = {
		danger: '<svg class="sd-alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
		warning: '<svg class="sd-alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
		success: '<svg class="sd-alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
		info: '<svg class="sd-alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
	};

	// ================================================================
	// VALUTAZIONE
	// ================================================================

	function evaluate() {
		$loading.show();
		$body.hide();
		$error.hide();
		$refresh.prop( 'disabled', true );

		$.ajax( {
			url:    sdPredive.ajaxUrl,
			method: 'POST',
			data:   {
				action: 'sd_predive_evaluate',
				nonce:  sdPredive.nonce,
			},
			success: function ( res ) {
				$loading.hide();
				$refresh.prop( 'disabled', false );

				if ( ! res.success ) {
					showError( res.data && res.data.message ? res.data.message : sdPredive.strings.error );
					return;
				}

				render( res.data );
			},
			error: function () {
				$loading.hide();
				$refresh.prop( 'disabled', false );
				showError( sdPredive.strings.error );
			},
		} );
	}

	// ================================================================
	// RENDER
	// ================================================================

	function render( data ) {
		var status = data.status || 'red';
		var cfg    = statusConfig[ status ] || statusConfig.red;

		// Semaforo luci
		$lightRed.toggleClass(    'sd-active', cfg.lights.red );
		$lightYellow.toggleClass( 'sd-active', cfg.lights.yellow );
		$lightGreen.toggleClass(  'sd-active', cfg.lights.green );

		// Classe wrapper per tematizzare i colori della raccomandazione
		$widget
			.removeClass( 'sd-predive-red sd-predive-yellow sd-predive-green' )
			.addClass( cfg.wrapClass );

		// Valore glicemia
		if ( data.glucose_display ) {
			$glucose.text( data.glucose_display + ' ' + data.unit );
		} else {
			$glucose.text( '—' );
		}

		// Freccia trend
		$arrow.text( data.arrow || '—' );

		// Età lettura
		$age.text( formatAge( data.last_min ) );

		// Etichetta stato
		if ( data.has_cgm ) {
			$statusLabel
				.text( cfg.label )
				.attr( 'class', 'sd-predive-status-label ' + cfg.labelClass )
				.show();
		} else {
			$statusLabel
				.text( '' )
				.attr( 'class', 'sd-predive-status-label' )
				.hide();
		}

		// Raccomandazione
		if ( data.recommendation ) {
			$recommendation.text( data.recommendation ).show();
		} else {
			$recommendation.text( '' ).hide();
		}

		// Avvisi
		$alerts.empty();
		if ( data.alerts && data.alerts.length ) {
			$.each( data.alerts, function ( i, alert ) {
				var level = alert.level || 'info';
				var icon  = alertIcons[ level ] || alertIcons.info;
				var $li   = $( '<li>' )
					.addClass( 'sd-alert-' + level )
					.html( icon + '<span>' + $( '<span>' ).text( alert.text ).html() + '</span>' );
				$alerts.append( $li );
			} );
		}

		// Timestamp valutazione
		var now = new Date();
		$timestamp.text( 'Valutato: ' + now.toLocaleTimeString( 'it-IT', { hour: '2-digit', minute: '2-digit' } ) );

		$body.show();

		// Pianifica prossimo refresh automatico
		scheduleRefresh();
	}

	// ================================================================
	// HELPERS
	// ================================================================

	function formatAge( minutes ) {
		if ( null === minutes || undefined === minutes ) {
			return '';
		}
		if ( minutes < 2 ) {
			return 'adesso';
		}
		if ( minutes < 60 ) {
			return minutes + ' ' + sdPredive.strings.minAgo;
		}
		var h = Math.floor( minutes / 60 );
		return h + ' ' + ( 1 === h ? sdPredive.strings.hourAgo : sdPredive.strings.hoursAgo );
	}

	function showError( msg ) {
		$error.text( msg ).show();
		$body.hide();
	}

	function scheduleRefresh() {
		if ( refreshTimer ) {
			clearTimeout( refreshTimer );
		}
		refreshTimer = setTimeout( evaluate, REFRESH_MS );
	}

	// ================================================================
	// EVENTI
	// ================================================================

	$refresh.on( 'click', function () {
		evaluate();
	} );

	// ================================================================
	// AVVIO
	// ================================================================

	evaluate();

}( jQuery ) );
