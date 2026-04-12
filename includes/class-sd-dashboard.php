<?php
/**
 * Dashboard Riepilogo Immersioni
 *
 * Shortcode [sd_dashboard] per visualizzare:
 * - Statistiche riassuntive
 * - Lista immersioni con filtri
 * - Grafico glicemie per ogni immersione (diabetici)
 * - Export CSV/Excel
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Dashboard {

	public function __construct() {
		add_shortcode( 'sd_dashboard', array( $this, 'render_dashboard' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sd_get_dive_detail', array( $this, 'get_dive_detail' ) );
		add_action( 'wp_ajax_sd_export_csv', array( $this, 'export_csv' ) );
		add_action( 'wp_ajax_sd_delete_dive', array( $this, 'delete_dive' ) );
	}

	/**
	 * Carica assets
	 */
	public function enqueue_assets() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'sd_dashboard' ) ) {
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
			wp_enqueue_style(
				'sd-dashboard',
				SD_LOGBOOK_PLUGIN_URL . 'assets/css/dashboard.css',
				array( 'sd-logbook-form' ),
				SD_LOGBOOK_VERSION
			);
			// Leaflet maps
			wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
			wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );

			wp_enqueue_style( 'sd-dive-edit', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-edit.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );

			wp_enqueue_script(
				'sd-dashboard',
				SD_LOGBOOK_PLUGIN_URL . 'assets/js/dashboard.js',
				array( 'jquery', 'leaflet' ),
				SD_LOGBOOK_VERSION,
				true
			);
			wp_enqueue_script(
				'sd-dive-edit',
				SD_LOGBOOK_PLUGIN_URL . 'assets/js/dive-edit.js',
				array( 'jquery', 'sd-dashboard' ),
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
				'sd-dashboard',
				'sdDashboard',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( 'sd_dashboard_nonce' ),
					'glycemiaUnit' => $glycemia_unit,
				)
			);
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
	}

	/**
	 * Render dashboard
	 */
	public function render_dashboard( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="sd-notice sd-notice-warning">'
				. __( 'Devi effettuare il login per vedere la dashboard.', 'sd-logbook' )
				. ' <a href="' . SD_Logbook::get_login_url() . '">' . __( 'Accedi', 'sd-logbook' ) . '</a>'
				. '</div>';
		}

		if ( ! current_user_can( 'sd_view_own_dives' ) && ! current_user_can( 'sd_view_all_dives' ) ) {
			return '<div class="sd-notice sd-notice-error">'
				. __( 'Non hai i permessi per accedere alla dashboard.', 'sd-logbook' )
				. '</div>';
		}

		$user_id      = get_current_user_id();
		$is_diabetic  = SD_Roles::is_diabetic_diver( $user_id );
		$can_view_all = false; // La dashboard personale mostra SEMPRE solo le proprie immersioni.
		                       // Il pannello medico/ricerca ha la sua pagina dedicata per vedere tutti i dati.

		// Fetch dives — solo immersioni dell'utente corrente
		global $wpdb;
		$db = new SD_Database();

		$dives = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*, %s as diver_name
			 FROM {$db->table('dives')} d
			 WHERE d.user_id = %d
			 ORDER BY d.dive_date DESC, d.time_in DESC
			 LIMIT 200",
				wp_get_current_user()->display_name,
				$user_id
			)
		);

		// Stats — sempre solo le proprie immersioni
		$stats = $this->get_stats( $user_id, false, $db );

		// User info
		$current_user = wp_get_current_user();
		$display_name = trim( $current_user->first_name . ' ' . $current_user->last_name );
		if ( empty( $display_name ) ) {
			$display_name = $current_user->display_name;
		}

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/dashboard.php';
		return ob_get_clean();
	}

	/**
	 * Statistiche
	 */
	private function get_stats( $user_id, $can_view_all, $db ) {
		global $wpdb;
		if ( $can_view_all ) {
			$where = $wpdb->prepare( '(shared_for_research = 1 OR user_id = %d)', $user_id );
		} else {
			$where = $wpdb->prepare( 'user_id = %d', $user_id );
		}

		$stats                 = new stdClass();
		$stats->total_dives    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->table('dives')} WHERE {$where}" );
		$stats->max_depth         = $wpdb->get_var( "SELECT MAX(max_depth) FROM {$db->table('dives')} WHERE {$where}" );
		$stats->max_depth_dive_id = (int) $wpdb->get_var( "SELECT id FROM {$db->table('dives')} WHERE {$where} ORDER BY max_depth DESC LIMIT 1" );
		$stats->total_time     = (int) $wpdb->get_var( "SELECT SUM(dive_time) FROM {$db->table('dives')} WHERE {$where}" );
		$stats->unique_sites   = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT site_name) FROM {$db->table('dives')} WHERE {$where}" );
		$stats->last_dive_date = $wpdb->get_var( "SELECT MAX(dive_date) FROM {$db->table('dives')} WHERE {$where}" );

		return $stats;
	}

	/**
	 * Dettaglio immersione via AJAX (per modale)
	 */
	public function get_dive_detail() {
		check_ajax_referer( 'sd_dashboard_nonce', 'nonce' );

		$dive_id = absint( $_POST['dive_id'] ?? 0 );
		if ( ! $dive_id ) {
			wp_send_json_error();
		}

		global $wpdb;
		$db      = new SD_Database();
		$user_id = get_current_user_id();

		// Get dive — solo immersioni dell'utente corrente
		$dive = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('dives')} WHERE id = %d AND user_id = %d",
				$dive_id,
				$user_id
			)
		);

		if ( ! $dive ) {
			wp_send_json_error( array( 'message' => 'Immersione non trovata' ) );
		}

		// Get diabetes data if exists
		$diabetes = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('dive_diabetes')} WHERE dive_id = %d",
				$dive_id
			)
		);

		wp_send_json_success(
			array(
				'dive'     => $dive,
				'diabetes' => $diabetes,
			)
		);
	}

	/**
	 * Export CSV
	 */
	public function export_csv() {
		check_ajax_referer( 'sd_dashboard_nonce', 'nonce' );

		$user_id        = get_current_user_id();
		$can_export_all = SD_Roles::can_export_all( $user_id );

		global $wpdb;
		$db = new SD_Database();

		// Query
		if ( $can_export_all ) {
			$dives = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT d.*, dd.*, u.display_name as diver_name
				 FROM {$db->table('dives')} d
				 LEFT JOIN {$db->table('dive_diabetes')} dd ON d.id = dd.dive_id
				 LEFT JOIN {$wpdb->users} u ON d.user_id = u.ID
				 WHERE d.shared_for_research = 1 OR d.user_id = %d
				 ORDER BY d.dive_date DESC",
					$user_id
				),
				ARRAY_A
			);
		} else {
			$dives = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT d.*, dd.*
				 FROM {$db->table('dives')} d
				 LEFT JOIN {$db->table('dive_diabetes')} dd ON d.id = dd.dive_id
				 WHERE d.user_id = %d
				 ORDER BY d.dive_date DESC",
					$user_id
				),
				ARRAY_A
			);
		}

		if ( empty( $dives ) ) {
			wp_send_json_error( array( 'message' => 'Nessun dato da esportare' ) );
		}

		// Generate CSV
		$filename   = 'scubadiabetes-export-' . gmdate( 'Y-m-d-His' ) . '.csv';
		$upload_dir = wp_upload_dir();
		$filepath   = $upload_dir['basedir'] . '/' . $filename;

		$fp = fopen( $filepath, 'w' );

		// BOM for Excel UTF-8
		fwrite( $fp, "\xEF\xBB\xBF" );

		// Headers
		if ( ! empty( $dives ) ) {
			fputcsv( $fp, array_keys( $dives[0] ), ';' );
		}

		foreach ( $dives as $row ) {
			fputcsv( $fp, $row, ';' );
		}

		fclose( $fp );

		$file_url = $upload_dir['baseurl'] . '/' . $filename;

		wp_send_json_success(
			array(
				'url'      => $file_url,
				'filename' => $filename,
			)
		);
	}

	/**
	 * Elimina immersione
	 */
	public function delete_dive() {
		check_ajax_referer( 'sd_dashboard_nonce', 'nonce' );

		$dive_id = absint( $_POST['dive_id'] ?? 0 );
		$user_id = get_current_user_id();

		if ( ! $dive_id ) {
			wp_send_json_error();
		}

		global $wpdb;
		$db = new SD_Database();

		// Solo proprie immersioni
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

		// Elimina dati diabete collegati
		$wpdb->delete( $db->table( 'dive_diabetes' ), array( 'dive_id' => $dive_id ) );
		// Elimina supervisione
		$wpdb->delete( $db->table( 'medical_supervision' ), array( 'dive_id' => $dive_id ) );
		// Elimina nutrizione
		$wpdb->delete( $db->table( 'nutrition_log' ), array( 'dive_id' => $dive_id ) );
		// Elimina immersione
		$wpdb->delete( $db->table( 'dives' ), array( 'id' => $dive_id ) );

		wp_send_json_success( array( 'message' => 'Immersione eliminata' ) );
	}
}
