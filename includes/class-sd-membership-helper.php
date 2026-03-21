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
			$d  = $ts ? date( 'Ymd', $ts ) : '';
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
	 * - Al segretariato
	 */
	public static function send_registration_emails( $member_id, $plain_password = '' ) {
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
		$year      = date( 'Y' );

		// === Email al socio ===
		$to      = $member->email;
		$subject = sprintf( '[%s] Conferma iscrizione %s', $site_name, $year );
		$body    = self::get_welcome_email_body( $member, $plain_password, $site_name, $site_url );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $to, $subject, $body, $headers );

		// === Email al genitore/tutore (se minorenne) ===
		if ( $member->sotto_tutela && ! empty( $member->guardian_email ) ) {
			$guardian_to      = $member->guardian_email;
			$guardian_subject = sprintf( '[%s] Iscrizione di %s %s - Anno %s', $site_name, $member->first_name, $member->last_name, $year );
			$guardian_body    = self::get_guardian_email_body( $member, $site_name );
			wp_mail( $guardian_to, $guardian_subject, $guardian_body, $headers );
		}

		// === Email al segretariato ===
		$secretariat_email = get_option( 'sd_secretariat_email', get_option( 'admin_email' ) );
		if ( $secretariat_email ) {
			$staff_subject = sprintf( '[%s] Nuova iscrizione: %s %s', $site_name, $member->first_name, $member->last_name );
			$staff_body    = self::get_staff_email_body( $member, $site_name );
			wp_mail( $secretariat_email, $staff_subject, $staff_body, $headers );
		}
	}

	/**
	 * Email di benvenuto al nuovo socio
	 */
	private static function get_welcome_email_body( $member, $plain_password, $site_name, $site_url ) {
		$name      = esc_html( $member->first_name . ' ' . $member->last_name );
		$login_url = $site_url . '/accedi/';

		$payment_info = self::get_payment_instructions( $member );

		$html  = '<html><body style="font-family:Arial,sans-serif;color:#333;">';
		$html .= '<h2 style="color:#0055a5;">Benvenuto in ScubaDiabetes!</h2>';
		$html .= '<p>Caro/a <strong>' . $name . '</strong>,</p>';
		$html .= '<p>La tua iscrizione all\'Associazione ScubaDiabetes per l\'anno <strong>' . date( 'Y' ) . '</strong> è stata ricevuta con successo.</p>';

		if ( ! empty( $plain_password ) ) {
			$html .= '<h3>Le tue credenziali di accesso:</h3>';
			$html .= '<ul>';
			$html .= '<li><strong>Username:</strong> ' . esc_html( $member->email ) . '</li>';
			$html .= '<li><strong>Password:</strong> ' . esc_html( $plain_password ) . '</li>';
			$html .= '</ul>';
			$html .= '<p><a href="' . esc_url( $login_url ) . '" style="background:#0055a5;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;">Accedi al portale</a></p>';
			$html .= '<p><em>Ti consigliamo di cambiare la password al primo accesso.</em></p>';
		}

		$html .= '<h3>Dettagli iscrizione:</h3>';
		$html .= '<ul>';
		$html .= '<li><strong>Tassa annuale:</strong> CHF ' . number_format( $member->fee_amount, 2 ) . '</li>';
		$html .= '</ul>';

		if ( ! empty( $payment_info ) ) {
			$html .= '<h3>Istruzioni per il pagamento:</h3>';
			$html .= $payment_info;
		}

		$html .= '<p>Per informazioni contatta il segretariato: <a href="mailto:' . esc_attr( get_option( 'sd_secretariat_email', get_option( 'admin_email' ) ) ) . '">' . esc_html( get_option( 'sd_secretariat_email', get_option( 'admin_email' ) ) ) . '</a></p>';
		$html .= '<p>Cordiali saluti,<br><strong>' . esc_html( $site_name ) . '</strong></p>';
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
		$html .= '<h2 style="color:#0055a5;">Iscrizione ScubaDiabetes ' . date( 'Y' ) . '</h2>';
		$html .= '<p>Gentile <strong>' . $guardian_name . '</strong>,</p>';
		$html .= '<p>La informiamo che l\'iscrizione all\'Associazione ScubaDiabetes per <strong>' . $name . '</strong> è stata ricevuta con successo.</p>';
		$html .= '<p>In qualità di ' . esc_html( $member->guardian_role ) . ', Lei verrà tenuta/o informata/o delle attività dell\'associazione relative al/la minore.</p>';
		$html .= '<p>Per informazioni: <a href="mailto:' . esc_attr( get_option( 'sd_secretariat_email', get_option( 'admin_email' ) ) ) . '">' . esc_html( get_option( 'sd_secretariat_email', get_option( 'admin_email' ) ) ) . '</a></p>';
		$html .= '<p>Cordiali saluti,<br><strong>' . esc_html( $site_name ) . '</strong></p>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * Email al segretariato con riepilogo della nuova iscrizione
	 */
	private static function get_staff_email_body( $member, $site_name ) {
		$name = esc_html( $member->first_name . ' ' . $member->last_name );

		$html  = '<html><body style="font-family:Arial,sans-serif;color:#333;">';
		$html .= '<h2 style="color:#0055a5;">Nuova iscrizione ricevuta</h2>';
		$html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;max-width:600px;">';
		$html .= '<tr><th align="left" style="background:#f5f5f5;">Campo</th><th align="left" style="background:#f5f5f5;">Valore</th></tr>';

		$fields = array(
			'Nome completo'  => $name,
			'Email'          => $member->email,
			'Telefono'       => $member->phone,
			'Data di nascita' => $member->date_of_birth,
			'Genere'         => $member->gender,
			'Subacqueo'      => $member->is_scuba ? 'Sì' : 'No',
			'Diabete'        => $member->diabetes_type,
			'Tassa'          => 'CHF ' . number_format( $member->fee_amount, 2 ),
			'Tipo socio'     => $member->member_type,
			'Tutore'         => $member->sotto_tutela ? 'Sì' : 'No',
		);

		foreach ( $fields as $label => $value ) {
			$html .= '<tr>';
			$html .= '<td><strong>' . esc_html( $label ) . '</strong></td>';
			$html .= '<td>' . esc_html( (string) $value ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</table>';
		$html .= '<p>Per gestire questa iscrizione accedi alla <a href="' . esc_url( home_url( '/gestione-soci/' ) ) . '">pagina di gestione soci</a>.</p>';
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
			$html .= '<li><strong>Bonifico IBAN:</strong> ' . esc_html( $iban ) . '</li>';
		}
		if ( $twint ) {
			$html .= '<li><strong>TWINT:</strong> ' . esc_html( $twint ) . '</li>';
		}
		if ( $paypal ) {
			$html .= '<li><strong>PayPal:</strong> ' . esc_html( $paypal ) . '</li>';
		}
		$html .= '</ul>';

		return $html;
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
			'CH' => 'Svizzera',
			'IT' => 'Italia',
			'DE' => 'Germania',
			'FR' => 'Francia',
			'AT' => 'Austria',
			'LI' => 'Liechtenstein',
			'LU' => 'Lussemburgo',
			'BE' => 'Belgio',
			'NL' => 'Paesi Bassi',
			'ES' => 'Spagna',
			'PT' => 'Portogallo',
			'GB' => 'Regno Unito',
			'IE' => 'Irlanda',
			'SE' => 'Svezia',
			'NO' => 'Norvegia',
			'DK' => 'Danimarca',
			'FI' => 'Finlandia',
			'PL' => 'Polonia',
			'CZ' => 'Repubblica Ceca',
			'SK' => 'Slovacchia',
			'HU' => 'Ungheria',
			'RO' => 'Romania',
			'BG' => 'Bulgaria',
			'HR' => 'Croazia',
			'SI' => 'Slovenia',
			'GR' => 'Grecia',
			'US' => 'Stati Uniti',
			'CA' => 'Canada',
			'AU' => 'Australia',
			'NZ' => 'Nuova Zelanda',
			'JP' => 'Giappone',
			'BR' => 'Brasile',
			'AR' => 'Argentina',
			'ZA' => 'Sudafrica',
			'OT' => 'Altro',
		);
	}

	/**
	 * Lista cantoni svizzeri
	 */
	public static function get_swiss_cantons() {
		return array(
			'AG' => 'Argovia (AG)',
			'AI' => 'Appenzello Interno (AI)',
			'AR' => 'Appenzello Esterno (AR)',
			'BE' => 'Berna (BE)',
			'BL' => 'Basilea Campagna (BL)',
			'BS' => 'Basilea Città (BS)',
			'FR' => 'Friburgo (FR)',
			'GE' => 'Ginevra (GE)',
			'GL' => 'Glarona (GL)',
			'GR' => 'Grigioni (GR)',
			'JU' => 'Giura (JU)',
			'LU' => 'Lucerna (LU)',
			'NE' => 'Neuchâtel (NE)',
			'NW' => 'Nidvaldo (NW)',
			'OW' => 'Obvaldo (OW)',
			'SG' => 'San Gallo (SG)',
			'SH' => 'Sciaffusa (SH)',
			'SO' => 'Soletta (SO)',
			'SZ' => 'Svitto (SZ)',
			'TG' => 'Turgovia (TG)',
			'TI' => 'Ticino (TI)',
			'UR' => 'Uri (UR)',
			'VD' => 'Vaud (VD)',
			'VS' => 'Vallese (VS)',
			'ZG' => 'Zugo (ZG)',
			'ZH' => 'Zurigo (ZH)',
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
			$yob = $ts ? date( 'Y', $ts ) : '';
		}
		$rand = strtoupper( substr( md5( $first_name . $last_name . $birth_date . time() ), 0, 4 ) );
		return $initials . $yob . $rand;
	}
}
