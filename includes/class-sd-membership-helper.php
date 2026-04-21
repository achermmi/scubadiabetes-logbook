<?php
/**
 * Helper statico per il sistema iscrizioni
 *
 * Fornisce funzioni condivise tra SD_Membership e SD_Membership_Admin:
 * - Generazione password
 * - Assegnazione ruoli WP
 * - Invio email
 * - Audit log
 * - Cron
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Membership_Helper {

	/**
	 * Genera la password per il nuovo utente secondo la formula:
	 * [Cognome[0]][YYYYMMDD][Nome[0]][CAP][is_diabetic][default_shared][!]
	 */
	public static function generate_password( $cognome, $birth_date, $nome, $cap, $is_diabetic, $default_shared ) {
		$c = strtoupper( substr( trim( $cognome ), 0, 1 ) );
		$d = '';
		if ( ! empty( $birth_date ) ) {
			$ts = strtotime( $birth_date );
			$d  = $ts ? gmdate( 'Ymd', $ts ) : '';
		}
		$n      = strtoupper( substr( trim( $nome ), 0, 1 ) );
		$cap    = preg_replace( '/[^A-Za-z0-9]/', '', $cap );
		$diab   = $is_diabetic ? '1' : '0';
		$shared = $default_shared ? '1' : '0';
		return $c . $d . $n . $cap . $diab . $shared . '!';
	}

	/**
	 * Determina se una persona è minorenne in base alla data di nascita
	 */
	public static function is_minor( $birth_date_str ) {
		if ( empty( $birth_date_str ) ) {
			return false;
		}
		$ts = strtotime( $birth_date_str );
		if ( ! $ts ) {
			return false;
		}
		$age_seconds = time() - $ts;
		return $age_seconds < ( 18 * 365.25 * 86400 );
	}

	/**
	 * Ruoli SD gestiti da questo plugin (mai toccare administrator, editor, ecc.)
	 */
	const SD_ROLES = array( 'sd_diver_diabetic', 'sd_diver', 'sd_staff', 'sd_medical' );

	/**
	 * Assegna il ruolo SD corretto al nuovo utente.
	 * Non tocca mai i ruoli WordPress nativi (administrator, editor, ecc.).
	 * Se l'utente non aveva ruoli, aggiunge subscriber come base.
	 */
	public static function assign_wp_role( $user_id, $is_scuba, $is_diabetic ) {
		$user = new WP_User( $user_id );

		// Non modificare mai gli admin o gli utenti con ruoli privilegiati
		if ( $user->has_cap( 'administrator' ) || $user->has_cap( 'manage_options' ) ) {
			return;
		}

		// Rimuovi solo i ruoli SD precedenti (non subscriber, non altri)
		foreach ( self::SD_ROLES as $sd_role ) {
			$user->remove_role( $sd_role );
		}

		if ( $is_scuba && $is_diabetic ) {
			// Rimuovi subscriber base se presente (verrà sostituito dal ruolo SD)
			$user->remove_role( 'subscriber' );
			$user->add_role( 'sd_diver_diabetic' );
		} elseif ( $is_scuba ) {
			$user->remove_role( 'subscriber' );
			$user->add_role( 'sd_diver' );
		} else {
			// Non subacqueo: lascia subscriber se non ha altri ruoli
			$roles = array_filter( (array) $user->roles );
			if ( empty( $roles ) ) {
				$user->add_role( 'subscriber' );
			}
		}
	}

	/**
	 * Invia le email di registrazione
	 * - Al nuovo socio (con credenziali)
	 * - Al genitore/tutore (se minorenne)
	 * - A ogni famigliare iscritto (con credenziali, senza richiesta di pagamento)
	 * - Al segretariato
	 *
	 * @param int    $member_id        ID del socio intestatario
	 * @param string $plain_password   Password in chiaro dell'intestatario
	 * @param array  $registered_family Array di famigliari registrati [{first_name, last_name, email, password, member_id}]
	 */
	public static function send_registration_emails( $member_id, $plain_password = '', $registered_family = array() ) {
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

		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();
		$year      = gmdate( 'Y' );
		$headers   = array( 'Content-Type: text/html; charset=UTF-8' );

		// === Email al socio intestatario ===
		$to      = $member->email;
		/* translators: 1: site name, 2: year */
		$subject = sprintf( __( '[%1$s] Conferma iscrizione %2$s', 'sd-logbook' ), $site_name, $year );
		$body    = self::get_welcome_email_body( $member, $plain_password, $site_name, $site_url, $registered_family );
		wp_mail( $to, $subject, $body, $headers );

		// === Email al genitore/tutore (se minorenne) ===
		if ( $member->sotto_tutela && ! empty( $member->guardian_email ) ) {
			$guardian_to      = $member->guardian_email;
			/* translators: 1: site name, 2: first name, 3: last name, 4: year */
			$guardian_subject = sprintf( __( '[%1$s] Iscrizione di %2$s %3$s - Anno %4$s', 'sd-logbook' ), $site_name, $member->first_name, $member->last_name, $year );
			$guardian_body    = self::get_guardian_email_body( $member, $site_name );
			wp_mail( $guardian_to, $guardian_subject, $guardian_body, $headers );
		}

		// === Email a ogni famigliare registrato ===
		foreach ( (array) $registered_family as $fm ) {
			if ( empty( $fm['email'] ) || ! is_email( $fm['email'] ) ) {
				continue;
			}
			$fm_subject = sprintf(
				/* translators: 1: site name, 2: year */
				__( '[%1$s] Conferma iscrizione famigliare %2$s', 'sd-logbook' ),
				$site_name,
				$year
			);
			$fm_body = self::get_family_welcome_email_body( $fm, $member, $site_name, $site_url );
			wp_mail( $fm['email'], $fm_subject, $fm_body, $headers );
		}

		// === Email al segretariato ===
		$secretariat_email = get_option( 'sd_secretariat_email', get_option( 'admin_email' ) );
		if ( $secretariat_email ) {
			/* translators: 1: site name, 2: first name, 3: last name */
			$staff_subject = sprintf( __( '[%1$s] Nuova iscrizione: %2$s %3$s', 'sd-logbook' ), $site_name, $member->first_name, $member->last_name );
			$staff_body    = self::get_staff_email_body( $member, $site_name, $registered_family );
			wp_mail( $secretariat_email, $staff_subject, $staff_body, $headers );
		}
	}

	/**
	 * Email di benvenuto al nuovo socio
	 *
	 * @param object $member           Dati del socio
	 * @param string $plain_password   Password in chiaro
	 * @param string $site_name        Nome del sito
	 * @param string $site_url         URL del sito
	 * @param array  $registered_family Famigliari registrati (per iscrizione famiglia)
	 */
	private static function get_welcome_email_body( $member, $plain_password, $site_name, $site_url, $registered_family = array() ) {
		$name      = esc_html( $member->first_name . ' ' . $member->last_name );
		$login_url = $site_url . '/login/';

		$is_famiglia = ( floatval( $member->fee_amount ) >= 75 || 'attivo_capo_famiglia' === $member->member_type );

		$html  = '<html><body style="font-family:Arial,sans-serif;color:#333;">';
		$html .= '<h2 style="color:#0055a5;">' . __( 'Benvenuto in ScubaDiabetes!', 'sd-logbook' ) . '</h2>';
		/* translators: member full name */
		$html .= '<p>' . sprintf( __( 'Caro/a <strong>%s</strong>,', 'sd-logbook' ), $name ) . '</p>';
		/* translators: year */
		$html .= '<p>' . sprintf( __( 'La tua iscrizione all\'Associazione ScubaDiabetes per l\'anno <strong>%s</strong> è stata ricevuta con successo.', 'sd-logbook' ), gmdate( 'Y' ) ) . '</p>';

		if ( ! empty( $plain_password ) ) {
			$html .= '<h3>' . __( 'Le tue credenziali di accesso:', 'sd-logbook' ) . '</h3>';
			$html .= '<ul>';
			$html .= '<li><strong>' . __( 'Username:', 'sd-logbook' ) . '</strong> ' . esc_html( $member->email ) . '</li>';
			$html .= '<li><strong>' . __( 'Password:', 'sd-logbook' ) . '</strong> ' . esc_html( $plain_password ) . '</li>';
			$html .= '</ul>';
			$html .= '<p><a href="' . esc_url( $login_url ) . '" style="background:#0055a5;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;">' . __( 'Accedi al portale', 'sd-logbook' ) . '</a></p>';
			$html .= '<p><em>' . __( 'Ti consigliamo di cambiare la password al primo accesso.', 'sd-logbook' ) . '</em></p>';
		}

		$html .= '<h3>' . __( 'Dettagli iscrizione:', 'sd-logbook' ) . '</h3>';
		$html .= '<ul>';
		if ( $is_famiglia ) {
			$html .= '<li><strong>' . __( 'Tassa annuale:', 'sd-logbook' ) . '</strong> CHF 75.00 — ' . __( 'Iscrizione Famiglia / Nucleo familiare', 'sd-logbook' ) . '</li>';
		} else {
			/* translators: fee amount in CHF */
			$html .= '<li><strong>' . __( 'Tassa annuale:', 'sd-logbook' ) . '</strong> CHF ' . number_format( $member->fee_amount, 2 ) . '</li>';
		}
		if ( ! empty( $member->taglia_maglietta ) ) {
			$html .= '<li><strong>' . __( 'Taglia Maglietta:', 'sd-logbook' ) . '</strong> ' . esc_html( $member->taglia_maglietta ) . '</li>';
		}
		$html .= '</ul>';

		// Elenco famigliari registrati
		if ( $is_famiglia && ! empty( $registered_family ) ) {
			$html .= '<h3>' . __( 'Famigliari iscritti:', 'sd-logbook' ) . '</h3>';
			$html .= '<p>' . __( 'Ogni famigliare ha ricevuto un\'email separata con le proprie credenziali di accesso.', 'sd-logbook' ) . '</p>';
			$html .= '<ul>';
			foreach ( $registered_family as $fm ) {
				$html .= '<li>' . esc_html( $fm['first_name'] . ' ' . $fm['last_name'] ) . ' (' . esc_html( $fm['email'] ) . ')</li>';
			}
			$html .= '</ul>';
		}

		$payment_info = self::get_payment_instructions( $member );
		if ( ! empty( $payment_info ) ) {
			$html .= '<h3>' . __( 'Istruzioni per il pagamento:', 'sd-logbook' ) . '</h3>';
			$html .= $payment_info;
		}

		/* translators: secretariat email address */
		$html .= '<p>' . sprintf( __( 'Per informazioni contatta il segretariato: <a href="mailto:%1$s">%2$s</a>', 'sd-logbook' ), esc_attr( get_option( 'sd_secretariat_email', get_option( 'admin_email' ) ) ), esc_html( get_option( 'sd_secretariat_email', get_option( 'admin_email' ) ) ) ) . '</p>';
		/* translators: site name */
		$html .= '<p>' . __( 'Cordiali saluti,', 'sd-logbook' ) . '<br><strong>' . esc_html( $site_name ) . '</strong></p>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * Email di benvenuto al famigliare (senza richiesta di pagamento, con credenziali)
	 *
	 * @param array  $fm          Dati del famigliare [{first_name, last_name, email, password}]
	 * @param object $intestatario Dati del socio intestatario
	 * @param string $site_name   Nome del sito
	 * @param string $site_url    URL del sito
	 */
	private static function get_family_welcome_email_body( $fm, $intestatario, $site_name, $site_url ) {
		$name          = esc_html( $fm['first_name'] . ' ' . $fm['last_name'] );
		$int_name      = esc_html( $intestatario->first_name . ' ' . $intestatario->last_name );
		$login_url     = $site_url . '/login/';

		$html  = '<html><body style="font-family:Arial,sans-serif;color:#333;">';
		$html .= '<h2 style="color:#0055a5;">' . __( 'Benvenuto in ScubaDiabetes!', 'sd-logbook' ) . '</h2>';
		/* translators: member full name */
		$html .= '<p>' . sprintf( __( 'Caro/a <strong>%s</strong>,', 'sd-logbook' ), $name ) . '</p>';
		$html .= '<p>' . sprintf(
			/* translators: 1: head of family name, 2: year */
			__( 'Sei stato/a iscritto/a come famigliare di <strong>%1$s</strong> all\'Associazione ScubaDiabetes per l\'anno <strong>%2$s</strong>.', 'sd-logbook' ),
			$int_name,
			gmdate( 'Y' )
		) . '</p>';
		$html .= '<p>' . __( 'La quota associativa è inclusa nell\'iscrizione famiglia — nessun pagamento è richiesto da parte tua.', 'sd-logbook' ) . '</p>';

		if ( ! empty( $fm['password'] ) ) {
			$html .= '<h3>' . __( 'Le tue credenziali di accesso:', 'sd-logbook' ) . '</h3>';
			$html .= '<ul>';
			$html .= '<li><strong>' . __( 'Username:', 'sd-logbook' ) . '</strong> ' . esc_html( $fm['email'] ) . '</li>';
			$html .= '<li><strong>' . __( 'Password:', 'sd-logbook' ) . '</strong> ' . esc_html( $fm['password'] ) . '</li>';
			$html .= '</ul>';
			$html .= '<p><a href="' . esc_url( $login_url ) . '" style="background:#0055a5;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;">' . __( 'Accedi al portale', 'sd-logbook' ) . '</a></p>';
			$html .= '<p><em>' . __( 'Ti consigliamo di cambiare la password al primo accesso.', 'sd-logbook' ) . '</em></p>';
		}

		/* translators: secretariat email address */
		$html .= '<p>' . sprintf( __( 'Per informazioni contatta il segretariato: <a href="mailto:%1$s">%2$s</a>', 'sd-logbook' ), esc_attr( get_option( 'sd_secretariat_email', get_option( 'admin_email' ) ) ), esc_html( get_option( 'sd_secretariat_email', get_option( 'admin_email' ) ) ) ) . '</p>';
		$html .= '<p>' . __( 'Cordiali saluti,', 'sd-logbook' ) . '<br><strong>' . esc_html( $site_name ) . '</strong></p>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * Email al genitore/tutore
	 */
	private static function get_guardian_email_body( $member, $site_name ) {
		$name          = esc_html( $member->first_name . ' ' . $member->last_name );
		$guardian_name = esc_html( $member->guardian_first_name . ' ' . $member->guardian_last_name );

		$html  = '<html><body style="font-family:Arial,sans-serif;color:#333;">';
		/* translators: year */
		$html .= '<h2 style="color:#0055a5;">' . sprintf( __( 'Iscrizione ScubaDiabetes %s', 'sd-logbook' ), gmdate( 'Y' ) ) . '</h2>';
		/* translators: guardian full name */
		$html .= '<p>' . sprintf( __( 'Gentile <strong>%s</strong>,', 'sd-logbook' ), $guardian_name ) . '</p>';
		/* translators: member full name */
		$html .= '<p>' . sprintf( __( 'La informiamo che l\'iscrizione all\'Associazione ScubaDiabetes per <strong>%s</strong> è stata ricevuta con successo.', 'sd-logbook' ), $name ) . '</p>';
		/* translators: guardian role (e.g. genitore, tutore) */
		$html .= '<p>' . sprintf( __( 'In qualità di %s, Lei verrà tenuta/o informata/o delle attività dell\'associazione relative al/la minore.', 'sd-logbook' ), esc_html( $member->guardian_role ) ) . '</p>';
		/* translators: secretariat email address */
		$html .= '<p>' . sprintf( __( 'Per informazioni: <a href="mailto:%1$s">%2$s</a>', 'sd-logbook' ), esc_attr( get_option( 'sd_secretariat_email', get_option( 'admin_email' ) ) ), esc_html( get_option( 'sd_secretariat_email', get_option( 'admin_email' ) ) ) ) . '</p>';
		$html .= '<p>' . __( 'Cordiali saluti,', 'sd-logbook' ) . '<br><strong>' . esc_html( $site_name ) . '</strong></p>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * Email al segretariato con riepilogo della nuova iscrizione
	 *
	 * @param object $member           Dati del socio intestatario
	 * @param string $site_name        Nome del sito
	 * @param array  $registered_family Famigliari registrati (opzionale)
	 */
	private static function get_staff_email_body( $member, $site_name, $registered_family = array() ) {
		$name = esc_html( $member->first_name . ' ' . $member->last_name );

		$html  = '<html><body style="font-family:Arial,sans-serif;color:#333;">';
		$html .= '<h2 style="color:#0055a5;">' . __( 'Nuova iscrizione ricevuta', 'sd-logbook' ) . '</h2>';
		$html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;max-width:600px;">';
		$html .= '<tr><th align="left" style="background:#f5f5f5;">' . __( 'Field', 'sd-logbook' ) . '</th><th align="left" style="background:#f5f5f5;">' . __( 'Valore', 'sd-logbook' ) . '</th></tr>';

		$fields = array(
			__( 'Nome completo', 'sd-logbook' )  => $name,
			__( 'Email', 'sd-logbook' )           => $member->email,
			__( 'Telefono', 'sd-logbook' )        => $member->phone,
			__( 'Data di nascita', 'sd-logbook' ) => $member->date_of_birth,
			__( 'Genere', 'sd-logbook' )          => $member->gender,
			__( 'Subacqueo', 'sd-logbook' )       => $member->is_scuba ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' ),
			__( 'Diabete', 'sd-logbook' )         => $member->diabetes_type,
			__( 'Tassa', 'sd-logbook' )           => 'CHF ' . number_format( $member->fee_amount, 2 ),
			__( 'Tipo socio', 'sd-logbook' )      => $member->member_type,
			__( 'Taglia Maglietta', 'sd-logbook' ) => ! empty( $member->taglia_maglietta ) ? $member->taglia_maglietta : '—',
			__( 'Tutore', 'sd-logbook' )          => $member->sotto_tutela ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' ),
		);

		foreach ( $fields as $label => $value ) {
			$html .= '<tr>';
			$html .= '<td><strong>' . esc_html( $label ) . '</strong></td>';
			$html .= '<td>' . esc_html( (string) $value ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</table>';

		// Famigliari registrati
		if ( ! empty( $registered_family ) ) {
			$html .= '<h3 style="color:#0055a5;margin-top:1.5rem;">' . __( 'Famigliari iscritti:', 'sd-logbook' ) . '</h3>';
			foreach ( $registered_family as $idx => $fm ) {
				$html .= '<h4 style="margin-top:1rem;">' . esc_html( sprintf( __( 'Famigliare %d', 'sd-logbook' ), $idx + 1 ) ) . '</h4>';
				$html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;max-width:600px;">';
				$html .= '<tr><th align="left" style="background:#f5f5f5;">' . __( 'Campo', 'sd-logbook' ) . '</th><th align="left" style="background:#f5f5f5;">' . __( 'Valore', 'sd-logbook' ) . '</th></tr>';
				$html .= '<tr><td><strong>' . __( 'Nome completo', 'sd-logbook' ) . '</strong></td><td>' . esc_html( $fm['first_name'] . ' ' . $fm['last_name'] ) . '</td></tr>';
				$html .= '<tr><td><strong>' . __( 'Email', 'sd-logbook' ) . '</strong></td><td>' . esc_html( $fm['email'] ) . '</td></tr>';
				$html .= '<tr><td><strong>' . __( 'Tipo socio', 'sd-logbook' ) . '</strong></td><td>' . __( 'Attivo Famigliare', 'sd-logbook' ) . '</td></tr>';
				$html .= '<tr><td><strong>' . __( 'Tassa', 'sd-logbook' ) . '</strong></td><td>CHF 0.00</td></tr>';
				$html .= '</table>';
			}
		}

		/* translators: URL to member management page */
		$html .= '<p>' . sprintf( __( 'Per gestire questa iscrizione accedi alla <a href="%s">pagina di gestione soci</a>.', 'sd-logbook' ), esc_url( home_url( '/gestione-soci/' ) ) ) . '</p>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * Recupera le istruzioni di pagamento dal database delle opzioni
	 */
	private static function get_payment_instructions( $member ) {
		$method = get_option( 'sd_payment_method', 'bonifico_iban' );
		$iban   = get_option( 'sd_payment_iban', '' );
		$twint  = get_option( 'sd_payment_twint', '' );
		$paypal = get_option( 'sd_payment_paypal', '' );

		$html = '<ul>';
		if ( $iban ) {
			$html .= '<li><strong>' . __( 'Bonifico IBAN:', 'sd-logbook' ) . '</strong> ' . esc_html( $iban ) . '</li>';
		}
		if ( $twint ) {
			$html .= '<li><strong>' . __( 'TWINT:', 'sd-logbook' ) . '</strong> ' . esc_html( $twint ) . '</li>';
		}
		if ( $paypal ) {
			$html .= '<li><strong>' . __( 'PayPal:', 'sd-logbook' ) . '</strong> ' . esc_html( $paypal ) . '</li>';
		}
		$html .= '</ul>';

		return $html;
	}

	/**
	 * Disabilita un utente WordPress (blocca il login via meta)
	 */
	public static function disable_wp_user( $user_id ) {
		if ( ! $user_id ) {
			return;
		}
		update_user_meta( (int) $user_id, 'sd_account_disabled', 1 );
	}

	/**
	 * Riabilita un utente WordPress
	 */
	public static function enable_wp_user( $user_id ) {
		if ( ! $user_id ) {
			return;
		}
		delete_user_meta( (int) $user_id, 'sd_account_disabled' );
	}

	/**
	 * Registra un'azione nell'audit log
	 */
	public static function log_audit( $member_id, $action, $table_name = 'sd_members', $record_id = null, $old_data = null, $new_data = null ) {
		global $wpdb;
		$db = new SD_Database();

		$wpdb->insert(
			$db->table( 'audit_log' ),
			array(
				'member_id'  => absint( $member_id ),
				'action'     => sanitize_text_field( $action ),
				'table_name' => sanitize_text_field( $table_name ),
				'record_id'  => $record_id ? absint( $record_id ) : null,
				'old_data'   => $old_data ? wp_json_encode( $old_data ) : null,
				'new_data'   => $new_data ? wp_json_encode( $new_data ) : null,
				'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '',
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Carica un membro completo (con dati utente WP)
	 */
	public static function get_member_full( $member_id ) {
		global $wpdb;
		$db = new SD_Database();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.*, u.user_login, u.user_registered,
				        dp.is_diabetic, dp.diabetes_type as dp_diabetes_type,
				        dp.weight, dp.height, dp.blood_type, dp.allergies,
				        dp.medications, dp.diabetology_center, dp.default_shared_for_research
				 FROM {$db->table('members')} m
				 LEFT JOIN {$wpdb->users} u ON u.ID = m.wp_user_id
				 LEFT JOIN {$db->table('diver_profiles')} dp ON dp.user_id = m.wp_user_id
				 WHERE m.id = %d",
				$member_id
			)
		);
	}

	/**
	 * Programma il cron giornaliero per i reminder di rinnovo
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( 'sd_membership_renewal_check' ) ) {
			wp_schedule_event( time(), 'daily', 'sd_membership_renewal_check' );
		}
	}

	/**
	 * Lista paesi comuni (per i select)
	 */
	public static function get_countries() {
		return array(
			'CH' => __( 'Svizzera', 'sd-logbook' ),
			'IT' => __( 'Italia', 'sd-logbook' ),
			'DE' => __( 'Germania', 'sd-logbook' ),
			'FR' => __( 'Francia', 'sd-logbook' ),
			'AT' => __( 'Austria', 'sd-logbook' ),
			'LI' => __( 'Liechtenstein', 'sd-logbook' ),
			'LU' => __( 'Lussemburgo', 'sd-logbook' ),
			'BE' => __( 'Belgio', 'sd-logbook' ),
			'NL' => __( 'Paesi Bassi', 'sd-logbook' ),
			'ES' => __( 'Spagna', 'sd-logbook' ),
			'PT' => __( 'Portogallo', 'sd-logbook' ),
			'GB' => __( 'Regno Unito', 'sd-logbook' ),
			'IE' => __( 'Irlanda', 'sd-logbook' ),
			'SE' => __( 'Svezia', 'sd-logbook' ),
			'NO' => __( 'Norvegia', 'sd-logbook' ),
			'DK' => __( 'Danimarca', 'sd-logbook' ),
			'FI' => __( 'Finlandia', 'sd-logbook' ),
			'PL' => __( 'Polonia', 'sd-logbook' ),
			'CZ' => __( 'Repubblica Ceca', 'sd-logbook' ),
			'SK' => __( 'Slovacchia', 'sd-logbook' ),
			'HU' => __( 'Ungheria', 'sd-logbook' ),
			'RO' => __( 'Romania', 'sd-logbook' ),
			'BG' => __( 'Bulgaria', 'sd-logbook' ),
			'HR' => __( 'Croazia', 'sd-logbook' ),
			'SI' => __( 'Slovenia', 'sd-logbook' ),
			'GR' => __( 'Grecia', 'sd-logbook' ),
			'US' => __( 'Stati Uniti', 'sd-logbook' ),
			'CA' => __( 'Canada', 'sd-logbook' ),
			'AU' => __( 'Australia', 'sd-logbook' ),
			'NZ' => __( 'Nuova Zelanda', 'sd-logbook' ),
			'JP' => __( 'Giappone', 'sd-logbook' ),
			'BR' => __( 'Brasile', 'sd-logbook' ),
			'AR' => __( 'Argentina', 'sd-logbook' ),
			'ZA' => __( 'Sudafrica', 'sd-logbook' ),
			'OT' => __( 'Altro', 'sd-logbook' ),
		);
	}

	/**
	 * Lista cantoni svizzeri
	 */
	public static function get_swiss_cantons() {
		return array(
			'AG' => __( 'Argovia (AG)', 'sd-logbook' ),
			'AI' => __( 'Appenzello Interno (AI)', 'sd-logbook' ),
			'AR' => __( 'Appenzello Esterno (AR)', 'sd-logbook' ),
			'BE' => __( 'Berna (BE)', 'sd-logbook' ),
			'BL' => __( 'Basilea Campagna (BL)', 'sd-logbook' ),
			'BS' => __( 'Basilea Città (BS)', 'sd-logbook' ),
			'FR' => __( 'Friburgo (FR)', 'sd-logbook' ),
			'GE' => __( 'Ginevra (GE)', 'sd-logbook' ),
			'GL' => __( 'Glarona (GL)', 'sd-logbook' ),
			'GR' => __( 'Grigioni (GR)', 'sd-logbook' ),
			'JU' => __( 'Giura (JU)', 'sd-logbook' ),
			'LU' => __( 'Lucerna (LU)', 'sd-logbook' ),
			'NE' => __( 'Neuchâtel (NE)', 'sd-logbook' ),
			'NW' => __( 'Nidvaldo (NW)', 'sd-logbook' ),
			'OW' => __( 'Obvaldo (OW)', 'sd-logbook' ),
			'SG' => __( 'San Gallo (SG)', 'sd-logbook' ),
			'SH' => __( 'Sciaffusa (SH)', 'sd-logbook' ),
			'SO' => __( 'Soletta (SO)', 'sd-logbook' ),
			'SZ' => __( 'Svitto (SZ)', 'sd-logbook' ),
			'TG' => __( 'Turgovia (TG)', 'sd-logbook' ),
			'TI' => __( 'Ticino (TI)', 'sd-logbook' ),
			'UR' => __( 'Uri (UR)', 'sd-logbook' ),
			'VD' => __( 'Vaud (VD)', 'sd-logbook' ),
			'VS' => __( 'Vallese (VS)', 'sd-logbook' ),
			'ZG' => __( 'Zugo (ZG)', 'sd-logbook' ),
			'ZH' => __( 'Zurigo (ZH)', 'sd-logbook' ),
		);
	}

	/**
	 * Genera l'ID anonimo per la ricerca (identico alla logica in SD_Diver_Profile)
	 */
	public static function generate_research_id( $first_name, $last_name, $birth_date ) {
		$initials = strtoupper( substr( $first_name, 0, 1 ) . substr( $last_name, 0, 1 ) );
		$yob      = '';
		if ( ! empty( $birth_date ) ) {
			$ts  = strtotime( $birth_date );
			$yob = $ts ? gmdate( 'Y', $ts ) : '';
		}
		$rand = strtoupper( substr( md5( $first_name . $last_name . $birth_date . time() ), 0, 4 ) );
		return $initials . $yob . $rand;
	}
}
