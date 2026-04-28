<?php
/**
 * Integrazione Tidepool API
 *
 * Permette agli utenti di collegare il proprio account Tidepool e importare
 * automaticamente le letture CGM (CBG) tramite le API REST di Tidepool.
 *
 * Documentazione: https://developer.tidepool.org
 * API base: https://api.tidepool.org
 *
 * @package ScubaDiabetes_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SD_Tidepool
 *
 * Gestisce l'autenticazione con Tidepool e il sync automatico
 * delle letture CGM (tipo "cbg") verso la tabella sd_nightscout_readings (condivisa).
 */
class SD_Tidepool {

	/** @var string Base URL API Tidepool */
	const API_BASE = 'https://api.tidepool.org';

	/** @var string Endpoint login */
	const LOGIN_PATH = '/auth/login';

	/** @var string Endpoint dati corrente */
	const DATA_PATH = '/data/';

	/** @var string Fattore conversione mmol/L → mg/dL */
	const MMOL_TO_MGDL = 18.01559;

	/** @var string Hook cron per sync automatico */
	const CRON_HOOK = 'sd_tidepool_sync_cron';

	/** @var int Ore di storico da recuperare per ogni sync */
	const SYNC_HOURS = 24;

	/** @var string Prefisso tabelle DB */
	private string $prefix;

	public function __construct() {
		global $wpdb;
		$this->prefix = $wpdb->prefix . 'sd_';

		// AJAX autenticato
		add_action( 'wp_ajax_sd_tidepool_save', array( $this, 'ajax_save_credentials' ) );
		add_action( 'wp_ajax_sd_tidepool_test', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_sd_tidepool_sync', array( $this, 'ajax_manual_sync' ) );
		add_action( 'wp_ajax_sd_tidepool_disconnect', array( $this, 'ajax_disconnect' ) );

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
		$iv    = base64_decode( $iv_b64 );
		$key   = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
		return $plain ?: '';
	}

	/**
	 * Mappa il trend Tidepool (stringa lowercase) verso il formato Nightscout.
	 */
	private function map_trend( string $trend ): string {
		$map = array(
			'doubleUp'      => 'DoubleUp',
			'singleUp'      => 'SingleUp',
			'fortyFiveUp'   => 'FortyFiveUp',
			'flat'          => 'Flat',
			'fortyFiveDown' => 'FortyFiveDown',
			'singleDown'    => 'SingleDown',
			'doubleDown'    => 'DoubleDown',
		);
		return $map[ $trend ] ?? 'NONE';
	}

	// =========================================================================
	// Comunicazione con le API Tidepool
	// =========================================================================

	/**
	 * Effettua il login su Tidepool e restituisce [session_token, user_id].
	 *
	 * @param string $email    Email account Tidepool.
	 * @param string $password Password account Tidepool.
	 * @return array{token:string, user_id:string}|WP_Error
	 */
	public function tidepool_login( string $email, string $password ) {
		$url = self::API_BASE . self::LOGIN_PATH;

		// Basic Auth: base64(email:password)
		$credentials = base64_encode( $email . ':' . $password );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . $credentials,
					'Content-Type'  => 'application/json',
				),
				'body'    => '',
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
			return new WP_Error( 'tidepool_login_failed', sprintf( __( 'Login Tidepool fallito (HTTP %1$d): %2$s', 'sd-logbook' ), $code, $raw ) );
		}

		// Il token è nell'header di risposta
		$token = wp_remote_retrieve_header( $response, 'x-tidepool-session-token' );

		// L'user ID è nel corpo JSON
		$body    = json_decode( $raw, true );
		$user_id = $body['userid'] ?? '';

		if ( empty( $token ) || empty( $user_id ) ) {
			return new WP_Error( 'tidepool_bad_session', __( 'Risposta Tidepool non valida: token o user ID mancante.', 'sd-logbook' ) );
		}

