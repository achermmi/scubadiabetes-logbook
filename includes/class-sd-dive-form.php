<?php
/**
 * Gestione Form Immersione - Frontend
 *
 * Shortcode [sd_dive_form] per registrare una nuova immersione.
 * Il form mostra i dati subacquei per tutti gli utenti.
 * Per i subacquei diabetici viene mostrata anche la sezione glicemie (Step 4).
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Dive_Form {

	public function __construct() {
		add_shortcode( 'sd_dive_form', array( $this, 'render_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sd_save_dive', array( $this, 'save_dive' ) );
		add_action( 'wp_ajax_sd_save_glycemia_unit', array( $this, 'save_glycemia_unit' ) );
		add_action( 'wp_ajax_sd_cgm_prefill', array( $this, 'ajax_cgm_prefill' ) );
		add_action( 'wp_ajax_sd_gear_profiles_list', array( $this, 'ajax_gear_profiles_list' ) );
		add_action( 'wp_ajax_sd_gear_profile_save', array( $this, 'ajax_gear_profile_save' ) );
		add_action( 'wp_ajax_sd_gear_profile_delete', array( $this, 'ajax_gear_profile_delete' ) );
		add_action( 'wp_ajax_sd_gear_profile_duplicate', array( $this, 'ajax_gear_profile_duplicate' ) );
		add_action( 'wp_ajax_sd_gear_profiles_reorder', array( $this, 'ajax_gear_profiles_reorder' ) );
	}

	/**
	 * Carica CSS e JS solo nelle pagine con lo shortcode
	 */
	public function enqueue_assets() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'sd_dive_form' ) ) {
			$form_css_path = SD_LOGBOOK_PLUGIN_DIR . 'assets/css/dive-form.css';
			$form_css_ver  = file_exists( $form_css_path ) ? (string) filemtime( $form_css_path ) : SD_LOGBOOK_VERSION;
			$dia_css_path  = SD_LOGBOOK_PLUGIN_DIR . 'assets/css/diabetes-form.css';
			$dia_css_ver   = file_exists( $dia_css_path ) ? (string) filemtime( $dia_css_path ) : SD_LOGBOOK_VERSION;
			$form_js_path  = SD_LOGBOOK_PLUGIN_DIR . 'assets/js/dive-form.js';
			$form_js_ver   = file_exists( $form_js_path ) ? (string) filemtime( $form_js_path ) : SD_LOGBOOK_VERSION;
			$dia_js_path   = SD_LOGBOOK_PLUGIN_DIR . 'assets/js/diabetes-form.js';
			$dia_js_ver    = file_exists( $dia_js_path ) ? (string) filemtime( $dia_js_path ) : SD_LOGBOOK_VERSION;

			wp_enqueue_style(
				'sd-logbook-form',
				SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css',
				array(),
				$form_css_ver
			);
			wp_enqueue_style(
				'sd-logbook-diabetes',
				SD_LOGBOOK_PLUGIN_URL . 'assets/css/diabetes-form.css',
				array( 'sd-logbook-form' ),
				$dia_css_ver
			);
			wp_enqueue_script(
				'sd-logbook-form',
				SD_LOGBOOK_PLUGIN_URL . 'assets/js/dive-form.js',
				array( 'jquery' ),
				$form_js_ver,
				true
			);
			wp_enqueue_script(
				'sd-logbook-diabetes',
				SD_LOGBOOK_PLUGIN_URL . 'assets/js/diabetes-form.js',
				array( 'jquery', 'sd-logbook-form' ),
				$dia_js_ver,
				true
			);
			// Get user's glycemia unit preference
			$glycemia_unit = 'mg/dl';
			global $wpdb;
			$db_tmp    = new SD_Database();
			$user_unit = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT glycemia_unit FROM {$db_tmp->table('diver_profiles')} WHERE user_id = %d",
					get_current_user_id()
				)
			);
			if ( $user_unit ) {
				$glycemia_unit = $user_unit;
			}

			$default_shared = 1;
			$user_shared    = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT default_shared_for_research FROM {$db_tmp->table('diver_profiles')} WHERE user_id = %d",
					get_current_user_id()
				)
			);
			if ( null !== $user_shared ) {
				$default_shared = (int) $user_shared;
			}

			// Controlla se l'utente ha un dispositivo CGM configurato
			$cgm_device = $this->get_user_cgm_device( get_current_user_id() );

			wp_localize_script(
				'sd-logbook-form',
				'sdLogbook',
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'nonce'          => wp_create_nonce( 'sd_dive_form_nonce' ),
					'glycemiaUnit'   => $glycemia_unit,
					'defaultShared'  => $default_shared,
					'dashboardUrl'   => home_url( '/dashboard-immersioni/' ),
					'cgmDevice'      => $cgm_device,
					'strings'      => array(
						'saving'      => __( 'Salvataggio...', 'sd-logbook' ),
						'saved'       => __( 'Immersione salvata!', 'sd-logbook' ),
						'error'       => __( 'Errore nel salvataggio', 'sd-logbook' ),
						'required'    => __( 'Campo obbligatorio', 'sd-logbook' ),
						'confirmSave' => __( 'Confermi il salvataggio dell\'immersione?', 'sd-logbook' ),
						'gearProfilesLoadError' => __( 'Impossibile caricare i profili attrezzatura.', 'sd-logbook' ),
						'gearProfilesSaveError' => __( 'Impossibile salvare il profilo attrezzatura.', 'sd-logbook' ),
						'gearProfilesDeleteError' => __( 'Impossibile eliminare il profilo attrezzatura.', 'sd-logbook' ),
						'gearProfileNameRequired' => __( 'Inserisci un nome profilo (es. apnea, OWD, tecnica).', 'sd-logbook' ),
						'gearProfileSaved' => __( 'Profilo attrezzatura salvato.', 'sd-logbook' ),
						'gearProfileDeleted' => __( 'Profilo attrezzatura eliminato.', 'sd-logbook' ),
						'gearProfileApplied' => __( 'Profilo attrezzatura applicato al form.', 'sd-logbook' ),
						'gearProfileSelectRequired' => __( 'Seleziona prima un profilo attrezzatura.', 'sd-logbook' ),
						'gearProfileDeleteConfirm' => __( 'Confermi l\'eliminazione del profilo selezionato?', 'sd-logbook' ),
						'gearProfileDuplicateError' => __( 'Impossibile duplicare il profilo attrezzatura.', 'sd-logbook' ),
						'gearProfileDuplicated' => __( 'Profilo attrezzatura duplicato.', 'sd-logbook' ),
						'gearProfilesReorderError' => __( 'Impossibile aggiornare l\'ordine dei profili.', 'sd-logbook' ),
						'gearProfilesOrderSaved' => __( 'Ordine profili aggiornato.', 'sd-logbook' ),
						'gearProfilesReorderNeedTwo' => __( 'Servono almeno 2 profili per poter cambiare ordine.', 'sd-logbook' ),
						'gearProfilesReorderAtBoundary' => __( 'Il profilo selezionato è già al limite dell\'elenco.', 'sd-logbook' ),
					),
				)
			);
		}
	}

	/**
	 * Render del form tramite shortcode
	 */
	public function render_form( $atts ) {
		// Verifica login
		if ( ! is_user_logged_in() ) {
			return '<div class="sd-notice sd-notice-warning">'
				. __( 'Devi effettuare il login per registrare un\'immersione.', 'sd-logbook' )
				. ' <a href="' . SD_Logbook::get_login_url() . '">' . __( 'Accedi', 'sd-logbook' ) . '</a>'
				. '</div>';
		}

		// Verifica permessi
		if ( ! current_user_can( 'sd_log_dive' ) ) {
			return '<div class="sd-notice sd-notice-error">'
				. __( 'Non hai i permessi per registrare immersioni.', 'sd-logbook' )
				. '</div>';
		}

		$user_id     = get_current_user_id();
		$is_diabetic = SD_Roles::is_diabetic_diver( $user_id );

		// Calcola prossimo numero immersione
		global $wpdb;
		$db          = new SD_Database();
		$last_number = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(dive_number) FROM {$db->table('dives')} WHERE user_id = %d",
				$user_id
			)
		);
		$next_number = $last_number ? $last_number + 1 : 1;

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/dive-form.php';
		return ob_get_clean();
	}

	/**
	 * Salva l'immersione via AJAX
	 */
	public function save_dive() {
		// Verifica nonce
		if ( ! check_ajax_referer( 'sd_dive_form_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta. Ricarica la pagina.', 'sd-logbook' ) ) );
		}

		// Verifica permessi
		if ( ! current_user_can( 'sd_log_dive' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		$user_id = get_current_user_id();

		// Sanitizza i dati
		$data = array(
			'user_id'           => $user_id,
			'dive_number'       => absint( $_POST['dive_number'] ?? 0 ) ?: null,
			'dive_date'         => sanitize_text_field( $_POST['dive_date'] ?? '' ),
			'site_name'         => sanitize_text_field( $_POST['site_name'] ?? '' ),
			'site_location'     => sanitize_text_field( $_POST['site_location'] ?? '' ) ?: null,
			'site_latitude'     => ! empty( $_POST['site_latitude'] ) ? floatval( $_POST['site_latitude'] ) : null,
			'site_longitude'    => ! empty( $_POST['site_longitude'] ) ? floatval( $_POST['site_longitude'] ) : null,
			'time_in'           => sanitize_text_field( $_POST['time_in'] ?? '' ) ?: null,
			'time_out'          => sanitize_text_field( $_POST['time_out'] ?? '' ) ?: null,
			'pressure_start'    => ! empty( $_POST['pressure_start'] ) ? absint( $_POST['pressure_start'] ) : null,
			'pressure_end'      => ! empty( $_POST['pressure_end'] ) ? absint( $_POST['pressure_end'] ) : null,
			'max_depth'         => ! empty( $_POST['max_depth'] ) ? floatval( $_POST['max_depth'] ) : null,
			'avg_depth'         => ! empty( $_POST['avg_depth'] ) ? floatval( $_POST['avg_depth'] ) : null,
			'dive_time'         => ! empty( $_POST['dive_time'] ) ? absint( $_POST['dive_time'] ) : null,
			'tank_count'        => ! empty( $_POST['tank_count'] ) ? absint( $_POST['tank_count'] ) : 1,
			'tank_capacity'     => ! empty( $_POST['tank_capacity'] ) ? floatval( $_POST['tank_capacity'] ) : null,
			'gas_mix'           => sanitize_text_field( $_POST['gas_mix'] ?? 'aria' ),
			'nitrox_percentage' => ! empty( $_POST['nitrox_percentage'] ) ? floatval( $_POST['nitrox_percentage'] ) : null,
			'safety_stop_depth' => ! empty( $_POST['safety_stop_depth'] ) ? floatval( $_POST['safety_stop_depth'] ) : null,
			'safety_stop_time'  => ! empty( $_POST['safety_stop_time'] ) ? absint( $_POST['safety_stop_time'] ) : null,
			'deco_stop_depth'   => ! empty( $_POST['deco_stop_depth'] ) ? floatval( $_POST['deco_stop_depth'] ) : null,
			'deco_stop_time'    => ! empty( $_POST['deco_stop_time'] ) ? absint( $_POST['deco_stop_time'] ) : null,
			'deep_stop_depth'   => ! empty( $_POST['deep_stop_depth'] ) ? floatval( $_POST['deep_stop_depth'] ) : null,
			'deep_stop_time'    => ! empty( $_POST['deep_stop_time'] ) ? absint( $_POST['deep_stop_time'] ) : null,
			'ballast_kg'        => ! empty( $_POST['ballast_kg'] ) ? floatval( $_POST['ballast_kg'] ) : null,
			'entry_type'        => sanitize_text_field( $_POST['entry_type'] ?? '' ) ?: null,
			'dive_type'         => sanitize_text_field( $_POST['dive_type'] ?? '' ) ?: null,
			'weather'           => sanitize_text_field( $_POST['weather'] ?? '' ) ?: null,
			'temp_air'          => ! empty( $_POST['temp_air'] ) ? floatval( $_POST['temp_air'] ) : null,
			'temp_water'        => ! empty( $_POST['temp_water'] ) ? floatval( $_POST['temp_water'] ) : null,
			'sea_condition'     => sanitize_text_field( $_POST['sea_condition'] ?? '' ) ?: null,
			'current_strength'  => sanitize_text_field( $_POST['current_strength'] ?? '' ) ?: null,
			'visibility'        => sanitize_text_field( $_POST['visibility'] ?? '' ) ?: null,
			'suit_type'            => sanitize_text_field( $_POST['suit_type'] ?? '' ) ?: null,
			'gear_notes'           => sanitize_textarea_field( $_POST['gear_notes'] ?? '' ) ?: null,
			'thermal_comfort'      => sanitize_text_field( $_POST['thermal_comfort'] ?? '' ) ?: null,
			'workload'             => sanitize_text_field( $_POST['workload'] ?? '' ) ?: null,
			'problems'             => sanitize_text_field( $_POST['problems'] ?? '' ) ?: null,
			'malfunctions'         => sanitize_text_field( $_POST['malfunctions'] ?? '' ) ?: null,
			'symptoms'             => sanitize_text_field( $_POST['symptoms'] ?? '' ) ?: null,
			'exposure_to_altitude' => sanitize_text_field( $_POST['exposure_to_altitude'] ?? '' ) ?: null,
			'sightings'            => sanitize_textarea_field( $_POST['sightings'] ?? '' ) ?: null,
			'other_equipment'      => sanitize_textarea_field( $_POST['other_equipment'] ?? '' ) ?: null,
			'notes'                => sanitize_textarea_field( $_POST['notes'] ?? '' ) ?: null,
			'buddy_name'           => sanitize_text_field( $_POST['buddy_name'] ?? '' ) ?: null,
			'guide_name'           => sanitize_text_field( $_POST['guide_name'] ?? '' ) ?: null,
			'shared_for_research'  => ! empty( $_POST['shared_for_research'] ) ? 1 : 0,
		);

		// Validazione campi obbligatori
		if ( empty( $data['dive_date'] ) ) {
			wp_send_json_error( array( 'message' => __( 'La data è obbligatoria.', 'sd-logbook' ) ) );
		}
		if ( empty( $data['site_location'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Il luogo di immersione è obbligatorio.', 'sd-logbook' ) ) );
		}

		// Inserisci o aggiorna nel database
		global $wpdb;
		$db    = new SD_Database();
		$table = $db->table( 'dives' );

		$saved_dive_id = ! empty( $_POST['saved_dive_id'] ) ? absint( $_POST['saved_dive_id'] ) : 0;

		if ( $saved_dive_id ) {
			// UPDATE: verifica che l'immersione appartenga all'utente corrente
			$owner = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id FROM {$table} WHERE id = %d",
					$saved_dive_id
				)
			);
			if ( (int) $owner !== $user_id ) {
				wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
			}
			$result = $wpdb->update( $table, $data, array( 'id' => $saved_dive_id ) );
			if ( false === $result ) {
				wp_send_json_error( array( 'message' => __( 'Errore nel salvataggio. Riprova.', 'sd-logbook' ) ) );
			}
			$dive_id = $saved_dive_id;
		} else {
			// INSERT
			$result = $wpdb->insert( $table, $data );
			if ( false === $result ) {
				wp_send_json_error( array( 'message' => __( 'Errore nel salvataggio. Riprova.', 'sd-logbook' ) ) );
			}
			$dive_id = $wpdb->insert_id;
		}

		// Se utente diabetico, salva anche i dati diabete
		if ( SD_Roles::is_diabetic_diver( $user_id ) ) {
			$this->save_diabetes_data( $dive_id, $user_id );
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Immersione salvata con successo!', 'sd-logbook' ),
				'dive_id'     => $dive_id,
				'is_diabetic' => SD_Roles::is_diabetic_diver( $user_id ),
			)
		);
	}

	/**
	 * Salva i dati diabete per un'immersione
	 * I valori glicemici vengono SEMPRE salvati in mg/dL nel database.
	 * Se l'utente usa mmol/L, il form invia un flag e i valori vengono convertiti.
	 */
	private function save_diabetes_data( $dive_id, $user_id ) {
		global $wpdb;
		$db    = new SD_Database();
		$table = $db->table( 'dive_diabetes' );

		// Determina se i valori in arrivo sono in mmol/L
		$input_unit = sanitize_text_field( $_POST['glycemia_input_unit'] ?? 'mg/dl' );
		$is_mmol    = ( 'mmol/l' === $input_unit );

		$checkpoints   = array( '60', '30', '10', 'post' );
		$diabetes_data = array(
			'dive_id' => $dive_id,
			'user_id' => $user_id,
		);

		foreach ( $checkpoints as $cp ) {
			$prefix    = 'glic_' . $cp . '_';

			// Capillare
			$raw_cap = ! empty( $_POST[ $prefix . 'cap' ] ) ? floatval( $_POST[ $prefix . 'cap' ] ) : null;
			if ( null !== $raw_cap && $is_mmol ) {
				$raw_cap = round( $raw_cap * 18 );
			} elseif ( null !== $raw_cap ) {
				$raw_cap = absint( $raw_cap );
			}
			// Sensore
			$raw_sens = ! empty( $_POST[ $prefix . 'sens' ] ) ? floatval( $_POST[ $prefix . 'sens' ] ) : null;
			if ( null !== $raw_sens && $is_mmol ) {
				$raw_sens = round( $raw_sens * 18 );
			} elseif ( null !== $raw_sens ) {
				$raw_sens = absint( $raw_sens );
			}

			$diabetes_data[ $prefix . 'cap' ]        = $raw_cap;
			$diabetes_data[ $prefix . 'sens' ]       = $raw_sens;
			$diabetes_data[ $prefix . 'trend' ]      = sanitize_text_field( $_POST[ $prefix . 'trend' ] ?? '' ) ?: null;
			$diabetes_data[ $prefix . 'cho_rapidi' ] = ! empty( $_POST[ $prefix . 'cho_rapidi' ] ) ? floatval( $_POST[ $prefix . 'cho_rapidi' ] ) : null;
			$diabetes_data[ $prefix . 'cho_lenti' ]  = ! empty( $_POST[ $prefix . 'cho_lenti' ] ) ? floatval( $_POST[ $prefix . 'cho_lenti' ] ) : null;
			$diabetes_data[ $prefix . 'insulin' ]    = ! empty( $_POST[ $prefix . 'insulin' ] ) ? floatval( $_POST[ $prefix . 'insulin' ] ) : null;
			$diabetes_data[ $prefix . 'notes' ]      = sanitize_text_field( $_POST[ $prefix . 'notes' ] ?? '' ) ?: null;
		}

		// 4 misure extra (extra1-extra4)
		$valid_when = array( 'prima_60', 'prima_30', 'prima_10', 'prima_post', 'dopo_post' );
		foreach ( array( 'extra1', 'extra2', 'extra3', 'extra4' ) as $ex ) {
			$prefix = 'glic_' . $ex . '_';

			$diabetes_data[ $prefix . 'when' ] = in_array( $_POST[ $prefix . 'when' ] ?? '', $valid_when, true )
				? $_POST[ $prefix . 'when' ] : null;

			$raw_cap = ! empty( $_POST[ $prefix . 'cap' ] ) ? floatval( $_POST[ $prefix . 'cap' ] ) : null;
			if ( null !== $raw_cap ) {
				$raw_cap = $is_mmol ? round( $raw_cap * 18 ) : absint( $raw_cap );
			}
			$raw_sens = ! empty( $_POST[ $prefix . 'sens' ] ) ? floatval( $_POST[ $prefix . 'sens' ] ) : null;
			if ( null !== $raw_sens ) {
				$raw_sens = $is_mmol ? round( $raw_sens * 18 ) : absint( $raw_sens );
			}

			$diabetes_data[ $prefix . 'cap' ]        = $raw_cap;
			$diabetes_data[ $prefix . 'sens' ]       = $raw_sens;
			$diabetes_data[ $prefix . 'trend' ]      = sanitize_text_field( $_POST[ $prefix . 'trend' ] ?? '' ) ?: null;
			$diabetes_data[ $prefix . 'cho_rapidi' ] = ! empty( $_POST[ $prefix . 'cho_rapidi' ] ) ? floatval( $_POST[ $prefix . 'cho_rapidi' ] ) : null;
			$diabetes_data[ $prefix . 'cho_lenti' ]  = ! empty( $_POST[ $prefix . 'cho_lenti' ] ) ? floatval( $_POST[ $prefix . 'cho_lenti' ] ) : null;
			$diabetes_data[ $prefix . 'insulin' ]    = ! empty( $_POST[ $prefix . 'insulin' ] ) ? floatval( $_POST[ $prefix . 'insulin' ] ) : null;
			$diabetes_data[ $prefix . 'notes' ]      = sanitize_text_field( $_POST[ $prefix . 'notes' ] ?? '' ) ?: null;
		}

		// Decisione immersione
		$diabetes_data['dive_decision']        = sanitize_text_field( $_POST['dive_decision'] ?? '' ) ?: null;
		$diabetes_data['dive_decision_reason'] = sanitize_text_field( $_POST['dive_decision_reason'] ?? '' ) ?: null;

		// Chetonemia
		$diabetes_data['ketone_checked'] = ! empty( $_POST['ketone_checked'] ) ? 1 : 0;
		$diabetes_data['ketone_value']   = ! empty( $_POST['ketone_value'] ) ? floatval( $_POST['ketone_value'] ) : null;

		// Terapia insulinica
		$diabetes_data['basal_insulin_reduced'] = ! empty( $_POST['basal_insulin_reduced'] ) ? 1 : 0;
		$diabetes_data['basal_reduction_pct']   = ! empty( $_POST['basal_reduction_pct'] ) ? absint( $_POST['basal_reduction_pct'] ) : null;
		$diabetes_data['bolus_insulin_reduced'] = ! empty( $_POST['bolus_insulin_reduced'] ) ? 1 : 0;
		$diabetes_data['bolus_reduction_pct']   = ! empty( $_POST['bolus_reduction_pct'] ) ? absint( $_POST['bolus_reduction_pct'] ) : null;
		$diabetes_data['pump_disconnected']     = ! empty( $_POST['pump_disconnected'] ) ? 1 : 0;
		$diabetes_data['pump_disconnect_time']  = ! empty( $_POST['pump_disconnect_time'] ) ? absint( $_POST['pump_disconnect_time'] ) : null;

		// Ipoglicemia
		$diabetes_data['hypo_during_dive'] = ! empty( $_POST['hypo_during_dive'] ) ? 1 : 0;
		$diabetes_data['hypo_treatment']   = sanitize_textarea_field( $_POST['hypo_treatment'] ?? '' ) ?: null;

		// Note
		$diabetes_data['diabetes_notes'] = sanitize_textarea_field( $_POST['diabetes_notes'] ?? '' ) ?: null;

		$existing_diabetes_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE dive_id = %d AND user_id = %d",
				$dive_id,
				$user_id
			)
		);

		if ( $existing_diabetes_id ) {
			$wpdb->update( $table, $diabetes_data, array( 'id' => $existing_diabetes_id ) );
		} else {
			$wpdb->insert( $table, $diabetes_data );
		}
	}

	/**
	 * Salva la preferenza unità glicemica (AJAX, fire-and-forget dal form)
	 */
	public function save_glycemia_unit() {
		if ( ! check_ajax_referer( 'sd_dive_form_nonce', 'nonce', false ) ) {
			wp_send_json_error();
		}
		$user_id = get_current_user_id();
		$unit    = in_array( $_POST['glycemia_unit'] ?? '', array( 'mg/dl', 'mmol/l' ), true )
			? $_POST['glycemia_unit'] : 'mg/dl';

		global $wpdb;
		$db       = new SD_Database();
		$table    = $db->table( 'diver_profiles' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) );

		if ( $existing ) {
			$wpdb->update( $table, array( 'glycemia_unit' => $unit ), array( 'user_id' => $user_id ) );
		} else {
			$wpdb->insert(
				$table,
				array(
					'user_id'       => $user_id,
					'glycemia_unit' => $unit,
				)
			);
		}
		wp_send_json_success();
	}

	/**
	 * AJAX: lista dei profili attrezzatura salvati dall'utente.
	 */
	public function ajax_gear_profiles_list() {
		if ( ! check_ajax_referer( 'sd_dive_form_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta.', 'sd-logbook' ) ) );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'sd_log_dive' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		wp_send_json_success(
			array(
				'profiles' => $this->get_stored_gear_profiles( $user_id ),
			)
		);
	}

	/**
	 * AJAX: salva/aggiorna un profilo attrezzatura.
	 */
	public function ajax_gear_profile_save() {
		if ( ! check_ajax_referer( 'sd_dive_form_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta.', 'sd-logbook' ) ) );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'sd_log_dive' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		$profile_id   = sanitize_text_field( wp_unslash( $_POST['profile_id'] ?? '' ) );
		$profile_name = sanitize_text_field( wp_unslash( $_POST['profile_name'] ?? '' ) );

		if ( '' === $profile_name ) {
			wp_send_json_error( array( 'message' => __( 'Nome profilo obbligatorio.', 'sd-logbook' ) ) );
		}

		$profile_raw = $_POST['profile'] ?? array();
		if ( ! is_array( $profile_raw ) ) {
			$profile_raw = array();
		} else {
			$profile_raw = wp_unslash( $profile_raw );
		}

		$profile_fields = $this->sanitize_gear_profile_fields( $profile_raw );
		$profiles       = $this->get_stored_gear_profiles( $user_id );

		if ( '' === $profile_id ) {
			$profile_id = wp_generate_uuid4();
		}

		$updated = false;
		foreach ( $profiles as $idx => $item ) {
			if ( ( $item['id'] ?? '' ) === $profile_id ) {
				$profiles[ $idx ] = array(
					'id'         => $profile_id,
					'name'       => $profile_name,
					'fields'     => $profile_fields,
					'updated_at' => current_time( 'mysql', true ),
				);
				$updated = true;
				break;
			}
		}

		if ( ! $updated ) {
			$profiles[] = array(
				'id'         => $profile_id,
				'name'       => $profile_name,
				'fields'     => $profile_fields,
				'updated_at' => current_time( 'mysql', true ),
			);
		}

		// Limite prudenziale per evitare payload troppo grandi in user_meta.
		if ( count( $profiles ) > 30 ) {
			$profiles = array_slice( $profiles, 0, 30 );
		}

		$this->save_stored_gear_profiles( $user_id, $profiles );

		wp_send_json_success(
			array(
				'profiles'   => $profiles,
				'profile_id' => $profile_id,
			)
		);
	}

	/**
	 * AJAX: elimina un profilo attrezzatura.
	 */
	public function ajax_gear_profile_delete() {
		if ( ! check_ajax_referer( 'sd_dive_form_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta.', 'sd-logbook' ) ) );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'sd_log_dive' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		$profile_id = sanitize_text_field( wp_unslash( $_POST['profile_id'] ?? '' ) );
		if ( '' === $profile_id ) {
			wp_send_json_error( array( 'message' => __( 'Profilo non valido.', 'sd-logbook' ) ) );
		}

		$profiles = $this->get_stored_gear_profiles( $user_id );
		$before   = count( $profiles );

		$profiles = array_values(
			array_filter(
				$profiles,
				static function ( $item ) use ( $profile_id ) {
					return ( $item['id'] ?? '' ) !== $profile_id;
				}
			)
		);

		if ( count( $profiles ) === $before ) {
			wp_send_json_error( array( 'message' => __( 'Profilo non trovato.', 'sd-logbook' ) ) );
		}

		$this->save_stored_gear_profiles( $user_id, $profiles );

		wp_send_json_success(
			array(
				'profiles' => $profiles,
			)
		);
	}

	/**
	 * AJAX: duplica un profilo attrezzatura.
	 */
	public function ajax_gear_profile_duplicate() {
		if ( ! check_ajax_referer( 'sd_dive_form_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta.', 'sd-logbook' ) ) );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'sd_log_dive' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		$profile_id = sanitize_text_field( wp_unslash( $_POST['profile_id'] ?? '' ) );
		if ( '' === $profile_id ) {
			wp_send_json_error( array( 'message' => __( 'Profilo non valido.', 'sd-logbook' ) ) );
		}

		$profiles = $this->get_stored_gear_profiles( $user_id );
		$source   = null;

		foreach ( $profiles as $item ) {
			if ( ( $item['id'] ?? '' ) === $profile_id ) {
				$source = $item;
				break;
			}
		}

		if ( ! $source ) {
			wp_send_json_error( array( 'message' => __( 'Profilo non trovato.', 'sd-logbook' ) ) );
		}

		$base_name = sanitize_text_field( (string) ( $source['name'] ?? '' ) );
		$new_name  = $base_name . ' (copia)';

		$existing_names = array_map(
			static function ( $item ) {
				return strtolower( (string) ( $item['name'] ?? '' ) );
			},
			$profiles
		);

		$counter = 2;
		while ( in_array( strtolower( $new_name ), $existing_names, true ) ) {
			$new_name = sprintf( '%s (copia %d)', $base_name, $counter );
			++$counter;
		}

		$new_profile = array(
			'id'         => wp_generate_uuid4(),
			'name'       => $new_name,
			'fields'     => $this->sanitize_gear_profile_fields( is_array( $source['fields'] ?? null ) ? $source['fields'] : array() ),
			'updated_at' => current_time( 'mysql', true ),
		);

		$inserted = false;
		for ( $i = 0, $len = count( $profiles ); $i < $len; $i++ ) {
			if ( ( $profiles[ $i ]['id'] ?? '' ) === $profile_id ) {
				array_splice( $profiles, $i + 1, 0, array( $new_profile ) );
				$inserted = true;
				break;
			}
		}

		if ( ! $inserted ) {
			$profiles[] = $new_profile;
		}

		if ( count( $profiles ) > 30 ) {
			$profiles = array_slice( $profiles, 0, 30 );
		}

		$this->save_stored_gear_profiles( $user_id, $profiles );

		wp_send_json_success(
			array(
				'profiles'   => $profiles,
				'profile_id' => $new_profile['id'],
			)
		);
	}

	/**
	 * AJAX: aggiorna ordine manuale dei profili attrezzatura.
	 */
	public function ajax_gear_profiles_reorder() {
		if ( ! check_ajax_referer( 'sd_dive_form_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta.', 'sd-logbook' ) ) );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'sd_log_dive' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		$ordered_ids = $_POST['ordered_ids'] ?? array();
		if ( ! is_array( $ordered_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Ordine profili non valido.', 'sd-logbook' ) ) );
		}

		$ordered_ids = array_map(
			static function ( $id ) {
				return sanitize_text_field( (string) $id );
			},
			wp_unslash( $ordered_ids )
		);
		$ordered_ids = array_values( array_filter( $ordered_ids ) );

		$profiles = $this->get_stored_gear_profiles( $user_id );
		if ( empty( $profiles ) ) {
			wp_send_json_success( array( 'profiles' => array() ) );
		}

		$map = array();
		foreach ( $profiles as $item ) {
			$map[ $item['id'] ] = $item;
		}

		$reordered = array();
		foreach ( $ordered_ids as $id ) {
			if ( isset( $map[ $id ] ) ) {
				$reordered[] = $map[ $id ];
				unset( $map[ $id ] );
			}
		}

		foreach ( $profiles as $item ) {
			$id = $item['id'] ?? '';
			if ( '' !== $id && isset( $map[ $id ] ) ) {
				$reordered[] = $item;
				unset( $map[ $id ] );
			}
		}

		$this->save_stored_gear_profiles( $user_id, $reordered );

		wp_send_json_success(
			array(
				'profiles' => $reordered,
			)
		);
	}

	/**
	 * Restituisce i profili attrezzatura salvati in user_meta.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_stored_gear_profiles( int $user_id ): array {
		$profiles = get_user_meta( $user_id, 'sd_gear_profiles', true );
		if ( ! is_array( $profiles ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $profiles as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id     = sanitize_text_field( (string) ( $item['id'] ?? '' ) );
			$name   = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			$fields = is_array( $item['fields'] ?? null ) ? $item['fields'] : array();

			if ( '' === $id || '' === $name ) {
				continue;
			}

			$normalized[] = array(
				'id'         => $id,
				'name'       => $name,
				'fields'     => $this->sanitize_gear_profile_fields( $fields ),
				'updated_at' => sanitize_text_field( (string) ( $item['updated_at'] ?? '' ) ),
			);
		}

		return $normalized;
	}

	/**
	 * Salva in user_meta la collezione profili attrezzatura.
	 *
	 * @param int   $user_id User ID.
	 * @param array $profiles Lista profili.
	 * @return void
	 */
	private function save_stored_gear_profiles( int $user_id, array $profiles ): void {
		update_user_meta( $user_id, 'sd_gear_profiles', $profiles );
	}

	/**
	 * Sanitizza i campi del profilo attrezzatura.
	 *
	 * @param array $raw Payload raw da POST.
	 * @return array<string,string>
	 */
	private function sanitize_gear_profile_fields( array $raw ): array {
		$tank_count = isset( $raw['tank_count'] ) ? (int) $raw['tank_count'] : 1;
		if ( ! in_array( $tank_count, array( 1, 2, 3 ), true ) ) {
			$tank_count = 1;
		}

		$gas_mix = sanitize_text_field( (string) ( $raw['gas_mix'] ?? 'aria' ) );
		if ( ! in_array( $gas_mix, array( 'aria', 'nitrox', 'trimix' ), true ) ) {
			$gas_mix = 'aria';
		}

		$suit_type = sanitize_text_field( (string) ( $raw['suit_type'] ?? '' ) );
		if ( ! in_array( $suit_type, array( '', 'umida', 'semistagna', 'stagna' ), true ) ) {
			$suit_type = '';
		}

		$tank_capacity = '';
		if ( isset( $raw['tank_capacity'] ) && '' !== (string) $raw['tank_capacity'] ) {
			$tank_capacity = (string) round( (float) $raw['tank_capacity'], 1 );
		}

		$nitrox_percentage = '';
		if ( isset( $raw['nitrox_percentage'] ) && '' !== (string) $raw['nitrox_percentage'] ) {
			$nitrox = (float) $raw['nitrox_percentage'];
			if ( $nitrox >= 21 && $nitrox <= 100 ) {
				$nitrox_percentage = (string) round( $nitrox, 1 );
			}
		}

		$ballast_kg = '';
		if ( isset( $raw['ballast_kg'] ) && '' !== (string) $raw['ballast_kg'] ) {
			$ballast_kg = (string) round( (float) $raw['ballast_kg'], 1 );
		}

		return array(
			'tank_count'        => (string) $tank_count,
			'gas_mix'           => $gas_mix,
			'tank_capacity'     => $tank_capacity,
			'nitrox_percentage' => $nitrox_percentage,
			'ballast_kg'        => $ballast_kg,
			'suit_type'         => $suit_type,
			'gear_notes'        => sanitize_textarea_field( (string) ( $raw['gear_notes'] ?? '' ) ),
		);
	}

	/**
	 * Restituisce il nome del dispositivo CGM configurato dall'utente.
	 * Controlla in ordine: Nightscout, Dexcom, LibreView, CareLink, Tidepool.
	 *
	 * @param int $user_id ID utente WP.
	 * @return string Nome dispositivo (es. "Nightscout") o stringa vuota.
	 */
	private function get_user_cgm_device( int $user_id ): string {
		global $wpdb;
		$p = $wpdb->prefix . 'sd_';

		// Nightscout
		$ns = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$p}nightscout_connections WHERE user_id = %d LIMIT 1",
				$user_id
			)
		);
		if ( $ns ) {
			return 'Nightscout';
		}

		// Dexcom OAuth
		$dx = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$p}dexcom_oauth WHERE user_id = %d LIMIT 1",
				$user_id
			)
		);
		if ( $dx ) {
			return 'Dexcom';
		}

		// LibreView
		$lv = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$p}libreview_connections WHERE user_id = %d LIMIT 1",
				$user_id
			)
		);
		if ( $lv ) {
			return 'LibreView';
		}

		// CareLink
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}carelink_connections'" ) ) {
			$cl = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$p}carelink_connections WHERE user_id = %d LIMIT 1",
					$user_id
				)
			);
			if ( $cl ) {
				return 'CareLink';
			}
		}

		// Tidepool
		$tp = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$p}tidepool_connections WHERE user_id = %d LIMIT 1",
				$user_id
			)
		);
		if ( $tp ) {
			return 'Tidepool';
		}

		return '';
	}

	/**
	 * AJAX: restituisce le letture CGM ai 4 timepoint dell'immersione.
	 *
	 * Parametri POST:
	 *   - dive_date  (YYYY-MM-DD) orario locale sito
	 *   - time_in    (HH:MM)      inizio immersione, orario locale
	 *   - time_out   (HH:MM)      fine immersione, opzionale
	 *
	 * Le letture in DB sono sempre UTC (gmdate).
	 * Converte l'orario locale usando la timezone WordPress per la ricerca.
	 */
	public function ajax_cgm_prefill(): void {
		if ( ! check_ajax_referer( 'sd_dive_form_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta. Ricarica la pagina.', 'sd-logbook' ) ) );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$dive_date = sanitize_text_field( wp_unslash( $_POST['dive_date'] ?? '' ) );
		$time_in   = sanitize_text_field( wp_unslash( $_POST['time_in'] ?? '' ) );
		$time_out  = sanitize_text_field( wp_unslash( $_POST['time_out'] ?? '' ) );

		if ( empty( $dive_date ) || empty( $time_in ) ) {
			wp_send_json_error( array( 'message' => __( 'Inserisci data e ora di inizio immersione prima di importare le letture.', 'sd-logbook' ) ) );
		}

		// Converte orario locale WordPress → UTC timestamp
		$tz_string = get_option( 'timezone_string' );
		if ( $tz_string ) {
			$tz = new \DateTimeZone( $tz_string );
		} else {
			$offset = (float) get_option( 'gmt_offset', 0 );
			$sign   = $offset >= 0 ? '+' : '-';
			$abs    = abs( $offset );
			$h      = (int) $abs;
			$m      = (int) round( ( $abs - $h ) * 60 );
			$tz     = new \DateTimeZone( sprintf( '%s%02d:%02d', $sign, $h, $m ) );
		}

		// Timestamp UTC di inizio immersione
		$dt_in      = \DateTime::createFromFormat( 'Y-m-d H:i', $dive_date . ' ' . $time_in, $tz );
		if ( ! $dt_in ) {
			wp_send_json_error( array( 'message' => __( 'Data o orario non validi.', 'sd-logbook' ) ) );
		}
		$dive_start = $dt_in->getTimestamp();

		// Timestamp UTC di fine immersione (POST)
		if ( ! empty( $time_out ) ) {
			$dt_out   = \DateTime::createFromFormat( 'Y-m-d H:i', $dive_date . ' ' . $time_out, $tz );
			$dive_end = $dt_out ? $dt_out->getTimestamp() : $dive_start + 60 * 60;
		} else {
			$dive_end = $dive_start + 60 * 60; // approssimazione: +1h
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sd_nightscout_readings';

		/*
		 * Timepoints cercati:
		 *   -60 → 60 min prima del tuffo, finestra ±20 min
		 *   -30 → 30 min prima del tuffo, finestra ±15 min
		 *   -10 → 10 min prima del tuffo, finestra ±12 min
		 *   post → subito dopo la fine del tuffo, finestra +0 / +40 min
		 *          (NON cerchiamo prima perché il post è a tuffo concluso)
		 */
		$timepoints = array(
			'60'   => array(
				'ref' => $dive_start - 60 * 60,
				'before' => 20 * 60,
				'after' => 20 * 60,
			),
			'30'   => array(
				'ref' => $dive_start - 30 * 60,
				'before' => 15 * 60,
				'after' => 15 * 60,
			),
			'10'   => array(
				'ref' => $dive_start - 10 * 60,
				'before' => 12 * 60,
				'after' => 12 * 60,
			),
			'post' => array(
				'ref' => $dive_end,
				'before' => 5 * 60,
				'after' => 40 * 60,
			),
		);

		$results = array();
		foreach ( $timepoints as $key => $tp ) {
			$from     = gmdate( 'Y-m-d H:i:s', $tp['ref'] - $tp['before'] );
			$to       = gmdate( 'Y-m-d H:i:s', $tp['ref'] + $tp['after'] );
			$ref_utc  = gmdate( 'Y-m-d H:i:s', $tp['ref'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT glucose_value, direction, reading_time, device
					 FROM {$table}
					 WHERE user_id = %d
					   AND reading_time BETWEEN %s AND %s
					 ORDER BY ABS( TIMESTAMPDIFF( SECOND, reading_time, %s ) )
					 LIMIT 1",
					$user_id,
					$from,
					$to,
					$ref_utc
				)
			);

			$direction = $row && $row->direction ? (string) $row->direction : '';

			// Fallback: se la lettura più vicina non ha trend, prova a recuperare
			// la freccia dalla lettura con trend più vicina nella stessa finestra.
			if ( $row && ( '' === $direction || 'NONE' === strtoupper( $direction ) ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$trend_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT direction
						 FROM {$table}
						 WHERE user_id = %d
						   AND reading_time BETWEEN %s AND %s
						   AND direction IS NOT NULL
						   AND direction <> ''
						   AND UPPER(direction) <> 'NONE'
						 ORDER BY ABS( TIMESTAMPDIFF( SECOND, reading_time, %s ) )
						 LIMIT 1",
						$user_id,
						$from,
						$to,
						$ref_utc
					)
				);
				if ( $trend_row && ! empty( $trend_row->direction ) ) {
					$direction = (string) $trend_row->direction;
				}
			}

			$results[ $key ] = $row
				? array(
					'value'     => (int) $row->glucose_value,
					'direction' => $direction ? $direction : 'NONE',
					'time'      => $row->reading_time,
					'device'    => $row->device,
				)
				: null;
		}

		$found = array_filter( $results );
		if ( empty( $found ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Nessuna lettura CGM trovata per gli orari dell\'immersione. Verifica che il dispositivo sia sincronizzato e che l\'immersione sia avvenuta nelle ultime 24 ore.', 'sd-logbook' ),
				)
			);
		}

		wp_send_json_success( array( 'readings' => $results ) );
	}
}
