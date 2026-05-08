<?php
/**
 * Adapter TWINT Express Checkout.
 *
 * Implementa il flusso TWINT eCommerce standard (compatibile PostFinance/SIX/Datatrans-style):
 *  1. create_order()  → POST /v1/orders  → restituisce orderUuid + token QR
 *  2. get_order()     → GET  /v1/orders/{uuid}  → polling status
 *  3. cancel_order()  → DELETE /v1/orders/{uuid}
 *
 * Autenticazione: Basic Auth (storeUuid:apiKey) su HTTPS.
 * Per certificato client SSL configurare cert_path / cert_password nelle impostazioni.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Payment_Twint extends SD_Payment_Adapter {

	/**
	 * @return string
	 */
	public function get_provider_name() {
		return 'twint';
	}

	/**
	 * Endpoint base API TWINT.
	 *
	 * @return string
	 */
	private function get_api_base() {
		$mode = get_option( 'sd_payment_twint_mode', 'sandbox' );
		if ( 'live' === $mode ) {
			return rtrim( (string) get_option( 'sd_payment_twint_live_api_url', 'https://api.twint.ch/v1' ), '/' );
		}
		return rtrim( (string) get_option( 'sd_payment_twint_sandbox_api_url', 'https://sandbox.twint.ch/v1' ), '/' );
	}

	/**
	 * Header Authorization Basic per TWINT.
	 *
	 * @return string|WP_Error
	 */
	private function get_auth_header() {
		$store_uuid = trim( (string) get_option( 'sd_payment_twint_store_uuid', '' ) );
		$api_key    = trim( (string) get_option( 'sd_payment_twint_api_key', '' ) );

		if ( '' === $store_uuid || '' === $api_key ) {
			return new WP_Error( 'sd_twint_missing_credentials', __( 'Credenziali TWINT non configurate.', 'sd-logbook' ) );
		}

		return 'Basic ' . base64_encode( $store_uuid . ':' . $api_key );
	}

	/**
	 * Argomenti comuni wp_remote_* con eventuale certificato client.
	 *
	 * @param array $extra Args aggiuntivi (method, body, ecc.).
	 * @return array|WP_Error
	 */
	private function request_args( array $extra = array() ) {
		$auth = $this->get_auth_header();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$args = array_merge(
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => $auth,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
			),
			$extra
		);

		// Certificato client SSL opzionale (richiesto da alcuni acquirer CH).
		$cert_path = trim( (string) get_option( 'sd_payment_twint_cert_path', '' ) );
		$cert_pass = trim( (string) get_option( 'sd_payment_twint_cert_password', '' ) );
		if ( '' !== $cert_path && file_exists( $cert_path ) ) {
			$args['sslcertificates'] = $cert_path;
			if ( '' !== $cert_pass ) {
				$args['sslkey'] = $cert_pass;
			}
		}

		return $args;
	}

	/**
	 * Crea un ordine TWINT e restituisce uuid + dati QR.
	 *
	 * @param array $args {
	 *     @type string $reference_id  Riferimento interno (es. "member-42").
	 *     @type float  $amount        Importo in CHF.
	 *     @type string $currency      Valuta (default CHF).
	 *     @type string $description   Descrizione ordine.
	 *     @type string $return_url    URL di ritorno dopo il pagamento.
	 * }
	 * @return array|WP_Error {
	 *     @type string $order_uuid   UUID ordine TWINT.
	 *     @type string $pairing_token Token per QR code.
	 *     @type string $qr_code_svg  SVG del QR (se restituito dall'API).
	 *     @type string $deep_link    Deep link per mobile (twint://).
	 * }
	 */
	public function create_order( array $args ) {
		$cashregister_ref = trim( (string) get_option( 'sd_payment_twint_cashregister_ref', 'SD-LOGBOOK' ) );

		$payload = array(
			'merchantTransactionReference' => sanitize_text_field( (string) ( $args['reference_id'] ?? '' ) ),
			'requestedAmount'              => array(
				'amount'   => number_format( (float) ( $args['amount'] ?? 0 ), 2, '.', '' ),
				'currency' => (string) ( $args['currency'] ?? 'CHF' ),
			),
			'cashRegisterId'               => array(
				'merchantCashRegisterId' => $cashregister_ref,
			),
			'merchantInformation'          => array(
				'merchantName' => sanitize_text_field( (string) get_bloginfo( 'name' ) ),
			),
		);

		if ( ! empty( $args['return_url'] ) ) {
			$payload['confirmationUrl'] = esc_url_raw( (string) $args['return_url'] );
		}

		$request_args = $this->request_args( array(
			'method' => 'POST',
			'body'   => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $request_args ) ) {
			return $request_args;
		}

		$response = wp_remote_post(
			$this->get_api_base() . '/orders',
			$request_args
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[SD TWINT] create_order wp_error: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['orderUuid'] ) ) {
			$msg = ! empty( $body['message'] ) ? (string) $body['message'] : wp_remote_retrieve_body( $response );
			error_log( '[SD TWINT] create_order failed HTTP ' . $code . ': ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error( 'sd_twint_order_failed', __( 'Creazione ordine TWINT non riuscita.', 'sd-logbook' ) . ' (' . esc_html( $msg ) . ')' );
		}

		return array(
			'order_uuid'    => (string) $body['orderUuid'],
			'pairing_token' => (string) ( $body['pairingToken'] ?? $body['orderUuid'] ),
			'qr_code_svg'   => (string) ( $body['qrCode']['data'] ?? '' ),
			'deep_link'     => (string) ( $body['links']['pay'] ?? '' ),
			'payload'       => $body,
		);
	}

	/**
	 * Recupera lo stato di un ordine TWINT.
	 *
	 * @param string $order_uuid UUID ordine.
	 * @return array|WP_Error {
	 *     @type string $status   IN_PROGRESS | SUCCESS | FAILURE | REVERSED.
	 *     @type array  $payload  Risposta completa.
	 * }
	 */
	public function get_order( string $order_uuid ) {
		$order_uuid   = sanitize_text_field( $order_uuid );
		$request_args = $this->request_args();

		if ( is_wp_error( $request_args ) ) {
			return $request_args;
		}

		$response = wp_remote_get(
			$this->get_api_base() . '/orders/' . rawurlencode( $order_uuid ),
			$request_args
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['orderUuid'] ) ) {
			return new WP_Error( 'sd_twint_status_failed', __( 'Recupero stato ordine TWINT non riuscito.', 'sd-logbook' ) );
		}

		return array(
			'order_uuid' => (string) $body['orderUuid'],
			'status'     => (string) ( $body['status'] ?? 'UNKNOWN' ),
			'payload'    => $body,
		);
	}

	/**
	 * Annulla un ordine TWINT in sospeso.
	 *
	 * @param string $order_uuid UUID ordine.
	 * @return true|WP_Error
	 */
	public function cancel_order( string $order_uuid ) {
		$order_uuid   = sanitize_text_field( $order_uuid );
		$request_args = $this->request_args( array( 'method' => 'DELETE' ) );

		if ( is_wp_error( $request_args ) ) {
			return $request_args;
		}

		$response = wp_remote_request(
			$this->get_api_base() . '/orders/' . rawurlencode( $order_uuid ),
			$request_args
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
}
