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

	public function __construct() {
		add_shortcode( 'sd_medical_panel', array( $this, 'render_panel' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sd_medical_get_diver', array( $this, 'get_diver_data' ) );
		add_action( 'wp_ajax_sd_medical_save_note', array( $this, 'save_supervision_note' ) );
		add_action( 'wp_ajax_sd_medical_export', array( $this, 'export_research_data' ) );
	}

	public function enqueue_assets() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'sd_medical_panel' ) ) {
			wp_enqueue_style( 'sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION );
			wp_enqueue_style( 'sd-dashboard', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dashboard.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
			wp_enqueue_style( 'sd-medical', SD_LOGBOOK_PLUGIN_URL . 'assets/css/medical-panel.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
			wp_enqueue_script( 'sd-medical', SD_LOGBOOK_PLUGIN_URL . 'assets/js/medical-panel.js', array( 'jquery' ), SD_LOGBOOK_VERSION, true );
			wp_localize_script(
				'sd-medical',
				'sdMedical',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'sd_medical_nonce' ),
				)
			);
		}
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

		if ( ! SD_Roles::can_view_all( get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato', 'sd-logbook' ) ) );
		}

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
		}

		// Immersioni con dati diabete
		$dives = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*, dd.glic_60_value, dd.glic_60_method, dd.glic_60_trend,
					dd.glic_30_value, dd.glic_30_method, dd.glic_30_trend,
					dd.glic_10_value, dd.glic_10_method, dd.glic_10_trend,
					dd.glic_post_value, dd.glic_post_method, dd.glic_post_trend,
					dd.dive_decision, dd.dive_decision_reason
			 FROM {$db->table('dives')} d
			 LEFT JOIN {$db->table('dive_diabetes')} dd ON d.id = dd.dive_id
			 WHERE d.user_id = %d AND d.shared_for_research = 1
			 ORDER BY d.dive_date DESC, d.time_in DESC
			 LIMIT 50",
				$diver_id
			)
		);

		// Note supervisione per questo sub
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
			 LIMIT 30",
				$diver_id
			)
		);

		foreach ( $notes as &$note ) {
			$note->supervisor = trim( $note->sup_first . ' ' . $note->sup_last ) ?: $note->supervisor_name;
		}

		// Certificazioni e clearances
		$certs      = get_user_meta( $diver_id, 'sd_certifications', true ) ?: array();
		$clearances = get_user_meta( $diver_id, 'sd_medical_clearances', true ) ?: array();

		wp_send_json_success(
			array(
				'name'        => $name,
				'is_diabetic' => $is_diabetic,
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
		if ( ! SD_Roles::can_supervise( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato a supervisionare', 'sd-logbook' ) ) );
		}

		$diver_id = absint( $_POST['diver_id'] ?? 0 );
		$dive_id  = absint( $_POST['dive_id'] ?? 0 );
		$type     = sanitize_text_field( $_POST['note_type'] ?? 'note' );
		$status   = sanitize_text_field( $_POST['note_status'] ?? 'in_revisione' );
		$text     = sanitize_textarea_field( $_POST['note_text'] ?? '' );

		if ( ! $diver_id || empty( $text ) ) {
			wp_send_json_error( array( 'message' => __( 'Dati incompleti', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db = new SD_Database();

		$wpdb->insert(
			$db->table( 'medical_supervision' ),
			array(
				'dive_id'            => $dive_id ?: null,
				'diver_user_id'      => $diver_id,
				'supervisor_user_id' => $user_id,
				'supervision_type'   => $type,
				'status'             => $status,
				'notes'              => $text,
			)
		);

		$current_user = wp_get_current_user();
		$sup_name     = trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name;

		wp_send_json_success(
			array(
				'message'    => __( 'Nota salvata.', 'sd-logbook' ),
				'supervisor' => $sup_name,
				'date'       => date_i18n( 'd/m/Y H:i' ),
			)
		);
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
