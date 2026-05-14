<?php
/**
 * Convertitore di Valuta CHF/EUR
 *
 * Gestisce la conversione tra franchi svizzeri e euro utilizzando XE.com API.
 * Memorizza i tassi di cambio giornalieri per ridurre le chiamate API.
 *
 * @package SD_Logbook
 * @since 3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Currency_Converter {

	/**
	 * Istanza singleton
	 */
	private static $instance = null;

	/**
	 * URL API XE.com
	 */
	const XE_API_URL = 'https://api.xe.com/api/v1/convert';

	/**
	 * Timeout API (secondi)
	 */
	const API_TIMEOUT = 5;

	/**
	 * Chiave API XE.com (da configurare in opzioni WordPress)
	 */
	private $xe_api_key;

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
		$this->xe_api_key = get_option( 'sd_xe_api_key', '' );
		$this->init_hooks();
	}

	/**
	 * Inizializza gli hook
	 */
	private function init_hooks() {
		add_action( 'wp_ajax_nopriv_sd_get_eur_price', array( $this, 'ajax_get_eur_price' ) );
		add_action( 'wp_ajax_sd_get_eur_price', array( $this, 'ajax_get_eur_price' ) );
		add_action( 'wp_ajax_sd_update_currency_rates', array( $this, 'ajax_update_currency_rates' ) );

		// Cron giornaliero per aggiornare i tassi
		if ( ! wp_next_scheduled( 'sd_currency_rate_update' ) ) {
			wp_schedule_event( time(), 'daily', 'sd_currency_rate_update' );
		}
		add_action( 'sd_currency_rate_update', array( $this, 'update_daily_rate' ) );
	}

	// ======================================================================
	// METODI PUBBLICI
	// ======================================================================

	/**
	 * Converte un importo da CHF a EUR
	 *
	 * @param float $amount_chf Importo in CHF
	 * @param string $rate_date Data del tasso (YYYY-MM-DD), default oggi
	 * @return float|false Importo in EUR o false se errore
	 */
	public function convert_chf_to_eur( $amount_chf, $rate_date = null ) {
		if ( ! $rate_date ) {
			$rate_date = wp_date( 'Y-m-d' );
		}

		// Recuperare il tasso di cambio
		$rate = $this->get_rate( $rate_date );

		if ( ! $rate ) {
			return false;
		}

		return round( floatval( $amount_chf ) * floatval( $rate ), 2 );
	}

	/**
	 * Recupera il tasso CHF/EUR per una data
	 *
	 * @param string $rate_date Data (YYYY-MM-DD), default oggi
	 * @return float|false Tasso di cambio o false se non disponibile
	 */
	public function get_rate( $rate_date = null ) {
		global $wpdb;

		if ( ! $rate_date ) {
			$rate_date = wp_date( 'Y-m-d' );
		}

		// Cercare nel database
		$cached_rate = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT chf_to_eur FROM ' . $wpdb->prefix . 'sd_currency_rates
				WHERE rate_date = %s',
				$rate_date
			)
		);

		if ( $cached_rate ) {
			return floatval( $cached_rate );
		}

		// Se è oggi e non abbiamo il tasso, prova a recuperarlo ora
		$today = wp_date( 'Y-m-d' );
		if ( $rate_date === $today ) {
			return $this->fetch_and_save_rate( $rate_date );
		}

		// Per date passate, usa il tasso di ieri se disponibile
		if ( strtotime( $rate_date ) < time() ) {
			$yesterday = wp_date( 'Y-m-d', strtotime( $rate_date . ' -1 day' ) );
			return $this->get_rate( $yesterday );
		}

		return false;
	}

	/**
	 * Recupera il tasso da XE.com e lo salva nel database
	 *
	 * @param string $rate_date Data (YYYY-MM-DD), default oggi
	 * @return float|false Tasso o false se errore
	 */
	public function fetch_and_save_rate( $rate_date = null ) {
		global $wpdb;

		if ( ! $rate_date ) {
			$rate_date = wp_date( 'Y-m-d' );
		}

		// Se la chiave API non è configurata, usare un tasso fisso di fallback
		if ( ! $this->xe_api_key ) {
			$fallback_rate = get_option( 'sd_currency_fallback_rate', 1.05 );
			$this->save_rate( $rate_date, $fallback_rate );
			return floatval( $fallback_rate );
		}

		$rate = $this->fetch_rate_from_api( 'CHF', 'EUR' );

		if ( ! $rate ) {
			// Fallback a tasso memorizzato in precedenza
			$last_rate = $wpdb->get_var(
				'SELECT chf_to_eur FROM ' . $wpdb->prefix . 'sd_currency_rates
				ORDER BY rate_date DESC LIMIT 1'
			);

			if ( $last_rate ) {
				$this->save_rate( $rate_date, $last_rate );
				return floatval( $last_rate );
			}

			// Ultimo fallback
			$fallback_rate = get_option( 'sd_currency_fallback_rate', 1.05 );
			$this->save_rate( $rate_date, $fallback_rate );
			return floatval( $fallback_rate );
		}

		$this->save_rate( $rate_date, $rate );
		return $rate;
	}

	/**
	 * Recupera il tasso da XE.com API
	 *
	 * @param string $from Valuta sorgente (es. CHF)
	 * @param string $to Valuta destinazione (es. EUR)
	 * @return float|false Tasso di cambio o false se errore
	 */
	public function fetch_rate_from_api( $from = 'CHF', $to = 'EUR' ) {
		$args = array(
			'method'    => 'GET',
			'timeout'   => self::API_TIMEOUT,
			'sslverify' => true,
			'headers'   => array(
				'Authorization' => 'Basic ' . base64_encode( $this->xe_api_key . ':' ),
			),
		);

		$response = wp_remote_get(
			self::XE_API_URL . '?from=' . urlencode( $from ) . '&to=' . urlencode( $to ) . '&amount=1',
			$args
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'SD Currency: API Error - ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! $body || empty( $body['result'] ) ) {
			error_log( 'SD Currency: Invalid API response - ' . print_r( $body, true ) );
			return false;
		}

		return floatval( $body['result'] );
	}

	/**
	 * Salva il tasso di cambio nel database
	 *
	 * @param string $rate_date Data (YYYY-MM-DD)
	 * @param float  $rate Tasso CHF->EUR
	 * @return int|false ID inserito o false se errore
	 */
	public function save_rate( $rate_date, $rate ) {
		global $wpdb;

		// Verificare se esiste già
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'sd_currency_rates WHERE rate_date = %s',
				$rate_date
			)
		);

		if ( $existing ) {
			return $wpdb->update(
				$wpdb->prefix . 'sd_currency_rates',
				array( 'chf_to_eur' => floatval( $rate ) ),
				array( 'rate_date' => $rate_date ),
				array( '%f' ),
				array( '%s' )
			);
		}

		return $wpdb->insert(
			$wpdb->prefix . 'sd_currency_rates',
			array(
				'rate_date'  => sanitize_text_field( $rate_date ),
				'chf_to_eur' => floatval( $rate ),
				'source'     => 'xe.com',
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%s', '%f', '%s', '%s' )
		) ? $wpdb->insert_id : false;
	}

	/**
	 * Formatta un importo in CHF con il simbolo
	 *
	 * @param float $amount Importo
	 * @return string Importo formattato
	 */
	public static function format_chf( $amount ) {
		return 'CHF ' . number_format( floatval( $amount ), 2, '.', ' ' );
	}

	/**
	 * Formatta un importo in EUR con il simbolo
	 *
	 * @param float $amount Importo
	 * @return string Importo formattato
	 */
	public static function format_eur( $amount ) {
		return '€ ' . number_format( floatval( $amount ), 2, '.', ' ' );
	}

	// ======================================================================
	// METODI AJAX
	// ======================================================================

	/**
	 * AJAX: Recupera il prezzo in EUR per un importo CHF
	 */
	public function ajax_get_eur_price() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		$price_chf = floatval( $_POST['price_chf'] ?? 0 );

		if ( $price_chf <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Prezzo CHF non valido', 'sd-logbook' ) ) );
		}

		$price_eur = $this->convert_chf_to_eur( $price_chf );

		if ( ! $price_eur ) {
			wp_send_json_error( array( 'message' => __( 'Errore nella conversione valuta', 'sd-logbook' ) ) );
		}

		$today = wp_date( 'Y-m-d' );
		$rate  = $this->get_rate( $today );

		wp_send_json_success( array(
			'price_eur'      => $price_eur,
			'rate'           => $rate,
			'rate_date'      => $today,
			'formatted_chf'  => self::format_chf( $price_chf ),
			'formatted_eur'  => self::format_eur( $price_eur ),
		) );
	}

	/**
	 * AJAX (Admin): Aggiorna i tassi di cambio
	 */
	public function ajax_update_currency_rates() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$this->update_daily_rate();

		$today = wp_date( 'Y-m-d' );
		$rate  = $this->get_rate( $today );

		wp_send_json_success( array(
			'rate'      => $rate,
			'rate_date' => $today,
			'message'   => __( 'Tassi di cambio aggiornati', 'sd-logbook' ),
		) );
	}

	/**
	 * Cron: Aggiorna il tasso di cambio giornaliero
	 */
	public function update_daily_rate() {
		$today = wp_date( 'Y-m-d' );
		$this->fetch_and_save_rate( $today );
	}

	// ======================================================================
	// METODI STATICI - HELPER
	// ======================================================================

	/**
	 * Converte CHF a EUR (static helper)
	 *
	 * @param float $amount_chf Importo in CHF
	 * @return float Importo in EUR
	 */
	public static function convert( $amount_chf ) {
		$converter = self::get_instance();
		return $converter->convert_chf_to_eur( $amount_chf );
	}

	/**
	 * Recupera il tasso corrente CHF/EUR
	 *
	 * @return float Tasso di cambio
	 */
	public static function get_today_rate() {
		$converter = self::get_instance();
		return $converter->get_rate( wp_date( 'Y-m-d' ) );
	}
}
