<?php
/**
 * Impostazioni admin per sistema pagamenti soci.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Payment_Settings {

	/** @var string */
	const PAGE_SLUG = 'sd-payment-settings';

	/** @var string */
	const OPTION_GROUP = 'sd_payment_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_sd_payment_create_pages', array( $this, 'handle_create_pages' ) );
		add_action( 'wp_ajax_sd_load_ik_categories', array( $this, 'ajax_load_ik_categories' ) );
	}

	/**
	 * Registra pagina menu impostazioni.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Pagamenti Soci', 'sd-logbook' ),
			__( 'Pagamenti Soci', 'sd-logbook' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registra opzioni WP.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_paypal_mode',
			array(
				'sanitize_callback' => array( $this, 'sanitize_paypal_mode' ),
				'default'           => 'sandbox',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_paypal_client_id',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_paypal_secret',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_checkout_page_url',
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_confirmation_page_url',
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_login_url',
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => home_url( '/login/' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_enable_paypal',
			array(
				'sanitize_callback' => 'absint',
				'default'           => 1,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_enable_invoice',
			array(
				'sanitize_callback' => 'absint',
				'default'           => 1,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_enable_twint_stub',
			array(
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_provider',
			array(
				'sanitize_callback' => array( $this, 'sanitize_twint_provider' ),
				'default'           => 'direct',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_ik_key',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_ik_event_id',
			array(
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_ik_shop_id',
			array(
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		foreach ( array( 30, 50, 75 ) as $_tier ) {
			register_setting(
				self::OPTION_GROUP,
				'sd_payment_twint_ik_category_id_' . $_tier,
				array(
					'sanitize_callback' => 'absint',
					'default'           => 0,
				)
			);
		}
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_mode',
			array(
				'sanitize_callback' => array( $this, 'sanitize_twint_mode' ),
				'default'           => 'sandbox',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_sandbox_api_url',
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => 'https://sandbox.twint.ch/v1',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_live_api_url',
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => 'https://api.twint.ch/v1',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_store_uuid',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_api_key',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_cashregister_ref',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'SD-LOGBOOK',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_cert_path',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_twint_cert_password',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_association_title',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'Associazione ScubaDiabetes',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_brand_primary',
			array(
				'sanitize_callback' => array( $this, 'sanitize_hex_color_value' ),
				'default'           => '#0055A5',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_brand_secondary',
			array(
				'sanitize_callback' => array( $this, 'sanitize_hex_color_value' ),
				'default'           => '#00A3D8',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_receipt_footer_note',
			array(
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => 'Documento gestionale emesso dall\'associazione. Validita fiscale subordinata alla normativa applicabile e a verifica professionale in CH/IT.',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_association_name',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'Associazione ScubaDiabetes',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_association_address',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_association_postal_code',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_association_city',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_association_email',
			array(
				'sanitize_callback' => 'sanitize_email',
				'default'           => get_bloginfo( 'admin_email' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_association_phone',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_bank_name',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_bank_address',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_bank_postal_code',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_bank_city',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_bank_iban',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_bank_swift',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_bank_bic',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_qr_payload',
			array(
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_payment_invoice_qr_image_url',
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
	}

	/**
	 * Sanitizza modalita paypal.
	 *
	 * @param string $value valore.
	 * @return string
	 */
	public function sanitize_paypal_mode( $value ) {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, array( 'sandbox', 'live' ), true ) ? $value : 'sandbox';
	}

	/**
	 * Sanitizza modalita twint.
	 *
	 * @param string $value valore.
	 * @return string
	 */
	public function sanitize_twint_mode( $value ) {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, array( 'sandbox', 'live' ), true ) ? $value : 'sandbox';
	}

	/**
	 * Sanitizza provider TWINT.
	 *
	 * @param string $value provider.
	 * @return string
	 */
	public function sanitize_twint_provider( $value ) {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, array( 'direct', 'infomaniak' ), true ) ? $value : 'direct';
	}

	/**
	 * Sanitizza colore hex.
	 *
	 * @param string $value colore.
	 * @return string
	 */
	public function sanitize_hex_color_value( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( preg_match( '/^#[A-Fa-f0-9]{6}$/', $value ) ) {
			return strtoupper( $value );
		}
		return '#0055A5';
	}

	/**
	 * AJAX: carica categorie Infomaniak per un dato event_id.
	 *
	 * @return void
	 */
	public function ajax_load_ik_categories() {
		check_ajax_referer( 'sd_ik_categories', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permesso negato.' ), 403 );
		}

		$event_id = absint( $_POST['event_id'] ?? 0 );
		$shop_id  = absint( $_POST['shop_id'] ?? 0 );

		if ( $event_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Event ID non valido.' ) );
		}

		$api_key = trim( (string) get_option( 'sd_payment_twint_ik_key', '' ) );
		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => 'API Key Infomaniak non configurata. Salva prima le impostazioni.' ) );
		}

		$base    = 'https://etickets.infomaniak.com/api/shop';
		$kparam  = '?key=' . rawurlencode( $api_key );
		$kparam2 = '&key=' . rawurlencode( $api_key );

		// Candidati senza shop_id nel path (tutti i /{shop}/... restituiscono 405).
		// Per ogni path proviamo sia header (cURL) sia query-param (bypass proxy CDN).
		$candidates = array(
			// Tariff con query param (aggira strip header da CDN).
			'/event/{id}/tariffs?key'    => $base . '/event/' . $event_id . '/tariffs' . $kparam,
			// Header only.
			'/event/{id}/tariffs'        => $base . '/event/' . $event_id . '/tariffs',
			// Categorie con query param.
			'/event/{id}/categories?key' => $base . '/event/' . $event_id . '/categories' . $kparam,
			// Tutti gli eventi dello shop (lista).
			'/events?key'                => $base . '/events' . $kparam,
			// Dettaglio evento con query param.
			'/event/{id}?key'            => $base . '/event/' . $event_id . $kparam,
			// Tariffe globali con event_id + key.
			'/tariffs?event_id&key'      => $base . '/tariffs?event_id=' . $event_id . $kparam2,
			// Fallback: lista tariffe senza event_id filter.
			'/tariffs?key'               => $base . '/tariffs' . $kparam,
		);

		$attempts = array();
		foreach ( $candidates as $label => $url ) {
			$result             = $this->fetch_ik_url( $url, $api_key );
			$code               = $result['code'];
			$body               = $result['body'];
			$attempts[ $label ] = array(
				'code'   => $code,
				'method' => $result['method'] ?? '?',
				'body'   => substr( $body, 0, 600 ),
			);

			if ( $code >= 200 && $code < 300 ) {
				$data       = json_decode( $body, true );
				$categories = $this->extract_ik_categories( $data );
				wp_send_json_success(
					array(
						'categories' => $categories,
						'endpoint'   => $label . ' ' . $url,
						'raw'        => $body,
					)
				);
			}
		}

		wp_send_json_error(
			array(
				'message'  => 'Nessun endpoint ha risposto con successo. Vedi dettagli debug.',
				'attempts' => $attempts,
			)
		);
	}

	/**
	 * Esegue una richiesta GET usando cURL direttamente (se disponibile) per
	 * garantire che l'header `key` non venga strippato da WP_Http.
	 *
	 * @param string $url     URL da chiamare.
	 * @param string $api_key Chiave API Infomaniak.
	 * @return array{code:int, body:string}
	 */
	private function fetch_ik_url( $url, $api_key ) {
		if ( function_exists( 'curl_init' ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions
			$ch = curl_init( $url );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 15,
					CURLOPT_HTTPHEADER     => array(
						'key: ' . $api_key,
						'currency: 1',
						'Accept-Language: it_IT',
					),
					CURLOPT_SSL_VERIFYPEER => true,
					// Non seguire redirect: evita che un redirect strippa gli header.
					CURLOPT_FOLLOWLOCATION => false,
				)
			);
			$body = curl_exec( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$err  = curl_error( $ch );
			curl_close( $ch );
			// phpcs:enable WordPress.WP.AlternativeFunctions.
			return array(
				'code'   => $err ? 0 : $code,
				'body'   => $err ? $err : (string) $body,
				'method' => 'curl',
			);
		}

		// Fallback WP HTTP API (nota: WP può strippare header 'key' su GET).
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'key'             => $api_key,
					'currency'        => '1',
					'Accept-Language' => 'it_IT',
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array(
				'code'   => 0,
				'body'   => $resp->get_error_message(),
				'method' => 'wp_fallback',
			);
		}
		return array(
			'code'   => (int) wp_remote_retrieve_response_code( $resp ),
			'body'   => wp_remote_retrieve_body( $resp ),
			'method' => 'wp_fallback',
		);
	}

	/**
	 * Estrae array normalizzato di categorie da una risposta JSON Infomaniak.
	 *
	 * @param mixed $data Dato decodificato.
	 * @return array
	 */
	private function extract_ik_categories( $data ) {
		$categories = array();
		if ( ! is_array( $data ) ) {
			return $categories;
		}

		// Cerca la lista in chiavi comuni.
		$list = null;
		foreach ( array( 'categories', 'tariffs', 'data', 'rates', 'items' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$list = $data[ $key ];
				break;
			}
		}
		if ( null === $list ) {
			// Potrebbe essere un array piatto di oggetti.
			$list = $data;
		}

		foreach ( $list as $cat ) {
			if ( ! is_array( $cat ) ) {
				continue;
			}
			$id    = $cat['id'] ?? $cat['category_id'] ?? $cat['tariff_id'] ?? null;
			$name  = $cat['name'] ?? $cat['title'] ?? $cat['label'] ?? '';
			$price = $cat['price'] ?? $cat['amount'] ?? $cat['price_chf'] ?? null;
			if ( null !== $id ) {
				$categories[] = array(
					'id'    => (int) $id,
					'name'  => is_array( $name ) ? ( $name['it'] ?? $name['fr'] ?? $name['en'] ?? reset( $name ) ) : (string) $name,
					'price' => $price,
				);
			}
		}
		return $categories;
	}

	/**
	 * Gestisce creazione guidata pagine checkout/conferma.
	 *
	 * @return void
	 */
	public function handle_create_pages() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'sd-logbook' ) );
		}

		check_admin_referer( 'sd_payment_create_pages' );

		$checkout_id = $this->upsert_page(
			'pagamento-tassa-sociale',
			__( 'Pagamento Tassa Sociale', 'sd-logbook' ),
			'[sd_payment_checkout]'
		);

		$confirm_id = $this->upsert_page(
			'conferma-pagamento',
			__( 'Conferma Pagamento', 'sd-logbook' ),
			'[sd_payment_confirmation]'
		);

		if ( $checkout_id ) {
			update_option( 'sd_payment_checkout_page_url', get_permalink( $checkout_id ) );
		}
		if ( $confirm_id ) {
			update_option( 'sd_payment_confirmation_page_url', get_permalink( $confirm_id ) );
		}

		$redirect = add_query_arg(
			array(
				'page'                     => self::PAGE_SLUG,
				'sd_payment_pages_created' => 1,
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Crea o aggiorna pagina con slug/shortcode richiesto.
	 *
	 * @param string $slug slug.
	 * @param string $title titolo.
	 * @param string $content contenuto.
	 * @return int
	 */
	private function upsert_page( $slug, $title, $content ) {
		$page = get_page_by_path( $slug );
		if ( $page ) {
			wp_update_post(
				array(
					'ID'           => (int) $page->ID,
					'post_title'   => $title,
					'post_content' => $content,
					'post_status'  => 'publish',
				)
			);
			return (int) $page->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
			)
		);

		return is_wp_error( $page_id ) ? 0 : (int) $page_id;
	}

	/**
	 * Render pagina settings.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'sd-logbook' ) );
		}

		$checkout_url                    = get_option( 'sd_payment_checkout_page_url', '' );
		$confirm_url                     = get_option( 'sd_payment_confirmation_page_url', '' );
		$login_url                       = get_option( 'sd_payment_login_url', home_url( '/login/' ) );
		$invoice_association_name        = get_option( 'sd_payment_invoice_association_name', get_option( 'sd_payment_association_title', 'Associazione ScubaDiabetes' ) );
		$invoice_association_address     = get_option( 'sd_payment_invoice_association_address', '' );
		$invoice_association_postal_code = get_option( 'sd_payment_invoice_association_postal_code', '' );
		$invoice_association_city        = get_option( 'sd_payment_invoice_association_city', '' );
		$invoice_association_email       = get_option( 'sd_payment_invoice_association_email', get_bloginfo( 'admin_email' ) );
		$invoice_association_phone       = get_option( 'sd_payment_invoice_association_phone', '' );
		$invoice_bank_name               = get_option( 'sd_payment_invoice_bank_name', '' );
		$invoice_bank_address            = get_option( 'sd_payment_invoice_bank_address', '' );
		$invoice_bank_postal_code        = get_option( 'sd_payment_invoice_bank_postal_code', '' );
		$invoice_bank_city               = get_option( 'sd_payment_invoice_bank_city', '' );
		$invoice_bank_iban               = get_option( 'sd_payment_invoice_bank_iban', '' );
		$invoice_bank_swift              = get_option( 'sd_payment_invoice_bank_swift', '' );
		$invoice_bank_bic                = get_option( 'sd_payment_invoice_bank_bic', '' );
		$invoice_qr_payload              = get_option( 'sd_payment_invoice_qr_payload', '' );
		$invoice_qr_image_url            = get_option( 'sd_payment_invoice_qr_image_url', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pagamenti Soci - Impostazioni', 'sd-logbook' ); ?></h1>
			<?php if ( ! empty( $_GET['sd_payment_pages_created'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Pagine checkout/conferma create o aggiornate con successo.', 'sd-logbook' ); ?></p></div>
			<?php endif; ?>

			<div style="max-width:980px;display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">
				<div style="background:#fff;border:1px solid #ccd0d4;padding:18px;border-radius:4px;">
					<h2><?php esc_html_e( 'Configurazione provider e flusso', 'sd-logbook' ); ?></h2>
					<form method="post" action="options.php">
						<?php settings_fields( self::OPTION_GROUP ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Modalita PayPal', 'sd-logbook' ); ?></th>
								<td>
									<label><input type="radio" name="sd_payment_paypal_mode" value="sandbox" <?php checked( get_option( 'sd_payment_paypal_mode', 'sandbox' ), 'sandbox' ); ?>> Sandbox</label><br>
									<label><input type="radio" name="sd_payment_paypal_mode" value="live" <?php checked( get_option( 'sd_payment_paypal_mode', 'sandbox' ), 'live' ); ?>> Live</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_paypal_client_id"><?php esc_html_e( 'PayPal Client ID', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_paypal_client_id" name="sd_payment_paypal_client_id" value="<?php echo esc_attr( get_option( 'sd_payment_paypal_client_id', '' ) ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_paypal_secret"><?php esc_html_e( 'PayPal Secret', 'sd-logbook' ); ?></label></th>
								<td><input type="password" class="regular-text" id="sd_payment_paypal_secret" name="sd_payment_paypal_secret" value="<?php echo esc_attr( get_option( 'sd_payment_paypal_secret', '' ) ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Metodi attivi', 'sd-logbook' ); ?></th>
								<td>
									<label><input type="checkbox" name="sd_payment_enable_paypal" value="1" <?php checked( (int) get_option( 'sd_payment_enable_paypal', 1 ), 1 ); ?>> PayPal</label><br>
									<label><input type="checkbox" name="sd_payment_enable_invoice" value="1" <?php checked( (int) get_option( 'sd_payment_enable_invoice', 1 ), 1 ); ?>> Fattura</label><br>
									<label><input type="checkbox" name="sd_payment_enable_twint_stub" value="1" <?php checked( (int) get_option( 'sd_payment_enable_twint_stub', 0 ), 1 ); ?>> TWINT Express Checkout</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_checkout_page_url"><?php esc_html_e( 'URL pagina checkout', 'sd-logbook' ); ?></label></th>
								<td><input type="url" class="regular-text" id="sd_payment_checkout_page_url" name="sd_payment_checkout_page_url" value="<?php echo esc_attr( $checkout_url ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_confirmation_page_url"><?php esc_html_e( 'URL pagina conferma', 'sd-logbook' ); ?></label></th>
								<td><input type="url" class="regular-text" id="sd_payment_confirmation_page_url" name="sd_payment_confirmation_page_url" value="<?php echo esc_attr( $confirm_url ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_login_url"><?php esc_html_e( 'URL login', 'sd-logbook' ); ?></label></th>
								<td><input type="url" class="regular-text" id="sd_payment_login_url" name="sd_payment_login_url" value="<?php echo esc_attr( $login_url ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_association_title"><?php esc_html_e( 'Titolo associazione (PDF)', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_association_title" name="sd_payment_association_title" value="<?php echo esc_attr( get_option( 'sd_payment_association_title', 'Associazione ScubaDiabetes' ) ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_brand_primary"><?php esc_html_e( 'Colore brand primario', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_brand_primary" name="sd_payment_brand_primary" value="<?php echo esc_attr( get_option( 'sd_payment_brand_primary', '#0055A5' ) ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_brand_secondary"><?php esc_html_e( 'Colore brand secondario', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_brand_secondary" name="sd_payment_brand_secondary" value="<?php echo esc_attr( get_option( 'sd_payment_brand_secondary', '#00A3D8' ) ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_receipt_footer_note"><?php esc_html_e( 'Nota footer ricevuta', 'sd-logbook' ); ?></label></th>
								<td><textarea class="large-text" rows="3" id="sd_payment_receipt_footer_note" name="sd_payment_receipt_footer_note"><?php echo esc_textarea( get_option( 'sd_payment_receipt_footer_note', '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row" colspan="2" style="padding-top:18px;"><h3 style="margin:0;"><?php esc_html_e( 'Configurazione TWINT Express Checkout', 'sd-logbook' ); ?></h3></th>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Provider TWINT', 'sd-logbook' ); ?></th>
							<td>
								<label><input type="radio" name="sd_payment_twint_provider" value="direct" <?php checked( get_option( 'sd_payment_twint_provider', 'direct' ), 'direct' ); ?>> <?php esc_html_e( 'Diretto (storeUuid + apiKey + certificato)', 'sd-logbook' ); ?></label><br>
								<label><input type="radio" name="sd_payment_twint_provider" value="infomaniak" <?php checked( get_option( 'sd_payment_twint_provider', 'direct' ), 'infomaniak' ); ?>> <?php esc_html_e( 'Infomaniak eCommerce (etickets.infomaniak.com)', 'sd-logbook' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sd_payment_twint_ik_key"><?php esc_html_e( 'Infomaniak API Key', 'sd-logbook' ); ?></label></th>
							<td>
								<input type="password" class="regular-text" id="sd_payment_twint_ik_key" name="sd_payment_twint_ik_key" value="<?php echo esc_attr( get_option( 'sd_payment_twint_ik_key', '' ) ); ?>">
								<p class="description"><?php esc_html_e( 'Manager Infomaniak &rarr; Shop &rarr; Disponibilit&#224; online &rarr; Accesso API', 'sd-logbook' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sd_payment_twint_ik_event_id"><?php esc_html_e( 'Infomaniak Event ID + Shop ID', 'sd-logbook' ); ?></label></th>
							<td>
								<?php echo wp_kses( __( 'Event ID:', 'sd-logbook' ), array() ); ?>
								<input type="number" id="sd_payment_twint_ik_event_id" name="sd_payment_twint_ik_event_id" value="<?php echo esc_attr( get_option( 'sd_payment_twint_ik_event_id', 0 ) ); ?>" min="0" style="width:120px">
								&nbsp;&nbsp;<?php echo wp_kses( __( 'Shop ID:', 'sd-logbook' ), array() ); ?>
								<input type="number" id="sd_payment_twint_ik_shop_id" name="sd_payment_twint_ik_shop_id" value="<?php echo esc_attr( get_option( 'sd_payment_twint_ik_shop_id', 0 ) ); ?>" min="0" style="width:100px">
								&nbsp;
								<button type="button" id="sd-ik-load-cats" class="button"><?php esc_html_e( 'Carica categorie', 'sd-logbook' ); ?></button>
								<p class="description"><?php echo wp_kses( __( 'URL Manager: .../tickets/<strong>59285</strong>/events/<strong>392024</strong>/view → Shop ID = 59285, Event ID = 392024', 'sd-logbook' ), array( 'strong' => array() ) ); ?></p>
								<div id="sd-ik-cats-result" style="margin-top:10px;display:none;"></div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Infomaniak Category ID per importo', 'sd-logbook' ); ?></th>
							<td>
								<?php foreach ( array( 30, 50, 75 ) as $tier ) : ?>
								<p>
									<label for="sd_payment_twint_ik_category_id_<?php echo esc_attr( $tier ); ?>">
										<strong>CHF <?php echo esc_html( $tier ); ?></strong> &mdash; Category ID:
									</label>
									<input type="number" class="small-text" id="sd_payment_twint_ik_category_id_<?php echo esc_attr( $tier ); ?>" name="sd_payment_twint_ik_category_id_<?php echo esc_attr( $tier ); ?>" value="<?php echo esc_attr( get_option( 'sd_payment_twint_ik_category_id_' . $tier, 0 ) ); ?>" min="0">
								</p>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( "Per ogni importo, inserisci l'ID della categoria biglietto corrispondente nel tuo evento Infomaniak (Manager → etickets → evento → Categorie).", 'sd-logbook' ); ?></p>
							</td>
						</tr>
						<script>
						(function(){
							document.getElementById('sd-ik-load-cats').addEventListener('click', function(){
								var btn = this;
								var eventId = document.getElementById('sd_payment_twint_ik_event_id').value;
								var result  = document.getElementById('sd-ik-cats-result');
								if ( !eventId || parseInt(eventId) <= 0 ) {
									result.style.display='block';
									result.innerHTML='<span style="color:red">Inserisci prima l\'Event ID.</span>';
									return;
								}
								btn.disabled = true;
								btn.textContent = 'Caricamento...';
								result.style.display='none';
								var shopIdEl = document.getElementById('sd_payment_twint_ik_shop_id');
								var shopId   = shopIdEl ? shopIdEl.value : '0';
								var fd = new FormData();
								fd.append('action',   'sd_load_ik_categories');
								fd.append('nonce',    '<?php echo esc_js( wp_create_nonce( 'sd_ik_categories' ) ); ?>');
								fd.append('event_id', eventId);
								fd.append('shop_id',  shopId);
								fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {method:'POST', body:fd})
									.then(function(r){ return r.json(); })
									.then(function(resp){
										btn.disabled    = false;
										btn.textContent = 'Carica categorie';
										result.style.display = 'block';
										if ( !resp.success ) {
											var msg = resp.data && resp.data.message ? resp.data.message : JSON.stringify(resp);
											var dbg = '';
											if ( resp.data && resp.data.attempts ) {
												dbg = '<details style="margin-top:8px"><summary>Debug (tutti i tentativi)</summary><pre style="background:#f5f5f5;padding:8px;max-height:300px;overflow:auto;font-size:11px">' + JSON.stringify(resp.data.attempts, null, 2) + '</pre></details>';
											}
											result.innerHTML = '<span style="color:red">Errore: ' + msg + '</span>' + dbg;
											return;
										}
										var cats = resp.data.categories;
										var endpoint = resp.data.endpoint || '';
										if ( !cats || cats.length === 0 ) {
											result.innerHTML = '<em>Nessuna categoria trovata (endpoint: ' + endpoint + '). Raw:</em><pre style="background:#f5f5f5;padding:8px;max-height:200px;overflow:auto">' + resp.data.raw + '</pre>';
											return;
										}
										var html = '<p style="color:green;margin-bottom:6px">&#10003; Trovate via: ' + endpoint + '</p>';
										html += '<table style="border-collapse:collapse"><thead><tr><th style="padding:4px 12px 4px 0;border-bottom:1px solid #ccc">ID</th><th style="padding:4px 12px 4px 0;border-bottom:1px solid #ccc">Nome</th><th style="padding:4px 0;border-bottom:1px solid #ccc">Prezzo</th></tr></thead><tbody>';
										cats.forEach(function(c){
											html += '<tr><td style="padding:3px 12px 3px 0"><code>' + c.id + '</code></td><td style="padding:3px 12px 3px 0">' + c.name + '</td><td style="padding:3px 0">' + (c.price !== null && c.price !== undefined ? 'CHF ' + c.price : '') + '</td></tr>';
										});
										html += '</tbody></table>';
										result.innerHTML = html;
									})
									.catch(function(e){
										btn.disabled = false;
										btn.textContent = 'Carica categorie';
										result.style.display = 'block';
										result.innerHTML = '<span style="color:red">Errore rete: ' + e.message + '</span>';
									});
							});
						})();
						</script>
						<tr>
							<th scope="row" style="padding-top:12px;"><em><?php esc_html_e( '&#8212; Impostazioni provider Diretto &#8212;', 'sd-logbook' ); ?></em></th>
							<td></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Modalita TWINT', 'sd-logbook' ); ?></th>
							<td>
								<label><input type="radio" name="sd_payment_twint_mode" value="sandbox" <?php checked( get_option( 'sd_payment_twint_mode', 'sandbox' ), 'sandbox' ); ?>> Sandbox</label>&nbsp;&nbsp;
								<label><input type="radio" name="sd_payment_twint_mode" value="live" <?php checked( get_option( 'sd_payment_twint_mode', 'sandbox' ), 'live' ); ?>> Live</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sd_payment_twint_sandbox_api_url"><?php esc_html_e( 'URL API Sandbox', 'sd-logbook' ); ?></label></th>
							<td><input type="url" class="regular-text" id="sd_payment_twint_sandbox_api_url" name="sd_payment_twint_sandbox_api_url" value="<?php echo esc_attr( get_option( 'sd_payment_twint_sandbox_api_url', 'https://sandbox.twint.ch/v1' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="sd_payment_twint_live_api_url"><?php esc_html_e( 'URL API Live', 'sd-logbook' ); ?></label></th>
							<td><input type="url" class="regular-text" id="sd_payment_twint_live_api_url" name="sd_payment_twint_live_api_url" value="<?php echo esc_attr( get_option( 'sd_payment_twint_live_api_url', 'https://api.twint.ch/v1' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="sd_payment_twint_store_uuid"><?php esc_html_e( 'Store UUID', 'sd-logbook' ); ?></label></th>
							<td><input type="text" class="regular-text" id="sd_payment_twint_store_uuid" name="sd_payment_twint_store_uuid" value="<?php echo esc_attr( get_option( 'sd_payment_twint_store_uuid', '' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="sd_payment_twint_api_key"><?php esc_html_e( 'API Key', 'sd-logbook' ); ?></label></th>
							<td><input type="password" class="regular-text" id="sd_payment_twint_api_key" name="sd_payment_twint_api_key" value="<?php echo esc_attr( get_option( 'sd_payment_twint_api_key', '' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="sd_payment_twint_cashregister_ref"><?php esc_html_e( 'Cash Register ID', 'sd-logbook' ); ?></label></th>
							<td><input type="text" class="regular-text" id="sd_payment_twint_cashregister_ref" name="sd_payment_twint_cashregister_ref" value="<?php echo esc_attr( get_option( 'sd_payment_twint_cashregister_ref', 'SD-LOGBOOK' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="sd_payment_twint_cert_path"><?php esc_html_e( 'Percorso certificato SSL (opz.)', 'sd-logbook' ); ?></label></th>
							<td><input type="text" class="regular-text" id="sd_payment_twint_cert_path" name="sd_payment_twint_cert_path" value="<?php echo esc_attr( get_option( 'sd_payment_twint_cert_path', '' ) ); ?>"><p class="description"><?php esc_html_e( 'Percorso assoluto al file .pem del certificato client (richiesto da alcuni acquirer CH).', 'sd-logbook' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="sd_payment_twint_cert_password"><?php esc_html_e( 'Password certificato (opz.)', 'sd-logbook' ); ?></label></th>
							<td><input type="password" class="regular-text" id="sd_payment_twint_cert_password" name="sd_payment_twint_cert_password" value="<?php echo esc_attr( get_option( 'sd_payment_twint_cert_password', '' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row" colspan="2" style="padding-top:18px;"><h3 style="margin:0;"><?php esc_html_e( 'Dati Fattura (Associazione)', 'sd-logbook' ); ?></h3></th>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_association_name"><?php esc_html_e( 'Nome Associazione', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_association_name" name="sd_payment_invoice_association_name" value="<?php echo esc_attr( $invoice_association_name ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_association_address"><?php esc_html_e( 'Indirizzo Associazione', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_association_address" name="sd_payment_invoice_association_address" value="<?php echo esc_attr( $invoice_association_address ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_association_postal_code"><?php esc_html_e( 'CAP Associazione', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_association_postal_code" name="sd_payment_invoice_association_postal_code" value="<?php echo esc_attr( $invoice_association_postal_code ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_association_city"><?php esc_html_e( 'Localita Associazione', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_association_city" name="sd_payment_invoice_association_city" value="<?php echo esc_attr( $invoice_association_city ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_association_email"><?php esc_html_e( 'E-mail Associazione', 'sd-logbook' ); ?></label></th>
								<td><input type="email" class="regular-text" id="sd_payment_invoice_association_email" name="sd_payment_invoice_association_email" value="<?php echo esc_attr( $invoice_association_email ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_association_phone"><?php esc_html_e( 'Telefono Associazione', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_association_phone" name="sd_payment_invoice_association_phone" value="<?php echo esc_attr( $invoice_association_phone ); ?>"></td>
							</tr>
							<tr>
								<th scope="row" colspan="2" style="padding-top:18px;"><h3 style="margin:0;"><?php esc_html_e( 'Dati Fattura (Banca)', 'sd-logbook' ); ?></h3></th>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_bank_name"><?php esc_html_e( 'Banca', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_bank_name" name="sd_payment_invoice_bank_name" value="<?php echo esc_attr( $invoice_bank_name ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_bank_address"><?php esc_html_e( 'Indirizzo Banca', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_bank_address" name="sd_payment_invoice_bank_address" value="<?php echo esc_attr( $invoice_bank_address ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_bank_postal_code"><?php esc_html_e( 'CAP Banca', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_bank_postal_code" name="sd_payment_invoice_bank_postal_code" value="<?php echo esc_attr( $invoice_bank_postal_code ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_bank_city"><?php esc_html_e( 'Localita Banca', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_bank_city" name="sd_payment_invoice_bank_city" value="<?php echo esc_attr( $invoice_bank_city ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_bank_iban"><?php esc_html_e( 'IBAN Banca', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_bank_iban" name="sd_payment_invoice_bank_iban" value="<?php echo esc_attr( $invoice_bank_iban ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_bank_swift"><?php esc_html_e( 'SWIFT Banca', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_bank_swift" name="sd_payment_invoice_bank_swift" value="<?php echo esc_attr( $invoice_bank_swift ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_bank_bic"><?php esc_html_e( 'BIC Banca', 'sd-logbook' ); ?></label></th>
								<td><input type="text" class="regular-text" id="sd_payment_invoice_bank_bic" name="sd_payment_invoice_bank_bic" value="<?php echo esc_attr( $invoice_bank_bic ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_qr_payload"><?php esc_html_e( 'QR pagamento (payload)', 'sd-logbook' ); ?></label></th>
								<td>
									<textarea class="large-text" rows="4" id="sd_payment_invoice_qr_payload" name="sd_payment_invoice_qr_payload"><?php echo esc_textarea( $invoice_qr_payload ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Inserisci il testo/payload QR da stampare nella fattura (es. payload Swiss QR).', 'sd-logbook' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sd_payment_invoice_qr_image_url"><?php esc_html_e( 'QR immagine (jpg/jpeg/png URL)', 'sd-logbook' ); ?></label></th>
								<td>
									<input type="url" class="regular-text" id="sd_payment_invoice_qr_image_url" name="sd_payment_invoice_qr_image_url" value="<?php echo esc_attr( $invoice_qr_image_url ); ?>">
									<p class="description"><?php esc_html_e( 'Carica il QR in Libreria Media e incolla qui il relativo URL.', 'sd-logbook' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Salva impostazioni', 'sd-logbook' ) ); ?>
					</form>
				</div>

				<div style="background:#fff;border:1px solid #ccd0d4;padding:18px;border-radius:4px;">
					<h2><?php esc_html_e( 'Creazione guidata pagine', 'sd-logbook' ); ?></h2>
					<p><?php esc_html_e( 'Questa azione crea o aggiorna automaticamente le pagine pubbliche del flusso pagamento.', 'sd-logbook' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'sd_payment_create_pages' ); ?>
						<input type="hidden" name="action" value="sd_payment_create_pages">
						<?php submit_button( __( 'Crea/Aggiorna pagine checkout e conferma', 'sd-logbook' ), 'secondary', 'submit', false ); ?>
					</form>
					<hr>
					<h3><?php esc_html_e( 'Shortcode disponibili', 'sd-logbook' ); ?></h3>
					<ul style="list-style:disc;padding-left:20px;">
						<li><code>[sd_payment_checkout]</code></li>
						<li><code>[sd_payment_confirmation]</code></li>
					</ul>
					<p><strong><?php esc_html_e( 'URL checkout attuale:', 'sd-logbook' ); ?></strong><br><?php echo esc_html( $checkout_url ? $checkout_url : '-' ); ?></p>
					<p><strong><?php esc_html_e( 'URL conferma attuale:', 'sd-logbook' ); ?></strong><br><?php echo esc_html( $confirm_url ? $confirm_url : '-' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
