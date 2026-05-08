<?php
/**
 * Adapter PayPal Orders API.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Payment_PayPal extends SD_Payment_Adapter {

	/**
	 * @return string
	 */
	public function get_provider_name() {
		return 'paypal';
	}

	/**
	 * Restituisce endpoint base API PayPal.
	 *
	 * @return string
	 */
	private function get_api_base() {
		$mode = get_option( 'sd_payment_paypal_mode', 'sandbox' );
		return 'live' === $mode ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
	}

	/**
	 * Ottiene access token OAuth2 da PayPal.
	 *
	 * @return string|WP_Error
	 */
	private function get_access_token() {
		$client_id = trim( (string) get_option( 'sd_payment_paypal_client_id', '' ) );
		$secret    = trim( (string) get_option( 'sd_payment_paypal_secret', '' ) );

		if ( '' === $client_id || '' === $secret ) {
			return new WP_Error( 'sd_paypal_missing_credentials', __( 'Credenziali PayPal non configurate.', 'sd-logbook' ) );
		}

		$response = wp_remote_post(
			$this->get_api_base() . '/v1/oauth2/token',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ),
				),
				'body'    => array(
					'grant_type' => 'client_credentials',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
			error_log( '[SD PayPal] auth failed – HTTP ' . $code . ' – ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error( 'sd_paypal_auth_failed', __( 'Autenticazione PayPal non riuscita.', 'sd-logbook' ) );
		}

		return (string) $body['access_token'];
	}

	/**
	 * Crea un ordine PayPal e ritorna approval URL + order id.
	 *
	 * @param array $args Dati ordine.
	 * @return array|WP_Error
	 */
	public function create_order( $args ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$payload = array(
			'intent'              => 'CAPTURE',
			'purchase_units'      => array(
				array(
					'reference_id' => (string) ( $args['reference_id'] ?? '' ),
					'description'  => (string) ( $args['description'] ?? 'Quota associativa ScubaDiabetes' ),
					'amount'       => array(
						'currency_code' => (string) ( $args['currency'] ?? 'CHF' ),
						'value'         => number_format( (float) ( $args['amount'] ?? 0 ), 2, '.', '' ),
					),
				),
			),
			'application_context' => array(
				'brand_name'  => (string) get_bloginfo( 'name' ),
				'user_action' => 'PAY_NOW',
				'return_url'  => (string) ( $args['return_url'] ?? home_url( '/' ) ),
				'cancel_url'  => (string) ( $args['cancel_url'] ?? home_url( '/' ) ),
			),
		);

		$response = wp_remote_post(
			$this->get_api_base() . '/v2/checkout/orders',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['id'] ) ) {
			return new WP_Error( 'sd_paypal_order_failed', __( 'Creazione ordine PayPal non riuscita.', 'sd-logbook' ) );
		}

		$approval_url = '';
		if ( ! empty( $body['links'] ) && is_array( $body['links'] ) ) {
			foreach ( $body['links'] as $link ) {
				if ( isset( $link['rel'] ) && 'approve' === $link['rel'] && ! empty( $link['href'] ) ) {
					$approval_url = (string) $link['href'];
					break;
				}
			}
		}

		if ( '' === $approval_url ) {
			return new WP_Error( 'sd_paypal_missing_approval_url', __( 'URL di approvazione PayPal non disponibile.', 'sd-logbook' ) );
		}

		return array(
			'order_id'     => (string) $body['id'],
			'approval_url' => $approval_url,
		);
	}

	/**
	 * Cattura un ordine approvato da PayPal.
	 *
	 * @param string $order_id ID ordine PayPal.
	 * @return array|WP_Error
	 */
	public function capture_order( $order_id ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			$this->get_api_base() . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['status'] ) ) {
			return new WP_Error( 'sd_paypal_capture_failed', __( 'Cattura pagamento PayPal non riuscita.', 'sd-logbook' ) );
		}

		return array(
			'order_id' => (string) $order_id,
			'status'   => (string) $body['status'],
			'payload'  => $body,
		);
	}
}
