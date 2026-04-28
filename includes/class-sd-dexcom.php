<?php
/**
 * Integrazione Dexcom Share API
 *
 * Permette agli utenti di collegare il proprio account Dexcom e importare
 * automaticamente le letture CGM tramite le API Share di Dexcom (US e OUS).
 *
 * @package ScubaDiabetes_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SD_Dexcom
 *
 * Gestisce l'autenticazione con le API Dexcom Share e il sync automatico
 * delle letture CGM verso la tabella sd_nightscout_readings (condivisa).
 */
class SD_Dexcom {

	/** @var string Server Dexcom USA */
	const US_BASE = 'https://share2.dexcom.com';

	/** @var string Server Dexcom fuori USA */
	const OUS_BASE = 'https://shareous1.dexcom.com';

	/** @var string Application ID pubblico Dexcom Share */
	const APP_ID = 'd8665ade-9673-4e27-9ff6-92db4ce13d13';

	/** @var string Endpoint login */
	const LOGIN_PATH = '/ShareWebServices/Services/General/LoginPublisherAccountByName';

	/** @var string Endpoint letture CGM */
	const READINGS_PATH = '/ShareWebServices/Services/Publisher/ReadPublisherLatestGlucoseValues';

	/** @var string Hook cron per sync automatico */
	const CRON_HOOK = 'sd_dexcom_sync_cron';

	/** @var int Minuti da recuperare per ogni sync */
	const SYNC_MINUTES = 1440;

	/** @var int Numero massimo letture per chiamata (288 = 24h @ 5 min) */
	const MAX_COUNT = 288;

	/** @var string Prefisso tabelle DB */
	private string $prefix;

	public function __construct() {
		global $wpdb;
		$this->prefix = $wpdb->prefix . 'sd_';

		// AJAX autenticato
		add_action( 'wp_ajax_sd_dexcom_save',       array( $this, 'ajax_save_credentials' ) );
		add_action( 'wp_ajax_sd_dexcom_test',       array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_sd_dexcom_sync',       array( $this, 'ajax_manual_sync' ) );
		add_action( 'wp_ajax_sd_dexcom_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_sd_dexcom_readings',   array( $this, 'ajax_get_readings' ) );

		// Cron sync automatico
		add_action( self::CRON_HOOK, array( $this, 'cron_sync_all' ) );
	}

	// =========================================================================
	// Helpers interni
	// =========================================================================

	/**
	 * Restituisce il nome della tabella con prefisso.
	 */
	private function table( string $name ): string {
		return $this->prefix . $name;
	}

	/**
	 * Cifra una stringa in AES-256-CBC usando wp_salt.
	 */
	private function encrypt( string $plain ): string {
		$key    = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'AES-256-CBC', $key, 0, $iv );
		return base64_encode( base64_encode( $iv ) . '::' . $cipher );
	}

	/**
	 * Decifra una stringa cifrata con encrypt().
	 */
	private function decrypt( string $encrypted ): string {
		$decoded = base64_decode( $encrypted );
		if ( false === $decoded || ! str_contains( $decoded, '::' ) ) {
			return '';
		}
		[ $iv_b64, $cipher ] = explode( '::', $decoded, 2 );
		$iv  = base64_decode( $iv_b64 );
		$key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
		return $plain ?: '';
	}

	/**
	 * Mappa il trend Dexcom (stringa) all'arrow symbol Nightscout.
	 */
	private function map_trend( string $trend ): string {
		$map = array(
			'DoubleUp'      => 'DoubleUp',
			'SingleUp'      => 'SingleUp',
			'FortyFiveUp'   => 'FortyFiveUp',
			'Flat'          => 'Flat',
			'FortyFiveDown' => 'FortyFiveDown',
			'SingleDown'    => 'SingleDown',
			'DoubleDown'    => 'DoubleDown',
			'None'          => 'NONE',
		);
		return $map[ $trend ] ?? 'NONE';
	}

	/**
	 * Restituisce il base URL in base alla scelta server dell'utente.
	 */
	private function base_url( string $server ): string {
		return ( 'us' === $server ) ? self::US_BASE : self::OUS_BASE;
	}

	// =========================================================================
	// Comunicazione con le API Dexcom Share
	// =========================================================================

