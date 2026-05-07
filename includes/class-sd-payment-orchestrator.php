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
				'payment_method'         => 'bonifico_iban',
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
	 * Invia email post-pagamento con dati completi, credenziali e ricevuta.
	 *
	 * @param int   $member_id ID socio.
	 * @param array $docs Path documenti.
	 * @return void
	 */
	public function send_post_payment_emails( $member_id, $docs, $force = false ) {
		global $wpdb;
		$db     = new SD_Database();
		error_log( 'SD send_post_payment_emails: start member_id=' . $member_id . ' force=' . ( $force ? '1' : '0' ) );
		$member = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('members')} WHERE id = %d",
				$member_id
			)
		);
		if ( ! $member ) {
			error_log( 'SD send_post_payment_emails: member not found id=' . $member_id );
			return;
		}

		$payment = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('payments')} WHERE member_id = %d ORDER BY id DESC LIMIT 1",
				$member_id
			)
		);
		if ( ! $payment ) {
			error_log( 'SD send_post_payment_emails: payment not found for member_id=' . $member_id );
			return;
		}
		if ( ! $force && 1 === (int) $payment->is_activation_email_sent ) {
			error_log( 'SD send_post_payment_emails: skipped (already sent) member_id=' . $member_id );
			return;
		}

		$year = ! empty( $payment->payment_year ) ? (int) $payment->payment_year : (int) gmdate( 'Y' );

		$subject = sprintf(
			/* translators: 1: site name, 2: year */
			__( '[%1$s] Pagamento confermato — Iscrizione %2$d attivata', 'sd-logbook' ),
			get_bloginfo( 'name' ),
			$year
		);

		// Recupera default_shared da diver_profiles per rigenerare la password iniziale.
		$default_shared = 1;
		if ( ! empty( $member->wp_user_id ) ) {
			$profile = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT default_shared_for_research FROM {$db->table('diver_profiles')} WHERE user_id = %d",
					(int) $member->wp_user_id
				)
			);
			if ( $profile ) {
				$default_shared = (int) $profile->default_shared_for_research;
			}
		}

		$is_diabetic = 'non_diabetico' !== (string) $member->diabetes_type;
		$plain_pass  = SD_Membership_Helper::generate_password(
			(string) $member->last_name,
			(string) $member->date_of_birth,
			(string) $member->first_name,
			(string) $member->address_postal,
			$is_diabetic,
			$default_shared
		);

		$login_url   = trim( (string) get_option( 'sd_payment_login_url', home_url( '/login/' ) ) );
		$member_name = esc_html( trim( (string) $member->first_name . ' ' . (string) $member->last_name ) );

		$body  = '<html><body style="font-family:Arial,sans-serif;color:#333;max-width:680px;margin:auto">';
		$body .= '<h2 style="color:#0055a5">' . esc_html__( 'Pagamento confermato — Iscrizione attivata!', 'sd-logbook' ) . '</h2>';
		$body .= '<p>' . sprintf(
			esc_html__( 'Caro/a %1$s, il tuo pagamento è stato ricevuto e la tua iscrizione per l\'anno %2$d è ora attiva.', 'sd-logbook' ),
			$member_name,
			$year
		) . '</p>';

		$body .= '<h3 style="color:#0055a5">' . esc_html__( 'Credenziali di accesso al portale', 'sd-logbook' ) . '</h3>';
		$body .= '<table style="border-collapse:collapse">';
		$body .= '<tr><th style="background:#f0f4f8;padding:6px 12px;text-align:left;border:1px solid #d0d7de">';
		$body .= esc_html__( 'Username', 'sd-logbook' ) . '</th>';
		$body .= '<td style="padding:6px 12px;border:1px solid #d0d7de">' . esc_html( (string) $member->email ) . '</td></tr>';
		$body .= '<tr><th style="background:#f0f4f8;padding:6px 12px;text-align:left;border:1px solid #d0d7de">';
		$body .= esc_html__( 'Password', 'sd-logbook' ) . '</th>';
		$body .= '<td style="padding:6px 12px;border:1px solid #d0d7de;font-family:monospace">' . esc_html( $plain_pass ) . '</td></tr>';
		$body .= '</table>';
		$body .= '<p><a href="' . esc_url( $login_url ) . '" style="background:#0055a5;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;display:inline-block;margin-top:8px">';
		$body .= esc_html__( 'Accedi al portale', 'sd-logbook' ) . '</a></p>';
		$body .= '<p style="font-size:12px;color:#666">' . esc_html__( 'Ti consigliamo di cambiare la password al primo accesso.', 'sd-logbook' ) . '</p>';

		$body .= '<h3 style="color:#0055a5">' . esc_html__( 'Riepilogo dati iscrizione', 'sd-logbook' ) . '</h3>';
		$body .= $this->build_member_data_html( $member, $payment );

		$body .= '<h3 style="color:#0055a5">' . esc_html__( 'Documenti allegati', 'sd-logbook' ) . '</h3>';
		$body .= '<ul>';
		$body .= '<li>' . esc_html__( 'Ricevuta di pagamento — valida per la detrazione fiscale in Svizzera (CH) e in Italia (IT)', 'sd-logbook' ) . '</li>';
		$body .= '<li>' . esc_html__( 'Tessera associativa', 'sd-logbook' ) . '</li>';
		$body .= '</ul>';
		$body .= '</body></html>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $this->needs_guardian_as_to( $member ) ) {
			$to        = (string) $member->guardian_email;
			$headers[] = 'Cc: ' . (string) $member->email;
		} else {
			$to = (string) $member->email;
		}

		$attachments = array();
		if ( ! empty( $docs['receipt'] ) && file_exists( $docs['receipt'] ) ) {
			$attachments[] = $docs['receipt'];
		}
		if ( ! empty( $docs['card'] ) && file_exists( $docs['card'] ) ) {
			$attachments[] = $docs['card'];
		}

		error_log( 'SD send_post_payment_emails: calling wp_mail to=' . $to . ' subject=' . $subject . ' attachments=' . count( $attachments ) );
		$mail_result = wp_mail( $to, $subject, $body, $headers, $attachments );
		error_log( 'SD send_post_payment_emails: wp_mail result=' . ( $mail_result ? '1' : '0' ) . ' member_id=' . $member_id );

		$wpdb->update(
			$db->table( 'payments' ),
			array( 'is_activation_email_sent' => 1 ),
			array( 'id' => (int) $payment->id )
		);
	}

	/**
	 * Wrapper pubblico per reinvio email fattura da admin.
	 *
	 * @param int    $member_id        ID socio.
	 * @param string $invoice_pdf_path Path fattura PDF.
	 * @return void
	 */
	public function resend_invoice_email_public( $member_id, $invoice_pdf_path ) {
		return $this->send_invoice_email( $member_id, $invoice_pdf_path, true );
	}

	/**
	 * Invia email di conferma iscrizione con fattura allegata (percorso bonifico).
	 *
	 * @param int    $member_id        ID socio.
	 * @param string $invoice_pdf_path Path fattura PDF.
	 * @param bool   $force            Se true bypassa il controllo is_activation_email_sent.
	 * @return bool True se wp_mail ha avuto successo.
	 */
	private function send_invoice_email( $member_id, $invoice_pdf_path, $force = false ) {
		global $wpdb;
		$db     = new SD_Database();
		error_log( 'SD send_invoice_email: chiamata per member_id=' . $member_id . ' pdf=' . $invoice_pdf_path . ' force=' . ( $force ? '1' : '0' ) );

		$member = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('members')} WHERE id = %d",
				$member_id
			)
		);
		if ( ! $member ) {
			error_log( 'SD send_invoice_email: member non trovato per id=' . $member_id );
			return false;
		}

		$payment = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('payments')} WHERE member_id = %d ORDER BY id DESC LIMIT 1",
				$member_id
			)
		);
		if ( ! $payment ) {
			error_log( 'SD send_invoice_email: payment non trovato per member_id=' . $member_id );
			return false;
		}
		if ( ! $force && 1 === (int) $payment->is_activation_email_sent ) {
			error_log( 'SD send_invoice_email: is_activation_email_sent=1, email già inviata per member_id=' . $member_id );
			return false;
		}

		$year    = ! empty( $payment->payment_year ) ? (int) $payment->payment_year : (int) gmdate( 'Y' );
		$subject = sprintf(
			/* translators: 1: site name, 2: year */
			__( '[%1$s] Conferma iscrizione e fattura tassa sociale %2$d', 'sd-logbook' ),
			get_bloginfo( 'name' ),
			$year
		);

		$association_name = (string) get_option(
			'sd_payment_invoice_association_name',
			get_option( 'sd_payment_association_title', get_bloginfo( 'name' ) )
		);
		$bank_iban  = (string) get_option( 'sd_payment_invoice_bank_iban', '' );
		$bank_swift = (string) get_option( 'sd_payment_invoice_bank_swift', '' );
		$bank_bic   = (string) get_option( 'sd_payment_invoice_bank_bic', '' );
		$qr_payload = (string) get_option( 'sd_payment_invoice_qr_payload', '' );

		$member_name = esc_html( trim( (string) $member->first_name . ' ' . (string) $member->last_name ) );

		$body  = '<html><body style="font-family:Arial,sans-serif;color:#333;max-width:680px;margin:auto">';
		$body .= '<div style="margin-bottom:16px"><img src="https://scubadiabetes.ch/wp-content/uploads/2026/04/scubadiabetes_radius60.png" alt="ScubaDiabetes" style="height:80px;width:auto"></div>';
		$body .= '<h2 style="color:#0055a5">' . esc_html__( 'Conferma ricezione iscrizione', 'sd-logbook' ) . '</h2>';
		$body .= '<p>' . sprintf(
			esc_html__( 'Caro/a %1$s, abbiamo ricevuto la tua domanda di iscrizione per l\'anno %2$d.', 'sd-logbook' ),
			$member_name,
			$year
		) . '</p>';
		$body .= '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:12px 16px;margin:16px 0">';
		$body .= '<strong>' . esc_html__( 'Il tuo account verrà attivato alla ricezione del pagamento della tassa sociale.', 'sd-logbook' ) . '</strong>';
		$body .= '</div>';

		$body .= '<h3 style="color:#0055a5">' . esc_html__( 'Riepilogo dati iscrizione ricevuta', 'sd-logbook' ) . '</h3>';
		$body .= $this->build_member_data_html( $member, $payment );

		$body .= '<h3 style="color:#0055a5">' . esc_html__( 'Come effettuare il pagamento (Bonifico IBAN)', 'sd-logbook' ) . '</h3>';
		$body .= '<p>' . esc_html__( 'In allegato trovi la fattura PDF. Effettua un bonifico bancario con i seguenti dati:', 'sd-logbook' ) . '</p>';
		$body .= '<ul>';
		$body .= '<li><strong>' . esc_html__( 'Associazione:', 'sd-logbook' ) . '</strong> ' . esc_html( $association_name ) . '</li>';
		$body .= '<li><strong>' . esc_html__( 'IBAN:', 'sd-logbook' ) . '</strong> ' . esc_html( $bank_iban ) . '</li>';
		if ( '' !== trim( $bank_swift . $bank_bic ) ) {
			$body .= '<li><strong>' . esc_html__( 'SWIFT/BIC:', 'sd-logbook' ) . '</strong> ' . esc_html( trim( $bank_swift . ' ' . $bank_bic ) ) . '</li>';
		}
		if ( '' !== trim( $qr_payload ) ) {
			$body .= '<li><strong>' . esc_html__( 'QR pagamento:', 'sd-logbook' ) . '</strong><br>' . nl2br( esc_html( $qr_payload ) ) . '</li>';
		}
		$body .= '</ul>';
		$body .= '<p style="color:#666;font-size:12px">' . esc_html__( 'La tessera associativa verrà emessa dopo la conferma del pagamento.', 'sd-logbook' ) . '</p>';
		$body .= '</body></html>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $this->needs_guardian_as_to( $member ) ) {
			$to        = (string) $member->guardian_email;
			$headers[] = 'Cc: ' . (string) $member->email;
		} else {
			$to = (string) $member->email;
		}

		$attachments = array();
		if ( '' !== trim( $invoice_pdf_path ) && file_exists( $invoice_pdf_path ) ) {
			$attachments[] = $invoice_pdf_path;
		}

		$mail_sent = wp_mail( $to, $subject, $body, $headers, $attachments );
		if ( $mail_sent ) {
			$wpdb->update(
				$db->table( 'payments' ),
				array( 'is_activation_email_sent' => 1 ),
				array( 'id' => (int) $payment->id )
			);
		} else {
			error_log( 'SD send_invoice_email: wp_mail fallito per member_id=' . $member_id . ' to=' . $to );
		}
		return $mail_sent;
	}

	/**
	 * Restituisce true se il tutore deve ricevere l'email come destinatario principale (To).
	 * Condizione: sotto_tutela = 1 OPPURE il socio è minorenne (< 18 anni), e il tutore ha un'email valida.
	 *
	 * @param object $member Dati socio.
	 * @return bool
	 */
	private function needs_guardian_as_to( $member ) {
		if ( empty( $member->guardian_email ) || ! is_email( (string) $member->guardian_email ) ) {
			return false;
		}
		if ( 1 === (int) $member->sotto_tutela ) {
			return true;
		}
		if ( ! empty( $member->date_of_birth ) ) {
			$age = (int) gmdate( 'Y' ) - (int) gmdate( 'Y', strtotime( (string) $member->date_of_birth ) );
			// Correzione se il compleanno di quest'anno non è ancora passato
			if ( gmdate( 'md' ) < gmdate( 'md', strtotime( (string) $member->date_of_birth ) ) ) {
				--$age;
			}
			if ( $age < 18 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Genera tabella HTML con tutti i dati del socio per uso nelle e-mail.
	 *
	 * @param object      $member  Dati socio.
	 * @param object|null $payment Dati pagamento.
	 * @return string HTML.
	 */
	private function build_member_data_html( $member, $payment = null ) {
		$th    = 'style="background:#f0f4f8;padding:6px 10px;text-align:left;white-space:nowrap;border:1px solid #d0d7de"';
		$td    = 'style="padding:6px 10px;border:1px solid #d0d7de"';
		$table = 'style="border-collapse:collapse;width:100%;font-size:13px"';

		$dob_formatted   = $member->date_of_birth ? gmdate( 'd.m.Y', strtotime( (string) $member->date_of_birth ) ) : '';
		$diabetes_labels = array(
			'non_diabetico' => 'Non diabetico',
			'tipo_1'        => 'Tipo 1',
			'tipo_2'        => 'Tipo 2',
			'tipo_3c'       => 'Tipo 3c (pancreasectomia, pancreatite)',
			'lada'          => 'LADA',
			'mody'          => 'MODY',
			'midd'          => 'MIDD',
			'altro'         => 'Altro',
		);
		$diabetes_label  = $diabetes_labels[ (string) $member->diabetes_type ] ?? (string) $member->diabetes_type;

		$rows = array(
			array( 'Numero socio', (string) $member->member_number ),
			array( 'Nome', (string) $member->first_name ),
			array( 'Cognome', (string) $member->last_name ),
			array( 'Email (username)', (string) $member->email ),
			array( 'Telefono', (string) $member->phone ),
			array( 'Data di nascita', $dob_formatted ),
			array( 'Luogo di nascita', (string) $member->birth_place ),
			array( 'Nazione di nascita', (string) $member->birth_country ),
			array( 'Genere', (string) $member->gender ),
			array( 'Diabete', $diabetes_label ),
		);

		if ( ! empty( $member->diabetology_center ) ) {
			$rows[] = array( 'Centro diabetologico', (string) $member->diabetology_center );
		}

		$rows[] = array( 'Indirizzo', (string) $member->address_street );
		$rows[] = array( 'CAP', (string) $member->address_postal );
		$rows[] = array( 'Città', (string) $member->address_city );
		$rows[] = array( 'Cantone', (string) $member->address_canton );
		$rows[] = array( 'Nazione', (string) $member->address_country );
		$rows[] = array( 'Cod. fiscale/AVS', (string) $member->fiscal_code );
		$rows[] = array( 'Taglia maglietta', (string) $member->taglia_maglietta );
		$rows[] = array( 'Tipo socio', (string) $member->member_type );
		$rows[] = array( 'Tassa annuale', 'CHF ' . number_format( (float) $member->fee_amount, 2, '.', '' ) );

		if ( 1 === (int) $member->sotto_tutela ) {
			$rows[] = array(
				'Tutore/Genitore',
				trim( (string) $member->guardian_first_name . ' ' . (string) $member->guardian_last_name )
				. ' (' . (string) $member->guardian_role . ')',
			);
			$rows[] = array( 'Email tutore', (string) $member->guardian_email );
			$rows[] = array( 'Tel. tutore', (string) $member->guardian_phone );
		}

		if ( null !== $payment ) {
			$rows[] = array(
				'Anno associativo',
				! empty( $payment->payment_year ) ? (string) $payment->payment_year : gmdate( 'Y' ),
			);
		}

		$html = '<table ' . $table . '>';
		foreach ( $rows as $row ) {
			if ( '' === trim( (string) $row[1] ) ) {
				continue;
			}
			$html .= '<tr><th ' . $th . '>' . esc_html( $row[0] ) . '</th>';
			$html .= '<td ' . $td . '>' . esc_html( (string) $row[1] ) . '</td></tr>';
		}
		$html .= '</table>';

		return $html;
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
