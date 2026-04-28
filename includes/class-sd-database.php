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
			diabetes_type varchar(20) DEFAULT 'none',
			therapy_type varchar(40) DEFAULT 'none',
			therapy_detail varchar(100) DEFAULT NULL,
			therapy_detail_other varchar(120) DEFAULT NULL,
			certification_level varchar(50) DEFAULT NULL,
			certification_agency varchar(50) DEFAULT NULL,
			certification_date date DEFAULT NULL,
			emergency_contact_name varchar(100) DEFAULT NULL,
			emergency_contact_phone varchar(30) DEFAULT NULL,
			emergency_contact_email varchar(100) DEFAULT NULL,
			emergency_contact_relationship varchar(50) DEFAULT NULL,
			medical_clearance_date date DEFAULT NULL,
			medical_clearance_expiry date DEFAULT NULL,
			id_for_research varchar(20) DEFAULT NULL,
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
			hba1c_unit varchar(10) NOT NULL DEFAULT 'percent',
			hba1c_date date DEFAULT NULL,
			uses_cgm tinyint(1) NOT NULL DEFAULT 0,
			cgm_device varchar(50) DEFAULT NULL,
			insulin_pump_model varchar(100) DEFAULT NULL,
			insulin_pump_model_other varchar(120) DEFAULT NULL,
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
			gear_notes text DEFAULT NULL,
			thermal_comfort varchar(20) DEFAULT NULL,
			workload varchar(20) DEFAULT NULL,
			problems varchar(100) DEFAULT NULL,
			malfunctions varchar(100) DEFAULT NULL,
			symptoms varchar(50) DEFAULT NULL,
			exposure_to_altitude varchar(20) DEFAULT NULL,
			sightings text DEFAULT NULL,
			other_equipment text DEFAULT NULL,
			notes text DEFAULT NULL,
			buddy_name varchar(100) DEFAULT NULL,
			guide_name varchar(100) DEFAULT NULL,
			computer_brand varchar(50) DEFAULT NULL,
			computer_model varchar(100) DEFAULT NULL,
			computer_serial varchar(50) DEFAULT NULL,
			computer_firmware varchar(50) DEFAULT NULL,
			imported_at datetime DEFAULT NULL,
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

		// Migration: add computer / import columns if missing (added in v2.1.0).
		// Migration: add Shearwater extended fields if missing (added in v2.5.0).
		// Migration: add entry_type and remaining fields if missing (added in v2.6.0).
		$new_dive_cols = array(
			'computer_brand'       => 'varchar(50) DEFAULT NULL',
			'computer_model'       => 'varchar(100) DEFAULT NULL',
			'computer_serial'      => 'varchar(50) DEFAULT NULL',
			'computer_firmware'    => 'varchar(50) DEFAULT NULL',
			'imported_at'          => 'datetime DEFAULT NULL',
			'gear_notes'           => 'text DEFAULT NULL',
			'thermal_comfort'      => 'varchar(20) DEFAULT NULL',
			'workload'             => 'varchar(20) DEFAULT NULL',
			'problems'             => 'varchar(100) DEFAULT NULL',
			'malfunctions'         => 'varchar(100) DEFAULT NULL',
			'symptoms'             => 'varchar(50) DEFAULT NULL',
			'exposure_to_altitude' => 'varchar(20) DEFAULT NULL',
			'entry_type'           => 'varchar(10) DEFAULT NULL',
			'avg_depth'            => 'decimal(5,1) DEFAULT NULL',
			'nitrox_percentage'    => 'decimal(4,1) DEFAULT NULL',
			'ballast_kg'           => 'decimal(4,1) DEFAULT NULL',
			'buddy_name'           => 'varchar(100) DEFAULT NULL',
			'guide_name'           => 'varchar(100) DEFAULT NULL',
			'site_latitude'        => 'decimal(10,7) DEFAULT NULL',
			'site_longitude'       => 'decimal(10,7) DEFAULT NULL',
		);
		foreach ( $new_dive_cols as $col_name => $col_def ) {
			$exists = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SHOW COLUMNS FROM ' . $table_dives . ' LIKE %s', $col_name ) // phpcs:ignore
			);
			if ( empty( $exists ) ) {
				$wpdb->query( 'ALTER TABLE ' . $table_dives . ' ADD COLUMN ' . $col_name . ' ' . $col_def ); // phpcs:ignore
			}
		}

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
			glic_60_cap smallint unsigned DEFAULT NULL,
			glic_60_sens smallint unsigned DEFAULT NULL,
			glic_60_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_60_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_60_insulin decimal(5,2) DEFAULT NULL,
			glic_60_notes varchar(255) DEFAULT NULL,
			glic_30_value smallint unsigned DEFAULT NULL,
			glic_30_method varchar(1) DEFAULT NULL,
			glic_30_trend varchar(20) DEFAULT NULL,
			glic_30_cap smallint unsigned DEFAULT NULL,
			glic_30_sens smallint unsigned DEFAULT NULL,
			glic_30_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_30_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_30_insulin decimal(5,2) DEFAULT NULL,
			glic_30_notes varchar(255) DEFAULT NULL,
			glic_10_value smallint unsigned DEFAULT NULL,
			glic_10_method varchar(1) DEFAULT NULL,
			glic_10_trend varchar(20) DEFAULT NULL,
			glic_10_cap smallint unsigned DEFAULT NULL,
			glic_10_sens smallint unsigned DEFAULT NULL,
			glic_10_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_10_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_10_insulin decimal(5,2) DEFAULT NULL,
			glic_10_notes varchar(255) DEFAULT NULL,
			glic_post_value smallint unsigned DEFAULT NULL,
			glic_post_method varchar(1) DEFAULT NULL,
			glic_post_trend varchar(20) DEFAULT NULL,
			glic_post_cap smallint unsigned DEFAULT NULL,
			glic_post_sens smallint unsigned DEFAULT NULL,
			glic_post_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_post_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_post_insulin decimal(5,2) DEFAULT NULL,
			glic_post_notes varchar(255) DEFAULT NULL,
			glic_extra_when varchar(20) DEFAULT NULL,
			glic_extra_cap smallint unsigned DEFAULT NULL,
			glic_extra_sens smallint unsigned DEFAULT NULL,
			glic_extra_trend varchar(20) DEFAULT NULL,
			glic_extra_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_extra_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_extra_insulin decimal(5,2) DEFAULT NULL,
			glic_extra_notes varchar(255) DEFAULT NULL,
			glic_extra1_when varchar(20) DEFAULT NULL,
			glic_extra1_cap smallint unsigned DEFAULT NULL,
			glic_extra1_sens smallint unsigned DEFAULT NULL,
			glic_extra1_trend varchar(20) DEFAULT NULL,
			glic_extra1_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_extra1_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_extra1_insulin decimal(5,2) DEFAULT NULL,
			glic_extra1_notes varchar(255) DEFAULT NULL,
			glic_extra2_when varchar(20) DEFAULT NULL,
			glic_extra2_cap smallint unsigned DEFAULT NULL,
			glic_extra2_sens smallint unsigned DEFAULT NULL,
			glic_extra2_trend varchar(20) DEFAULT NULL,
			glic_extra2_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_extra2_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_extra2_insulin decimal(5,2) DEFAULT NULL,
			glic_extra2_notes varchar(255) DEFAULT NULL,
			glic_extra3_when varchar(20) DEFAULT NULL,
			glic_extra3_cap smallint unsigned DEFAULT NULL,
			glic_extra3_sens smallint unsigned DEFAULT NULL,
			glic_extra3_trend varchar(20) DEFAULT NULL,
			glic_extra3_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_extra3_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_extra3_insulin decimal(5,2) DEFAULT NULL,
			glic_extra3_notes varchar(255) DEFAULT NULL,
			glic_extra4_when varchar(20) DEFAULT NULL,
			glic_extra4_cap smallint unsigned DEFAULT NULL,
			glic_extra4_sens smallint unsigned DEFAULT NULL,
			glic_extra4_trend varchar(20) DEFAULT NULL,
			glic_extra4_cho_rapidi decimal(5,1) DEFAULT NULL,
			glic_extra4_cho_lenti decimal(5,1) DEFAULT NULL,
			glic_extra4_insulin decimal(5,2) DEFAULT NULL,
			glic_extra4_notes varchar(255) DEFAULT NULL,
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

		// Migrazione: aggiungi le nuove colonne cap/sens/extra se mancanti (aggiunto in v3.0.0).
		$new_diabetes_cols = array(
			'glic_60_cap'          => 'smallint unsigned DEFAULT NULL',
			'glic_60_sens'         => 'smallint unsigned DEFAULT NULL',
			'glic_30_cap'          => 'smallint unsigned DEFAULT NULL',
			'glic_30_sens'         => 'smallint unsigned DEFAULT NULL',
			'glic_10_cap'          => 'smallint unsigned DEFAULT NULL',
			'glic_10_sens'         => 'smallint unsigned DEFAULT NULL',
			'glic_post_cap'        => 'smallint unsigned DEFAULT NULL',
			'glic_post_sens'       => 'smallint unsigned DEFAULT NULL',
			'glic_extra_when'      => 'varchar(20) DEFAULT NULL',
			'glic_extra_cap'       => 'smallint unsigned DEFAULT NULL',
			'glic_extra_sens'      => 'smallint unsigned DEFAULT NULL',
			'glic_extra_trend'     => 'varchar(20) DEFAULT NULL',
			'glic_extra_cho_rapidi' => 'decimal(5,1) DEFAULT NULL',
			'glic_extra_cho_lenti' => 'decimal(5,1) DEFAULT NULL',
			'glic_extra_insulin'   => 'decimal(5,2) DEFAULT NULL',
			'glic_extra_notes'     => 'varchar(255) DEFAULT NULL',
			'glic_extra1_when'      => 'varchar(20) DEFAULT NULL',
			'glic_extra1_cap'       => 'smallint unsigned DEFAULT NULL',
			'glic_extra1_sens'      => 'smallint unsigned DEFAULT NULL',
			'glic_extra1_trend'     => 'varchar(20) DEFAULT NULL',
			'glic_extra1_cho_rapidi' => 'decimal(5,1) DEFAULT NULL',
			'glic_extra1_cho_lenti' => 'decimal(5,1) DEFAULT NULL',
			'glic_extra1_insulin'   => 'decimal(5,2) DEFAULT NULL',
			'glic_extra1_notes'     => 'varchar(255) DEFAULT NULL',
			'glic_extra2_when'      => 'varchar(20) DEFAULT NULL',
			'glic_extra2_cap'       => 'smallint unsigned DEFAULT NULL',
			'glic_extra2_sens'      => 'smallint unsigned DEFAULT NULL',
			'glic_extra2_trend'     => 'varchar(20) DEFAULT NULL',
			'glic_extra2_cho_rapidi' => 'decimal(5,1) DEFAULT NULL',
			'glic_extra2_cho_lenti' => 'decimal(5,1) DEFAULT NULL',
			'glic_extra2_insulin'   => 'decimal(5,2) DEFAULT NULL',
			'glic_extra2_notes'     => 'varchar(255) DEFAULT NULL',
			'glic_extra3_when'      => 'varchar(20) DEFAULT NULL',
			'glic_extra3_cap'       => 'smallint unsigned DEFAULT NULL',
			'glic_extra3_sens'      => 'smallint unsigned DEFAULT NULL',
			'glic_extra3_trend'     => 'varchar(20) DEFAULT NULL',
			'glic_extra3_cho_rapidi' => 'decimal(5,1) DEFAULT NULL',
			'glic_extra3_cho_lenti' => 'decimal(5,1) DEFAULT NULL',
			'glic_extra3_insulin'   => 'decimal(5,2) DEFAULT NULL',
			'glic_extra3_notes'     => 'varchar(255) DEFAULT NULL',
			'glic_extra4_when'      => 'varchar(20) DEFAULT NULL',
			'glic_extra4_cap'       => 'smallint unsigned DEFAULT NULL',
			'glic_extra4_sens'      => 'smallint unsigned DEFAULT NULL',
			'glic_extra4_trend'     => 'varchar(20) DEFAULT NULL',
			'glic_extra4_cho_rapidi' => 'decimal(5,1) DEFAULT NULL',
			'glic_extra4_cho_lenti' => 'decimal(5,1) DEFAULT NULL',
			'glic_extra4_insulin'   => 'decimal(5,2) DEFAULT NULL',
			'glic_extra4_notes'     => 'varchar(255) DEFAULT NULL',
		);
		foreach ( $new_diabetes_cols as $col_name => $col_def ) {
			$exists = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SHOW COLUMNS FROM ' . $table_diabetes . ' LIKE %s', $col_name ) // phpcs:ignore
			);
			if ( empty( $exists ) ) {
				$wpdb->query( 'ALTER TABLE ' . $table_diabetes . ' ADD COLUMN ' . $col_name . ' ' . $col_def ); // phpcs:ignore
			}
		}

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
	 * Crea le tabelle per il sistema iscrizioni soci
	 * Usa dbDelta per le nuove tabelle e ALTER TABLE per colonne aggiuntive
	 * sulle tabelle già esistenti
	 */
	public function create_membership_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// =====================================================================
		// TABELLA: SOCI (sd_members)
		// =====================================================================
		$table_members = $this->table( 'members' );
		$sql_members   = "CREATE TABLE {$table_members} (
			id int(11) NOT NULL AUTO_INCREMENT,
			wp_user_id int(11) DEFAULT NULL,
			first_name varchar(100) NOT NULL,
			last_name varchar(100) NOT NULL,
			email varchar(255) NOT NULL,
			phone varchar(50) DEFAULT NULL,
			date_of_birth date DEFAULT NULL,
			fiscal_code varchar(50) DEFAULT NULL,
			address_street varchar(255) DEFAULT NULL,
			address_city varchar(100) DEFAULT NULL,
			address_postal varchar(20) DEFAULT NULL,
			address_country varchar(100) DEFAULT 'CH',
			address_canton varchar(50) DEFAULT NULL,
			membership_type varchar(50) DEFAULT 'individuale',
			diabetes_type varchar(30) DEFAULT 'non_diabetico',
			roles text DEFAULT NULL,
			member_since date DEFAULT NULL,
			membership_expiry date DEFAULT NULL,
			is_active tinyint(1) DEFAULT 1,
			has_paid_fee tinyint(1) DEFAULT 0,
			medical_cert_expiry date DEFAULT NULL,
			notes text DEFAULT NULL,
			avatar_url varchar(500) DEFAULT NULL,
			sotto_tutela tinyint(1) DEFAULT 0,
			birth_place varchar(100) DEFAULT NULL,
			birth_country varchar(100) DEFAULT 'CH',
			gender varchar(5) DEFAULT NULL,
			is_scuba tinyint(1) DEFAULT 0,
			fee_amount decimal(5,2) DEFAULT NULL,
			member_type varchar(50) DEFAULT 'attivo',
			diabetology_center varchar(200) DEFAULT NULL,
			registered_by int(11) DEFAULT NULL,
			registered_at datetime DEFAULT NULL,
			privacy_consent tinyint(1) DEFAULT 0,
			consent_date datetime DEFAULT NULL,
			guardian_first_name varchar(100) DEFAULT NULL,
			guardian_last_name varchar(100) DEFAULT NULL,
			guardian_role varchar(50) DEFAULT NULL,
			guardian_dob date DEFAULT NULL,
			guardian_birth_place varchar(100) DEFAULT NULL,
			guardian_birth_country varchar(100) DEFAULT 'CH',
			guardian_gender varchar(5) DEFAULT NULL,
			guardian_email varchar(100) DEFAULT NULL,
			guardian_phone varchar(30) DEFAULT NULL,
			guardian_address varchar(200) DEFAULT NULL,
			guardian_city varchar(100) DEFAULT NULL,
			guardian_postal varchar(20) DEFAULT NULL,
			guardian_country varchar(100) DEFAULT 'CH',
			taglia_maglietta varchar(15) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY idx_email (email),
			KEY idx_wp_user (wp_user_id)
		) {$charset_collate};";
		dbDelta( $sql_members );

		// =====================================================================
		// TABELLA: PAGAMENTI (sd_payments)
		// =====================================================================
		$table_payments = $this->table( 'payments' );
		$sql_payments   = "CREATE TABLE {$table_payments} (
			id int(11) NOT NULL AUTO_INCREMENT,
			member_id int(11) NOT NULL,
			amount decimal(10,2) NOT NULL,
			currency varchar(3) DEFAULT 'CHF',
			payment_date datetime DEFAULT NULL,
			payment_method varchar(50) NOT NULL DEFAULT 'bonifico_iban',
			payment_year int(11) NOT NULL,
			status varchar(30) DEFAULT 'in_attesa',
			transaction_id varchar(255) DEFAULT NULL,
			notes text DEFAULT NULL,
			registered_by int(11) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_member (member_id),
			KEY idx_year (payment_year)
		) {$charset_collate};";
		dbDelta( $sql_payments );

		// =====================================================================
		// TABELLA: FAMILIARI (sd_family_members)
		// =====================================================================
		$table_family = $this->table( 'family_members' );
		$sql_family   = "CREATE TABLE {$table_family} (
			id int(11) NOT NULL AUTO_INCREMENT,
			member_id int(11) NOT NULL,
			first_name varchar(100) NOT NULL,
			last_name varchar(100) NOT NULL,
			date_of_birth date DEFAULT NULL,
			phone varchar(50) DEFAULT NULL,
			email varchar(255) DEFAULT NULL,
			diabetes_type varchar(30) DEFAULT 'non_diabetico',
			companion_role varchar(50) DEFAULT NULL,
			is_companion tinyint(1) DEFAULT 0,
			address varchar(200) DEFAULT NULL,
			city varchar(100) DEFAULT NULL,
			postal varchar(20) DEFAULT NULL,
			country varchar(100) DEFAULT 'CH',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY member_id (member_id)
		) {$charset_collate};";
		dbDelta( $sql_family );

		// =====================================================================
		// TABELLA: AUDIT LOG (sd_audit_log)
		// =====================================================================
		$table_audit = $this->table( 'audit_log' );
		$sql_audit   = "CREATE TABLE {$table_audit} (
			id int(11) NOT NULL AUTO_INCREMENT,
			member_id int(11) DEFAULT NULL,
			action varchar(100) NOT NULL,
			table_name varchar(100) DEFAULT NULL,
			record_id int(11) DEFAULT NULL,
			old_data longtext DEFAULT NULL,
			new_data longtext DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			user_agent varchar(500) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_member (member_id),
			KEY idx_action (action),
			KEY idx_created (created_at)
		) {$charset_collate};";
		dbDelta( $sql_audit );

		// =====================================================================
		// TABELLA: DIVER_PROFILES - aggiungi campo diabetology_center se mancante
		// =====================================================================
		$col = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $this->table( 'diver_profiles' ) . ' LIKE %s',
				'diabetology_center'
			)
		);
		if ( empty( $col ) ) {
			$wpdb->query( 'ALTER TABLE ' . $this->table( 'diver_profiles' ) . ' ADD COLUMN diabetology_center varchar(200) DEFAULT NULL' ); // phpcs:ignore
		}

		// TABELLA: DIVER_PROFILES - allinea tipo colonna therapy_type
		$wpdb->query( 'ALTER TABLE ' . $this->table( 'diver_profiles' ) . " MODIFY COLUMN therapy_type varchar(40) DEFAULT 'none'" ); // phpcs:ignore

		// TABELLA: DIVER_PROFILES - dettaglio terapia (nuovi campi)
		$col = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $this->table( 'diver_profiles' ) . ' LIKE %s',
				'therapy_detail'
			)
		);
		if ( empty( $col ) ) {
			$wpdb->query( 'ALTER TABLE ' . $this->table( 'diver_profiles' ) . ' ADD COLUMN therapy_detail varchar(100) DEFAULT NULL' ); // phpcs:ignore
		}

		$col = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $this->table( 'diver_profiles' ) . ' LIKE %s',
				'therapy_detail_other'
			)
		);
		if ( empty( $col ) ) {
			$wpdb->query( 'ALTER TABLE ' . $this->table( 'diver_profiles' ) . ' ADD COLUMN therapy_detail_other varchar(120) DEFAULT NULL' ); // phpcs:ignore
		}

		// TABELLA: DIVER_PROFILES - unita HbA1c
		$col = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $this->table( 'diver_profiles' ) . ' LIKE %s',
				'hba1c_unit'
			)
		);
		if ( empty( $col ) ) {
			$wpdb->query( "ALTER TABLE " . $this->table( 'diver_profiles' ) . " ADD COLUMN hba1c_unit varchar(10) NOT NULL DEFAULT 'percent'" ); // phpcs:ignore
		}

		// TABELLA: DIVER_PROFILES - estende modello microinfusore + campo altro
		$wpdb->query( 'ALTER TABLE ' . $this->table( 'diver_profiles' ) . ' MODIFY COLUMN insulin_pump_model varchar(100) DEFAULT NULL' ); // phpcs:ignore
		$col = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $this->table( 'diver_profiles' ) . ' LIKE %s',
				'insulin_pump_model_other'
			)
		);
		if ( empty( $col ) ) {
			$wpdb->query( 'ALTER TABLE ' . $this->table( 'diver_profiles' ) . ' ADD COLUMN insulin_pump_model_other varchar(120) DEFAULT NULL' ); // phpcs:ignore
		}

		// =====================================================================
		// MIGRAZIONI v2.8.0: Iscrizione famiglia con utenti WP per ogni famigliare
		// =====================================================================

		// sd_members: colonna parent_member_id per tracciare i famigliari dell'intestatario
		$col = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $table_members . ' LIKE %s', // phpcs:ignore
				'parent_member_id'
			)
		);
		if ( empty( $col ) ) {
			$wpdb->query( 'ALTER TABLE ' . $table_members . ' ADD COLUMN parent_member_id int(11) DEFAULT NULL' ); // phpcs:ignore
			$wpdb->query( 'ALTER TABLE ' . $table_members . ' ADD KEY idx_parent_member (parent_member_id)' ); // phpcs:ignore
		}

		// sd_family_members: colonna wp_user_id per il link all'utente WP creato
		$col = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $table_family . ' LIKE %s', // phpcs:ignore
				'wp_user_id'
			)
		);
		if ( empty( $col ) ) {
			$wpdb->query( 'ALTER TABLE ' . $table_family . ' ADD COLUMN wp_user_id int(11) DEFAULT NULL' ); // phpcs:ignore
		}

		// sd_family_members: colonna gender
		$col = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $table_family . ' LIKE %s', // phpcs:ignore
				'gender'
			)
		);
		if ( empty( $col ) ) {
			$wpdb->query( 'ALTER TABLE ' . $table_family . ' ADD COLUMN gender varchar(5) DEFAULT NULL' ); // phpcs:ignore
		}

		// sd_family_members: colonna is_scuba per determinare il ruolo WP
		$col = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $table_family . ' LIKE %s', // phpcs:ignore
				'is_scuba'
			)
		);
		if ( empty( $col ) ) {
			$wpdb->query( 'ALTER TABLE ' . $table_family . ' ADD COLUMN is_scuba tinyint(1) DEFAULT 0' ); // phpcs:ignore
		}

		// sd_family_members: colonna member_id nel senso dell'intestatario (già esiste),
		// aggiunta colonna family_member_id che punta al record sd_members del famigliare
		$col = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $table_family . ' LIKE %s', // phpcs:ignore
				'family_member_id'
			)
		);
		if ( empty( $col ) ) {
			$wpdb->query( 'ALTER TABLE ' . $table_family . ' ADD COLUMN family_member_id int(11) DEFAULT NULL' ); // phpcs:ignore
		}

		// =====================================================================
		// MIGRAZIONI v2.9.0: Taglia maglietta
		// =====================================================================

		// sd_members: colonna taglia_maglietta
		$col = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $table_members . ' LIKE %s', // phpcs:ignore
				'taglia_maglietta'
			)
		);
		if ( empty( $col ) ) {
			$wpdb->query( 'ALTER TABLE ' . $table_members . ' ADD COLUMN taglia_maglietta varchar(15) DEFAULT NULL' ); // phpcs:ignore
		}
	}

	/**
	 * Corregge i valori non validi del campo member_type.
	 *
	 * Regole (da membership-form e membership.php):
	 *   - fee >= 75 + senza genitore → attivo_capo_famiglia
	 *   - parent_member_id non nullo  → attivo_famigliare
	 *   - tutti gli altri invalidi    → attivo
	 */
	public function fix_invalid_member_types() {
		global $wpdb;
		$table = $this->table( 'members' );

		$valid = array(
			'attivo',
			'attivo_capo_famiglia',
			'attivo_famigliare',
			'passivo',
			'accompagnatore',
			'sostenitore',
			'onorario',
			'fondatore',
		);

		$placeholders = implode( ', ', array_fill( 0, count( $valid ), '%s' ) );

		// 1. fee >= 75 e nessun genitore → attivo_capo_famiglia
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET member_type = 'attivo_capo_famiglia'
				 WHERE member_type NOT IN ({$placeholders})
				   AND fee_amount >= 75
				   AND (parent_member_id IS NULL OR parent_member_id = 0)",
				...$valid
			)
		);

		// 2. Ha un genitore → attivo_famigliare
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET member_type = 'attivo_famigliare'
				 WHERE member_type NOT IN ({$placeholders})
				   AND parent_member_id IS NOT NULL
				   AND parent_member_id > 0",
				...$valid
			)
		);

		// 3. Tutti i restanti invalidi → attivo
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET member_type = 'attivo'
				 WHERE member_type NOT IN ({$placeholders})",
				...$valid
			)
		);
	}

	// =====================================================================
	// TABELLE NIGHTSCOUT (aggiunto in v3.2.0)
	// =====================================================================

	/**
	 * Crea o aggiorna le tabelle per l'integrazione Nightscout.
	 * Idempotente: sicuro da chiamare più volte (usa dbDelta).
	 */
	public function create_nightscout_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Connessioni Nightscout (1 per utente)
		$t_conn = $this->table( 'nightscout_connections' );
		dbDelta( "CREATE TABLE {$t_conn} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			nightscout_url varchar(500) NOT NULL,
			api_token_enc text NOT NULL,
			sync_enabled tinyint(1) NOT NULL DEFAULT 1,
			last_sync_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_user_id (user_id)
		) {$charset_collate};" );

		// Cache letture CGM / capillari da Nightscout
		$t_read = $this->table( 'nightscout_readings' );
		dbDelta( "CREATE TABLE {$t_read} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			reading_time datetime NOT NULL,
			glucose_value smallint unsigned NOT NULL,
			glucose_unit varchar(10) NOT NULL DEFAULT 'mg/dl',
			direction varchar(30) DEFAULT NULL,
			reading_type varchar(5) NOT NULL DEFAULT 'sgv',
			device varchar(100) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_user_time (user_id, reading_time),
			KEY idx_user_id (user_id),
			KEY idx_time (reading_time)
		) {$charset_collate};" );

		// Cache trattamenti (boli insulina, carboidrati, ecc.) da Nightscout
		$t_treat = $this->table( 'nightscout_treatments' );
		dbDelta( "CREATE TABLE {$t_treat} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			treatment_time datetime NOT NULL,
			event_type varchar(60) NOT NULL,
			insulin_units decimal(5,2) DEFAULT NULL,
			carbs_grams decimal(5,1) DEFAULT NULL,
			notes text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user_id (user_id),
			KEY idx_time (treatment_time)
		) {$charset_collate};" );
	}

	/**
	 * Crea/aggiorna la tabella per le connessioni Dexcom API Ufficiale (OAuth 2.0)
	 */
	public function create_dexcom_oauth_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t_conn = $this->table( 'dexcom_oauth_connections' );
		dbDelta( "CREATE TABLE {$t_conn} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			access_token text NOT NULL,
			refresh_token text NOT NULL,
			token_expires_at datetime NOT NULL,
			sync_enabled tinyint(1) NOT NULL DEFAULT 1,
			last_sync_at datetime DEFAULT NULL,
			connected_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_user_id (user_id)
		) {$charset_collate};" );
	}

	/**
	 * Crea/aggiorna la tabella per le connessioni Tidepool
	 */
	public function create_tidepool_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t_conn = $this->table( 'tidepool_connections' );
		dbDelta( "CREATE TABLE {$t_conn} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			tidepool_email varchar(255) NOT NULL,
			password_enc text NOT NULL,
			tidepool_user_id varchar(100) DEFAULT NULL,
			sync_enabled tinyint(1) NOT NULL DEFAULT 1,
			last_sync_at datetime DEFAULT NULL,
			session_token varchar(512) DEFAULT NULL,
			session_expires datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_user_id (user_id)
		) {$charset_collate};" );
	}

	/**
	 * Crea/aggiorna la tabella per le connessioni Dexcom Share
	 */
	public function create_dexcom_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t_conn = $this->table( 'dexcom_connections' );
		dbDelta( "CREATE TABLE {$t_conn} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			dexcom_username varchar(255) NOT NULL,
			password_enc text NOT NULL,
			server varchar(5) NOT NULL DEFAULT 'ous',
			device_name varchar(100) DEFAULT NULL,
			sync_enabled tinyint(1) NOT NULL DEFAULT 1,
			last_sync_at datetime DEFAULT NULL,
			session_id varchar(255) DEFAULT NULL,
			session_expires datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_user_id (user_id)
		) {$charset_collate};" );
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
