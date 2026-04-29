<?php
/**
 * Integrazione LibreView (Abbott FreeStyle Libre) — LibreLinkUp API
 *
 * Permette agli utenti di collegare il proprio account LibreView e importare
 * automaticamente le letture CGM tramite le API LibreLinkUp di Abbott.
 *
 * Documentazione: https://libreview-unofficial.stoplight.io
 * API base: https://api.libreview.io  (con redirect regionale automatico)
 *
 * Flusso:
 *   1. Utente inserisce email + password LibreView nel profilo
 *   2. Login → API restituisce token + eventuale redirect regionale
 *   3. Token cifrato AES-256-CBC salvato in DB
 *   4. Cron orario importa letture nelle tabelle locali
 *   5. Il form immersione legge i dati locali per pre-compilare i campi glicemici
 *
 * @package SD_Logbook
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_LibreView {

	// =========================================================================
	// Costanti
	// =========================================================================

	/** @var string Base URL API LibreView (globale, risponde con redirect regionale) */
	const API_BASE_GLOBAL = 'https://api.libreview.io';

	/** @var string Base URL API LibreView EU */
	const API_BASE_EU = 'https://api-eu.libreview.io';

	/** @var string Base URL API LibreView US */
	const API_BASE_US = 'https://api-us.libreview.io';

	/** @var string Endpoint login */
	const LOGIN_PATH = '/llu/auth/login';

	/** @var string Endpoint lista connessioni (pazienti associati) */
	const CONNECTIONS_PATH = '/llu/connections';

	/** @var string Endpoint grafico letture */
	const GRAPH_PATH = '/llu/connections/{patientId}/graph';

	/** @var string Prodotto simulato per le API Abbott */
	const PRODUCT = 'llu.android';

	/** @var string Versione app simulata */
	const APP_VERSION = '4.16.0';

	/** @var string Hook cron per sync automatico */
	const CRON_HOOK = 'sd_libreview_sync_cron';

	/** @var int Ore di storico da recuperare per ogni sync */
	const SYNC_HOURS = 24;

	/** @var string Prefisso tabelle DB */
	private string $prefix;

	// =========================================================================
	// Costruttore
	// =========================================================================

	public function __construct() {
		global $wpdb;
		$this->prefix = $wpdb->prefix . 'sd_';

		// AJAX autenticato
		add_action( 'wp_ajax_sd_libreview_save', array( $this, 'ajax_save_credentials' ) );
		add_action( 'wp_ajax_sd_libreview_test', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_sd_libreview_sync', array( $this, 'ajax_manual_sync' ) );
		add_action( 'wp_ajax_sd_libreview_disconnect', array( $this, 'ajax_disconnect' ) );

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
	 * Costruisce gli header HTTP comuni per le API LibreLinkUp.
	 *
	 * @param string|null $token Bearer token (null per la richiesta di login).
	 * @return array<string, string>
	 */
	private function build_headers( ?string $token = null, ?string $account_id = null ): array {
		$headers = array(
			'Content-Type'    => 'application/json',
			'Accept-Encoding' => 'gzip',
			'Connection'      => 'Keep-Alive',
			'product'         => self::PRODUCT,
			'version'         => self::APP_VERSION,
			'Cache-Control'   => 'no-cache',
			'pragma'          => 'no-cache',
		);
		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		if ( $account_id ) {
			$headers['account-id'] = $account_id;
		}
		return $headers;
	}

	/**
	 * Estrae il campo "sub" (subject = account-id) dal payload JWT senza validare la firma.
	 *
	 * Il claim "sub" è la fonte autorevole per l'header account-id: corrisponde sempre
	 * all'account per cui il token è stato emesso.
	 *
	 * @param string $token Bearer JWT.
	 * @return string UUID account oppure stringa vuota.
	 */
	private function jwt_sub( string $token ): string {
		$payload = $this->jwt_payload( $token );
		return isset( $payload['sub'] ) ? trim( (string) $payload['sub'] ) : '';
	}

	/**
	 * Decodifica e restituisce il payload JWT come array associativo.
	 *
	 * @param string $token Bearer JWT.
	 * @return array<string, mixed>
	 */
	private function jwt_payload( string $token ): array {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return array();
		}
		$b64 = strtr( $parts[1], '-_', '+/' );
		$b64 = str_pad( $b64, (int) ceil( strlen( $b64 ) / 4 ) * 4, '=', STR_PAD_RIGHT );
		$payload_raw = base64_decode( $b64 );
		if ( false === $payload_raw ) {
			return array();
		}
		$payload = json_decode( $payload_raw, true );
		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Mappa il TrendArrow numerico LibreView verso il formato Nightscout.
	 *
	 * @param int $arrow Valore TrendArrow (1–7).
	 * @return string Stringa direzione Nightscout.
	 */
	private function map_trend( int $arrow ): string {
		$map = array(
			1 => 'DoubleDown',
			2 => 'SingleDown',
			3 => 'FortyFiveDown',
			4 => 'Flat',
			5 => 'FortyFiveUp',
			6 => 'SingleUp',
			7 => 'DoubleUp',
		);
		return $map[ $arrow ] ?? 'NONE';
	}

	/**
	 * Converte il timestamp stringa LibreView (es. "4/29/2026 10:00:00 AM") in epoch UTC.
	 *
	 * LibreView restituisce timestamp nel fuso orario UTC senza indicazione esplicita.
	 *
	 * @param string $ts Timestamp LibreView.
	 * @return int|false Unix timestamp oppure false.
	 */
	private function parse_timestamp( string $ts ) {
		// Formato atteso: M/D/YYYY H:i:s AM/PM
		$dt = \DateTime::createFromFormat( 'n/j/Y g:i:s A', $ts, new \DateTimeZone( 'UTC' ) );
		if ( $dt ) {
			return $dt->getTimestamp();
		}
		// Fallback
		return strtotime( $ts );
	}

	// =========================================================================
	// Comunicazione con le API LibreLinkUp
	// =========================================================================

	/**
	 * Effettua il login su LibreView e restituisce [token, region].
	 *
	 * Se l'API risponde con redirect=true, ritorna anche la region da usare
	 * per le chiamate successive. Il chiamante deve aggiornare il base_url.
	 *
	 * @param string $email    Email account LibreView.
	 * @param string $password Password account LibreView.
	 * @param string $base_url Base URL da usare (default: globale).
	 * @return array{token:string, expires:int, region:string, base_url:string}|WP_Error
	 */
	public function libreview_login( string $email, string $password, string $base_url = self::API_BASE_GLOBAL ) {
		$url  = $base_url . self::LOGIN_PATH;
		$body = wp_json_encode(
			array(
				'email'    => $email,
				'password' => $password,
			)
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers'   => $this->build_headers(),
				'body'      => $body,
				'timeout'   => 20,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== (int) $code || ! isset( $data['status'] ) ) {
			/* translators: %1$d=HTTP code, %2$s=body */
			return new WP_Error(
				'libreview_login_failed',
				sprintf( __( 'Login LibreView fallito (HTTP %1$d): %2$s', 'sd-logbook' ), $code, $raw )
			);
		}

		// Errore applicativo (status != 0)
		if ( 0 !== (int) $data['status'] ) {
			$status = (int) $data['status'];
			// Status 4 = Terms of Service LibreLinkUp non accettati (ToS specifici dell'app LibreLinkUp,
			// separati da quelli di LibreView web). L'utente deve aprire l'app LibreLinkUp sul telefono,
			// effettuare il login e accettare i termini. Se non ha l'app: installarla da App Store/Google Play.
			if ( 4 === $status ) {
				return new WP_Error(
					'libreview_tos_required',
					__( 'Devi accettare i Termini di Servizio dell\'app LibreLinkUp (Abbott). Installa l\'app "LibreLinkUp" su iOS/Android, accedi con le stesse credenziali e accetta i termini, quindi riprova.', 'sd-logbook' )
				);
			}
			// Estrae il messaggio dal percorso più comune, poi fallback
			$msg = $data['error']['message']
				?? $data['errors']['message']
				?? $data['message']
				?? sprintf(
					/* translators: %d=status code API */
					__( 'Errore API LibreView (status %d). Verifica email e password.', 'sd-logbook' ),
					$status
				);
			return new WP_Error( 'libreview_login_error', $msg );
		}

		// Redirect regionale: ripete il login sull'endpoint corretto
		if ( ! empty( $data['data']['redirect'] ) && true === $data['data']['redirect'] ) {
			$region  = strtolower( $data['data']['region'] ?? 'eu' );
			$new_url = $this->region_to_base_url( $region );
			return $this->libreview_login( $email, $password, $new_url );
		}

		$ticket  = $data['data']['authTicket'] ?? array();
		$token   = $ticket['token'] ?? '';
		$expires = isset( $ticket['expires'] ) ? (int) $ticket['expires'] : ( time() + 3600 );
		$region  = strtolower( $data['data']['region'] ?? '' );

		if ( empty( $token ) ) {
			return new WP_Error( 'libreview_bad_token', __( 'Token LibreView non ricevuto.', 'sd-logbook' ) );
		}

		// L'account-id richiesto da Abbott è il claim "sub" del JWT token.
		// SHA-256 dell'email era usato in vecchie implementazioni DIY ma causa AccountIdMismatch.
		$account_id = $this->jwt_sub( $token );
		if ( empty( $account_id ) ) {
			// Fallback: SHA-256 dell'email (usato da alcune implementazioni DIY)
			$account_id = hash( 'sha256', strtolower( $email ) );
		}

		return array(
			'token'      => $token,
			'expires'    => $expires,
			'region'     => $region,
			'base_url'   => $base_url,
			'account_id' => $account_id,
		);
	}

	/**
	 * Mappa region string → base URL.
	 *
	 * @param string $region Codice regione (es. 'eu', 'us', 'de', 'fr' …).
	 * @return string URL base.
	 */
	private function region_to_base_url( string $region ): string {
		$us_regions = array( 'us', 'ca' );
		if ( in_array( $region, $us_regions, true ) ) {
			return self::API_BASE_US;
		}
		return self::API_BASE_EU;
	}

	/**
	 * Recupera la lista connessioni LibreLinkUp (pazienti/dispositivi dell'utente).
	 *
	 * @param string $token    Bearer token.
	 * @param string $base_url Base URL regionale.
	 * @return array|WP_Error Array connessioni oppure WP_Error.
	 */
	public function libreview_get_connections( string $token, string $base_url, string $account_id = '' ) {
		$url = $base_url . self::CONNECTIONS_PATH;

		$response = wp_remote_get(
			$url,
			array(
				'headers'   => $this->build_headers( $token, $account_id ),
				'timeout'   => 20,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== (int) $code ) {
			/* translators: %1$d=HTTP code, %2$s=body */
			return new WP_Error(
				'libreview_connections_failed',
				sprintf( __( 'Connessioni LibreView non recuperabili (HTTP %1$d): %2$s', 'sd-logbook' ), $code, $raw )
			);
		}

		return $data['data'] ?? array();
	}

	/**
	 * Recupera le letture CGM dal grafico di una connessione.
	 *
	 * @param string $token      Bearer token.
	 * @param string $base_url   Base URL regionale.
	 * @param string $patient_id ID paziente (da connections).
	 * @return array{connection: array, graphData: array}|WP_Error
	 */
	public function libreview_fetch_graph( string $token, string $base_url, string $patient_id, string $account_id = '' ) {
		$path = str_replace( '{patientId}', rawurlencode( $patient_id ), self::GRAPH_PATH );
		$url  = $base_url . $path;

		$response = wp_remote_get(
			$url,
			array(
				'headers'   => $this->build_headers( $token, $account_id ),
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== (int) $code ) {
			/* translators: %1$d=HTTP code, %2$s=body */
			return new WP_Error(
				'libreview_graph_failed',
				sprintf( __( 'Letture LibreView non recuperabili (HTTP %1$d): %2$s', 'sd-logbook' ), $code, $raw )
			);
		}

		return $data['data'] ?? array();
	}

	// =========================================================================
	// Accesso connessione in DB
	// =========================================================================

	/**
	 * Recupera la connessione LibreView di un utente dal DB.
	 *
	 * @param int $user_id ID utente WP.
	 * @return object|null Riga DB oppure null.
	 */
	public function get_connection( int $user_id ) {
		global $wpdb;
		$table = $this->table( 'libreview_connections' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
	}

	// =========================================================================
	// Salvataggio letture nel DB
	// =========================================================================

	/**
	 * Converte e salva le letture CGM LibreView nella tabella sd_nightscout_readings.
	 *
	 * @param int    $user_id  ID utente WP.
	 * @param array  $readings Array letture (format graphData LibreView).
	 * @param string $device   Nome dispositivo (es. "FreeStyle Libre 3").
	 * @return int Numero letture inserite/aggiornate.
	 */
	private function save_readings( int $user_id, array $readings, string $device = 'FreeStyle Libre' ): int {
		global $wpdb;
		$table    = $this->table( 'nightscout_readings' );
		$inserted = 0;

		foreach ( $readings as $r ) {
			if ( empty( $r['Timestamp'] ) || ! isset( $r['Value'] ) ) {
				continue;
			}

			// LibreView restituisce mg/dL direttamente
			$glucose = (int) round( (float) $r['Value'] );
			if ( $glucose <= 0 || $glucose > 600 ) {
				continue;
			}

			$ts           = $this->parse_timestamp( $r['Timestamp'] );
			if ( ! $ts ) {
				continue;
			}
			$reading_time = gmdate( 'Y-m-d H:i:s', $ts );
			$trend        = $this->map_trend( (int) ( $r['TrendArrow'] ?? 0 ) );

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
					sanitize_text_field( $device )
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
	 * Ottiene o rinnova il token LibreView per una connessione.
	 * Usa la cache se il token non è scaduto.
	 *
	 * @param object $conn Riga connessione DB.
	 * @return array{token:string, base_url:string}|WP_Error
	 */
	private function get_or_refresh_token( object $conn ) {
		global $wpdb;

		$password = $this->decrypt( $conn->password_enc );
		if ( empty( $password ) ) {
			return new WP_Error( 'libreview_decrypt_failed', __( 'Impossibile decifrare la password LibreView.', 'sd-logbook' ) );
		}

		// Usa token in cache se ancora valido (con 5 minuti di margine)
		if (
			! empty( $conn->auth_token ) &&
			! empty( $conn->token_expires ) &&
			strtotime( $conn->token_expires ) > time() + 300
		) {
			// Usa account_id salvato in DB (estratto dal JWT sub al momento del login)
			$account_id = ! empty( $conn->account_id ) ? $conn->account_id : $this->jwt_sub( $conn->auth_token );
			if ( empty( $account_id ) ) {
				$account_id = hash( 'sha256', strtolower( $conn->libreview_email ) );
			}
			return array(
				'token'      => $conn->auth_token,
				'base_url'   => $conn->api_base_url ?: self::API_BASE_EU,
				'account_id' => $account_id,
			);
		}

		// Rinnova token via login
		$base = $conn->api_base_url ?: self::API_BASE_GLOBAL;
		$login = $this->libreview_login( $conn->libreview_email, $password, $base );

		if ( is_wp_error( $login ) ) {
			return $login;
		}

		// Persiste token + nuova base URL + account_id
		$table = $this->table( 'libreview_connections' );
		$wpdb->update(
			$table,
			array(
				'auth_token'    => $login['token'],
				'token_expires' => gmdate( 'Y-m-d H:i:s', $login['expires'] ),
				'api_base_url'  => $login['base_url'],
				'account_id'    => $login['account_id'],
			),
			array( 'user_id' => (int) $conn->user_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'token'      => $login['token'],
			'base_url'   => $login['base_url'],
			'account_id' => $login['account_id'],
		);
	}

	/**
	 * Esegue il sync LibreView per un singolo utente.
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
				'message'  => __( 'Sync disabilitato o nessuna connessione LibreView.', 'sd-logbook' ),
			);
		}

		$auth = $this->get_or_refresh_token( $conn );
		if ( is_wp_error( $auth ) ) {
			return array(
				'ok'       => false,
				'inserted' => 0,
				'message'  => $auth->get_error_message(),
			);
		}

		$token      = $auth['token'];
		$base_url   = $auth['base_url'];
		$account_id = $auth['account_id'] ?? '';

		// Recupera lista connessioni (pazienti/dispositivi)
		$connections = $this->libreview_get_connections( $token, $base_url, $account_id );
		if ( is_wp_error( $connections ) || empty( $connections ) ) {
			return array(
				'ok'       => false,
				'inserted' => 0,
				'message'  => is_wp_error( $connections )
					? $connections->get_error_message()
					: __( 'Nessun dispositivo LibreView associato all\'account.', 'sd-logbook' ),
			);
		}

		$total_inserted = 0;

		foreach ( $connections as $patient ) {
			$patient_id = $patient['patientId'] ?? '';
			if ( empty( $patient_id ) ) {
				continue;
			}

			$device_name = $patient['sensor']['sn'] ?? ( $patient['firstName'] ?? 'FreeStyle Libre' );
			$device_name = 'LibreView/' . sanitize_text_field( $device_name );

			$graph = $this->libreview_fetch_graph( $token, $base_url, $patient_id, $account_id );
			if ( is_wp_error( $graph ) ) {
				continue;
			}

			// graphData = storico letture; connection.glucoseMeasurement = ultima lettura
			$readings = $graph['graphData'] ?? array();

			// Aggiunge l'ultima lettura corrente se non è già nel grafico
			$current = $graph['connection']['glucoseMeasurement'] ?? null;
			if ( $current && isset( $current['Timestamp'] ) ) {
				$readings[] = $current;
			}

			if ( ! empty( $readings ) ) {
				$total_inserted += $this->save_readings( $user_id, $readings, $device_name );
			}
		}

		// Aggiorna last_sync_at
		$table = $this->table( 'libreview_connections' );
		$wpdb->update(
			$table,
			array( 'last_sync_at' => current_time( 'mysql', true ) ),
			array( 'user_id' => $user_id ),
			array( '%s' ),
			array( '%d' )
		);

		return array(
			'ok'       => true,
			'inserted' => $total_inserted,
			'message'  => sprintf(
				_n( '%d lettura sincronizzata da LibreView.', '%d letture sincronizzate da LibreView.', $total_inserted, 'sd-logbook' ),
				$total_inserted
			),
		);
	}

	// =========================================================================
	// Cron
	// =========================================================================

	/**
	 * Sync automatico di tutti gli utenti con LibreView abilitato.
	 */
	public function cron_sync_all(): void {
		global $wpdb;
		$table = $this->table( 'libreview_connections' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT user_id FROM {$table} WHERE sync_enabled = 1" );
		foreach ( $rows as $row ) {
			$this->sync_user( (int) $row->user_id );
		}
	}

	/**
	 * Programma il cron LibreView (se non già attivo).
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Rimuove il cron LibreView.
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
	 * AJAX: salva le credenziali LibreView.
	 */
	public function ajax_save_credentials(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$email    = sanitize_email( wp_unslash( $_POST['libreview_email'] ?? '' ) );
		// Le password non vanno sanificate con sanitize_text_field (muta caratteri speciali)
		$password = wp_unslash( $_POST['libreview_password'] ?? '' );
		// Rimuoviamo solo caratteri di controllo (NUL, tab, newline) che non fanno parte di password reali
		$password = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $password );

		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Inserisci un indirizzo email valido.', 'sd-logbook' ) ) );
		}

		if ( empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'La password è obbligatoria.', 'sd-logbook' ) ) );
		}

		$password_enc = $this->encrypt( $password );

		global $wpdb;
		$table    = $this->table( 'libreview_connections' );
		$existing = $this->get_connection( $user_id );

		$data = array(
			'libreview_email' => $email,
			'password_enc'    => $password_enc,
			'sync_enabled'    => 1,
			'auth_token'      => null,
			'token_expires'   => null,
			'api_base_url'    => null,
			'account_id'      => null,
		);

		if ( $existing ) {
			$result = $wpdb->update( $table, $data, array( 'user_id' => $user_id ), null, array( '%d' ) );
		} else {
			$data['user_id'] = $user_id;
			$result = $wpdb->insert( $table, $data );
		}

		if ( false === $result ) {
			wp_send_json_error(
				array(
					'message' => __( 'Errore nel salvataggio delle credenziali. La tabella LibreView potrebbe non essere stata creata: riattiva il plugin dalle impostazioni WordPress.', 'sd-logbook' ),
				)
			);
		}

		wp_send_json_success( array( 'message' => __( 'Credenziali LibreView salvate.', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX: testa la connessione LibreView (login + recupero connessioni).
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		// Prova prima con credenziali dal form (test prima di salvare), poi dal DB
		$post_email    = sanitize_email( wp_unslash( $_POST['libreview_email'] ?? '' ) );
		$post_password = wp_unslash( $_POST['libreview_password'] ?? '' );
		$post_password = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $post_password );

		if ( ! empty( $post_email ) && ! empty( $post_password ) ) {
			$test_email    = $post_email;
			$test_password = $post_password;
		} else {
			$conn = $this->get_connection( $user_id );
			if ( ! $conn ) {
				wp_send_json_error( array( 'message' => __( 'Nessuna connessione LibreView configurata.', 'sd-logbook' ) ) );
			}
			$test_email    = $conn->libreview_email;
			$test_password = $this->decrypt( $conn->password_enc );
		}

		$login = $this->libreview_login( $test_email, $test_password );

		if ( is_wp_error( $login ) ) {
			wp_send_json_error( array( 'message' => $login->get_error_message() ) );
		}

		$connections = $this->libreview_get_connections( $login['token'], $login['base_url'], $login['account_id'] ?? '' );
		if ( is_wp_error( $connections ) ) {
			wp_send_json_error( array( 'message' => $connections->get_error_message() ) );
		}

		$n_devices = is_array( $connections ) ? count( $connections ) : 0;

		if ( 0 === $n_devices ) {
			wp_send_json_success(
				array(
					'message' => __( 'Connessione LibreView OK. Nessun sensore attivo trovato nell\'account.', 'sd-logbook' ),
				)
			);
			return;
		}

		// Leggi ultima lettura dal primo dispositivo
		$first_patient = $connections[0];
		$patient_id    = $first_patient['patientId'] ?? '';
		$last_msg      = '';

		if ( $patient_id ) {
			$graph = $this->libreview_fetch_graph( $login['token'], $login['base_url'], $patient_id, $login['account_id'] ?? '' );
			if ( ! is_wp_error( $graph ) ) {
				$current = $graph['connection']['glucoseMeasurement'] ?? null;
				if ( $current && isset( $current['Value'] ) ) {
					$last_msg = sprintf(
						/* translators: %d=valore glucosio mg/dL */
						__( ' Ultima lettura: %d mg/dL.', 'sd-logbook' ),
						(int) $current['Value']
					);
				}
			}
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d=numero sensori */
					_n(
						'Connessione LibreView OK. %d sensore trovato.',
						'Connessione LibreView OK. %d sensori trovati.',
						$n_devices,
						'sd-logbook'
					),
					$n_devices
				) . $last_msg,
			)
		);
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
	 * AJAX: disconnetti account LibreView.
	 */
	public function ajax_disconnect(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$table = $this->table( 'libreview_connections' );
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );

		wp_send_json_success( array( 'message' => __( 'Account LibreView disconnesso.', 'sd-logbook' ) ) );
	}

	// =========================================================================
	// Dati per il template
	// =========================================================================

	/**
	 * Restituisce tutti i dati LibreView necessari al template profile.php.
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
				'LibreView%'
			)
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$t_read} WHERE user_id = %d AND device LIKE %s",
				$user_id,
				'LibreView%'
			)
		);

		return array(
			'connected'      => true,
			'email'          => $conn->libreview_email,
			'sync_enabled'   => (bool) $conn->sync_enabled,
			'last_sync_at'   => $conn->last_sync_at,
			'last_glucose'   => $last ? (int) $last->glucose_value : null,
			'last_trend'     => $last ? $last->direction : null,
			'last_read_time' => $last ? $last->reading_time : null,
			'readings_count' => $count,
		);
	}
}