		return array(
			'token'   => $token,
			'user_id' => $user_id,
		);
	}

	/**
	 * Recupera le letture CBG (CGM) di un utente da Tidepool.
	 *
	 * @param string $session_token Token di sessione Tidepool.
	 * @param string $tidepool_user_id ID utente Tidepool.
	 * @param int    $hours           Ore di storico da recuperare.
	 * @return array|WP_Error Array letture CBG oppure WP_Error.
	 */
	public function tidepool_fetch_cbg( string $session_token, string $tidepool_user_id, int $hours = self::SYNC_HOURS ) {
		$start_date = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - $hours * HOUR_IN_SECONDS );
		$url        = self::API_BASE . self::DATA_PATH . rawurlencode( $tidepool_user_id );
		$url       .= '?' . http_build_query(
			array(
				'type'      => 'cbg',
				'startDate' => $start_date,
			)
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'x-tidepool-session-token' => $session_token,
					'Content-Type'             => 'application/json',
				),
				'timeout'   => 30,
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
			return new WP_Error( 'tidepool_fetch_failed', sprintf( __( 'Letture Tidepool fallite (HTTP %1$d): %2$s', 'sd-logbook' ), $code, $raw ) );
		}

		$readings = json_decode( $raw, true );
		if ( ! is_array( $readings ) ) {
			return new WP_Error( 'tidepool_bad_response', __( 'Risposta Tidepool non valida.', 'sd-logbook' ) );
		}

		return $readings;
	}

	// =========================================================================
	// Accesso connessione in DB
	// =========================================================================

	/**
	 * Recupera la connessione Tidepool di un utente dal DB.
	 *
	 * @param int $user_id ID utente WP.
	 * @return object|null Riga DB oppure null.
	 */
	public function get_connection( int $user_id ) {
		global $wpdb;
		$table = $this->table( 'tidepool_connections' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
	}

	/**
	 * Login + fetch full flow: riusa session_token cache se non scaduto.
	 *
	 * @param object $conn Riga connessione DB.
	 * @return array{token:string, readings:array}|WP_Error
	 */
	private function login_and_fetch( object $conn ) {
		global $wpdb;

		$email    = $conn->tidepool_email;
		$password = $this->decrypt( $conn->password_enc );

		if ( empty( $password ) ) {
			return new WP_Error( 'tidepool_decrypt_failed', __( 'Impossibile decifrare la password Tidepool.', 'sd-logbook' ) );
		}

		// Controlla cache session_token (Tidepool scade dopo ~1h, usiamo 55min)
		$token           = null;
		$tidepool_userid = $conn->tidepool_user_id ?? '';

		if ( ! empty( $conn->session_token ) && ! empty( $conn->session_expires ) && ! empty( $tidepool_userid ) ) {
			$expires = strtotime( $conn->session_expires );
			if ( $expires && $expires > time() + 60 ) {
				$token = $conn->session_token;
			}
		}

		if ( null === $token ) {
			$login = $this->tidepool_login( $email, $password );
			if ( is_wp_error( $login ) ) {
				return $login;
			}
			$token           = $login['token'];
			$tidepool_userid = $login['user_id'];

			// Salva in cache (scade fra 55 minuti)
			$table = $this->table( 'tidepool_connections' );
			$wpdb->update(
				$table,
				array(
					'session_token'   => $token,
					'session_expires' => gmdate( 'Y-m-d H:i:s', time() + 55 * MINUTE_IN_SECONDS ),
					'tidepool_user_id' => $tidepool_userid,
				),
				array( 'user_id' => (int) $conn->user_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		$readings = $this->tidepool_fetch_cbg( $token, $tidepool_userid );

		// Se sessione scaduta, ri-autentica una volta
		if ( is_wp_error( $readings ) ) {
			$login = $this->tidepool_login( $email, $password );
			if ( is_wp_error( $login ) ) {
				return $login;
			}
			$token           = $login['token'];
			$tidepool_userid = $login['user_id'];
			$readings        = $this->tidepool_fetch_cbg( $token, $tidepool_userid );
		}

		if ( is_wp_error( $readings ) ) {
			return $readings;
		}

		return array(
			'token'    => $token,
			'readings' => $readings,
		);
	}

	// =========================================================================
	// Salvataggio letture nel DB
	// =========================================================================

	/**
	 * Converte e salva le letture CBG Tidepool nella tabella sd_nightscout_readings.
	 *
	 * @param int    $user_id  ID utente WP.
	 * @param array  $readings Array letture CBG da Tidepool.
	 * @param string $device   Nome dispositivo (es. "Tidepool CGM").
	 * @return int Numero letture inserite/aggiornate.
	 */
	private function save_readings( int $user_id, array $readings, string $device = 'Tidepool' ): int {
		global $wpdb;
		$table    = $this->table( 'nightscout_readings' );
		$inserted = 0;

		foreach ( $readings as $r ) {
			if ( empty( $r['time'] ) || ! isset( $r['value'] ) ) {
				continue;
			}

			// Il valore può essere in mmol/L o mg/dL
			$units = $r['units'] ?? 'mmol/L';
			if ( 'mmol/L' === $units || 'mmol/l' === $units ) {
				$glucose = (int) round( (float) $r['value'] * self::MMOL_TO_MGDL );
			} else {
				$glucose = (int) round( (float) $r['value'] );
			}

			if ( $glucose <= 0 || $glucose > 600 ) {
				continue;
			}

			// Timestamp ISO 8601 → datetime MySQL
			$ts           = strtotime( $r['time'] );
			$reading_time = gmdate( 'Y-m-d H:i:s', $ts );
			$trend        = $this->map_trend( $r['trend'] ?? '' );

			// Device da payload Tidepool (deviceId o payload.deviceInfo)
			$device_name = ! empty( $r['deviceId'] ) ? sanitize_text_field( $r['deviceId'] ) : $device;

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
					$device_name
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
	 * Esegue il sync Tidepool per un singolo utente.
	 *
	 * @param int $user_id ID utente WP.
	 * @return array{ok:bool, inserted:int, message:string}
	 */
	public function sync_user( int $user_id ): array {
		global $wpdb;

		$conn = $this->get_connection( $user_id );
		if ( ! $conn || ! $conn->sync_enabled ) {
			return array(
				'ok'       => false,
				'inserted' => 0,
				'message'  => 'Sync disabilitato o nessuna connessione.',
			);
		}

		$result = $this->login_and_fetch( $conn );
		if ( is_wp_error( $result ) ) {
			return array(
				'ok'       => false,
				'inserted' => 0,
				'message'  => $result->get_error_message(),
			);
		}

		$inserted = $this->save_readings( $user_id, $result['readings'], 'Tidepool' );

		// Aggiorna last_sync_at
		$table = $this->table( 'tidepool_connections' );
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
	 * Sync automatico di tutti gli utenti con Tidepool abilitato.
	 */
	public function cron_sync_all(): void {
		global $wpdb;
		$table = $this->table( 'tidepool_connections' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT user_id FROM {$table} WHERE sync_enabled = 1" );
		foreach ( $rows as $row ) {
			$this->sync_user( (int) $row->user_id );
		}
	}

	/**
	 * Programma il cron Tidepool (se non già attivo).
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Rimuove il cron Tidepool.
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
	 * AJAX: salva le credenziali Tidepool.
	 */
	public function ajax_save_credentials(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$email    = sanitize_email( wp_unslash( $_POST['tidepool_email'] ?? '' ) );
		$password = sanitize_text_field( wp_unslash( $_POST['tidepool_password'] ?? '' ) );

		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Inserisci un indirizzo email valido.', 'sd-logbook' ) ) );
		}

		if ( empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'La password è obbligatoria.', 'sd-logbook' ) ) );
		}

		$password_enc = $this->encrypt( $password );

		global $wpdb;
		$table    = $this->table( 'tidepool_connections' );
		$existing = $this->get_connection( $user_id );

		$data = array(
			'tidepool_email'  => $email,
			'password_enc'    => $password_enc,
			'sync_enabled'    => 1,
			'session_token'   => null,
			'session_expires' => null,
			'tidepool_user_id' => null,
		);

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'user_id' => $user_id ), null, array( '%d' ) );
		} else {
			$data['user_id'] = $user_id;
			$wpdb->insert( $table, $data );
		}

		wp_send_json_success( array( 'message' => __( 'Credenziali Tidepool salvate.', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX: testa la connessione Tidepool (login + fetch 1 lettura).
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$conn = $this->get_connection( $user_id );
		if ( ! $conn ) {
			wp_send_json_error( array( 'message' => __( 'Nessuna connessione Tidepool configurata.', 'sd-logbook' ) ) );
		}

		$password = $this->decrypt( $conn->password_enc );
		$login    = $this->tidepool_login( $conn->tidepool_email, $password );

		if ( is_wp_error( $login ) ) {
			wp_send_json_error( array( 'message' => $login->get_error_message() ) );
		}

		$readings = $this->tidepool_fetch_cbg( $login['token'], $login['user_id'], 1 );

		if ( is_wp_error( $readings ) ) {
			wp_send_json_error( array( 'message' => $readings->get_error_message() ) );
		}

		$last = ! empty( $readings ) ? $readings[ count( $readings ) - 1 ] : null;
		$msg  = __( 'Connessione Tidepool OK.', 'sd-logbook' );

		if ( $last ) {
			$units = $last['units'] ?? 'mmol/L';
			if ( 'mmol/L' === $units || 'mmol/l' === $units ) {
				$glucose = (int) round( (float) $last['value'] * self::MMOL_TO_MGDL );
			} else {
				$glucose = (int) round( (float) $last['value'] );
			}
			$msg .= sprintf(
				/* translators: %d=valore glucosio mg/dL */
				__( ' Ultima lettura disponibile: %d mg/dL.', 'sd-logbook' ),
				$glucose
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
	 * AJAX: disconnetti account Tidepool.
	 */
	public function ajax_disconnect(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$table = $this->table( 'tidepool_connections' );
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );

		wp_send_json_success( array( 'message' => __( 'Account Tidepool disconnesso.', 'sd-logbook' ) ) );
	}

	// =========================================================================
	// Dati per il template
	// =========================================================================

	/**
	 * Restituisce tutti i dati Tidepool necessari al template profile.php.
	 *
	 * @param int $user_id ID utente WP.
	 * @return array
	 */
	public static function get_profile_data( int $user_id ): array {
		$instance = new self();
		$conn     = $instance->get_connection( $user_id );

		if ( ! $conn ) {
			return array(
				'connected'      => false,
				'email'          => '',
				'sync_enabled'   => false,
				'last_sync_at'   => null,
				'last_glucose'   => null,
				'last_trend'     => null,
				'last_read_time' => null,
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
				'Tidepool%'
			)
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$t_read} WHERE user_id = %d AND device LIKE %s",
				$user_id,
				'Tidepool%'
			)
		);

		return array(
			'connected'      => true,
			'email'          => $conn->tidepool_email,
			'sync_enabled'   => (bool) $conn->sync_enabled,
			'last_sync_at'   => $conn->last_sync_at,
			'last_glucose'   => $last ? (int) $last->glucose_value : null,
			'last_trend'     => $last ? $last->direction : null,
			'last_read_time' => $last ? $last->reading_time : null,
			'readings_count' => $count,
		);
	}
}
