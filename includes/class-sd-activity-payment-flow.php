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
					r.registration_data,
					r.payment_status,
					r.price_chf,
					r.price_eur,
					r.price_id,
					r.confirmation_token,
					r.confirmation_expires_at,
					a.title AS activity_title,
					a.start_date AS activity_start_date,
					a.end_date AS activity_end_date,
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

		$this->hydrate_price_name_from_registration_data( $row );

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
					r.registration_data,
					r.payment_status,
					r.price_chf,
					r.price_eur,
					r.price_id,
					r.confirmation_token,
					r.confirmation_expires_at,
					a.title AS activity_title,
					a.start_date AS activity_start_date,
					a.end_date AS activity_end_date,
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

		$this->hydrate_price_name_from_registration_data( $row );

		return $row;
	}

	/**
	 * Arricchisce il contesto con i nomi tariffa selezionati (multi-tariffa).
	 *
	 * @param object $row Contesto registrazione.
	 * @return void
	 */
	private function hydrate_price_name_from_registration_data( $row ) {
		if ( ! isset( $row->registration_data ) ) {
			return;
		}

		$registration_data = json_decode( (string) $row->registration_data, true );
		if ( ! is_array( $registration_data ) ) {
			return;
		}

		$selected_names = isset( $registration_data['selected_price_names'] ) && is_array( $registration_data['selected_price_names'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $registration_data['selected_price_names'] ) ) )
			: array();

		if ( ! empty( $selected_names ) ) {
			$row->price_name = implode( ' + ', $selected_names );
		}
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

		$completed_at = current_time( 'mysql' );
		$payment_data = array(
			'provider'            => (string) ( $data['provider'] ?? '' ),
			'provider_payment_id' => (string) ( $data['provider_payment_id'] ?? '' ),
			'payment_method'      => (string) ( $data['payment_method'] ?? '' ),
			'payment_date'        => $completed_at,
			'amount_chf'          => (float) $ctx->price_chf,
			'amount_eur'          => (float) $ctx->price_eur,
		);

		// Aggiorna registrazione
		$wpdb->update(
			$wpdb->prefix . 'sd_activity_registrations',
			array(
				'payment_status' => 'paid',
				'payment_date'   => $completed_at,
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
				'completed_at'       => $completed_at,
				'payload'            => $data['payload'] ?? array(),
			)
		);

		// Invia email di conferma
		$this->send_confirmation_email( $ctx, $payment_data );

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
	 * @param object $ctx          Row dalla query.
	 * @param array  $payment_data Dati pagamento confermato.
	 */
	private function send_confirmation_email( $ctx, array $payment_data = array() ) {
		if ( ! $this->is_electronic_or_paypal_payment( $payment_data ) ) {
			return;
		}

		$participant_name = trim( (string) $ctx->first_name . ' ' . (string) $ctx->last_name );
		$activity_title   = (string) ( $ctx->activity_title ?? '' );
		$activity_date    = ! empty( $ctx->activity_start_date )
			? date_i18n( 'd.m.Y', strtotime( (string) $ctx->activity_start_date ) )
			: '';

		$payment_method_slug = sanitize_text_field( (string) ( $payment_data['payment_method'] ?? ( $ctx->payment_method ?? '' ) ) );
		$payment_method      = $this->payment_method_label( $payment_method_slug );
		$transaction_id      = sanitize_text_field( (string) ( $payment_data['provider_payment_id'] ?? ( $ctx->transaction_id ?? '' ) ) );
		$payment_date_raw    = (string) ( $payment_data['payment_date'] ?? current_time( 'mysql' ) );
		$payment_date_label  = date_i18n( 'd.m.Y H:i', strtotime( $payment_date_raw ) );

		$amount_chf = number_format( (float) ( $payment_data['amount_chf'] ?? $ctx->price_chf ?? 0 ), 2, '.', '' );
		$amount_eur = number_format( (float) ( $payment_data['amount_eur'] ?? $ctx->price_eur ?? 0 ), 2, '.', '' );

		$attachments = array();
		if ( class_exists( 'SD_Payment_Documents' ) ) {
			$pdf_path = ( new SD_Payment_Documents() )->generate_activity_payment_confirmation_document( $ctx, $payment_data );
			if ( ! is_wp_error( $pdf_path ) && file_exists( (string) $pdf_path ) ) {
				$attachments[] = (string) $pdf_path;
			} else {
				$err = is_wp_error( $pdf_path ) ? $pdf_path->get_error_message() : 'unknown';
				error_log( '[SD Activity Payment] PDF conferma pagamento non generato reg=' . (int) $ctx->registration_id . ' err=' . $err ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		$subject = sprintf(
			/* translators: %s: titolo attività */
			__( 'Pagamento confermato: %s', 'sd-logbook' ),
			$activity_title
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$body  = '<html><body style="font-family:Arial,sans-serif;color:#333;max-width:680px;margin:auto">';
		$body .= '<h2 style="color:#0055a5">' . esc_html__( 'Pagamento attività confermato', 'sd-logbook' ) . '</h2>';
		$body .= '<p>' . sprintf(
			esc_html__( 'Ciao %1$s, il tuo pagamento per l\'attività "%2$s" è stato ricevuto con successo.', 'sd-logbook' ),
			esc_html( $participant_name ),
			esc_html( $activity_title )
		) . '</p>';
		$body .= '<table style="border-collapse:collapse;width:100%;font-size:14px;margin-bottom:16px">';
		$body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Attività', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $activity_title ) . '</td></tr>';
		if ( '' !== $activity_date ) {
			$body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Data attività', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $activity_date ) . '</td></tr>';
		}
		$body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Metodo pagamento', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $payment_method ) . '</td></tr>';
		$body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Importo', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">CHF ' . esc_html( $amount_chf ) . ' / EUR ' . esc_html( $amount_eur ) . '</td></tr>';
		$body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Data pagamento', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $payment_date_label ) . '</td></tr>';
		if ( '' !== trim( $transaction_id ) ) {
			$body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Riferimento transazione', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $transaction_id ) . '</td></tr>';
		}
		$body .= '</table>';
		$body .= '<h3 style="color:#0055a5">' . esc_html__( 'Dati registrati nel modulo', 'sd-logbook' ) . '</h3>';
		$body .= $this->build_registration_data_html( $ctx );
		$body .= '<p style="color:#666;font-size:12px">' . esc_html__( 'In allegato trovi il PDF con riepilogo completo di iscrizione e pagamento.', 'sd-logbook' ) . '</p>';
		$body .= '</body></html>';

		if ( ! empty( $ctx->email ) ) {
			wp_mail( sanitize_email( (string) $ctx->email ), $subject, $body, $headers, $attachments );
		}

		$secretariat_email = sanitize_email( (string) get_option( 'sd_payment_invoice_association_email', 'info@scubadiabetes.ch' ) );
		if ( ! is_email( $secretariat_email ) ) {
			$secretariat_email = 'info@scubadiabetes.ch';
		}
		$secretariat_subject = sprintf(
			/* translators: %s: titolo attività */
			__( '[%1$s] Pagamento attività confermato: %2$s', 'sd-logbook' ),
			get_bloginfo( 'name' ),
			$activity_title
		);

		$secretariat_body  = '<html><body style="font-family:Arial,sans-serif;color:#333;max-width:680px;margin:auto">';
		$secretariat_body .= '<h2 style="color:#0055a5">' . esc_html__( 'Pagamento attività confermato', 'sd-logbook' ) . '</h2>';
		$secretariat_body .= '<table style="border-collapse:collapse;width:100%;font-size:14px;margin-bottom:16px">';
		$secretariat_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>ID registrazione</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">#' . (int) $ctx->registration_id . '</td></tr>';
		$secretariat_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Partecipante', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $participant_name ) . '</td></tr>';
		$secretariat_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>Email</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( (string) $ctx->email ) . '</td></tr>';
		$secretariat_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Attività', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $activity_title ) . '</td></tr>';
		if ( '' !== $activity_date ) {
			$secretariat_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Data attività', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $activity_date ) . '</td></tr>';
		}
		$secretariat_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Metodo pagamento', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $payment_method ) . '</td></tr>';
		$secretariat_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Importo', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">CHF ' . esc_html( $amount_chf ) . ' / EUR ' . esc_html( $amount_eur ) . '</td></tr>';
		$secretariat_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Data pagamento', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $payment_date_label ) . '</td></tr>';
		if ( '' !== trim( $transaction_id ) ) {
			$secretariat_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>' . esc_html__( 'Riferimento transazione', 'sd-logbook' ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $transaction_id ) . '</td></tr>';
		}
		$secretariat_body .= '</table>';
		$secretariat_body .= '<h3 style="color:#0055a5">' . esc_html__( 'Dati registrati nel modulo', 'sd-logbook' ) . '</h3>';
		$secretariat_body .= $this->build_registration_data_html( $ctx );
		$secretariat_body .= '<p style="color:#666;font-size:12px">' . esc_html__( 'In allegato il PDF riepilogativo inviato al partecipante.', 'sd-logbook' ) . '</p>';
		$secretariat_body .= '</body></html>';

		wp_mail( $secretariat_email, $secretariat_subject, $secretariat_body, $headers, $attachments );
	}

	/**
	 * Verifica se il pagamento è elettronico o PayPal.
	 *
	 * @param array $payment_data Dati pagamento.
	 * @return bool
	 */
	private function is_electronic_or_paypal_payment( array $payment_data ) {
		$provider = sanitize_key( (string) ( $payment_data['provider'] ?? '' ) );
		$method   = sanitize_key( (string) ( $payment_data['payment_method'] ?? '' ) );

		if ( in_array( $provider, array( 'stripe', 'paypal' ), true ) ) {
			return true;
		}

		return in_array(
			$method,
			array( 'paypal', 'twint', 'carta_credito', 'apple_pay', 'google_pay' ),
			true
		);
	}

	/**
	 * Etichetta leggibile del metodo di pagamento.
	 *
	 * @param string $slug Slug metodo.
	 * @return string
	 */
	private function payment_method_label( $slug ) {
		$map = array(
			'paypal'        => __( 'PayPal', 'sd-logbook' ),
			'twint'         => __( 'TWINT', 'sd-logbook' ),
			'carta_credito' => __( 'Carta di credito/debito', 'sd-logbook' ),
			'apple_pay'     => __( 'Apple Pay', 'sd-logbook' ),
			'google_pay'    => __( 'Google Pay', 'sd-logbook' ),
			'stripe'        => __( 'Stripe', 'sd-logbook' ),
		);

		$key = sanitize_key( (string) $slug );
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}

		return '' !== $key ? $key : __( 'n/d', 'sd-logbook' );
	}

	/**
	 * Invia notifica richiesta fattura al partecipante (con PDF allegato) e al segretariato.
	 *
	 * @param object $ctx
	 * @return array{sent:bool,error:string}
	 */
	private function send_invoice_request_email( $ctx ) {
		if ( empty( $ctx->email ) ) {
			return array(
				'sent'  => false,
				'error' => __( 'Email partecipante mancante.', 'sd-logbook' ),
			);
		}

		$activity_title   = (string) ( $ctx->activity_title ?? '' );
		$participant_name = trim( (string) $ctx->first_name . ' ' . (string) $ctx->last_name );
		$activity_date    = ! empty( $ctx->activity_start_date )
			? date_i18n( 'd.m.Y', strtotime( (string) $ctx->activity_start_date ) )
			: '';
		$amount_chf       = number_format( (float) $ctx->price_chf, 2, '.', '' );
		$amount_eur       = number_format( (float) $ctx->price_eur, 2, '.', '' );
		$price_name       = (string) ( $ctx->price_name ?? '' );

		// --- Genera PDF fattura ---
		$attachments = array();
		$pdf_path    = '';
		if ( class_exists( 'SD_Payment_Documents' ) ) {
			$generated = ( new SD_Payment_Documents() )->generate_activity_invoice_document( $ctx );
			if ( ! is_wp_error( $generated ) && file_exists( (string) $generated ) ) {
				$pdf_path      = (string) $generated;
				$attachments[] = $pdf_path;
			} else {
				$err = is_wp_error( $generated ) ? $generated->get_error_message() : 'unknown';
				error_log( '[SD Activity Payment] PDF fattura non generato reg=' . (int) $ctx->registration_id . ' err=' . $err ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		// --- Dati banca/associazione per email ---
		$association_name = (string) get_option(
			'sd_payment_invoice_association_name',
			get_option( 'sd_payment_association_title', get_bloginfo( 'name' ) )
		);
		$bank_iban  = (string) get_option( 'sd_payment_invoice_bank_iban', '' );
		$bank_swift = (string) get_option( 'sd_payment_invoice_bank_swift', '' );
		$bank_bic   = (string) get_option( 'sd_payment_invoice_bank_bic', '' );
		$qr_payload = (string) get_option( 'sd_payment_invoice_qr_payload', '' );

		// --- Subject ---
		$subject = sprintf(
			/* translators: 1: site name, 2: titolo attività */
			__( '[%1$s] Iscrizione ricevuta e fattura: %2$s', 'sd-logbook' ),
			get_bloginfo( 'name' ),
			$activity_title
		);

		// --- Corpo HTML partecipante ---
		$body  = '<html><body style="font-family:Arial,sans-serif;color:#333;max-width:680px;margin:auto">';
		$body .= '<div style="margin-bottom:16px"><img src="https://scubadiabetes.ch/wp-content/uploads/2026/04/scubadiabetes_radius60.png" alt="ScubaDiabetes" style="height:80px;width:auto"></div>';
		$body .= '<h2 style="color:#0055a5">' . esc_html__( 'Conferma ricezione iscrizione', 'sd-logbook' ) . '</h2>';
		$body .= '<p>' . sprintf(
			esc_html__( 'Caro/a %1$s, abbiamo ricevuto la tua iscrizione all\'attività "%2$s".', 'sd-logbook' ),
			esc_html( $participant_name ),
			esc_html( $activity_title )
		) . '</p>';
		$body .= '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:12px 16px;margin:16px 0">';
		$body .= '<strong>' . esc_html__( 'La tua partecipazione verrà confermata alla ricezione del pagamento della fattura allegata.', 'sd-logbook' ) . '</strong>';
		$body .= '</div>';

		// Riepilogo iscrizione
		$body .= '<h3 style="color:#0055a5">' . esc_html__( 'Riepilogo iscrizione', 'sd-logbook' ) . '</h3>';
		$body .= $this->build_registration_data_html( $ctx );

		// Pagamento
		$body .= '<h3 style="color:#0055a5">' . esc_html__( 'Come effettuare il pagamento (Bonifico IBAN)', 'sd-logbook' ) . '</h3>';
		$body .= '<p>' . esc_html__( 'In allegato trovi la fattura PDF. Effettua un bonifico bancario con i seguenti dati:', 'sd-logbook' ) . '</p>';
		$body .= '<ul>';
		$body .= '<li><strong>' . esc_html__( 'Associazione:', 'sd-logbook' ) . '</strong> ' . esc_html( $association_name ) . '</li>';
		$body .= '<li><strong>' . esc_html__( 'IBAN:', 'sd-logbook' ) . '</strong> ' . esc_html( $bank_iban ) . '</li>';
		if ( '' !== trim( $bank_swift . $bank_bic ) ) {
			$body .= '<li><strong>' . esc_html__( 'SWIFT/BIC:', 'sd-logbook' ) . '</strong> ' . esc_html( trim( $bank_swift . ' ' . $bank_bic ) ) . '</li>';
		}
		$body .= '<li><strong>' . esc_html__( 'Importo:', 'sd-logbook' ) . '</strong> CHF ' . esc_html( $amount_chf ) . ' (EUR ' . esc_html( $amount_eur ) . ')</li>';
		$body .= '<li><strong>' . esc_html__( 'Causale:', 'sd-logbook' ) . '</strong> ' . esc_html( 'Iscrizione ' . $activity_title . ' - ' . $participant_name ) . '</li>';
		if ( '' !== trim( $qr_payload ) ) {
			$body .= '<li><strong>' . esc_html__( 'QR pagamento:', 'sd-logbook' ) . '</strong><br>' . nl2br( esc_html( $qr_payload ) ) . '</li>';
		}
		$body .= '</ul>';
		$body .= '<p style="color:#666;font-size:12px">' . esc_html__( 'Per qualsiasi domanda contatta il segretariato dell\'associazione.', 'sd-logbook' ) . '</p>';
		$body .= '</body></html>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$mail_error          = '';
		$mail_error_listener = static function ( $wp_error ) use ( &$mail_error ) {
			if ( is_wp_error( $wp_error ) ) {
				$mail_error = $wp_error->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $mail_error_listener, 10, 1 );
		$participant_sent = wp_mail( sanitize_email( (string) $ctx->email ), $subject, $body, $headers, $attachments );
		remove_action( 'wp_mail_failed', $mail_error_listener, 10 );

		// --- Notifica segretariato ---
		$admin_email = (string) get_option(
			'sd_payment_invoice_association_email',
			get_option( 'admin_email' )
		);
		$admin_subject = sprintf(
			'[%s] Nuova iscrizione con richiesta fattura: %s',
			get_bloginfo( 'name' ),
			$activity_title
		);

		$admin_body  = '<html><body style="font-family:Arial,sans-serif;color:#333;max-width:680px;margin:auto">';
		$admin_body .= '<h2 style="color:#0055a5">' . esc_html__( 'Nuova iscrizione con richiesta fattura', 'sd-logbook' ) . '</h2>';
		$admin_body .= '<table style="border-collapse:collapse;width:100%;font-size:14px;margin-bottom:16px">';
		$admin_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>ID registrazione</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">#' . (int) $ctx->registration_id . '</td></tr>';
		$admin_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>Attività</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $activity_title ) . ( $activity_date ? ' (' . esc_html( $activity_date ) . ')' : '' ) . '</td></tr>';
		$admin_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>Partecipante</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $participant_name ) . '</td></tr>';
		$admin_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>Email</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( (string) $ctx->email ) . '</td></tr>';
		if ( '' !== $price_name ) {
			$admin_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>Tariffa</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $price_name ) . '</td></tr>';
		}
		$admin_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>Importo</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">CHF ' . esc_html( $amount_chf ) . ' / EUR ' . esc_html( $amount_eur ) . '</td></tr>';
		$admin_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>Metodo pagamento</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">Fattura</td></tr>';
		$admin_body .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc"><strong>Stato pagamento</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . esc_html( $this->payment_status_label( (string) ( $ctx->payment_status ?? 'invoice_requested' ) ) ) . '</td></tr>';
		$admin_body .= '</table>';

		$admin_body .= '<h3 style="color:#0055a5">' . esc_html__( 'Dati modulo di iscrizione', 'sd-logbook' ) . '</h3>';
		$admin_body .= $this->build_registration_data_html( $ctx );
		$admin_body .= '<p style="color:#666;font-size:12px">In allegato la stessa fattura PDF inviata al partecipante.</p>';
		$admin_body .= '</body></html>';

		$admin_sent = wp_mail(
			sanitize_email( $admin_email ),
			$admin_subject,
			$admin_body,
			$headers,
			$attachments
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
	 * Costruisce tabella HTML con i dati del modulo di iscrizione.
	 *
	 * @param object $ctx
	 * @return string
	 */
	private function build_registration_data_html( $ctx ) {
		$raw = isset( $ctx->registration_data ) ? (string) $ctx->registration_data : '';
		if ( '' === $raw ) {
			return '<p style="color:#64748b;font-size:13px">' . esc_html__( 'Nessun dato modulo registrato.', 'sd-logbook' ) . '</p>';
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return '<p style="color:#64748b;font-size:13px">' . esc_html__( 'Nessun dato modulo registrato.', 'sd-logbook' ) . '</p>';
		}

		// Etichette amichevoli per chiavi note.
		$labels = array(
			'birth_date'            => __( 'Data di nascita', 'sd-logbook' ),
			'is_minor'              => __( 'Minorenne', 'sd-logbook' ),
			'luogo_di_nascita'      => __( 'Luogo di nascita', 'sd-logbook' ),
			'diabete_tipo'          => __( 'Tipo di diabete', 'sd-logbook' ),
			'celiachia'             => __( 'Celiachia', 'sd-logbook' ),
			'telefono_cellulare'    => __( 'Telefono cellulare', 'sd-logbook' ),
			'selected_price_names'  => __( 'Tariffe selezionate', 'sd-logbook' ),
			'selected_price_ids'    => __( 'ID tariffe', 'sd-logbook' ),
			'selected_price_count'  => __( 'Numero tariffe', 'sd-logbook' ),
		);
		// Chiavi da nascondere (rumore tecnico).
		$skip = array( 'selected_price_ids', 'selected_price_count' );

		$html  = '<table style="border-collapse:collapse;width:100%;font-size:14px;margin-bottom:16px">';
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			$label = isset( $labels[ $key ] )
				? $labels[ $key ]
				: ucfirst( str_replace( array( '_', '-' ), ' ', (string) $key ) );

			if ( 'is_minor' === $key ) {
				$display = in_array( (string) $value, array( '1', 'true', 'yes' ), true ) ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' );
			} elseif ( is_array( $value ) ) {
				$display = implode( ', ', array_map( 'strval', $value ) );
			} else {
				$display = (string) $value;
			}
			$html .= '<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc;width:38%"><strong>' . esc_html( $label ) . '</strong></td><td style="padding:6px 10px;border:1px solid #e2e8f0">' . nl2br( esc_html( $display ) ) . '</td></tr>';
		}
		$html .= '</table>';
		return $html;
	}

	/**
	 * Restituisce un'etichetta leggibile per uno stato pagamento.
	 *
	 * @param string $status
	 * @return string
	 */
	private function payment_status_label( $status ) {
		$map = array(
			'in_attesa'         => __( 'In attesa', 'sd-logbook' ),
			'invoice_requested' => __( 'Fattura richiesta', 'sd-logbook' ),
			'invoice_sent'      => __( 'Fattura inviata', 'sd-logbook' ),
			'invoice_error'     => __( 'Errore invio fattura', 'sd-logbook' ),
			'paid'              => __( 'Pagato', 'sd-logbook' ),
			'completato'        => __( 'Completato', 'sd-logbook' ),
			'failed'            => __( 'Fallito', 'sd-logbook' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : ucfirst( str_replace( '_', ' ', (string) $status ) );
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
