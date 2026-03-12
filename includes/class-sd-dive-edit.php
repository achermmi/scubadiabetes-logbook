<?php
/**
 * Modifica Immersione — Consulta e modifica le proprie immersioni
 *
 * Shortcode [sd_dive_edit]
 * L'utente vede le proprie immersioni, può modificarle.
 * Ogni modifica viene tracciata nella tabella sd_dive_edits.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Dive_Edit {

	public function __construct() {
		add_shortcode( 'sd_dive_edit', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sd_get_dive_for_edit', array( $this, 'get_dive_for_edit' ) );
		add_action( 'wp_ajax_sd_update_dive', array( $this, 'update_dive' ) );
		add_action( 'wp_ajax_sd_get_dive_history', array( $this, 'get_dive_history' ) );
	}

	public function enqueue_assets() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'sd_dive_edit' ) ) {
			return;
		}

		wp_enqueue_style( 'sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-logbook-diabetes', SD_LOGBOOK_PLUGIN_URL . 'assets/css/diabetes-form.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-dive-edit', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-edit.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
		wp_enqueue_script( 'sd-dive-edit', SD_LOGBOOK_PLUGIN_URL . 'assets/js/dive-edit.js', array( 'jquery' ), SD_LOGBOOK_VERSION, true );

		// User glycemia unit
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
			'sd-dive-edit',
			'sdDiveEdit',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'sd_dive_edit_nonce' ),
				'glycemiaUnit' => $glycemia_unit,
			)
		);
	}

	public function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="sd-notice sd-notice-warning">'
				. __( 'Devi effettuare il login.', 'sd-logbook' )
				. ' <a href="' . SD_Logbook::get_login_url() . '">' . __( 'Accedi', 'sd-logbook' ) . '</a></div>';
		}
		if ( ! current_user_can( 'sd_log_dive' ) ) {
			return '<div class="sd-notice sd-notice-error">' . __( 'Non hai i permessi.', 'sd-logbook' ) . '</div>';
		}

		$user_id     = get_current_user_id();
		$is_diabetic = SD_Roles::is_diabetic_diver( $user_id );

		// Fetch user's dives
		global $wpdb;
		$db    = new SD_Database();
		$dives = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.id, d.dive_number, d.dive_date, d.site_name, d.max_depth, d.dive_time,
					(SELECT COUNT(*) FROM {$db->table('dive_diabetes')} dd WHERE dd.dive_id = d.id) AS has_diabetes,
					(SELECT COUNT(*) FROM {$db->table('dive_edits')} de WHERE de.dive_id = d.id) AS edit_count
			 FROM {$db->table('dives')} d
			 WHERE d.user_id = %d
			 ORDER BY d.dive_date DESC, d.time_in DESC
			 LIMIT 200",
				$user_id
			)
		);

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/dive-edit.php';
		return ob_get_clean();
	}

	// ================================================================
	// AJAX: Carica dati immersione per modifica
	// ================================================================
	public function get_dive_for_edit() {
		check_ajax_referer( 'sd_dive_edit_nonce', 'nonce' );
		$dive_id = absint( $_POST['dive_id'] ?? 0 );
		$user_id = get_current_user_id();
		if ( ! $dive_id ) {
			wp_send_json_error();
		}

		global $wpdb;
		$db = new SD_Database();

		$dive = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('dives')} WHERE id = %d AND user_id = %d",
				$dive_id,
				$user_id
			),
			ARRAY_A
		);

		if ( ! $dive ) {
			wp_send_json_error( array( 'message' => 'Immersione non trovata' ) );
		}

		$diabetes = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('dive_diabetes')} WHERE dive_id = %d",
				$dive_id
			),
			ARRAY_A
		);

		wp_send_json_success(
			array(
				'dive'     => $dive,
				'diabetes' => $diabetes,
			)
		);
	}

	// ================================================================
	// AJAX: Salva modifiche con storico
	// ================================================================
	public function update_dive() {
		check_ajax_referer( 'sd_dive_edit_nonce', 'nonce' );
		$dive_id = absint( $_POST['dive_id'] ?? 0 );
		$user_id = get_current_user_id();
		if ( ! $dive_id ) {
			wp_send_json_error();
		}

		global $wpdb;
		$db = new SD_Database();

		// Verifica proprietà
		$old_dive = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('dives')} WHERE id = %d AND user_id = %d",
				$dive_id,
				$user_id
			),
			ARRAY_A
		);

		if ( ! $old_dive ) {
			wp_send_json_error( array( 'message' => 'Non autorizzato' ) );
		}

		// Campi aggiornabili — processa SOLO quelli presenti nel POST
		$dive_fields = array(
			'dive_number',
			'dive_date',
			'site_name',
			'site_latitude',
			'site_longitude',
			'time_in',
			'time_out',
			'pressure_start',
			'pressure_end',
			'max_depth',
			'avg_depth',
			'dive_time',
			'tank_count',
			'tank_capacity',
			'gas_mix',
			'nitrox_percentage',
			'safety_stop_depth',
			'safety_stop_time',
			'deco_stop_depth',
			'deco_stop_time',
			'deep_stop_depth',
			'deep_stop_time',
			'ballast_kg',
			'entry_type',
			'dive_type',
			'weather',
			'temp_air',
			'temp_water',
			'sea_condition',
			'current_strength',
			'visibility',
			'suit_type',
			'sightings',
			'other_equipment',
			'notes',
			'buddy_name',
			'guide_name',
		);

		$int_fields   = array(
			'dive_number',
			'pressure_start',
			'pressure_end',
			'dive_time',
			'tank_count',
			'safety_stop_time',
			'deco_stop_time',
			'deep_stop_time',
		);
		$float_fields = array(
			'site_latitude',
			'site_longitude',
			'max_depth',
			'avg_depth',
			'tank_capacity',
			'nitrox_percentage',
			'safety_stop_depth',
			'deco_stop_depth',
			'deep_stop_depth',
			'ballast_kg',
			'temp_air',
			'temp_water',
		);

		$new_dive = array();
		$changes  = array();

		foreach ( $dive_fields as $field ) {
			// Processa solo campi effettivamente presenti nel form inviato
			if ( ! array_key_exists( $field, $_POST ) ) {
				continue;
			}

			$new_val = sanitize_text_field( $_POST[ $field ] );
			if ( '' === $new_val ) {
				$new_val = null;
			}

			// Converti numerici
			if ( in_array( $field, $int_fields, true ) && null !== $new_val ) {
				$new_val = absint( $new_val );
			} elseif ( in_array( $field, $float_fields, true ) && null !== $new_val ) {
				$new_val = floatval( $new_val );
			}

			$new_dive[ $field ] = $new_val;

			// Confronto normalizzato: rimuovi differenze spurie come "18.0" vs "18"
			$old_val = $old_dive[ $field ] ?? null;
			if ( ! $this->values_equal( $old_val, $new_val, $field, $int_fields, $float_fields ) ) {
				$changes[] = array(
					'dive_id'    => $dive_id,
					'user_id'    => $user_id,
					'edit_type'  => 'update',
					'table_name' => 'dives',
					'field_name' => $field,
					'old_value'  => $old_val,
					'new_value'  => null !== $new_val ? (string) $new_val : null,
				);
			}
		}

		// Aggiorna solo se ci sono campi da aggiornare
		if ( ! empty( $new_dive ) ) {
			$wpdb->update( $db->table( 'dives' ), $new_dive, array( 'id' => $dive_id ) );
		}

		// ── Dati diabete ──
		if ( SD_Roles::is_diabetic_diver( $user_id ) ) {
			$old_diabetes = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$db->table('dive_diabetes')} WHERE dive_id = %d",
					$dive_id
				),
				ARRAY_A
			);

			$input_unit = sanitize_text_field( $_POST['glycemia_input_unit'] ?? 'mg/dl' );
			$is_mmol    = ( 'mmol/l' === $input_unit );

			$checkpoints      = array( '60', '30', '10', 'post' );
			$diabetes_data    = array();
			$diabetes_changes = array();

			foreach ( $checkpoints as $cp ) {
				$prefix = 'glic_' . $cp . '_';

				// Valore glicemico — converti in mg/dL se mmol/L
				$raw = ! empty( $_POST[ $prefix . 'value' ] ) ? floatval( $_POST[ $prefix . 'value' ] ) : null;
				if ( null !== $raw && $is_mmol ) {
					$raw = (int) round( $raw * 18.018 );
				} elseif ( null !== $raw ) {
					$raw = absint( $raw );
				}
				$diabetes_data[ $prefix . 'value' ] = $raw;

				$diabetes_data[ $prefix . 'method' ]     = in_array( $_POST[ $prefix . 'method' ] ?? '', array( 'C', 'S' ), true ) ? $_POST[ $prefix . 'method' ] : null;
				$diabetes_data[ $prefix . 'trend' ]      = sanitize_text_field( $_POST[ $prefix . 'trend' ] ?? '' ) ?: null;
				$diabetes_data[ $prefix . 'cho_rapidi' ] = ! empty( $_POST[ $prefix . 'cho_rapidi' ] ) ? floatval( $_POST[ $prefix . 'cho_rapidi' ] ) : null;
				$diabetes_data[ $prefix . 'cho_lenti' ]  = ! empty( $_POST[ $prefix . 'cho_lenti' ] ) ? floatval( $_POST[ $prefix . 'cho_lenti' ] ) : null;
				$diabetes_data[ $prefix . 'insulin' ]    = ! empty( $_POST[ $prefix . 'insulin' ] ) ? floatval( $_POST[ $prefix . 'insulin' ] ) : null;
				$diabetes_data[ $prefix . 'notes' ]      = sanitize_text_field( $_POST[ $prefix . 'notes' ] ?? '' ) ?: null;
			}

			// Campi extra diabete
			$diabetes_data['dive_decision']         = sanitize_text_field( $_POST['dive_decision'] ?? '' ) ?: null;
			$diabetes_data['dive_decision_reason']  = sanitize_text_field( $_POST['dive_decision_reason'] ?? '' ) ?: null;
			$diabetes_data['ketone_checked']        = ! empty( $_POST['ketone_checked'] ) ? 1 : 0;
			$diabetes_data['ketone_value']          = ! empty( $_POST['ketone_value'] ) ? floatval( $_POST['ketone_value'] ) : null;
			$diabetes_data['basal_insulin_reduced'] = ! empty( $_POST['basal_insulin_reduced'] ) ? 1 : 0;
			$diabetes_data['basal_reduction_pct']   = ! empty( $_POST['basal_reduction_pct'] ) ? absint( $_POST['basal_reduction_pct'] ) : null;
			$diabetes_data['bolus_insulin_reduced'] = ! empty( $_POST['bolus_insulin_reduced'] ) ? 1 : 0;
			$diabetes_data['bolus_reduction_pct']   = ! empty( $_POST['bolus_reduction_pct'] ) ? absint( $_POST['bolus_reduction_pct'] ) : null;
			$diabetes_data['pump_disconnected']     = ! empty( $_POST['pump_disconnected'] ) ? 1 : 0;
			$diabetes_data['pump_disconnect_time']  = ! empty( $_POST['pump_disconnect_time'] ) ? absint( $_POST['pump_disconnect_time'] ) : null;
			$diabetes_data['hypo_during_dive']      = ! empty( $_POST['hypo_during_dive'] ) ? 1 : 0;
			$diabetes_data['hypo_treatment']        = sanitize_textarea_field( $_POST['hypo_treatment'] ?? '' ) ?: null;
			$diabetes_data['diabetes_notes']        = sanitize_textarea_field( $_POST['diabetes_notes'] ?? '' ) ?: null;

			// Track changes — confronto normalizzato
			if ( $old_diabetes ) {
				foreach ( $diabetes_data as $field => $new_val ) {
					$old_val = $old_diabetes[ $field ] ?? null;
					if ( ! $this->values_equal( $old_val, $new_val, $field, array(), array() ) ) {
						$changes[] = array(
							'dive_id'    => $dive_id,
							'user_id'    => $user_id,
							'edit_type'  => 'update',
							'table_name' => 'dive_diabetes',
							'field_name' => $field,
							'old_value'  => $old_val,
							'new_value'  => null !== $new_val ? (string) $new_val : null,
						);
					}
				}
				$wpdb->update( $db->table( 'dive_diabetes' ), $diabetes_data, array( 'dive_id' => $dive_id ) );
			} else {
				// Non esisteva — crea
				$diabetes_data['dive_id'] = $dive_id;
				$diabetes_data['user_id'] = $user_id;
				$wpdb->insert( $db->table( 'dive_diabetes' ), $diabetes_data );
				$changes[] = array(
					'dive_id'    => $dive_id,
					'user_id'    => $user_id,
					'edit_type'  => 'create',
					'table_name' => 'dive_diabetes',
					'field_name' => '_all',
					'old_value'  => null,
					'new_value'  => 'Dati diabete aggiunti',
				);
			}
		}

		// ── Salva storico modifiche ──
		if ( ! empty( $changes ) ) {
			$table_edits = $db->table( 'dive_edits' );
			foreach ( $changes as $c ) {
				$wpdb->insert( $table_edits, $c );
			}
		}

		wp_send_json_success(
			array(
				'message'       => __( 'Immersione aggiornata con successo!', 'sd-logbook' ),
				'changes_count' => count( $changes ),
			)
		);
	}

	// ================================================================
	// AJAX: Storico modifiche
	// ================================================================
	public function get_dive_history() {
		check_ajax_referer( 'sd_dive_edit_nonce', 'nonce' );
		$dive_id = absint( $_POST['dive_id'] ?? 0 );
		$user_id = get_current_user_id();
		if ( ! $dive_id ) {
			wp_send_json_error();
		}

		global $wpdb;
		$db = new SD_Database();

		// Verifica proprietà
		$dive = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$db->table('dives')} WHERE id = %d AND user_id = %d",
				$dive_id,
				$user_id
			)
		);
		if ( ! $dive ) {
			wp_send_json_error( array( 'message' => 'Non autorizzato' ) );
		}

		$history = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT de.*, u.display_name
			 FROM {$db->table('dive_edits')} de
			 LEFT JOIN {$wpdb->users} u ON de.user_id = u.ID
			 WHERE de.dive_id = %d
			 ORDER BY de.created_at DESC
			 LIMIT 100",
				$dive_id
			),
			ARRAY_A
		);

		wp_send_json_success( array( 'history' => $history ) );
	}

	// ================================================================
	// Helper: confronto normalizzato tra vecchio e nuovo valore
	// Gestisce: null vs '', '18.0' vs '18', '0' vs 0, ecc.
	// ================================================================
	private function values_equal( $old, $new, $field = '', $int_fields = array(), $float_fields = array() ) {
		// Entrambi null o vuoti → uguali
		if ( ( null === $old || '' === $old ) && ( null === $new || '' === $new ) ) {
			return true;
		}
		// Solo uno null → diversi
		if ( ( null === $old || '' === $old ) !== ( null === $new || '' === $new ) ) {
			return false;
		}

		// Confronto numerico per campi numerici
		if ( is_numeric( $old ) && is_numeric( $new ) ) {
			return abs( floatval( $old ) - floatval( $new ) ) < 0.01;
		}

		// Confronto stringa
		return (string) $old === (string) $new;
	}
}
