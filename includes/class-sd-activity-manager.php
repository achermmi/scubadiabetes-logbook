<?php
/**
 * Gestione Attività ScubaDiabetes
 *
 * Gestisce la creazione, modifica, eliminazione e iscrizioni alle attività.
 * Include gestione moduli dinamici, tariffe e iscrizioni.
 *
 * @package SD_Logbook
 * @since 3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Activity_Manager {

	/**
	 * Istanza singleton
	 */
	private static $instance = null;

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
		$this->init_hooks();
	}

	/**
	 * Inizializza gli hook
	 */
	private function init_hooks() {
		// Shortcodes
		add_shortcode( 'sd_gestione_attivita', array( $this, 'shortcode_activity_admin_dashboard' ) );
		add_shortcode( 'sd_iscrizione_attivita', array( $this, 'shortcode_activity_registration_form' ) );

		// Public AJAX (nopriv)
		add_action( 'wp_ajax_nopriv_sd_activities_list', array( $this, 'ajax_get_activities_list' ) );
		add_action( 'wp_ajax_sd_activities_list', array( $this, 'ajax_get_activities_list' ) );
		add_action( 'wp_ajax_nopriv_sd_activity_get_details', array( $this, 'ajax_activity_get_details' ) );
		add_action( 'wp_ajax_sd_activity_get_details', array( $this, 'ajax_activity_get_details' ) );
		add_action( 'wp_ajax_nopriv_sd_activity_get', array( $this, 'ajax_get_activity_details' ) );
		add_action( 'wp_ajax_sd_activity_get', array( $this, 'ajax_get_activity_details' ) );
		add_action( 'wp_ajax_nopriv_sd_activity_register', array( $this, 'ajax_register_for_activity' ) );
		add_action( 'wp_ajax_sd_activity_register', array( $this, 'ajax_register_for_activity' ) );

		// Admin AJAX
		add_action( 'wp_ajax_sd_activity_save', array( $this, 'ajax_save_activity' ) );
		add_action( 'wp_ajax_sd_activity_delete', array( $this, 'ajax_delete_activity' ) );
		add_action( 'wp_ajax_sd_activity_update_form_field', array( $this, 'ajax_update_form_field' ) );
		add_action( 'wp_ajax_sd_activity_delete_form_field', array( $this, 'ajax_delete_form_field' ) );
		add_action( 'wp_ajax_sd_activity_move_form_field', array( $this, 'ajax_move_form_field' ) );
		add_action( 'wp_ajax_sd_activity_price_save', array( $this, 'ajax_save_activity_price' ) );
		add_action( 'wp_ajax_sd_activity_price_delete', array( $this, 'ajax_delete_activity_price' ) );
		add_action( 'wp_ajax_sd_activity_price_set_default', array( $this, 'ajax_set_default_activity_price' ) );
		add_action( 'wp_ajax_sd_activity_delete_all_prices', array( $this, 'ajax_delete_all_prices' ) );
		add_action( 'wp_ajax_sd_activity_registration_list', array( $this, 'ajax_get_registrations_list' ) );
		add_action( 'wp_ajax_sd_activity_registration_update_status', array( $this, 'ajax_update_registration_status' ) );
		add_action( 'wp_ajax_sd_activity_registration_update_payment', array( $this, 'ajax_update_registration_payment' ) );
	}

	// ======================================================================
	// METODI PUBBLICI - ATTIVITÀ
	// ======================================================================

	/**
	 * Crea una nuova attività
	 *
	 * @param array $data Dati attività
	 * @return int|false ID attività o false se errore
	 */
	public function create_activity( $data = array() ) {
		global $wpdb;

		$defaults = array(
			'title'                 => '',
			'description'           => '',
			'start_date'            => '',
			'end_date'              => '',
			'location'              => '',
			'max_participants'      => null,
			'event_status'          => 'draft',
			'thumbnail_url'         => '',
			'form_configuration'    => array(),
			'price_configuration'   => array(),
			'created_by'            => get_current_user_id(),
		);

		$data = wp_parse_args( $data, $defaults );

		// Validazione
		if ( empty( $data['title'] ) ) {
			return false;
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'sd_activities',
			array(
				'title'                 => sanitize_text_field( $data['title'] ),
				'description'           => wp_kses_post( $data['description'] ),
				'start_date'            => sanitize_text_field( $data['start_date'] ),
				'end_date'              => sanitize_text_field( $data['end_date'] ),
				'location'              => sanitize_text_field( $data['location'] ),
				'max_participants'      => intval( $data['max_participants'] ) ?: null,
				'event_status'          => sanitize_text_field( $data['event_status'] ),
				'thumbnail_url'         => esc_url_raw( $data['thumbnail_url'] ),
				'form_configuration'    => wp_json_encode( $data['form_configuration'] ),
				'price_configuration'   => wp_json_encode( $data['price_configuration'] ),
				'created_by'            => intval( $data['created_by'] ),
				'created_at'            => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( $result ) {
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Recupera i dettagli di un'attività
	 *
	 * @param int $activity_id ID attività
	 * @return array|null Dati attività
	 */
	public function get_activity( $activity_id ) {
		global $wpdb;

		$activity = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'sd_activities WHERE id = %d',
				$activity_id
			),
			ARRAY_A
		);

		if ( ! $activity ) {
			return null;
		}

		// Decodifica JSON
		$activity['form_configuration'] = json_decode( $activity['form_configuration'], true ) ?: array();
		$activity['price_configuration'] = json_decode( $activity['price_configuration'], true ) ?: array();

		// Recupera tariffe
		$activity['prices'] = $this->get_activity_prices( $activity_id );

		// Recupera campi modulo
		$activity['form_fields'] = $this->get_form_fields( $activity_id );
		$activity['section_layout_order'] = $this->get_section_layout_order( $activity );

		// Recupera iscrizioni
		$activity['registrations_count'] = $this->get_registrations_count( $activity_id );

		return $activity;
	}

	/**
	 * Aggiorna un'attività
	 *
	 * @param int   $activity_id ID attività
	 * @param array $data Dati da aggiornare
	 * @return bool
	 */
	public function update_activity( $activity_id, $data = array() ) {
		global $wpdb;

		$update_data = array();

		$allowed_keys = array(
			'title',
			'description',
			'start_date',
			'end_date',
			'location',
			'max_participants',
			'event_status',
			'thumbnail_url',
			'form_configuration',
			'price_configuration',
		);

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				continue;
			}

			if ( 'title' === $key ) {
				$update_data[ $key ] = sanitize_text_field( $value );
			} elseif ( 'description' === $key ) {
				$update_data[ $key ] = wp_kses_post( $value );
			} elseif ( in_array( $key, array( 'start_date', 'end_date', 'location', 'event_status' ), true ) ) {
				$update_data[ $key ] = sanitize_text_field( $value );
			} elseif ( 'max_participants' === $key ) {
				$update_data[ $key ] = intval( $value ) ?: null;
			} elseif ( 'thumbnail_url' === $key ) {
				$update_data[ $key ] = esc_url_raw( $value );
			} elseif ( in_array( $key, array( 'form_configuration', 'price_configuration' ), true ) ) {
				$update_data[ $key ] = wp_json_encode( $value );
			}
		}

		if ( empty( $update_data ) ) {
			return false;
		}

		$update_data['updated_at'] = current_time( 'mysql' );

		return (bool) $wpdb->update(
			$wpdb->prefix . 'sd_activities',
			$update_data,
			array( 'id' => $activity_id ),
			null,
			array( '%d' )
		);
	}

	/**
	 * Elimina un'attività
	 *
	 * @param int $activity_id ID attività
	 * @return bool
	 */
	public function delete_activity( $activity_id ) {
		global $wpdb;

		// Le tabelle figlie verranno eliminate in cascata dai FOREIGN KEY
		return (bool) $wpdb->delete(
			$wpdb->prefix . 'sd_activities',
			array( 'id' => $activity_id ),
			array( '%d' )
		);
	}

	// ======================================================================
	// METODI PUBBLICI - TARIFFE
	// ======================================================================

	/**
	 * Recupera le tariffe di un'attività
	 *
	 * @param int $activity_id ID attività
	 * @return array Array di tariffe
	 */
	public function get_activity_prices( $activity_id ) {
		global $wpdb;

		$prices = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'sd_activity_prices WHERE activity_id = %d ORDER BY is_default DESC, id ASC',
				$activity_id
			),
			ARRAY_A
		);

		foreach ( $prices as &$price ) {
			$price['price_chf']  = floatval( $price['price_chf'] );
			$price['price_eur']  = floatval( $price['price_eur'] );
			$price['is_default'] = (bool) $price['is_default'];
		}

		return $prices ?: array();
	}

	/**
	 * Crea una nuova tariffa per un'attività
	 *
	 * @param int   $activity_id ID attività
	 * @param array $data Dati tariffa
	 * @return int|false ID tariffa o false se errore
	 */
	public function create_price( $activity_id, $data = array() ) {
		global $wpdb;

		$defaults = array(
			'price_name'        => '',
			'price_chf'         => 0,
			'price_eur'         => 0,
			'currency_rate'     => null,
			'currency_rate_date' => null,
			'is_default'        => false,
		);

		$data = wp_parse_args( $data, $defaults );
		$set_default = ! empty( $data['is_default'] );

		if ( empty( $data['price_name'] ) || floatval( $data['price_chf'] ) <= 0 ) {
			return false;
		}

		if ( $set_default ) {
			$this->clear_default_prices( $activity_id );
		}

		return $wpdb->insert(
			$wpdb->prefix . 'sd_activity_prices',
			array(
				'activity_id'        => intval( $activity_id ),
				'price_name'         => sanitize_text_field( $data['price_name'] ),
				'price_chf'          => floatval( $data['price_chf'] ),
				'price_eur'          => floatval( $data['price_eur'] ) ?: null,
				'currency_rate'      => floatval( $data['currency_rate'] ) ?: null,
				'currency_rate_date' => sanitize_text_field( $data['currency_rate_date'] ) ?: null,
				'is_default'         => $set_default ? 1 : 0,
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%f', '%f', '%f', '%s', '%d', '%s' )
		) ? $wpdb->insert_id : false;
	}

	/**
	 * Aggiorna una tariffa esistente.
	 *
	 * @param int   $activity_id ID attività.
	 * @param int   $price_id ID tariffa.
	 * @param array $data Dati tariffa.
	 * @return bool
	 */
	public function update_price( $activity_id, $price_id, $data = array() ) {
		global $wpdb;

		$defaults = array(
			'price_name'         => '',
			'price_chf'          => 0,
			'price_eur'          => 0,
			'currency_rate'      => null,
			'currency_rate_date' => null,
			'is_default'         => false,
		);

		$data = wp_parse_args( $data, $defaults );
		$set_default = ! empty( $data['is_default'] );

		if ( intval( $price_id ) <= 0 || empty( $data['price_name'] ) || floatval( $data['price_chf'] ) <= 0 ) {
			return false;
		}

		if ( $set_default ) {
			$this->clear_default_prices( $activity_id, $price_id );
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'sd_activity_prices',
			array(
				'price_name'         => sanitize_text_field( $data['price_name'] ),
				'price_chf'          => floatval( $data['price_chf'] ),
				'price_eur'          => floatval( $data['price_eur'] ) ?: null,
				'currency_rate'      => floatval( $data['currency_rate'] ) ?: null,
				'currency_rate_date' => sanitize_text_field( $data['currency_rate_date'] ) ?: null,
				'is_default'         => $set_default ? 1 : 0,
			),
			array(
				'id'          => intval( $price_id ),
				'activity_id' => intval( $activity_id ),
			),
			array( '%s', '%f', '%f', '%f', '%s', '%d' ),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Elimina una singola tariffa di un'attività.
	 *
	 * @param int $activity_id ID attività.
	 * @param int $price_id ID tariffa.
	 * @return bool
	 */
	public function delete_price( $activity_id, $price_id ) {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'sd_activity_prices',
			array(
				'id'          => intval( $price_id ),
				'activity_id' => intval( $activity_id ),
			),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Imposta una tariffa come predefinita.
	 *
	 * @param int $activity_id ID attività.
	 * @param int $price_id ID tariffa.
	 * @return bool
	 */
	public function set_default_price( $activity_id, $price_id ) {
		global $wpdb;

		$price = $this->get_price( $price_id );
		if ( ! $price || intval( $price['activity_id'] ) !== intval( $activity_id ) ) {
			return false;
		}

		$this->clear_default_prices( $activity_id, $price_id );

		$result = $wpdb->update(
			$wpdb->prefix . 'sd_activity_prices',
			array( 'is_default' => 1 ),
			array(
				'id'          => intval( $price_id ),
				'activity_id' => intval( $activity_id ),
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Azzera il flag predefinita su tutte le tariffe dell'attività.
	 *
	 * @param int $activity_id ID attività.
	 * @param int $exclude_price_id ID tariffa da escludere.
	 * @return bool
	 */
	private function clear_default_prices( $activity_id, $exclude_price_id = 0 ) {
		global $wpdb;

		$activity_id = intval( $activity_id );
		$exclude_price_id = intval( $exclude_price_id );

		if ( $exclude_price_id > 0 ) {
			$query = $wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . 'sd_activity_prices SET is_default = 0 WHERE activity_id = %d AND id != %d',
				$activity_id,
				$exclude_price_id
			);
		} else {
			$query = $wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . 'sd_activity_prices SET is_default = 0 WHERE activity_id = %d',
				$activity_id
			);
		}

		$result = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $result;
	}

	/**
	 * Elimina tutte le tariffe di un'attività.
	 *
	 * @param int $activity_id ID attività.
	 * @return bool
	 */
	public function delete_all_prices( $activity_id ) {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'sd_activity_prices',
			array( 'activity_id' => intval( $activity_id ) ),
			array( '%d' )
		);

		return false !== $result;
	}

	// ======================================================================
	// METODI PUBBLICI - CAMPI MODULO
	// ======================================================================

	/**
	 * Recupera i campi del modulo per un'attività
	 *
	 * @param int $activity_id ID attività
	 * @return array Array di campi
	 */
	public function get_form_fields( $activity_id ) {
		global $wpdb;

		$fields = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'sd_activity_form_fields WHERE activity_id = %d ORDER BY section_order ASC, field_order ASC, id ASC',
				$activity_id
			),
			ARRAY_A
		);

		foreach ( $fields as &$field ) {
			$field['is_required']   = (bool) $field['is_required'];
			$field['section_key']   = sanitize_key( $field['section_key'] ?: 'additional' );
			$field['section_label'] = sanitize_text_field( $field['section_label'] ?: $this->get_default_section_label( $field['section_key'] ) );
			$field['section_order'] = intval( $field['section_order'] ?: $this->get_default_section_order( $field['section_key'] ) );
			$field['field_order']   = intval( $field['field_order'] );
			$field['options']       = json_decode( $field['options'], true ) ?: array();
		}

		return $fields ?: array();
	}

	/**
	 * Etichetta default per sezione.
	 *
	 * @param string $section_key Chiave sezione.
	 * @return string
	 */
	private function get_default_section_label( $section_key ) {
		switch ( sanitize_key( $section_key ) ) {
			case 'personal':
				return __( 'Dati Personali', 'sd-logbook' );
			case 'activity_data':
				return __( 'Dati Attivita', 'sd-logbook' );
			case 'pricing':
				return __( 'Selezione Tariffa', 'sd-logbook' );
			case 'consents':
				return __( 'Consensi', 'sd-logbook' );
			case 'additional':
			default:
				return __( 'Informazioni Aggiuntive', 'sd-logbook' );
		}
	}

	/**
	 * Ordine default per sezione.
	 *
	 * @param string $section_key Chiave sezione.
	 * @return int
	 */
	private function get_default_section_order( $section_key ) {
		switch ( sanitize_key( $section_key ) ) {
			case 'personal':
				return 10;
			case 'activity_data':
				return 15;
			case 'pricing':
				return 30;
			case 'consents':
				return 40;
			case 'additional':
			default:
				return 20;
		}
	}

	/**
	 * Calcola l'ordine normalizzato delle sezioni del modulo.
	 *
	 * @param array $activity Dati attività già decodificati.
	 * @return array
	 */
	private function get_section_layout_order( $activity ) {
		$layout_order = array();

		if ( ! empty( $activity['form_configuration']['section_meta']['layout_order'] ) && is_array( $activity['form_configuration']['section_meta']['layout_order'] ) ) {
			foreach ( $activity['form_configuration']['section_meta']['layout_order'] as $section_key ) {
				$section_key = sanitize_key( $section_key );
				if ( '' !== $section_key && ! in_array( $section_key, $layout_order, true ) ) {
					$layout_order[] = $section_key;
				}
			}
		}

		$default_keys = array( 'personal', 'additional', 'pricing', 'consents' );
		if ( ! empty( $activity['form_fields'] ) && is_array( $activity['form_fields'] ) ) {
			foreach ( $activity['form_fields'] as $field ) {
				$section_key = sanitize_key( $field['section_key'] ?? 'additional' );
				if ( '' !== $section_key && ! in_array( $section_key, $layout_order, true ) ) {
					$layout_order[] = $section_key;
				}
			}
		}

		foreach ( $default_keys as $section_key ) {
			if ( ! in_array( $section_key, $layout_order, true ) ) {
				$layout_order[] = $section_key;
			}
		}

		return $layout_order;
	}

	/**
	 * Normalizza le opzioni di un campo.
	 *
	 * @param mixed $options Opzioni raw.
	 * @return array
	 */
	private function normalize_form_field_options( $options ) {
		if ( is_string( $options ) ) {
			$decoded = json_decode( wp_unslash( $options ), true );
			$options = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $options ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$label = sanitize_text_field( $option['label'] ?? '' );
			$value = sanitize_title( $option['value'] ?? $label );

			if ( '' === $label || '' === $value ) {
				continue;
			}

			$normalized[] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		return $normalized;
	}

	/**
	 * Sanitizza configurazione campo immagine.
	 *
	 * @param mixed $config Configurazione immagine raw.
	 * @return array
	 */
	private function sanitize_image_config( $config ) {
		if ( is_string( $config ) ) {
			$decoded = json_decode( wp_unslash( $config ), true );
			$config = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $config ) ) {
			return array();
		}

		return array(
			'image_type'       => sanitize_key( $config['image_type'] ?? 'display' ),
			'image_url'        => esc_url_raw( $config['image_url'] ?? '' ),
			'image_width'      => intval( $config['image_width'] ?? 0 ),
			'image_height'     => intval( $config['image_height'] ?? 0 ),
			'image_aspect_ratio' => intval( $config['image_aspect_ratio'] ?? 0 ),
			'image_align_h'    => sanitize_key( $config['image_align_h'] ?? 'left' ),
			'image_align_v'    => sanitize_key( $config['image_align_v'] ?? 'top' ),
			'image_alt_text'   => sanitize_text_field( $config['image_alt_text'] ?? '' ),
		);
	}

	/**
	 * Normalizza i dati del campo modulo.
	 *
	 * @param array $data Dati raw.
	 * @param array $existing Dati esistenti.
	 * @return array
	 */
	private function normalize_form_field_data( $data, $existing = array() ) {
		$defaults = array(
			'field_type'    => $existing['field_type'] ?? 'text',
			'field_name'    => $existing['field_name'] ?? '',
			'field_label'   => $existing['field_label'] ?? '',
			'placeholder'   => $existing['placeholder'] ?? '',
			'is_required'   => ! empty( $existing['is_required'] ),
			'section_key'   => $existing['section_key'] ?? 'additional',
			'section_label' => $existing['section_label'] ?? '',
			'section_order' => $existing['section_order'] ?? 20,
			'field_order'   => $existing['field_order'] ?? 0,
			'options'       => $existing['options'] ?? array(),
			'content'       => $existing['content'] ?? '',
		);

		$data = wp_parse_args( $data, $defaults );

		$allowed_types = array( 'text', 'textarea', 'select', 'checkbox', 'radio', 'date', 'number', 'content', 'image' );
		$field_type    = sanitize_key( $data['field_type'] );
		if ( ! in_array( $field_type, $allowed_types, true ) ) {
			$field_type = 'text';
		}

		$section_key = sanitize_key( $data['section_key'] ?: 'additional' );
		if ( empty( $section_key ) ) {
			$section_key = 'additional';
		}

		$section_label = sanitize_text_field( $data['section_label'] ?? '' );
		if ( '' === $section_label ) {
			$section_label = $this->get_default_section_label( $section_key );
		}

		// For content fields, use wp_kses_post to allow safe HTML
		$content = '';
		if ( 'content' === $field_type ) {
			$content = wp_kses_post( $data['content'] ?? '' );
		}

		// For image fields, don't normalize options (they have different structure)
		$options = $data['options'] ?? array();
		if ( 'image' !== $field_type ) {
			$options = $this->normalize_form_field_options( $options );
		} else {
			// For image fields, sanitize the options directly
			$options = $this->sanitize_image_config( $options );
		}

		return array(
			'field_type'    => $field_type,
			'field_name'    => sanitize_key( $data['field_name'] ?: $data['field_label'] ),
			'field_label'   => sanitize_text_field( $data['field_label'] ),
			'placeholder'   => sanitize_text_field( $data['placeholder'] ),
			'is_required'   => ! empty( $data['is_required'] ),
			'section_key'   => $section_key,
			'section_label' => $section_label,
			'section_order' => intval( $data['section_order'] ?: $this->get_default_section_order( $section_key ) ),
			'field_order'   => max( 1, intval( $data['field_order'] ) ),
			'options'       => $options,
			'content'       => $content,
		);
	}

	/**
	 * Sanifica valore registrazione ricorsivamente.
	 *
	 * @param mixed $value Valore raw.
	 * @return mixed
	 */
	private function sanitize_registration_value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'sanitize_registration_value' ), $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Recupera un campo modulo.
	 *
	 * @param int $field_id ID campo.
	 * @param int $activity_id ID attività.
	 * @return array|null
	 */
	private function get_form_field( $field_id, $activity_id ) {
		global $wpdb;

		$field = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'sd_activity_form_fields WHERE id = %d AND activity_id = %d LIMIT 1',
				$field_id,
				$activity_id
			),
			ARRAY_A
		);

		if ( ! $field ) {
			return null;
		}

		$field['options'] = json_decode( $field['options'], true ) ?: array();
		return $field;
	}

	/**
	 * Allinea metadati di sezione a tutti i campi della stessa sezione.
	 *
	 * @param int    $activity_id ID attività.
	 * @param string $section_key Chiave sezione.
	 * @param string $section_label Etichetta sezione.
	 * @param int    $section_order Ordine sezione.
	 * @return void
	 */
	private function sync_section_metadata( $activity_id, $section_key, $section_label, $section_order ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'sd_activity_form_fields',
			array(
				'section_label' => sanitize_text_field( $section_label ),
				'section_order' => intval( $section_order ),
			),
			array(
				'activity_id'  => intval( $activity_id ),
				'section_key'  => sanitize_key( $section_key ),
			),
			array( '%s', '%d' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Crea un nuovo campo di modulo
	 *
	 * @param int   $activity_id ID attività
	 * @param array $data Dati campo
	 * @return int|false ID campo o false se errore
	 */
	public function create_form_field( $activity_id, $data = array() ) {
		global $wpdb;

		$data = $this->normalize_form_field_data( $data );

		if ( empty( $data['field_name'] ) || empty( $data['field_label'] ) ) {
			return false;
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'sd_activity_form_fields',
			array(
				'activity_id'   => intval( $activity_id ),
				'field_type'    => sanitize_text_field( $data['field_type'] ),
				'field_name'    => sanitize_text_field( $data['field_name'] ),
				'field_label'   => sanitize_text_field( $data['field_label'] ),
				'placeholder'   => sanitize_text_field( $data['placeholder'] ),
				'is_required'   => intval( $data['is_required'] ),
				'section_key'   => sanitize_key( $data['section_key'] ),
				'section_label' => sanitize_text_field( $data['section_label'] ),
				'section_order' => intval( $data['section_order'] ),
				'field_order'   => intval( $data['field_order'] ),
				'options'       => wp_json_encode( $data['options'] ),
				'content'       => wp_kses_post( $data['content'] ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $result ) {
			return false;
		}

		$this->sync_section_metadata( $activity_id, $data['section_key'], $data['section_label'], $data['section_order'] );

		return $wpdb->insert_id;
	}

	/**
	 * Aggiorna un campo modulo.
	 *
	 * @param int   $field_id ID campo.
	 * @param int   $activity_id ID attività.
	 * @param array $data Dati campo.
	 * @return bool
	 */
	public function update_form_field( $field_id, $activity_id, $data = array() ) {
		global $wpdb;

		$existing = $this->get_form_field( $field_id, $activity_id );
		if ( ! $existing ) {
			return false;
		}

		$data = $this->normalize_form_field_data( $data, $existing );

		$result = $wpdb->update(
			$wpdb->prefix . 'sd_activity_form_fields',
			array(
				'field_type'    => sanitize_text_field( $data['field_type'] ),
				'field_name'    => sanitize_text_field( $data['field_name'] ),
				'field_label'   => sanitize_text_field( $data['field_label'] ),
				'placeholder'   => sanitize_text_field( $data['placeholder'] ),
				'is_required'   => intval( $data['is_required'] ),
				'section_key'   => sanitize_key( $data['section_key'] ),
				'section_label' => sanitize_text_field( $data['section_label'] ),
				'section_order' => intval( $data['section_order'] ),
				'field_order'   => intval( $data['field_order'] ),
				'options'       => wp_json_encode( $data['options'] ),
				'content'       => wp_kses_post( $data['content'] ),
			),
			array(
				'id'          => intval( $field_id ),
				'activity_id' => intval( $activity_id ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' ),
			array( '%d', '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$this->sync_section_metadata( $activity_id, $data['section_key'], $data['section_label'], $data['section_order'] );
		return true;
	}

	/**
	 * Elimina un campo modulo.
	 *
	 * @param int $field_id ID campo.
	 * @param int $activity_id ID attività.
	 * @return bool
	 */
	public function delete_form_field( $field_id, $activity_id ) {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'sd_activity_form_fields',
			array(
				'id'          => intval( $field_id ),
				'activity_id' => intval( $activity_id ),
			),
			array( '%d', '%d' )
		);

		return (bool) $result;
	}

	/**
	 * Sposta un campo sopra o sotto all'interno della stessa sezione.
	 *
	 * @param int    $field_id ID campo.
	 * @param int    $activity_id ID attività.
	 * @param string $direction Direzione.
	 * @return bool
	 */
	public function move_form_field( $field_id, $activity_id, $direction ) {
		global $wpdb;

		$current = $this->get_form_field( $field_id, $activity_id );
		if ( ! $current ) {
			return false;
		}

		$direction = 'up' === $direction ? 'up' : 'down';
		$operator  = 'up' === $direction ? '<' : '>';
		$order     = 'up' === $direction ? 'DESC' : 'ASC';

		$swap = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, field_order FROM ' . $wpdb->prefix . 'sd_activity_form_fields WHERE activity_id = %d AND section_key = %s AND field_order ' . $operator . ' %d ORDER BY field_order ' . $order . ', id ' . $order . ' LIMIT 1',
				$activity_id,
				$current['section_key'] ?: 'additional',
				intval( $current['field_order'] )
			),
			ARRAY_A
		);

		if ( $swap ) {
			// Scambia con il campo trovato
			$wpdb->update(
				$wpdb->prefix . 'sd_activity_form_fields',
				array( 'field_order' => intval( $swap['field_order'] ) ),
				array( 'id' => intval( $current['id'] ) ),
				array( '%d' ),
				array( '%d' )
			);

			$wpdb->update(
				$wpdb->prefix . 'sd_activity_form_fields',
				array( 'field_order' => intval( $current['field_order'] ) ),
				array( 'id' => intval( $swap['id'] ) ),
				array( '%d' ),
				array( '%d' )
			);
		} else {
			// Nessun campo da scambiare: incrementa/decrementa il field_order
			$new_order = intval( $current['field_order'] ) + ( 'up' === $direction ? -1 : 1 );
			if ( $new_order < 0 ) {
				$new_order = 0;
			}
			$wpdb->update(
				$wpdb->prefix . 'sd_activity_form_fields',
				array( 'field_order' => $new_order ),
				array( 'id' => intval( $current['id'] ) ),
				array( '%d' ),
				array( '%d' )
			);
		}

		return true;
	}

	// ======================================================================
	// METODI PUBBLICI - ISCRIZIONI
	// ======================================================================

	/**
	 * Registra un partecipante all'attività
	 *
	 * @param int   $activity_id ID attività
	 * @param array $data Dati iscrizione
	 * @return int|false ID iscrizione o false se errore
	 */
	public function register_for_activity( $activity_id, $data = array() ) {
		global $wpdb;

		$defaults = array(
			'member_id'          => null,
			'email'              => '',
			'first_name'         => '',
			'last_name'          => '',
			'registration_data'  => array(),
			'price_id'           => null,
			'price_chf'          => 0,
			'price_eur'          => 0,
		);

		$data = wp_parse_args( $data, $defaults );

		// Validazione
		if ( empty( $data['email'] ) || empty( $data['first_name'] ) || empty( $data['last_name'] ) ) {
			return false;
		}

		// Verificare disponibilità posti
		$activity = $this->get_activity( $activity_id );
		if ( $activity && $activity['max_participants'] ) {
			if ( $activity['registrations_count'] >= $activity['max_participants'] ) {
				// Mettere in lista d'attesa
				$status = 'waitlist';
			} else {
				$status = 'registered';
			}
		} else {
			$status = 'registered';
		}

		return $wpdb->insert(
			$wpdb->prefix . 'sd_activity_registrations',
			array(
				'activity_id'           => intval( $activity_id ),
				'member_id'             => intval( $data['member_id'] ) ?: null,
				'email'                 => sanitize_email( $data['email'] ),
				'first_name'            => sanitize_text_field( $data['first_name'] ),
				'last_name'             => sanitize_text_field( $data['last_name'] ),
				'registration_data'     => wp_json_encode( $data['registration_data'] ),
				'status'                => $status,
				'payment_status'        => 'pending',
				'price_id'              => intval( $data['price_id'] ) ?: null,
				'price_chf'             => floatval( $data['price_chf'] ),
				'price_eur'             => floatval( $data['price_eur'] ),
				'created_by'            => get_current_user_id(),
				'created_at'            => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%f', '%d', '%s' )
		) ? $wpdb->insert_id : false;
	}

	/**
	 * Recupera le iscrizioni di un'attività
	 *
	 * @param int   $activity_id ID attività
	 * @param array $args Argomenti di filtro
	 * @return array Array di iscrizioni
	 */
	public function get_registrations( $activity_id, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page' => 20,
			'page'     => 1,
			'status'   => '',
			'payment_status' => '',
			'search'   => '',
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where = array( 'activity_id = ' . intval( $activity_id ) );

		if ( ! empty( $args['status'] ) ) {
			$where[] = "status = '" . esc_sql( $args['status'] ) . "'";
		}

		if ( ! empty( $args['payment_status'] ) ) {
			$where[] = "payment_status = '" . esc_sql( $args['payment_status'] ) . "'";
		}

		if ( ! empty( $args['search'] ) ) {
			$search = '%' . esc_sql( $args['search'] ) . '%';
			$where[] = $wpdb->prepare(
				'(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)',
				$search,
				$search,
				$search
			);
		}

		$where_clause = implode( ' AND ', $where );

		$per_page = intval( $args['per_page'] );
		$page     = max( 1, intval( $args['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		$registrations = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'sd_activity_registrations
				WHERE ' . $where_clause . '
				ORDER BY ' . esc_sql( $args['orderby'] ) . ' ' . esc_sql( $args['order'] ) . '
				LIMIT %d OFFSET %d',
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$current_rate = 0.0;
		if ( class_exists( 'SD_Currency_Converter' ) ) {
			$converter    = SD_Currency_Converter::get_instance();
			$current_rate = floatval( $converter->get_rate() );
		}

		foreach ( $registrations as &$reg ) {
			$reg['registration_data'] = json_decode( $reg['registration_data'], true ) ?: array();
			$reg['price_chf'] = floatval( $reg['price_chf'] );
			$reg['price_eur'] = floatval( $reg['price_eur'] );

			// Se EUR non è stato salvato, calcola il controvalore al cambio attuale.
			if ( $reg['price_chf'] > 0 && $reg['price_eur'] <= 0 && $current_rate > 0 ) {
				$reg['price_eur'] = round( $reg['price_chf'] * $current_rate, 2 );
			}
		}

		return $registrations;
	}

	/**
	 * Conta le iscrizioni di un'attività
	 *
	 * @param int $activity_id ID attività
	 * @return int Numero iscritti
	 */
	public function get_registrations_count( $activity_id ) {
		global $wpdb;

		return intval(
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'sd_activity_registrations WHERE activity_id = %d AND status != %s',
					$activity_id,
					'cancelled'
				)
			)
		);
	}

	/**
	 * Aggiorna lo stato di pagamento di un'iscrizione
	 *
	 * @param int    $registration_id ID iscrizione
	 * @param string $payment_status Stato pagamento
	 * @param array  $payment_data Dati pagamento aggiuntivi
	 * @return bool
	 */
	public function update_registration_payment_status( $registration_id, $payment_status = 'pending', $payment_data = array() ) {
		global $wpdb;

		$update_data = array(
			'payment_status' => sanitize_text_field( $payment_status ),
			'updated_at'     => current_time( 'mysql' ),
		);

		if ( 'paid' === $payment_status && empty( $payment_data['payment_date'] ) ) {
			$update_data['payment_date'] = current_time( 'mysql' );
		} elseif ( ! empty( $payment_data['payment_date'] ) ) {
			$update_data['payment_date'] = sanitize_text_field( $payment_data['payment_date'] );
		}

		if ( ! empty( $payment_data['payment_method'] ) ) {
			$update_data['payment_method'] = sanitize_text_field( $payment_data['payment_method'] );
		}

		if ( ! empty( $payment_data['transaction_id'] ) ) {
			$update_data['transaction_id'] = sanitize_text_field( $payment_data['transaction_id'] );
		}

		if ( ! empty( $payment_data['invoice_number'] ) ) {
			$update_data['invoice_number'] = sanitize_text_field( $payment_data['invoice_number'] );
		}

		return (bool) $wpdb->update(
			$wpdb->prefix . 'sd_activity_registrations',
			$update_data,
			array( 'id' => $registration_id ),
			null,
			array( '%d' )
		);
	}

	/**
	 * Aggiorna lo stato iscrizione.
	 *
	 * @param int    $registration_id ID iscrizione.
	 * @param string $status Stato iscrizione.
	 * @return bool
	 */
	public function update_registration_status( $registration_id, $status = 'registered' ) {
		global $wpdb;

		$allowed_statuses = array( 'registered', 'waitlist', 'cancelled' );
		$status           = sanitize_text_field( $status );

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return false;
		}

		return (bool) $wpdb->update(
			$wpdb->prefix . 'sd_activity_registrations',
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => intval( $registration_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	// ======================================================================
	// METODI AJAX
	// ======================================================================

	/**
	 * AJAX: Recupera lista attività
	 */
	public function ajax_get_activities_list() {
		global $wpdb;

		$per_page = intval( $_POST['per_page'] ?? 10 );
		$page     = max( 1, intval( $_POST['page'] ?? 1 ) );
		$search   = sanitize_text_field( $_POST['search'] ?? '' );
		$status   = sanitize_text_field( $_POST['status'] ?? '' );

		$where = array( '1=1' );

		if ( ! empty( $search ) ) {
			$search = '%' . esc_sql( $search ) . '%';
			$where[] = $wpdb->prepare( '(title LIKE %s OR location LIKE %s)', $search, $search );
		}

		if ( ! empty( $status ) ) {
			$where[] = "event_status = '" . esc_sql( $status ) . "'";
		}

		$where_clause = implode( ' AND ', $where );
		$offset       = ( $page - 1 ) * $per_page;

		$activities = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'sd_activities
				WHERE ' . $where_clause . '
				ORDER BY start_date ASC
				LIMIT %d OFFSET %d',
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$total = intval(
			$wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'sd_activities WHERE ' . $where_clause )
		);

		// Decodifica JSON
		foreach ( $activities as &$activity ) {
			$activity['form_configuration']  = json_decode( $activity['form_configuration'], true ) ?: array();
			$activity['price_configuration'] = json_decode( $activity['price_configuration'], true ) ?: array();
			$activity['registrations_count'] = $this->get_registrations_count( $activity['id'] );
		}

		wp_send_json_success(
			array(
				'activities' => $activities,
				'total'      => $total,
				'page'       => $page,
				'per_page'   => $per_page,
			)
		);
	}

	/**
	 * AJAX: Recupera dettagli attività
	 */
	public function ajax_get_activity_details() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		$activity_id = intval( $_POST['activity_id'] ?? 0 );

		if ( ! $activity_id ) {
			wp_send_json_error( array( 'message' => __( 'ID attività non valido', 'sd-logbook' ) ) );
		}

		$activity = $this->get_activity( $activity_id );

		if ( ! $activity ) {
			wp_send_json_error( array( 'message' => __( 'Attività non trovata', 'sd-logbook' ) ) );
		}

		wp_send_json_success( $activity );
	}

	/**
	 * AJAX: Registra un partecipante all'attività
	 */
	public function ajax_register_for_activity() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		$activity_id = intval( $_POST['activity_id'] ?? 0 );

		if ( ! $activity_id ) {
			wp_send_json_error( array( 'message' => __( 'ID attività non valido', 'sd-logbook' ) ) );
		}

		$activity = $this->get_activity( $activity_id );
		if ( ! $activity ) {
			wp_send_json_error( array( 'message' => __( 'Attività non trovata', 'sd-logbook' ) ) );
		}

		// Recuperare i dati del modulo
		$email       = sanitize_email( $_POST['email'] ?? '' );
		$first_name  = sanitize_text_field( $_POST['first_name'] ?? '' );
		$last_name   = sanitize_text_field( $_POST['last_name'] ?? '' );
		$price_ids_raw = array();
		if ( isset( $_POST['price_ids'] ) ) {
			$raw_price_ids = wp_unslash( $_POST['price_ids'] );
			if ( is_string( $raw_price_ids ) ) {
				$decoded = json_decode( $raw_price_ids, true );
				if ( is_array( $decoded ) ) {
					$price_ids_raw = $decoded;
				} elseif ( '' !== trim( $raw_price_ids ) ) {
					$price_ids_raw = explode( ',', $raw_price_ids );
				}
			} elseif ( is_array( $raw_price_ids ) ) {
				$price_ids_raw = $raw_price_ids;
			}
		}

		$price_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) $price_ids_raw ),
					static function ( $value ) {
						return $value > 0;
					}
				)
			)
		);

		if ( empty( $price_ids ) ) {
			$legacy_price_id = intval( $_POST['price_id'] ?? 0 );
			if ( $legacy_price_id > 0 ) {
				$price_ids = array( $legacy_price_id );
			}
		}

		if ( ! $email || ! $first_name || ! $last_name || empty( $price_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Campi obbligatori mancanti', 'sd-logbook' ) ) );
		}

		// Verificare che i prezzi appartengono a questa attività e calcolare i totali.
		$selected_prices     = array();
		$total_price_chf     = 0.0;
		$total_price_eur     = 0.0;
		$selected_price_names = array();

		foreach ( $price_ids as $single_price_id ) {
			$price = $this->get_price( $single_price_id );
			if ( ! $price || intval( $price['activity_id'] ) !== intval( $activity_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Tariffa non valida', 'sd-logbook' ) ) );
			}

			$selected_prices[] = $price;
			$total_price_chf  += floatval( $price['price_chf'] );

			$price_eur = floatval( $price['price_eur'] );
			if ( $price_eur <= 0 && class_exists( 'SD_Currency_Converter' ) ) {
				$converted = SD_Currency_Converter::get_instance()->convert_chf_to_eur( floatval( $price['price_chf'] ) );
				if ( false !== $converted ) {
					$price_eur = floatval( $converted );
				}
			}
			$total_price_eur += max( 0, $price_eur );

			$selected_price_names[] = sanitize_text_field( $price['price_name'] ?? '' );
		}

		$primary_price_id = intval( $price_ids[0] ?? 0 );

		// Raccogliere dati del modulo
		$registration_data_raw = wp_unslash( $_POST['registration_data'] ?? '' );
		$registration_data     = json_decode( $registration_data_raw, true );

		if ( ! is_array( $registration_data ) ) {
			$registration_data = array();
			foreach ( $activity['form_fields'] as $field ) {
				$field_name = 'field_' . $field['field_name'];
				if ( isset( $_POST[ $field_name ] ) ) {
					$registration_data[ $field['field_name'] ] = $this->sanitize_registration_value( wp_unslash( $_POST[ $field_name ] ) );
				}
				if ( isset( $_POST[ $field_name . '[]' ] ) ) {
					$registration_data[ $field['field_name'] ] = $this->sanitize_registration_value( wp_unslash( $_POST[ $field_name . '[]' ] ) );
				}
			}
		} else {
			foreach ( $registration_data as $field_name => $field_value ) {
				$registration_data[ sanitize_key( $field_name ) ] = $this->sanitize_registration_value( $field_value );
			}
		}

		$registration_data['selected_price_ids']   = $price_ids;
		$registration_data['selected_price_names'] = $selected_price_names;
		$registration_data['selected_price_count'] = count( $price_ids );

		// Determinare se l'utente è loggato
		$member_id = is_user_logged_in() ? get_current_user_id() : null;

		// Registrare l'iscrizione
		$registration_id = $this->register_for_activity(
			$activity_id,
			array(
				'member_id'         => $member_id,
				'email'             => $email,
				'first_name'        => $first_name,
				'last_name'         => $last_name,
				'registration_data' => $registration_data,
				'price_id'          => $primary_price_id,
				'price_chf'         => $total_price_chf,
				'price_eur'         => $total_price_eur,
			)
		);

		if ( ! $registration_id ) {
			wp_send_json_error( array( 'message' => __( 'Errore nella registrazione', 'sd-logbook' ) ) );
		}

		// Se il prezzo è zero o non configurato, iscrizione immediata senza pagamento.
		$amount_chf = floatval( $total_price_chf );
		if ( $amount_chf <= 0 ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'sd_activity_registrations',
				array( 'payment_status' => 'free' ),
				array( 'id' => $registration_id ),
				array( '%s' ),
				array( '%d' )
			);
			wp_send_json_success(
				array(
					'registration_id' => $registration_id,
					'payment_required' => false,
					'message'          => __( 'Iscrizione completata con successo.', 'sd-logbook' ),
				)
			);
			return;
		}

		// Genera token di pagamento (valido 48 ore).
		$confirmation_token  = bin2hex( random_bytes( 24 ) );
		$confirmation_expires = gmdate( 'Y-m-d H:i:s', time() + 172800 );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'sd_activity_registrations',
			array(
				'confirmation_token'      => $confirmation_token,
				'confirmation_expires_at' => $confirmation_expires,
			),
			array( 'id' => $registration_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Costruisce URL checkout attività.
		$activity_flow = new SD_Activity_Payment_Flow();
		$checkout_url  = add_query_arg(
			array( 'sdapt' => rawurlencode( $confirmation_token ) ),
			$activity_flow->get_checkout_page_url()
		);

		wp_send_json_success(
			array(
				'registration_id'  => $registration_id,
				'payment_required' => true,
				'redirect_url'     => $checkout_url,
				'message'          => __( 'Registrazione completata. Reindirizzamento al pagamento...', 'sd-logbook' ),
			)
		);
	}

	/**
	 * AJAX (Admin): Salva attività
	 */
	public function ajax_save_activity() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		$data        = isset( $_POST['activity'] ) ? wp_unslash( (array) $_POST['activity'] ) : array();

		if ( $activity_id ) {
			$result = $this->update_activity( $activity_id, $data );
		} else {
			$result = $this->create_activity( $data );
			$activity_id = $result;
		}

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Errore nel salvataggio', 'sd-logbook' ) ) );
		}

		wp_send_json_success(
			array(
				'activity_id' => $activity_id,
				'message'     => __( 'Attività salvata con successo', 'sd-logbook' ),
			)
		);
	}

	/**
	 * AJAX (Admin): Elimina attività
	 */
	public function ajax_delete_activity() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$activity_id = intval( $_POST['activity_id'] ?? 0 );

		if ( ! $this->delete_activity( $activity_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Errore nell\'eliminazione', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Attività eliminata', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX (Admin): Aggiorna campo modulo
	 */
	public function ajax_update_form_field() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		$field_id    = intval( $_POST['field_id'] ?? 0 );
		$field_data  = isset( $_POST['field'] ) ? wp_unslash( (array) $_POST['field'] ) : array();

		if ( ! $activity_id || empty( $field_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Dati campo non validi', 'sd-logbook' ) ) );
		}

		if ( $field_id ) {
			$result = $this->update_form_field( $field_id, $activity_id, $field_data );
		} else {
			$field_id = $this->create_form_field( $activity_id, $field_data );
			$result   = (bool) $field_id;
		}

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Errore nel salvataggio del campo', 'sd-logbook' ) ) );
		}

		wp_send_json_success(
			array(
				'field_id' => $field_id,
				'message'  => $field_id && ! empty( $_POST['field_id'] ) ? __( 'Campo aggiornato', 'sd-logbook' ) : __( 'Campo salvato', 'sd-logbook' ),
			)
		);
	}

	/**
	 * AJAX (Admin): Elimina campo modulo.
	 */
	public function ajax_delete_form_field() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		$field_id    = intval( $_POST['field_id'] ?? 0 );

		if ( ! $activity_id || ! $field_id || ! $this->delete_form_field( $field_id, $activity_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Impossibile eliminare il campo', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Campo eliminato', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX (Admin): Sposta campo modulo.
	 */
	public function ajax_move_form_field() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		$field_id    = intval( $_POST['field_id'] ?? 0 );
		$direction   = sanitize_key( $_POST['direction'] ?? 'up' );

		if ( ! $activity_id || ! $field_id || ! $this->move_form_field( $field_id, $activity_id, $direction ) ) {
			wp_send_json_error( array( 'message' => __( 'Impossibile spostare il campo', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Ordine campo aggiornato', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX (Admin): Salva tariffa attività
	 */
	public function ajax_save_activity_price() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		$price_data  = isset( $_POST['price'] ) ? (array) $_POST['price'] : array();
		$price_id    = intval( $price_data['id'] ?? 0 );

		if ( ! $activity_id || empty( $price_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Dati tariffa non validi', 'sd-logbook' ) ) );
		}

		$price_payload = array(
			'price_name'         => sanitize_text_field( $price_data['price_name'] ?? '' ),
			'price_chf'          => floatval( $price_data['price_chf'] ?? 0 ),
			'price_eur'          => floatval( $price_data['price_eur'] ?? 0 ),
			'currency_rate'      => floatval( $price_data['currency_rate'] ?? 0 ),
			'currency_rate_date' => sanitize_text_field( $price_data['currency_rate_date'] ?? '' ),
			'is_default'         => ! empty( $price_data['is_default'] ) ? 1 : 0,
		);

		if ( $price_payload['price_chf'] > 0 && class_exists( 'SD_Currency_Converter' ) ) {
			if ( empty( $price_payload['currency_rate_date'] ) ) {
				$price_payload['currency_rate_date'] = wp_date( 'Y-m-d' );
			}

			$converter = SD_Currency_Converter::get_instance();

			if ( $price_payload['currency_rate'] <= 0 ) {
				$rate = $converter->get_rate( $price_payload['currency_rate_date'] );
				if ( $rate ) {
					$price_payload['currency_rate'] = floatval( $rate );
				}
			}

			if ( $price_payload['price_eur'] <= 0 ) {
				$converted = $converter->convert_chf_to_eur( $price_payload['price_chf'], $price_payload['currency_rate_date'] );
				if ( false !== $converted ) {
					$price_payload['price_eur'] = floatval( $converted );
				}
			}
		}

		if ( $price_id > 0 ) {
			$updated = $this->update_price( $activity_id, $price_id, $price_payload );

			if ( ! $updated ) {
				wp_send_json_error( array( 'message' => __( 'Errore nell\'aggiornamento della tariffa', 'sd-logbook' ) ) );
			}

			wp_send_json_success(
				array(
					'price_id' => $price_id,
					'message'  => __( 'Tariffa aggiornata con successo', 'sd-logbook' ),
				)
			);
		}

		$created_price_id = $this->create_price( $activity_id, $price_payload );

		if ( ! $created_price_id ) {
			wp_send_json_error( array( 'message' => __( 'Errore nel salvataggio della tariffa', 'sd-logbook' ) ) );
		}

		wp_send_json_success(
			array(
				'price_id' => $created_price_id,
				'message'  => __( 'Tariffa salvata con successo', 'sd-logbook' ),
			)
		);
	}

	/**
	 * AJAX (Admin): Elimina una tariffa attività.
	 */
	public function ajax_delete_activity_price() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		$price_id    = intval( $_POST['price_id'] ?? 0 );

		if ( ! $activity_id || ! $price_id ) {
			wp_send_json_error( array( 'message' => __( 'Dati tariffa non validi', 'sd-logbook' ) ) );
		}

		if ( ! $this->delete_price( $activity_id, $price_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Impossibile eliminare la tariffa', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Tariffa eliminata', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX (Admin): Imposta una tariffa predefinita.
	 */
	public function ajax_set_default_activity_price() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		$price_id    = intval( $_POST['price_id'] ?? 0 );

		if ( ! $activity_id || ! $price_id ) {
			wp_send_json_error( array( 'message' => __( 'Dati tariffa non validi', 'sd-logbook' ) ) );
		}

		if ( ! $this->set_default_price( $activity_id, $price_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Impossibile impostare la tariffa predefinita', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Tariffa predefinita aggiornata', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX (Admin): Elimina tutte le tariffe dell'attività.
	 */
	public function ajax_delete_all_prices() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		if ( ! $activity_id ) {
			wp_send_json_error( array( 'message' => __( 'ID attività non valido', 'sd-logbook' ) ) );
		}

		if ( ! $this->delete_all_prices( $activity_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Impossibile eliminare le tariffe', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Tariffe eliminate', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX (Admin): Lista iscrizioni
	 */
	public function ajax_get_registrations_list() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		$per_page    = intval( $_POST['per_page'] ?? 20 );
		$page        = max( 1, intval( $_POST['page'] ?? 1 ) );

		$args = array(
			'per_page'       => $per_page,
			'page'           => $page,
			'payment_status' => sanitize_text_field( $_POST['payment_status'] ?? '' ),
			'search'         => sanitize_text_field( $_POST['search'] ?? '' ),
		);

		$registrations = $this->get_registrations( $activity_id, $args );

		wp_send_json_success(
			array(
				'registrations' => $registrations,
			)
		);
	}

	/**
	 * AJAX (Admin): Aggiorna stato iscrizione.
	 */
	public function ajax_update_registration_status() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$registration_id = intval( $_POST['registration_id'] ?? 0 );
		$status          = sanitize_text_field( $_POST['status'] ?? 'registered' );

		if ( ! $this->update_registration_status( $registration_id, $status ) ) {
			wp_send_json_error( array( 'message' => __( 'Errore nell\'aggiornamento stato', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Stato registrazione aggiornato', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX (Admin): Aggiorna stato pagamento iscrizione
	 */
	public function ajax_update_registration_payment() {
		check_ajax_referer( 'sd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}

		$registration_id = intval( $_POST['registration_id'] ?? 0 );
		$payment_status  = sanitize_text_field( $_POST['payment_status'] ?? 'pending' );
		$payment_data    = isset( $_POST['payment_data'] ) ? array_map( 'sanitize_text_field', (array) $_POST['payment_data'] ) : array();

		if ( ! $this->update_registration_payment_status( $registration_id, $payment_status, $payment_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Errore nell\'aggiornamento', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Pagamento aggiornato', 'sd-logbook' ) ) );
	}

	// ======================================================================
	// METODI PRIVATI - HELPER
	// ======================================================================

	/**
	 * Recupera i dettagli di una tariffa
	 *
	 * @param int $price_id ID tariffa
	 * @return array|null
	 */
	private function get_price( $price_id ) {
		global $wpdb;

		$price = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'sd_activity_prices WHERE id = %d',
				$price_id
			),
			ARRAY_A
		);

		if ( $price ) {
			$price['price_chf']  = floatval( $price['price_chf'] );
			$price['price_eur']  = floatval( $price['price_eur'] );
			$price['is_default'] = (bool) $price['is_default'];
		}

		return $price;
	}

	// ======================================================================
	// SHORTCODES & FRONTEND
	// ======================================================================

	/**
	 * Shortcode: [sd_gestione_attivita]
	 * Dashboard amministrativa attività
	 */
	public function shortcode_activity_admin_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<div class="sd-notice sd-notice-error">' . esc_html__( 'Permessi insufficienti per accedere alla dashboard attività.', 'sd-logbook' ) . '</div>';
		}

		wp_enqueue_media();
		wp_enqueue_editor();
		$activity_admin_css_version = SD_LOGBOOK_VERSION;
		$activity_admin_css_file    = SD_LOGBOOK_PLUGIN_DIR . 'assets/css/activity-admin.css';
		if ( file_exists( $activity_admin_css_file ) ) {
			$activity_admin_css_version .= '.' . filemtime( $activity_admin_css_file );
		}

		$activity_admin_js_version = SD_LOGBOOK_VERSION;
		$activity_admin_js_file    = SD_LOGBOOK_PLUGIN_DIR . 'assets/js/activity-admin.js';
		if ( file_exists( $activity_admin_js_file ) ) {
			$activity_admin_js_version .= '.' . filemtime( $activity_admin_js_file );
		}

		wp_enqueue_style( 'sd-activity-admin', SD_LOGBOOK_PLUGIN_URL . 'assets/css/activity-admin.css', array(), $activity_admin_css_version );
		wp_enqueue_script( 'sd-activity-admin', SD_LOGBOOK_PLUGIN_URL . 'assets/js/activity-admin.js', array( 'jquery', 'jquery-ui-sortable' ), $activity_admin_js_version, true );

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/activity-admin.php';
		return ob_get_clean();
	}

	/**
	 * Shortcode: [sd_iscrizione_attivita activity_id="X"]
	 * Mostra il modulo di iscrizione per un'attività
	 */
	public function shortcode_activity_registration_form( $atts ) {
		// Parse attributes
		$atts = shortcode_atts(
			array(
				'activity_id' => 0,
				'id'          => 0,
				'modal'       => false,
			),
			$atts,
			'sd_iscrizione_attivita'
		);

		$activity_id = intval( $atts['activity_id'] ?: $atts['id'] );

		if ( ! $activity_id ) {
			return '<div class="sd-notice sd-notice-error">' . esc_html__( 'ID attività non specificato', 'sd-logbook' ) . '</div>';
		}

		// Verificare che l'attività esista
		$activity = $this->get_activity( $activity_id );
		if ( ! $activity ) {
			return '<div class="sd-notice sd-notice-error">' . esc_html__( 'Attività non trovata. Verifica lo shortcode e crea prima almeno una attività di test.', 'sd-logbook' ) . '</div>';
		}

		// Enqueue CSS e JS
		wp_enqueue_style( 'sd-activity-registration', SD_LOGBOOK_PLUGIN_URL . 'assets/css/activity-registration.css', array(), SD_LOGBOOK_VERSION );
		$registration_js_version = SD_LOGBOOK_VERSION;
		$registration_js_file    = SD_LOGBOOK_PLUGIN_DIR . 'assets/js/activity-registration.js';
		if ( file_exists( $registration_js_file ) ) {
			$registration_js_version .= '.' . filemtime( $registration_js_file );
		}
		wp_enqueue_script( 'sd-activity-registration', SD_LOGBOOK_PLUGIN_URL . 'assets/js/activity-registration.js', array( 'jquery' ), $registration_js_version, true );

		// Render template
		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/activity-registration-form.php';
		return ob_get_clean();
	}

	/**
	 * AJAX: Recupera dettagli attività (per frontend - con campi e tariffe)
	 */
	public function ajax_activity_get_details() {
		check_ajax_referer( 'sd_activity_nonce', 'nonce' );

		$activity_id = intval( $_POST['activity_id'] ?? 0 );

		if ( ! $activity_id ) {
			wp_send_json_error( array( 'message' => __( 'ID attività non valido', 'sd-logbook' ) ) );
		}

		$activity = $this->get_activity( $activity_id );

		if ( ! $activity ) {
			wp_send_json_error( array( 'message' => __( 'Attività non trovata', 'sd-logbook' ) ) );
		}

		// Formato le date
		$activity['start_date_formatted'] = date_i18n( 'd.m.Y H:i', strtotime( $activity['start_date'] ) );
		$activity['end_date_formatted']   = date_i18n( 'd.m.Y H:i', strtotime( $activity['end_date'] ) );

		// Includi form_fields e prices
		$activity['form_fields'] = $this->get_form_fields( $activity_id );
		$activity['prices']      = $this->get_activity_prices( $activity_id );

		wp_send_json_success(
			array(
				'activity'     => $activity,
				'form_fields'  => $activity['form_fields'],
				'prices'       => $activity['prices'],
			)
		);
	}
}
