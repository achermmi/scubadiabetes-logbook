<?php
/**
 * Flusso di pagamento per le iscrizioni alle attività.
 *
 * Gestisce checkout e conferma per i partecipanti alle attività
 * (separato dal flusso quote sociali in SD_Payment_Flow).
 *
 * Shortcodes:
 * - [sd_activity_payment_checkout]
 * - [sd_activity_payment_confirmation]
 *
 * Gateway supportati: Stripe (carta/TWINT/Apple Pay/Google Pay), PayPal, Fattura.
 * Webhook Stripe: stesso endpoint /?sd_stripe_webhook=1, discriminato da metadata sd_activity_token.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Activity_Payment_Flow {

	/**
	 * @var SD_Payment_Stripe
	 */
	private $stripe;

	/**
	 * @var SD_Payment_PayPal
	 */
	private $paypal;

	public function __construct() {
		$this->stripe = new SD_Payment_Stripe();
		$this->paypal = new SD_Payment_PayPal();

		add_shortcode( 'sd_activity_payment_checkout', array( $this, 'render_checkout' ) );
		add_shortcode( 'sd_activity_payment_confirmation', array( $this, 'render_confirmation' ) );
		add_action( 'template_redirect', array( $this, 'handle_actions' ) );
		add_action( 'init', array( $this, 'handle_stripe_webhook_activity' ) );
		add_action( 'wp_ajax_sd_activity_resend_invoice_email', array( $this, 'ajax_resend_invoice_email' ) );
	}

	// =========================================================================
	// URL helpers
	// =========================================================================

	/**
	 * URL della pagina di checkout attività (opzione o home).
	 *
	 * @return string
	 */
	public function get_checkout_page_url() {
		$url = trim( (string) get_option( 'sd_activity_payment_checkout_url', '' ) );
		return '' !== $url ? $url : home_url( '/iscrizione-attivita-pagamento/' );
	}

	/**
	 * URL della pagina di conferma attività.
	 *
	 * @return string
	 */
	public function get_confirmation_page_url() {
		$url = trim( (string) get_option( 'sd_activity_payment_confirmation_url', '' ) );
		return '' !== $url ? $url : home_url( '/iscrizione-attivita-conferma/' );
	}

	// =========================================================================
	// DB helpers
	// =========================================================================

	/**
	 * Recupera il contesto di pagamento dal token.
	 *
	 * @param string $token Token generato alla registrazione.
	 * @return object|WP_Error
	 */
	public function get_context_by_token( string $token ) {
		global $wpdb;

		if ( '' === $token ) {
			return new WP_Error( 'sd_act_pay_missing_token', __( 'Token mancante.', 'sd-logbook' ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					r.id AS registration_id,
					r.activity_id,
					r.member_id,
					r.email,
					r.first_name,
					r.last_name,
					r.payment_status,
					r.price_chf,
					r.price_eur,
					r.price_id,
					r.confirmation_token,
					r.confirmation_expires_at,
					a.title AS activity_title,
					a.start_date AS activity_start_date,
					p.price_name
				FROM {$wpdb->prefix}sd_activity_registrations r
				LEFT JOIN {$wpdb->prefix}sd_activities a ON a.id = r.activity_id
				LEFT JOIN {$wpdb->prefix}sd_activity_prices p ON p.id = r.price_id
				WHERE r.confirmation_token = %s
				LIMIT 1",
				$token
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'sd_act_pay_invalid_token', __( 'Token non valido o scaduto.', 'sd-logbook' ) );
		}

		if (
			! empty( $row->confirmation_expires_at )
			&& strtotime( $row->confirmation_expires_at ) < time()
		) {
			return new WP_Error( 'sd_act_pay_token_expired', __( 'Il link di pagamento è scaduto. Contatta l\'organizzatore.', 'sd-logbook' ) );
		}

		return $row;
	}

	/**
	 * Recupera il contesto di pagamento da ID registrazione.
	 *
	 * @param int $registration_id ID registrazione.
	 * @return object|WP_Error
	 */
	private function get_context_by_registration_id( int $registration_id ) {
		global $wpdb;

		if ( $registration_id <= 0 ) {
			return new WP_Error( 'sd_act_pay_invalid_registration', __( 'Registrazione non valida.', 'sd-logbook' ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					r.id AS registration_id,
					r.activity_id,
					r.member_id,
					r.email,
					r.first_name,
					r.last_name,
					r.payment_status,
					r.price_chf,
					r.price_eur,
					r.price_id,
					r.confirmation_token,
					r.confirmation_expires_at,
					a.title AS activity_title,
					a.start_date AS activity_start_date,
					p.price_name
				FROM {$wpdb->prefix}sd_activity_registrations r
				LEFT JOIN {$wpdb->prefix}sd_activities a ON a.id = r.activity_id
				LEFT JOIN {$wpdb->prefix}sd_activity_prices p ON p.id = r.price_id
				WHERE r.id = %d
				LIMIT 1",
				$registration_id
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'sd_act_pay_not_found', __( 'Registrazione non trovata.', 'sd-logbook' ) );
		}

		return $row;
	}

	/**
	 * Crea un record in sd_activity_payments.
	 *
	 * @param int    $registration_id
	 * @param int    $activity_id
	 * @param array  $data
	 * @return int|false
	 */
	private function create_activity_payment( int $registration_id, int $activity_id, array $data ) {
		global $wpdb;

		return $wpdb->insert(
			$wpdb->prefix . 'sd_activity_payments',
			array(
				'registration_id'    => $registration_id,
				'activity_id'        => $activity_id,
				'member_id'          => $data['member_id'] ?: null,
				'email'              => $data['email'] ?? '',
				'amount_chf'         => $data['amount_chf'] ?? 0,
				'amount_eur'         => $data['amount_eur'] ?? null,
				'payment_method'     => $data['payment_method'] ?? '',
				'status'             => $data['status'] ?? 'in_attesa',
				'transaction_id'     => $data['transaction_id'] ?? null,
				'provider_payment_id' => $data['provider_payment_id'] ?? null,
				'confirmation_token' => $data['confirmation_token'] ?? null,
				'confirmation_expires_at' => $data['confirmation_expires_at'] ?? null,
				'provider_payload'   => isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
				'completed_at'       => $data['completed_at'] ?? null,
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		) ? $wpdb->insert_id : false;
	}

	/**
	 * Segna pagamento come completato.
	 *
	 * @param string $token
	 * @param array  $data provider, provider_payment_id, payment_method, amount_chf, payload
	 * @return bool
	 */
	public function complete_payment( string $token, array $data ) {
		global $wpdb;

		$ctx = $this->get_context_by_token( $token );
		if ( is_wp_error( $ctx ) ) {
			error_log( '[SD Activity Payment] complete_payment – token invalido: ' . $ctx->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		if ( 'paid' === $ctx->payment_status ) {
			// Idempotente: già pagato.
			return true;
		}

		// Aggiorna registrazione
		$wpdb->update(
			$wpdb->prefix . 'sd_activity_registrations',
			array(
				'payment_status' => 'paid',
				'payment_date'   => current_time( 'mysql' ),
				'payment_method' => $data['payment_method'] ?? '',
				'transaction_id' => $data['provider_payment_id'] ?? '',
			),
			array( 'id' => (int) $ctx->registration_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		// Crea record pagamento
		$this->create_activity_payment(
			(int) $ctx->registration_id,
			(int) $ctx->activity_id,
			array(
				'member_id'          => (int) $ctx->member_id,
				'email'              => (string) $ctx->email,
				'amount_chf'         => (float) $ctx->price_chf,
				'amount_eur'         => (float) $ctx->price_eur,
				'payment_method'     => $data['payment_method'] ?? '',
				'status'             => 'completato',
				'transaction_id'     => $data['provider_payment_id'] ?? '',
				'provider_payment_id' => $data['provider_payment_id'] ?? '',
				'confirmation_token' => $token,
				'completed_at'       => current_time( 'mysql' ),
				'payload'            => $data['payload'] ?? array(),
			)
		);

		// Invia email di conferma
		$this->send_confirmation_email( $ctx );

		return true;
	}

	/**
	 * Segna pagamento fattura come "in attesa" (fattura richiesta).
	 *
	 * @param string $token
	 * @return bool
	 */
	public function request_invoice( string $token ) {
		global $wpdb;

		$ctx = $this->get_context_by_token( $token );
		if ( is_wp_error( $ctx ) ) {
			return false;
		}

		if ( 'paid' === $ctx->payment_status ) {
			return true;
		}

		if ( 'invoice_sent' === $ctx->payment_status ) {
			return true;
		}

		$should_create_payment_row = ! in_array( $ctx->payment_status, array( 'invoice_requested', 'invoice_error', 'invoice_sent' ), true );

		$wpdb->update(
			$wpdb->prefix . 'sd_activity_registrations',
			array( 'payment_status' => 'invoice_requested' ),
			array( 'id' => (int) $ctx->registration_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( $should_create_payment_row ) {
			$this->create_activity_payment(
				(int) $ctx->registration_id,
				(int) $ctx->activity_id,
				array(
					'member_id'      => (int) $ctx->member_id,
					'email'          => (string) $ctx->email,
					'amount_chf'     => (float) $ctx->price_chf,
					'amount_eur'     => (float) $ctx->price_eur,
					'payment_method' => 'fattura',
					'status'         => 'in_attesa',
					'confirmation_token' => $token,
				)
			);
		}

		$send_result = $this->send_invoice_request_email( $ctx );
		$new_status  = ! empty( $send_result['sent'] ) ? 'invoice_sent' : 'invoice_error';

		$wpdb->update(
			$wpdb->prefix . 'sd_activity_registrations',
			array(
				'payment_status' => $new_status,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => (int) $ctx->registration_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( empty( $send_result['sent'] ) ) {
			error_log( '[SD Activity Payment] request_invoice email failed reg=' . (int) $ctx->registration_id . ' error=' . (string) ( $send_result['error'] ?? 'unknown' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return true;
	}

	/**
	 * Reinvio email fattura da admin e aggiorna stato in base all'esito.
	 *
	 * @param int $registration_id ID registrazione.
	 * @return array|WP_Error
	 */
	public function resend_invoice_email( int $registration_id ) {
		global $wpdb;

		$ctx = $this->get_context_by_registration_id( $registration_id );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		if ( 'paid' === (string) $ctx->payment_status ) {
			return new WP_Error( 'sd_act_pay_already_paid', __( 'Pagamento già registrato come pagato.', 'sd-logbook' ) );
		}

		$send_result = $this->send_invoice_request_email( $ctx );
		$new_status  = ! empty( $send_result['sent'] ) ? 'invoice_sent' : 'invoice_error';

		$wpdb->update(
			$wpdb->prefix . 'sd_activity_registrations',
			array(
				'payment_status' => $new_status,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => (int) $registration_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( empty( $send_result['sent'] ) ) {
			error_log( '[SD Activity Payment] resend_invoice_email failed reg=' . (int) $registration_id . ' error=' . (string) ( $send_result['error'] ?? 'unknown' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return array(
			'sent'   => ! empty( $send_result['sent'] ),
			'error'  => (string) ( $send_result['error'] ?? '' ),
			'status' => $new_status,
		);
	}

	// =========================================================================
	// Email helpers
	// =========================================================================

	/**
	 * Invia email di conferma pagamento al partecipante.
	 *
	 * @param object $ctx Row dalla query.
	 */
	private function send_confirmation_email( $ctx ) {
		if ( empty( $ctx->email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: titolo attività */
			__( 'Iscrizione confermata: %s', 'sd-logbook' ),
			$ctx->activity_title ?? ''
		);

		$body = sprintf(
			/* translators: 1: nome, 2: titolo attività, 3: data, 4: importo */
			__(
				"Ciao %1\$s,\n\nIl tuo pagamento per l'attività \"%2\$s\" del %3\$s è stato ricevuto con successo.\nImporto: CHF %4\$s\n\nGrazie!\nScubaDiabetes",
				'sd-logbook'
			),
			trim( $ctx->first_name . ' ' . $ctx->last_name ),
			$ctx->activity_title ?? '',
			! empty( $ctx->activity_start_date ) ? date_i18n( 'd.m.Y', strtotime( $ctx->activity_start_date ) ) : '',
			number_format( (float) $ctx->price_chf, 2, '.', '' )
		);

		wp_mail(
			sanitize_email( $ctx->email ),
			$subject,
			$body
		);
	}

	/**
	 * Invia notifica richiesta fattura al partecipante e all'admin.
	 *
	 * @param object $ctx
	 */
	private function send_invoice_request_email( $ctx ) {
		if ( empty( $ctx->email ) ) {
			return array(
				'sent'  => false,
				'error' => __( 'Email partecipante mancante.', 'sd-logbook' ),
			);
		}

		$subject = sprintf(
			/* translators: %s: titolo attività */
			__( 'Iscrizione ricevuta – fattura in arrivo: %s', 'sd-logbook' ),
			$ctx->activity_title ?? ''
		);

		$body = sprintf(
			/* translators: 1: nome, 2: titolo attività, 3: importo */
			__(
				"Ciao %1\$s,\n\nAbbiamo ricevuto la tua richiesta di iscrizione a \"%2\$s\".\nRiceverai presto una fattura di CHF %3\$s.\n\nGrazie!\nScubaDiabetes",
				'sd-logbook'
			),
			trim( $ctx->first_name . ' ' . $ctx->last_name ),
			$ctx->activity_title ?? '',
			number_format( (float) $ctx->price_chf, 2, '.', '' )
		);

		$mail_error = '';
		$mail_error_listener = static function ( $wp_error ) use ( &$mail_error ) {
			if ( is_wp_error( $wp_error ) ) {
				$mail_error = $wp_error->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $mail_error_listener, 10, 1 );
		$participant_sent = wp_mail( sanitize_email( $ctx->email ), $subject, $body );
		remove_action( 'wp_mail_failed', $mail_error_listener, 10 );

		// Notifica admin
		$admin_email = get_option( 'admin_email' );
		$admin_sent = wp_mail(
			$admin_email,
			sprintf( '[ScubaDiabetes] Nuova richiesta fattura attività: %s', $ctx->activity_title ?? '' ),
			sprintf(
				"Nuova iscrizione con richiesta fattura:\n\nNome: %s %s\nEmail: %s\nAttività: %s\nImporto: CHF %s\n",
				$ctx->first_name,
				$ctx->last_name,
				$ctx->email,
				$ctx->activity_title ?? '',
				number_format( (float) $ctx->price_chf, 2, '.', '' )
			)
		);

		if ( ! $admin_sent ) {
			error_log( '[SD Activity Payment] send_invoice_request_email admin notification failed for reg=' . (int) $ctx->registration_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		if ( ! $participant_sent && '' === $mail_error ) {
			$mail_error = __( 'Invio email fallito.', 'sd-logbook' );
		}

		return array(
			'sent'  => (bool) $participant_sent,
			'error' => (string) $mail_error,
		);
	}

	/**
	 * AJAX admin: reinvia email fattura per una registrazione.
	 *
	 * @return void
	 */
	public function ajax_resend_invoice_email() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$registration_id = intval( $_POST['registration_id'] ?? 0 );
		if ( $registration_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Registrazione non valida.', 'sd-logbook' ) ) );
		}

		$result = $this->resend_invoice_email( $registration_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( ! empty( $result['sent'] ) ) {
			wp_send_json_success(
				array(
					'message'        => __( 'Email fattura inviata con successo.', 'sd-logbook' ),
					'payment_status' => $result['status'],
				)
			);
		}

		wp_send_json_error(
			array(
				'message'        => __( 'Invio email fattura fallito. Verifica il log email.', 'sd-logbook' ),
				'payment_status' => $result['status'],
				'error'          => $result['error'],
			)
		);
	}

	// =========================================================================
	// Action handler (template_redirect)
	// =========================================================================

	/**
	 * Gestisce le azioni di checkout attività.
	 *
	 * @return void
	 */
	public function handle_actions() {
		$action          = isset( $_GET['sd_act_pay_action'] ) ? sanitize_text_field( wp_unslash( $_GET['sd_act_pay_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token           = isset( $_GET['sdapt'] ) ? sanitize_text_field( wp_unslash( $_GET['sdapt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paypal_enabled  = (int) get_option( 'sd_payment_enable_paypal', 1 ) === 1;
		$invoice_enabled = (int) get_option( 'sd_payment_enable_invoice', 1 ) === 1;
		$stripe_enabled  = (int) get_option( 'sd_payment_enable_stripe', 1 ) === 1;

		if ( '' === $action || '' === $token ) {
			return;
		}

		$ctx = $this->get_context_by_token( $token );
		if ( is_wp_error( $ctx ) ) {
			error_log( '[SD Activity Payment] handle_actions – action=' . $action . ' error=' . $ctx->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		// ----------------------------------------------------------------
		// Fattura
		// ----------------------------------------------------------------
		if ( 'invoice_confirm' === $action ) {
			if ( ! $invoice_enabled ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdapt'  => rawurlencode( $token ),
							'notice' => 'invoice_disabled',
						),
						$this->get_checkout_page_url()
					)
				);
				exit;
			}

			$this->request_invoice( $token );

			wp_safe_redirect(
				add_query_arg(
					array( 'sdapt' => rawurlencode( $token ) ),
					$this->get_confirmation_page_url()
				)
			);
			exit;
		}

		// ----------------------------------------------------------------
		// PayPal: avvio
		// ----------------------------------------------------------------
		if ( 'start_paypal' === $action ) {
			if ( ! $paypal_enabled ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdapt'  => rawurlencode( $token ),
							'notice' => 'paypal_disabled',
						),
						$this->get_checkout_page_url()
					)
				);
				exit;
			}

			$return_url = add_query_arg(
				array(
					'sd_act_pay_action' => 'paypal_return',
					'sdapt'             => rawurlencode( $token ),
				),
				$this->get_checkout_page_url()
			);

			$cancel_url = add_query_arg(
				array(
					'sdapt'  => rawurlencode( $token ),
					'notice' => 'paypal_cancelled',
				),
				$this->get_checkout_page_url()
			);

			$order = $this->paypal->create_order(
				array(
					'reference_id' => 'activity-reg-' . (int) $ctx->registration_id,
					'amount'       => (float) $ctx->price_chf,
					'currency'     => 'CHF',
					'description'  => sprintf(
						/* translators: %s: titolo attività */
						__( 'Iscrizione: %s', 'sd-logbook' ),
						$ctx->activity_title ?? ''
					),
					'return_url'   => $return_url,
					'cancel_url'   => $cancel_url,
				)
			);

			if ( is_wp_error( $order ) ) {
				error_log( '[SD Activity PayPal] create_order error: ' . $order->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdapt'  => rawurlencode( $token ),
							'notice' => 'paypal_error',
						),
						$this->get_checkout_page_url()
					)
				);
				exit;
			}

			wp_redirect( esc_url_raw( $order['approval_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		// ----------------------------------------------------------------
		// PayPal: ritorno
		// ----------------------------------------------------------------
		if ( 'paypal_return' === $action ) {
			$order_id = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' === $order_id ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdapt'  => rawurlencode( $token ),
							'notice' => 'paypal_missing_order',
						),
						$this->get_checkout_page_url()
					)
				);
				exit;
			}

			$capture = $this->paypal->capture_order( $order_id );
			if ( is_wp_error( $capture ) || ! in_array( $capture['status'], array( 'COMPLETED', 'APPROVED' ), true ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdapt'  => rawurlencode( $token ),
							'notice' => 'paypal_capture_failed',
						),
						$this->get_checkout_page_url()
					)
				);
				exit;
			}

			$this->complete_payment(
				$token,
				array(
					'provider'            => 'paypal',
					'provider_payment_id' => $order_id,
					'payment_method'      => 'paypal',
					'payload'             => $capture['payload'] ?? array(),
				)
			);

			wp_safe_redirect(
				add_query_arg(
					array( 'sdapt' => rawurlencode( $token ) ),
					$this->get_confirmation_page_url()
				)
			);
			exit;
		}

		// ----------------------------------------------------------------
		// Stripe: avvio
		// ----------------------------------------------------------------
		if ( 'start_stripe' === $action ) {
			if ( ! $stripe_enabled ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdapt'  => rawurlencode( $token ),
							'notice' => 'stripe_disabled',
						),
						$this->get_checkout_page_url()
					)
				);
				exit;
			}

			$return_url = add_query_arg(
				array(
					'sd_act_pay_action' => 'stripe_return',
					'sdapt'             => rawurlencode( $token ),
				),
				$this->get_checkout_page_url()
			);

			$cancel_url = add_query_arg(
				array(
					'sd_act_pay_action' => 'stripe_cancel',
					'sdapt'             => rawurlencode( $token ),
				),
				$this->get_checkout_page_url()
			);

			$session = $this->stripe->create_session(
				array(
					'amount'         => (float) $ctx->price_chf,
					'currency'       => 'CHF',
					'description'    => sprintf(
						/* translators: %s: titolo attività */
						__( 'Iscrizione: %s', 'sd-logbook' ),
						$ctx->activity_title ?? ''
					),
					'return_url'     => $return_url,
					'cancel_url'     => $cancel_url,
					'customer_email' => (string) ( $ctx->email ?? '' ),
					'sd_token'       => $token,
					// Usiamo metadata dedicato per distinguere dal flusso membership.
					'member_id'      => (int) $ctx->member_id,
				)
			);

			if ( is_wp_error( $session ) ) {
				error_log( '[SD Activity Stripe] create_session error: ' . $session->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdapt'  => rawurlencode( $token ),
							'notice' => 'stripe_error',
						),
						$this->get_checkout_page_url()
					)
				);
				exit;
			}

			// Salva session_id nel transient per verifica al ritorno (TTL 30 min).
			set_transient(
				'sd_act_stripe_' . $token,
				array(
					'session_id'      => $session['session_id'],
					'registration_id' => (int) $ctx->registration_id,
					'amount_chf'      => (float) $ctx->price_chf,
				),
				1800
			);

			wp_redirect( esc_url_raw( $session['approval_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		// ----------------------------------------------------------------
		// Stripe: ritorno dopo pagamento riuscito
		// ----------------------------------------------------------------
		if ( 'stripe_return' === $action ) {
			$stripe_data = get_transient( 'sd_act_stripe_' . $token );

			if ( empty( $stripe_data['session_id'] ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdapt'  => rawurlencode( $token ),
							'notice' => 'stripe_error',
						),
						$this->get_checkout_page_url()
					)
				);
				exit;
			}

			$verified = $this->stripe->retrieve_session( $stripe_data['session_id'] );

			if ( is_wp_error( $verified ) || 'paid' !== ( $verified['payment_status'] ?? '' ) ) {
				$notice = is_wp_error( $verified ) ? 'stripe_error' : 'stripe_cancelled';
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdapt'  => rawurlencode( $token ),
							'notice' => $notice,
						),
						$this->get_checkout_page_url()
					)
				);
				exit;
			}

			$this->complete_payment(
				$token,
				array(
					'provider'            => 'stripe',
					'provider_payment_id' => $verified['payment_intent'],
					'payment_method'      => $this->detect_stripe_payment_method( $verified ),
					'payload'             => $verified['payload'] ?? array(),
				)
			);

			delete_transient( 'sd_act_stripe_' . $token );

			wp_safe_redirect(
				add_query_arg(
					array( 'sdapt' => rawurlencode( $token ) ),
					$this->get_confirmation_page_url()
				)
			);
			exit;
		}

		// ----------------------------------------------------------------
		// Stripe: annullamento
		// ----------------------------------------------------------------
		if ( 'stripe_cancel' === $action ) {
			delete_transient( 'sd_act_stripe_' . $token );
			wp_safe_redirect(
				add_query_arg(
					array(
						'sdapt'  => rawurlencode( $token ),
						'notice' => 'stripe_cancelled',
					),
					$this->get_checkout_page_url()
				)
			);
			exit;
		}
	}

	/**
	 * Gestisce webhook Stripe per le attività.
	 * Si attiva solo se il metadata contiene sd_activity_token.
	 *
	 * @return void
	 */
	public function handle_stripe_webhook_activity() {
		if ( ! isset( $_GET['sd_stripe_webhook'] ) || '1' !== $_GET['sd_stripe_webhook'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$payload    = (string) file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

		$event = $this->stripe->verify_webhook( $payload, $sig_header );
		if ( is_wp_error( $event ) ) {
			// La classe SD_Payment_Flow ha già gestito (o gestirà) questo webhook.
			// Non duplichiamo l'errore.
			return;
		}

		if ( 'checkout.session.completed' !== $event['type'] ) {
			return;
		}

		$session_obj    = $event['data']['object'] ?? array();
		$payment_status = (string) ( $session_obj['payment_status'] ?? '' );
		$metadata       = (array) ( $session_obj['metadata'] ?? array() );
		$sd_token       = sanitize_text_field( (string) ( $metadata['sd_token'] ?? '' ) );

		if ( 'paid' !== $payment_status || '' === $sd_token ) {
			return;
		}

		// Discriminiamo: questo token appartiene a un'attività o a una quota?
		$ctx = $this->get_context_by_token( $sd_token );
		if ( is_wp_error( $ctx ) ) {
			// Non è un token di attività → lasciamo che SD_Payment_Flow lo gestisca.
			return;
		}

		// Idempotenza: già pagato?
		if ( 'paid' === $ctx->payment_status ) {
			return;
		}

		$pi_id = sanitize_text_field( (string) ( $session_obj['payment_intent'] ?? '' ) );

		$this->complete_payment(
			$sd_token,
			array(
				'provider'            => 'stripe',
				'provider_payment_id' => $pi_id,
				'payment_method'      => $this->detect_stripe_payment_method(
					array(
						'payment_intent_types' => array(),
						'payment_method_types' => (array) ( $session_obj['payment_method_types'] ?? array() ),
					)
				),
				'payload' => $session_obj,
			)
		);
	}

	/**
	 * Rileva il metodo di pagamento effettivo dalla sessione Stripe.
	 *
	 * @param array $session_data
	 * @return string
	 */
	private function detect_stripe_payment_method( array $session_data ) {
		$pi_types = (array) ( $session_data['payment_intent_types'] ?? array() );
		if ( ! empty( $pi_types ) ) {
			$used = strtolower( (string) ( $pi_types[0] ?? '' ) );
			return 'twint' === $used ? 'twint' : 'carta_credito';
		}
		$types = (array) ( $session_data['payment_method_types'] ?? array() );
		if ( array( 'twint' ) === $types ) {
			return 'twint';
		}
		return 'carta_credito';
	}

	// =========================================================================
	// Shortcodes
	// =========================================================================

	/**
	 * Rendering pagina checkout attività.
	 *
	 * @return string
	 */
	public function render_checkout() {
		$token          = isset( $_GET['sdapt'] ) ? sanitize_text_field( wp_unslash( $_GET['sdapt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ctx            = $this->get_context_by_token( $token );
		$notice         = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paypal_enabled  = (int) get_option( 'sd_payment_enable_paypal', 1 ) === 1;
		$invoice_enabled = (int) get_option( 'sd_payment_enable_invoice', 1 ) === 1;
		$stripe_enabled  = (int) get_option( 'sd_payment_enable_stripe', 1 ) === 1;
		$checkout_base   = $this->get_checkout_page_url();

		if ( is_wp_error( $ctx ) ) {
			return '<div class="sd-notice sd-notice-error">' . esc_html( $ctx->get_error_message() ) . '</div>';
		}

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/activity-payment-checkout.php';
		return ob_get_clean();
	}

	/**
	 * Rendering pagina conferma iscrizione attività.
	 *
	 * @return string
	 */
	public function render_confirmation() {
		$token = isset( $_GET['sdapt'] ) ? sanitize_text_field( wp_unslash( $_GET['sdapt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ctx   = $this->get_context_by_token( $token );

		if ( is_wp_error( $ctx ) ) {
			return '<div class="sd-notice sd-notice-error">' . esc_html( $ctx->get_error_message() ) . '</div>';
		}

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/activity-payment-confirmation.php';
		return ob_get_clean();
	}
}