	/**
	 * Effettua il login su Dexcom Share e restituisce il sessionId.
	 *
	 * @param string $username Username Dexcom.
	 * @param string $password Password Dexcom.
	 * @param string $server   'us' o 'ous'.
	 * @return string|WP_Error SessionId oppure WP_Error in caso di errore.
	 */
	public function dexcom_login( string $username, string $password, string $server ) {
		$url  = $this->base_url( $server ) . self::LOGIN_PATH;
		$body = wp_json_encode(
			array(
				'accountName'   => $username,
				'password'      => $password,
				'applicationId' => self::APP_ID,
			)
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => $body,
				'timeout' => 20,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $code ) {
			/* translators: %1$d=HTTP code, %2$s=body */
			return new WP_Error( 'dexcom_login_failed', sprintf( __( 'Login Dexcom fallito (HTTP %1$d): %2$s', 'sd-logbook' ), $code, $raw ) );
		}

		// La risposta è la session ID tra virgolette: "xxxxxxxx-..."
		$session_id = trim( $raw, '"' );
		if ( empty( $session_id ) || strlen( $session_id ) < 10 ) {
			return new WP_Error( 'dexcom_bad_session', __( 'Risposta login Dexcom non valida.', 'sd-logbook' ) );
		}

		return $session_id;
	}

	/**
	 * Recupera le ultime letture CGM dalla API Dexcom Share.
	 *
	 * @param string $session_id SessionId ottenuto da dexcom_login().
	 * @param string $server     'us' o 'ous'.
	 * @param int    $minutes    Minuti da coprire (default SYNC_MINUTES).
	 * @param int    $max_count  Numero massimo letture (default MAX_COUNT).
	 * @return array|WP_Error Array letture oppure WP_Error.
	 */
	public function dexcom_fetch_readings( string $session_id, string $server, int $minutes = self::SYNC_MINUTES, int $max_count = self::MAX_COUNT ) {
		$url  = $this->base_url( $server ) . self::READINGS_PATH;
		$body = wp_json_encode(
			array(
				'sessionId' => $session_id,
				'minutes'   => $minutes,
				'maxCount'  => $max_count,
			)
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => $body,
				'timeout' => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $code ) {
			/* translators: %1$d=HTTP code, %2$s=body */
			return new WP_Error( 'dexcom_fetch_failed', sprintf( __( 'Letture Dexcom fallite (HTTP %1$d): %2$s', 'sd-logbook' ), $code, $raw ) );
		}

		$readings = json_decode( $raw, true );
		if ( ! is_array( $readings ) ) {
			return new WP_Error( 'dexcom_bad_response', __( 'Risposta Dexcom non valida.', 'sd-logbook' ) );
		}

		return $readings;
	}

	// =========================================================================
	// Accesso connessione in DB
	// =========================================================================

