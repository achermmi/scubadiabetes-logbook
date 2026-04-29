<?php
/**
 * Plugin Name: ScubaDiabetes Logbook
 * Plugin URI: https://scubadiabetes.ch
 * Description: Logbook subacqueo per persone con diabete. Registrazione immersioni, monitoraggio glicemico, raccolta dati scientifici secondo il protocollo Diabete Sommerso.
 * Version: 1.0.0
 * Author: Mirko Achermann
 * Author URI: https://m-achermann.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sd-logbook
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.2
 */

// Impedisci accesso diretto
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Costanti del plugin
define( 'SD_LOGBOOK_VERSION', '1.2.0' );
define( 'SD_LOGBOOK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SD_LOGBOOK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SD_LOGBOOK_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Classe principale del plugin
 */
final class SD_Logbook {

	/**
	 * Istanza singleton
	 */
	private static $instance = null;

	/**
	 * Versione del database
	 */
	const DB_VERSION = '3.3.0';

	/**
	 * Ottieni istanza singleton
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Costruttore
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Carica i file necessari
	 */
	private function load_dependencies() {
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-database.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-roles.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-dive-form.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-dashboard.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-diver-profile.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-medical-panel.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-research-dashboard.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-diabetic-registry.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-dive-edit.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-membership-helper.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-membership.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-membership-admin.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-role-sync.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-dive-import.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-nightscout.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-nightscout-server.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-dexcom-oauth.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-dexcom-settings.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-tidepool.php';
		require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-libreview.php';
	}

	/**
	 * Inizializza gli hook
	 */
	private function init_hooks() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		add_filter( 'plugin_locale', array( $this, 'force_italian_locale' ), 10, 2 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init_components' ) );

		// Blocca il login degli account disabilitati (is_active = 0)
		add_filter( 'authenticate', array( $this, 'block_disabled_accounts' ), 30, 1 );
	}

	/**
	 * Blocca il login se l'account è stato disabilitato da un amministratore
	 *
	 * @param WP_User|WP_Error|null $user Risultato dell'autenticazione
	 * @return WP_User|WP_Error
	 */
	public function block_disabled_accounts( $user ) {
		if ( $user instanceof WP_User ) {
			if ( get_user_meta( $user->ID, 'sd_account_disabled', true ) ) {
				return new WP_Error(
					'account_disabled',
					__( 'Il tuo account è stato disabilitato. Contatta il segretariato per maggiori informazioni.', 'sd-logbook' )
				);
			}
		}
		return $user;
	}

	/**
	 * Attivazione plugin
	 */
	public function activate() {
		// Crea tabelle database
		$db = new SD_Database();
		$db->create_tables();
		$db->create_membership_tables();
		$db->create_nightscout_tables();
		$db->create_dexcom_tables();       // mantiene tabella Share API (dati esistenti)
		$db->create_dexcom_oauth_tables();
		$db->create_tidepool_tables();
		$db->create_libreview_tables();

		// Crea ruoli utente personalizzati
		$roles = new SD_Roles();
		$roles->create_roles();

		// Salva la versione del DB
		update_option( 'sd_logbook_db_version', self::DB_VERSION );
		update_option( 'sd_logbook_version', SD_LOGBOOK_VERSION );

		// Imposta email segretariato di default
		if ( ! get_option( 'sd_secretariat_email' ) ) {
			update_option( 'sd_secretariat_email', get_option( 'admin_email' ) );
		}

		// Programma cron rinnovi e sync Nightscout e Dexcom
		SD_Membership_Helper::schedule_cron();
		SD_Nightscout::schedule_cron();
		SD_Dexcom_OAuth::schedule_cron();
		SD_Tidepool::schedule_cron();
		SD_LibreView::schedule_cron();

		// Pulisci rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Disattivazione plugin
	 */
	public function deactivate() {
		// NON rimuoviamo le tabelle alla disattivazione (dati scientifici!)
		// I ruoli vengono mantenuti
		wp_clear_scheduled_hook( 'sd_membership_renewal_check' );
		SD_Nightscout::unschedule_cron();
		SD_Dexcom_OAuth::unschedule_cron();
		SD_Tidepool::unschedule_cron();
		SD_LibreView::unschedule_cron();
		flush_rewrite_rules();
	}

	/**
	 * Controlla aggiornamenti DB dopo caricamento plugin
	 */
	public function on_plugins_loaded() {
		$current_db_version = get_option( 'sd_logbook_db_version', '0' );
		if ( version_compare( $current_db_version, self::DB_VERSION, '<' ) ) {
			$db = new SD_Database();
			$db->create_tables();
			$db->create_membership_tables();
			$db->create_nightscout_tables();
			$db->create_dexcom_tables();       // mantiene tabella Share API (dati esistenti)
			$db->create_dexcom_oauth_tables();
			$db->create_tidepool_tables();
			$db->create_libreview_tables();

			// v3.1.0: corregge valori non validi in member_type
			if ( version_compare( $current_db_version, '3.1.0', '<' ) ) {
				$db->fix_invalid_member_types();
			}

			update_option( 'sd_logbook_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Carica traduzioni
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'sd-logbook',
			false,
			dirname( SD_LOGBOOK_PLUGIN_BASENAME ) . '/languages/'
		);
	}

	/**
	 * Forza la lingua italiana per il text domain del plugin
	 */
	public function force_italian_locale( $locale, $domain ) {
		if ( 'sd-logbook' === $domain ) {
			return 'it_IT';
		}
		return $locale;
	}

	/**
	 * Inizializza i componenti frontend
	 */
	public function init_components() {
		new SD_Dive_Form();
		new SD_Dashboard();
		new SD_Diver_Profile();
		new SD_Medical_Panel();
		new SD_Research_Dashboard();
		new SD_Diabetic_Registry();
		new SD_Dive_Edit();
		new SD_Membership();
		new SD_Membership_Admin();
		new SD_Role_Sync();
		new SD_Dive_Import();
		new SD_Nightscout();
		new SD_Nightscout_Server();
		new SD_Dexcom_OAuth();
		new SD_Dexcom_Settings();
		new SD_Tidepool();
		new SD_LibreView();

		// Cron rinnovi e sync Nightscout, Dexcom OAuth, Tidepool e LibreView (registra se non già programmato)
		SD_Membership_Helper::schedule_cron();
		SD_Nightscout::schedule_cron();
		SD_Dexcom_OAuth::schedule_cron();
		SD_Tidepool::schedule_cron();
		SD_LibreView::schedule_cron();

		// Neve PRO theme compatibility
		add_filter( 'neve_sidebar_position', array( $this, 'neve_force_fullwidth' ) );
		add_filter( 'neve_container_class_filter', array( $this, 'neve_container_class' ) );
	}

	/**
	 * Force full-width (no sidebar) on pages with plugin shortcodes
	 */
	public function neve_force_fullwidth( $position ) {
		if ( $this->is_plugin_page() ) {
			return 'full-width';
		}
		return $position;
	}

	/**
	 * Widen Neve container on plugin pages
	 */
	public function neve_container_class( $class ) {
		if ( $this->is_plugin_page() ) {
			return 'container-fluid';
		}
		return $class;
	}

	/**
	 * Check if current page has any plugin shortcode
	 */
	private function is_plugin_page() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return false;
		}
		$shortcodes = array( 'sd_dive_form', 'sd_dashboard', 'sd_diver_profile', 'sd_medical_panel', 'sd_research_dashboard', 'sd_diabetic_registry', 'sd_dive_edit', 'sd_iscrizione', 'sd_gestione_soci', 'sd_iscrizione_edit' );
		foreach ( $shortcodes as $sc ) {
			if ( has_shortcode( $post->post_content, $sc ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get frontend login URL with redirect back to current page
	 */
	public static function get_login_url( $redirect = '' ) {
		if ( empty( $redirect ) ) {
			$redirect = get_permalink();
		}

		// Check for common frontend login page slugs
		$login_page = get_page_by_path( 'login' );
		if ( ! $login_page ) {
			$login_page = get_page_by_path( 'accedi' );
		}

		if ( $login_page ) {
			$url = get_permalink( $login_page );
			return add_query_arg( 'redirect_to', urlencode( $redirect ), $url );
		}

		// Fallback to WP login
		return wp_login_url( $redirect );
	}
}

// Avvia il plugin
SD_Logbook::get_instance();
