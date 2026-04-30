<?php
/**
 * Integrazione Medtronic CareLink — Auth0 PKCE + Connect API
 *
 * Permette agli utenti con microinfusore Medtronic di collegare il proprio account
 * CareLink e importare automaticamente le letture CGM tramite le API Connect.
 *
 * Flusso di autenticazione:
 *   1. Discovery → SSO config (Auth0 client_id, auth endpoint, token endpoint)
 *   2. PKCE flow: genera code_verifier/challenge → GET authorize URL
 *   3. POST credenziali al form Auth0 → estrae code dal redirect
 *   4. Scambia code per access_token + refresh_token
 *   5. Rinnovo token via refresh_token (senza re-login)
 *
 * Endpoint CGM:
 *   - Moderno (BLE/SimplerA): blePereodicDataEndpoint da country settings
 *   - Legacy: GET /patient/connect/data?cpSerialNumber=NONE&msgType=last24hours
 *
 * @package SD_Logbook
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_CareLink {

	// =========================================================================
	// Costanti
	// =========================================================================

	/** @var string Host server EU */
	const SERVER_EU = 'carelink.minimed.eu';

	/** @var string Host server US */
	const SERVER_US = 'carelink.minimed.com';

	/** @var string Discovery path EU */
	const DISCOVER_PATH = '/connect/carepartner/v13/discover/android/3.6';

	/** @var string Base URL API paziente */
	const PATIENT_BASE = '/patient';

	/** @var string Hook cron per sync automatico */
	const CRON_HOOK = 'sd_carelink_sync_cron';

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
		add_action( 'wp_ajax_sd_carelink_save',       array( $this, 'ajax_save_credentials' ) );
		add_action( 'wp_ajax_sd_carelink_test',       array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_sd_carelink_sync',       array( $this, 'ajax_manual_sync' ) );
		add_action( 'wp_ajax_sd_carelink_disconnect', array( $this, 'ajax_disconnect' ) );

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
		$key     = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$decoded = base64_decode( $encrypted );
		if ( false === $decoded || false === strpos( $decoded, '::' ) ) {
			return '';
		}
		list( $iv_b64, $cipher ) = explode( '::', $decoded, 2 );
		$iv = base64_decode( $iv_b64 );
		if ( false === $iv ) {
			return '';
		}
		$plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Costruisce la base URL per il server selezionato.
	 */
	private function base_url( string $server ): string {
		return 'https://' . $server;
	}

	/**
	 * Determina se usare il server US.
	 */
	private function is_us_server( string $server ): bool {
		return self::SERVER_US === $server;
	}

	/**
	 * Legge la connessione CareLink per un utente.
	 */
	private function get_connection( int $user_id ): ?object {
		global $wpdb;
		$table = $this->table( 'carelink_connections' );
		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE user_id = %d LIMIT 1",
				$user_id
			)
		);
	}

	/**
	 * Mappa il trend CareLink verso il formato Nightscout.
	 */
	private function map_trend( string $trend ): string {
		$map = array(
			'UP_TRIPLE'   => 'TripleUp',
			'UP_DOUBLE'   => 'DoubleUp',
			'UP'          => 'SingleUp',
			'DOWN'        => 'SingleDown',
			'DOWN_DOUBLE' => 'DoubleDown',
			'DOWN_TRIPLE' => 'TripleDown',
			'NONE'        => 'NONE',
		);
		return $map[ $trend ] ?? 'NONE';
	}

	/**
	 * Converte il timestamp stringa CareLink (es. "04/29/2026 10:00:00") in epoch UTC.
	 * Formato: MM/DD/YYYY HH:MM:SS (UTC)
	 */
	private function parse_timestamp( string $ts ): int|false {
		// Formato: MM/DD/YYYY HH:MM:SS
		$dt = \DateTime::createFromFormat( 'm/d/Y H:i:s', $ts, new \DateTimeZone( 'UTC' ) );
		if ( $dt ) {
			return $dt->getTimestamp();
		}
		return strtotime( $ts );
	}

	// =========================================================================
	// Autenticazione CareLink — Auth0 PKCE
	// =========================================================================

	/**
	 * Recupera la configurazione SSO (Discovery) per il server specificato.
	 *
	 * @param string $server Self::SERVER_EU o self::SERVER_US.
	 * @return array{sso_url:string, client_id:string, token_url:string, redirect_uri:string, scope:string, audience:string}|WP_Error
	 */
	private function carelink_discover( string $server ) {
		$url = $this->base_url( $server ) . self::DISCOVER_PATH;

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== (int) $code || ! is_array( $data ) ) {
			return new WP_Error(
				'carelink_discover_failed',
				/* translators: %1$d=HTTP code, %2$s=body */
				sprintf( __( 'CareLink discovery fallita (HTTP %1$d): %2$s', 'sd-logbook' ), $code, $raw )
			);
		}

		// Estrae la configurazione SSO dalla risposta discovery
		$sso_base    = $data['device']['authorization']['ssoServerUrl']     ?? '';
		$client_id   = $data['device']['authorization']['ssoClientId']      ?? '';
		$token_path  = $data['device']['authorization']['ssoTokenUri']      ?? '';
		$redirect    = $data['device']['authorization']['ssoRedirectUri']   ?? '';
		$scope       = $data['device']['authorization']['ssoScope']         ?? 'openid profile email';
		$audience    = $data['device']['authorization']['ssoAudience']      ?? '';

		if ( empty( $sso_base ) || empty( $client_id ) ) {
			return new WP_Error(
				'carelink_discover_invalid',
				__( 'Risposta discovery CareLink non valida: dati SSO mancanti.', 'sd-logbook' )
			);
		}

		// token_url può essere assoluto o un path relativo all'SSO base
		$token_url = ( 0 === strpos( $token_path, 'http' ) )
			? $token_path
			: rtrim( $sso_base, '/' ) . '/' . ltrim( $token_path, '/' );

		// sso_url = base Auth0 authorize endpoint (senza path)
		$sso_url = rtrim( $sso_base, '/' );

		return array(
			'sso_url'      => $sso_url,
			'client_id'    => $client_id,
			'token_url'    => $token_url,
			'redirect_uri' => $redirect,
			'scope'        => $scope,
			'audience'     => $audience,
		);
	}

	/**
	 * Genera code_verifier e code_challenge per PKCE.
	 *
	 * @return array{verifier:string, challenge:string}
	 */
	private function generate_pkce(): array {
		$verifier  = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return array(
			'verifier'  => $verifier,
			'challenge' => $challenge,
		);
	}

	/**
	 * Esegue il login CareLink tramite Auth0 PKCE.
	 *
	 * Flusso:
	 *   1. Discovery → SSO config
	 *   2. GET authorize con PKCE → form HTML Auth0
	 *   3. POST credenziali → redirect con ?code=
	 *   4. Exchange code → tokens
	 *
	 * @param string $username Username o email CareLink.
	 * @param string $password Password CareLink.
	 * @param string $server   Self::SERVER_EU o self::SERVER_US.
	 * @return array{access_token:string, refresh_token:string, token_url:string, client_id:string, expires_at:string}|WP_Error
	 */
	public function carelink_login( string $username, string $password, string $server = self::SERVER_EU ) {
		// Fase 1: Discovery
		$sso = $this->carelink_discover( $server );
		if ( is_wp_error( $sso ) ) {
			return $sso;
		}

		// Fase 2: PKCE + authorize URL
		$pkce  = $this->generate_pkce();
		$state = bin2hex( random_bytes( 16 ) );

		$auth_url = add_query_arg(
			array(
				'response_type'         => 'code',
				'client_id'             => $sso['client_id'],
				'redirect_uri'          => $sso['redirect_uri'],
				'scope'                 => $sso['scope'],
				'state'                 => $state,
				'code_challenge'        => $pkce['challenge'],
				'code_challenge_method' => 'S256',
				'audience'              => $sso['audience'],
			),
			$sso['sso_url'] . '/authorize'
		);

		// Richiesta GET per ottenere il form di login Auth0 (con cookies)
		$cookies  = array();
		$response = wp_remote_get(
			$auth_url,
			array(
				'timeout'     => 20,
				'sslverify'   => true,
				'redirection' => 5,
				'headers'     => array(
					'User-Agent' => 'Mozilla/5.0 (Android 13; Mobile) CareLink/3.6',
					'Accept'     => 'text/html,application/xhtml+xml',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// Raccoglie i cookie di sessione Auth0
		$raw_cookies = wp_remote_retrieve_header( $response, 'set-cookie' );
		if ( ! is_array( $raw_cookies ) ) {
			$raw_cookies = $raw_cookies ? array( $raw_cookies ) : array();
		}
		foreach ( $raw_cookies as $raw ) {
			$parts = explode( ';', $raw );
			if ( ! empty( $parts[0] ) && false !== strpos( $parts[0], '=' ) ) {
				list( $cname, $cval ) = explode( '=', $parts[0], 2 );
				$cookies[ trim( $cname ) ] = trim( $cval );
			}
		}

		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'carelink_auth_page_failed',
				/* translators: %d=HTTP code */
				sprintf( __( 'Pagina login CareLink non raggiungibile (HTTP %d).', 'sd-logbook' ), $code )
			);
		}

		// Controlla se CAPTCHA è richiesta
		if ( false !== stripos( $body, 'captcha' ) || false !== stripos( $body, 'recaptcha' ) ) {
			return new WP_Error(
				'carelink_captcha',
				__( 'CareLink richiede la verifica CAPTCHA. Accedi manualmente su carelink.minimed.eu nel browser, completa il CAPTCHA e riprova tra qualche minuto.', 'sd-logbook' )
			);
		}

		// Estrae action URL del form di login e hidden fields
		$form_action = '';
		if ( preg_match( '/<form[^>]+action=["\']([^"\']+)["\'][^>]*>/i', $body, $m ) ) {
			$form_action = html_entity_decode( $m[1] );
		}

		// Se form_action è relativo, lo rende assoluto
		if ( $form_action && 0 !== strpos( $form_action, 'http' ) ) {
			$parsed   = wp_parse_url( $sso['sso_url'] );
			$base_origin = $parsed['scheme'] . '://' . $parsed['host'];
			$form_action = $base_origin . $form_action;
		}

		if ( empty( $form_action ) ) {
			// Prova a usare l'URL authorize come action fallback
			$form_action = $auth_url;
		}

		// Estrae tutti gli hidden input
		$hidden_fields = array();
		preg_match_all( '/<input[^>]+type=["\']hidden["\'][^>]*>/i', $body, $inputs );
		foreach ( $inputs[0] as $inp ) {
			$name = $val = '';
			if ( preg_match( '/name=["\']([^"\']+)["\']/i', $inp, $nm ) ) {
				$name = $nm[1];
			}
			if ( preg_match( '/value=["\']([^"\']*)["\']?/i', $inp, $vl ) ) {
				$val = html_entity_decode( $vl[1] );
			}
			if ( $name ) {
				$hidden_fields[ $name ] = $val;
			}
		}

		// Costruisce il body del POST con credenziali + hidden fields
		$post_body = array_merge(
			$hidden_fields,
			array(
				'username' => $username,
				'password' => $password,
			)
		);

		// Fase 3: POST credenziali
		$cookie_header = '';
		foreach ( $cookies as $cn => $cv ) {
			$cookie_header .= $cn . '=' . $cv . '; ';
		}

		$post_response = wp_remote_post(
			$form_action,
			array(
				'timeout'     => 20,
				'sslverify'   => true,
				'redirection' => 0, // NON seguire i redirect automaticamente
				'headers'     => array(
					'User-Agent'   => 'Mozilla/5.0 (Android 13; Mobile) CareLink/3.6',
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Referer'      => $auth_url,
					'Cookie'       => rtrim( $cookie_header, '; ' ),
					'Accept'       => 'text/html,application/xhtml+xml',
				),
				'body'        => http_build_query( $post_body ),
			)
		);

		if ( is_wp_error( $post_response ) ) {
			return $post_response;
		}

		$post_code     = wp_remote_retrieve_response_code( $post_response );
		$post_location = wp_remote_retrieve_header( $post_response, 'location' );
		$post_body_raw = wp_remote_retrieve_body( $post_response );

		// Auth0 risponde con 302 redirect verso redirect_uri?code=...
		$auth_code = '';

		if ( $post_location ) {
			$parsed_loc = wp_parse_url( $post_location );
			wp_parse_str( $parsed_loc['query'] ?? '', $query_params );
			$auth_code = $query_params['code'] ?? '';

			// Se il redirect è verso la login page con error, le credenziali sono sbagliate
			if ( ! empty( $query_params['error'] ) ) {
				$err_desc = $query_params['error_description'] ?? $query_params['error'];
				if ( false !== stripos( $err_desc, 'captcha' ) || false !== stripos( $err_desc, 'bot' ) ) {
					return new WP_Error(
						'carelink_captcha',
						__( 'CareLink richiede la verifica CAPTCHA. Accedi manualmente su carelink.minimed.eu nel browser, completa il CAPTCHA e riprova tra qualche minuto.', 'sd-logbook' )
					);
				}
				return new WP_Error(
					'carelink_auth_error',
					/* translators: %s=error description */
					sprintf( __( 'Autenticazione CareLink fallita: %s', 'sd-logbook' ), urldecode( $err_desc ) )
				);
			}
		}

		// Fallback: cerca il code nel body (alcune varianti di Auth0)
		if ( empty( $auth_code ) && $post_body_raw ) {
			if ( preg_match( '/[?&]code=([A-Za-z0-9_\-]+)/i', $post_body_raw, $cm ) ) {
				$auth_code = $cm[1];
			}
			// Controlla CAPTCHA nel body del form di risposta
			if ( empty( $auth_code ) && (
				false !== stripos( $post_body_raw, 'captcha' ) ||
				false !== stripos( $post_body_raw, 'recaptcha' )
			) ) {
				return new WP_Error(
					'carelink_captcha',
					__( 'CareLink richiede la verifica CAPTCHA. Accedi manualmente su carelink.minimed.eu nel browser, completa il CAPTCHA e riprova tra qualche minuto.', 'sd-logbook' )
				);
			}
		}

		if ( empty( $auth_code ) ) {
			// Controlla se le credenziali sono errate (risposta 200 = ritorno al form di login)
			if ( 200 === (int) $post_code && false !== stripos( $post_body_raw, 'wrong' ) ) {
				return new WP_Error(
					'carelink_wrong_credentials',
					__( 'Username o password CareLink errati. Verifica le credenziali.', 'sd-logbook' )
				);
			}
			return new WP_Error(
				'carelink_no_code',
				/* translators: %1$d=HTTP code, %2$s=location */
				sprintf(
					__( 'CareLink non ha restituito il codice di autorizzazione (HTTP %1$d, location: %2$s). Verifica username e password.', 'sd-logbook' ),
					$post_code,
					$post_location ?: 'nessuno'
				)
			);
		}

		// Fase 4: scambia code per tokens
		$token_result = $this->exchange_code_for_tokens(
			$auth_code,
			$pkce['verifier'],
			$sso['client_id'],
			$sso['token_url'],
			$sso['redirect_uri']
		);

		if ( is_wp_error( $token_result ) ) {
			return $token_result;
		}

		$token_result['token_url'] = $sso['token_url'];
		$token_result['client_id'] = $sso['client_id'];
		return $token_result;
	}

	/**
	 * Scambia il codice di autorizzazione per i token.
	 *
	 * @param string $code         Codice di autorizzazione OAuth2.
	 * @param string $code_verifier PKCE code verifier.
	 * @param string $client_id    Client ID Auth0.
	 * @param string $token_url    Token endpoint.
	 * @param string $redirect_uri Redirect URI.
	 * @return array{access_token:string, refresh_token:string, expires_at:string}|WP_Error
	 */
	private function exchange_code_for_tokens(
		string $code,
		string $code_verifier,
		string $client_id,
		string $token_url,
		string $redirect_uri
	) {
		$response = wp_remote_post(
			$token_url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'      => http_build_query(
					array(
						'grant_type'    => 'authorization_code',
						'client_id'     => $client_id,
						'code'          => $code,
						'code_verifier' => $code_verifier,
						'redirect_uri'  => $redirect_uri,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code_http = wp_remote_retrieve_response_code( $response );
		$raw       = wp_remote_retrieve_body( $response );
		$data      = json_decode( $raw, true );

		if ( 200 !== (int) $code_http || empty( $data['access_token'] ) ) {
			$err_msg = $data['error_description'] ?? $data['error'] ?? $raw;
			return new WP_Error(
				'carelink_token_exchange_failed',
				/* translators: %1$d=HTTP code, %2$s=error */
				sprintf( __( 'Scambio token CareLink fallito (HTTP %1$d): %2$s', 'sd-logbook' ), $code_http, $err_msg )
			);
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $expires_in - 60 );

		return array(
			'access_token'  => $data['access_token'],
			'refresh_token' => $data['refresh_token'] ?? '',
			'expires_at'    => $expires_at,
		);
	}

	/**
	 * Rinnova l'access token tramite refresh_token.
	 *
	 * @param object $conn Riga connessione DB.
	 * @return array{access_token:string, refresh_token:string, expires_at:string}|WP_Error
	 */
	private function refresh_access_token( object $conn ) {
		$refresh_token = $this->decrypt( $conn->refresh_token_enc );
		if ( empty( $refresh_token ) ) {
			return new WP_Error( 'carelink_no_refresh_token', __( 'Refresh token CareLink non disponibile. Riconnetti l\'account.', 'sd-logbook' ) );
		}

		$response = wp_remote_post(
			$conn->token_url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'      => http_build_query(
					array(
						'grant_type'    => 'refresh_token',
						'client_id'     => $conn->client_id,
						'refresh_token' => $refresh_token,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code_http = wp_remote_retrieve_response_code( $response );
		$raw       = wp_remote_retrieve_body( $response );
		$data      = json_decode( $raw, true );

		if ( 200 !== (int) $code_http || empty( $data['access_token'] ) ) {
			$err_msg = $data['error_description'] ?? $data['error'] ?? $raw;
			return new WP_Error(
				'carelink_refresh_failed',
				/* translators: %s=error description */
				sprintf( __( 'Rinnovo token CareLink fallito: %s. Potrebbe essere necessario riconnettersi.', 'sd-logbook' ), $err_msg )
			);
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $expires_in - 60 );

		return array(
			'access_token'  => $data['access_token'],
			'refresh_token' => $data['refresh_token'] ?? $refresh_token,
			'expires_at'    => $expires_at,
		);
	}

	/**
	 * Ottiene o rinnova l'access token per una connessione.
	 *
	 * @param object $conn Riga connessione DB.
	 * @return array{access_token:string}|WP_Error
	 */
	private function get_or_refresh_token( object $conn ) {
		global $wpdb;

		// Usa il token in cache se non è scaduto
		if (
			! empty( $conn->access_token ) &&
			! empty( $conn->token_expires_at ) &&
			strtotime( $conn->token_expires_at ) > time() + 120
		) {
			return array( 'access_token' => $conn->access_token );
		}

		// Prova refresh token
		$refreshed = $this->refresh_access_token( $conn );

		if ( is_wp_error( $refreshed ) ) {
			// Se il refresh fallisce, forza re-login
			$password = $this->decrypt( $conn->password_enc );
			if ( empty( $password ) ) {
				return $refreshed; // non possiamo fare niente
			}
			$login = $this->carelink_login( $conn->carelink_username, $password, $conn->server );
			if ( is_wp_error( $login ) ) {
				return $login;
			}
			$refreshed = array(
				'access_token'  => $login['access_token'],
				'refresh_token' => $login['refresh_token'],
				'expires_at'    => $login['expires_at'],
			);
		}

		// Persiste i token aggiornati
		$table = $this->table( 'carelink_connections' );
		$wpdb->update(
			$table,
			array(
				'access_token'     => $refreshed['access_token'],
				'refresh_token_enc' => $this->encrypt( $refreshed['refresh_token'] ),
				'token_expires_at' => $refreshed['expires_at'],
			),
			array( 'user_id' => (int) $conn->user_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return array( 'access_token' => $refreshed['access_token'] );
	}

	// =========================================================================
	// Fetch dati CGM dal server CareLink
	// =========================================================================

	/**
	 * Costruisce gli header per le chiamate API paziente.
	 */
	private function build_api_headers( string $access_token ): array {
		return array(
			'Authorization' => 'Bearer ' . $access_token,
			'Accept'        => 'application/json',
			'User-Agent'    => 'CareLink/3.6 Android',
		);
	}

	/**
	 * Recupera le impostazioni del paese per determinare l'endpoint BLE.
	 *
	 * @param string $access_token Token di accesso.
	 * @param string $server       Server (EU/US).
	 * @param string $country_code Codice paese (es. 'ch', 'it', 'us').
	 * @return string|null URL endpoint BLE oppure null.
	 */
	private function get_ble_endpoint( string $access_token, string $server, string $country_code ): ?string {
		$url = $this->base_url( $server ) . self::PATIENT_BASE . '/countries/settings'
			. '?countryCode=' . strtoupper( $country_code )
			. '&language=' . strtolower( $country_code );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => $this->build_api_headers( $access_token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		return $data['blePereodicDataEndpoint'] ?? null;
	}

	/**
	 * Recupera dati CGM dal monitor (endpoint moderno BLE).
	 *
	 * @param string $access_token Token di accesso.
	 * @param string $server       Server EU/US.
	 * @return array|WP_Error
	 */
	private function fetch_monitor_data( string $access_token, string $server ) {
		$url = $this->base_url( $server ) . self::PATIENT_BASE . '/monitor/data';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => $this->build_api_headers( $access_token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== (int) $code ) {
			return new WP_Error( 'carelink_monitor_failed', sprintf( 'HTTP %d', $code ) );
		}

		return $data;
	}

	/**
	 * Recupera dati CGM dall'endpoint BLE esterno (SimplerA, Guardian 4 BLE).
	 *
	 * @param string $access_token Token di accesso.
	 * @param string $ble_url      URL endpoint BLE da country settings.
	 * @return array|WP_Error
	 */
	private function fetch_ble_data( string $access_token, string $ble_url ) {
		$response = wp_remote_get(
			$ble_url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => $this->build_api_headers( $access_token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== (int) $code ) {
			return new WP_Error( 'carelink_ble_failed', sprintf( 'HTTP %d', $code ) );
		}

		return $data;
	}

	/**
	 * Recupera dati CGM dall'endpoint legacy (vecchi pompe, GUARDIAN 3).
	 *
	 * @param string $access_token Token di accesso.
	 * @param string $server       Server EU/US.
	 * @return array|WP_Error
	 */
	private function fetch_legacy_data( string $access_token, string $server ) {
		$url = $this->base_url( $server ) . self::PATIENT_BASE . '/connect/data'
			. '?cpSerialNumber=NONE&msgType=last24hours&requestTime=' . rawurlencode( gmdate( 'Y-m-d\TH:i:s.000\Z' ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => $this->build_api_headers( $access_token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== (int) $code ) {
			return new WP_Error( 'carelink_legacy_failed', sprintf( 'HTTP %d: %s', $code, $raw ) );
		}

		return $data;
	}

	/**
	 * Recupera dati CGM usando la strategia ottimale per il dispositivo.
	 *
	 * Strategia:
	 *   1. Prova endpoint moderno /monitor/data
	 *   2. Se dispositivo BLE/SIMPLERA → usa blePereodicDataEndpoint
	 *   3. Altrimenti → fallback legacy /connect/data
	 *
	 * @param string $access_token Token di accesso.
	 * @param string $server       Server EU/US.
	 * @param string $country_code Codice paese.
	 * @return array{sgs:array, device:string}|WP_Error
	 */
	private function fetch_cgm_data( string $access_token, string $server, string $country_code ) {
		// Prova monitor moderno
		$monitor = $this->fetch_monitor_data( $access_token, $server );

		if ( ! is_wp_error( $monitor ) ) {
			$device_family = strtoupper( $monitor['medicalDeviceFamily'] ?? '' );

			// BLE / SimplerA → usa endpoint BLE dedicato
			if (
				false !== strpos( $device_family, 'BLE' ) ||
				false !== strpos( $device_family, 'SIMPLERA' )
			) {
				$ble_url = $this->get_ble_endpoint( $access_token, $server, $country_code );
				if ( $ble_url ) {
					$ble_data = $this->fetch_ble_data( $access_token, $ble_url );
					if ( ! is_wp_error( $ble_data ) ) {
						return array(
							'sgs'    => $ble_data['sgs'] ?? array(),
							'device' => 'CareLink/' . $device_family,
						);
					}
				}
			}

			// GUARDIAN / altri moderni → usa i dati dal monitor
			return array(
				'sgs'    => $monitor['sgs'] ?? array(),
				'device' => 'CareLink/' . ( $monitor['medicalDeviceFamily'] ?? 'Unknown' ),
			);
		}

		// Fallback legacy
		$legacy = $this->fetch_legacy_data( $access_token, $server );
		if ( is_wp_error( $legacy ) ) {
			return $legacy;
		}

		return array(
			'sgs'    => $legacy['sgs'] ?? array(),
			'device' => 'CareLink/Legacy',
		);
	}

	// =========================================================================
	// Salvataggio letture
	// =========================================================================

	/**
	 * Salva le letture CGM CareLink nella tabella condivisa sd_nightscout_readings.
	 *
	 * @param int    $user_id ID utente WP.
	 * @param array  $sgs     Array di letture CareLink ({sg, datetime, trend, kind}).
	 * @param string $device  Nome dispositivo.
	 * @return int Numero di letture salvate.
	 */
	private function save_readings( int $user_id, array $sgs, string $device ): int {
		global $wpdb;
		$table    = $wpdb->prefix . 'sd_nightscout_readings';
		$inserted = 0;

		foreach ( $sgs as $entry ) {
			// Salta letture non di tipo SG o con valore 0
			if ( isset( $entry['kind'] ) && 'SG' !== $entry['kind'] ) {
				continue;
			}
			$sg_value = isset( $entry['sg'] ) ? (int) $entry['sg'] : 0;
			if ( $sg_value <= 0 ) {
				continue;
			}

			$ts_raw = $entry['datetime'] ?? '';
			$ts     = $this->parse_timestamp( $ts_raw );
			if ( false === $ts || $ts <= 0 ) {
				continue;
			}

			$reading_time = gmdate( 'Y-m-d H:i:s', $ts );

			// Mappa trend
			$trend = isset( $entry['trend'] ) ? $this->map_trend( (string) $entry['trend'] ) : 'NONE';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT IGNORE INTO {$table}
						(user_id, reading_time, glucose_value, direction, device)
					 VALUES (%d, %s, %d, %s, %s)",
					$user_id,
					$reading_time,
					$sg_value,
					$trend,
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
	 * Esegue il sync CareLink per un singolo utente.
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
				'message'  => __( 'Sync disabilitato o nessuna connessione CareLink.', 'sd-logbook' ),
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

		$data = $this->fetch_cgm_data( $auth['access_token'], $conn->server, $conn->country_code ?? 'ch' );
		if ( is_wp_error( $data ) ) {
			return array(
				'ok'       => false,
				'inserted' => 0,
				'message'  => $data->get_error_message(),
			);
		}

		$inserted = 0;
		if ( ! empty( $data['sgs'] ) ) {
			$inserted = $this->save_readings( $user_id, $data['sgs'], $data['device'] );
		}

		// Aggiorna last_sync_at
		$table = $this->table( 'carelink_connections' );
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
			'message'  => sprintf(
				_n( '%d lettura sincronizzata da CareLink.', '%d letture sincronizzate da CareLink.', $inserted, 'sd-logbook' ),
				$inserted
			),
		);
	}

	// =========================================================================
	// Cron
	// =========================================================================

	/**
	 * Sync automatico di tutti gli utenti con CareLink abilitato.
	 */
	public function cron_sync_all(): void {
		global $wpdb;
		$table = $this->table( 'carelink_connections' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT user_id FROM {$table} WHERE sync_enabled = 1" );
		foreach ( $rows as $row ) {
			$this->sync_user( (int) $row->user_id );
		}
	}

	/**
	 * Programma il cron CareLink (se non già attivo).
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Rimuove il cron CareLink.
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
	 * AJAX: salva le credenziali CareLink (con validazione login).
	 */
	public function ajax_save_credentials(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$username     = sanitize_text_field( wp_unslash( $_POST['carelink_username'] ?? '' ) );
		$password     = wp_unslash( $_POST['carelink_password'] ?? '' );
		$password     = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $password );
		$server       = sanitize_text_field( wp_unslash( $_POST['carelink_server'] ?? self::SERVER_EU ) );
		$country_code = sanitize_text_field( wp_unslash( $_POST['carelink_country'] ?? 'ch' ) );

		// Valida server
		if ( ! in_array( $server, array( self::SERVER_EU, self::SERVER_US ), true ) ) {
			$server = self::SERVER_EU;
		}
		$country_code = strtolower( substr( $country_code, 0, 5 ) );

		if ( empty( $username ) ) {
			wp_send_json_error( array( 'message' => __( 'Inserisci il tuo username CareLink.', 'sd-logbook' ) ) );
		}
		if ( empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'La password è obbligatoria.', 'sd-logbook' ) ) );
		}

		// Verifica le credenziali PRIMA di salvare
		$login = $this->carelink_login( $username, $password, $server );
		if ( is_wp_error( $login ) ) {
			wp_send_json_error( array( 'message' => $login->get_error_message() ) );
		}

		global $wpdb;
		$table    = $this->table( 'carelink_connections' );
		$existing = $this->get_connection( $user_id );

		$data = array(
			'carelink_username'  => $username,
			'password_enc'       => $this->encrypt( $password ),
			'server'             => $server,
			'country_code'       => $country_code,
			'access_token'       => $login['access_token'],
			'refresh_token_enc'  => $this->encrypt( $login['refresh_token'] ),
			'token_url'          => $login['token_url'],
			'client_id'          => $login['client_id'],
			'token_expires_at'   => $login['expires_at'],
			'sync_enabled'       => 1,
		);

		if ( $existing ) {
			$result = $wpdb->update( $table, $data, array( 'user_id' => $user_id ), null, array( '%d' ) );
		} else {
			$data['user_id'] = $user_id;
			$result          = $wpdb->insert( $table, $data );
		}

		if ( false === $result ) {
			wp_send_json_error(
				array(
					'message' => __( 'Errore nel salvataggio delle credenziali. La tabella CareLink potrebbe non essere stata creata: riattiva il plugin.', 'sd-logbook' ),
				)
			);
		}

		wp_send_json_success( array( 'message' => __( 'Credenziali CareLink salvate e connessione verificata.', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX: testa la connessione CareLink.
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		$post_username = sanitize_text_field( wp_unslash( $_POST['carelink_username'] ?? '' ) );
		$post_password = wp_unslash( $_POST['carelink_password'] ?? '' );
		$post_password = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $post_password );
		$post_server   = sanitize_text_field( wp_unslash( $_POST['carelink_server'] ?? '' ) );

		if ( ! empty( $post_username ) && ! empty( $post_password ) ) {
			$test_username = $post_username;
			$test_password = $post_password;
			$test_server   = in_array( $post_server, array( self::SERVER_EU, self::SERVER_US ), true )
				? $post_server : self::SERVER_EU;
		} else {
			$conn = $this->get_connection( $user_id );
			if ( ! $conn ) {
				wp_send_json_error( array( 'message' => __( 'Nessuna connessione CareLink configurata.', 'sd-logbook' ) ) );
			}
			$test_username = $conn->carelink_username;
			$test_password = $this->decrypt( $conn->password_enc );
			$test_server   = $conn->server;
		}

		$login = $this->carelink_login( $test_username, $test_password, $test_server );
		if ( is_wp_error( $login ) ) {
			wp_send_json_error( array( 'message' => $login->get_error_message() ) );
		}

		// Recupera dati per mostrare ultima lettura
		$conn_obj = $this->get_connection( $user_id );
		$country  = $conn_obj ? ( $conn_obj->country_code ?? 'ch' ) : 'ch';

		$data = $this->fetch_cgm_data( $login['access_token'], $test_server, $country );
		if ( is_wp_error( $data ) ) {
			wp_send_json_success(
				array( 'message' => __( 'Connessione CareLink OK. Impossibile recuperare dati CGM al momento.', 'sd-logbook' ) )
			);
			return;
		}

		$sgs    = $data['sgs'] ?? array();
		$last   = null;
		foreach ( array_reverse( $sgs ) as $entry ) {
			if ( isset( $entry['kind'] ) && 'SG' !== $entry['kind'] ) {
				continue;
			}
			if ( ! empty( $entry['sg'] ) && (int) $entry['sg'] > 0 ) {
				$last = $entry;
				break;
			}
		}

		$last_msg = '';
		if ( $last ) {
			$last_msg = sprintf(
				/* translators: %d=valore glucosio mg/dL */
				__( ' Ultima lettura: %d mg/dL.', 'sd-logbook' ),
				(int) $last['sg']
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d=numero letture */
					_n( 'Connessione CareLink OK. %d lettura disponibile.', 'Connessione CareLink OK. %d letture disponibili.', count( $sgs ), 'sd-logbook' ),
					count( $sgs )
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
	 * AJAX: disconnetti account CareLink.
	 */
	public function ajax_disconnect(): void {
		check_ajax_referer( 'sd_profile_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$table = $this->table( 'carelink_connections' );
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );

		wp_send_json_success( array( 'message' => __( 'Account CareLink disconnesso.', 'sd-logbook' ) ) );
	}

	// =========================================================================
	// Dati per il template
	// =========================================================================

	/**
	 * Restituisce tutti i dati CareLink necessari al template profile.php.
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
				'username'       => '',
				'server'         => self::SERVER_EU,
				'country_code'   => 'ch',
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
				'CareLink%'
			)
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$t_read} WHERE user_id = %d AND device LIKE %s",
				$user_id,
				'CareLink%'
			)
		);

		return array(
			'connected'      => true,
			'username'       => $conn->carelink_username,
			'server'         => $conn->server,
			'country_code'   => $conn->country_code ?? 'ch',
			'sync_enabled'   => (bool) $conn->sync_enabled,
			'last_sync_at'   => $conn->last_sync_at,
			'last_glucose'   => $last ? (int) $last->glucose_value : null,
			'last_trend'     => $last ? $last->direction : null,
			'last_read_time' => $last ? $last->reading_time : null,
			'readings_count' => $count,
		);
	}
}
