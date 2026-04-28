<?php
/**
 * Integrazione Dexcom API Ufficiale (OAuth 2.0)
 *
 * Implementa il flusso Authorization Code OAuth 2.0 dell'API ufficiale
 * developer.dexcom.com per importare le letture EGV (CGM) degli utenti.
 *
 * L'utente viene reindirizzato alla pagina login Dexcom: le credenziali
 * non vengono mai trasmesse o salvate dal plugin.
 *
 * @package ScubaDiabetes_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SD_Dexcom_OAuth
 *
 * Gestisce autenticazione OAuth 2.0 con l'API ufficiale Dexcom e il sync
 * automatico delle letture EGV verso la tabella sd_nightscout_readings.
 */
class SD_Dexcom_OAuth {

	/** @var string Base URL API produzione */
	const API_BASE = 'https://api.dexcom.com';

	/** @var string Base URL API sandbox */
	const SANDBOX_BASE = 'https://sandbox-api.dexcom.com';

	/** @var string Path autorizzazione OAuth */
	const AUTH_PATH = '/v3/oauth2/login';

	/** @var string Path scambio/refresh token */
	const TOKEN_PATH = '/v3/oauth2/token';

	/** @var string Path EGV readings */
	const EGV_PATH = '/v3/users/self/egvs';

	/** @var string Hook cron per sync automatico */
	const CRON_HOOK = 'sd_dexcom_oauth_sync_cron';

	/** @var int Ore di storico da recuperare per ogni sync */
	const SYNC_HOURS = 24;

	/** @var string Query param usato nel redirect URI OAuth */
	const CALLBACK_PARAM = 'sd_dexcom_oauth_callback';

	/** @var string Prefisso tabelle DB */
	private string $prefix;

	/**
	 * Costruttore: registra hook WordPress.
	 */
	public function __construct() {
		global $wpdb;
		$this->prefix = $wpdb->prefix . 'sd_';

		// Gestione callback OAuth (intercetta il redirect da Dexcom)
		add_action( 'template_redirect', array( $this, 'handle_oauth_callback' ) );

		// Handler AJAX (solo utenti autenticati)
		add_action( 'wp_ajax_sd_dexcom_oauth_connect',     array( $this, 'ajax_initiate_oauth' ) );
		add_action( 'wp_ajax_sd_dexcom_oauth_sync',        array( $this, 'ajax_manual_sync' ) );
		add_action( 'wp_ajax_sd_dexcom_oauth_disconnect',  array( $this, 'ajax_disconnect' ) );

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
	 * Cifra una stringa con AES-256-CBC usando wp_salt come chiave.
	 */
	private function encrypt( string $plain ): string {
		$key    = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $cipher );
	}

	/**
	 * Decifra una stringa cifrata con encrypt().
	 */
	private function decrypt( string $enc ): string {
		$key  = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$data = base64_decode( $enc );
		if ( strlen( $data ) < 17 ) {
			return '';
		}
		$iv     = substr( $data, 0, 16 );
		$cipher = substr( $data, 16 );
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return $plain !== false ? $plain : '';
	}

	/**
	 * Mappa il trend Dexcom v3 nel formato Nightscout.
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
		return $map[ $trend ] ?? '';
	}

	// =========================================================================
	// Impostazioni app Dexcom (lette da WP options)
	// =========================================================================

	/**
	 * Restituisce il client_id configurato dall'admin.
	 */
	public static function get_client_id(): string {
		return (string) get_option( 'sd_dexcom_oauth_client_id', '' );
	}

	/**
	 * Restituisce il client_secret configurato dall'admin.
	 */
	public static function get_client_secret(): string {
		return (string) get_option( 'sd_dexcom_oauth_client_secret', '' );
	}

	/**
	 * Indica se è attiva la modalità sandbox.
	 */
	public static function is_sandbox(): bool {
		return (bool) get_option( 'sd_dexcom_oauth_sandbox', 0 );
	}

	/**
	 * Restituisce la base URL in base alla modalità attiva.
	 */
	public static function get_base_url(): string {
		return self::is_sandbox() ? self::SANDBOX_BASE : self::API_BASE;
	}

	/**
	 * Verifica se l'app Dexcom è correttamente configurata.
	 */
	public static function is_configured(): bool {
		return '' !== self::get_client_id() && '' !== self::get_client_secret();
	}

