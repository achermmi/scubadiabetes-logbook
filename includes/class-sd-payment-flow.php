<?php
/**
 * Checkout + conferma pagamento (frontend pubblico).
 *
 * Shortcodes:
 * - [sd_payment_checkout]
 * - [sd_payment_confirmation]
 *
 * Gateway supportati: PayPal, Stripe (carta/Apple Pay/Google Pay/TWINT), Fattura.
 * Webhook Stripe: configurare https://tuosite.com/?sd_stripe_webhook=1 nel dashboard Stripe.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Payment_Flow {

	/**
	 * @var SD_Payment_Orchestrator
	 */
	private $orchestrator;

	/**
	 * @var SD_Payment_PayPal
	 */
	private $paypal;

	/**
	 * @var SD_Payment_Stripe
	 */
	private $stripe;

	public function __construct() {
		$this->orchestrator = new SD_Payment_Orchestrator();
		$this->paypal       = new SD_Payment_PayPal();
		$this->stripe       = new SD_Payment_Stripe();

		add_shortcode( 'sd_payment_checkout', array( $this, 'render_checkout' ) );
		add_shortcode( 'sd_payment_confirmation', array( $this, 'render_confirmation' ) );
		add_action( 'template_redirect', array( $this, 'handle_actions' ) );
		add_action( 'init', array( $this, 'handle_stripe_webhook' ) );
	}

	/**
	 * Gestisce le azioni pubbliche di checkout (PayPal, Stripe, Fattura).
	 *
	 * @return void
	 */
	public function handle_actions() {
		$action          = isset( $_GET['sd_payment_action'] ) ? sanitize_text_field( wp_unslash( $_GET['sd_payment_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token           = isset( $_GET['sdpt'] ) ? sanitize_text_field( wp_unslash( $_GET['sdpt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paypal_enabled  = (int) get_option( 'sd_payment_enable_paypal', 1 ) === 1;
		$invoice_enabled = (int) get_option( 'sd_payment_enable_invoice', 1 ) === 1;
		$stripe_enabled  = (int) get_option( 'sd_payment_enable_stripe', 1 ) === 1;

		if ( '' === $action || '' === $token ) {
			return;
		}

		$ctx = $this->orchestrator->get_payment_context_by_token( $token );
		if ( is_wp_error( $ctx ) ) {
			error_log( '[SD Payment] handle_actions – action=' . $action . ' error=' . $ctx->get_error_code() . ': ' . $ctx->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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
							'sdpt'   => rawurlencode( $token ),
							'notice' => 'invoice_disabled',
						),
						$this->orchestrator->get_checkout_page_url()
					)
				);
				exit;
			}

			$result = $this->orchestrator->request_invoice_payment(
				(int) $ctx->member_id,
				array(
					'provider'            => 'fattura',
					'provider_payment_id' => 'fattura-' . (int) $ctx->id . '-' . gmdate( 'YmdHis' ),
					'payment_method'      => 'fattura',
					'amount'              => (float) $ctx->amount,
					'notes'               => __( 'Generazione fattura e messa in attesa pagamento.', 'sd-logbook' ),
				)
			);

			$redirect = $this->orchestrator->get_confirmation_page_url();
			if ( ! is_wp_error( $result ) && ! empty( $result['redirect_to'] ) ) {
				$redirect = $result['redirect_to'];
			}
			wp_safe_redirect( $redirect );
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
							'sdpt'   => rawurlencode( $token ),
							'notice' => 'paypal_disabled',
						),
						$this->orchestrator->get_checkout_page_url()
					)
				);
				exit;
			}

			$return_url = add_query_arg(
				array(
					'sd_payment_action' => 'paypal_return',
					'sdpt'              => rawurlencode( $token ),
				),
				$this->orchestrator->get_checkout_page_url()
			);

			$cancel_url = add_query_arg(
				array(
					'sdpt'   => rawurlencode( $token ),
					'notice' => 'paypal_cancelled',
				),
				$this->orchestrator->get_checkout_page_url()
			);

			$order = $this->paypal->create_order(
				array(
					'reference_id' => 'member-' . (int) $ctx->member_id,
					'amount'       => (float) $ctx->amount,
					'currency'     => ! empty( $ctx->currency ) ? (string) $ctx->currency : 'CHF',
					'description'  => 'Quota sociale ScubaDiabetes',
					'return_url'   => $return_url,
					'cancel_url'   => $cancel_url,
				)
			);

			if ( is_wp_error( $order ) ) {
				error_log( '[SD PayPal] create_order error: ' . $order->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdpt'   => rawurlencode( $token ),
							'notice' => 'paypal_error',
						),
						$this->orchestrator->get_checkout_page_url()
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
							'sdpt'   => rawurlencode( $token ),
							'notice' => 'paypal_missing_order',
						),
						$this->orchestrator->get_checkout_page_url()
					)
				);
				exit;
			}

			$capture = $this->paypal->capture_order( $order_id );
			if ( is_wp_error( $capture ) || ! in_array( $capture['status'], array( 'COMPLETED', 'APPROVED' ), true ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdpt'   => rawurlencode( $token ),
							'notice' => 'paypal_capture_failed',
						),
						$this->orchestrator->get_checkout_page_url()
					)
				);
				exit;
			}

			$result = $this->orchestrator->accept_payment(
				(int) $ctx->member_id,
				array(
					'provider'            => 'paypal',
					'provider_payment_id' => $order_id,
					'payment_method'      => 'paypal',
					'amount'              => (float) $ctx->amount,
					'payload_json'        => $capture['payload'],
					'notes'               => __( 'Pagamento confermato da PayPal.', 'sd-logbook' ),
				)
			);

			$redirect = $this->orchestrator->get_confirmation_page_url();
			if ( ! is_wp_error( $result ) && ! empty( $result['redirect_to'] ) ) {
				$redirect = $result['redirect_to'];
			}
			wp_safe_redirect( $redirect );
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
							'sdpt'   => rawurlencode( $token ),
							'notice' => 'stripe_disabled',
						),
						$this->orchestrator->get_checkout_page_url()
					)
				);
				exit;
			}

			$return_url = add_query_arg(
				array(
					'sd_payment_action' => 'stripe_return',
					'sdpt'              => rawurlencode( $token ),
				),
				$this->orchestrator->get_checkout_page_url()
			);

			$cancel_url = add_query_arg(
				array(
					'sd_payment_action' => 'stripe_cancel',
					'sdpt'              => rawurlencode( $token ),
				),
				$this->orchestrator->get_checkout_page_url()
			);

			$session = $this->stripe->create_session(
				array(
					'amount'         => (float) $ctx->amount,
					'currency'       => ! empty( $ctx->currency ) ? (string) $ctx->currency : 'CHF',
					'description'    => 'Quota sociale ScubaDiabetes ' . (int) $ctx->payment_year,
					'return_url'     => $return_url,
					'cancel_url'     => $cancel_url,
					'customer_email' => (string) ( $ctx->email ?? '' ),
					'sd_token'       => $token,
					'member_id'      => (int) $ctx->member_id,
				)
			);

			if ( is_wp_error( $session ) ) {
				error_log( '[SD Stripe] create_session error: ' . $session->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdpt'   => rawurlencode( $token ),
							'notice' => 'stripe_error',
						),
						$this->orchestrator->get_checkout_page_url()
					)
				);
				exit;
			}

			// Salva session_id nel transient per la verifica al ritorno (TTL 30 min).
			set_transient(
				'sd_stripe_session_' . $token,
				array(
					'session_id' => $session['session_id'],
					'member_id'  => (int) $ctx->member_id,
					'amount'     => (float) $ctx->amount,
					'currency'   => ! empty( $ctx->currency ) ? (string) $ctx->currency : 'CHF',
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
			$stripe_data = get_transient( 'sd_stripe_session_' . $token );

			if ( empty( $stripe_data['session_id'] ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdpt'   => rawurlencode( $token ),
							'notice' => 'stripe_error',
						),
						$this->orchestrator->get_checkout_page_url()
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
							'sdpt'   => rawurlencode( $token ),
							'notice' => $notice,
						),
						$this->orchestrator->get_checkout_page_url()
					)
				);
				exit;
			}

			$result = $this->orchestrator->accept_payment(
				(int) $stripe_data['member_id'],
				array(
					'provider'            => 'stripe',
					'provider_payment_id' => $verified['payment_intent'],
					'payment_method'      => $this->detect_stripe_payment_method( $verified ),
					'amount'              => (float) $stripe_data['amount'],
					'payload_json'        => $verified['payload'],
					'notes'               => __( 'Pagamento confermato da Stripe Checkout.', 'sd-logbook' ),
				)
			);

			delete_transient( 'sd_stripe_session_' . $token );

			$redirect = $this->orchestrator->get_confirmation_page_url();
			if ( ! is_wp_error( $result ) && ! empty( $result['redirect_to'] ) ) {
				$redirect = $result['redirect_to'];
			}
			wp_safe_redirect( $redirect );
			exit;
		}

		// ----------------------------------------------------------------
		// Stripe: annullamento
		// ----------------------------------------------------------------
		if ( 'stripe_cancel' === $action ) {
			delete_transient( 'sd_stripe_session_' . $token );
			wp_safe_redirect(
				add_query_arg(
					array(
						'sdpt'   => rawurlencode( $token ),
						'notice' => 'stripe_cancelled',
					),
					$this->orchestrator->get_checkout_page_url()
				)
			);
			exit;
		}
	}

	/**
	 * Rileva il metodo di pagamento effettivo dalla sessione Stripe.
	 * Nota: `payment_method_types` è la lista configurata, non il metodo usato.
	 * Il valore esatto è disponibile solo recuperando il PaymentIntent separatamente.
	 * Qui usiamo un'euristica: se la sessione ha solo twint, usiamo 'twint', altrimenti 'carta_credito'.
	 *
	 * @param array $session_data Dati sessione da retrieve_session().
	 * @return string
	 */
	private function detect_stripe_payment_method( array $session_data ) {
		$types = (array) ( $session_data['payment_method_types'] ?? array() );
		if ( array( 'twint' ) === $types ) {
			return 'twint';
		}
		return 'carta_credito';
	}

	/**
	 * Gestisce i webhook Stripe in ingresso.
	 *
	 * URL webhook da configurare in Stripe Dashboard:
	 * https://tuosite.com/?sd_stripe_webhook=1
	 *
	 * Evento gestito: checkout.session.completed
	 *
	 * @return void
	 */
	public function handle_stripe_webhook() {
		if ( ! isset( $_GET['sd_stripe_webhook'] ) || '1' !== $_GET['sd_stripe_webhook'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$payload    = (string) file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

		$event = $this->stripe->verify_webhook( $payload, $sig_header );
		if ( is_wp_error( $event ) ) {
			error_log( '[SD Stripe Webhook] errore verifica: ' . $event->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			wp_send_json( array( 'error' => $event->get_error_message() ), 400 );
			exit;
		}

		if ( 'checkout.session.completed' === $event['type'] ) {
			$session_obj    = $event['data']['object'] ?? array();
			$pi_id          = sanitize_text_field( (string) ( $session_obj['payment_intent'] ?? '' ) );
			$payment_status = (string) ( $session_obj['payment_status'] ?? '' );

			if ( '' === $pi_id || 'paid' !== $payment_status ) {
				wp_send_json( array( 'received' => true ) );
				exit;
			}

			// Verifica idempotenza: controlla se già processato dal ritorno sincrono.
			global $wpdb;
			$db      = new SD_Database();
			$already = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$db->table('payments')} WHERE provider = 'stripe' AND provider_payment_id = %s LIMIT 1",
					$pi_id
				)
			);

			if ( $already ) {
				wp_send_json( array( 'received' => true ) );
				exit;
			}

			// Recupera member_id dal metadata della sessione.
			$metadata  = (array) ( $session_obj['metadata'] ?? array() );
			$member_id = (int) ( $metadata['member_id'] ?? 0 );
			$sd_token  = sanitize_text_field( (string) ( $metadata['sd_token'] ?? '' ) );

			// Fallback: recupera member_id dal token di pagamento.
			if ( $member_id <= 0 && '' !== $sd_token ) {
				$payment_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT member_id, amount FROM {$db->table('payments')} WHERE confirmation_token = %s ORDER BY id DESC LIMIT 1",
						$sd_token
					)
				);
				if ( $payment_row ) {
					$member_id = (int) $payment_row->member_id;
				}
			}

			if ( $member_id > 0 ) {
				$amount = isset( $session_obj['amount_total'] ) ? ( (float) $session_obj['amount_total'] / 100.0 ) : 0.0;
				( new SD_Payment_Orchestrator() )->accept_payment(
					$member_id,
					array(
						'provider'            => 'stripe',
						'provider_payment_id' => $pi_id,
						'payment_method'      => 'stripe',
						'amount'              => $amount,
						'payload_json'        => $session_obj,
						'notes'               => __( 'Pagamento confermato da webhook Stripe.', 'sd-logbook' ),
					)
				);
			}
		}

		wp_send_json( array( 'received' => true ) );
		exit;
	}

	/**
	 * Rendering pagina checkout.
	 *
	 * @return string
	 */
	public function render_checkout() {
		$token = isset( $_GET['sdpt'] ) ? sanitize_text_field( wp_unslash( $_GET['sdpt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ctx   = $this->orchestrator->get_payment_context_by_token( $token );
		if ( is_wp_error( $ctx ) ) {
			return '<div class="sd-notice sd-notice-error">' . esc_html( $ctx->get_error_message() ) . '</div>';
		}

		$checkout_action_base = $this->orchestrator->get_checkout_page_url();
		$notice               = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paypal_enabled       = (int) get_option( 'sd_payment_enable_paypal', 1 ) === 1;
		$invoice_enabled      = (int) get_option( 'sd_payment_enable_invoice', 1 ) === 1;
		$stripe_enabled       = (int) get_option( 'sd_payment_enable_stripe', 1 ) === 1;

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/payment-checkout.php';
		return ob_get_clean();
	}

	/**
	 * Rendering pagina conferma.
	 *
	 * @return string
	 */
	public function render_confirmation() {
		$token = isset( $_GET['sdpt'] ) ? sanitize_text_field( wp_unslash( $_GET['sdpt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ctx   = $this->orchestrator->get_payment_context_by_token( $token );
		if ( is_wp_error( $ctx ) ) {
			return '<div class="sd-notice sd-notice-error">' . esc_html( $ctx->get_error_message() ) . '</div>';
		}

		$receipt_url = '';
		$card_url    = '';
		$upload      = wp_upload_dir();
		if ( ! empty( $ctx->receipt_pdf_path ) && ! empty( $upload['basedir'] ) && ! empty( $upload['baseurl'] ) ) {
			$receipt_url = str_replace( trailingslashit( $upload['basedir'] ), trailingslashit( $upload['baseurl'] ), $ctx->receipt_pdf_path );
		}
		if ( ! empty( $ctx->membership_card_pdf_path ) && ! empty( $upload['basedir'] ) && ! empty( $upload['baseurl'] ) ) {
			$card_url = str_replace( trailingslashit( $upload['basedir'] ), trailingslashit( $upload['baseurl'] ), $ctx->membership_card_pdf_path );
		}

		$login_url = trim( (string) get_option( 'sd_payment_login_url', home_url( '/login/' ) ) );

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/payment-confirmation.php';
		return ob_get_clean();
	}
}
