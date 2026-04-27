<?php
/**
 * Pannello Medico / Staff
 *
 * Shortcode [sd_medical_panel]
 * Accessibile SOLO a ruoli sd_medical, sd_staff, administrator
 *
 * Funzionalità:
 * - Lista subacquei (filtro per diabetici)
 * - Dettaglio immersioni di un subacqueo con dati glicemici
 * - Aggiunta note di supervisione medica
 * - Export dati per ricerca
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Medical_Panel {

	/**
	 * Accesso gestione supervisione: medico SD o amministratore WP.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function can_manage_supervision( $user_id ) {
		return SD_Roles::is_medical( $user_id ) || user_can( $user_id, 'manage_options' );
	}

	public function __construct() {
		add_shortcode( 'sd_medical_panel', array( $this, 'render_panel' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sd_medical_get_diver', array( $this, 'get_diver_data' ) );
		add_action( 'wp_ajax_sd_medical_save_note', array( $this, 'save_supervision_note' ) );
		add_action( 'wp_ajax_sd_medical_delete_note', array( $this, 'delete_supervision_note' ) );
		add_action( 'wp_ajax_sd_medical_export', array( $this, 'export_research_data' ) );
	}

	public function enqueue_assets() {
		$medical_js_path = SD_LOGBOOK_PLUGIN_DIR . 'assets/js/medical-panel.js';
		$medical_js_ver  = file_exists( $medical_js_path ) ? (string) filemtime( $medical_js_path ) : SD_LOGBOOK_VERSION;

		wp_enqueue_style( 'sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-dashboard', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dashboard.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-medical', SD_LOGBOOK_PLUGIN_URL . 'assets/css/medical-panel.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-profile', SD_LOGBOOK_PLUGIN_URL . 'assets/css/profile.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css', array(), '1.9.4' );
		wp_enqueue_script( 'leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js', array(), '1.9.4', true );
		wp_enqueue_script( 'sd-medical', SD_LOGBOOK_PLUGIN_URL . 'assets/js/medical-panel.js', array( 'jquery', 'leaflet' ), $medical_js_ver, true );
		wp_localize_script(
			'sd-medical',
			'sdMedical',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sd_medical_nonce' ),
			)
		);
	}

	public function render_panel( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="sd-notice sd-notice-warning">'
				. __( 'Devi effettuare il login per vedere il pannello medico.', 'sd-logbook' )
				. ' <a href="' . SD_Logbook::get_login_url() . '">' . __( 'Accedi', 'sd-logbook' ) . '</a>'
				. '</div>';
		}

		$user_id = get_current_user_id();
		if ( ! SD_Roles::can_view_all( $user_id ) ) {
			return '<div class="sd-notice sd-notice-error">' . __( 'Accesso riservato a medici e staff.', 'sd-logbook' ) . '</div>';
		}

		$is_medical = SD_Roles::is_medical( $user_id );

		// Fetch all divers
		global $wpdb;
		$db = new SD_Database();

		// Fetch all users who have diver roles (including medical/staff who also dive)
		$diver_users = get_users(
			array(
				'role__in' => array( 'sd_diver_diabetic', 'sd_diver' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		// Also fetch medical/staff who have dives (they have multiple roles)
		$med_staff_users = get_users(
			array(
				'role__in' => array( 'sd_medical', 'sd_staff' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		// Merge and deduplicate
		$all_users = array();
		$seen_ids  = array();
		foreach ( array_merge( $diver_users, $med_staff_users ) as $user ) {
			if ( ! in_array( $user->ID, $seen_ids ) ) {
				$seen_ids[]  = $user->ID;
				$all_users[] = $user;
			}
		}

		$divers = array();
		foreach ( $all_users as $user ) {
			$obj               = new stdClass();
			$obj->ID           = $user->ID;
			$obj->display_name = $user->display_name;
			$obj->first_name   = $user->first_name;
			$obj->last_name    = $user->last_name;
			$obj->is_diabetic  = in_array( 'sd_diver_diabetic', (array) $user->roles, true );
			$obj->full_name    = trim( $user->first_name . ' ' . $user->last_name );
			if ( empty( $obj->full_name ) ) {
				$obj->full_name = $user->display_name;
			}
			$obj->dive_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$db->table('dives')} WHERE user_id = %d AND shared_for_research = 1",
					$user->ID
				)
			);
			$obj->last_dive  = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(dive_date) FROM {$db->table('dives')} WHERE user_id = %d AND shared_for_research = 1",
					$user->ID
				)
			);
			$divers[]        = $obj;
		}

		// Stats
		$total_divers   = count(
			array_filter(
				$divers,
				function ( $d ) {
					return $d->dive_count > 0;
				}
			)
		);
		$diabetic_count = count(
			array_filter(
				$divers,
				function ( $d ) {
					return $d->is_diabetic && $d->dive_count > 0;
				}
			)
		);
		$total_dives    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->table('dives')} WHERE shared_for_research = 1" );

		// Current user info
		$current_user = wp_get_current_user();
		$display_name = trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name;

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/medical-panel.php';
		return ob_get_clean();
	}

	/**
	 * AJAX: dati di un subacqueo (immersioni + glicemie + note supervisione)
	 */
	public function get_diver_data() {
		check_ajax_referer( 'sd_medical_nonce', 'nonce' );

		$current_user_id = get_current_user_id();
		if ( ! SD_Roles::can_view_all( $current_user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato', 'sd-logbook' ) ) );
		}

		$can_access_supervision = self::can_manage_supervision( $current_user_id );

		$diver_id = absint( $_POST['diver_id'] ?? 0 );
		if ( ! $diver_id ) {
			wp_send_json_error();
		}

		global $wpdb;
		$db = new SD_Database();

		// Info subacqueo
		$user        = get_userdata( $diver_id );
		$name        = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
		$is_diabetic = in_array( 'sd_diver_diabetic', (array) $user->roles, true );

		// Profilo diabete
		$profile = null;
		if ( $is_diabetic ) {
			$profile = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$db->table('diver_profiles')} WHERE user_id = %d",
					$diver_id
				)
			);

			// Fallback da iscrizione: se il centro diabetologico e presente in sd_members
			// ma non in sd_diver_profiles, aggiungilo al payload del pannello medico.
			if ( ! $profile || empty( $profile->diabetology_center ) ) {
				$member_diabetology_center = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT diabetology_center FROM {$db->table('members')} WHERE wp_user_id = %d ORDER BY id DESC LIMIT 1",
						$diver_id
					)
				);
				if ( ! empty( $member_diabetology_center ) ) {
					if ( ! $profile ) {
						$profile = new stdClass();
					}
					$profile->diabetology_center = $member_diabetology_center;
				}
			}
		}

		// Immersioni con dati diabete
		$dives = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*, dd.*,
				d.id AS dive_id,
				d.id AS dive_base_id,
				d.user_id AS dive_user_id
			 FROM {$db->table('dives')} d
			 LEFT JOIN {$db->table('dive_diabetes')} dd ON d.id = dd.dive_id
			 WHERE d.user_id = %d AND d.shared_for_research = 1
			 ORDER BY d.dive_date DESC, d.time_in DESC
			 LIMIT 50",
				$diver_id
			)
		);

		$notes = array();
		if ( $can_access_supervision ) {
			// Note supervisione per questo sub, collegate alla singola immersione tramite dive_id.
			$notes = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ms.*, u.display_name as supervisor_name,
						um_fn.meta_value as sup_first, um_ln.meta_value as sup_last
					 FROM {$db->table('medical_supervision')} ms
					 LEFT JOIN {$wpdb->users} u ON ms.supervisor_user_id = u.ID
					 LEFT JOIN {$wpdb->usermeta} um_fn ON u.ID = um_fn.user_id AND um_fn.meta_key = 'first_name'
					 LEFT JOIN {$wpdb->usermeta} um_ln ON u.ID = um_ln.user_id AND um_ln.meta_key = 'last_name'
					 WHERE ms.diver_user_id = %d
					 ORDER BY ms.created_at DESC
					 LIMIT 50",
					$diver_id
				)
			);

			foreach ( $notes as &$note ) {
				$note->supervisor = trim( $note->sup_first . ' ' . $note->sup_last ) ?: $note->supervisor_name;
			}
		}

		// Certificazioni e clearances
		$certs      = get_user_meta( $diver_id, 'sd_certifications', true ) ?: array();
		$clearances = get_user_meta( $diver_id, 'sd_medical_clearances', true ) ?: array();

		$badges     = SD_Roles::get_role_badges( $diver_id );
		$role_label = ! empty( $badges ) ? $badges[0]['label'] : '';

		wp_send_json_success(
			array(
				'diver_id'    => $diver_id,
				'name'        => $name,
				'is_diabetic' => $is_diabetic,
				'can_access_supervision' => $can_access_supervision,
				'role_label'  => $role_label,
				'profile'     => $profile,
				'dives'       => $dives,
				'notes'       => $notes,
				'certs'       => $certs,
				'clearances'  => $clearances,
			)
		);
	}

	/**
	 * AJAX: salva nota supervisione medica
	 */
	public function save_supervision_note() {
		check_ajax_referer( 'sd_medical_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! self::can_manage_supervision( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Solo i medici possono gestire le note di supervisione', 'sd-logbook' ) ) );
		}

		$diver_id = absint( $_POST['diver_id'] ?? 0 );
		$dive_id  = absint( $_POST['dive_id'] ?? 0 );
		$review_id = absint( $_POST['review_id'] ?? 0 );
		$type     = sanitize_text_field( $_POST['note_type'] ?? 'note' );
		$status   = sanitize_text_field( $_POST['note_status'] ?? 'in_revisione' );
		$text     = sanitize_textarea_field( $_POST['note_text'] ?? '' );

		if ( ! $diver_id || ! $dive_id ) {
			wp_send_json_error( array( 'message' => __( 'Dati incompleti', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db = new SD_Database();
		$table = $db->table( 'medical_supervision' );

		$valid_dive = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$db->table('dives')} WHERE id = %d AND user_id = %d LIMIT 1",
				$dive_id,
				$diver_id
			)
		);
		if ( ! $valid_dive ) {
			wp_send_json_error( array( 'message' => __( 'Immersione non valida per il subacqueo selezionato', 'sd-logbook' ) ) );
		}

		$payload = array(
			'dive_id'            => $dive_id,
			'diver_user_id'      => $diver_id,
			'supervisor_user_id' => $user_id,
			'supervision_type'   => $type,
			'status'             => $status,
			'notes'              => $text,
		);

		if ( $review_id > 0 ) {
			$existing_review = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE id = %d AND diver_user_id = %d AND dive_id = %d LIMIT 1",
					$review_id,
					$diver_id,
					$dive_id
				)
			);

			if ( ! $existing_review ) {
				wp_send_json_error( array( 'message' => __( 'Revisione non trovata o non valida', 'sd-logbook' ) ) );
			}

			$payload['created_at'] = current_time( 'mysql' );
			$wpdb->update(
				$table,
				$payload,
				array( 'id' => $review_id )
			);
			$saved_review_id = $review_id;
		} else {
			$recent_duplicate_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id
					 FROM {$table}
					 WHERE dive_id = %d
					   AND diver_user_id = %d
					   AND supervisor_user_id = %d
					   AND supervision_type = %s
					   AND status = %s
					   AND notes = %s
					   AND created_at >= DATE_SUB(%s, INTERVAL 2 MINUTE)
					 ORDER BY created_at DESC, id DESC
					 LIMIT 1",
					$dive_id,
					$diver_id,
					$user_id,
					$type,
					$status,
					$text,
					current_time( 'mysql' )
				)
			);

			if ( $recent_duplicate_id > 0 ) {
				$saved_review_id = $recent_duplicate_id;
			} else {
				$wpdb->insert( $table, $payload );
				$saved_review_id = (int) $wpdb->insert_id;
			}
		}

		$current_user = wp_get_current_user();
		$sup_name     = trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name;

		$saved_review = null;
		if ( ! empty( $saved_review_id ) ) {
			$saved_review = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, dive_id, supervision_type, status, notes, created_at FROM {$table} WHERE id = %d LIMIT 1",
					$saved_review_id
				),
				ARRAY_A
			);
			if ( is_array( $saved_review ) ) {
				$saved_review['supervisor'] = $sup_name;
			}
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Nota salvata.', 'sd-logbook' ),
				'supervisor' => $sup_name,
				'date'       => date_i18n( 'd/m/Y H:i' ),
				'review'     => $saved_review,
			)
		);
	}

	/**
	 * AJAX: elimina revisione supervisione medica
	 */
	public function delete_supervision_note() {
		check_ajax_referer( 'sd_medical_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! self::can_manage_supervision( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Solo i medici possono eliminare le revisioni', 'sd-logbook' ) ) );
		}

		$review_id = absint( $_POST['review_id'] ?? 0 );
		$diver_id  = absint( $_POST['diver_id'] ?? 0 );
		$dive_id   = absint( $_POST['dive_id'] ?? 0 );

		if ( ! $review_id || ! $diver_id || ! $dive_id ) {
			wp_send_json_error( array( 'message' => __( 'Dati incompleti', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db    = new SD_Database();
		$table = $db->table( 'medical_supervision' );

		$valid_review = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE id = %d AND diver_user_id = %d AND dive_id = %d LIMIT 1",
				$review_id,
				$diver_id,
				$dive_id
			)
		);

		if ( ! $valid_review ) {
			wp_send_json_error( array( 'message' => __( 'Revisione non trovata o non valida', 'sd-logbook' ) ) );
		}

		$wpdb->delete(
			$table,
			array( 'id' => $review_id ),
			array( '%d' )
		);

		wp_send_json_success( array( 'message' => __( 'Revisione eliminata.', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX: export ricerca scientifica (tutti i dati diabetici)
	 */
	public function export_research_data() {
		check_ajax_referer( 'sd_medical_nonce', 'nonce' );

		if ( ! SD_Roles::can_export_all( get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db = new SD_Database();

		$rows = $wpdb->get_results(
			"SELECT u.display_name as diver,
					um_fn.meta_value as first_name, um_ln.meta_value as last_name,
					d.dive_number, d.dive_date, d.site_name,
					d.time_in, d.time_out, d.max_depth, d.avg_depth, d.dive_time,
					d.gas_mix, d.temp_water, d.sea_condition, d.current_strength,
					dd.*
			 FROM {$db->table('dives')} d
			 INNER JOIN {$db->table('dive_diabetes')} dd ON d.id = dd.dive_id
			 LEFT JOIN {$wpdb->users} u ON d.user_id = u.ID
			 LEFT JOIN {$wpdb->usermeta} um_fn ON u.ID = um_fn.user_id AND um_fn.meta_key = 'first_name'
			 LEFT JOIN {$wpdb->usermeta} um_ln ON u.ID = um_ln.user_id AND um_ln.meta_key = 'last_name'
			 WHERE d.shared_for_research = 1
			 ORDER BY d.dive_date DESC, u.display_name ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			wp_send_json_error( array( 'message' => __( 'Nessun dato diabetico da esportare', 'sd-logbook' ) ) );
		}

		$filename   = 'scubadiabetes-research-' . gmdate( 'Y-m-d' ) . '.csv';
		$upload_dir = wp_upload_dir();
		$filepath   = $upload_dir['basedir'] . '/' . $filename;

		$fp = fopen( $filepath, 'w' );
		fwrite( $fp, "\xEF\xBB\xBF" ); // BOM UTF-8

		// Intestazioni pulite
		$headers = array_keys( $rows[0] );
		fputcsv( $fp, $headers, ';' );

		foreach ( $rows as $row ) {
			fputcsv( $fp, $row, ';' );
		}
		fclose( $fp );

		wp_send_json_success(
			array(
				'url'      => $upload_dir['baseurl'] . '/' . $filename,
				'filename' => $filename,
				'count'    => count( $rows ),
			)
		);
	}
}
