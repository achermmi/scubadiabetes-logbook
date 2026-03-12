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
	}

	/**
	 * Carica CSS e JS solo nelle pagine con lo shortcode
	 */
	public function enqueue_assets() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'sd_dive_form' ) ) {
			wp_enqueue_style(
				'sd-logbook-form',
				SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css',
				array(),
				SD_LOGBOOK_VERSION
			);
			wp_enqueue_style(
				'sd-logbook-diabetes',
				SD_LOGBOOK_PLUGIN_URL . 'assets/css/diabetes-form.css',
				array( 'sd-logbook-form' ),
				SD_LOGBOOK_VERSION
			);
			wp_enqueue_script(
				'sd-logbook-form',
				SD_LOGBOOK_PLUGIN_URL . 'assets/js/dive-form.js',
				array( 'jquery' ),
				SD_LOGBOOK_VERSION,
				true
			);
			wp_enqueue_script(
				'sd-logbook-diabetes',
				SD_LOGBOOK_PLUGIN_URL . 'assets/js/diabetes-form.js',
				array( 'jquery', 'sd-logbook-form' ),
				SD_LOGBOOK_VERSION,
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

			wp_localize_script(
				'sd-logbook-form',
				'sdLogbook',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( 'sd_dive_form_nonce' ),
					'glycemiaUnit' => $glycemia_unit,
					'strings'      => array(
						'saving'      => __( 'Salvataggio...', 'sd-logbook' ),
						'saved'       => __( 'Immersione salvata!', 'sd-logbook' ),
						'error'       => __( 'Errore nel salvataggio', 'sd-logbook' ),
						'required'    => __( 'Campo obbligatorio', 'sd-logbook' ),
						'confirmSave' => __( 'Confermi il salvataggio dell\'immersione?', 'sd-logbook' ),
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
			'suit_type'         => sanitize_text_field( $_POST['suit_type'] ?? '' ) ?: null,
			'sightings'         => sanitize_textarea_field( $_POST['sightings'] ?? '' ) ?: null,
			'other_equipment'   => sanitize_textarea_field( $_POST['other_equipment'] ?? '' ) ?: null,
			'notes'             => sanitize_textarea_field( $_POST['notes'] ?? '' ) ?: null,
			'buddy_name'        => sanitize_text_field( $_POST['buddy_name'] ?? '' ) ?: null,
			'guide_name'        => sanitize_text_field( $_POST['guide_name'] ?? '' ) ?: null,
		);

		// Validazione campi obbligatori
		if ( empty( $data['dive_date'] ) ) {
			wp_send_json_error( array( 'message' => __( 'La data è obbligatoria.', 'sd-logbook' ) ) );
		}
		if ( empty( $data['site_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Il sito di immersione è obbligatorio.', 'sd-logbook' ) ) );
		}

		// Inserisci nel database
		global $wpdb;
		$db    = new SD_Database();
		$table = $db->table( 'dives' );

		$result = $wpdb->insert( $table, $data );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Errore nel salvataggio. Riprova.', 'sd-logbook' ) ) );
		}

		$dive_id = $wpdb->insert_id;

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
			$raw_value = ! empty( $_POST[ $prefix . 'value' ] ) ? floatval( $_POST[ $prefix . 'value' ] ) : null;
			// Converti mmol/L → mg/dL se necessario
			if ( null !== $raw_value && $is_mmol ) {
				$raw_value = round( $raw_value * 18.018 );
			} elseif ( null !== $raw_value ) {
				$raw_value = absint( $raw_value );
			}
			$diabetes_data[ $prefix . 'value' ]      = $raw_value;
			$diabetes_data[ $prefix . 'method' ]     = in_array( $_POST[ $prefix . 'method' ] ?? '', array( 'C', 'S' ), true ) ? $_POST[ $prefix . 'method' ] : null;
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

		$wpdb->insert( $table, $diabetes_data );
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
}
