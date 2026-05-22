<?php
/**
 * Generazione PDF per attività e registrazioni - ScubaDiabetes Logbook
 *
 * Fornisce tre tipi di PDF:
 *  - Scheda attività (singola)
 *  - Lista registrazioni (tutte o filtrate per payment_status/search)
 *  - Scheda singola registrazione
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Activity_PDF {

	public function __construct() {
		$this->init_hooks();
	}

	public function init_hooks() {
		add_action( 'wp_ajax_sd_activity_pdf_activity',             array( $this, 'ajax_pdf_activity' ) );
		add_action( 'wp_ajax_sd_activity_pdf_registrations',        array( $this, 'ajax_pdf_registrations' ) );
		add_action( 'wp_ajax_sd_activity_pdf_single_registration',  array( $this, 'ajax_pdf_single_registration' ) );
	}

	// ── helpers ──────────────────────────────────────────────────────────────

	private function check_permissions() {
		check_ajax_referer( 'sd_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti', 'sd-logbook' ) ) );
		}
	}

	private function get_manager() {
		if ( class_exists( 'SD_Activity_Manager' ) ) {
			return SD_Activity_Manager::get_instance();
		}
		return null;
	}

	private function stream_pdf( $html, $filename, $orientation = 'portrait' ) {
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			wp_send_json_error( array( 'message' => __( 'Libreria PDF non disponibile (dompdf mancante).', 'sd-logbook' ) ) );
		}

		$options = new \Dompdf\Options();
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isHtml5ParserEnabled', true );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', $orientation );
		$dompdf->render();

		$output = $dompdf->output();

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . strlen( $output ) );
			header( 'Cache-Control: private, max-age=0, must-revalidate' );
			header( 'Pragma: public' );
		}

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private function pdf_header_html( $title, $subtitle = '' ) {
		$logo_path = SD_LOGBOOK_PLUGIN_DIR . 'assets/images/logo.png';
		$logo_html = '';
		if ( file_exists( $logo_path ) ) {
			$logo_data = base64_encode( file_get_contents( $logo_path ) ); // phpcs:ignore
			$logo_html = '<img src="data:image/png;base64,' . $logo_data . '" class="logo" alt="ScubaDiabetes">';
		}

		$date_str = date_i18n( 'd/m/Y H:i' );

		return '
		<div class="pdf-header">
			' . $logo_html . '
			<div class="pdf-header-text">
				<h1>' . esc_html( $title ) . '</h1>
				' . ( $subtitle ? '<p class="subtitle">' . esc_html( $subtitle ) . '</p>' : '' ) . '
			</div>
			<div class="pdf-meta">Generato il ' . esc_html( $date_str ) . '</div>
		</div>
		<hr class="divider">';
	}

	private function base_css() {
		return '
		<style>
			* { box-sizing: border-box; margin: 0; padding: 0; }
			body {
				font-family: "DejaVu Sans", sans-serif;
				font-size: 10px;
				color: #1a1a2e;
				line-height: 1.4;
			}
			.pdf-header {
				display: block;
				margin-bottom: 14px;
			}
			.logo {
				height: 40px;
				float: left;
				margin-right: 12px;
			}
			.pdf-header-text {
				display: inline-block;
			}
			h1 {
				font-size: 16px;
				color: #0a4f8f;
				margin-bottom: 2px;
			}
			.subtitle {
				font-size: 9px;
				color: #555;
			}
			.pdf-meta {
				text-align: right;
				font-size: 8px;
				color: #888;
				clear: both;
				margin-top: 4px;
			}
			hr.divider {
				border: none;
				border-top: 2px solid #0a4f8f;
				margin: 8px 0 12px 0;
			}
			h2 {
				font-size: 13px;
				color: #0a4f8f;
				margin: 14px 0 6px 0;
				border-left: 4px solid #0a4f8f;
				padding-left: 6px;
			}
			h3 {
				font-size: 11px;
				color: #0a4f8f;
				margin: 10px 0 4px 0;
			}
			.info-grid {
				width: 100%;
				margin-bottom: 10px;
			}
			.info-grid td {
				padding: 3px 6px 3px 0;
				vertical-align: top;
				font-size: 9.5px;
			}
			.info-grid .label {
				font-weight: bold;
				color: #444;
				width: 130px;
				white-space: nowrap;
			}
			.info-grid .value {
				color: #1a1a2e;
			}
			table.data-table {
				width: 100%;
				border-collapse: collapse;
				margin-top: 6px;
				font-size: 8.5px;
			}
			table.data-table th {
				background-color: #0a4f8f;
				color: #fff;
				padding: 5px 4px;
				text-align: left;
				font-size: 8px;
				font-weight: bold;
			}
			table.data-table td {
				padding: 4px;
				border-bottom: 1px solid #e0e0e0;
				vertical-align: top;
			}
			table.data-table tr:nth-child(even) td {
				background-color: #f5f8ff;
			}
			.badge {
				display: inline-block;
				padding: 1px 5px;
				border-radius: 3px;
				font-size: 7.5px;
				font-weight: bold;
				color: #fff;
			}
			.badge-registered  { background-color: #2ecc71; }
			.badge-pending     { background-color: #f39c12; }
			.badge-cancelled   { background-color: #e74c3c; }
			.badge-paid        { background-color: #27ae60; }
			.badge-invoice_sent { background-color: #3498db; }
			.badge-unpaid      { background-color: #e67e22; }
			.badge-free        { background-color: #95a5a6; }
			.field-block {
				margin-bottom: 8px;
			}
			.field-label {
				font-weight: bold;
				color: #444;
				font-size: 9px;
				margin-bottom: 2px;
			}
			.field-value {
				background-color: #f7f9fc;
				border: 1px solid #ddd;
				padding: 3px 6px;
				border-radius: 3px;
				font-size: 9.5px;
			}
			.section-box {
				border: 1px solid #d0d8e8;
				border-radius: 4px;
				padding: 8px 10px;
				margin-bottom: 10px;
				background: #fafbff;
			}
			.two-col {
				width: 100%;
			}
			.two-col td {
				width: 50%;
				vertical-align: top;
				padding-right: 10px;
			}
			.page-break { page-break-after: always; }
			.footer {
				position: fixed;
				bottom: 0;
				width: 100%;
				font-size: 7.5px;
				color: #aaa;
				text-align: center;
				border-top: 1px solid #eee;
				padding-top: 3px;
			}
		</style>';
	}

	private function status_label( $status ) {
		$map = array(
			'registered' => 'Registrato',
			'pending'    => 'In attesa',
			'cancelled'  => 'Annullato',
		);
		return $map[ $status ] ?? ucfirst( $status );
	}

	private function payment_label( $status ) {
		$map = array(
			'paid'         => 'Pagato',
			'unpaid'       => 'Non pagato',
			'pending'      => 'In attesa',
			'invoice_sent' => 'Fattura inviata',
			'free'         => 'Gratuito',
		);
		return $map[ $status ] ?? ucfirst( $status );
	}

	private function status_badge( $status ) {
		$label = $this->status_label( $status );
		return '<span class="badge badge-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	private function payment_badge( $status ) {
		$label = $this->payment_label( $status );
		$css   = 'badge-' . str_replace( '_', '-', $status );
		return '<span class="badge ' . esc_attr( $css ) . '">' . esc_html( $label ) . '</span>';
	}

	// ── PDF: scheda attività ─────────────────────────────────────────────────

	public function ajax_pdf_activity() {
		$this->check_permissions();

		$activity_id = intval( $_POST['activity_id'] ?? 0 );
		if ( $activity_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'ID attività non valido', 'sd-logbook' ) ) );
		}

		$manager  = $this->get_manager();
		$activity = $manager ? $manager->get_activity( $activity_id ) : null;
		if ( ! $activity ) {
			wp_send_json_error( array( 'message' => __( 'Attività non trovata', 'sd-logbook' ) ) );
		}

		$html = $this->build_html_activity( $activity );
		$filename = 'attivita_' . $activity_id . '_' . gmdate( 'Ymd' ) . '.pdf';
		$this->stream_pdf( $html, $filename );
	}

	private function build_html_activity( $activity ) {
		$start  = ! empty( $activity['start_date'] ) ? date_i18n( 'd/m/Y', strtotime( $activity['start_date'] ) ) : '-';
		$end    = ! empty( $activity['end_date'] )   ? date_i18n( 'd/m/Y', strtotime( $activity['end_date'] ) )   : '-';
		$status_labels = array(
			'draft'     => 'Bozza',
			'published' => 'Pubblicata',
			'closed'    => 'Chiusa',
			'archived'  => 'Archiviata',
		);
		$status_str = $status_labels[ $activity['event_status'] ?? '' ] ?? ( $activity['event_status'] ?? '-' );

		$html = $this->base_css();
		$html .= '<body>';
		$html .= $this->pdf_header_html( $activity['title'], 'Scheda Attività' );

		// Info principali
		$html .= '<h2>Dettagli Attività</h2>';
		$html .= '<table class="info-grid"><tbody>';
		$info = array(
			'ID'           => '#' . $activity['id'],
			'Titolo'       => $activity['title'],
			'Data inizio'  => $start,
			'Data fine'    => $end,
			'Luogo'        => $activity['location'] ?: '-',
			'Stato'        => $status_str,
			'Max iscritti' => $activity['max_participants'] ? intval( $activity['max_participants'] ) : 'Illimitato',
			'Iscrizioni'   => intval( $activity['registrations_count'] ?? 0 ),
		);
		foreach ( $info as $label => $value ) {
			$html .= '<tr>';
			$html .= '<td class="label">' . esc_html( $label ) . ':</td>';
			$html .= '<td class="value">' . esc_html( $value ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		// Descrizione
		if ( ! empty( $activity['description'] ) ) {
			$html .= '<h2>Descrizione</h2>';
			$html .= '<div class="section-box">' . nl2br( esc_html( wp_strip_all_tags( $activity['description'] ) ) ) . '</div>';
		}

		// Tariffe
		$prices = $activity['prices'] ?? array();
		if ( ! empty( $prices ) ) {
			$html .= '<h2>Tariffe</h2>';
			$html .= '<table class="data-table"><thead><tr>';
			$html .= '<th>Nome</th><th>CHF</th><th>EUR</th><th>Note</th><th>Predefinita</th>';
			$html .= '</tr></thead><tbody>';
			foreach ( $prices as $p ) {
				$html .= '<tr>';
				$html .= '<td>' . esc_html( $p['price_name'] ?? '' ) . '</td>';
				$html .= '<td>' . number_format( floatval( $p['price_chf'] ?? 0 ), 2, '.', "'" ) . '</td>';
				$html .= '<td>' . number_format( floatval( $p['price_eur'] ?? 0 ), 2, '.', "'" ) . '</td>';
				$html .= '<td>' . esc_html( $p['rate_note'] ?? '' ) . '</td>';
				$html .= '<td>' . ( ! empty( $p['is_default'] ) ? '✓' : '' ) . '</td>';
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';
		}

		// Campi modulo
		$fields = $activity['form_fields'] ?? array();
		if ( ! empty( $fields ) ) {
			$html .= '<h2>Campi Modulo Iscrizione</h2>';
			$html .= '<table class="data-table"><thead><tr>';
			$html .= '<th>#</th><th>Nome Campo</th><th>Tipo</th><th>Etichetta</th><th>Obbligatorio</th>';
			$html .= '</tr></thead><tbody>';
			$i = 1;
			foreach ( $fields as $f ) {
				if ( 'content' === ( $f['field_type'] ?? '' ) ) {
					continue;
				}
				$html .= '<tr>';
				$html .= '<td>' . $i++ . '</td>';
				$html .= '<td>' . esc_html( $f['field_name'] ?? '' ) . '</td>';
				$html .= '<td>' . esc_html( $f['field_type'] ?? '' ) . '</td>';
				$html .= '<td>' . esc_html( $f['field_label'] ?? '' ) . '</td>';
				$html .= '<td>' . ( ! empty( $f['required'] ) ? '✓' : '' ) . '</td>';
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';
		}

		$html .= '<div class="footer">ScubaDiabetes – Attività #' . intval( $activity['id'] ) . ' – ' . esc_html( $activity['title'] ) . '</div>';
		$html .= '</body>';
		return $html;
	}

	// ── PDF: lista registrazioni ─────────────────────────────────────────────

	public function ajax_pdf_registrations() {
		$this->check_permissions();

		$activity_id    = intval( $_POST['activity_id'] ?? 0 );
		$payment_status = sanitize_text_field( $_POST['payment_status'] ?? '' );
		$search         = sanitize_text_field( $_POST['search'] ?? '' );

		if ( $activity_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'ID attività non valido', 'sd-logbook' ) ) );
		}

		$manager  = $this->get_manager();
		$activity = $manager ? $manager->get_activity( $activity_id ) : null;
		if ( ! $activity ) {
			wp_send_json_error( array( 'message' => __( 'Attività non trovata', 'sd-logbook' ) ) );
		}

		$args = array(
			'per_page'       => 9999,
			'page'           => 1,
			'payment_status' => $payment_status,
			'search'         => $search,
		);
		$registrations = $manager->get_registrations( $activity_id, $args );

		$html     = $this->build_html_registrations( $activity, $registrations, $payment_status, $search );
		$filename = 'registrazioni_attivita_' . $activity_id . '_' . gmdate( 'Ymd' ) . '.pdf';
		$this->stream_pdf( $html, $filename, 'landscape' );
	}

	private function build_html_registrations( $activity, $registrations, $filter_payment, $filter_search ) {
		$subtitle = 'Lista Registrazioni – ' . $activity['title'];
		if ( $filter_payment ) {
			$subtitle .= ' [filtro: ' . $this->payment_label( $filter_payment ) . ']';
		}
		if ( $filter_search ) {
			$subtitle .= ' [cerca: ' . $filter_search . ']';
		}

		// Campi dinamici
		$manager     = $this->get_manager();
		$form_fields = $manager ? $manager->get_form_fields( $activity['id'] ) : array();
		$skip_names  = array( 'first_name', 'last_name', 'email', 'birth_date' );
		$dyn_fields  = array();
		foreach ( $form_fields as $ff ) {
			$fname = $ff['field_name'] ?? '';
			if ( '' === $fname || in_array( $fname, $skip_names, true ) || 'content' === ( $ff['field_type'] ?? '' ) ) {
				continue;
			}
			$dyn_fields[ $fname ] = $ff['field_label'] ?? $fname;
		}

		$html = $this->base_css();
		$html .= '<body>';
		$html .= $this->pdf_header_html( 'Registrazioni Attività', $subtitle );

		$html .= '<p style="font-size:9px;margin-bottom:8px;">Totale: <strong>' . count( $registrations ) . '</strong> registrazioni</p>';

		if ( empty( $registrations ) ) {
			$html .= '<p style="color:#888;font-style:italic;">Nessuna registrazione trovata.</p>';
		} else {
			$html .= '<table class="data-table"><thead><tr>';
			$html .= '<th>#</th><th>Nome</th><th>Cognome</th><th>Email</th><th>Data nasc.</th>';
			$html .= '<th>Stato</th><th>Pagamento</th><th>CHF</th><th>EUR</th><th>N. Fattura</th><th>Data iscr.</th>';
			foreach ( $dyn_fields as $label ) {
				$html .= '<th>' . esc_html( $label ) . '</th>';
			}
			$html .= '</tr></thead><tbody>';

			$i = 1;
			foreach ( $registrations as $reg ) {
				$rd = is_array( $reg['registration_data'] ) ? $reg['registration_data'] : array();
				$html .= '<tr>';
				$html .= '<td>' . $i++ . '</td>';
				$html .= '<td>' . esc_html( $reg['first_name'] ?? '' ) . '</td>';
				$html .= '<td>' . esc_html( $reg['last_name'] ?? '' ) . '</td>';
				$html .= '<td>' . esc_html( $reg['email'] ?? '' ) . '</td>';
				$html .= '<td>' . esc_html( $rd['birth_date'] ?? '' ) . '</td>';
				$html .= '<td>' . $this->status_badge( $reg['status'] ?? '' ) . '</td>';
				$html .= '<td>' . $this->payment_badge( $reg['payment_status'] ?? '' ) . '</td>';
				$html .= '<td>' . number_format( floatval( $reg['price_chf'] ?? 0 ), 2, '.', "'" ) . '</td>';
				$html .= '<td>' . number_format( floatval( $reg['price_eur'] ?? 0 ), 2, '.', "'" ) . '</td>';
				$html .= '<td>' . esc_html( $reg['invoice_number'] ?? '' ) . '</td>';
				$created = ! empty( $reg['created_at'] ) ? date_i18n( 'd/m/Y', strtotime( $reg['created_at'] ) ) : '-';
				$html .= '<td>' . esc_html( $created ) . '</td>';
				foreach ( array_keys( $dyn_fields ) as $fname ) {
					$val = $rd[ $fname ] ?? '';
					if ( is_array( $val ) ) {
						$val = implode( ', ', $val );
					}
					$html .= '<td>' . esc_html( (string) $val ) . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';
		}

		$html .= '<div class="footer">ScubaDiabetes – Attività #' . intval( $activity['id'] ) . ' – ' . esc_html( $activity['title'] ) . '</div>';
		$html .= '</body>';
		return $html;
	}

	// ── PDF: singola registrazione ───────────────────────────────────────────

	public function ajax_pdf_single_registration() {
		$this->check_permissions();

		$registration_id = intval( $_POST['registration_id'] ?? 0 );
		if ( $registration_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'ID registrazione non valido', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$reg = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'sd_activity_registrations WHERE id = %d',
				$registration_id
			),
			ARRAY_A
		);

		if ( ! $reg ) {
			wp_send_json_error( array( 'message' => __( 'Registrazione non trovata', 'sd-logbook' ) ) );
		}

		$reg['registration_data'] = json_decode( $reg['registration_data'], true ) ?: array();

		$manager  = $this->get_manager();
		$activity = $manager ? $manager->get_activity( intval( $reg['activity_id'] ) ) : null;

		$html     = $this->build_html_single_registration( $reg, $activity, $manager );
		$filename = 'registrazione_' . $registration_id . '_' . gmdate( 'Ymd' ) . '.pdf';
		$this->stream_pdf( $html, $filename );
	}

	private function build_html_single_registration( $reg, $activity, $manager ) {
		$full_name = trim( ( $reg['first_name'] ?? '' ) . ' ' . ( $reg['last_name'] ?? '' ) );
		$activity_title = $activity ? $activity['title'] : ( 'Attività #' . intval( $reg['activity_id'] ) );

		$html = $this->base_css();
		$html .= '<body>';
		$html .= $this->pdf_header_html( 'Scheda Iscrizione', $full_name . ' – ' . $activity_title );

		// Dati anagrafici
		$html .= '<h2>Dati Iscritto</h2>';
		$html .= '<table class="info-grid"><tbody>';
		$rd      = $reg['registration_data'];
		$created = ! empty( $reg['created_at'] ) ? date_i18n( 'd/m/Y H:i', strtotime( $reg['created_at'] ) ) : '-';
		$anag    = array(
			'Nome e Cognome'   => $full_name,
			'Email'            => $reg['email'] ?? '-',
			'Data di nascita'  => $rd['birth_date'] ?? '-',
			'Attività'         => $activity_title,
			'Data iscrizione'  => $created,
		);
		foreach ( $anag as $label => $value ) {
			$html .= '<tr><td class="label">' . esc_html( $label ) . ':</td><td class="value">' . esc_html( $value ) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		// Stato e pagamento
		$html .= '<h2>Stato</h2>';
		$html .= '<table class="info-grid"><tbody>';
		$price_chf = number_format( floatval( $reg['price_chf'] ?? 0 ), 2, '.', "'" );
		$price_eur = number_format( floatval( $reg['price_eur'] ?? 0 ), 2, '.', "'" );
		$stato     = array(
			'Stato iscrizione' => $this->status_label( $reg['status'] ?? '' ),
			'Stato pagamento'  => $this->payment_label( $reg['payment_status'] ?? '' ),
			'Metodo pagamento' => ucfirst( $reg['payment_method'] ?? '-' ),
			'Prezzo CHF'       => 'CHF ' . $price_chf,
			'Prezzo EUR'       => 'EUR ' . $price_eur,
			'N. Fattura'       => $reg['invoice_number'] ?: '-',
		);
		foreach ( $stato as $label => $value ) {
			$html .= '<tr><td class="label">' . esc_html( $label ) . ':</td><td class="value">' . esc_html( $value ) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		// Campi modulo dinamici
		$form_fields = $manager ? $manager->get_form_fields( intval( $reg['activity_id'] ) ) : array();
		$skip_names  = array( 'first_name', 'last_name', 'email', 'birth_date' );
		$dyn_fields  = array();
		foreach ( $form_fields as $ff ) {
			$fname = $ff['field_name'] ?? '';
			if ( '' === $fname || in_array( $fname, $skip_names, true ) || 'content' === ( $ff['field_type'] ?? '' ) ) {
				continue;
			}
			$dyn_fields[ $fname ] = $ff['field_label'] ?? $fname;
		}

		if ( ! empty( $dyn_fields ) ) {
			$html .= '<h2>Dati Modulo Iscrizione</h2>';
			foreach ( $dyn_fields as $fname => $label ) {
				$val = $rd[ $fname ] ?? '';
				if ( is_array( $val ) ) {
					$val = implode( ', ', $val );
				}
				$html .= '<div class="field-block">';
				$html .= '<div class="field-label">' . esc_html( $label ) . '</div>';
				$html .= '<div class="field-value">' . esc_html( (string) $val ) . '</div>';
				$html .= '</div>';
			}
		}

		$html .= '<div class="footer">ScubaDiabetes – Iscrizione #' . intval( $reg['id'] ) . ' – ' . esc_html( $full_name ) . '</div>';
		$html .= '</body>';
		return $html;
	}
}
