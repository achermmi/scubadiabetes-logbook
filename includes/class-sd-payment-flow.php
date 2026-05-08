<?php
/**
 * Checkout + conferma pagamento (frontend pubblico).
 *
 * Shortcodes:
 * - [sd_payment_checkout]
 * - [sd_payment_confirmation]
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
	 * @var SD_Payment_Adapter
	 */
	private $twint;

	/**
	 * Istanzia l'adapter TWINT corretto in base all'impostazione sd_payment_twint_provider.
	 *
	 * @return SD_Payment_Adapter
	 */
	private function make_twint_adapter() {
		$provider = get_option( 'sd_payment_twint_provider', 'direct' );
		if ( 'infomaniak' === $provider ) {
			return new SD_Payment_Twint_Infomaniak();
		}
		return new SD_Payment_Twint();
	}

	public function __construct() {
		$this->orchestrator = new SD_Payment_Orchestrator();
		$this->paypal       = new SD_Payment_PayPal();
		$this->twint        = $this->make_twint_adapter();

		add_shortcode( 'sd_payment_checkout', array( $this, 'render_checkout' ) );
		add_shortcode( 'sd_payment_confirmation', array( $this, 'render_confirmation' ) );
		add_action( 'template_redirect', array( $this, 'handle_actions' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_sd_twint_poll', array( $this, 'ajax_twint_poll' ) );
		add_action( 'wp_ajax_nopriv_sd_twint_poll', array( $this, 'ajax_twint_poll' ) );
	}

	/**
	 * Gestisce azioni pubbliche di checkout.
	 *
	 * @return void
	 */
	public function handle_actions() {
		$action          = isset( $_GET['sd_payment_action'] ) ? sanitize_text_field( wp_unslash( $_GET['sd_payment_action'] ) ) : '';
		$token           = isset( $_GET['sdpt'] ) ? sanitize_text_field( wp_unslash( $_GET['sdpt'] ) ) : '';
		$paypal_enabled  = (int) get_option( 'sd_payment_enable_paypal', 1 ) === 1;
		$invoice_enabled = (int) get_option( 'sd_payment_enable_invoice', 1 ) === 1;
		$twint_enabled   = (int) get_option( 'sd_payment_enable_twint_stub', 0 ) === 1;

		if ( '' === $action || '' === $token ) {
			return;
		}

		$ctx = $this->orchestrator->get_payment_context_by_token( $token );
		if ( is_wp_error( $ctx ) ) {
			error_log( '[SD Payment] handle_actions early exit – action=' . $action . ' token=' . $token . ' error=' . $ctx->get_error_code() . ': ' . $ctx->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

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
				error_log( '[SD PayPal] create_order error: ' . $order->get_error_code() . ' — ' . $order->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				$redirect = add_query_arg(
					array(
						'sdpt'   => rawurlencode( $token ),
						'notice' => 'paypal_error',
					),
					$this->orchestrator->get_checkout_page_url()
				);
				wp_safe_redirect( $redirect );
				exit;
			}

			// PayPal approval URL è un dominio esterno (sandbox.paypal.com / paypal.com):
			// wp_safe_redirect() blocca i redirect esterni e li manda al fallback locale → 404.
			// Usiamo wp_redirect() che non ha questa restrizione.
			wp_redirect( esc_url_raw( $order['approval_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		if ( 'paypal_return' === $action ) {
			$order_id = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
			if ( '' === $order_id ) {
				$redirect = add_query_arg(
					array(
						'sdpt'   => rawurlencode( $token ),
						'notice' => 'paypal_missing_order',
					),
					$this->orchestrator->get_checkout_page_url()
				);
				wp_safe_redirect( $redirect );
				exit;
			}

			$capture = $this->paypal->capture_order( $order_id );
			if ( is_wp_error( $capture ) || ! in_array( $capture['status'], array( 'COMPLETED', 'APPROVED' ), true ) ) {
				$redirect = add_query_arg(
					array(
						'sdpt'   => rawurlencode( $token ),
						'notice' => 'paypal_capture_failed',
					),
					$this->orchestrator->get_checkout_page_url()
				);
				wp_safe_redirect( $redirect );
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

		if ( 'start_twint' === $action ) {
			if ( ! $twint_enabled ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdpt'   => rawurlencode( $token ),
							'notice' => 'twint_disabled',
						),
						$this->orchestrator->get_checkout_page_url()
					)
				);
				exit;
			}

			$twint_provider = get_option( 'sd_payment_twint_provider', 'direct' );

			if ( 'infomaniak' === $twint_provider ) {
				// ---- Flusso Infomaniak: redirect alla pagina TWINT di Infomaniak ----.
				$ok_url = add_query_arg(
					array(
						'sd_payment_action' => 'twint_ik_return',
						'sdpt'              => rawurlencode( $token ),
					),
					$this->orchestrator->get_checkout_page_url()
				);
				$cancel_url = add_query_arg(
					array(
						'sdpt'   => rawurlencode( $token ),
						'notice' => 'twint_cancelled',
					),
					$this->orchestrator->get_checkout_page_url()
				);

				$order = $this->twint->create_order(
					array(
						'amount'     => (float) $ctx->amount,
						'currency'   => ! empty( $ctx->currency ) ? (string) $ctx->currency : 'CHF',
						'return_url' => $ok_url,
						'cancel_url' => $cancel_url,
					)
				);

				if ( is_wp_error( $order ) ) {
					error_log( '[SD TWINT IK] start_twint create_order error: ' . $order->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					wp_safe_redirect(
						add_query_arg(
							array(
								'sdpt'   => rawurlencode( $token ),
								'notice' => 'twint_error',
							),
							$this->orchestrator->get_checkout_page_url()
						)
					);
					exit;
				}

				set_transient(
					'sd_twint_order_' . $token,
					array(
						'order_id'  => $order['order_id'],
						'member_id' => (int) $ctx->member_id,
						'amount'    => (float) $ctx->amount,
						'currency'  => ! empty( $ctx->currency ) ? (string) $ctx->currency : 'CHF',
					),
					900
				);

				// Redirect a pagina esterna Infomaniak (non wp_safe_redirect che blocca domini esterni).
				wp_redirect( esc_url_raw( $order['approval_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}

			// ---- Flusso diretto TWINT: mostra QR in-page ----.
			$confirm_url = add_query_arg(
				array(
					'sd_payment_action' => 'twint_return',
					'sdpt'              => rawurlencode( $token ),
				),
				$this->orchestrator->get_checkout_page_url()
			);

			$order = $this->twint->create_order(
				array(
					'reference_id' => 'member-' . (int) $ctx->member_id . '-' . gmdate( 'YmdHis' ),
					'amount'       => (float) $ctx->amount,
					'currency'     => ! empty( $ctx->currency ) ? (string) $ctx->currency : 'CHF',
					'return_url'   => $confirm_url,
				)
			);

			if ( is_wp_error( $order ) ) {
				error_log( '[SD TWINT] start_twint create_order error: ' . $order->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdpt'   => rawurlencode( $token ),
							'notice' => 'twint_error',
						),
						$this->orchestrator->get_checkout_page_url()
					)
				);
				exit;
			}

			// Salva i dati TWINT in un transient legato al token (TTL 15 minuti).
			set_transient(
				'sd_twint_order_' . $token,
				array(
					'order_uuid'    => $order['order_uuid'],
					'pairing_token' => $order['pairing_token'],
					'qr_code_svg'   => $order['qr_code_svg'],
					'deep_link'     => $order['deep_link'],
					'member_id'     => (int) $ctx->member_id,
					'amount'        => (float) $ctx->amount,
					'currency'      => ! empty( $ctx->currency ) ? (string) $ctx->currency : 'CHF',
				),
				900
			);

			wp_safe_redirect(
				add_query_arg(
					array(
						'sdpt'        => rawurlencode( $token ),
						'twint_order' => rawurlencode( $order['order_uuid'] ),
					),
					$this->orchestrator->get_checkout_page_url()
				)
			);
			exit;
		}

		if ( 'twint_ik_return' === $action ) {
			// Ritorno dalla pagina di pagamento Infomaniak TWINT.
			$twint_data = get_transient( 'sd_twint_order_' . $token );
			if ( empty( $twint_data['order_id'] ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'sdpt'   => rawurlencode( $token ),
							'notice' => 'twint_error',
						),
						$this->orchestrator->get_checkout_page_url()
					)
				);
				exit;
			}

			$result = $this->twint->get_order( $twint_data['order_id'] );
			if ( is_wp_error( $result ) || 'paid' !== $result['status'] ) {
				$notice = is_wp_error( $result ) ? 'twint_error' : 'twint_cancelled';
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

			$accept = $this->orchestrator->accept_payment(
				(int) $twint_data['member_id'],
				array(
					'provider'            => 'twint',
					'provider_payment_id' => $twint_data['order_id'],
					'payment_method'      => 'twint',
					'amount'              => (float) $twint_data['amount'],
					'payload_json'        => wp_json_encode( $result['payload'] ),
					'notes'               => __( 'Pagamento TWINT confermato via Infomaniak.', 'sd-logbook' ),
				)
			);
			delete_transient( 'sd_twint_order_' . $token );

			$redirect = $this->orchestrator->get_confirmation_page_url();
			if ( ! is_wp_error( $accept ) && ! empty( $accept['redirect_to'] ) ) {
				$redirect = $accept['redirect_to'];
			}
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( 'twint_cancel' === $action ) {
			$twint_data = get_transient( 'sd_twint_order_' . $token );
			if ( ! empty( $twint_data['order_uuid'] ) ) {
				$this->twint->cancel_order( $twint_data['order_uuid'] );
				delete_transient( 'sd_twint_order_' . $token );
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'sdpt'   => rawurlencode( $token ),
						'notice' => 'twint_cancelled',
					),
					$this->orchestrator->get_checkout_page_url()
				)
			);
			exit;
		}
	}

	/**
	 * AJAX: polling stato ordine TWINT.
	 *
	 * @return void
	 */
	public function ajax_twint_poll() {
		check_ajax_referer( 'sd_twint_poll', 'nonce' );

		$token = isset( $_POST['sdpt'] ) ? sanitize_text_field( wp_unslash( $_POST['sdpt'] ) ) : '';
		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => 'Token mancante.' ), 400 );
			return;
		}

		$twint_data = get_transient( 'sd_twint_order_' . $token );
		if ( empty( $twint_data['order_uuid'] ) ) {
			wp_send_json_error( array( 'message' => 'Sessione TWINT scaduta o non trovata.' ), 404 );
			return;
		}

		$result = $this->twint->get_order( $twint_data['order_uuid'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
			return;
		}

		$status = $result['status'];

		if ( 'SUCCESS' === $status ) {
			// Accetta il pagamento nel sistema.
			$accept = $this->orchestrator->accept_payment(
				(int) $twint_data['member_id'],
				array(
					'provider'            => 'twint',
					'provider_payment_id' => $twint_data['order_uuid'],
					'payment_method'      => 'twint',
					'amount'              => (float) $twint_data['amount'],
					'payload_json'        => wp_json_encode( $result['payload'] ),
					'notes'               => __( 'Pagamento TWINT confermato.', 'sd-logbook' ),
				)
			);
			delete_transient( 'sd_twint_order_' . $token );

			$redirect = $this->orchestrator->get_confirmation_page_url();
			if ( ! is_wp_error( $accept ) && ! empty( $accept['redirect_to'] ) ) {
				$redirect = $accept['redirect_to'];
			}

			wp_send_json_success(
				array(
					'status'       => 'SUCCESS',
					'redirect_url' => esc_url_raw( $redirect ),
				)
			);
			return;
		}

		if ( in_array( $status, array( 'FAILURE', 'REVERSED' ), true ) ) {
			delete_transient( 'sd_twint_order_' . $token );
			wp_send_json_success( array( 'status' => 'FAILURE' ) );
			return;
		}

		// IN_PROGRESS o UNKNOWN: continua polling.
		wp_send_json_success( array( 'status' => 'IN_PROGRESS' ) );
	}

	/**
	 * Registra gli asset di pagamento (script JS).
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_script(
			'sd-twint-checkout',
			plugin_dir_url( __DIR__ ) . 'assets/js/twint-checkout.js',
			array(),
			SD_LOGBOOK_VERSION,
			true
		);
	}

	/**
	 * Rendering pagina checkout.
	 *
	 * @return string
	 */
	public function render_checkout() {
		$token = isset( $_GET['sdpt'] ) ? sanitize_text_field( wp_unslash( $_GET['sdpt'] ) ) : '';
		$ctx   = $this->orchestrator->get_payment_context_by_token( $token );
		if ( is_wp_error( $ctx ) ) {
			return '<div class="sd-notice sd-notice-error">' . esc_html( $ctx->get_error_message() ) . '</div>';
		}

		$checkout_action_base = $this->orchestrator->get_checkout_page_url();
		$notice               = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( $_GET['notice'] ) ) : '';
		$paypal_enabled       = (int) get_option( 'sd_payment_enable_paypal', 1 ) === 1;
		$invoice_enabled      = (int) get_option( 'sd_payment_enable_invoice', 1 ) === 1;
		$twint_enabled        = (int) get_option( 'sd_payment_enable_twint_stub', 1 ) === 1;

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
		$token = isset( $_GET['sdpt'] ) ? sanitize_text_field( wp_unslash( $_GET['sdpt'] ) ) : '';
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
