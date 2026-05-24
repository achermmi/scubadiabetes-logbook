<?php
/**
 * SD_PDF_Template_Designer
 *
 * Designer drag-and-drop per template PDF attività/registrazioni.
 * Shortcode: [sd_pdf_template_designer]
 *
 * @package ScubaDiabetes_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_PDF_Template_Designer {

	// Campi fissi disponibili per le registrazioni
	const FIXED_FIELDS = array(
		'reg_first_name' => 'Nome',
		'reg_last_name' => 'Cognome',
		'reg_email' => 'Email',
		'reg_birth_date' => 'Data di nascita',
		'reg_status' => 'Stato iscrizione',
		'reg_payment_status' => 'Stato pagamento',
		'reg_payment_method' => 'Metodo pagamento',
		'reg_price_chf' => 'Prezzo CHF',
		'reg_price_eur' => 'Prezzo EUR',
		'reg_invoice_number' => 'N. Fattura',
		'reg_created_at' => 'Data iscrizione',
	);

	// Campi fissi disponibili per l'attività
	const ACTIVITY_FIELDS = array(
		'act_title' => 'Titolo attività',
		'act_start_date' => 'Data inizio',
		'act_end_date' => 'Data fine',
		'act_location' => 'Luogo',
		'act_description' => 'Descrizione',
		'act_max_participants' => 'Max partecipanti',
		'act_event_status' => 'Stato evento',
	);

	// Campi fissi disponibili per i Soci
	const MEMBER_FIELDS = array(
		'mbr_first_name'       => 'Nome',
		'mbr_last_name'        => 'Cognome',
		'mbr_email'            => 'E-mail',
		'mbr_date_of_birth'    => 'Data di nascita',
		'mbr_member_number'    => 'Numero socio',
		'mbr_member_type'      => 'Tipo socio',
		'mbr_membership_type'  => 'Tipo iscrizione',
		'mbr_fee_amount'       => 'Quota associativa',
		'mbr_membership_expiry' => 'Scadenza iscrizione',
		'mbr_phone'            => 'Telefono',
		'mbr_address_street'   => 'Indirizzo',
		'mbr_address_postal'   => 'CAP',
		'mbr_address_city'     => 'Città',
		'mbr_address_country'  => 'Nazione',
		'mbr_taglia_maglietta' => 'Taglia maglietta',
		'mbr_diabetes_type'    => 'Tipo diabete',
		'mbr_blood_type'       => 'Gruppo sanguigno',
		'mbr_weight'           => 'Peso',
		'mbr_height'           => 'Altezza',
		'mbr_is_scuba'         => 'Subacqueo',
		'mbr_gender'           => 'Genere',
		'mbr_birth_place'      => 'Luogo di nascita',
		'mbr_fiscal_code'      => 'Codice fiscale / AVS',
		'mbr_privacy_consent'  => 'Consenso privacy',
	);

	public function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		add_shortcode( 'sd_pdf_template_designer', array( $this, 'shortcode_designer' ) );
		add_action( 'wp_ajax_sd_pdf_tpl_save', array( $this, 'ajax_template_save' ) );
		add_action( 'wp_ajax_sd_pdf_tpl_list', array( $this, 'ajax_template_list' ) );
		add_action( 'wp_ajax_sd_pdf_tpl_load', array( $this, 'ajax_template_load' ) );
		add_action( 'wp_ajax_sd_pdf_tpl_delete', array( $this, 'ajax_template_delete' ) );
		add_action( 'wp_ajax_sd_pdf_tpl_fields', array( $this, 'ajax_activity_fields' ) );
		add_action( 'wp_ajax_sd_pdf_tpl_generate', array( $this, 'ajax_generate_pdf' ) );
		add_action( 'wp_ajax_sd_pdf_tpl_gen_all', array( $this, 'ajax_generate_pdf_all' ) );
		add_action( 'wp_ajax_sd_pdf_tpl_gen_member', array( $this, 'ajax_generate_member_pdf' ) );
		add_action( 'wp_ajax_sd_pdf_tpl_gen_all_members', array( $this, 'ajax_generate_member_pdf_all' ) );
	}

	// =========================================================================
	// SHORTCODE
	// =========================================================================

	public function shortcode_designer( $atts ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'Accesso non autorizzato.', 'sd-logbook' ) . '</p>';
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'sd-pdf-template-designer',
			plugin_dir_url( __DIR__ ) . 'assets/css/activity-pdf-designer.css',
			array(),
			SD_LOGBOOK_VERSION
		);

		wp_enqueue_script(
			'sd-pdf-template-designer',
			plugin_dir_url( __DIR__ ) . 'assets/js/activity-pdf-designer.js',
			array( 'jquery' ),
			SD_LOGBOOK_VERSION,
			true
		);

		wp_localize_script(
			'sd-pdf-template-designer',
			'sdPdfDesigner',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'sd_nonce' ),
				'fixedFields'    => self::FIXED_FIELDS,
				'activityFields' => self::ACTIVITY_FIELDS,
				'memberFields'   => self::MEMBER_FIELDS,
				'textDomain'     => 'sd-logbook',
			)
		);

		ob_start();
		include plugin_dir_path( __DIR__ ) . 'templates/activity-pdf-designer.php';
		return ob_get_clean();
	}

	// =========================================================================
	// AJAX: SALVA TEMPLATE
	// =========================================================================

	public function ajax_template_save() {
		check_ajax_referer( 'sd_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		global $wpdb;

		$name        = sanitize_text_field( $_POST['name'] ?? '' );
		$orientation = sanitize_key( $_POST['orientation'] ?? 'portrait' );
		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		$template_id = intval( $_POST['template_id'] ?? 0 );
		$tpl_type    = sanitize_key( $_POST['template_type'] ?? 'activity' );
		if ( ! in_array( $tpl_type, array( 'activity', 'member' ), true ) ) {
			$tpl_type = 'activity';
		}

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Nome template richiesto.', 'sd-logbook' ) ) );
		}

		$elements_raw = stripslashes( $_POST['elements_json'] ?? '[]' );
		$elements     = json_decode( $elements_raw, true );
		if ( ! is_array( $elements ) ) {
			$elements = array();
		}

		// Sanifica ogni elemento
		$elements = array_map( array( $this, 'sanitize_element' ), $elements );

		$data = array(
			'name'          => $name,
			'orientation'   => in_array( $orientation, array( 'portrait', 'landscape' ), true ) ? $orientation : 'portrait',
			'elements_json' => wp_json_encode( $elements ),
			'activity_id'   => ( 'activity' === $tpl_type && $activity_id > 0 ) ? $activity_id : null,
			'template_type' => $tpl_type,
			'updated_at'    => current_time( 'mysql' ),
		);

		if ( $template_id > 0 ) {
			$wpdb->update(
				$wpdb->prefix . 'sd_pdf_templates',
				$data,
				array( 'id' => $template_id ),
				array( '%s', '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
			wp_send_json_success(
				array(
					'template_id' => $template_id,
					'message' => __( 'Template salvato.', 'sd-logbook' ),
				)
			);
		} else {
			$data['created_by'] = get_current_user_id();
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert(
				$wpdb->prefix . 'sd_pdf_templates',
				$data,
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
			);
			wp_send_json_success(
				array(
					'template_id' => $wpdb->insert_id,
					'message' => __( 'Template creato.', 'sd-logbook' ),
				)
			);
		}
	}

	// =========================================================================
	// AJAX: LISTA TEMPLATE
	// =========================================================================

	public function ajax_template_list() {
		check_ajax_referer( 'sd_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		global $wpdb;

		$type_filter = sanitize_key( $_POST['template_type'] ?? '' );
		$where       = '';
		$params      = array();

		if ( in_array( $type_filter, array( 'activity', 'member' ), true ) ) {
			$where  = ' WHERE template_type = %s';
			$params = array( $type_filter );
		}

		$sql  = 'SELECT id, name, orientation, activity_id, template_type, created_at, updated_at FROM ' . $wpdb->prefix . 'sd_pdf_templates' . $where . ' ORDER BY updated_at DESC';
		$rows = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		wp_send_json_success( array( 'templates' => $rows ?: array() ) );
	}

	// =========================================================================
	// AJAX: CARICA TEMPLATE
	// =========================================================================

	public function ajax_template_load() {
		check_ajax_referer( 'sd_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		global $wpdb;

		$template_id = intval( $_POST['template_id'] ?? 0 );
		if ( $template_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'ID template non valido.', 'sd-logbook' ) ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'sd_pdf_templates WHERE id = %d',
				$template_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Template non trovato.', 'sd-logbook' ) ) );
		}

		$row['elements'] = json_decode( $row['elements_json'], true ) ?: array();
		unset( $row['elements_json'] );

		wp_send_json_success( array( 'template' => $row ) );
	}

	// =========================================================================
	// AJAX: ELIMINA TEMPLATE
	// =========================================================================

	public function ajax_template_delete() {
		check_ajax_referer( 'sd_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		global $wpdb;

		$template_id = intval( $_POST['template_id'] ?? 0 );
		if ( $template_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'ID template non valido.', 'sd-logbook' ) ) );
		}

		$deleted = $wpdb->delete(
			$wpdb->prefix . 'sd_pdf_templates',
			array( 'id' => $template_id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Errore durante l\'eliminazione.', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Template eliminato.', 'sd-logbook' ) ) );
	}

	// =========================================================================
	// AJAX: CAMPI DINAMICI ATTIVITÀ
	// =========================================================================

	public function ajax_activity_fields() {
		check_ajax_referer( 'sd_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		if ( $activity_id <= 0 ) {
			wp_send_json_success( array( 'dynamic_fields' => array() ) );
			return;
		}

		$manager = SD_Activity_Manager::get_instance();
		$form_fields = $manager->get_form_fields( $activity_id );

		$dynamic = array();
		foreach ( $form_fields as $ff ) {
			$fname = sanitize_key( $ff['field_name'] ?? '' );
			if ( '' === $fname || 'content' === ( $ff['field_type'] ?? '' ) ) {
				continue;
			}
			$dynamic[ 'dyn_' . $fname ] = sanitize_text_field( $ff['field_label'] ?? $fname );
		}

		wp_send_json_success( array( 'dynamic_fields' => $dynamic ) );
	}

	// =========================================================================
	// AJAX: GENERA PDF (singola registrazione o anteprima)
	// =========================================================================

	public function ajax_generate_pdf() {
		check_ajax_referer( 'sd_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		$template_id     = intval( $_POST['template_id'] ?? 0 );
		$registration_id = intval( $_POST['registration_id'] ?? 0 );
		$activity_id     = intval( $_POST['activity_id'] ?? 0 );

		if ( $template_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'ID template non valido.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$tpl = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sd_pdf_templates WHERE id = %d', $template_id ),
			ARRAY_A
		);

		if ( ! $tpl ) {
			wp_send_json_error( array( 'message' => __( 'Template non trovato.', 'sd-logbook' ) ) );
		}

		$elements = json_decode( $tpl['elements_json'], true ) ?: array();
		$manager  = SD_Activity_Manager::get_instance();

		$activity = null;
		if ( $activity_id > 0 ) {
			$activity = $manager->get_activity( $activity_id );
		}

		$registration = null;
		if ( $registration_id > 0 ) {
			$registration = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sd_activity_registrations WHERE id = %d', $registration_id ),
				ARRAY_A
			);
			if ( $registration ) {
				$registration['registration_data'] = json_decode( $registration['registration_data'], true ) ?: array();
				if ( ! $activity ) {
					$activity = $manager->get_activity( intval( $registration['activity_id'] ) );
				}
			}
		}

		$html     = $this->build_pdf_html( $elements, $tpl['orientation'], $activity, $registration, 0 === $registration_id );
		$filename = 'template_' . $template_id . ( $registration_id > 0 ? '_reg_' . $registration_id : '_anteprima' ) . '_' . gmdate( 'Ymd_His' ) . '.pdf';

		$this->stream_pdf( $html, $filename, $tpl['orientation'] );
	}

	// =========================================================================
	// AJAX: GENERA PDF PER TUTTE LE REGISTRAZIONI
	// =========================================================================

	public function ajax_generate_pdf_all() {
		check_ajax_referer( 'sd_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		$template_id     = intval( $_POST['template_id'] ?? 0 );
		$activity_id     = intval( $_POST['activity_id'] ?? 0 );
		$payment_status  = sanitize_text_field( $_POST['payment_status'] ?? '' );

		if ( $template_id <= 0 || $activity_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Parametri non validi.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$tpl = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sd_pdf_templates WHERE id = %d', $template_id ),
			ARRAY_A
		);

		if ( ! $tpl ) {
			wp_send_json_error( array( 'message' => __( 'Template non trovato.', 'sd-logbook' ) ) );
		}

		$elements = json_decode( $tpl['elements_json'], true ) ?: array();
		$manager  = SD_Activity_Manager::get_instance();
		$activity = $manager->get_activity( $activity_id );

		$args = array(
			'per_page' => 9999,
			'page' => 1,
		);
		if ( ! empty( $payment_status ) ) {
			$args['payment_status'] = $payment_status;
		}
		$registrations = $manager->get_registrations( $activity_id, $args );

		if ( empty( $registrations ) ) {
			wp_send_json_error( array( 'message' => __( 'Nessuna iscrizione trovata.', 'sd-logbook' ) ) );
		}

		// Genera un PDF multi-pagina (ogni registrazione = una pagina)
		$all_html = '';
		foreach ( $registrations as $idx => $reg ) {
			$reg['registration_data'] = is_array( $reg['registration_data'] ) ? $reg['registration_data'] : ( json_decode( $reg['registration_data'], true ) ?: array() );
			$page_html = $this->build_page_content( $elements, $activity, $reg );
			if ( $idx > 0 ) {
				$all_html .= '<div style="page-break-before:always;"></div>';
			}
			$all_html .= $page_html;
		}

		$html     = $this->wrap_pdf_html( $all_html, $tpl['orientation'] );
		$filename = 'template_' . $template_id . '_attivita_' . $activity_id . '_' . gmdate( 'Ymd_His' ) . '.pdf';

		$this->stream_pdf( $html, $filename, $tpl['orientation'] );
	}

	// =========================================================================
	// HELPER: COSTRUISCE HTML PDF
	// =========================================================================

	private function build_pdf_html( $elements, $orientation, $activity, $registration, $is_preview = false ) {
		$content = $this->build_page_content( $elements, $activity, $registration, $is_preview );
		return $this->wrap_pdf_html( $content, $orientation );
	}

	private function build_page_content( $elements, $activity, $registration, $is_preview = false ) {
		// Sfondi (image con is_background) vengono renderizzati per primi
		usort(
			$elements,
			function ( $a, $b ) {
				$a_bg = ( 'image' === ( $a['type'] ?? '' ) ) && ! empty( $a['is_background'] );
				$b_bg = ( 'image' === ( $b['type'] ?? '' ) ) && ! empty( $b['is_background'] );
				return (int) $b_bg - (int) $a_bg;
			}
		);
		$html = '<div class="sd-pdf-page">';
		foreach ( $elements as $el ) {
			$type = sanitize_key( $el['type'] ?? '' );
			if ( 'image' === $type ) {
				$html .= $this->render_image_element( $el, $is_preview );
				continue;
			}
			$x              = floatval( $el['x'] ?? 0 );
			$y              = floatval( $el['y'] ?? 0 );
			$width          = floatval( $el['width'] ?? 60 );
			$height         = floatval( $el['height'] ?? 0 );
			$font_size      = intval( $el['font_size'] ?? 11 );
			$font_bold      = ! empty( $el['font_bold'] );
			$font_italic    = ! empty( $el['font_italic'] );
			$color          = sanitize_hex_color( $el['color'] ?? '' ) ?: '#000000';
			$prefix         = esc_html( $el['prefix'] ?? '' );
			$suffix         = esc_html( $el['suffix'] ?? '' );
			$label_show     = ! empty( $el['label_show'] );
			$label_text     = esc_html( $el['label'] ?? '' );
			$label_position = in_array( $el['label_position'] ?? 'above', array( 'above', 'below', 'before', 'after' ), true ) ? $el['label_position'] : 'above';

			$value = $this->resolve_field_value( $type, $el, $activity, $registration );

			$is_html = $this->field_is_html( $type );

			$style  = 'position:absolute;';
			$style .= 'left:' . $x . 'mm;';
			$style .= 'top:' . $y . 'mm;';
			$style .= 'width:' . $width . 'mm;';
			if ( $height > 0 ) {
				$style .= 'height:' . $height . 'mm;overflow:hidden;';
			}
			$style .= 'font-size:' . $font_size . 'pt;';
			$style .= 'color:' . $color . ';';
			if ( $is_html ) {
				$style .= 'word-wrap:break-word;';
			}
			if ( $font_bold ) {
				$style .= 'font-weight:bold;';
			}
			if ( $font_italic ) {
				$style .= 'font-style:italic;';
			}
			if ( $is_preview ) {
				$style .= 'border:1px dashed #bbb;box-sizing:border-box;padding:1px 2px;';
			}

			$lbl_style = 'font-size:' . max( 7, $font_size - 2 ) . 'pt;opacity:0.6;';
			if ( $is_html ) {
				$val_text = ( $prefix ? esc_html( $prefix ) : '' ) . wp_kses_post( $value ) . ( $suffix ? esc_html( $suffix ) : '' );
			} else {
				$val_text = esc_html( $prefix . $value . $suffix );
			}
			$lbl_span  = '<span style="' . $lbl_style . '">' . $label_text . '</span>';

			if ( ! $label_show || '' === $label_text ) {
				$inner = $val_text;
			} elseif ( 'above' === $label_position ) {
				$inner = $lbl_span . '<br>' . $val_text;
			} elseif ( 'below' === $label_position ) {
				$inner = $val_text . '<br>' . $lbl_span;
			} elseif ( 'before' === $label_position ) {
				$inner = '<span style="' . $lbl_style . '">' . $label_text . ': </span>' . $val_text;
			} elseif ( 'after' === $label_position ) {
				$inner = $val_text . '<span style="' . $lbl_style . '"> ' . $label_text . '</span>';
			} else {
				$inner = $val_text;
			}

			$html .= '<div style="' . $style . '">' . $inner . '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	private function wrap_pdf_html( $content, $orientation ) {
		$is_portrait = 'portrait' === $orientation;
		$page_w = $is_portrait ? '210mm' : '297mm';
		$page_h = $is_portrait ? '297mm' : '210mm';
		$paper  = $is_portrait ? '@page { size: A4 portrait; margin: 0; }' : '@page { size: A4 landscape; margin: 0; }';

		return '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
' . $paper . '
* { box-sizing: border-box; margin: 0; padding: 0; font-family: DejaVu Sans, Arial, sans-serif; }
body { width: ' . $page_w . '; height: ' . $page_h . '; }
.sd-pdf-page { position: relative; width: ' . $page_w . '; height: ' . $page_h . '; overflow: hidden; }
</style>
</head><body>' . $content . '</body></html>';
	}

	// =========================================================================
	// HELPER: RISOLVE VALORE CAMPO
	// =========================================================================

	private function resolve_field_value( $type, $el, $activity, $registration, $member = null ) {
		// Testo libero
		if ( 'text_label' === $type ) {
			return esc_html( $el['custom_text'] ?? '' );
		}

		// Campi socio (mbr_*)
		if ( 0 === strpos( $type, 'mbr_' ) ) {
			if ( ! $member ) {
				return '—';
			}
			$member = is_object( $member ) ? (array) $member : $member;
			$key    = substr( $type, 4 );
			switch ( $key ) {
				case 'date_of_birth':
					$raw = $member['date_of_birth'] ?? '';
					return $raw ? date_i18n( 'd.m.Y', strtotime( $raw ) ) : '';
				case 'membership_expiry':
					$raw = $member['membership_expiry'] ?? '';
					return $raw ? date_i18n( 'd.m.Y', strtotime( $raw ) ) : '';
				case 'fee_amount':
					return ! empty( $member['fee_amount'] ) ? 'CHF ' . number_format( (float) $member['fee_amount'], 2, '.', '\'' ) : '';
				case 'is_scuba':
					return ! empty( $member['is_scuba'] ) ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' );
				case 'privacy_consent':
					return ! empty( $member['privacy_consent'] ) ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' );
				case 'gender':
					return esc_html( $member['gender'] ?? '' );
				default:
					return esc_html( (string) ( $member[ $key ] ?? '' ) );
			}
		}

		// Campi attività
		if ( 0 === strpos( $type, 'act_' ) ) {
			if ( ! $activity ) {
				return '';
			}
			$key = substr( $type, 4 );
			$val = $activity[ $key ] ?? '';
			if ( in_array( $key, array( 'start_date', 'end_date' ), true ) && ! empty( $val ) ) {
				$val = date_i18n( 'd.m.Y', strtotime( $val ) );
			}
			return $val;
		}

		// Campi registrazione fissi
		if ( 0 === strpos( $type, 'reg_' ) ) {
			if ( ! $registration ) {
				return '—';
			}
			$key = substr( $type, 4 );
			switch ( $key ) {
				case 'birth_date':
					$raw = $registration['registration_data']['birth_date'] ?? '';
					$val = $raw ? date_i18n( 'd.m.Y', strtotime( $raw ) ) : '';
					break;
				case 'price_chf':
					$val = number_format( floatval( $registration['price_chf'] ?? 0 ), 2, '.', '\'' ) . ' CHF';
					break;
				case 'price_eur':
					$val = number_format( floatval( $registration['price_eur'] ?? 0 ), 2, '.', '\'' ) . ' EUR';
					break;
				case 'created_at':
				case 'payment_date':
					$raw = $registration[ $key ] ?? '';
					$val = $raw ? date_i18n( 'd.m.Y', strtotime( $raw ) ) : '';
					break;
				default:
					$val = $registration[ $key ] ?? '';
					break;
			}
			return $val;
		}

		// Campi dinamici del modulo
		if ( 0 === strpos( $type, 'dyn_' ) ) {
			if ( ! $registration ) {
				return '—';
			}
			$key = substr( $type, 4 );
			$rd  = is_array( $registration['registration_data'] ) ? $registration['registration_data'] : array();
			$val = $rd[ $key ] ?? '';
			// Fallback a campo top-level per campi standard (first_name, last_name, email…)
			if ( '' === (string) $val && array_key_exists( $key, $registration ) ) {
				$val = $registration[ $key ] ?? '';
			}
			// Formattazione date
			if ( in_array( $key, array( 'birth_date' ), true ) && ! empty( $val ) ) {
				$val = date_i18n( 'd.m.Y', strtotime( $val ) );
			}
			if ( is_array( $val ) ) {
				$val = implode( ', ', $val );
			}
			return $val;
		}

		return '';
	}

	// =========================================================================
	// AJAX: GENERA PDF (singolo socio o anteprima socio)
	// =========================================================================

	public function ajax_generate_member_pdf() {
		check_ajax_referer( 'sd_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		$template_id = intval( $_POST['template_id'] ?? 0 );
		$member_id   = intval( $_POST['member_id'] ?? 0 );

		if ( $template_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'ID template non valido.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$tpl = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sd_pdf_templates WHERE id = %d', $template_id ),
			ARRAY_A
		);

		if ( ! $tpl ) {
			wp_send_json_error( array( 'message' => __( 'Template non trovato.', 'sd-logbook' ) ) );
		}

		$elements = json_decode( $tpl['elements_json'], true ) ?: array();

		$member = null;
		if ( $member_id > 0 ) {
			$db     = new SD_Database();
			$member = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . $db->table( 'members' ) . ' WHERE id = %d', $member_id ),
				ARRAY_A
			);
		}

		$html     = $this->build_member_pdf_html( $elements, $tpl['orientation'], $member, 0 === $member_id );
		$filename = 'socio_' . ( $member_id > 0 ? $member_id : 'anteprima' ) . '_tpl' . $template_id . '_' . gmdate( 'Ymd_His' ) . '.pdf';

		$this->stream_pdf( $html, $filename, $tpl['orientation'] );
	}

	// =========================================================================
	// AJAX: GENERA PDF PER TUTTI I SOCI
	// =========================================================================

	public function ajax_generate_member_pdf_all() {
		check_ajax_referer( 'sd_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'sd-logbook' ) ) );
		}

		$template_id = intval( $_POST['template_id'] ?? 0 );
		$filter_type = sanitize_key( $_POST['filter_type'] ?? 'all' );

		if ( $template_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'ID template non valido.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$tpl = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sd_pdf_templates WHERE id = %d', $template_id ),
			ARRAY_A
		);

		if ( ! $tpl ) {
			wp_send_json_error( array( 'message' => __( 'Template non trovato.', 'sd-logbook' ) ) );
		}

		$db    = new SD_Database();
		$today = gmdate( 'Y-m-d' );
		$soon  = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

		$where = 'WHERE COALESCE(is_active,1) = 1';
		$extra_params = array();

		if ( 'in_scadenza' === $filter_type ) {
			$where       .= ' AND membership_expiry BETWEEN %s AND %s';
			$extra_params = array( $today, $soon );
		} elseif ( 'scaduti' === $filter_type ) {
			$where       .= ' AND membership_expiry < %s';
			$extra_params = array( $today );
		} elseif ( 'non_pagati' === $filter_type ) {
			$where .= ' AND has_paid_fee = 0';
		}

		$sql     = 'SELECT * FROM ' . $db->table( 'members' ) . ' ' . $where . ' ORDER BY last_name ASC, first_name ASC';
		$members = empty( $extra_params )
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $wpdb->prepare( $sql, $extra_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $members ) ) {
			wp_send_json_error( array( 'message' => __( 'Nessun socio trovato.', 'sd-logbook' ) ) );
		}

		$elements = json_decode( $tpl['elements_json'], true ) ?: array();
		$all_html = '';

		foreach ( $members as $idx => $member ) {
			$page_html = $this->build_member_page_content( $elements, $member );
			if ( $idx > 0 ) {
				$all_html .= '<div style="page-break-before:always;"></div>';
			}
			$all_html .= $page_html;
		}

		$html     = $this->wrap_pdf_html( $all_html, $tpl['orientation'] );
		$filename = 'soci_tpl' . $template_id . '_' . gmdate( 'Ymd_His' ) . '.pdf';

		$this->stream_pdf( $html, $filename, $tpl['orientation'] );
	}

	// =========================================================================
	// HELPER: GENERA PDF SOCIO COME STRINGA (per allegati email)
	// =========================================================================

	public function generate_member_pdf_string( $template_id, $member_data ) {
		global $wpdb;

		$tpl = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sd_pdf_templates WHERE id = %d', $template_id ),
			ARRAY_A
		);

		if ( ! $tpl ) {
			return false;
		}

		$member_arr = is_object( $member_data ) ? (array) $member_data : (array) $member_data;
		$elements   = json_decode( $tpl['elements_json'], true ) ?: array();
		$html       = $this->build_member_pdf_html( $elements, $tpl['orientation'], $member_arr, false );

		return $this->generate_pdf_string( $html, $tpl['orientation'] );
	}

	// =========================================================================
	// HELPER: COSTRUISCE HTML PDF SOCIO
	// =========================================================================

	private function build_member_pdf_html( $elements, $orientation, $member, $is_preview = false ) {
		$content = $this->build_member_page_content( $elements, $member, $is_preview );
		return $this->wrap_pdf_html( $content, $orientation );
	}

	private function build_member_page_content( $elements, $member, $is_preview = false ) {
		usort(
			$elements,
			function ( $a, $b ) {
				$a_bg = ( 'image' === ( $a['type'] ?? '' ) ) && ! empty( $a['is_background'] );
				$b_bg = ( 'image' === ( $b['type'] ?? '' ) ) && ! empty( $b['is_background'] );
				return (int) $b_bg - (int) $a_bg;
			}
		);

		$html = '<div class="sd-pdf-page">';
		foreach ( $elements as $el ) {
			$type = sanitize_key( $el['type'] ?? '' );
			if ( 'image' === $type ) {
				$html .= $this->render_image_element( $el, $is_preview );
				continue;
			}

			$x              = floatval( $el['x'] ?? 0 );
			$y              = floatval( $el['y'] ?? 0 );
			$width          = floatval( $el['width'] ?? 60 );
			$height         = floatval( $el['height'] ?? 0 );
			$font_size      = intval( $el['font_size'] ?? 11 );
			$font_bold      = ! empty( $el['font_bold'] );
			$font_italic    = ! empty( $el['font_italic'] );
			$color          = sanitize_hex_color( $el['color'] ?? '' ) ?: '#000000';
			$prefix         = esc_html( $el['prefix'] ?? '' );
			$suffix         = esc_html( $el['suffix'] ?? '' );
			$label_show     = ! empty( $el['label_show'] );
			$label_text     = esc_html( $el['label'] ?? '' );
			$label_position = in_array( $el['label_position'] ?? 'above', array( 'above', 'below', 'before', 'after' ), true ) ? $el['label_position'] : 'above';

			$value = $this->resolve_field_value( $type, $el, null, null, $member );

			$style  = 'position:absolute;';
			$style .= 'left:' . $x . 'mm;top:' . $y . 'mm;width:' . $width . 'mm;';
			if ( $height > 0 ) {
				$style .= 'height:' . $height . 'mm;overflow:hidden;';
			}
			$style .= 'font-size:' . $font_size . 'pt;color:' . $color . ';';
			if ( $font_bold )   { $style .= 'font-weight:bold;'; }
			if ( $font_italic ) { $style .= 'font-style:italic;'; }
			if ( $is_preview )  { $style .= 'border:1px dashed #bbb;box-sizing:border-box;padding:1px 2px;'; }

			$lbl_style = 'font-size:' . max( 7, $font_size - 2 ) . 'pt;opacity:0.6;';
			$val_text  = esc_html( $prefix . $value . $suffix );
			$lbl_span  = '<span style="' . $lbl_style . '">' . $label_text . '</span>';

			if ( ! $label_show || '' === $label_text ) {
				$inner = $val_text;
			} elseif ( 'above' === $label_position ) {
				$inner = $lbl_span . '<br>' . $val_text;
			} elseif ( 'below' === $label_position ) {
				$inner = $val_text . '<br>' . $lbl_span;
			} elseif ( 'before' === $label_position ) {
				$inner = '<span style="' . $lbl_style . '">' . $label_text . ': </span>' . $val_text;
			} else {
				$inner = $val_text . '<span style="' . $lbl_style . '"> ' . $label_text . '</span>';
			}

			$html .= '<div style="' . $style . '">' . $inner . '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	// =========================================================================
	// HELPER: GENERA PDF COME STRINGA (senza streaming)
	// =========================================================================

	private function generate_pdf_string( $html, $orientation ) {
		if ( ! class_exists( '\\Dompdf\\Dompdf' ) ) {
			$autoload = plugin_dir_path( __DIR__ ) . 'vendor/autoload.php';
			if ( file_exists( $autoload ) ) {
				require_once $autoload;
			}
		}

		$options = new \Dompdf\Options();
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'chroot', array( ABSPATH, WP_CONTENT_DIR ) );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', ( 'landscape' === $orientation ) ? 'landscape' : 'portrait' );
		$dompdf->render();

		return $dompdf->output();
	}

	// =========================================================================
	// HELPER: STREAMING PDF
	// =========================================================================

	private function stream_pdf( $html, $filename, $orientation ) {
		if ( ! class_exists( '\\Dompdf\\Dompdf' ) ) {
			$autoload = plugin_dir_path( __DIR__ ) . 'vendor/autoload.php';
			if ( file_exists( $autoload ) ) {
				require_once $autoload;
			}
		}

		$options = new \Dompdf\Options();
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'chroot', array( ABSPATH, WP_CONTENT_DIR ) );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html );

		$paper_orientation = ( 'landscape' === $orientation ) ? 'landscape' : 'portrait';
		$dompdf->setPaper( 'A4', $paper_orientation );
		$dompdf->render();

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: max-age=0' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $dompdf->output();
		exit;
	}

	// =========================================================================
	// HELPER: IL CAMPO CONTIENE HTML (non va escaped con esc_html)
	// =========================================================================

	private function field_is_html( $type ) {
		return in_array( $type, array( 'act_description' ), true );
	}

	// =========================================================================
	// HELPER: SANIFICA ELEMENTO TEMPLATE
	// =========================================================================

	private function sanitize_element( $el ) {
		if ( ! is_array( $el ) ) {
			return array();
		}
		$type = sanitize_key( $el['type'] ?? '' );
		$base = array(
			'id'             => sanitize_key( $el['id'] ?? '' ),
			'type'           => $type,
			'label'          => sanitize_text_field( $el['label'] ?? '' ),
			'x'              => round( floatval( $el['x'] ?? 0 ), 2 ),
			'y'              => round( floatval( $el['y'] ?? 0 ), 2 ),
			'width'          => round( floatval( $el['width'] ?? 60 ), 2 ),
			'font_size'      => max( 6, min( 72, intval( $el['font_size'] ?? 11 ) ) ),
			'font_bold'      => ! empty( $el['font_bold'] ),
			'font_italic'    => ! empty( $el['font_italic'] ),
			'color'          => sanitize_hex_color( $el['color'] ?? '' ) ?: '#000000',
			'prefix'         => sanitize_text_field( $el['prefix'] ?? '' ),
			'suffix'         => sanitize_text_field( $el['suffix'] ?? '' ),
			'label_show'     => ! empty( $el['label_show'] ),
			'label_position' => in_array( $el['label_position'] ?? 'above', array( 'above', 'below', 'before', 'after' ), true ) ? sanitize_key( $el['label_position'] ) : 'above',
			'custom_text'    => sanitize_text_field( $el['custom_text'] ?? '' ),
			'height'         => round( max( 0.0, floatval( $el['height'] ?? 0 ) ), 2 ),
		);
		if ( 'image' === $type ) {
			$base['url']           = esc_url_raw( $el['url'] ?? '' );
			$base['attachment_id'] = max( 0, intval( $el['attachment_id'] ?? 0 ) );
			$base['height']        = round( max( 1.0, floatval( $el['height'] ?? 40 ) ), 2 );
			$base['rotation']      = intval( $el['rotation'] ?? 0 ) % 360;
			$base['flip_h']        = ! empty( $el['flip_h'] );
			$base['flip_v']        = ! empty( $el['flip_v'] );
			$base['opacity']       = round( max( 0.05, min( 1.0, floatval( $el['opacity'] ?? 1.0 ) ) ), 2 );
			$base['is_background'] = ! empty( $el['is_background'] );
		}
		return $base;
	}

	// =========================================================================
	// HELPER: RENDERIZZA ELEMENTO IMMAGINE
	// =========================================================================

	private function render_image_element( $el, $is_preview = false ) {
		$x         = floatval( $el['x'] ?? 0 );
		$y         = floatval( $el['y'] ?? 0 );
		$width     = floatval( $el['width'] ?? 60 );
		$height    = floatval( $el['height'] ?? 40 );
		$rotation  = intval( $el['rotation'] ?? 0 );
		$flip_h    = ! empty( $el['flip_h'] );
		$flip_v    = ! empty( $el['flip_v'] );
		$opacity   = round( max( 0.05, min( 1.0, floatval( $el['opacity'] ?? 1.0 ) ) ), 2 );
		$attach_id = intval( $el['attachment_id'] ?? 0 );

		// Preferisce il percorso fisico del file (dompdf non necessita HTTP)
		$img_src = '';
		if ( $attach_id > 0 ) {
			$file = get_attached_file( $attach_id );
			if ( $file && file_exists( $file ) ) {
				$img_src = $file;
			}
		}
		if ( empty( $img_src ) ) {
			$img_src = esc_attr( $el['url'] ?? '' );
		}

		$style  = 'position:absolute;';
		$style .= 'left:' . $x . 'mm;top:' . $y . 'mm;';
		$style .= 'width:' . $width . 'mm;height:' . $height . 'mm;';
		$style .= 'opacity:' . $opacity . ';overflow:hidden;';

		$transforms = array();
		if ( 0 !== $rotation ) {
			$transforms[] = 'rotate(' . $rotation . 'deg)';
		}
		if ( $flip_h ) {
			$transforms[] = 'scaleX(-1)';
		}
		if ( $flip_v ) {
			$transforms[] = 'scaleY(-1)';
		}
		if ( ! empty( $transforms ) ) {
			$style .= 'transform:' . implode( ' ', $transforms ) . ';';
			$style .= 'transform-origin:center center;';
		}

		if ( $is_preview ) {
			$style .= 'border:1px dashed #bbb;box-sizing:border-box;';
		}

		if ( empty( $img_src ) ) {
			return '<div style="' . $style . 'background:#f0f0f0;"></div>';
		}

		$img_style = 'width:100%;height:100%;display:block;';
		return '<div style="' . $style . '"><img src="' . esc_attr( $img_src ) . '" style="' . $img_style . '" alt=""></div>';
	}
}
