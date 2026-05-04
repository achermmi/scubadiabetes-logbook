<?php
/**
 * Orchestrazione pagamenti e side effects.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Payment_Orchestrator {

	/**
	 * Prepara token checkout per un socio.
	 *
	 * @param int $member_id ID socio.
	 * @return array|WP_Error
	 */
	public function prepare_checkout( $member_id ) {
		global $wpdb;
		$db = new SD_Database();

		$member_id = (int) $member_id;
		if ( $member_id <= 0 ) {
			return new WP_Error( 'sd_invalid_member', __( 'Socio non valido.', 'sd-logbook' ) );
		}

		$this->ensure_member_number( $member_id );

		$payment = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('payments')} WHERE member_id = %d ORDER BY id DESC LIMIT 1",
				$member_id
			)
		);

		if ( ! $payment ) {
			return new WP_Error( 'sd_payment_missing', __( 'Pagamento non trovato.', 'sd-logbook' ) );
		}

		$token   = wp_generate_password( 32, false, false );
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

		$wpdb->update(
			$db->table( 'payments' ),
			array(
				'confirmation_token'      => $token,
				'confirmation_expires_at' => $expires,
			),
			array( 'id' => (int) $payment->id )
		);

		return array(
			'token'            => $token,
			'checkout_url'     => add_query_arg( 'sdpt', rawurlencode( $token ), $this->get_checkout_page_url() ),
			'confirmation_url' => add_query_arg( 'sdpt', rawurlencode( $token ), $this->get_confirmation_page_url() ),
		);
	}

	/**
	 * URL pagina checkout.
	 *
	 * @return string
	 */
	public function get_checkout_page_url() {
		$url = trim( (string) get_option( 'sd_payment_checkout_page_url', '' ) );
		if ( '' === $url ) {
			$url = home_url( '/pagamento-tassa-sociale/' );
		}
		return $url;
	}

	/**
	 * URL pagina conferma.
	 *
	 * @return string
	 */
	public function get_confirmation_page_url() {
		$url = trim( (string) get_option( 'sd_payment_confirmation_page_url', '' ) );
		if ( '' === $url ) {
			$url = home_url( '/conferma-pagamento/' );
		}
		return $url;
	}

	/**
	 * Ritorna dati pagamento a partire dal token.
	 *
	 * @param string $token Token checkout/conferma.
	 * @return object|WP_Error
	 */
	public function get_payment_context_by_token( $token ) {
		global $wpdb;
		$db = new SD_Database();

		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'sd_missing_token', __( 'Token mancante.', 'sd-logbook' ) );
		}

		$ctx = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.*, m.first_name, m.last_name, m.email, m.member_number, m.member_type, m.date_of_birth, m.sotto_tutela, m.guardian_email, m.has_paid_fee, m.membership_expiry
				 FROM {$db->table('payments')} p
				 INNER JOIN {$db->table('members')} m ON m.id = p.member_id
				 WHERE p.confirmation_token = %s
				 ORDER BY p.id DESC LIMIT 1",
				$token
			)
		);

		if ( ! $ctx ) {
			return new WP_Error( 'sd_invalid_token', __( 'Token non valido.', 'sd-logbook' ) );
		}

		if ( ! empty( $ctx->confirmation_expires_at ) && strtotime( (string) $ctx->confirmation_expires_at ) < time() ) {
			return new WP_Error( 'sd_expired_token', __( 'Token scaduto.', 'sd-logbook' ) );
		}

		return $ctx;
	}

	/**
	 * Accetta un pagamento e applica side effects.
	 *
	 * @param int   $member_id ID socio.
	 * @param array $args Dati pagamento.
	 * @return array|WP_Error
	 */
	public function accept_payment( $member_id, $args ) {
		global $wpdb;
		$db = new SD_Database();

		$member_id            = (int) $member_id;
		$provider             = sanitize_text_field( (string) ( $args['provider'] ?? 'manual' ) );
		$provider_payment_id  = sanitize_text_field( (string) ( $args['provider_payment_id'] ?? '' ) );
		$payment_method       = sanitize_text_field( (string) ( $args['payment_method'] ?? $provider ) );
		$notes                = sanitize_textarea_field( (string) ( $args['notes'] ?? '' ) );
		$payload_json         = isset( $args['payload_json'] ) ? wp_json_encode( $args['payload_json'] ) : null;
		$payment_date         = ! empty( $args['payment_date'] ) ? sanitize_text_field( (string) $args['payment_date'] ) : current_time( 'mysql' );
		$payment_year         = (int) gmdate( 'Y', strtotime( $payment_date ) );

		$member = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('members')} WHERE id = %d",
				$member_id
			)
		);

		if ( ! $member ) {
			return new WP_Error( 'sd_member_not_found', __( 'Socio non trovato.', 'sd-logbook' ) );
		}

		$this->ensure_member_number( $member_id );

		$payment = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('payments')} WHERE member_id = %d ORDER BY id DESC LIMIT 1",
				$member_id
			)
		);
		if ( ! $payment ) {
			return new WP_Error( 'sd_payment_not_found', __( 'Record pagamento non trovato.', 'sd-logbook' ) );
		}

		if ( '' !== $provider_payment_id ) {
			$already = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$db->table('payments')} WHERE provider = %s AND provider_payment_id = %s AND id != %d LIMIT 1",
					$provider,
					$provider_payment_id,
					(int) $payment->id
				)
			);
			if ( $already ) {
				return array(
					'status'      => 'already_processed',
					'member_id'   => $member_id,
					'payment_id'  => (int) $already,
					'redirect_to' => add_query_arg( 'sdpt', rawurlencode( (string) $payment->confirmation_token ), $this->get_confirmation_page_url() ),
				);
			}
		}

		$amount = isset( $args['amount'] ) ? (float) $args['amount'] : (float) $payment->amount;
		if ( $amount <= 0 ) {
			$amount = (float) $member->fee_amount;
		}

		$expiry = gmdate( 'Y-m-d', strtotime( '+1 year', strtotime( $payment_date ) ) );

		$wpdb->update(
			$db->table( 'members' ),
			array(
				'has_paid_fee'      => 1,
				'is_active'         => 1,
				'membership_expiry' => $expiry,
			),
			array( 'id' => $member_id )
		);

		$update_data = array(
			'amount'                 => $amount,
			'payment_date'           => $payment_date,
			'payment_method'         => $payment_method,
			'payment_year'           => $payment_year,
			'status'                 => 'completato',
			'provider'               => $provider,
			'provider_payment_id'    => $provider_payment_id,
			'provider_status'        => 'completed',
			'payload_json'           => $payload_json,
			'completed_at'           => current_time( 'mysql' ),
			'transaction_id'         => $provider_payment_id,
			'notes'                  => $notes,
			'registered_by'          => get_current_user_id(),
		);
		$wpdb->update( $db->table( 'payments' ), $update_data, array( 'id' => (int) $payment->id ) );

		$this->cascade_family_payment( $member_id, $payment_date, $payment_year, $payment_method, $provider, $notes );

		$member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$db->table('members')} WHERE id = %d", $member_id ) );
		$payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$db->table('payments')} WHERE id = %d", (int) $payment->id ) );

		$docs  = ( new SD_Payment_Documents() )->generate_documents( $member, $payment );
		if ( ! is_wp_error( $docs ) ) {
			$wpdb->update(
				$db->table( 'payments' ),
				array(
					'receipt_pdf_path'         => $docs['receipt'],
					'membership_card_pdf_path' => $docs['card'],
				),
				array( 'id' => (int) $payment->id )
			);
		}

		$this->send_post_payment_emails( $member_id, (array) $docs );

		$redirect_url = add_query_arg( 'sdpt', rawurlencode( (string) $payment->confirmation_token ), $this->get_confirmation_page_url() );

		return array(
			'status'      => 'completed',
			'member_id'   => $member_id,
			'payment_id'  => (int) $payment->id,
			'redirect_to' => $redirect_url,
		);
	}

	/**
	 * Registra richiesta pagamento con fattura (stato in attesa).
	 *
	 * @param int   $member_id ID socio.
	 * @param array $args Dati pagamento.
	 * @return array|WP_Error
	 */
	public function request_invoice_payment( $member_id, $args = array() ) {
		global $wpdb;
		$db = new SD_Database();

		$member_id = (int) $member_id;
		if ( $member_id <= 0 ) {
			return new WP_Error( 'sd_invalid_member', __( 'Socio non valido.', 'sd-logbook' ) );
		}

		$this->ensure_member_number( $member_id );

		$member = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('members')} WHERE id = %d",
				$member_id
			)
		);
		if ( ! $member ) {
			return new WP_Error( 'sd_member_not_found', __( 'Socio non trovato.', 'sd-logbook' ) );
		}

		$payment = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('payments')} WHERE member_id = %d ORDER BY id DESC LIMIT 1",
				$member_id
			)
		);
		if ( ! $payment ) {
			return new WP_Error( 'sd_payment_not_found', __( 'Record pagamento non trovato.', 'sd-logbook' ) );
		}

		$provider_payment_id = ! empty( $args['provider_payment_id'] )
			? sanitize_text_field( (string) $args['provider_payment_id'] )
			: 'fattura-' . (int) $payment->id . '-' . gmdate( 'YmdHis' );
		$amount = isset( $args['amount'] ) ? (float) $args['amount'] : (float) $payment->amount;
		if ( $amount <= 0 ) {
			$amount = (float) $member->fee_amount;
		}

		$wpdb->update(
			$db->table( 'members' ),
			array(
				'has_paid_fee' => 0,
				'is_active'    => 0,
			),
			array( 'id' => $member_id )
		);

		$wpdb->update(
			$db->table( 'payments' ),
			array(
				'amount'                 => $amount,
				'payment_method'         => 'fattura',
				'payment_year'           => ! empty( $payment->payment_year ) ? (int) $payment->payment_year : (int) gmdate( 'Y' ),
				'status'                 => 'in_attesa',
				'provider'               => 'fattura',
				'provider_payment_id'    => $provider_payment_id,
				'provider_status'        => 'pending',
				'payload_json'           => null,
				'completed_at'           => null,
				'transaction_id'         => $provider_payment_id,
				'notes'                  => __( 'Fattura generata e inviata al socio. In attesa accredito.', 'sd-logbook' ),
				'registered_by'          => get_current_user_id(),
				'membership_card_pdf_path' => null,
				'is_activation_email_sent' => 0,
			),
			array( 'id' => (int) $payment->id )
		);

		$member  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$db->table('members')} WHERE id = %d", $member_id ) );
		$payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$db->table('payments')} WHERE id = %d", (int) $payment->id ) );

		$invoice_pdf = ( new SD_Payment_Documents() )->generate_invoice_document( $member, $payment );
		if ( ! is_wp_error( $invoice_pdf ) ) {
			$wpdb->update(
				$db->table( 'payments' ),
				array( 'receipt_pdf_path' => $invoice_pdf ),
				array( 'id' => (int) $payment->id )
			);
		}

		$this->send_invoice_email( $member_id, ! is_wp_error( $invoice_pdf ) ? (string) $invoice_pdf : '' );

		$redirect_url = add_query_arg( 'sdpt', rawurlencode( (string) $payment->confirmation_token ), $this->get_confirmation_page_url() );

		return array(
			'status'      => 'pending_invoice',
			'member_id'   => $member_id,
			'payment_id'  => (int) $payment->id,
			'redirect_to' => $redirect_url,
		);
	}

	/**
	 * Aggiorna famigliari collegati al capo famiglia.
	 *
	 * @param int    $member_id Intestatario.
	 * @param string $payment_date Data pagamento.
	 * @param int    $payment_year Anno pagamento.
	 * @param string $payment_method Metodo pagamento.
	 * @param string $provider Provider pagamento.
	 * @param string $notes Note.
	 * @return void
	 */
	private function cascade_family_payment( $member_id, $payment_date, $payment_year, $payment_method, $provider, $notes ) {
		global $wpdb;
		$db = new SD_Database();

		$family_members = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('members')} WHERE parent_member_id = %d",
				$member_id
			)
		);

		if ( empty( $family_members ) ) {
			return;
		}

		foreach ( $family_members as $fm ) {
			$expiry = gmdate( 'Y-m-d', strtotime( '+1 year', strtotime( $payment_date ) ) );
			$wpdb->update(
				$db->table( 'members' ),
				array(
					'has_paid_fee'      => 1,
					'is_active'         => 1,
					'member_type'       => 'attivo_famigliare',
					'membership_expiry' => $expiry,
				),
				array( 'id' => (int) $fm->id )
			);

			$this->ensure_member_number( (int) $fm->id );

			$existing_fm_payment = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$db->table('payments')} WHERE member_id = %d AND payment_year = %d ORDER BY id DESC LIMIT 1",
					(int) $fm->id,
					(int) $payment_year
				)
			);

			$fm_data = array(
				'member_id'               => (int) $fm->id,
				'amount'                  => 0.00,
				'currency'                => 'CHF',
				'payment_date'            => $payment_date,
				'payment_method'          => $payment_method,
				'payment_year'            => (int) $payment_year,
				'status'                  => 'completato',
				'provider'                => $provider,
				'provider_status'         => 'completed',
				'notes'                   => $notes,
				'registered_by'           => get_current_user_id(),
				'completed_at'            => current_time( 'mysql' ),
			);

			if ( $existing_fm_payment ) {
				$wpdb->update( $db->table( 'payments' ), $fm_data, array( 'id' => (int) $existing_fm_payment->id ) );
			} else {
				$wpdb->insert( $db->table( 'payments' ), $fm_data );
			}
		}
	}

	/**
	 * Invia email post-pagamento.
	 *
	 * @param int   $member_id ID socio.
	 * @param array $docs Path documenti.
	 * @return void
	 */
	private function send_post_payment_emails( $member_id, $docs ) {
		global $wpdb;
		$db     = new SD_Database();
		$member = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('members')} WHERE id = %d",
				$member_id
			)
		);
		if ( ! $member ) {
			return;
		}

		$payment = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('payments')} WHERE member_id = %d ORDER BY id DESC LIMIT 1",
				$member_id
			)
		);
		if ( ! $payment || (int) $payment->is_activation_email_sent === 1 ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: year */
			__( '[%1$s] Pagamento confermato - Iscrizione %2$s', 'sd-logbook' ),
			get_bloginfo( 'name' ),
			gmdate( 'Y' )
		);

		$login_url = trim( (string) get_option( 'sd_payment_login_url', home_url( '/login/' ) ) );
		$reset_url = wp_lostpassword_url();

		$body  = '<html><body style="font-family:Arial,sans-serif;color:#333;">';
		$body .= '<h2 style="color:#0055a5;">' . esc_html__( 'Pagamento confermato', 'sd-logbook' ) . '</h2>';
		$body .= '<p>' . sprintf( esc_html__( 'Grazie %s, benvenuto/a nell\'associazione per l\'anno in corso.', 'sd-logbook' ), esc_html( trim( $member->first_name . ' ' . $member->last_name ) ) ) . '</p>';
		$body .= '<ul>';
		$body .= '<li><strong>' . esc_html__( 'Username:', 'sd-logbook' ) . '</strong> ' . esc_html( $member->email ) . '</li>';
		$body .= '<li><strong>' . esc_html__( 'Login:', 'sd-logbook' ) . '</strong> <a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a></li>';
		$body .= '<li><strong>' . esc_html__( 'Imposta/Reimposta password:', 'sd-logbook' ) . '</strong> <a href="' . esc_url( $reset_url ) . '">' . esc_html( $reset_url ) . '</a></li>';
		$body .= '</ul>';
		$body .= '<p>' . esc_html__( 'In allegato trovi ricevuta e tessera associativa.', 'sd-logbook' ) . '</p>';
		$body .= '</body></html>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( (int) $member->sotto_tutela === 1 && ! empty( $member->guardian_email ) ) {
			$headers[] = 'Cc: ' . $member->email;
			$to = $member->guardian_email;
		} else {
			$to = $member->email;
		}

		$attachments = array();
		if ( ! empty( $docs['receipt'] ) && file_exists( $docs['receipt'] ) ) {
			$attachments[] = $docs['receipt'];
		}
		if ( ! empty( $docs['card'] ) && file_exists( $docs['card'] ) ) {
			$attachments[] = $docs['card'];
		}

		wp_mail( $to, $subject, $body, $headers, $attachments );

		$wpdb->update(
			$db->table( 'payments' ),
			array( 'is_activation_email_sent' => 1 ),
			array( 'id' => (int) $payment->id )
		);
	}

	/**
	 * Invia email di richiesta pagamento con fattura allegata.
	 *
	 * @param int    $member_id ID socio.
	 * @param string $invoice_pdf_path Path fattura PDF.
	 * @return void
	 */
	private function send_invoice_email( $member_id, $invoice_pdf_path ) {
		global $wpdb;
		$db     = new SD_Database();
		$member = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('members')} WHERE id = %d",
				$member_id
			)
		);
		if ( ! $member ) {
			return;
		}

		$payment = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('payments')} WHERE member_id = %d ORDER BY id DESC LIMIT 1",
				$member_id
			)
		);
		if ( ! $payment || (int) $payment->is_activation_email_sent === 1 ) {
			return;
		}

		$year = ! empty( $payment->payment_year ) ? (int) $payment->payment_year : (int) gmdate( 'Y' );
		$subject = sprintf(
			/* translators: 1: site name, 2: year */
			__( '[%1$s] Fattura tassa sociale %2$d', 'sd-logbook' ),
			get_bloginfo( 'name' ),
			$year
		);

		$association_name = (string) get_option( 'sd_payment_invoice_association_name', get_option( 'sd_payment_association_title', get_bloginfo( 'name' ) ) );
		$bank_iban        = (string) get_option( 'sd_payment_invoice_bank_iban', '' );
		$bank_swift       = (string) get_option( 'sd_payment_invoice_bank_swift', '' );
		$bank_bic         = (string) get_option( 'sd_payment_invoice_bank_bic', '' );
		$qr_payload       = (string) get_option( 'sd_payment_invoice_qr_payload', '' );

		$body  = '<html><body style="font-family:Arial,sans-serif;color:#333;">';
		$body .= '<h2 style="color:#0055a5;">' . esc_html__( 'Fattura tassa sociale', 'sd-logbook' ) . '</h2>';
		$body .= '<p>' . sprintf( esc_html__( 'Ciao %s, in allegato trovi la fattura per completare il pagamento della quota associativa.', 'sd-logbook' ), esc_html( trim( $member->first_name . ' ' . $member->last_name ) ) ) . '</p>';
		$body .= '<ul>';
		$body .= '<li><strong>' . esc_html__( 'Associazione:', 'sd-logbook' ) . '</strong> ' . esc_html( $association_name ) . '</li>';
		$body .= '<li><strong>' . esc_html__( 'IBAN:', 'sd-logbook' ) . '</strong> ' . esc_html( $bank_iban ) . '</li>';
		$body .= '<li><strong>' . esc_html__( 'SWIFT/BIC:', 'sd-logbook' ) . '</strong> ' . esc_html( trim( $bank_swift . ' ' . $bank_bic ) ) . '</li>';
		$body .= '</ul>';
		if ( '' !== trim( $qr_payload ) ) {
			$body .= '<p><strong>' . esc_html__( 'Payload QR pagamento:', 'sd-logbook' ) . '</strong><br>' . nl2br( esc_html( $qr_payload ) ) . '</p>';
		}
		$body .= '<p>' . esc_html__( 'Lo stato rimane in attesa fino alla verifica dell accredito. La tessera verrà emessa dopo la conferma pagamento.', 'sd-logbook' ) . '</p>';
		$body .= '</body></html>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( (int) $member->sotto_tutela === 1 && ! empty( $member->guardian_email ) ) {
			$headers[] = 'Cc: ' . $member->email;
			$to = $member->guardian_email;
		} else {
			$to = $member->email;
		}

		$attachments = array();
		if ( '' !== trim( $invoice_pdf_path ) && file_exists( $invoice_pdf_path ) ) {
			$attachments[] = $invoice_pdf_path;
		}

		wp_mail( $to, $subject, $body, $headers, $attachments );
	}

	/**
	 * Assicura presenza numero socio dedicato.
	 *
	 * @param int $member_id ID socio.
	 * @return string
	 */
	public function ensure_member_number( $member_id ) {
		global $wpdb;
		$db = new SD_Database();

		$member = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, member_number, member_since FROM {$db->table('members')} WHERE id = %d",
				(int) $member_id
			)
		);
		if ( ! $member ) {
			return '';
		}
		if ( ! empty( $member->member_number ) ) {
			return (string) $member->member_number;
		}

		$year = ! empty( $member->member_since ) ? gmdate( 'Y', strtotime( $member->member_since ) ) : gmdate( 'Y' );
		$number = sprintf( 'SD-%s-%06d', $year, (int) $member->id );
		$wpdb->update(
			$db->table( 'members' ),
			array( 'member_number' => $number ),
			array( 'id' => (int) $member->id )
		);

		return $number;
	}
}
