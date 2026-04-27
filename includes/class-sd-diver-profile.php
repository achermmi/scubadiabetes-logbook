<?php
/**
 * Profilo Subacqueo - Record multipli
 *
 * Ogni sezione (certificazioni, idoneità, contatti emergenza) supporta
 * N record memorizzati come JSON in user_meta:
 *   sd_certifications    -> array di certificazioni
 *   sd_medical_clearances -> array di idoneità
 *   sd_emergency_contacts -> array di contatti
 *   sd_medical_docs       -> array di documenti caricati
 *
 * I dati diabete rimangono nella tabella sd_diver_profiles (1 record per utente)
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Diver_Profile {

	public function __construct() {
		add_shortcode( 'sd_diver_profile', array( $this, 'render_profile' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers
		add_action( 'wp_ajax_sd_save_certification', array( $this, 'save_certification' ) );
		add_action( 'wp_ajax_sd_delete_certification', array( $this, 'delete_certification' ) );
		add_action( 'wp_ajax_sd_save_medical_clearance', array( $this, 'save_medical_clearance' ) );
		add_action( 'wp_ajax_sd_delete_medical_clearance', array( $this, 'delete_medical_clearance' ) );
		add_action( 'wp_ajax_sd_save_emergency_contact', array( $this, 'save_emergency_contact' ) );
		add_action( 'wp_ajax_sd_delete_emergency_contact', array( $this, 'delete_emergency_contact' ) );
		add_action( 'wp_ajax_sd_delete_medical_doc', array( $this, 'delete_medical_doc' ) );
		add_action( 'wp_ajax_sd_save_diabetes_profile', array( $this, 'save_diabetes_profile' ) );
		add_action( 'wp_ajax_sd_save_sharing_preference', array( $this, 'save_sharing_preference' ) );
		add_action( 'wp_ajax_sd_save_personal_data', array( $this, 'save_personal_data' ) );
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-profile', SD_LOGBOOK_PLUGIN_URL . 'assets/css/profile.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
		wp_enqueue_script( 'sd-profile', SD_LOGBOOK_PLUGIN_URL . 'assets/js/profile.js', array( 'jquery' ), SD_LOGBOOK_VERSION, true );
		wp_localize_script(
			'sd-profile',
			'sdProfile',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sd_profile_nonce' ),
			)
		);
	}

	public function render_profile( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="sd-notice sd-notice-warning">'
				. __( 'Devi effettuare il login.', 'sd-logbook' )
				. ' <a href="' . SD_Logbook::get_login_url() . '">' . __( 'Accedi', 'sd-logbook' ) . '</a></div>';
		}

		$user_id     = get_current_user_id();
		$is_diabetic = SD_Roles::is_diabetic_diver( $user_id );

		// Load data
		$certifications     = get_user_meta( $user_id, 'sd_certifications', true ) ?: array();
		$medical_clearances = get_user_meta( $user_id, 'sd_medical_clearances', true ) ?: array();
		$emergency_contacts = get_user_meta( $user_id, 'sd_emergency_contacts', true ) ?: array();
		$medical_docs       = get_user_meta( $user_id, 'sd_medical_docs', true ) ?: array();

		// Diabetes profile from DB
		global $wpdb;
		$db               = new SD_Database();
		$diabetes_profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('diver_profiles')} WHERE user_id = %d",
				$user_id
			)
		);

		// Fallback: se il centro diabetologico e stato valorizzato in iscrizione (sd_members)
		// ma non ancora in sd_diver_profiles, lo mostriamo comunque nel profilo subacqueo.
		if ( ! $diabetes_profile || empty( $diabetes_profile->diabetology_center ) ) {
			$member_diabetology_center = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT diabetology_center FROM {$db->table('members')} WHERE wp_user_id = %d ORDER BY id DESC LIMIT 1",
					$user_id
				)
			);
			if ( ! empty( $member_diabetology_center ) ) {
				if ( ! $diabetes_profile ) {
					$diabetes_profile = new stdClass();
				}
				$diabetes_profile->diabetology_center = $member_diabetology_center;
			}
		}

		$current_user = wp_get_current_user();
		$display_name = trim( $current_user->first_name . ' ' . $current_user->last_name );
		if ( empty( $display_name ) ) {
			$display_name = $current_user->display_name;
		}

		// Role labels for display
		$wp_roles         = wp_roles();
		$user_role_labels = array();
		foreach ( $current_user->roles as $role ) {
			if ( isset( $wp_roles->role_names[ $role ] ) ) {
				$user_role_labels[] = translate_user_role( $wp_roles->role_names[ $role ] );
			}
		}
		$user_role_display = ! empty( $user_role_labels ) ? implode( ', ', $user_role_labels ) : '';

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/profile.php';
		return ob_get_clean();
	}

	// ================================================================
	// CERTIFICAZIONI
	// ================================================================
	public function save_certification() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		$certs   = get_user_meta( $user_id, 'sd_certifications', true ) ?: array();

		$new = array(
			'agency' => sanitize_text_field( $_POST['agency'] ?? '' ),
			'level'  => sanitize_text_field( $_POST['level'] ?? '' ),
			'date'   => sanitize_text_field( $_POST['cert_date'] ?? '' ),
			'number' => sanitize_text_field( $_POST['cert_number'] ?? '' ),
		);

		if ( empty( $new['agency'] ) || empty( $new['level'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Agenzia e livello sono obbligatori.', 'sd-logbook' ) ) );
		}

		// Handle optional document upload
		if ( ! empty( $_FILES['cert_doc'] ) && UPLOAD_ERR_OK === $_FILES['cert_doc']['error'] ) {
			$doc_result = $this->upload_cert_doc( $user_id, $_FILES['cert_doc'] );
			if ( $doc_result ) {
				$new['doc'] = $doc_result;
			} else {
				wp_send_json_error( array( 'message' => __( 'Formato documento non valido o file troppo grande (max 5 MB, PDF/JPG/PNG).', 'sd-logbook' ) ) );
			}
		}

		$edit_index = isset( $_POST['edit_index'] ) && '' !== $_POST['edit_index'] ? absint( $_POST['edit_index'] ) : -1;
		if ( $edit_index >= 0 && isset( $certs[ $edit_index ] ) ) {
			// Keep existing doc if no new file was uploaded
			if ( empty( $new['doc'] ) && ! empty( $certs[ $edit_index ]['doc'] ) ) {
				$new['doc'] = $certs[ $edit_index ]['doc'];
			}
			$certs[ $edit_index ] = $new;
		} else {
			$certs[] = $new;
		}

		update_user_meta( $user_id, 'sd_certifications', $certs );
		wp_send_json_success(
			array(
				'message' => __( 'Certificazione salvata.', 'sd-logbook' ),
				'data'    => $new,
			)
		);
	}

	public function delete_certification() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		$index   = absint( $_POST['index'] ?? -1 );
		$certs   = get_user_meta( $user_id, 'sd_certifications', true ) ?: array();
		if ( isset( $certs[ $index ] ) ) {
			// Delete associated document file if present
			if ( ! empty( $certs[ $index ]['doc']['path'] ) && file_exists( $certs[ $index ]['doc']['path'] ) ) {
				unlink( $certs[ $index ]['doc']['path'] );
			}
			array_splice( $certs, $index, 1 );
			update_user_meta( $user_id, 'sd_certifications', $certs );
		}
		wp_send_json_success();
	}

	// ================================================================
	// IDONEITÀ MEDICA
	// ================================================================
	public function save_medical_clearance() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id    = get_current_user_id();
		$clearances = get_user_meta( $user_id, 'sd_medical_clearances', true ) ?: array();

		$new = array(
			'date'   => sanitize_text_field( $_POST['clearance_date'] ?? '' ),
			'expiry' => sanitize_text_field( $_POST['clearance_expiry'] ?? '' ),
			'doctor' => sanitize_text_field( $_POST['clearance_doctor'] ?? '' ),
			'type'   => sanitize_text_field( $_POST['clearance_type'] ?? '' ),
			'notes'  => sanitize_text_field( $_POST['clearance_notes'] ?? '' ),
		);

		if ( empty( $new['date'] ) ) {
			wp_send_json_error( array( 'message' => __( 'La data è obbligatoria.', 'sd-logbook' ) ) );
		}

		// Handle file upload
		if ( ! empty( $_FILES['clearance_doc'] ) && UPLOAD_ERR_OK === $_FILES['clearance_doc']['error'] ) {
			$doc_result = $this->upload_single_doc( $user_id, $_FILES['clearance_doc'] );
			if ( $doc_result ) {
				$new['doc'] = $doc_result;
			}
		}

		$edit_index = isset( $_POST['edit_index'] ) && '' !== $_POST['edit_index'] ? absint( $_POST['edit_index'] ) : -1;
		if ( $edit_index >= 0 && isset( $clearances[ $edit_index ] ) ) {
			// Keep existing doc if no new upload
			if ( empty( $new['doc'] ) && ! empty( $clearances[ $edit_index ]['doc'] ) ) {
				$new['doc'] = $clearances[ $edit_index ]['doc'];
			}
			$clearances[ $edit_index ] = $new;
		} else {
			$clearances[] = $new;
		}

		// Sort by date desc
		usort(
			$clearances,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		update_user_meta( $user_id, 'sd_medical_clearances', $clearances );
		wp_send_json_success( array( 'message' => __( 'Idoneità salvata.', 'sd-logbook' ) ) );
	}

	public function delete_medical_clearance() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id    = get_current_user_id();
		$index      = absint( $_POST['index'] ?? -1 );
		$clearances = get_user_meta( $user_id, 'sd_medical_clearances', true ) ?: array();
		if ( isset( $clearances[ $index ] ) ) {
			// Delete associated doc
			if ( ! empty( $clearances[ $index ]['doc']['path'] ) && file_exists( $clearances[ $index ]['doc']['path'] ) ) {
				unlink( $clearances[ $index ]['doc']['path'] );
			}
			array_splice( $clearances, $index, 1 );
			update_user_meta( $user_id, 'sd_medical_clearances', $clearances );
		}
		wp_send_json_success();
	}

	// ================================================================
	// CONTATTI EMERGENZA
	// ================================================================
	public function save_emergency_contact() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id  = get_current_user_id();
		$contacts = get_user_meta( $user_id, 'sd_emergency_contacts', true ) ?: array();

		$new = array(
			'name'         => sanitize_text_field( $_POST['contact_name'] ?? '' ),
			'phone'        => sanitize_text_field( $_POST['contact_phone'] ?? '' ),
			'email'        => strtolower( sanitize_email( $_POST['contact_email'] ?? '' ) ),
			'relationship' => sanitize_text_field( $_POST['contact_relationship'] ?? '' ),
			'notes'        => sanitize_textarea_field( $_POST['contact_notes'] ?? '' ),
		);

		if ( empty( $new['name'] ) || empty( $new['phone'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Nome e telefono obbligatori.', 'sd-logbook' ) ) );
		}

		$edit_index = isset( $_POST['edit_index'] ) && '' !== $_POST['edit_index'] ? absint( $_POST['edit_index'] ) : -1;
		if ( $edit_index >= 0 && isset( $contacts[ $edit_index ] ) ) {
			$contacts[ $edit_index ] = $new;
		} else {
			$contacts[] = $new;
		}

		update_user_meta( $user_id, 'sd_emergency_contacts', $contacts );
		wp_send_json_success( array( 'message' => __( 'Contatto salvato.', 'sd-logbook' ) ) );
	}

	public function delete_emergency_contact() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id  = get_current_user_id();
		$index    = absint( $_POST['index'] ?? -1 );
		$contacts = get_user_meta( $user_id, 'sd_emergency_contacts', true ) ?: array();
		if ( isset( $contacts[ $index ] ) ) {
			array_splice( $contacts, $index, 1 );
			update_user_meta( $user_id, 'sd_emergency_contacts', $contacts );
		}
		wp_send_json_success();
	}

	// ================================================================
	// DOCUMENTI MEDICI (legacy delete)
	// ================================================================
	public function delete_medical_doc() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		$index   = absint( $_POST['doc_index'] ?? -1 );
		$docs    = get_user_meta( $user_id, 'sd_medical_docs', true ) ?: array();
		if ( isset( $docs[ $index ] ) ) {
			if ( ! empty( $docs[ $index ]['path'] ) && file_exists( $docs[ $index ]['path'] ) ) {
				unlink( $docs[ $index ]['path'] );
			}
			array_splice( $docs, $index, 1 );
			update_user_meta( $user_id, 'sd_medical_docs', $docs );
		}
		wp_send_json_success();
	}

	// ================================================================
	// PROFILO DIABETE
	// ================================================================
	public function save_diabetes_profile() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();

		if ( ! SD_Roles::is_diver( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato', 'sd-logbook' ) ) );
		}

		$legacy_diabetes_map = array(
			'tipo1' => 'tipo_1',
			'tipo2' => 'tipo_2',
			'none'  => 'non_diabetico',
		);
		$diabetes_type_raw   = sanitize_text_field( $_POST['diabetes_type'] ?? 'non_diabetico' );
		$diabetes_type_raw   = $legacy_diabetes_map[ $diabetes_type_raw ] ?? $diabetes_type_raw;
		$allowed_diabetes    = array( 'non_diabetico', 'tipo_1', 'tipo_2', 'tipo_3c', 'lada', 'mody', 'midd', 'altro', 'non_specificato' );
		$diabetes_type       = in_array( $diabetes_type_raw, $allowed_diabetes, true ) ? $diabetes_type_raw : 'non_diabetico';

		$legacy_therapy_map = array(
			'orale' => 'ipoglicemizzante_orale',
			'mista' => 'iniettiva_non_insulinica',
		);
		$therapy_type_raw  = sanitize_text_field( $_POST['therapy_type'] ?? 'none' );
		$therapy_type_raw  = $legacy_therapy_map[ $therapy_type_raw ] ?? $therapy_type_raw;
		$allowed_therapy   = array( 'none', 'mdi', 'csii', 'ahcl', 'ipoglicemizzante_orale', 'iniettiva_non_insulinica' );
		$therapy_type      = in_array( $therapy_type_raw, $allowed_therapy, true ) ? $therapy_type_raw : 'none';

		$therapy_detail       = sanitize_text_field( $_POST['therapy_detail'] ?? '' );
		$therapy_detail_other = sanitize_text_field( $_POST['therapy_detail_other'] ?? '' );

		$hba1c_unit_raw = sanitize_text_field( $_POST['hba1c_unit'] ?? 'percent' );
		$hba1c_unit     = in_array( $hba1c_unit_raw, array( 'percent', 'mmol_mol' ), true ) ? $hba1c_unit_raw : 'percent';

		$pump_raw   = sanitize_text_field( $_POST['insulin_pump_model'] ?? '' );
		$pump_other = sanitize_text_field( $_POST['insulin_pump_model_other'] ?? '' );
		if ( 'Altro' === $pump_raw && ! empty( $pump_other ) ) {
			$pump_raw = $pump_other;
		}

		$is_diabetic = 'non_diabetico' === $diabetes_type ? 0 : 1;

		$data = array(
			'is_diabetic'        => $is_diabetic,
			'diabetes_type'      => $diabetes_type,
			'diabetology_center' => sanitize_text_field( $_POST['diabetology_center'] ?? '' ) ?: null,
			'therapy_type'       => $therapy_type,
			'therapy_detail'     => $therapy_detail ?: null,
			'therapy_detail_other' => $therapy_detail_other ?: null,
			'hba1c_last'         => ! empty( $_POST['hba1c_last'] ) ? floatval( $_POST['hba1c_last'] ) : null,
			'hba1c_unit'         => $hba1c_unit,
			'hba1c_date'         => sanitize_text_field( $_POST['hba1c_date'] ?? '' ) ?: null,
			'uses_cgm'           => ! empty( $_POST['uses_cgm'] ) ? 1 : 0,
			'cgm_device'         => sanitize_text_field( $_POST['cgm_device'] ?? '' ) ?: null,
			'insulin_pump_model' => $pump_raw ?: null,
			'insulin_pump_model_other' => $pump_other ?: null,
			'glycemia_unit'      => in_array( $_POST['glycemia_unit'] ?? '', array( 'mg/dl', 'mmol/l' ), true ) ? $_POST['glycemia_unit'] : 'mg/dl',
			'notes'              => sanitize_textarea_field( $_POST['diabetes_notes'] ?? '' ) ?: null,
		);

		global $wpdb;
		$db       = new SD_Database();
		$table    = $db->table( 'diver_profiles' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) );

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'user_id' => $user_id ) );
		} else {
			$wpdb->insert( $table, array_merge( array( 'user_id' => $user_id ), $data ) );
		}

		// Sync WordPress role based on is_diabetic
		$wp_user = new WP_User( $user_id );
		if ( $is_diabetic ) {
			$wp_user->remove_role( 'sd_diver' );
			$wp_user->add_role( 'sd_diver_diabetic' );
		} else {
			$wp_user->remove_role( 'sd_diver_diabetic' );
			$wp_user->add_role( 'sd_diver' );
		}

		SD_Membership_Helper::sync_diabetes_consistency_for_user( $user_id, $diabetes_type );

		$this->update_research_id( $user_id );
		wp_send_json_success( array( 'message' => __( 'Dati diabete aggiornati.', 'sd-logbook' ) ) );
	}

	// ================================================================
	// PREFERENZA CONDIVISIONE
	// ================================================================
	public function save_sharing_preference() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		$shared  = ! empty( $_POST['default_shared_for_research'] ) ? 1 : 0;

		global $wpdb;
		$db       = new SD_Database();
		$table    = $db->table( 'diver_profiles' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) );

		if ( $existing ) {
			$wpdb->update( $table, array( 'default_shared_for_research' => $shared ), array( 'user_id' => $user_id ) );
		} else {
			$wpdb->insert(
				$table,
				array(
					'user_id'                      => $user_id,
					'default_shared_for_research'  => $shared,
				)
			);
		}

		$this->update_research_id( $user_id );
		wp_send_json_success( array( 'message' => __( 'Preferenza condivisione aggiornata.', 'sd-logbook' ) ) );
	}

	// ================================================================
	// DATI PERSONALI
	// ================================================================
	public function save_personal_data() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();

		$allowed_genders     = array( 'M', 'F', 'NB', 'U' );
		$gender_raw          = sanitize_text_field( $_POST['personal_gender'] ?? '' );
		$allowed_blood_types = array( 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', '0+', '0-' );
		$blood_type_raw      = sanitize_text_field( $_POST['personal_blood_type'] ?? '' );

		// Parse and sanitize allergies JSON array
		$allergies_raw  = wp_unslash( $_POST['personal_allergies'] ?? '[]' );
		$allergies_arr  = json_decode( $allergies_raw, true );
		$allergies_clean = array();
		if ( is_array( $allergies_arr ) ) {
			foreach ( $allergies_arr as $a ) {
				$clean = sanitize_text_field( (string) $a );
				if ( '' !== $clean ) {
					$allergies_clean[] = $clean;
				}
			}
		}

		// Parse and sanitize medications JSON array
		$medications_raw   = wp_unslash( $_POST['personal_medications'] ?? '[]' );
		$medications_arr   = json_decode( $medications_raw, true );
		$medications_clean = array();
		if ( is_array( $medications_arr ) ) {
			foreach ( $medications_arr as $m ) {
				$name = sanitize_text_field( is_array( $m ) ? ( $m['name'] ?? '' ) : (string) $m );
				if ( '' !== $name ) {
					$medications_clean[] = array(
						'name'    => $name,
						'sospeso' => is_array( $m ) && ! empty( $m['sospeso'] ),
					);
				}
			}
		}

		$data = array(
			'gender'      => in_array( $gender_raw, $allowed_genders, true ) ? $gender_raw : null,
			'gsm'         => sanitize_text_field( $_POST['personal_gsm'] ?? '' ) ?: null,
			'phone'       => sanitize_text_field( $_POST['personal_phone'] ?? '' ) ?: null,
			'address'     => sanitize_text_field( $_POST['personal_address'] ?? '' ) ?: null,
			'zip'         => sanitize_text_field( $_POST['personal_zip'] ?? '' ) ?: null,
			'city'        => sanitize_text_field( $_POST['personal_city'] ?? '' ) ?: null,
			'birth_date'  => sanitize_text_field( $_POST['personal_birth_date'] ?? '' ) ?: null,
			'weight'      => ! empty( $_POST['personal_weight'] ) ? floatval( $_POST['personal_weight'] ) : null,
			'height'      => ! empty( $_POST['personal_height'] ) ? absint( $_POST['personal_height'] ) : null,
			'blood_type'  => in_array( $blood_type_raw, $allowed_blood_types, true ) ? $blood_type_raw : null,
			'allergies'   => ! empty( $allergies_clean ) ? wp_json_encode( $allergies_clean ) : null,
			'medications' => ! empty( $medications_clean ) ? wp_json_encode( $medications_clean ) : null,
		);

		global $wpdb;
		$db       = new SD_Database();
		$table    = $db->table( 'diver_profiles' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) );

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'user_id' => $user_id ) );
		} else {
			$wpdb->insert( $table, array_merge( array( 'user_id' => $user_id ), $data ) );
		}

		$this->update_research_id( $user_id );
		wp_send_json_success( array( 'message' => __( 'Dati personali aggiornati.', 'sd-logbook' ) ) );
	}

	// ================================================================
	// HELPER: Genera e salva ID ricerca
	// Formato: YYYYMMDD + Iniziale Nome + Iniziale Cognome + Sesso + is_diabetic + shared
	// ================================================================
	private function generate_research_id( $user_id ) {
		global $wpdb;
		$db      = new SD_Database();
		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT birth_date, gender, is_diabetic, default_shared_for_research FROM {$db->table('diver_profiles')} WHERE user_id = %d",
				$user_id
			)
		);

		$user       = get_userdata( $user_id );
		$first_name = $user ? $user->first_name : '';
		$last_name  = $user ? $user->last_name : '';

		$birth    = ( $profile && $profile->birth_date ) ? str_replace( '-', '', $profile->birth_date ) : '00000000';
		$ini_f    = $first_name ? strtoupper( substr( $first_name, 0, 1 ) ) : 'X';
		$ini_l    = $last_name ? strtoupper( substr( $last_name, 0, 1 ) ) : 'X';
		$gender   = ( $profile && $profile->gender ) ? $profile->gender : 'U';
		$diabetic = $profile ? (int) $profile->is_diabetic : 0;
		$shared   = $profile ? (int) $profile->default_shared_for_research : 1;

		return $birth . $ini_f . $ini_l . $gender . $diabetic . $shared;
	}

	private function update_research_id( $user_id ) {
		global $wpdb;
		$db    = new SD_Database();
		$table = $db->table( 'diver_profiles' );
		$id    = $this->generate_research_id( $user_id );
		$wpdb->update( $table, array( 'id_for_research' => $id ), array( 'user_id' => $user_id ) );
	}

	// ================================================================
	// HELPER: Upload singolo documento
	// ================================================================
	private function upload_cert_doc( $user_id, $file ) {
		$allowed  = array( 'pdf', 'jpg', 'jpeg', 'png' );
		$max_size = 5 * 1024 * 1024;

		$name = sanitize_file_name( $file['name'] );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, $allowed, true ) || $file['size'] > $max_size ) {
			return null;
		}

		$upload_dir  = wp_upload_dir();
		$sd_dir      = $upload_dir['basedir'] . '/sd-cert-docs/' . $user_id;
		wp_mkdir_p( $sd_dir );

		$unique_name = time() . '-' . $name;
		$dest        = $sd_dir . '/' . $unique_name;

		if ( move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return array(
				'name' => $name,
				'url'  => $upload_dir['baseurl'] . '/sd-cert-docs/' . $user_id . '/' . $unique_name,
				'path' => $dest,
				'date' => date_i18n( 'd/m/Y' ),
			);
		}
		return null;
	}

	private function upload_single_doc( $user_id, $file ) {
		$allowed  = array( 'pdf', 'jpg', 'jpeg', 'png', 'zip' );
		$max_size = 5 * 1024 * 1024;

		$name = sanitize_file_name( $file['name'] );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, $allowed, true ) || $file['size'] > $max_size ) {
			return null;
		}

		$upload_dir = wp_upload_dir();
		$sd_dir     = $upload_dir['basedir'] . '/sd-medical-docs/' . $user_id;
		wp_mkdir_p( $sd_dir );

		$unique_name = time() . '-' . $name;
		$dest        = $sd_dir . '/' . $unique_name;

		if ( move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return array(
				'name' => $name,
				'url'  => $upload_dir['baseurl'] . '/sd-medical-docs/' . $user_id . '/' . $unique_name,
				'path' => $dest,
				'date' => date_i18n( 'd/m/Y' ),
			);
		}
		return null;
	}
}
