<?php
/**
 * Adapter Stripe Checkout.
 *
 * Gestisce Carta di credito/debito, Apple Pay, Google Pay e TWINT (CHF)
 * tramite Stripe Checkout Sessions API. Nessuna dipendenza da SDK esterni:
 * tutte le chiamate usano wp_remote_request().
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Payment_Stripe extends SD_Payment_Adapter {

	/**
	 * @return string
	 */
	public function get_provider_name() {
		return 'stripe';
	}

	/**
	 * Endpoint base Stripe API.
	 *
	 * @return string
	 */
	private function get_api_base() {
		return 'https://api.stripe.com/v1';
	}

	/**
	 * Chiave segreta attiva (sandbox o live).
	 *
	 * @return string
	 */
	private function get_secret_key() {
		$mode = get_option( 'sd_payment_stripe_mode', 'sandbox' );
		return 'live' === $mode
			? trim( (string) get_option( 'sd_payment_stripe_live_secret', '' ) )
			: trim( (string) get_option( 'sd_payment_stripe_sandbox_secret', '' ) );
	}

	/**
	 * Esegue una richiesta all'API Stripe.
	 *
	 * @param string $method   GET o POST.
	 * @param string $endpoint Percorso relativo (es. /checkout/sessions).
	 * @param array  $body     Corpo della richiesta (solo per POST).
	 * @return array|WP_Error
	 */
	private function request( string $method, string $endpoint, array $body = array() ) {
		$key = $this->get_secret_key();
		if ( '' === $key ) {
			return new WP_Error( 'sd_stripe_missing_key', __( 'Chiave segreta Stripe non configurata.', 'sd-logbook' ) );
		}

		$args = array(
			'timeout' => 30,
			'method'  => $method,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $key,
				'Content-Type'   => 'application/x-www-form-urlencoded',
				'Stripe-Version' => '2023-10-16',
			),
		);

		if ( 'POST' === $method && ! empty( $body ) ) {
			$args['body'] = http_build_query( $body );
		}

		$response = wp_remote_request( $this->get_api_base() . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = ! empty( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Errore Stripe sconosciuto.', 'sd-logbook' );
			error_log( '[SD Stripe] HTTP ' . $code . ': ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error( 'sd_stripe_api_error', $msg );
		}

		return (array) $data;
	}

	/**
	 * Crea una Stripe Checkout Session e restituisce l'URL hosted.
	 *
	 * Metodi di pagamento abilitati: carta (card → include automaticamente
	 * Apple Pay e Google Pay se il browser lo supporta) + TWINT (solo CHF).
	 *
	 * @param array $args {
	 *     @type float  $amount         Importo in CHF.
	 *     @type string $currency       Valuta (default chf).
	 *     @type string $description    Descrizione riga ordine.
	 *     @type string $return_url     URL di ritorno dopo pagamento riuscito.
	 *     @type string $cancel_url     URL di ritorno dopo annullamento.
	 *     @type string $customer_email Email pre-compilata nel form Stripe.
	 *     @type string $sd_token       Token pagamento SD (salvato nei metadata).
	 *     @type int    $member_id      ID socio (salvato nei metadata).
	 * }
	 * @return array|WP_Error {
	 *     @type string $session_id   ID sessione Stripe (cs_xxx).
	 *     @type string $approval_url URL checkout hosted Stripe.
	 * }
	 */
	public function create_session( array $args ) {
		$amount_cents = (int) round( (float) ( $args['amount'] ?? 0 ) * 100 );
		$currency     = strtolower( sanitize_text_field( (string) ( $args['currency'] ?? 'chf' ) ) );
		$description  = sanitize_text_field( (string) ( $args['description'] ?? 'Quota sociale ScubaDiabetes' ) );
		$success_url  = esc_url_raw( (string) ( $args['return_url'] ?? home_url( '/' ) ) );
		$cancel_url   = esc_url_raw( (string) ( $args['cancel_url'] ?? home_url( '/' ) ) );
		$email        = sanitize_email( (string) ( $args['customer_email'] ?? '' ) );
		$sd_token     = sanitize_text_field( (string) ( $args['sd_token'] ?? '' ) );
		$member_id    = (int) ( $args['member_id'] ?? 0 );

		$body = array(
			'mode'                                          => 'payment',
			'success_url'                                   => $success_url,
			'cancel_url'                                    => $cancel_url,
			'line_items[0][quantity]'                       => '1',
			'line_items[0][price_data][currency]'           => $currency,
			'line_items[0][price_data][unit_amount]'        => (string) $amount_cents,
			'line_items[0][price_data][product_data][name]' => $description,
			// card include automaticamente Apple Pay e Google Pay.
			// twint solo per CHF (Svizzera).
			'payment_method_types[0]'                       => 'card',
			'payment_method_types[1]'                       => 'twint',
		);

		if ( '' !== $email && is_email( $email ) ) {
			$body['customer_email'] = $email;
		}

		// Salva token e member_id nei metadata per il webhook.
		if ( '' !== $sd_token ) {
			$body['metadata[sd_token]'] = $sd_token;
		}
		if ( $member_id > 0 ) {
			$body['metadata[member_id]'] = (string) $member_id;
		}

		$data = $this->request( 'POST', '/checkout/sessions', $body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['id'] ) || empty( $data['url'] ) ) {
			return new WP_Error( 'sd_stripe_session_missing', __( 'Risposta sessione Stripe non valida.', 'sd-logbook' ) );
		}

		return array(
			'session_id'   => (string) $data['id'],
			'approval_url' => (string) $data['url'],
		);
	}

	/**
	 * Recupera una Checkout Session esistente per verificarne lo stato.
	 *
	 * @param string $session_id ID sessione (cs_xxx).
	 * @return array|WP_Error {
	 *     @type string $session_id           ID sessione.
	 *     @type string $status               open | complete | expired.
	 *     @type string $payment_status       paid | unpaid | no_payment_required.
	 *     @type string $payment_intent       ID PaymentIntent.
	 *     @type array  $payment_method_types Metodi configurati.
	 *     @type int    $amount_total         Importo in centesimi.
	 *     @type string $currency             Valuta.
	 *     @type string $customer_email       Email cliente.
	 *     @type array  $payload              Risposta completa.
	 * }
	 */
	public function retrieve_session( string $session_id ) {
		$session_id = sanitize_text_field( $session_id );
		if ( '' === $session_id ) {
			return new WP_Error( 'sd_stripe_missing_session_id', __( 'Session ID Stripe mancante.', 'sd-logbook' ) );
		}

		$data = $this->request( 'GET', '/checkout/sessions/' . rawurlencode( $session_id ) . '?expand[]=payment_intent' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// payment_intent può essere un oggetto espanso o un semplice ID stringa.
		$pi_raw        = $data['payment_intent'] ?? '';
		$pi_id         = is_array( $pi_raw ) ? (string) ( $pi_raw['id'] ?? '' ) : (string) $pi_raw;
		$pi_used_types = is_array( $pi_raw ) ? (array) ( $pi_raw['payment_method_types'] ?? array() ) : array();

		return array(
			'session_id'            => (string) ( $data['id'] ?? '' ),
			'status'                => (string) ( $data['status'] ?? '' ),
			'payment_status'        => (string) ( $data['payment_status'] ?? '' ),
			'payment_intent'        => $pi_id,
			'payment_intent_types'  => $pi_used_types,
			'payment_method_types'  => (array) ( $data['payment_method_types'] ?? array() ),
			'amount_total'         => (int) ( $data['amount_total'] ?? 0 ),
			'currency'             => (string) ( $data['currency'] ?? '' ),
			'customer_email'       => (string) ( $data['customer_details']['email'] ?? '' ),
			'metadata'             => (array) ( $data['metadata'] ?? array() ),
			'payload'              => $data,
		);
	}

	/**
	 * Verifica la firma di un webhook Stripe.
	 *
	 * @param string $payload    Corpo grezzo della richiesta HTTP.
	 * @param string $sig_header Valore dell'header Stripe-Signature.
	 * @return array|WP_Error Evento Stripe decodificato.
	 */
	public function verify_webhook( string $payload, string $sig_header ) {
		$secret = trim( (string) get_option( 'sd_payment_stripe_webhook_secret', '' ) );
		if ( '' === $secret ) {
			return new WP_Error( 'sd_stripe_webhook_no_secret', __( 'Webhook secret Stripe non configurato.', 'sd-logbook' ) );
		}

		// Estrai timestamp e firma dall'header Stripe-Signature.
		$parts = array();
		foreach ( explode( ',', $sig_header ) as $chunk ) {
			$kv = explode( '=', trim( $chunk ), 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ trim( $kv[0] ) ] = trim( $kv[1] );
			}
		}

		$timestamp = isset( $parts['t'] ) ? (int) $parts['t'] : 0;
		$signature = $parts['v1'] ?? '';

		if ( 0 === $timestamp || '' === $signature ) {
			return new WP_Error( 'sd_stripe_webhook_malformed', __( 'Header Stripe-Signature malformato.', 'sd-logbook' ) );
		}

		// Tolleranza temporale: 5 minuti.
		if ( abs( time() - $timestamp ) > 300 ) {
			return new WP_Error( 'sd_stripe_webhook_expired', __( 'Webhook Stripe scaduto (timestamp troppo vecchio).', 'sd-logbook' ) );
		}

		$signed_payload = $timestamp . '.' . $payload;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'sd_stripe_webhook_invalid_sig', __( 'Firma webhook Stripe non valida.', 'sd-logbook' ) );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_Error( 'sd_stripe_webhook_invalid_event', __( 'Payload evento Stripe non valido.', 'sd-logbook' ) );
		}

		return $event;
	}
}