	/**
	 * Restituisce il redirect URI da registrare nel portale Dexcom.
	 */
	public static function get_redirect_uri(): string {
		return add_query_arg( self::CALLBACK_PARAM, '1', home_url( '/' ) );
	}

	// =========================================================================
	// Gestione stato OAuth (CSRF protection con transient)
	// =========================================================================

	/**
	 * Genera un token di stato OAuth e lo associa all'user_id + return URL.
	 *
	 * @param int    $user_id   WP user ID che avvia il flusso.
	 * @param string $return_url URL a cui tornare dopo l'autenticazione.
	 * @return string Token opaco da passare come parametro 'state'.
	 */
	private function generate_oauth_state( int $user_id, string $return_url ): string {
		$state = wp_generate_password( 32, false );
		set_transient(
			'sd_dxoauth_state_' . $state,
			array(
				'user_id'    => $user_id,
				'return_url' => $return_url,
			),
			600 // 10 minuti
		);
		return $state;
	}

	/**
	 * Valida lo stato OAuth e restituisce i dati associati. Usa il transient
	 * una sola volta (elimina dopo la verifica).
	 *
	 * @param string $state Token ricevuto nel callback.
	 * @return array{user_id:int,return_url:string}|false
	 */
	private function validate_oauth_state( string $state ): array|false {
		$data = get_transient( 'sd_dxoauth_state_' . $state );
		if ( is_array( $data ) && isset( $data['user_id'] ) ) {
			delete_transient( 'sd_dxoauth_state_' . $state );
			return $data;
		}
		return false;
	}

	// =========================================================================
	// Callback OAuth (redirect da Dexcom → questo sito)
	// =========================================================================

	/**
	 * Intercetta il redirect OAuth di Dexcom, scambia il codice con i token
	 * e salva la connessione.
	 *
	 * Agganciato a template_redirect.
	 */
	public function handle_oauth_callback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::CALLBACK_PARAM ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code  = sanitize_text_field( wp_unslash( $_GET['code']  ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );

		// Utente ha negato l'accesso
		if ( $error ) {
			wp_safe_redirect( add_query_arg( 'dexcom_error', rawurlencode( $error ), home_url( '/' ) ) );
			exit;
		}

		if ( ! $code || ! $state ) {
			wp_safe_redirect( add_query_arg( 'dexcom_error', 'missing_params', home_url( '/' ) ) );
			exit;
		}

		// Valida stato (CSRF check)
		$state_data = $this->validate_oauth_state( $state );
		if ( false === $state_data ) {
			wp_safe_redirect( add_query_arg( 'dexcom_error', 'invalid_state', home_url( '/' ) ) );
			exit;
		}

		$user_id    = (int) $state_data['user_id'];
		$return_url = $state_data['return_url'];

		// Scambia codice con token
		$tokens = $this->exchange_code( $code );
		if ( is_wp_error( $tokens ) ) {
			wp_safe_redirect(
				add_query_arg( 'dexcom_error', rawurlencode( $tokens->get_error_message() ), $return_url )
			);
			exit;
		}

		// Salva token
		$this->save_tokens( $user_id, $tokens );

		// Primo sync immediato (non bloccante se fallisce)
		$this->sync_user( $user_id );

		wp_safe_redirect( add_query_arg( 'dexcom_connected', '1', $return_url ) );
		exit;
	}

	// =========================================================================
	// Flusso OAuth: scambio codice e refresh token
	// =========================================================================

