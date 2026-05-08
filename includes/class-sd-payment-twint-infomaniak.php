<?php
/**
 * Adapter TWINT via Infomaniak eCommerce API (etickets.infomaniak.com).
 *
 * Flusso redirect (simile a PayPal):
 *  1. create_order() → POST /api/shop/order/create → order_id
 *     → (opz.) POST /api/shop/order/{id}/tickets (aggiunge biglietto configurato)
 *     → GET  /api/shop/order/{id}/payment?mode=twint&url_ok=... → approval_url
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

		$payment_endpoint = add_query_arg(
			array(
				'mode'       => 'twint',
				'url_ok'     => rawurlencode( $return_url ),
				'url_error'  => rawurlencode( $cancel_url ),
				'url_cancel' => rawurlencode( $cancel_url ),
				'locale'     => 'it-CH',
			),
			self::API_BASE . '/order/' . $order_id . '/payment'
		);

		$pay_response = wp_remote_get(
			$payment_endpoint,
			$this->request_args()
		);

		if ( is_wp_error( $pay_response ) ) {
			error_log( '[SD TWINT IK] get payment URL wp_error: ' . $pay_response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $pay_response;
		}

		$pay_code     = (int) wp_remote_retrieve_response_code( $pay_response );
		$pay_body     = wp_remote_retrieve_body( $pay_response );
		$approval_url = trim( $pay_body, "\" \t\n\r" );

		// Alcuni formati di risposta possono essere JSON con una chiave 'url'.
		if ( ! filter_var( $approval_url, FILTER_VALIDATE_URL ) ) {
			$decoded = json_decode( $pay_body, true );
			if ( is_array( $decoded ) && ! empty( $decoded['url'] ) ) {
				$approval_url = (string) $decoded['url'];
			} else {
				error_log( '[SD TWINT IK] get payment URL failed HTTP ' . $pay_code . ': ' . $pay_body ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return new WP_Error(
					'sd_twint_ik_payment_url_failed',
					__( 'Recupero URL pagamento TWINT non riuscito.', 'sd-logbook' )
				);
			}
		}

		return array(
			'order_id'     => (string) $order_id,
			'approval_url' => $approval_url,
		);
	}

	/**
	 * Associa i dati cliente a un ordine Infomaniak.
	 *
	 * Nota: il naming dei campi varia tra versioni API, quindi proviamo payload
	 * compatibili e endpoint alternativi.
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

		if ( '' === $first_name && '' === $last_name ) {
			$local      = sanitize_text_field( strtok( $email, '@' ) );
			$first_name = '' !== $local ? $local : 'Cliente';
		}

		$payload_candidates = array(
			array(
				'firstname' => $first_name,
				'lastname'  => $last_name,
				'email'     => $email,
				'phone'     => $phone,
			),
			array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'email'      => $email,
				'phone'      => $phone,
			),
			array(
				'name'  => trim( $first_name . ' ' . $last_name ),
				'email' => $email,
				'phone' => $phone,
			),
		);

		$endpoints = array(
			self::API_BASE . '/order/' . rawurlencode( $order_id ) . '/customer',
			self::API_BASE . '/order/' . rawurlencode( $order_id ) . '/client',
		);

		$attempts = array();
		foreach ( $endpoints as $endpoint ) {
			foreach ( $payload_candidates as $payload ) {
				$payload = array_filter(
					$payload,
					static function ( $value ) {
						return '' !== (string) $value;
					}
				);
				$resp    = wp_remote_post(
					$endpoint,
					$this->request_args(
						array(
							'headers' => array_merge(
								$this->get_headers(),
								array( 'Content-Type' => 'application/json' )
							),
							'body'    => wp_json_encode( $payload ),
						)
					)
				);

				if ( is_wp_error( $resp ) ) {
					$attempts[] = array(
						'endpoint' => $endpoint,
						'error'    => $resp->get_error_message(),
					);
					continue;
				}

				$code       = (int) wp_remote_retrieve_response_code( $resp );
				$body       = wp_remote_retrieve_body( $resp );
				$attempts[] = array(
					'endpoint' => $endpoint,
					'code'     => $code,
					'body'     => substr( (string) $body, 0, 300 ),
				);
				if ( $code >= 200 && $code < 300 ) {
					return true;
				}
			}
		}

		return new WP_Error(
			'sd_twint_ik_customer_attach_failed',
			__( 'Associazione cliente Infomaniak non riuscita.', 'sd-logbook' ) . ' ' . wp_json_encode( $attempts )
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
