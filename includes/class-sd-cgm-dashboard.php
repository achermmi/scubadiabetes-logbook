<?php
/**
 * Dashboard CGM Paziente + Pannello CGM Medico
 *
 * Shortcode [sd_cgm_dashboard] — Subacqueo diabetico: proprie letture CGM
 * Shortcode [sd_cgm_medical]   — Medico/Staff: letture di tutti i pazienti, con filtri
 *
 * Tutte le letture in DB sono in mg/dL (UTC). La conversione mmol/L avviene lato client.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_CGM_Dashboard {

	const PER_PAGE = 50;

	// ================================================================
	// COSTRUTTORE
	// ================================================================

	public function __construct() {
		add_shortcode( 'sd_cgm_dashboard', array( $this, 'render_patient' ) );
		add_shortcode( 'sd_cgm_medical',   array( $this, 'render_medical' ) );
		add_action( 'wp_enqueue_scripts',            array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sd_cgm_patient_fetch',  array( $this, 'ajax_patient_fetch' ) );
		add_action( 'wp_ajax_sd_cgm_medical_fetch',  array( $this, 'ajax_medical_fetch' ) );
	}

	// ================================================================
	// ASSETS
	// ================================================================

	public function enqueue_assets() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}
		$has_patient = has_shortcode( $post->post_content, 'sd_cgm_dashboard' );
		$has_medical = has_shortcode( $post->post_content, 'sd_cgm_medical' );
		if ( ! $has_patient && ! $has_medical ) {
			return;
		}

		$css_path = SD_LOGBOOK_PLUGIN_DIR . 'assets/css/cgm-dashboard.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : SD_LOGBOOK_VERSION;

		wp_enqueue_style( 'sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-dashboard',    SD_LOGBOOK_PLUGIN_URL . 'assets/css/dashboard.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-cgm',          SD_LOGBOOK_PLUGIN_URL . 'assets/css/cgm-dashboard.css', array( 'sd-logbook-form' ), $css_ver );

		$nonce = wp_create_nonce( 'sd_cgm_nonce' );

		if ( $has_patient ) {
			$js_path = SD_LOGBOOK_PLUGIN_DIR . 'assets/js/cgm-dashboard.js';
			$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : SD_LOGBOOK_VERSION;
			wp_enqueue_script( 'sd-cgm-dashboard', SD_LOGBOOK_PLUGIN_URL . 'assets/js/cgm-dashboard.js', array( 'jquery' ), $js_ver, true );
			wp_localize_script(
				'sd-cgm-dashboard',
				'sdCgmDash',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => $nonce,
					'unit'    => $this->get_glycemia_unit( get_current_user_id() ),
				)
			);
		}

		if ( $has_medical ) {
			$js_path = SD_LOGBOOK_PLUGIN_DIR . 'assets/js/cgm-medical.js';
			$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : SD_LOGBOOK_VERSION;
			wp_enqueue_script( 'sd-cgm-medical', SD_LOGBOOK_PLUGIN_URL . 'assets/js/cgm-medical.js', array( 'jquery' ), $js_ver, true );
			wp_localize_script(
				'sd-cgm-medical',
				'sdCgmMedical',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => $nonce,
				)
			);
		}
	}

	// ================================================================
	// SHORTCODE: PAZIENTE
	// ================================================================

	public function render_patient( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="sd-notice sd-notice-warning">'
				. __( 'Devi effettuare il login per vedere i tuoi dati CGM.', 'sd-logbook' )
				. '</div>';
		}
		$user_id = get_current_user_id();
		if ( ! SD_Roles::is_diabetic_diver( $user_id ) && ! current_user_can( 'manage_options' ) ) {
			return '<div class="sd-notice sd-notice-error">'
				. __( 'Sezione riservata ai subacquei diabetici.', 'sd-logbook' )
				. '</div>';
		}

		$glycemia_unit = $this->get_glycemia_unit( $user_id );
		$display_name  = wp_get_current_user()->display_name;
		$tz            = self::get_tz();
		$stats         = $this->compute_stats( $user_id );
		$device_name   = self::device_source( $stats->last_device );

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/cgm-dashboard.php';
		return ob_get_clean();
	}

	// ================================================================
	// SHORTCODE: MEDICO
	// ================================================================

	public function render_medical( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="sd-notice sd-notice-warning">'
				. __( 'Devi effettuare il login per vedere il pannello CGM.', 'sd-logbook' )
				. '</div>';
		}
		$user_id = get_current_user_id();
		if ( ! SD_Roles::can_view_all( $user_id ) ) {
			return '<div class="sd-notice sd-notice-error">'
				. __( 'Accesso riservato a medici e staff.', 'sd-logbook' )
				. '</div>';
		}

		$display_name = wp_get_current_user()->display_name;

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/cgm-medical.php';
		return ob_get_clean();
	}

	// ================================================================
	// AJAX: PAZIENTE
	// ================================================================

	public function ajax_patient_fetch() {
		if ( ! check_ajax_referer( 'sd_cgm_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta.', 'sd-logbook' ) ) );
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$page      = max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$period    = sanitize_text_field( wp_unslash( $_POST['period']    ?? '24h' ) );
		$date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );

		[ $from_utc, $to_utc ] = $this->period_to_utc( $period, $date_from, $date_to );

		global $wpdb;
		$t = $wpdb->prefix . 'sd_nightscout_readings';

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND reading_time BETWEEN %s AND %s",
			$user_id, $from_utc, $to_utc
		) );

		$offset = ( $page - 1 ) * self::PER_PAGE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT reading_time, glucose_value, direction, device
			 FROM {$t}
			 WHERE user_id = %d AND reading_time BETWEEN %s AND %s
			 ORDER BY reading_time DESC
			 LIMIT %d OFFSET %d",
			$user_id, $from_utc, $to_utc, self::PER_PAGE, $offset
		) );

		$tz        = self::get_tz();
		$formatted = array();
		foreach ( $rows as $r ) {
			$formatted[] = array(
				'time'      => $this->utc_to_local( $r->reading_time, $tz ),
				'value'     => (int) $r->glucose_value,
				'direction' => $r->direction ?: 'NONE',
				'source'    => self::device_source( $r->device ),
				'class'     => self::glucose_class( (int) $r->glucose_value ),
			);
		}

		// Dati grafico solo a pagina 1
		$chart_data = null;
		if ( 1 === $page ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$all = $wpdb->get_results( $wpdb->prepare(
				"SELECT reading_time, glucose_value FROM {$t}
				 WHERE user_id = %d AND reading_time BETWEEN %s AND %s
				 ORDER BY reading_time ASC",
				$user_id, $from_utc, $to_utc
			) );
			$chart_data = $this->thin_chart( $all, 300 );
		}

		wp_send_json_success( array(
			'rows'       => $formatted,
			'total'      => $total,
			'per_page'   => self::PER_PAGE,
			'chart_data' => $chart_data,
		) );
	}

	// ================================================================
	// AJAX: MEDICO
	// ================================================================

	public function ajax_medical_fetch() {
		if ( ! check_ajax_referer( 'sd_cgm_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta.', 'sd-logbook' ) ) );
		}
		$user_id = get_current_user_id();
		if ( ! SD_Roles::can_view_all( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Accesso non autorizzato.', 'sd-logbook' ) ) );
		}

		$page      = max( 1, (int) ( $_POST['page']      ?? 1 ) );
		$search    = sanitize_text_field( wp_unslash( $_POST['search']    ?? '' ) );
		$date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );
		$filter    = sanitize_text_field( wp_unslash( $_POST['filter']    ?? 'all' ) );
		$cgm_type  = sanitize_text_field( wp_unslash( $_POST['cgm_type']  ?? '' ) );

		global $wpdb;
		$t  = $wpdb->prefix . 'sd_nightscout_readings';
		$tu = $wpdb->users;

		$where  = array( '1=1' );
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $date_from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$where[]  = 'DATE(r.reading_time) >= %s';
			$params[] = $date_from;
		}
		if ( $date_to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$where[]  = 'DATE(r.reading_time) <= %s';
			$params[] = $date_to;
		}

		if ( 'anomalous' === $filter ) {
			$where[] = '(r.glucose_value < 70 OR r.glucose_value > 180)';
		} elseif ( 'normal' === $filter ) {
			$where[] = 'r.glucose_value BETWEEN 70 AND 180';
		} elseif ( 'low' === $filter ) {
			$where[] = 'r.glucose_value < 70';
		} elseif ( 'high' === $filter ) {
			$where[] = 'r.glucose_value > 180';
		}

		$allowed_cgm = array( 'CareLink', 'LibreView', 'Dexcom', 'Nightscout', 'Tidepool' );
		if ( $cgm_type && in_array( $cgm_type, $allowed_cgm, true ) ) {
			$where[]  = 'r.device LIKE %s';
			$params[] = $wpdb->esc_like( $cgm_type ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$sql_count = "SELECT COUNT(*) FROM {$t} r INNER JOIN {$tu} u ON u.ID = r.user_id WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $sql_count, $params ) )
			: (int) $wpdb->get_var( $sql_count ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$offset     = ( $page - 1 ) * self::PER_PAGE;
		$row_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$sql_rows = "SELECT r.reading_time, r.glucose_value, r.direction, r.device,
		                    u.ID as uid, u.display_name
		             FROM {$t} r
		             INNER JOIN {$tu} u ON u.ID = r.user_id
		             WHERE {$where_sql}
		             ORDER BY r.reading_time DESC
		             LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql_rows, $row_params ) );

		$tz        = self::get_tz();
		$formatted = array();
		foreach ( $rows as $r ) {
			$formatted[] = array(
				'uid'       => (int) $r->uid,
				'name'      => $r->display_name,
				'time'      => $this->utc_to_local( $r->reading_time, $tz ),
				'value'     => (int) $r->glucose_value,
				'direction' => $r->direction ?: 'NONE',
				'source'    => self::device_source( $r->device ),
				'class'     => self::glucose_class( (int) $r->glucose_value ),
			);
		}

		// Statistiche solo a pagina 1
		$stats = null;
		if ( 1 === $page ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$sql_users = "SELECT COUNT(DISTINCT r.user_id) FROM {$t} r INNER JOIN {$tu} u ON u.ID = r.user_id WHERE {$where_sql}";
			$anom_sql  = "SELECT COUNT(*) FROM {$t} r INNER JOIN {$tu} u ON u.ID = r.user_id WHERE {$where_sql} AND (r.glucose_value < 70 OR r.glucose_value > 180)";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$users_n = $params ? (int) $wpdb->get_var( $wpdb->prepare( $sql_users, $params ) ) : (int) $wpdb->get_var( $sql_users );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$anom_n  = $params ? (int) $wpdb->get_var( $wpdb->prepare( $anom_sql, $params ) )  : (int) $wpdb->get_var( $anom_sql );
			$pct     = $total > 0 ? (int) round( $anom_n / $total * 100 ) : 0;

			$stats = array(
				'total'    => $total,
				'users'    => $users_n,
				'anomalous'=> $anom_n,
				'pct_anom' => $pct,
			);
		}

		wp_send_json_success( array(
			'rows'     => $formatted,
			'total'    => $total,
			'per_page' => self::PER_PAGE,
			'stats'    => $stats,
		) );
	}

	// ================================================================
	// HELPERS — privati
	// ================================================================

	private function get_glycemia_unit( $user_id ) {
		global $wpdb;
		$db   = new SD_Database();
		$unit = $wpdb->get_var( $wpdb->prepare(
			"SELECT glycemia_unit FROM {$db->table('diver_profiles')} WHERE user_id = %d",
			$user_id
		) );
		return in_array( $unit, array( 'mg/dl', 'mmol/l' ), true ) ? $unit : 'mg/dl';
	}

	private function compute_stats( $user_id ) {
		global $wpdb;
		$t   = $wpdb->prefix . 'sd_nightscout_readings';
		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		$last = $wpdb->get_row( $wpdb->prepare(
			"SELECT glucose_value, direction, reading_time, device FROM {$t} WHERE user_id = %d ORDER BY reading_time DESC LIMIT 1",
			$user_id
		) );

		$from_24h = ( clone $now )->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
		$avg_24h  = $wpdb->get_var( $wpdb->prepare(
			"SELECT ROUND(AVG(glucose_value)) FROM {$t} WHERE user_id = %d AND reading_time >= %s",
			$user_id, $from_24h
		) );

		$from_7d    = ( clone $now )->modify( '-7 days' )->format( 'Y-m-d H:i:s' );
		$total_7d   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND reading_time >= %s",
			$user_id, $from_7d
		) );
		$inrange_7d = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND reading_time >= %s AND glucose_value BETWEEN 70 AND 180",
			$user_id, $from_7d
		) );
		$tir = $total_7d > 0 ? (int) round( $inrange_7d / $total_7d * 100 ) : null;

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d", $user_id ) );

		return (object) array(
			'last_value'     => $last ? (int) $last->glucose_value : null,
			'last_direction' => $last ? ( $last->direction ?: 'NONE' ) : 'NONE',
			'last_time'      => $last ? $last->reading_time : null,
			'last_device'    => $last ? ( $last->device ?: '' ) : '',
			'avg_24h'        => $avg_24h ? (int) $avg_24h : null,
			'tir_7d'         => $tir,
			'total'          => $total,
		);
	}

	private function period_to_utc( $period, $date_from, $date_to ) {
		$tz  = self::get_tz();
		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		if ( 'custom' === $period && $date_from && $date_to ) {
			$dt_from = \DateTime::createFromFormat( 'Y-m-d', $date_from, $tz );
			$dt_to   = \DateTime::createFromFormat( 'Y-m-d', $date_to, $tz );
			if ( $dt_from && $dt_to ) {
				$dt_from->setTime( 0, 0, 0 )->setTimezone( new \DateTimeZone( 'UTC' ) );
				$dt_to->setTime( 23, 59, 59 )->setTimezone( new \DateTimeZone( 'UTC' ) );
				return array( $dt_from->format( 'Y-m-d H:i:s' ), $dt_to->format( 'Y-m-d H:i:s' ) );
			}
		}

		$hours_map = array( '24h' => 24, '7d' => 168, '30d' => 720 );
		$hours     = $hours_map[ $period ] ?? 24;
		$from      = ( clone $now )->modify( "-{$hours} hours" )->format( 'Y-m-d H:i:s' );
		return array( $from, $now->format( 'Y-m-d H:i:s' ) );
	}

	private function thin_chart( $rows, $max ) {
		$count = count( $rows );
		if ( 0 === $count ) {
			return array();
		}
		if ( $count <= $max ) {
			return array_map( function ( $r ) {
				return array( (int) strtotime( $r->reading_time . ' UTC' ) * 1000, (int) $r->glucose_value );
			}, $rows );
		}
		$step   = $count / $max;
		$result = array();
		for ( $i = 0; $i < $max; $i++ ) {
			$idx      = min( (int) round( $i * $step ), $count - 1 );
			$r        = $rows[ $idx ];
			$result[] = array( (int) strtotime( $r->reading_time . ' UTC' ) * 1000, (int) $r->glucose_value );
		}
		return $result;
	}

	private function utc_to_local( $utc_str, $tz ) {
		$dt = new \DateTime( $utc_str, new \DateTimeZone( 'UTC' ) );
		$dt->setTimezone( $tz );
		return $dt->format( 'd/m/Y H:i' );
	}

	// ================================================================
	// HELPERS — public static (usati anche dai template)
	// ================================================================

	public static function glucose_class( $val ) {
		if ( $val < 54 )   return 'sd-gluc-very-low';
		if ( $val < 70 )   return 'sd-gluc-low';
		if ( $val <= 180 ) return 'sd-gluc-normal';
		if ( $val <= 250 ) return 'sd-gluc-high';
		return 'sd-gluc-very-high';
	}

	public static function glucose_label( $val ) {
		if ( $val === null ) return '—';
		if ( $val < 54 )   return __( 'Ipoglicemia grave', 'sd-logbook' );
		if ( $val < 70 )   return __( 'Ipoglicemia', 'sd-logbook' );
		if ( $val <= 180 ) return __( 'Nella norma', 'sd-logbook' );
		if ( $val <= 250 ) return __( 'Alta', 'sd-logbook' );
		return __( 'Molto alta', 'sd-logbook' );
	}

	public static function direction_arrow( $dir ) {
		$map = array(
			'TripleUp'      => '↑↑↑',
			'DoubleUp'      => '↑↑',
			'SingleUp'      => '↑',
			'FortyFiveUp'   => '↗',
			'Flat'          => '→',
			'FortyFiveDown' => '↘',
			'SingleDown'    => '↓',
			'DoubleDown'    => '↓↓',
			'TripleDown'    => '↓↓↓',
			'NONE'          => '—',
		);
		return $map[ $dir ] ?? '—';
	}

	public static function device_source( $device ) {
		if ( ! $device ) return '';
		foreach ( array( 'CareLink', 'LibreView', 'Dexcom', 'Nightscout', 'Tidepool' ) as $src ) {
			if ( str_starts_with( $device, $src ) ) return $src;
		}
		return $device;
	}

	public static function format_glucose( $val, $unit ) {
		if ( $val === null ) return '—';
		if ( 'mmol/l' === $unit ) {
			return number_format( $val / 18, 1 );
		}
		return (string) (int) $val;
	}

	// ================================================================
	// HELPERS — private static
	// ================================================================

	private static function get_tz() {
		$tz_string = get_option( 'timezone_string' );
		if ( $tz_string ) {
			return new \DateTimeZone( $tz_string );
		}
		$offset = (float) get_option( 'gmt_offset', 0 );
		$sign   = $offset >= 0 ? '+' : '-';
		$abs    = abs( $offset );
		$h      = (int) $abs;
		$m      = (int) round( ( $abs - $h ) * 60 );
		return new \DateTimeZone( sprintf( '%s%02d:%02d', $sign, $h, $m ) );
	}
}
