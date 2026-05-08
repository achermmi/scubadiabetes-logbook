<?php
/**
 * Adapter TWINT via Infomaniak eCommerce API (etickets.infomaniak.com).
 *
 * Flusso redirect (simile a PayPal):
 *  1. create_order() → POST /api/shop/order/create → order_id
 *  2. customer lookup/create → POST /api/shop/customer/login oppure /customer/create
 *  3. attach customer      → POST /api/shop/order/{id}/customer {customer_id}
 *  4. payment URL          → GET  /api/shop/order/{id}/payment?... → approval_url
 *  2. get_order()    → GET /api/shop/order/{id} → status ('paid' = successo)
 *  3. cancel_order() → DELETE /api/shop/order/{id}
 *
 * Autenticazione: header `key: <API_KEY>` (generata in Infomaniak manager →
 * Shop / Disponibilità online → Accesso API).
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Payment_Twint_Infomaniak extends SD_Payment_Adapter {

	const API_BASE = 'https://etickets.infomaniak.com/api/shop';

	/**
	 * @return string
	 */
	public function get_provider_name() {
		return 'twint';
	}

	/**
	 * Header comuni per ogni richiesta.
	 *
	 * @return array
	 */
	private function get_headers() {
		return array(
			'key'             => trim( (string) get_option( 'sd_payment_twint_ik_key', '' ) ),
			'currency'        => '1', // 1 = CHF.
			'Accept-Language' => 'it_IT',
		);
	}

	/**
	 * Argomenti comuni wp_remote_*.
	 *
	 * @param array $extra Args aggiuntivi (method, body, ecc.).
	 * @return array
	 */
	private function request_args( array $extra = array() ) {
		return array_merge(
			array(
				'timeout' => 30,
				'headers' => $this->get_headers(),
			),
			$extra
		);
	}

	/**
	 * Crea un ordine Infomaniak e restituisce order_id + URL di pagamento TWINT.
	 *
	 * @param array $args {
	 *     @type float  $amount      Importo in CHF (informativo, non inviato all'API).
	 *     @type string $return_url  URL di conferma successo (url_ok).
	 *     @type string $cancel_url  URL di annullamento/errore (url_error / url_cancel).
	 *     @type array  $customer    Dati cliente (first_name, last_name, email, phone).
	 * }
	 * Richiede che sd_payment_twint_ik_category_id sia configurato (ID categoria
	 * biglietto di un evento Infomaniak con periodo di vendita attivo).
	 * @return array|WP_Error {
	 *     @type string $order_id     ID ordine Infomaniak (numerico come stringa).
	 *     @type string $approval_url URL della pagina di pagamento TWINT di Infomaniak.
	 * }
	 */
	public function create_order( array $args ) {
		$api_key = trim( (string) get_option( 'sd_payment_twint_ik_key', '' ) );
		if ( '' === $api_key ) {
			return new WP_Error(
				'sd_twint_ik_missing_key',
				__( 'API Key Infomaniak non configurata.', 'sd-logbook' )
			);
		}

		// Seleziona category_id in base all'importo (30 / 50 / 75 CHF).
		$amount      = (float) ( $args['amount'] ?? 0.0 );
		$amount_key  = (int) round( $amount );
		$category_id = (int) get_option( 'sd_payment_twint_ik_category_id_' . $amount_key, 0 );

		// Fallback: primo tier configurato tra 30/50/75.
		if ( $category_id <= 0 ) {
			foreach ( array( 30, 50, 75 ) as $_tier ) {
				$fallback = (int) get_option( 'sd_payment_twint_ik_category_id_' . $_tier, 0 );
				if ( $fallback > 0 ) {
					$category_id = $fallback;
					break;
				}
			}
		}

		if ( $category_id <= 0 ) {
			return new WP_Error(
				'sd_twint_ik_missing_category',
				__( 'Category ID Infomaniak non configurato. Configura i Category ID nelle impostazioni pagamento.', 'sd-logbook' )
			);
		}
		// Crea ordine con biglietto incluso nel body.
		$body = wp_json_encode(
			array(
				array(
					'category_id' => $category_id,
					'count'       => 1,
				),
			)
		);

		$response = wp_remote_post(
			self::API_BASE . '/order/create',
			$this->request_args(
				array(
					'headers' => array_merge(
						$this->get_headers(),
						array( 'Content-Type' => 'application/json' )
					),
					'body'    => $body,
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[SD TWINT IK] create order wp_error: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $response;
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw      = wp_remote_retrieve_body( $response );
		$order_id = trim( $raw, "\" \t\n\r" );

		if ( $code < 200 || $code >= 300 || ! is_numeric( $order_id ) ) {
			error_log( '[SD TWINT IK] create order failed HTTP ' . $code . ': ' . $raw ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error(
				'sd_twint_ik_order_failed',
				__( 'Creazione ordine Infomaniak non riuscita.', 'sd-logbook' )
			);
		}

		// Alcuni shop richiedono un cliente associato all'ordine prima del redirect di pagamento.
		$customer = isset( $args['customer'] ) && is_array( $args['customer'] ) ? $args['customer'] : array();
		if ( ! empty( $customer ) ) {
			$attach = $this->attach_customer_to_order( (string) $order_id, $customer );
			if ( is_wp_error( $attach ) ) {
				error_log( '[SD TWINT IK] attach customer failed: ' . $attach->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return $attach;
			}
		}

		// Ottieni URL pagamento TWINT.
		$return_url = esc_url_raw( (string) ( $args['return_url'] ?? '' ) );
		$cancel_url = esc_url_raw( (string) ( $args['cancel_url'] ?? $return_url ) );

		$approval_url = $this->request_payment_url( (string) $order_id, $return_url, $cancel_url );
		if ( is_wp_error( $approval_url ) ) {
			return $approval_url;
		}

		return array(
			'order_id'     => (string) $order_id,
			'approval_url' => $approval_url,
		);
	}

	/**
	 * Richiede URL di pagamento provando combinazioni di mode/parametro url default.
	 *
	 * @param string $order_id   ID ordine.
	 * @param string $return_url URL successo.
	 * @param string $cancel_url URL annullamento/errore.
	 * @return string|WP_Error
	 */
	private function request_payment_url( $order_id, $return_url, $cancel_url ) {
		$payment_base = self::API_BASE . '/order/' . rawurlencode( (string) $order_id ) . '/payment';

		$config_mode = trim( (string) get_option( 'sd_payment_twint_ik_mode', '' ) );
		$mode_candidates = array(
			'TWINT',
			'twint',
			'TWINT_QR',
			'twint_qr',
			'TWINT_DIRECT',
			'twint_direct',
		);
		if ( '' !== $config_mode ) {
			array_unshift( $mode_candidates, $config_mode );
		}
		$mode_candidates = array_values( array_unique( $mode_candidates ) );

		$config_default_param = trim( (string) get_option( 'sd_payment_twint_ik_default_url_param', '' ) );
		$default_url_params   = array( 'url_default', 'url', 'url_return', 'return_url' );
		if ( '' !== $config_default_param ) {
			array_unshift( $default_url_params, $config_default_param );
		}
		$default_url_params = array_values( array_unique( $default_url_params ) );

		$attempts = array();
		foreach ( $mode_candidates as $mode ) {
			foreach ( $default_url_params as $default_param ) {
				$query = array(
					'mode'       => $mode,
					'url_ok'     => $return_url,
					'url_error'  => $cancel_url,
					'url_cancel' => $cancel_url,
					'locale'     => 'it-CH',
					$default_param => $return_url,
				);

				$endpoint = add_query_arg( $query, $payment_base );
				$resp     = wp_remote_get( $endpoint, $this->request_args() );

				if ( is_wp_error( $resp ) ) {
					$attempts[] = array(
						'mode'  => $mode,
						'param' => $default_param,
						'error' => $resp->get_error_message(),
					);
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code( $resp );
				$body = (string) wp_remote_retrieve_body( $resp );

				$approval_url = trim( $body, "\" \t\n\r" );
				if ( ! filter_var( $approval_url, FILTER_VALIDATE_URL ) ) {
					$decoded = json_decode( $body, true );
					if ( is_array( $decoded ) && ! empty( $decoded['url'] ) ) {
						$approval_url = (string) $decoded['url'];
					}
				}

				if ( $code >= 200 && $code < 300 && filter_var( $approval_url, FILTER_VALIDATE_URL ) ) {
					return $approval_url;
				}

				$attempts[] = array(
					'mode'  => $mode,
					'param' => $default_param,
					'code'  => $code,
					'body'  => substr( $body, 0, 220 ),
				);
			}
		}

		error_log( '[SD TWINT IK] get payment URL failed: ' . wp_json_encode( $attempts ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return new WP_Error(
			'sd_twint_ik_payment_url_failed',
			__( 'Recupero URL pagamento TWINT non riuscito. Verifica mode e parametro URL default nelle opzioni Infomaniak.', 'sd-logbook' )
		);
	}

	/**
	 * Associa un customer esistente all'ordine (flow ufficiale etickets API).
	 *
	 * @param string $order_id  ID ordine.
	 * @param array  $customer  Dati cliente.
	 * @return true|WP_Error
	 */
	private function attach_customer_to_order( $order_id, array $customer ) {
		$email = sanitize_email( (string) ( $customer['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'sd_twint_ik_missing_customer_email',
				__( 'Email cliente mancante per pagamento TWINT Infomaniak.', 'sd-logbook' )
			);
		}

		$first_name = sanitize_text_field( (string) ( $customer['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $customer['last_name'] ?? '' ) );
		$phone      = sanitize_text_field( (string) ( $customer['phone'] ?? '' ) );

		$customer_id = $this->get_or_create_customer_id( $email, $first_name, $last_name, $phone );
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		$resp = wp_remote_post(
			self::API_BASE . '/order/' . rawurlencode( $order_id ) . '/customer',
			$this->request_args(
				array(
					'headers' => array_merge(
						$this->get_headers(),
						array( 'Content-Type' => 'application/json' )
					),
					'body'    => wp_json_encode(
						array(
							'customer_id' => (int) $customer_id,
						)
					),
				)
			)
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'sd_twint_ik_customer_attach_failed',
				__( 'Associazione cliente Infomaniak non riuscita.', 'sd-logbook' ) . ' ' . substr( $body, 0, 220 )
			);
		}

		return true;
	}

	/**
	 * Ottiene customer_id con login idempotente, altrimenti crea il cliente.
	 *
	 * @param string $email      Email cliente.
	 * @param string $first_name Nome.
	 * @param string $last_name  Cognome.
	 * @param string $phone      Telefono.
	 * @return int|WP_Error
	 */
	private function get_or_create_customer_id( $email, $first_name, $last_name, $phone ) {
		$login_resp = wp_remote_post(
			self::API_BASE . '/customer/login',
			$this->request_args(
				array(
					'headers' => array_merge(
						$this->get_headers(),
						array( 'Content-Type' => 'application/json' )
					),
					'body'    => wp_json_encode( array( 'email' => $email ) ),
				)
			)
		);

		if ( ! is_wp_error( $login_resp ) ) {
			$login_code = (int) wp_remote_retrieve_response_code( $login_resp );
			$login_body = json_decode( (string) wp_remote_retrieve_body( $login_resp ), true );
			if ( $login_code >= 200 && $login_code < 300 && is_array( $login_body ) && ! empty( $login_body['id'] ) ) {
				return (int) $login_body['id'];
			}
		}

		if ( '' === $first_name && '' === $last_name ) {
			$local      = sanitize_text_field( strtok( $email, '@' ) );
			$first_name = '' !== $local ? $local : 'Cliente';
		}

		$create_resp = wp_remote_post(
			self::API_BASE . '/customer/create',
			$this->request_args(
				array(
					'headers' => array_merge(
						$this->get_headers(),
						array( 'Content-Type' => 'application/json' )
					),
					'body'    => wp_json_encode(
						array(
							'firstname' => $first_name,
							'lastname'  => $last_name,
							'email'     => $email,
							'phone'     => $phone,
						)
					),
				)
			)
		);

		if ( is_wp_error( $create_resp ) ) {
			return $create_resp;
		}

		$create_code = (int) wp_remote_retrieve_response_code( $create_resp );
		$create_raw  = (string) wp_remote_retrieve_body( $create_resp );
		$create_body = json_decode( $create_raw, true );
		if ( $create_code >= 200 && $create_code < 300 && is_array( $create_body ) && ! empty( $create_body['id'] ) ) {
			return (int) $create_body['id'];
		}

		// In caso di race su email esistente, riprova login.
		$retry_login = wp_remote_post(
			self::API_BASE . '/customer/login',
			$this->request_args(
				array(
					'headers' => array_merge(
						$this->get_headers(),
						array( 'Content-Type' => 'application/json' )
					),
					'body'    => wp_json_encode( array( 'email' => $email ) ),
				)
			)
		);

		if ( ! is_wp_error( $retry_login ) ) {
			$retry_code = (int) wp_remote_retrieve_response_code( $retry_login );
			$retry_body = json_decode( (string) wp_remote_retrieve_body( $retry_login ), true );
			if ( $retry_code >= 200 && $retry_code < 300 && is_array( $retry_body ) && ! empty( $retry_body['id'] ) ) {
				return (int) $retry_body['id'];
			}
		}

		return new WP_Error(
			'sd_twint_ik_customer_create_failed',
			__( 'Creazione/lookup cliente Infomaniak non riuscita.', 'sd-logbook' ) . ' ' . substr( $create_raw, 0, 220 )
		);
	}

	/**
	 * Recupera lo stato di un ordine Infomaniak.
	 *
	 * @param string $order_id ID ordine Infomaniak.
	 * @return array|WP_Error {
	 *     @type string $status  'paid' | 'prebooked' | 'payment_requested' | 'free' | 'refunded' | 'unbooked' | 'cancelled'
	 *     @type array  $payload Risposta completa.
	 * }
	 */
	public function get_order( string $order_id ) {
		$order_id = sanitize_text_field( $order_id );

		$response = wp_remote_get(
			self::API_BASE . '/order/' . rawurlencode( $order_id ),
			$this->request_args()
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return new WP_Error(
				'sd_twint_ik_status_failed',
				__( 'Recupero stato ordine Infomaniak non riuscito.', 'sd-logbook' )
			);
		}

		return array(
			'status'  => (string) ( $body['status'] ?? 'unknown' ),
			'payload' => $body,
		);
	}

	/**
	 * Annulla un ordine Infomaniak in stato prebooked.
	 *
	 * @param string $order_id ID ordine Infomaniak.
	 * @return true|WP_Error
	 */
	public function cancel_order( string $order_id ) {
		$order_id = sanitize_text_field( $order_id );

		$response = wp_remote_request(
			self::API_BASE . '/order/' . rawurlencode( $order_id ),
			$this->request_args( array( 'method' => 'DELETE' ) )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
}
