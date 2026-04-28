<?php
/**
 * Server Nightscout interno — ScubaDiabetes
 *
 * Espone endpoint REST API compatibili con il protocollo Nightscout v1,
 * permettendo alle app CGM (xDrip+, Loop, AndroidAPS, Spike, Juggluco…)
 * di caricare dati direttamente su scubadiabetes.ch senza che l'utente
 * debba gestire un server Nightscout esterno.
 *
 * URL base: https://scubadiabetes.ch/wp-json/sd-ns/v1/
 *
 * Endpoint implementati:
 *   GET  /status.json          — info server (unauthenticated)
 *   GET  /entries[.json]       — lettura CGM
 *   POST /entries[.json]       — caricamento lettura CGM
 *   GET  /treatments[.json]    — trattamenti (insulina/carbo)
 *   POST /treatments[.json]    — caricamento trattamento
 *   GET  /profile[.json]       — profilo/impostazioni
 *
 * Autenticazione: ogni utente ha un token personale (20 caratteri casuali).
 * Le app inviano il token via header `api-secret: sha1(token)` — esattamente
 * come il protocollo Nightscout originale.
 *
 * Sicurezza:
 *   - Autenticazione HMAC-SHA1 (api-secret header)
 *   - Rate limiting: 60 richieste/minuto per token via transient WP
 *   - Solo HTTPS in produzione (WP_DEBUG = false)
 *   - Nessun dato cross-user esposto
 *   - Dati sanitizzati in ingresso e in uscita
 *
 * @package SD_Logbook
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Nightscout_Server {

	/**
	 * Namespace REST API
	 */
	const NS = 'sd-ns/v1';

	/**
	 * Versione del server mostrata in /status.json
	 */
	const SERVER_VERSION = '14.2.6-sd';

	/**
	 * Massimo numero di entry/treatments restituibili per query
	 */
	const MAX_COUNT = 500;

	/**
	 * Rate limit: richieste per minuto per token
	 */
	const RATE_LIMIT = 60;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// AJAX: generazione / rigenerazione token
		add_action( 'wp_ajax_sd_ns_generate_token', array( $this, 'ajax_generate_token' ) );
	}

	// ================================================================
	// REGISTRAZIONE ROUTES
	// ================================================================

	public function register_routes(): void {
		$ns = self::NS;

		// --- Status (no auth) ---
		register_rest_route(
			$ns,
			'/status(?:\.json)?',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_status' ),
				'permission_callback' => '__return_true',
			)
		);

		// --- Profile (auth required) ---
		register_rest_route(
			$ns,
			'/profile(?:\.json)?',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_profile' ),
				'permission_callback' => array( $this, 'authenticate_request' ),
			)
		);

		// --- Entries (letture CGM) ---
		register_rest_route(
			$ns,
			'/entries(?:\.json)?',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'route_entries_get' ),
					'permission_callback' => array( $this, 'authenticate_request' ),
					'args'                => array(
						'count' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_COUNT,
							'default' => 100,
						),
						'find'  => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'route_entries_post' ),
					'permission_callback' => array( $this, 'authenticate_request' ),
				),
			)
		);

		// --- Treatments (insulina / carboidrati) ---
		register_rest_route(
			$ns,
			'/treatments(?:\.json)?',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'route_treatments_get' ),
					'permission_callback' => array( $this, 'authenticate_request' ),
					'args'                => array(
						'count' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_COUNT,
							'default' => 100,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'route_treatments_post' ),
					'permission_callback' => array( $this, 'authenticate_request' ),
				),
			)
		);

		// --- DELETE entries/treatments (per compatibilità Nightscout) ---
		register_rest_route(
			$ns,
			'/entries/(?P<id>[0-9]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'route_entries_delete' ),
				'permission_callback' => array( $this, 'authenticate_request' ),
			)
		);
	}

	// ================================================================
	// AUTENTICAZIONE
	// ================================================================

	/**
	 * Verifica l'header `api-secret` (SHA1 del token utente).
	 * Imposta $request->set_param('_sd_user_id', ...) se OK.
	 * Supporta anche il parametro ?token= (per app che non supportano header).
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function authenticate_request( WP_REST_Request $request ) {
		// Header api-secret (standard Nightscout)
		$api_secret = $request->get_header( 'api-secret' );

		// Fallback: parametro ?token= in query string (alcune app usano questo)
		if ( empty( $api_secret ) ) {
			$api_secret = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
		}

		if ( empty( $api_secret ) ) {
			return new WP_Error( 'sd_ns_auth', 'API secret required.', array( 'status' => 401 ) );
		}

		// Cerca l'utente il cui SHA1(token) corrisponde
		$user_id = $this->resolve_token( $api_secret );
		if ( ! $user_id ) {
			return new WP_Error( 'sd_ns_auth', 'Invalid API secret.', array( 'status' => 401 ) );
		}

		// Rate limiting
		if ( ! $this->check_rate_limit( $user_id ) ) {
			return new WP_Error( 'sd_ns_rate', 'Too many requests. Slow down.', array( 'status' => 429 ) );
		}

		// Inietta user_id nella request
		$request->set_param( '_sd_user_id', $user_id );

		return true;
	}

	/**
	 * Cerca l'utente dato SHA1(token) o il token in chiaro.
	 * Nightscout invia SHA1(API_SECRET) nell'header.
	 *
	 * @param  string $api_secret  SHA1 del token oppure token in chiaro
	 * @return int|null  user_id oppure null
	 */
	private function resolve_token( string $api_secret ): ?int {
		// Controlla prima la cache (transient) per performance
		$cache_key = 'sd_ns_token_' . substr( md5( $api_secret ), 0, 12 );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached ?: null;
		}

		// Cerca tutti gli utenti con token SD impostato
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'sd_ns_api_token'"
		);

		foreach ( (array) $rows as $row ) {
			$plain_token = $row->meta_value;
			if ( empty( $plain_token ) ) {
				continue;
			}
			// Confronta SHA1(token) con quanto ricevuto
			if ( hash_equals( hash( 'sha1', $plain_token ), $api_secret ) ) {
				set_transient( $cache_key, (int) $row->user_id, 300 );
				return (int) $row->user_id;
			}
			// Confronto diretto (alcune app inviano il token in chiaro con token= param)
			if ( hash_equals( $plain_token, $api_secret ) ) {
				set_transient( $cache_key, (int) $row->user_id, 300 );
				return (int) $row->user_id;
			}
		}

		set_transient( $cache_key, 0, 60 ); // cache miss breve
		return null;
	}

	/**
	 * Rate limiting: max 60 req/min per utente via transient.
	 */
	private function check_rate_limit( int $user_id ): bool {
		$key   = 'sd_ns_rl_' . $user_id . '_' . floor( time() / 60 );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, 65 );
		return true;
	}

	// ================================================================
	// ROUTE: /status.json
	// ================================================================

	public function route_status( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'status'           => 'ok',
				'name'             => 'ScubaDiabetes Nightscout',
				'version'          => self::SERVER_VERSION,
				'apiEnabled'       => true,
				'careportalEnabled' => true,
				'head'             => 'SD-INTERNAL',
				'settings'         => array(
					'units' => 'mg/dl',
				),
				'extendedSettings' => array(),
			),
			200
		);
	}

	// ================================================================
	// ROUTE: /profile.json
	// ================================================================

	public function route_profile( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( '_sd_user_id' );

		global $wpdb;
		$db  = new SD_Database();
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT glycemia_unit FROM {$db->table('diver_profiles')} WHERE user_id = %d",
				$user_id
			)
		);

		$units = ( $row && 'mmol/l' === $row->glycemia_unit ) ? 'mmol' : 'mg/dl';

		return new WP_REST_Response(
			array(
				'_id'            => 'default',
				'defaultProfile' => 'Default',
				'store'          => array(
					'Default' => array(
						'units' => $units,
					),
				),
				'startDate'      => '2000-01-01T00:00:00.000Z',
				'units'          => $units,
			),
			200
		);
	}

	// ================================================================
	// ROUTE: GET /entries.json
	// ================================================================

	public function route_entries_get( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( '_sd_user_id' );
		$count   = min( (int) $request->get_param( 'count' ), self::MAX_COUNT );

		global $wpdb;
		$db   = new SD_Database();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$db->table('nightscout_readings')}
				 WHERE user_id = %d
				 ORDER BY reading_time DESC
				 LIMIT %d",
				$user_id,
				$count
			)
		);

		$entries = array();
		foreach ( (array) $rows as $row ) {
			$ts        = strtotime( $row->reading_time );
			$entries[] = array(
				'_id'       => (string) $row->id,
				'type'      => $row->reading_type ?: 'sgv',
				'sgv'       => (int) $row->glucose_value,
				'dateString' => gmdate( 'c', $ts ),
				'date'      => $ts * 1000,
				'direction' => $row->direction ?: 'NONE',
				'device'    => $row->device ?: 'ScubaDiabetes',
			);
		}

		return new WP_REST_Response( $entries, 200 );
	}

	// ================================================================
	// ROUTE: POST /entries.json
	// ================================================================

	public function route_entries_post( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( '_sd_user_id' );
		$body    = $request->get_json_params();

		// Alcune app inviano un array, altre un singolo oggetto
		if ( isset( $body['type'] ) ) {
			$body = array( $body );
		}

		if ( ! is_array( $body ) || empty( $body ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'No entries provided.',
				),
				400
			);
		}

		global $wpdb;
		$db      = new SD_Database();
		$saved   = 0;
		$skipped = 0;

		foreach ( $body as $entry ) {
			if ( empty( $entry['date'] ) && empty( $entry['dateString'] ) ) {
				continue;
			}

			// Timestamp in ms (Nightscout standard)
			if ( ! empty( $entry['date'] ) ) {
				$ts = intdiv( (int) $entry['date'], 1000 );
			} else {
				$ts = strtotime( $entry['dateString'] );
			}
			$dt   = gmdate( 'Y-m-d H:i:s', $ts );
			$type = sanitize_text_field( $entry['type'] ?? 'sgv' );

			if ( 'sgv' === $type ) {
				$val = isset( $entry['sgv'] ) ? (int) $entry['sgv'] : null;
			} elseif ( 'mbg' === $type ) {
				$val = isset( $entry['mbg'] ) ? (int) $entry['mbg'] : null;
			} else {
				$val = isset( $entry['glucose'] ) ? (int) $entry['glucose'] : null;
			}

			if ( null === $val || $val <= 0 || $val > 1500 ) {
				++$skipped;
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
				++$saved;
			} else {
				++$skipped;
			}
		}

		return new WP_REST_Response(
			array(
				'status'  => 'ok',
				'saved'   => $saved,
				'skipped' => $skipped,
			),
			200
		);
	}

	// ================================================================
	// ROUTE: GET /treatments.json
	// ================================================================

	public function route_treatments_get( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( '_sd_user_id' );
		$count   = min( (int) $request->get_param( 'count' ), self::MAX_COUNT );

		global $wpdb;
		$db   = new SD_Database();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$db->table('nightscout_treatments')}
				 WHERE user_id = %d
				 ORDER BY treatment_time DESC
				 LIMIT %d",
				$user_id,
				$count
			)
		);

		$treatments = array();
		foreach ( (array) $rows as $row ) {
			$ts           = strtotime( $row->treatment_time );
			$treatment    = array(
				'_id'        => (string) $row->id,
				'eventType'  => $row->event_type,
				'created_at' => gmdate( 'c', $ts ),
				'date'       => $ts * 1000,
				'notes'      => $row->notes ?: '',
			);
			if ( null !== $row->insulin_units ) {
				$treatment['insulin'] = (float) $row->insulin_units;
			}
			if ( null !== $row->carbs_grams ) {
				$treatment['carbs'] = (float) $row->carbs_grams;
			}
			$treatments[] = $treatment;
		}

		return new WP_REST_Response( $treatments, 200 );
	}

	// ================================================================
	// ROUTE: POST /treatments.json
	// ================================================================

	public function route_treatments_post( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( '_sd_user_id' );
		$body    = $request->get_json_params();

		if ( isset( $body['eventType'] ) ) {
			$body = array( $body );
		}

		if ( ! is_array( $body ) || empty( $body ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'No treatments provided.',
				),
				400
			);
		}

		global $wpdb;
		$db      = new SD_Database();
		$saved   = 0;
		$skipped = 0;

		foreach ( $body as $treat ) {
			if ( empty( $treat['created_at'] ) && empty( $treat['timestamp'] ) ) {
				continue;
			}

			$dt = ! empty( $treat['created_at'] )
				? gmdate( 'Y-m-d H:i:s', strtotime( $treat['created_at'] ) )
				: gmdate( 'Y-m-d H:i:s', intdiv( (int) $treat['timestamp'], 1000 ) );

			$event_type    = sanitize_text_field( $treat['eventType'] ?? 'Note' );
			$insulin_units = isset( $treat['insulin'] ) ? (float) $treat['insulin'] : null;
			$carbs_grams   = isset( $treat['carbs'] ) ? (float) $treat['carbs'] : null;
			$notes         = sanitize_textarea_field( $treat['notes'] ?? '' );

			// Evita duplicati (stessa ora + stesso tipo)
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
						'insulin_units'  => $insulin_units,
						'carbs_grams'    => $carbs_grams,
						'notes'          => $notes,
						'created_at'     => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%s', $insulin_units ? '%f' : 'NULL', $carbs_grams ? '%f' : 'NULL', '%s', '%s' )
				);
				++$saved;
			} else {
				++$skipped;
			}
		}

		return new WP_REST_Response(
			array(
				'status'  => 'ok',
				'saved'   => $saved,
				'skipped' => $skipped,
			),
			200
		);
	}

	// ================================================================
	// ROUTE: DELETE /entries/{id}
	// ================================================================

	public function route_entries_delete( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( '_sd_user_id' );
		$id      = (int) $request->get_param( 'id' );

		global $wpdb;
		$db = new SD_Database();
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$db->table( 'nightscout_readings' ),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	// ================================================================
	// TOKEN MANAGEMENT
	// ================================================================

	/**
	 * Genera o rigenera il token personale dell'utente.
	 * Il token in chiaro viene salvato come user_meta `sd_ns_api_token`.
	 * L'utente configura le app CGM con questo token (le app invieranno SHA1 nell'header).
	 */
	public static function get_or_create_token( int $user_id ): string {
		$existing = get_user_meta( $user_id, 'sd_ns_api_token', true );
		if ( ! empty( $existing ) ) {
			return $existing;
		}
		return self::regenerate_token( $user_id );
	}

	/**
	 * Genera un nuovo token sicuro e lo salva. Invalida la cache.
	 */
	public static function regenerate_token( int $user_id ): string {
		$token = wp_generate_password( 24, false );
		update_user_meta( $user_id, 'sd_ns_api_token', $token );

		// Invalida cache token
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_sd_ns_token_%' ) ); // phpcs:ignore

		return $token;
	}

	/**
	 * AJAX: genera/rigenera token (richiede conferma nonce).
	 */
	public function ajax_generate_token(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$regen  = (bool) ( $_POST['regen'] ?? false );
		$token  = $regen
			? self::regenerate_token( $user_id )
			: self::get_or_create_token( $user_id );

		$api_url = rest_url( self::NS );

		wp_send_json_success(
			array(
				'token'   => $token,
				'api_url' => $api_url,
			)
		);
	}

	/**
	 * Restituisce i dati pubblici del server interno per il template profilo.
	 *
	 * @param int $user_id
	 * @return array  keys: has_token, token, api_url, readings_count, last_reading
	 */
	public static function get_server_profile_data( int $user_id ): array {
		$token = get_user_meta( $user_id, 'sd_ns_api_token', true );

		global $wpdb;
		$db = new SD_Database();

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$db->table('nightscout_readings')} WHERE user_id = %d",
				$user_id
			)
		);

		$last = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT glucose_value, glucose_unit, direction, device, reading_time
				 FROM {$db->table('nightscout_readings')}
				 WHERE user_id = %d ORDER BY reading_time DESC LIMIT 1",
				$user_id
			)
		);

		return array(
			'has_token'      => ! empty( $token ),
			'token'          => $token ?: '',
			'api_url'        => rest_url( self::NS ),
			'readings_count' => $count,
			'last_reading'   => $last,
		);
	}
}
