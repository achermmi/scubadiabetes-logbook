<?php
/**
 * Adapter TWINT via Infomaniak.
 *
 * Nota: il flusso TWINT reale Infomaniak usa il checkout hosted su endpoint
 * /shop/{SHOP_CODE}/... e non l'endpoint privato /api/shop/order/{id}/payment.
 *
 * Questo adapter usa quindi un fallback operativo: redirect al checkout hosted
 * (shop/event page), lasciando al tunnel Infomaniak la gestione completa del
 * pagamento TWINT.
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
	 * Prepara il redirect verso checkout hosted Infomaniak.
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
	 *     @type string $order_id     Vuoto nel fallback hosted checkout.
	 *     @type string $approval_url URL checkout/evento Infomaniak.
	 * }
	 */
	public function create_order( array $args ) {
		$shop_code = $this->get_shop_code();
		if ( '' === $shop_code ) {
			return new WP_Error(
				'sd_twint_ik_missing_shop_code',
				__( 'Shop Code Infomaniak non configurato. Inserisci il codice shop (es. S9S93UKTS6) nelle impostazioni pagamento.', 'sd-logbook' )
			);
		}

		$event_id = (int) get_option( 'sd_payment_twint_ik_event_id', 0 );
		$url      = 'https://etickets.infomaniak.com/shop/' . rawurlencode( $shop_code ) . '/checkout';

		// Hint informativo: usato solo lato debug/log, non crea automaticamente la resa.
		if ( $event_id > 0 ) {
			$url = add_query_arg(
				array(
					'event_id' => $event_id,
				),
				$url
			);
		}

		return array(
			'order_id'          => '',
			'approval_url'      => $url,
			'external_checkout' => true,
		);
	}

	/**
	 * Verifica server-side il ritorno TWINT hosted via ref/resa.
	 *
	 * @param string $ref  Riferimento Infomaniak (query param ref).
	 * @param string $resa UUID prenotazione Infomaniak (query param resa).
	 * @return array|WP_Error
	 */
	public function verify_hosted_return( string $ref, string $resa ) {
		$shop_code = $this->get_shop_code();
		if ( '' === $shop_code ) {
			return new WP_Error(
				'sd_twint_ik_missing_shop_code',
				__( 'Shop Code Infomaniak non configurato.', 'sd-logbook' )
			);
		}

		$ref  = preg_replace( '/[^A-Za-z0-9]/', '', (string) $ref );
		$resa = strtolower( sanitize_text_field( (string) $resa ) );

		if ( '' === $ref || '' === $resa ) {
			return new WP_Error(
				'sd_twint_ik_missing_ref_resa',
				__( 'Parametri ref/resa mancanti nel ritorno TWINT.', 'sd-logbook' )
			);
		}

		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $resa ) ) {
			return new WP_Error(
				'sd_twint_ik_invalid_resa',
				__( 'Formato resa non valido.', 'sd-logbook' )
			);
		}

		$success_url = add_query_arg(
			array(
				'ref'  => $ref,
				'resa' => $resa,
			),
			'https://etickets.infomaniak.com/shop/' . rawurlencode( $shop_code ) . '/payment/twint/success'
		);

		$resp = wp_remote_get(
			$success_url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Accept-Language' => 'it_IT',
				),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code     = (int) wp_remote_retrieve_response_code( $resp );
		$location = (string) wp_remote_retrieve_header( $resp, 'location' );
		$body     = (string) wp_remote_retrieve_body( $resp );

		if ( 302 === $code ) {
			return array(
				'valid'    => true,
				'ref'      => $ref,
				'resa'     => $resa,
				'code'     => $code,
				'location' => $location,
				'url'      => $success_url,
			);
		}

		return new WP_Error(
			'sd_twint_ik_hosted_not_confirmed',
			__( 'Conferma pagamento TWINT non verificata sul ritorno hosted Infomaniak.', 'sd-logbook' ) . ' HTTP ' . $code . ' ' . substr( preg_replace( '/\s+/', ' ', $body ), 0, 180 )
		);
	}

	/**
	 * Restituisce Shop Code Infomaniak in formato pulito.
	 *
	 * @return string
	 */
	private function get_shop_code() {
		$shop_code = strtoupper( trim( (string) get_option( 'sd_payment_twint_ik_shop_code', '' ) ) );
		$shop_code = preg_replace( '/[^A-Z0-9]/', '', $shop_code );
		if ( '' !== $shop_code ) {
			return (string) $shop_code;
		}

		$legacy_shop_id = (int) get_option( 'sd_payment_twint_ik_shop_id', 0 );
		if ( $legacy_shop_id > 0 ) {
			return (string) $legacy_shop_id;
		}

		return '';
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

		$config_mode     = trim( (string) get_option( 'sd_payment_twint_ik_mode', 'TWINT' ) );
		$mode_candidates = array(
			$config_mode,
			'TWINT',
			'twint',
			'TWINT_QR',
			'twint_qr',
			'TWINT_DIRECT',
			'twint_direct',
		);
		$mode_candidates = array_values( array_unique( array_filter( $mode_candidates ) ) );

		$config_default_param = trim( (string) get_option( 'sd_payment_twint_ik_default_url_param', 'url_default' ) );
		$default_url_params   = array(
			$config_default_param,
			'url_default',
			'url',
			'url_return',
			'return_url',
		);
		$default_url_params   = array_values( array_unique( array_filter( $default_url_params ) ) );

		$attempts              = array();
		$found_invalid_mode    = false;
		$found_missing_default = false;

		foreach ( $mode_candidates as $mode ) {
			$tried_fallback_default_params = false;

			foreach ( $default_url_params as $index => $default_param ) {
				if ( $index > 0 && $tried_fallback_default_params ) {
					break;
				}

				$query = array(
					'mode'         => $mode,
					'url_ok'       => $return_url,
					'url_error'    => $cancel_url,
					'url_cancel'   => $cancel_url,
					'locale'       => 'it-CH',
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

				$error_message = $this->extract_api_error_message( $body );
				if ( $this->contains_any( $error_message, array( 'modalit', 'mode de paiement', 'payment mode' ) ) ) {
					$found_invalid_mode = true;
				}
				if ( $this->contains_any( $error_message, array( 'url predefinito', 'url par defaut', 'url par d', 'default url' ) ) ) {
					$found_missing_default = true;
					// Prova i fallback solo se l'API dice esplicitamente che manca la URL di default.
					$tried_fallback_default_params = true;
				} else {
					// Se l'errore non riguarda la URL default, non insistiamo con altri param names.
					$tried_fallback_default_params = false;
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

		if ( $found_invalid_mode ) {
			return new WP_Error(
				'sd_twint_ik_invalid_mode',
				__( 'Infomaniak ha rifiutato il mode di pagamento configurato. Verifica che TWINT sia abilitato nel tunnel checkout dello shop/evento e imposta il mode esatto richiesto.', 'sd-logbook' )
			);
		}

		if ( $found_missing_default ) {
			return new WP_Error(
				'sd_twint_ik_missing_default_url',
				__( 'Infomaniak richiede il parametro URL di default (tipicamente url_default). Verifica il nome parametro nelle impostazioni.', 'sd-logbook' )
			);
		}

		return new WP_Error(
			'sd_twint_ik_payment_url_failed',
			__( 'Recupero URL pagamento TWINT non riuscito. Verifica mode e parametro URL default nelle opzioni Infomaniak.', 'sd-logbook' )
		);
	}

	/**
	 * Estrae il messaggio errore dalla risposta API.
	 *
	 * @param string $body Corpo risposta.
	 * @return string
	 */
	private function extract_api_error_message( $body ) {
		$body = (string) $body;
		$data = json_decode( $body, true );
		if ( is_array( $data ) && ! empty( $data['error'] ) ) {
			return strtolower( (string) $data['error'] );
		}
		return strtolower( $body );
	}

	/**
	 * True se il testo contiene almeno uno dei frammenti passati.
	 *
	 * @param string $haystack Testo da cercare.
	 * @param array  $needles  Frammenti.
	 * @return bool
	 */
	private function contains_any( $haystack, array $needles ) {
		$haystack = strtolower( (string) $haystack );
		foreach ( $needles as $needle ) {
			$needle = strtolower( (string) $needle );
			if ( '' !== $needle && false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
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
