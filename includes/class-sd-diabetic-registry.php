<?php
/**
 * Registro Soci Diabetici
 *
 * Shortcode: [sd_diabetic_registry]
 * Accessibile SOLO a ruoli sd_medical, sd_staff, administrator.
 *
 * Mostra tutti i soci diabetici con il profilo diabetologico completo.
 * Supporta filtri per: nome/cognome, tipo diabete, terapia, CGM, tipo socio, anno.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Diabetic_Registry {

	/**
	 * Cache locale colonne tabella.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private static $table_columns_cache = array();

	public function __construct() {
		add_shortcode( 'sd_diabetic_registry', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sd_diabetic_registry_data', array( $this, 'ajax_get_data' ) );
	}

	public function enqueue_assets() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'sd_diabetic_registry' ) ) {
			return;
		}
		wp_enqueue_style( 'sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-diabetic-registry', SD_LOGBOOK_PLUGIN_URL . 'assets/css/diabetic-registry.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
		wp_enqueue_script( 'sd-diabetic-registry', SD_LOGBOOK_PLUGIN_URL . 'assets/js/diabetic-registry.js', array( 'jquery' ), SD_LOGBOOK_VERSION, true );
		wp_localize_script(
			'sd-diabetic-registry',
			'sdDiabeticRegistry',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sd_diabetic_registry_nonce' ),
			)
		);
	}

	public function render() {
		if ( ! is_user_logged_in() ) {
			return '<div class="sd-notice sd-notice-warning">' . __( 'Devi effettuare il login.', 'sd-logbook' ) . '</div>';
		}
		if ( ! SD_Roles::can_view_all( get_current_user_id() ) ) {
			return '<div class="sd-notice sd-notice-error">' . __( 'Accesso riservato a medici e staff.', 'sd-logbook' ) . '</div>';
		}

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/diabetic-registry.php';
		return ob_get_clean();
	}

	/**
	 * AJAX: restituisce i soci diabetici filtrati.
	 * Parametri POST: search, diabetes_type, therapy_type, uses_cgm, member_type, year
	 */
	public function ajax_get_data() {
		check_ajax_referer( 'sd_diabetic_registry_nonce', 'nonce' );

		if ( ! SD_Roles::can_view_all( get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db = new SD_Database();
		$dp_columns = self::get_table_columns( $db->table( 'diver_profiles' ) );

		$search       = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$diab_type    = sanitize_text_field( wp_unslash( $_POST['diabetes_type'] ?? '' ) );
		$therapy_type = sanitize_text_field( wp_unslash( $_POST['therapy_type'] ?? '' ) );
		$uses_cgm     = isset( $_POST['uses_cgm'] ) && '' !== $_POST['uses_cgm'] ? absint( $_POST['uses_cgm'] ) : null;
		$member_type  = sanitize_text_field( wp_unslash( $_POST['member_type'] ?? '' ) );
		$year         = absint( $_POST['year'] ?? 0 );

		$cap_key             = $wpdb->prefix . 'capabilities';
		$dp_is_diabetic_expr = isset( $dp_columns['is_diabetic'] ) ? 'COALESCE(dp.is_diabetic, 0)' : '0';
		$where   = array(
			'm.is_active = 1',
			$wpdb->prepare(
				"(
					{$dp_is_diabetic_expr} = 1
					OR ( m.diabetes_type IS NOT NULL AND m.diabetes_type NOT IN ('non_diabetico', '') )
					OR EXISTS (
						SELECT 1
						FROM {$wpdb->usermeta} um
						WHERE um.user_id = m.wp_user_id
						  AND um.meta_key = %s
						  AND um.meta_value LIKE %s
					)
				)",
				$cap_key,
				'%sd_diver_diabetic%'
			),
		);
		$params = array();

		if ( ! empty( $search ) ) {
			$like        = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]     = '(m.first_name LIKE %s OR m.last_name LIKE %s OR m.email LIKE %s)';
			$params[]    = $like;
			$params[]    = $like;
			$params[]    = $like;
		}

		if ( ! empty( $diab_type ) ) {
			if ( isset( $dp_columns['diabetes_type'] ) ) {
				$diab_expr = "REPLACE(COALESCE(dp.diabetes_type, m.diabetes_type), 'tipo1', 'tipo_1')";
			} else {
				$diab_expr = "REPLACE(m.diabetes_type, 'tipo1', 'tipo_1')";
			}
			$where[]  = $diab_expr . ' = %s';
			$params[] = $diab_type;
		}

		if ( ! empty( $therapy_type ) && isset( $dp_columns['therapy_type'] ) ) {
			$where[]  = 'dp.therapy_type = %s';
			$params[] = $therapy_type;
		}

		if ( null !== $uses_cgm && isset( $dp_columns['uses_cgm'] ) ) {
			$where[]  = 'dp.uses_cgm = %d';
			$params[] = $uses_cgm;
		}

		if ( ! empty( $member_type ) ) {
			$where[]  = 'm.member_type = %s';
			$params[] = $member_type;
		}

		if ( $year > 0 ) {
			$where[]  = 'YEAR(m.member_since) = %d';
			$params[] = $year;
		}

		$where_sql = implode( ' AND ', $where );

		// Costruisci l'espressione per diabetes_type con COALESCE e normalizzazione
		if ( isset( $dp_columns['diabetes_type'] ) ) {
			$diabetes_type_expr = "REPLACE(COALESCE(dp.diabetes_type, m.diabetes_type), 'tipo1', 'tipo_1') AS diabetes_type";
		} else {
			$diabetes_type_expr = "REPLACE(m.diabetes_type, 'tipo1', 'tipo_1') AS diabetes_type";
		}

		$sql = "SELECT
			m.id           AS member_id,
			m.first_name,
			m.last_name,
			m.email,
			m.gender,
			m.date_of_birth,
			m.member_type,
			m.membership_type,
			m.member_since,
			m.membership_expiry,
			m.is_scuba,
			m.diabetology_center  AS member_center,
			" . $diabetes_type_expr . ",
			" . self::dp_col_select_expr( $dp_columns, 'diabetology_center', 'profile_center' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'therapy_type' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'therapy_detail' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'therapy_detail_other' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'hba1c_last' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'hba1c_unit' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'hba1c_date' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'uses_cgm' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'cgm_device' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'insulin_pump_model' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'insulin_pump_model_other' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'glycemia_unit' ) . ",
			" . self::dp_col_select_expr( $dp_columns, 'notes' ) . "
		FROM {$db->table('members')} m
		LEFT JOIN {$db->table('diver_profiles')} dp ON dp.user_id = m.wp_user_id
		WHERE {$where_sql}
		ORDER BY m.last_name ASC, m.first_name ASC
		LIMIT 500";

		$rows = empty( $params )
			? $wpdb->get_results( $sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		wp_send_json_success( array( 'rows' => $rows ?: array() ) );
	}

	/**
	 * Restituisce gli anni disponibili (per il filtro anno).
	 */
	public static function get_years() {
		global $wpdb;
		$db             = new SD_Database();
		$dp_columns     = self::get_table_columns( $db->table( 'diver_profiles' ) );
		$cap_key        = $wpdb->prefix . 'capabilities';
		$dp_is_diab_sql = isset( $dp_columns['is_diabetic'] ) ? 'COALESCE(dp.is_diabetic, 0)' : '0';
		$sql     = $wpdb->prepare(
			"SELECT DISTINCT YEAR(m.member_since)
			 FROM {$db->table('members')} m
			 LEFT JOIN {$db->table('diver_profiles')} dp ON dp.user_id = m.wp_user_id
			 WHERE m.is_active = 1
			   AND (
				   {$dp_is_diab_sql} = 1
				   OR ( m.diabetes_type IS NOT NULL AND m.diabetes_type NOT IN ('non_diabetico', '') )
				   OR EXISTS (
					   SELECT 1
					   FROM {$wpdb->usermeta} um
					   WHERE um.user_id = m.wp_user_id
						 AND um.meta_key = %s
						 AND um.meta_value LIKE %s
				   )
			   )
			 ORDER BY 1 DESC",
			$cap_key,
			'%sd_diver_diabetic%'
		);
		$years = $wpdb->get_col( $sql );
		return array_filter( $years );
	}

	/**
	 * Restituisce le colonne di una tabella come mappa [colonna => true].
	 *
	 * @param string $table_name Nome tabella completo.
	 * @return array<string,bool>
	 */
	private static function get_table_columns( $table_name ) {
		if ( isset( self::$table_columns_cache[ $table_name ] ) ) {
			return self::$table_columns_cache[ $table_name ];
		}

		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table_name, 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$map     = array();
		foreach ( (array) $columns as $col ) {
			$map[ (string) $col ] = true;
		}

		self::$table_columns_cache[ $table_name ] = $map;
		return $map;
	}

	/**
	 * Costruisce una select sicura per colonne opzionali di diver_profiles.
	 *
	 * @param array<string,bool> $dp_columns Mappa colonne presenti.
	 * @param string             $column     Nome colonna.
	 * @param string|null        $alias      Alias opzionale.
	 * @return string
	 */
	private static function dp_col_select_expr( $dp_columns, $column, $alias = null ) {
		$alias = $alias ? $alias : $column;
		if ( isset( $dp_columns[ $column ] ) ) {
			return 'dp.' . $column . ' AS ' . $alias;
		}
		return 'NULL AS ' . $alias;
	}
}
