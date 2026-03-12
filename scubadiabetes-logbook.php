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
 * Requires PHP: 7.4
 */

// Impedisci accesso diretto
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Costanti del plugin
define( 'SD_LOGBOOK_VERSION', '1.0.0' );
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
    const DB_VERSION = '1.3.0';

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
        require_once SD_LOGBOOK_PLUGIN_DIR . 'includes/class-sd-dive-edit.php';
    }

    /**
     * Inizializza gli hook
     */
    private function init_hooks() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'init', array( $this, 'init_components' ) );
    }

    /**
     * Attivazione plugin
     */
    public function activate() {
        // Crea tabelle database
        $db = new SD_Database();
        $db->create_tables();

        // Crea ruoli utente personalizzati
        $roles = new SD_Roles();
        $roles->create_roles();

        // Salva la versione del DB
        update_option( 'sd_logbook_db_version', self::DB_VERSION );
        update_option( 'sd_logbook_version', SD_LOGBOOK_VERSION );

        // Pulisci rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Disattivazione plugin
     */
    public function deactivate() {
        // NON rimuoviamo le tabelle alla disattivazione (dati scientifici!)
        // I ruoli vengono mantenuti
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
     * Inizializza i componenti frontend
     */
    public function init_components() {
        new SD_Dive_Form();
        new SD_Dashboard();
        new SD_Diver_Profile();
        new SD_Medical_Panel();
        new SD_Research_Dashboard();
        new SD_Dive_Edit();

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
        if ( ! is_a( $post, 'WP_Post' ) ) return false;
        $shortcodes = array( 'sd_dive_form', 'sd_dashboard', 'sd_diver_profile', 'sd_medical_panel', 'sd_research_dashboard', 'sd_dive_edit' );
        foreach ( $shortcodes as $sc ) {
            if ( has_shortcode( $post->post_content, $sc ) ) return true;
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
