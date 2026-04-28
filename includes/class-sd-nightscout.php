<?php
/**
 * Integrazione Nightscout — CGM + trattamenti insulina
 *
 * Permette agli utenti di collegare il proprio server Nightscout per importare
 * automaticamente valori glicemici (CGM/capillare) e somministrazioni di insulina
 * in base al dispositivo registrato nel profilo.
 *
 * Flusso:
 *   1. Utente inserisce URL + API_SECRET nel profilo
 *   2. Il plugin testa la connessione (/api/v1/status.json)
 *   3. Un cron orario importa letture e trattamenti nelle tabelle locali
 *   4. Il form immersione legge i dati locali per pre-compilare i campi glicemici
 *
 * Sicurezza:
 *   - Token cifrato con AES-256-CBC usando wp_salt('auth') come chiave
 *   - Nonce WordPress su ogni azione AJAX
 *   - Solo HTTPS raccomandato (avviso per HTTP)
 *   - Dati restituiti solo per l'utente autenticato
 *
 * @package SD_Logbook
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Nightscout {

	public function __construct() {
		// Handler AJAX (solo utenti autenticati)
		add_action( 'wp_ajax_sd_nightscout_save', array( $this, 'ajax_save_connection' ) );
		add_action( 'wp_ajax_sd_nightscout_test', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_sd_nightscout_sync', array( $this, 'ajax_manual_sync' ) );
		add_action( 'wp_ajax_sd_nightscout_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_sd_nightscout_readings', array( $this, 'ajax_get_readings' ) );

		// Cron periodico
		add_action( 'sd_nightscout_sync_cron', array( $this, 'cron_sync_all' ) );
	}

	// ================================================================
	// CIFRATURA TOKEN
	// ================================================================

	/**
	 * Cifra l'API_SECRET con AES-256-CBC usando wp_salt come chiave.
	 */
	private function encrypt_token( string $token ): string {
		$key = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
		$iv  = openssl_random_pseudo_bytes( 16 );
		$enc = openssl_encrypt( $token, 'AES-256-CBC', $key, 0, $iv );
		return base64_encode( $iv . '::' . $enc );
	}

	/**
	 * Decifra il token precedentemente cifrato.
	 */
	private function decrypt_token( string $stored ): string {
		$key  = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
		$data = base64_decode( $stored, true );
		if ( false === $data ) {
			return '';
		}
		$parts = explode( '::', $data, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}
		$decrypted = openssl_decrypt( $parts[1], 'AES-256-CBC', $key, 0, $parts[0] );
		return ( false !== $decrypted ) ? $decrypted : '';
	}

	/**
	 * Restituisce il token mascherato (mostra solo gli ultimi 4 caratteri).
	 */
	private function mask_token( string $token ): string {
		$len = strlen( $token );
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return str_repeat( '*', $len - 4 ) . substr( $token, -4 );
	}

	// ================================================================
	// CHIAMATE API NIGHTSCOUT
	// ================================================================

	/**
	 * Esegue una chiamata GET all'API Nightscout.
	 *
	 * Nightscout autentica via header `api-secret` (SHA1 dell'API_SECRET).
	 *
	 * @param string $base_url  URL base del server (es. https://myns.fly.dev)
	 * @param string $token     API_SECRET in chiaro
	 * @param string $endpoint  Endpoint (es. /api/v1/entries.json)
	 * @param array  $query     Parametri GET aggiuntivi
	 * @return array|WP_Error   Array dati JSON oppure WP_Error
	 */
	private function api_call( string $base_url, string $token, string $endpoint, array $query = array() ) {
		$base_url = rtrim( esc_url_raw( $base_url ), '/' );
		$url      = $base_url . $endpoint;

		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => array(
					'Accept'     => 'application/json',
					'api-secret' => hash( 'sha1', $token ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code */
			return new WP_Error(
				'nightscout_http_error',
				sprintf( __( 'Nightscout ha risposto con il codice HTTP %d. Verifica URL e token.', 'sd-logbook' ), (int) $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data ) {
			return new WP_Error( 'nightscout_parse_error', __( 'Risposta non valida da Nightscout (JSON non parsabile).', 'sd-logbook' ) );
		}

		return $data;
	}

	// ================================================================
	// CRUD CONNESSIONE
	// ================================================================

	/**
	 * Recupera la riga di connessione per l'utente specificato.
	 */
	private function get_connection( int $user_id ): ?object {
		global $wpdb;
		$db = new SD_Database();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$db->table('nightscout_connections')} WHERE user_id = %d",
				$user_id
			)
		);
	}

	// ================================================================
	// AJAX — SALVA CONNESSIONE
	// ================================================================

	public function ajax_save_connection() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$raw_url = isset( $_POST['ns_url'] ) ? sanitize_text_field( wp_unslash( $_POST['ns_url'] ) ) : '';
		$token   = isset( $_POST['ns_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ns_token'] ) ) : '';

		// Valida URL
		$url = esc_url_raw( $raw_url );
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			wp_send_json_error( array( 'message' => __( 'URL Nightscout non valido.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db       = new SD_Database();
		$table    = $db->table( 'nightscout_connections' );
		$existing = $this->get_connection( $user_id );

		// Se il token contiene asterischi → l'utente non ha modificato il token → mantieni quello esistente
		$encrypted_token = '';
		if ( $token && false === strpos( $token, '*' ) ) {
			$encrypted_token = $this->encrypt_token( $token );
		} elseif ( $existing ) {
			$encrypted_token = $existing->api_token_enc;
		}

		if ( empty( $encrypted_token ) ) {
			wp_send_json_error( array( 'message' => __( 'Token API obbligatorio.', 'sd-logbook' ) ) );
		}

		$data = array(
			'nightscout_url' => $url,
			'api_token_enc'  => $encrypted_token,
			'sync_enabled'   => 1,
			'updated_at'     => current_time( 'mysql' ),
		);

		if ( $existing ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				$data,
				array( 'user_id' => $user_id ),
				array( '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$data['user_id']    = $user_id;
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				$data,
				array( '%d', '%s', '%s', '%d', '%s', '%s' )
			);
		}

		$plain = ( $token && false === strpos( $token, '*' ) ) ? $token : $this->decrypt_token( $encrypted_token );

		wp_send_json_success(
			array(
				'message'      => __( 'Connessione salvata.', 'sd-logbook' ),
				'masked_token' => $this->mask_token( $plain ),
			)
		);
	}

	// ================================================================
	// AJAX — TEST CONNESSIONE
	// ================================================================

	public function ajax_test_connection() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$conn = $this->get_connection( $user_id );
		if ( ! $conn ) {
			wp_send_json_error( array( 'message' => __( 'Connessione non configurata. Salva prima le credenziali.', 'sd-logbook' ) ) );
		}

		$token  = $this->decrypt_token( $conn->api_token_enc );
		$result = $this->api_call( $conn->nightscout_url, $token, '/api/v1/status.json' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$ns_status  = isset( $result['status'] ) ? sanitize_text_field( $result['status'] ) : 'unknown';
		$ns_version = isset( $result['version'] ) ? sanitize_text_field( $result['version'] ) : '?';

		wp_send_json_success(
			array(
				/* translators: 1: Nightscout version, 2: server status */
				'message' => sprintf(
					__( 'Connessione OK — Nightscout v%1$s (status: %2$s)', 'sd-logbook' ),
					esc_html( $ns_version ),
					esc_html( $ns_status )
				),
			)
		);
	}

	// ================================================================
	// AJAX — SYNC MANUALE
	// ================================================================

	public function ajax_manual_sync() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$result = $this->sync_user( $user_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				/* translators: 1: number of CGM readings, 2: number of treatments */
				'message'   => sprintf(
					__( 'Sync completato: %1$d letture CGM, %2$d trattamenti importati.', 'sd-logbook' ),
					(int) $result['readings'],
					(int) $result['treatments']
				),
				'last_sync' => current_time( 'mysql' ),
			)
		);
	}

	// ================================================================
	// AJAX — DISCONNETTI
	// ================================================================

	public function ajax_disconnect() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db = new SD_Database();
		$wpdb->delete( $db->table( 'nightscout_connections' ), array( 'user_id' => $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $db->table( 'nightscout_readings' ), array( 'user_id' => $user_id ), array( '%d' ) );    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $db->table( 'nightscout_treatments' ), array( 'user_id' => $user_id ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		wp_send_json_success( array( 'message' => __( 'Connessione Nightscout rimossa e dati locali eliminati.', 'sd-logbook' ) ) );
	}

	// ================================================================
	// AJAX — LETTURE RECENTI (usato dal form immersione)
	// ================================================================

	public function ajax_get_readings() {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$hours = min( 72, absint( $_GET['hours'] ?? 24 ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $hours * 3600 );

		global $wpdb;
		$db = new SD_Database();

		$readings = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT reading_time, glucose_value, glucose_unit, direction, reading_type, device
				 FROM {$db->table('nightscout_readings')}
				 WHERE user_id = %d AND reading_time >= %s
				 ORDER BY reading_time DESC",
				$user_id,
				$since
			)
		);

		$treatments = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT treatment_time, event_type, insulin_units, carbs_grams, notes
				 FROM {$db->table('nightscout_treatments')}
				 WHERE user_id = %d AND treatment_time >= %s
				 ORDER BY treatment_time DESC",
				$user_id,
				$since
			)
		);

		wp_send_json_success(
			array(
				'readings'   => $readings,
				'treatments' => $treatments,
			)
		);
	}

	// ================================================================
	// SYNC CORE
	// ================================================================

	/**
	 * Sincronizza i dati Nightscout per un utente.
	 * Importa le ultime 24h di letture CGM e gli ultimi 100 trattamenti.
	 *
	 * @param  int          $user_id
	 * @return array|WP_Error  array( 'readings' => int, 'treatments' => int )
	 */
	public function sync_user( int $user_id ) {
		$conn = $this->get_connection( $user_id );
		if ( ! $conn || ! $conn->sync_enabled ) {
			return new WP_Error( 'no_connection', __( 'Nessuna connessione Nightscout abilitata.', 'sd-logbook' ) );
		}

		$token = $this->decrypt_token( $conn->api_token_enc );
		if ( empty( $token ) ) {
			return new WP_Error( 'token_error', __( 'Impossibile decifrare il token Nightscout.', 'sd-logbook' ) );
		}

		global $wpdb;
		$db = new SD_Database();

		// ---- Letture CGM (ultimi 288 @ 5 min = 24h) ----
		$entries        = $this->api_call( $conn->nightscout_url, $token, '/api/v1/entries.json', array( 'count' => 288 ) );
		$readings_saved = 0;

		if ( ! is_wp_error( $entries ) && is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( empty( $entry['date'] ) ) {
					continue;
				}

				$ts   = intdiv( (int) $entry['date'], 1000 );
				$dt   = gmdate( 'Y-m-d H:i:s', $ts );
				$type = isset( $entry['type'] ) ? sanitize_text_field( $entry['type'] ) : 'sgv';

				if ( 'sgv' === $type && isset( $entry['sgv'] ) ) {
					$val = (int) $entry['sgv'];
				} elseif ( 'mbg' === $type && isset( $entry['mbg'] ) ) {
					$val = (int) $entry['mbg'];
				} else {
					continue;
				}

				$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT id FROM {$db->table('nightscout_readings')} WHERE user_id = %d AND reading_time = %s",
						$user_id,
						$dt
					)
				);

				if ( ! $exists ) {
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$db->table( 'nightscout_readings' ),
						array(
							'user_id'       => $user_id,
							'reading_time'  => $dt,
							'glucose_value' => $val,
							'glucose_unit'  => 'mg/dl',
							'direction'     => isset( $entry['direction'] ) ? sanitize_text_field( $entry['direction'] ) : null,
							'reading_type'  => $type,
							'device'        => isset( $entry['device'] ) ? sanitize_text_field( $entry['device'] ) : null,
							'created_at'    => current_time( 'mysql' ),
						),
						array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
					);
					++$readings_saved;
				}
			}
		}

		// ---- Trattamenti (ultimi 100) ----
		$treatments       = $this->api_call( $conn->nightscout_url, $token, '/api/v1/treatments.json', array( 'count' => 100 ) );
		$treatments_saved = 0;

		if ( ! is_wp_error( $treatments ) && is_array( $treatments ) ) {
			foreach ( $treatments as $treatment ) {
				if ( empty( $treatment['created_at'] ) ) {
					continue;
				}

				$dt         = gmdate( 'Y-m-d H:i:s', strtotime( $treatment['created_at'] ) );
				$event_type = sanitize_text_field( $treatment['eventType'] ?? '' );

				$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT id FROM {$db->table('nightscout_treatments')}
						 WHERE user_id = %d AND treatment_time = %s AND event_type = %s",
						$user_id,
						$dt,
						$event_type
					)
				);

				if ( ! $exists ) {
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$db->table( 'nightscout_treatments' ),
						array(
							'user_id'        => $user_id,
							'treatment_time' => $dt,
							'event_type'     => $event_type,
							'insulin_units'  => isset( $treatment['insulin'] ) ? (float) $treatment['insulin'] : null,
							'carbs_grams'    => isset( $treatment['carbs'] ) ? (float) $treatment['carbs'] : null,
							'notes'          => sanitize_textarea_field( $treatment['notes'] ?? '' ),
							'created_at'     => current_time( 'mysql' ),
						),
						array( '%d', '%s', '%s', '%f', '%f', '%s', '%s' )
					);
					++$treatments_saved;
				}
			}
		}

		// Aggiorna timestamp ultimo sync
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$db->table( 'nightscout_connections' ),
			array( 'last_sync_at' => current_time( 'mysql' ) ),
			array( 'user_id' => $user_id ),
			array( '%s' ),
			array( '%d' )
		);

		return array(
			'readings'   => $readings_saved,
			'treatments' => $treatments_saved,
		);
	}

	// ================================================================
	// CRON — SYNC TUTTI GLI UTENTI
	// ================================================================

	/**
	 * Cron orario: sincronizza tutti gli utenti con sync_enabled = 1.
	 */
	public function cron_sync_all() {
		global $wpdb;
		$db          = new SD_Database();
		$connections = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT user_id FROM {$db->table('nightscout_connections')} WHERE sync_enabled = 1"
		);

		foreach ( (array) $connections as $row ) {
			$this->sync_user( (int) $row->user_id );
		}
	}

	// ================================================================
	// CRON — REGISTRAZIONE / CANCELLAZIONE
	// ================================================================

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'sd_nightscout_sync_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'sd_nightscout_sync_cron' );
		}
	}

	public static function unschedule_cron(): void {
		wp_clear_scheduled_hook( 'sd_nightscout_sync_cron' );
	}

	// ================================================================
	// HELPER — DATI DA MOSTRARE NEL TEMPLATE PROFILO
	// ================================================================

	/**
	 * Restituisce i dati pubblici della connessione (no token) per il template.
	 *
	 * @param  int   $user_id
	 * @return array  keys: connected, url, last_sync, sync_enabled
	 */
	public static function get_profile_data( int $user_id ): array {
		global $wpdb;
		$db   = new SD_Database();
		$conn = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT nightscout_url, last_sync_at, sync_enabled
				 FROM {$db->table('nightscout_connections')}
				 WHERE user_id = %d",
				$user_id
			)
		);

		return array(
			'connected'    => (bool) $conn,
			'url'          => $conn ? esc_url( $conn->nightscout_url ) : '',
			'last_sync'    => $conn ? $conn->last_sync_at : null,
			'sync_enabled' => $conn ? (bool) $conn->sync_enabled : false,
		);
	}
}