	/**
	 * Recupera la connessione Dexcom di un utente dal DB.
	 *
	 * @param int $user_id ID utente WP.
	 * @return object|null Riga DB oppure null.
	 */
	public function get_connection( int $user_id ) {
		global $wpdb;
		$table = $this->table( 'dexcom_connections' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
	}

	/**
	 * Login + fetch full flow: restituisce session_id e letture.
	 * Ri-usa la session_id cache se non scaduta.
	 *
	 * @param object $conn Riga connessione DB.
	 * @return array{session_id:string, readings:array}|WP_Error
	 */
	private function login_and_fetch( object $conn ) {
		global $wpdb;

		$username = $conn->dexcom_username;
		$password = $this->decrypt( $conn->password_enc );
		$server   = $conn->server;

		if ( empty( $password ) ) {
			return new WP_Error( 'dexcom_decrypt_failed', __( 'Impossibile decifrare la password Dexcom.', 'sd-logbook' ) );
		}

		// Controlla cache session_id (valida 24h per sicurezza, Dexcom la tiene per ~6h)
		$session_id = null;
		if ( ! empty( $conn->session_id ) && ! empty( $conn->session_expires ) ) {
			$expires = strtotime( $conn->session_expires );
			if ( $expires && $expires > time() + 60 ) {
				$session_id = $conn->session_id;
			}
		}

		if ( null === $session_id ) {
			$login_result = $this->dexcom_login( $username, $password, $server );
			if ( is_wp_error( $login_result ) ) {
				return $login_result;
			}
			$session_id = $login_result;

			// Salva in cache (scade fra 5.5h)
			$table = $this->table( 'dexcom_connections' );
			$wpdb->update(
				$table,
				array(
					'session_id'      => $session_id,
					'session_expires' => gmdate( 'Y-m-d H:i:s', time() + 5 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS ),
				),
				array( 'user_id' => (int) $conn->user_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		$readings = $this->dexcom_fetch_readings( $session_id, $server );

		// Se sessione scaduta, ri-autentica una volta sola
		if ( is_wp_error( $readings ) && 200 !== (int) $readings->get_error_code() ) {
			$login_result = $this->dexcom_login( $username, $password, $server );
			if ( is_wp_error( $login_result ) ) {
				return $login_result;
			}
			$session_id = $login_result;
			$readings   = $this->dexcom_fetch_readings( $session_id, $server );
		}

		if ( is_wp_error( $readings ) ) {
			return $readings;
		}

		return array(
			'session_id' => $session_id,
			'readings'   => $readings,
		);
	}

	// =========================================================================
	// Salvataggio letture nel DB
	// =========================================================================

	/**
	 * Converte e salva le letture Dexcom nella tabella sd_nightscout_readings.
	 *
	 * @param int    $user_id  ID utente WP.
	 * @param array  $readings Array letture da Dexcom API.
	 * @param string $device   Nome dispositivo (es. "Dexcom G7").
	 * @return int Numero letture inserite/aggiornate.
	 */
	private function save_readings( int $user_id, array $readings, string $device = 'Dexcom' ): int {
		global $wpdb;
		$table   = $this->table( 'nightscout_readings' );
		$inserted = 0;

		foreach ( $readings as $r ) {
			if ( empty( $r['WT'] ) || ! isset( $r['Value'] ) ) {
				continue;
			}

			// Estrae il timestamp unix da "/Date(1234567890000)/"
			if ( preg_match( '/\/Date\((\d+)/', $r['WT'], $m ) ) {
				$ts = (int) ( $m[1] / 1000 );
			} else {
				continue;
			}

			$reading_time = gmdate( 'Y-m-d H:i:s', $ts );
			$glucose      = (int) $r['Value'];
			$trend        = $this->map_trend( $r['Trend'] ?? 'None' );

			if ( $glucose <= 0 || $glucose > 600 ) {
				continue;
			}

			$rows = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT INTO {$table}
						(user_id, reading_time, glucose_value, glucose_unit, direction, reading_type, device)
					VALUES (%d, %s, %d, %s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE
						glucose_value = VALUES(glucose_value),
						direction     = VALUES(direction),
						device        = VALUES(device)",
					$user_id,
					$reading_time,
					$glucose,
					'mg/dl',
					$trend,
					'sgv',
					$device
				)
			);

			if ( false !== $rows ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	// =========================================================================
	// Sync utente singolo
	// =========================================================================

	/**
	 * Esegue il sync Dexcom per un singolo utente.
	 *
	 * @param int $user_id ID utente WP.
	 * @return array{ok:bool, inserted:int, message:string}
	 */
	public function sync_user( int $user_id ): array {
		global $wpdb;

		$conn = $this->get_connection( $user_id );
		if ( ! $conn || ! $conn->sync_enabled ) {
			return array( 'ok' => false, 'inserted' => 0, 'message' => 'Sync disabilitato o nessuna connessione.' );
		}

		$result = $this->login_and_fetch( $conn );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'inserted' => 0, 'message' => $result->get_error_message() );
		}

		// Stima nome dispositivo dalla configurazione utente
		$device   = ! empty( $conn->device_name ) ? $conn->device_name : 'Dexcom';
		$inserted = $this->save_readings( $user_id, $result['readings'], $device );

		// Aggiorna last_sync_at
		$table = $this->table( 'dexcom_connections' );
		$wpdb->update(
			$table,
			array( 'last_sync_at' => current_time( 'mysql', true ) ),
			array( 'user_id' => $user_id ),
			array( '%s' ),
			array( '%d' )
		);

		return array(
			'ok'       => true,
			'inserted' => $inserted,
			'message'  => sprintf( _n( '%d lettura sincronizzata.', '%d letture sincronizzate.', $inserted, 'sd-logbook' ), $inserted ),
		);
	}

	// =========================================================================
	// Cron
	// =========================================================================

	/**
	 * Sync automatico di tutti gli utenti con Dexcom abilitato.
	 */
	public function cron_sync_all(): void {
		global $wpdb;
		$table = $this->table( 'dexcom_connections' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT user_id FROM {$table} WHERE sync_enabled = 1" );
		foreach ( $rows as $row ) {
			$this->sync_user( (int) $row->user_id );
		}
	}

	/**
	 * Programma il cron Dexcom (se non già attivo).
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Rimuove il cron Dexcom.
	 */
	public static function unschedule_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	// =========================================================================
	// Handler AJAX
	// =========================================================================

	/**
	 * AJAX: salva le credenziali Dexcom.
	 */
	public function ajax_save_credentials(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$username = sanitize_text_field( wp_unslash( $_POST['dexcom_username'] ?? '' ) );
		$password = sanitize_text_field( wp_unslash( $_POST['dexcom_password'] ?? '' ) );
		$server   = sanitize_text_field( wp_unslash( $_POST['dexcom_server'] ?? 'ous' ) );
		$device   = sanitize_text_field( wp_unslash( $_POST['dexcom_device'] ?? '' ) );

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'Username e password sono obbligatori.', 'sd-logbook' ) ) );
		}

		$server = in_array( $server, array( 'us', 'ous' ), true ) ? $server : 'ous';

		$password_enc = $this->encrypt( $password );

		global $wpdb;
		$table = $this->table( 'dexcom_connections' );

		$existing = $this->get_connection( $user_id );
		$data     = array(
			'dexcom_username' => $username,
			'password_enc'    => $password_enc,
			'server'          => $server,
			'device_name'     => $device,
			'sync_enabled'    => 1,
			'session_id'      => null,
			'session_expires' => null,
		);

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'user_id' => $user_id ), null, array( '%d' ) );
		} else {
			$data['user_id'] = $user_id;
			$wpdb->insert( $table, $data );
		}

		wp_send_json_success( array( 'message' => __( 'Credenziali Dexcom salvate.', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX: testa la connessione Dexcom (login + fetch 1 lettura).
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$conn = $this->get_connection( $user_id );
		if ( ! $conn ) {
			wp_send_json_error( array( 'message' => __( 'Nessuna connessione Dexcom configurata.', 'sd-logbook' ) ) );
		}

		$password = $this->decrypt( $conn->password_enc );
		$login    = $this->dexcom_login( $conn->dexcom_username, $password, $conn->server );

		if ( is_wp_error( $login ) ) {
			wp_send_json_error( array( 'message' => $login->get_error_message() ) );
		}

		$readings = $this->dexcom_fetch_readings( $login, $conn->server, 60, 5 );

		if ( is_wp_error( $readings ) ) {
			wp_send_json_error( array( 'message' => $readings->get_error_message() ) );
		}

		$last = ! empty( $readings[0] ) ? $readings[0] : null;
		$msg  = __( 'Connessione Dexcom OK.', 'sd-logbook' );
		if ( $last ) {
			$msg .= sprintf(
				/* translators: %1$d=valore glucosio, %2$s=trend */
				__( ' Ultima lettura: %1$d mg/dL (%2$s).', 'sd-logbook' ),
				$last['Value'],
				$last['Trend'] ?? '—'
			);
		}

		wp_send_json_success( array( 'message' => $msg ) );
	}

	/**
	 * AJAX: sync manuale.
	 */
	public function ajax_manual_sync(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$result = $this->sync_user( $user_id );
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * AJAX: disconnetti account Dexcom.
	 */
	public function ajax_disconnect(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$table = $this->table( 'dexcom_connections' );
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );

		wp_send_json_success( array( 'message' => __( 'Account Dexcom disconnesso.', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX: restituisce le ultime letture Dexcom in formato JSON (per grafici).
	 */
	public function ajax_get_readings(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$table = $this->table( 'nightscout_readings' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT reading_time, glucose_value, direction, device
				 FROM {$table}
				 WHERE user_id = %d AND reading_type = 'sgv'
				   AND device LIKE %s
				 ORDER BY reading_time DESC
				 LIMIT 288",
				$user_id,
				'Dexcom%'
			)
		);

		wp_send_json_success( array( 'readings' => $rows ) );
	}

	// =========================================================================
	// Dati per il template
	// =========================================================================

	/**
	 * Restituisce tutti i dati Dexcom necessari al template profile.php.
	 *
	 * @param int $user_id ID utente WP.
	 * @return array
	 */
	public static function get_profile_data( int $user_id ): array {
		$instance = new self();
		$conn     = $instance->get_connection( $user_id );

		if ( ! $conn ) {
			return array(
				'connected'     => false,
				'username'      => '',
				'server'        => 'ous',
				'device_name'   => '',
				'sync_enabled'  => false,
				'last_sync_at'  => null,
				'last_glucose'  => null,
				'last_trend'    => null,
				'readings_count' => 0,
			);
		}

		global $wpdb;
		$t_read = $wpdb->prefix . 'sd_nightscout_readings';

		$last = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT glucose_value, direction, reading_time, device
				 FROM {$t_read}
				 WHERE user_id = %d AND device LIKE %s
				 ORDER BY reading_time DESC LIMIT 1",
				$user_id,
				'Dexcom%'
			)
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$t_read} WHERE user_id = %d AND device LIKE %s",
				$user_id,
				'Dexcom%'
			)
		);

		return array(
			'connected'      => true,
			'username'       => $conn->dexcom_username,
			'server'         => $conn->server,
			'device_name'    => $conn->device_name ?? '',
			'sync_enabled'   => (bool) $conn->sync_enabled,
			'last_sync_at'   => $conn->last_sync_at,
			'last_glucose'   => $last ? (int) $last->glucose_value : null,
			'last_trend'     => $last ? $last->direction : null,
			'last_read_time' => $last ? $last->reading_time : null,
			'readings_count' => $count,
		);
	}
}
