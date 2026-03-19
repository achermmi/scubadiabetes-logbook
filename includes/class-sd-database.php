<?php
/**
 * Gestione Database - Tabelle indipendenti da WordPress
 *
 * Struttura basata su:
 * - Logbook immersioni (dalle immagini LogBook.jpg e Logbook1.png)
 * - Foglio raccolta dati scientifici (FOGLIO_X_DATI_X_PAZ.pdf)
 * - Protocollo Diabete Sommerso (Protocollo-DS_Rev2018-.pdf)
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Database {

	/**
	 * Prefisso tabelle del plugin
	 */
	private $prefix;

	public function __construct() {
		global $wpdb;
		$this->prefix = $wpdb->prefix . 'sd_';
	}

	/**
	 * Restituisce il nome completo di una tabella
	 */
	public function table( $name ) {
		return $this->prefix . $name;
	}

	/**
	 * Crea tutte le tabelle del plugin
	 */
	public function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// =====================================================================
		// TABELLA 1: PROFILO SUBACQUEO (sd_diver_profiles)
		// Dati anagrafici e medici del subacqueo
		// =====================================================================
		$table_profiles = $this->table( 'diver_profiles' );
		$sql_profiles   = "CREATE TABLE {$table_profiles} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			is_diabetic tinyint(1) NOT NULL DEFAULT 0,
			diabetes_type varchar(10) DEFAULT 'none',
			therapy_type varchar(10) DEFAULT 'none',
			certification_level varchar(50) DEFAULT NULL,
			certification_agency varchar(50) DEFAULT NULL,
			certification_date date DEFAULT NULL,
			emergency_contact_name varchar(100) DEFAULT NULL,
			emergency_contact_phone varchar(30) DEFAULT NULL,
			emergency_contact_email varchar(100) DEFAULT NULL,
			emergency_contact_relationship varchar(50) DEFAULT NULL,
			medical_clearance_date date DEFAULT NULL,
			medical_clearance_expiry date DEFAULT NULL,
			gender varchar(2) DEFAULT NULL,
			gsm varchar(30) DEFAULT NULL,
			phone varchar(30) DEFAULT NULL,
			address varchar(255) DEFAULT NULL,
			zip varchar(20) DEFAULT NULL,
			city varchar(100) DEFAULT NULL,
			birth_date date DEFAULT NULL,
			weight decimal(5,1) DEFAULT NULL,
			height smallint(5) unsigned DEFAULT NULL,
			hba1c_last decimal(4,1) DEFAULT NULL,
			hba1c_date date DEFAULT NULL,
			uses_cgm tinyint(1) NOT NULL DEFAULT 0,
			cgm_device varchar(50) DEFAULT NULL,
			insulin_pump_model varchar(50) DEFAULT NULL,
			glycemia_unit varchar(10) NOT NULL DEFAULT 'mg/dl',
			default_shared_for_research tinyint(1) NOT NULL DEFAULT 1,
			blood_type varchar(10) DEFAULT NULL,
			allergies text DEFAULT NULL,
			medications text DEFAULT NULL,
			notes text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_user_id (user_id),
			KEY idx_is_diabetic (is_diabetic)
		) {$charset_collate};";
		dbDelta( $sql_profiles );

		// =====================================================================
		// TABELLA 2: IMMERSIONI (sd_dives)
		// Dati subacquei comuni a TUTTI i subacquei (diabetici e non)
		// Corrisponde alla parte sinistra del logbook
		// =====================================================================
		$table_dives = $this->table( 'dives' );
		$sql_dives   = "CREATE TABLE {$table_dives} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			dive_number int unsigned DEFAULT NULL,
			dive_date date NOT NULL,
			site_name varchar(200) NOT NULL,
			site_latitude decimal(10,7) DEFAULT NULL,
			site_longitude decimal(10,7) DEFAULT NULL,
			time_in time DEFAULT NULL,
			time_out time DEFAULT NULL,
			pressure_start smallint unsigned DEFAULT NULL,
			pressure_end smallint unsigned DEFAULT NULL,
			max_depth decimal(5,1) DEFAULT NULL,
			avg_depth decimal(5,1) DEFAULT NULL,
			dive_time smallint unsigned DEFAULT NULL,
			tank_count tinyint unsigned DEFAULT 1,
			tank_capacity decimal(4,1) DEFAULT NULL,
			gas_mix varchar(10) DEFAULT 'aria',
			nitrox_percentage decimal(4,1) DEFAULT NULL,
			safety_stop_depth decimal(4,1) DEFAULT NULL,
			safety_stop_time smallint unsigned DEFAULT NULL,
			deco_stop_depth decimal(4,1) DEFAULT NULL,
			deco_stop_time smallint unsigned DEFAULT NULL,
			deep_stop_depth decimal(4,1) DEFAULT NULL,
			deep_stop_time smallint unsigned DEFAULT NULL,
			ballast_kg decimal(4,1) DEFAULT NULL,
			entry_type varchar(10) DEFAULT NULL,
			dive_type varchar(15) DEFAULT NULL,
			weather varchar(15) DEFAULT NULL,
			temp_air decimal(4,1) DEFAULT NULL,
			temp_water decimal(4,1) DEFAULT NULL,
			sea_condition varchar(10) DEFAULT NULL,
			current_strength varchar(10) DEFAULT NULL,
			visibility varchar(10) DEFAULT NULL,
			suit_type varchar(15) DEFAULT NULL,
			sightings text DEFAULT NULL,
			other_equipment text DEFAULT NULL,
			notes text DEFAULT NULL,
			buddy_name varchar(100) DEFAULT NULL,
			guide_name varchar(100) DEFAULT NULL,
			shared_for_research tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user_id (user_id),
			KEY idx_dive_date (dive_date),
			KEY idx_site (site_name),
			KEY idx_user_date (user_id, dive_date),
			KEY idx_shared (shared_for_research)
		) {$charset_collate};";
		dbDelta( $sql_dives );

		// =====================================================================
		// TABELLA 3: DATI DIABETE PER IMMERSIONE (sd_dive_diabetes)
		// SOLO per subacquei diabetici
		//
		// Struttura da FOGLIO_X_DATI_X_PAZ.pdf:
		// Per ogni checkpoint (-60, -30, -10 min, post):
		// - Glic: UN SOLO valore glicemico (mg/dl)
		// - Metodo: C (capillare) OPPURE S (sensore CGM)
		// - Freccia sensore: trend CGM (solo se metodo = S)
		// - CHO rapidi (gr)
		// - CHO lenti (gr)
		// - INS (U) - insulina somministrata
		// - Note provvedimenti
		// =====================================================================
		$table_diabetes = $this->table( 'dive_diabetes' );
		$sql_diabetes   = "CREATE TABLE {$table_diabetes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dive_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			glic_60_value smallint unsigned DEFAULT NULL,
			glic_60_method varchar(1) DEFAULT NULL,
			glic_60_trend varchar(20) DEFAULT NULL,
			glic_60_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_60_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_60_insulin decimal(5,2) DEFAULT NULL,
			glic_60_notes varchar(255) DEFAULT NULL,
			glic_30_value smallint unsigned DEFAULT NULL,
			glic_30_method varchar(1) DEFAULT NULL,
			glic_30_trend varchar(20) DEFAULT NULL,
			glic_30_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_30_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_30_insulin decimal(5,2) DEFAULT NULL,
			glic_30_notes varchar(255) DEFAULT NULL,
			glic_10_value smallint unsigned DEFAULT NULL,
			glic_10_method varchar(1) DEFAULT NULL,
			glic_10_trend varchar(20) DEFAULT NULL,
			glic_10_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_10_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_10_insulin decimal(5,2) DEFAULT NULL,
			glic_10_notes varchar(255) DEFAULT NULL,
			glic_post_value smallint unsigned DEFAULT NULL,
			glic_post_method varchar(1) DEFAULT NULL,
			glic_post_trend varchar(20) DEFAULT NULL,
			glic_post_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_post_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_post_insulin decimal(5,2) DEFAULT NULL,
			glic_post_notes varchar(255) DEFAULT NULL,
			dive_decision varchar(15) DEFAULT NULL,
			dive_decision_reason varchar(255) DEFAULT NULL,
			ketone_checked tinyint(1) NOT NULL DEFAULT 0,
			ketone_value decimal(4,2) DEFAULT NULL,
			basal_insulin_reduced tinyint(1) DEFAULT NULL,
			basal_reduction_pct tinyint unsigned DEFAULT NULL,
			bolus_insulin_reduced tinyint(1) DEFAULT NULL,
			bolus_reduction_pct tinyint unsigned DEFAULT NULL,
			pump_disconnected tinyint(1) DEFAULT NULL,
			pump_disconnect_time smallint unsigned DEFAULT NULL,
			hypo_during_dive tinyint(1) NOT NULL DEFAULT 0,
			hypo_treatment text DEFAULT NULL,
			diabetes_notes text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_dive_id (dive_id),
			KEY idx_user_id (user_id),
			KEY idx_dive_decision (dive_decision)
		) {$charset_collate};";
		dbDelta( $sql_diabetes );

		// =====================================================================
		// TABELLA 4: SESSIONI DI IMMERSIONE (sd_dive_sessions)
		// Raggruppa più immersioni dello stesso giorno (fino a 3 come da FOGLIO)
		// =====================================================================
		$table_sessions = $this->table( 'dive_sessions' );
		$sql_sessions   = "CREATE TABLE {$table_sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			session_date date NOT NULL,
			session_notes text DEFAULT NULL,
			weather_general varchar(100) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user_id (user_id),
			KEY idx_session_date (session_date),
			UNIQUE KEY idx_user_date (user_id, session_date)
		) {$charset_collate};";
		dbDelta( $sql_sessions );

		// =====================================================================
		// TABELLA 5: SUPERVISIONE MEDICA (sd_medical_supervision)
		// =====================================================================
		$table_medical = $this->table( 'medical_supervision' );
		$sql_medical   = "CREATE TABLE {$table_medical} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dive_id bigint(20) unsigned NOT NULL,
			diver_user_id bigint(20) unsigned NOT NULL,
			supervisor_user_id bigint(20) unsigned NOT NULL,
			supervision_type varchar(15) NOT NULL,
			status varchar(15) DEFAULT 'in_revisione',
			notes text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_dive_id (dive_id),
			KEY idx_diver (diver_user_id),
			KEY idx_supervisor (supervisor_user_id)
		) {$charset_collate};";
		dbDelta( $sql_medical );

		// =====================================================================
		// TABELLA 6: LOG ALIMENTAZIONE PRE-IMMERSIONE (sd_nutrition_log)
		// Da protocollo Tab. 4: alimentazione corretta
		// =====================================================================
		$table_nutrition = $this->table( 'nutrition_log' );

		// =====================================================================
		// TABELLA 7: STORICO MODIFICHE IMMERSIONI (sd_dive_edits)
		// Ogni modifica viene registrata con vecchi/nuovi valori
		// =====================================================================
		$table_edits = $this->table( 'dive_edits' );
		$sql_edits   = "CREATE TABLE {$table_edits} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dive_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			edit_type varchar(20) NOT NULL DEFAULT 'update',
			table_name varchar(30) NOT NULL DEFAULT 'dives',
			field_name varchar(60) NOT NULL,
			old_value text DEFAULT NULL,
			new_value text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_dive_id (dive_id),
			KEY idx_user_id (user_id),
			KEY idx_created (created_at)
		) {$charset_collate};";
		dbDelta( $sql_edits );

		$sql_nutrition = "CREATE TABLE {$table_nutrition} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dive_id bigint(20) unsigned DEFAULT NULL,
			user_id bigint(20) unsigned NOT NULL,
			log_date date NOT NULL,
			meal_type varchar(20) NOT NULL,
			description text DEFAULT NULL,
			calories_estimated smallint unsigned DEFAULT NULL,
			cho_grams decimal(5,1) DEFAULT NULL,
			liquids_ml smallint unsigned DEFAULT NULL,
			notes varchar(255) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user_id (user_id),
			KEY idx_dive_id (dive_id),
			KEY idx_date (log_date)
		) {$charset_collate};";
		dbDelta( $sql_nutrition );
	}

	/**
	 * Rimuovi tutte le tabelle (usata SOLO in uninstall, MAI in deactivate)
	 */
	public function drop_tables() {
		global $wpdb;

		$tables = array(
			'medical_supervision',
			'nutrition_log',
			'dive_edits',
			'dive_diabetes',
			'dives',
			'dive_sessions',
			'diver_profiles',
		);

		foreach ( $tables as $table ) {
			$table_name = $this->table( $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		}
	}

	/**
	 * Verifica se le tabelle esistono
	 */
	public function tables_exist() {
		global $wpdb;
		$table = $this->table( 'diver_profiles' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