	/**
	 * Scambia il codice di autorizzazione con access_token + refresh_token.
	 *
	 * @param string $code Codice ricevuto nel callback OAuth.
	 * @return array{access_token:string,refresh_token:string,expires_in:int}|WP_Error
	 */
	private function exchange_code( string $code ): array|WP_Error {
		$response = wp_remote_post(
			self::get_base_url() . self::TOKEN_PATH,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'client_id'     => self::get_client_id(),
					'client_secret' => self::get_client_secret(),
					'code'          => $code,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => self::get_redirect_uri(),
				),
			)
		);

		return $this->parse_token_response( $response );
	}

	/**
	 * Usa il refresh_token per ottenere un nuovo access_token.
	 *
	 * @param string $refresh_token Token di refresh (in chiaro, non cifrato).
	 * @return array{access_token:string,refresh_token:string,expires_in:int}|WP_Error
	 */
	private function refresh_tokens( string $refresh_token ): array|WP_Error {
		$response = wp_remote_post(
			self::get_base_url() . self::TOKEN_PATH,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'client_id'     => self::get_client_id(),
					'client_secret' => self::get_client_secret(),
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		return $this->parse_token_response( $response );
	}

	/**
	 * Analizza la risposta del token endpoint e restituisce i dati o WP_Error.
	 *
	 * @param array|WP_Error $response Risposta di wp_remote_post.
	 * @return array|WP_Error
	 */
	private function parse_token_response( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'Connessione a Dexcom fallita: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$msg = $body['error_description'] ?? $body['error'] ?? "Errore HTTP {$code}";
			return new WP_Error( 'token_error', $msg );
		}

		if ( empty( $body['access_token'] ) || empty( $body['refresh_token'] ) ) {
			return new WP_Error( 'token_missing', 'Risposta token non valida.' );
		}

		return $body;
	}

	/**
	 * Restituisce un access_token valido per l'utente, aggiornandolo se scaduto.
	 *
	 * @param int $user_id WP user ID.
	 * @return string|WP_Error Token in chiaro o WP_Error.
	 */
	private function get_valid_access_token( int $user_id ): string|WP_Error {
		$conn = $this->get_connection( $user_id );
		if ( ! $conn ) {
			return new WP_Error( 'not_connected', 'Account Dexcom non connesso.' );
		}

		$expires_at = strtotime( $conn->token_expires_at );
		$is_expired = ( $expires_at - 300 ) < time(); // rinnova 5 min prima

		if ( $is_expired ) {
			$refresh = $this->decrypt( $conn->refresh_token );
			if ( ! $refresh ) {
				return new WP_Error( 'decrypt_failed', 'Impossibile decriptare il refresh token.' );
			}

			$new_tokens = $this->refresh_tokens( $refresh );
			if ( is_wp_error( $new_tokens ) ) {
				return $new_tokens;
			}

			$this->save_tokens( $user_id, $new_tokens );
			return $new_tokens['access_token'];
		}

		$access = $this->decrypt( $conn->access_token );
		if ( ! $access ) {
			return new WP_Error( 'decrypt_failed', 'Impossibile decriptare l\'access token.' );
		}

		return $access;
	}

	// =========================================================================
	// Operazioni DB: salvataggio token e letture
	// =========================================================================

	/**
	 * Salva (INSERT o UPDATE) i token OAuth cifrati per un utente.
	 *
	 * @param int   $user_id WP user ID.
	 * @param array $tokens  Array con access_token, refresh_token, expires_in.
	 */
	private function save_tokens( int $user_id, array $tokens ): void {
		global $wpdb;

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + (int) ( $tokens['expires_in'] ?? 7200 ) );

		$wpdb->replace(
			$this->table( 'dexcom_oauth_connections' ),
			array(
				'user_id'          => $user_id,
				'access_token'     => $this->encrypt( $tokens['access_token'] ),
				'refresh_token'    => $this->encrypt( $tokens['refresh_token'] ),
				'token_expires_at' => $expires_at,
				'connected_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Recupera il record di connessione OAuth di un utente.
	 *
	 * @param int $user_id WP user ID.
	 * @return object|null
	 */
	private function get_connection( int $user_id ): ?object {
		global $wpdb;
		$table = $this->table( 'dexcom_oauth_connections' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
	}

	/**
	 * Salva le letture EGV nella tabella condivisa sd_nightscout_readings.
	 * Usa INSERT IGNORE per evitare duplicati (indice UNIQUE su user_id + reading_time).
	 *
	 * @param int    $user_id  WP user ID.
	 * @param array  $egvs     Array di oggetti EGV dalla risposta API.
	 * @param string $unit     Unità di misura ('mg/dL' o 'mmol/L').
	 * @return int Numero di righe inserite.
	 */
	private function save_readings( int $user_id, array $egvs, string $unit ): int {
		global $wpdb;
		$table   = $this->table( 'nightscout_readings' );
		$saved   = 0;

		foreach ( $egvs as $egv ) {
			// Salta letture senza valore (sensore in calibrazione ecc.)
			if ( ! isset( $egv['value'] ) || null === $egv['value'] ) {
				continue;
			}

			$glucose = (int) $egv['value'];

			// Converti mmol/L → mg/dL se necessario
			if ( 'mmol/L' === $unit ) {
				$glucose = (int) round( $glucose * 18.01559 );
			}

			$reading_time = sanitize_text_field( $egv['systemTime'] ?? '' );
			if ( ! $reading_time ) {
				continue;
			}

			// Normalizza la data (Dexcom restituisce YYYY-MM-DDTHH:MM:SS UTC)
			$reading_time = gmdate( 'Y-m-d H:i:s', strtotime( $reading_time ) );

			$trend = $this->map_trend( $egv['trend'] ?? '' );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table}
						(user_id, reading_time, glucose_value, glucose_unit, direction, reading_type, device)
					VALUES
						(%d, %s, %d, %s, %s, %s, %s)",
					$user_id,
					$reading_time,
					$glucose,
					'mg/dl',
					$trend,
					'sgv',
					'Dexcom OAuth'
				)
			);

			if ( $inserted ) {
				++$saved;
			}
		}

		return $saved;
	}

	// =========================================================================
	// Fetch dati EGV da Dexcom
	// =========================================================================

	/**
	 * Scarica le letture EGV per il periodo indicato.
	 *
	 * @param string $access_token Token di accesso valido.
	 * @param int    $hours        Ore di storico da recuperare.
	 * @return array{egvs:array,unit:string}|WP_Error
	 */
	private function fetch_egvs( string $access_token, int $hours = self::SYNC_HOURS ): array|WP_Error {
		$start = gmdate( 'Y-m-d\TH:i:s', strtotime( "-{$hours} hours" ) );
		$end   = gmdate( 'Y-m-d\TH:i:s' );

		$url = add_query_arg(
			array(
				'startDate' => $start,
				'endDate'   => $end,
			),
			self::get_base_url() . self::EGV_PATH
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'Connessione a Dexcom fallita: ' . $response->get_error_message() );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $http_code ) {
			return new WP_Error( 'token_expired', 'Token scaduto.' );
		}

		if ( 200 !== $http_code ) {
			$msg = $body['errors'][0]['message'] ?? "Errore HTTP {$http_code}";
			return new WP_Error( 'api_error', $msg );
		}

		return array(
			'egvs' => $body['egvs'] ?? array(),
			'unit' => $body['unit'] ?? 'mg/dL',
		);
	}

	// =========================================================================
	// Sync
	// =========================================================================

	/**
	 * Esegue il sync completo per un singolo utente.
	 *
	 * @param int $user_id WP user ID.
	 * @return array{success:bool,message:string,saved:int}
	 */
	public function sync_user( int $user_id ): array {
		$token = $this->get_valid_access_token( $user_id );
		if ( is_wp_error( $token ) ) {
			return array(
				'success' => false,
				'message' => $token->get_error_message(),
				'saved'   => 0,
			);
		}

		$data = $this->fetch_egvs( $token, self::SYNC_HOURS );

		// Retry con refresh se il token era scaduto
		if ( is_wp_error( $data ) && 'token_expired' === $data->get_error_code() ) {
			$conn    = $this->get_connection( $user_id );
			$refresh = $conn ? $this->decrypt( $conn->refresh_token ) : '';
			if ( $refresh ) {
				$new_tokens = $this->refresh_tokens( $refresh );
				if ( ! is_wp_error( $new_tokens ) ) {
					$this->save_tokens( $user_id, $new_tokens );
					$data = $this->fetch_egvs( $new_tokens['access_token'], self::SYNC_HOURS );
				}
			}
		}

		if ( is_wp_error( $data ) ) {
			return array(
				'success' => false,
				'message' => $data->get_error_message(),
				'saved'   => 0,
			);
		}

		$saved = $this->save_readings( $user_id, $data['egvs'], $data['unit'] );

		// Aggiorna timestamp ultimo sync
		global $wpdb;
		$table = $this->table( 'dexcom_oauth_connections' );
		$wpdb->update(
			$table,
			array( 'last_sync_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'user_id' => $user_id ),
			array( '%s' ),
			array( '%d' )
		);

		$count = count( $data['egvs'] );
		return array(
			'success' => true,
			'message' => sprintf( 'Sync completato: %d letture ricevute, %d nuove salvate.', $count, $saved ),
			'saved'   => $saved,
		);
	}

	/**
	 * Sync cron: sincronizza tutti gli utenti connessi con sync abilitato.
	 */
	public function cron_sync_all(): void {
		global $wpdb;
		$table = $this->table( 'dexcom_oauth_connections' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$users = $wpdb->get_col( "SELECT user_id FROM {$table} WHERE sync_enabled = 1" );
		foreach ( $users as $uid ) {
			$this->sync_user( (int) $uid );
		}
	}

	/**
	 * Registra il cron orario se non già programmato.
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Rimuove il cron hook.
	 */
	public static function unschedule_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// =========================================================================
	// Handler AJAX
	// =========================================================================

	/**
	 * AJAX: avvia il flusso OAuth restituendo l'URL di autorizzazione Dexcom.
	 */
	public function ajax_initiate_oauth(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Non autenticato.' ) );
		}

		if ( ! self::is_configured() ) {
			wp_send_json_error( array( 'message' => 'Integrazione Dexcom non configurata. Contatta l\'amministratore del sito.' ) );
		}

		$user_id    = get_current_user_id();
		$return_url = esc_url_raw( wp_unslash( $_POST['return_url'] ?? home_url( '/' ) ) );

		// Limita il return_url allo stesso dominio per sicurezza
		if ( wp_parse_url( $return_url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$return_url = home_url( '/' );
		}

		$state    = $this->generate_oauth_state( $user_id, $return_url );
		$auth_url = add_query_arg(
			array(
				'client_id'     => self::get_client_id(),
				'redirect_uri'  => rawurlencode( self::get_redirect_uri() ),
				'response_type' => 'code',
				'scope'         => 'offline_access',
				'state'         => $state,
			),
			self::get_base_url() . self::AUTH_PATH
		);

		wp_send_json_success( array( 'auth_url' => $auth_url ) );
	}

	/**
	 * AJAX: sync manuale delle letture EGV.
	 */
	public function ajax_manual_sync(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Non autenticato.' ) );
		}

		$user_id = get_current_user_id();
		$result  = $this->sync_user( $user_id );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * AJAX: disconnette l'account Dexcom rimuovendo token e connessione.
	 */
	public function ajax_disconnect(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Non autenticato.' ) );
		}

		global $wpdb;
		$user_id = get_current_user_id();
		$table   = $this->table( 'dexcom_oauth_connections' );
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );

		wp_send_json_success( array( 'message' => 'Account Dexcom disconnesso.' ) );
	}

	// =========================================================================
	// Dati per il template di profilo
	// =========================================================================

	/**
	 * Restituisce i dati Dexcom OAuth da mostrare nel profilo utente.
	 *
	 * @param int $user_id WP user ID.
	 * @return array
	 */
	public static function get_profile_data( int $user_id ): array {
		global $wpdb;
		$prefix = $wpdb->prefix . 'sd_';
		$table  = $prefix . 'dexcom_oauth_connections';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conn   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );

		$base = array(
			'connected'      => false,
			'sync_enabled'   => true,
			'last_sync_at'   => null,
			'last_glucose'   => null,
			'last_trend'     => null,
			'last_read_time' => null,
			'readings_count' => 0,
		);

		if ( ! $conn ) {
			return $base;
		}

		// Lettura più recente
		$readings_table = $prefix . 'nightscout_readings';
		$last = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT glucose_value, direction, reading_time
				   FROM {$readings_table}
				  WHERE user_id = %d AND device = 'Dexcom OAuth'
				  ORDER BY reading_time DESC
				  LIMIT 1",
				$user_id
			)
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$readings_table} WHERE user_id = %d AND device = 'Dexcom OAuth'",
				$user_id
			)
		);

		return array(
			'connected'      => true,
			'sync_enabled'   => (bool) $conn->sync_enabled,
			'last_sync_at'   => $conn->last_sync_at,
			'last_glucose'   => $last ? (int) $last->glucose_value : null,
			'last_trend'     => $last ? (string) $last->direction : null,
			'last_read_time' => $last ? $last->reading_time : null,
			'readings_count' => $count,
		);
	}
}
