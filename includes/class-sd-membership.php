<?php
/**
 * Gestione Iscrizioni - Modulo pubblico
 *
 * Shortcode: [sd_iscrizione]
 * Accessibile a tutti (inclusi utenti non loggati).
 * Al submit crea un utente WP e registra il socio nelle tabelle:
 *   - wp_users
 *   - wp_sd_members
 *   - wp_sd_diver_profiles (se subacqueo)
 *   - wp_sd_family_members (se famiglia o accompagnatori)
 *   - wp_sd_payments (placeholder in attesa)
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Membership {

	public function __construct() {
		add_shortcode( 'sd_iscrizione', array( $this, 'render_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX - accessibile anche a utenti non loggati
		add_action( 'wp_ajax_sd_register_member', array( $this, 'register_member' ) );
		add_action( 'wp_ajax_nopriv_sd_register_member', array( $this, 'register_member' ) );
	}

	/**
	 * Carica CSS e JS solo nella pagina che contiene il shortcode
	 */
	public function enqueue_assets() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'sd_iscrizione' ) ) {
			return;
		}

		wp_enqueue_style( 'sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-membership', SD_LOGBOOK_PLUGIN_URL . 'assets/css/membership.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );

		wp_enqueue_script( 'sd-membership', SD_LOGBOOK_PLUGIN_URL . 'assets/js/membership.js', array( 'jquery' ), SD_LOGBOOK_VERSION, true );
		wp_localize_script(
			'sd-membership',
			'sdMembership',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'sd_membership_nonce' ),
				'currentYear' => gmdate( 'Y' ),
			)
		);
	}

	/**
	 * Rendering del modulo di iscrizione
	 */
	public function render_form( $atts ) {
		// Se già iscritto e loggato, mostra messaggio
		if ( is_user_logged_in() ) {
			$user      = wp_get_current_user();
			$diab_role = in_array( 'sd_diver_diabetic', (array) $user->roles, true );
			$diver     = in_array( 'sd_diver', (array) $user->roles, true );
			if ( $diab_role || $diver ) {
				return '<div class="sd-notice sd-notice-info">'
					. sprintf(
						/* translators: %s: user display name */
						__( 'Sei già iscritto come <strong>%s</strong>. Per modificare i tuoi dati accedi al tuo profilo.', 'sd-logbook' ),
						esc_html( $user->display_name )
					)
					. '</div>';
			}
		}

		$countries      = SD_Membership_Helper::get_countries();
		$swiss_cantons  = SD_Membership_Helper::get_swiss_cantons();
		$current_year   = gmdate( 'Y' );

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/membership-form.php';
		return ob_get_clean();
	}

	/**
	 * Handler AJAX: registra il nuovo socio
	 */
	public function register_member() {
		check_ajax_referer( 'sd_membership_nonce', 'nonce' );

		// Rate limiting: max 3 tentativi per ora per IP
		$ip        = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rate_key  = 'sd_reg_attempts_' . md5( $ip );
		$attempts  = (int) get_transient( $rate_key );
		if ( $attempts >= 5 ) {
			wp_send_json_error( array( 'message' => __( 'Troppi tentativi. Attendi un\'ora prima di riprovare.', 'sd-logbook' ) ) );
		}
		set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );

		// === 1. Sanitizzazione e validazione campi base ===
		$first_name     = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last_name      = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$email          = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone          = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$birth_date     = sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ?? '' ) );
		$birth_place    = sanitize_text_field( wp_unslash( $_POST['birth_place'] ?? '' ) );
		$birth_country  = sanitize_text_field( wp_unslash( $_POST['birth_country'] ?? 'CH' ) );
		$gender         = sanitize_text_field( wp_unslash( $_POST['gender'] ?? '' ) );
		$sotto_tutela   = ! empty( $_POST['sotto_tutela'] ) && '1' === $_POST['sotto_tutela'] ? 1 : 0;
		$fiscal_code    = sanitize_text_field( wp_unslash( $_POST['fiscal_code'] ?? '' ) );

		// Indirizzo
		$address_street  = sanitize_text_field( wp_unslash( $_POST['address_street'] ?? '' ) );
		$address_postal  = sanitize_text_field( wp_unslash( $_POST['address_postal'] ?? '' ) );
		$address_city    = sanitize_text_field( wp_unslash( $_POST['address_city'] ?? '' ) );
		$address_country = sanitize_text_field( wp_unslash( $_POST['address_country'] ?? 'CH' ) );
		$address_canton  = sanitize_text_field( wp_unslash( $_POST['address_canton'] ?? '' ) );

		// Campi iscrizione
		$is_scuba      = ! empty( $_POST['is_scuba'] ) ? 1 : 0;
		$fee_amount    = floatval( $_POST['fee_amount'] ?? 0 );
		$member_type   = sanitize_text_field( wp_unslash( $_POST['member_type'] ?? 'attivo' ) );
		$privacy_consent = ! empty( $_POST['privacy_consent'] ) ? 1 : 0;
		$default_shared  = isset( $_POST['default_shared_for_research'] ) ? 1 : 0;

		// Validazione campi obbligatori
		$errors = array();

		if ( empty( $first_name ) ) {
			$errors[] = __( 'Il nome è obbligatorio.', 'sd-logbook' );
		}
		if ( empty( $last_name ) ) {
			$errors[] = __( 'Il cognome è obbligatorio.', 'sd-logbook' );
		}
		if ( ! is_email( $email ) ) {
			$errors[] = __( 'Inserisci un indirizzo email valido.', 'sd-logbook' );
		}
		if ( empty( $birth_date ) || ! strtotime( $birth_date ) ) {
			$errors[] = __( 'La data di nascita è obbligatoria.', 'sd-logbook' );
		}
		if ( empty( $phone ) ) {
			$errors[] = __( 'Il numero di telefono è obbligatorio.', 'sd-logbook' );
		}
		if ( empty( $address_street ) || empty( $address_city ) || empty( $address_postal ) ) {
			$errors[] = __( 'L\'indirizzo completo è obbligatorio.', 'sd-logbook' );
		}
		if ( ! $privacy_consent ) {
			$errors[] = __( 'Devi accettare l\'informativa sulla privacy.', 'sd-logbook' );
		}
		if ( ! in_array( $fee_amount, array( 30, 50, 75 ), true ) ) {
			$errors[] = __( 'Seleziona una tassa associativa valida.', 'sd-logbook' );
		}
		if ( ! in_array( $gender, array( 'M', 'F', 'NB', 'U' ), true ) ) {
			$errors[] = __( 'Seleziona il genere.', 'sd-logbook' );
		}

		// Verifica unicità email
		if ( email_exists( $email ) ) {
			$errors[] = __( 'Esiste già un account con questa email. Contatta il segretariato se hai bisogno di assistenza.', 'sd-logbook' );
		}

		// === 2. Logica minori ===
		$is_minor = SD_Membership_Helper::is_minor( $birth_date ) || $sotto_tutela;

		$guardian_first_name   = '';
		$guardian_last_name    = '';
		$guardian_role_val     = '';
		$guardian_dob          = null;
		$guardian_birth_place  = '';
		$guardian_birth_country = 'CH';
		$guardian_gender       = '';
		$guardian_email        = '';
		$guardian_phone        = '';
		$guardian_address      = '';
		$guardian_city         = '';
		$guardian_postal       = '';
		$guardian_country      = 'CH';

		if ( $is_minor ) {
			$guardian_first_name    = sanitize_text_field( wp_unslash( $_POST['guardian_first_name'] ?? '' ) );
			$guardian_last_name     = sanitize_text_field( wp_unslash( $_POST['guardian_last_name'] ?? '' ) );
			$guardian_role_val      = sanitize_text_field( wp_unslash( $_POST['guardian_role'] ?? '' ) );
			$guardian_dob_raw       = sanitize_text_field( wp_unslash( $_POST['guardian_dob'] ?? '' ) );
			$guardian_dob           = ! empty( $guardian_dob_raw ) && strtotime( $guardian_dob_raw ) ? $guardian_dob_raw : null;
			$guardian_birth_place   = sanitize_text_field( wp_unslash( $_POST['guardian_birth_place'] ?? '' ) );
			$guardian_birth_country = sanitize_text_field( wp_unslash( $_POST['guardian_birth_country'] ?? 'CH' ) );
			$guardian_gender        = sanitize_text_field( wp_unslash( $_POST['guardian_gender'] ?? '' ) );
			$guardian_email         = sanitize_email( wp_unslash( $_POST['guardian_email'] ?? '' ) );
			$guardian_phone         = sanitize_text_field( wp_unslash( $_POST['guardian_phone'] ?? '' ) );
			$guardian_address       = sanitize_text_field( wp_unslash( $_POST['guardian_address'] ?? '' ) );
			$guardian_city          = sanitize_text_field( wp_unslash( $_POST['guardian_city'] ?? '' ) );
			$guardian_postal        = sanitize_text_field( wp_unslash( $_POST['guardian_postal'] ?? '' ) );
			$guardian_country       = sanitize_text_field( wp_unslash( $_POST['guardian_country'] ?? 'CH' ) );

			if ( empty( $guardian_first_name ) || empty( $guardian_last_name ) ) {
				$errors[] = __( 'I dati del genitore/tutore sono obbligatori per i minorenni.', 'sd-logbook' );
			}
			if ( ! is_email( $guardian_email ) ) {
				$errors[] = __( 'L\'email del genitore/tutore non è valida.', 'sd-logbook' );
			}
			if ( empty( $guardian_phone ) ) {
				$errors[] = __( 'Il telefono del genitore/tutore è obbligatorio.', 'sd-logbook' );
			}
		}

		// === 3. Dati subacqueo ===
		$diabetes_type      = 'non_diabetico';
		$is_diabetic        = 0;
		$weight             = null;
		$height             = null;
		$blood_type         = '';
		$allergies_json     = '';
		$medications_json   = '';
		$diabetology_center = '';

		if ( $is_scuba ) {
			$weight             = ! empty( $_POST['weight'] ) ? floatval( $_POST['weight'] ) : null;
			$height             = ! empty( $_POST['height'] ) ? absint( $_POST['height'] ) : null;
			$blood_type         = sanitize_text_field( wp_unslash( $_POST['blood_type'] ?? '' ) );
			$diabetes_type_raw  = sanitize_text_field( wp_unslash( $_POST['diabetes_type'] ?? 'non_diabetico' ) );
			$allowed_types      = array( 'non_diabetico', 'tipo_1', 'tipo_2', 'non_specificato', 'altro' );
			$diabetes_type      = in_array( $diabetes_type_raw, $allowed_types, true ) ? $diabetes_type_raw : 'non_diabetico';
			$is_diabetic        = in_array( $diabetes_type, array( 'tipo_1', 'tipo_2', 'non_specificato', 'altro' ), true ) ? 1 : 0;
			$diabetology_center = sanitize_text_field( wp_unslash( $_POST['diabetology_center'] ?? '' ) );

			// Allergie: array di stringhe
			$allergies_raw = isset( $_POST['allergies'] ) ? wp_unslash( $_POST['allergies'] ) : array();
			if ( is_string( $allergies_raw ) ) {
				$allergies_raw = json_decode( stripslashes( $allergies_raw ), true ) ?: array();
			}
			$allergies_clean = array_map( 'sanitize_text_field', (array) $allergies_raw );
			$allergies_json  = wp_json_encode( array_filter( $allergies_clean ) );

			// Medicamenti: array di oggetti {name, dosage, unit, suspended}
			$meds_raw = isset( $_POST['medications'] ) ? wp_unslash( $_POST['medications'] ) : array();
			if ( is_string( $meds_raw ) ) {
				$meds_raw = json_decode( stripslashes( $meds_raw ), true ) ?: array();
			}
			$meds_clean = array();
			foreach ( (array) $meds_raw as $med ) {
				if ( empty( $med['name'] ) ) {
					continue;
				}
				$meds_clean[] = array(
					'name'      => sanitize_text_field( $med['name'] ?? '' ),
					'dosage'    => sanitize_text_field( $med['dosage'] ?? '' ),
					'unit'      => sanitize_text_field( $med['unit'] ?? '' ),
					'suspended' => ! empty( $med['suspended'] ) ? true : false,
				);
			}
			$medications_json = wp_json_encode( $meds_clean );
		}

		// === 4. Se ci sono errori, torna subito ===
		if ( ! empty( $errors ) ) {
			wp_send_json_error( array( 'message' => implode( '<br>', $errors ) ) );
		}

		// === 5. Crea utente WordPress ===
		$password = SD_Membership_Helper::generate_password( $last_name, $birth_date, $first_name, $address_postal, $is_diabetic, $default_shared );

		$user_id = wp_create_user( $email, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		wp_update_user(
			array(
				'ID'           => $user_id,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $first_name . ' ' . $last_name,
			)
		);

		SD_Membership_Helper::assign_wp_role( $user_id, $is_scuba, $is_diabetic );

		// === 6. Salva in wp_sd_members ===
		global $wpdb;
		$db = new SD_Database();

		$member_data = array(
			'wp_user_id'          => $user_id,
			'first_name'          => $first_name,
			'last_name'           => $last_name,
			'email'               => $email,
			'phone'               => $phone,
			'date_of_birth'       => $birth_date ?: null,
			'fiscal_code'         => $fiscal_code,
			'address_street'      => $address_street,
			'address_city'        => $address_city,
			'address_postal'      => $address_postal,
			'address_country'     => $address_country,
			'address_canton'      => $address_canton,
			'membership_type'     => $fee_amount >= 75 ? 'famiglia' : ( $fee_amount <= 30 ? 'individuale' : 'individuale' ),
			'diabetes_type'       => $is_diabetic ? ( 'tipo_1' === $diabetes_type || 'tipo_2' === $diabetes_type ? $diabetes_type : 'tipo_1' ) : 'non_diabetico',
			'member_since'        => gmdate( 'Y-m-d' ),
			'membership_expiry'   => gmdate( 'Y-12-31' ),
			'is_active'           => 1,
			'has_paid_fee'        => 0,
			// Campi estesi
			'sotto_tutela'        => $sotto_tutela ? 1 : 0,
			'birth_place'         => $birth_place,
			'birth_country'       => $birth_country,
			'gender'              => $gender,
			'is_scuba'            => $is_scuba,
			'fee_amount'          => $fee_amount,
			'member_type'         => $member_type,
			'diabetology_center'  => $diabetology_center,
			'registered_by'       => get_current_user_id(),
			'registered_at'       => current_time( 'mysql' ),
			'privacy_consent'     => 1,
			'consent_date'        => current_time( 'mysql' ),
			// Dati tutore
			'guardian_first_name'    => $guardian_first_name,
			'guardian_last_name'     => $guardian_last_name,
			'guardian_role'          => $guardian_role_val,
			'guardian_dob'           => $guardian_dob,
			'guardian_birth_place'   => $guardian_birth_place,
			'guardian_birth_country' => $guardian_birth_country,
			'guardian_gender'        => $guardian_gender,
			'guardian_email'         => $guardian_email,
			'guardian_phone'         => $guardian_phone,
			'guardian_address'       => $guardian_address,
			'guardian_city'          => $guardian_city,
			'guardian_postal'        => $guardian_postal,
			'guardian_country'       => $guardian_country,
		);

		$wpdb->insert( $db->table( 'members' ), $member_data );
		$member_id = $wpdb->insert_id;

		if ( ! $member_id ) {
			// Rollback utente WP se l'insert fallisce
			wp_delete_user( $user_id );
			wp_send_json_error( array( 'message' => __( 'Errore durante il salvataggio. Riprova.', 'sd-logbook' ) ) );
		}

		// === 7. Salva diver profile (se subacqueo) ===
		if ( $is_scuba ) {
			$research_id    = SD_Membership_Helper::generate_research_id( $first_name, $last_name, $birth_date );
			$profile_data   = array(
				'user_id'                    => $user_id,
				'is_diabetic'                => $is_diabetic,
				'diabetes_type'              => $diabetes_type,
				'weight'                     => $weight,
				'height'                     => $height,
				'blood_type'                 => $blood_type,
				'allergies'                  => $allergies_json,
				'medications'                => $medications_json,
				'default_shared_for_research' => $default_shared,
				'id_for_research'            => $research_id,
				'birth_date'                 => $birth_date ?: null,
				'gender'                     => $gender,
				'phone'                      => $phone,
				'address'                    => $address_street,
				'zip'                        => $address_postal,
				'city'                       => $address_city,
				'diabetology_center'         => $diabetology_center,
			);

			// Upsert diver profile
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$db->table('diver_profiles')} WHERE user_id = %d",
					$user_id
				)
			);
			if ( $existing ) {
				$wpdb->update( $db->table( 'diver_profiles' ), $profile_data, array( 'user_id' => $user_id ) );
			} else {
				$wpdb->insert( $db->table( 'diver_profiles' ), $profile_data );
			}
		}

		// === 8. Salva familiari (tassa 75 CHF) ===
		if ( $fee_amount >= 75 && ! empty( $_POST['family_members'] ) ) {
			$family_members = wp_unslash( $_POST['family_members'] );
			if ( is_string( $family_members ) ) {
				$family_members = json_decode( $family_members, true ) ?: array();
			}
			foreach ( (array) $family_members as $fm ) {
				$fm_name = sanitize_text_field( $fm['first_name'] ?? '' );
				if ( empty( $fm_name ) ) {
					continue;
				}
				$fm_diab_type = sanitize_text_field( $fm['diabetes_type'] ?? 'non_diabetico' );
				$wpdb->insert(
					$db->table( 'family_members' ),
					array(
						'member_id'     => $member_id,
						'first_name'    => $fm_name,
						'last_name'     => sanitize_text_field( $fm['last_name'] ?? '' ),
						'date_of_birth' => ! empty( $fm['date_of_birth'] ) && strtotime( $fm['date_of_birth'] ) ? $fm['date_of_birth'] : null,
						'phone'         => sanitize_text_field( $fm['phone'] ?? '' ),
						'email'         => sanitize_email( $fm['email'] ?? '' ),
						'diabetes_type' => in_array( $fm_diab_type, array( 'tipo_1', 'tipo_2', 'non_diabetico' ), true ) ? $fm_diab_type : 'non_diabetico',
						'is_companion'  => 0,
					)
				);
			}
		}

		// === 9. Salva accompagnatori autorizzati (se minorenne) ===
		if ( $is_minor && ! empty( $_POST['companions'] ) ) {
			$companions = wp_unslash( $_POST['companions'] );
			if ( is_string( $companions ) ) {
				$companions = json_decode( $companions, true ) ?: array();
			}
			foreach ( (array) $companions as $comp ) {
				$comp_name = sanitize_text_field( $comp['first_name'] ?? '' );
				if ( empty( $comp_name ) ) {
					continue;
				}
				$wpdb->insert(
					$db->table( 'family_members' ),
					array(
						'member_id'      => $member_id,
						'first_name'     => $comp_name,
						'last_name'      => sanitize_text_field( $comp['last_name'] ?? '' ),
						'date_of_birth'  => ! empty( $comp['date_of_birth'] ) && strtotime( $comp['date_of_birth'] ) ? $comp['date_of_birth'] : null,
						'phone'          => sanitize_text_field( $comp['phone'] ?? '' ),
						'email'          => sanitize_email( $comp['email'] ?? '' ),
						'companion_role' => sanitize_text_field( $comp['companion_role'] ?? '' ),
						'is_companion'   => 1,
					)
				);
			}
		}

		// === 10. Crea pagamento in attesa ===
		$wpdb->insert(
			$db->table( 'payments' ),
			array(
				'member_id'      => $member_id,
				'amount'         => $fee_amount,
				'currency'       => 'CHF',
				'payment_method' => 'bonifico_iban',
				'payment_year'   => intval( gmdate( 'Y' ) ),
				'status'         => 'in_attesa',
				'registered_by'  => get_current_user_id(),
			)
		);

		// === 11. Audit log ===
		SD_Membership_Helper::log_audit( $member_id, 'register', 'sd_members', $member_id, null, $member_data );

		// === 12. Invio email ===
		SD_Membership_Helper::send_registration_emails( $member_id, $password );

		wp_send_json_success(
			array(
				'message'   => sprintf(
					/* translators: %s: member name */
					__( 'Iscrizione completata con successo! Benvenuto/a <strong>%s</strong>. Controlla la tua email per le credenziali di accesso e le istruzioni di pagamento.', 'sd-logbook' ),
					esc_html( $first_name . ' ' . $last_name )
				),
				'member_id' => $member_id,
			)
		);
	}
}
