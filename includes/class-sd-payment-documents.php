<?php
/**
 * Generazione documenti pagamento (ricevuta + tessera).
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Payment_Documents {

	/**
	 * Crea PDF fattura pagamento in attesa.
	 *
	 * @param object $member Dati socio.
	 * @param object $payment Dati pagamento.
	 * @return string|WP_Error
	 */
	public function generate_invoice_document( $member, $payment ) {
		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) || ! is_dir( $upload['basedir'] ) ) {
			return new WP_Error( 'sd_upload_dir_missing', __( 'Directory upload non disponibile.', 'sd-logbook' ) );
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'sd-documents/' . gmdate( 'Y' ) . '/member-' . (int) $member->id . '/';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sd_docs_dir_failed', __( 'Impossibile creare la cartella documenti.', 'sd-logbook' ) );
		}

		$safe_number = ! empty( $member->member_number ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $member->member_number ) : (string) $member->id;
		$invoice     = $dir . 'fattura-' . $safe_number . '-' . gmdate( 'Ymd-His' ) . '.pdf';

		$invoice_page = $this->build_invoice_page( $member, $payment );
		$this->write_styled_pdf( $invoice, array( $invoice_page ) );

		return $invoice;
	}

	/**
	 * Crea i PDF minimi per ricevuta e tessera.
	 *
	 * @param object $member Dati socio.
	 * @param object $payment Dati pagamento.
	 * @return array{receipt:string,card:string}|WP_Error
	 */
	public function generate_documents( $member, $payment ) {
		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) || ! is_dir( $upload['basedir'] ) ) {
			return new WP_Error( 'sd_upload_dir_missing', __( 'Directory upload non disponibile.', 'sd-logbook' ) );
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'sd-documents/' . gmdate( 'Y' ) . '/member-' . (int) $member->id . '/';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sd_docs_dir_failed', __( 'Impossibile creare la cartella documenti.', 'sd-logbook' ) );
		}

		$safe_number = ! empty( $member->member_number ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $member->member_number ) : (string) $member->id;
		$receipt     = $dir . 'ricevuta-' . $safe_number . '-' . gmdate( 'Ymd-His' ) . '.pdf';
		$card        = $dir . 'tessera-' . $safe_number . '-' . gmdate( 'Ymd-His' ) . '.pdf';

		$receipt_page = $this->build_receipt_page( $member, $payment );
		$this->write_styled_pdf( $receipt, array( $receipt_page ) );

		$card_pages = $this->build_card_pages( $member, $payment );
		$this->write_styled_pdf( $card, $card_pages );

		return array(
			'receipt' => $receipt,
			'card'    => $card,
		);
	}

	/**
	 * Genera PDF elenco soci in formato A4 orizzontale.
	 *
	 * @param array $rows Righe dati soci (ARRAY_A da wpdb).
	 * @param int   $year Anno associativo.
	 * @return string Contenuto binario del PDF.
	 */
	public function build_members_list_pdf( array $rows, int $year ): string {
		$pw = 841.89;
		$ph = 595.28;
		$margin_l = 28.0;
		$header_h = 65.0;
		$accent_h = 8.0;
		$tbl_hdr_h = 18.0;
		$row_h = 14.0;
		$footer_y = 12.0;
		$tbl_top_y = $ph - $header_h - $accent_h;
		$tbl_hdr_y = $tbl_top_y - $tbl_hdr_h;
		$rows_per_pg = max( 1, (int) floor( ( $tbl_hdr_y - $footer_y - 4.0 ) / $row_h ) );

		$assoc_title = (string) get_option( 'sd_payment_association_title', 'Associazione ScubaDiabetes' );
		$primary     = $this->hex_to_rgb( get_option( 'sd_payment_brand_primary', '#0055A5' ) );
		$secondary   = $this->hex_to_rgb( get_option( 'sd_payment_brand_secondary', '#00A3D8' ) );

		// Logo.
		$logo_url = 'https://scubadiabetes.ch/wp-content/uploads/2026/04/scubadiabetes_radius60.png';
		$logo_bg  = array(
			(int) round( $primary[0] * 255 ),
			(int) round( $primary[1] * 255 ),
			(int) round( $primary[2] * 255 ),
		);
		$logo_img = $this->resolve_qr_image_for_pdf( $logo_url, $logo_bg );
		$logo_h = 50.0;
		$logo_w = 50.0;
		if ( ! empty( $logo_img['path'] ) ) {
			$logo_meta = @getimagesize( (string) $logo_img['path'] );
			if ( is_array( $logo_meta ) && ! empty( $logo_meta[1] ) && (int) $logo_meta[1] > 0 ) {
				$logo_w = 50.0 * (float) $logo_meta[0] / (float) $logo_meta[1];
			}
		}

		// Colonne tabella: [ label, larghezza pt, max_chars ].
		$columns = array(
			array( '#', 22, 4 ),
			array( 'Cognome, Nome', 120, 28 ),
			array( 'Email', 150, 35 ),
			array( 'Nascita', 58, 10 ),
			array( 'Tipo Socio', 95, 22 ),
			array( 'CHF', 42, 8 ),
			array( 'Pagato', 36, 3 ),
			array( 'Metodo', 85, 20 ),
			array( 'Data Pag.', 58, 10 ),
			array( 'Scadenza', 60, 10 ),
		);
		$table_w = 0.0;
		foreach ( $columns as $col ) {
			$table_w += (float) $col[1];
		}

		$method_labels = array(
			'bonifico_iban' => 'Bonifico IBAN',
			'twint'         => 'TWINT',
			'paypal'        => 'PayPal',
			'carta_credito' => 'Carta credito',
			'apple_pay'     => 'Apple Pay',
			'google_pay'    => 'Google Pay',
			'fattura'       => 'Fattura',
			'famigliare'    => 'Famigliare',
			'staff'         => 'Staff',
		);

		$type_labels = array(
			'attivo'               => 'Attivo',
			'attivo_capo_famiglia' => 'Attivo Capo Famiglia',
			'attivo_famigliare'    => 'Attivo Famigliare',
			'passivo'              => 'Passivo',
			'accompagnatore'       => 'Accompagnatore',
			'sostenitore'          => 'Sostenitore',
			'onorario'             => 'Onorario',
			'fondatore'            => 'Fondatore',
			'staff'                => 'Staff',
		);

		$total_rows = count( $rows );
		$total_pages = max( 1, (int) ceil( $total_rows / $rows_per_pg ) );
		$pages = array();

		for ( $pg = 0; $pg < $total_pages; $pg++ ) {
			$ops = '';
			$images = array();

			// Barra header primaria.
			$ops .= $this->rect_fill( 0, $ph - $header_h, $pw, $header_h, $primary );
			$ops .= $this->rect_fill( 0, $tbl_top_y, $pw, $accent_h, $secondary );

			// Testo intestazione (colonna sinistra).
			$ops .= $this->text( $margin_l, $ph - 26, 14, strtoupper( $assoc_title ), true, array( 1, 1, 1 ) );
			$ops .= $this->text( $margin_l, $ph - 44, 10, 'Elenco soci - Anno ' . (string) $year, false, array( 0.80, 0.91, 1.0 ) );
			$ops .= $this->text( $margin_l, $ph - 59, 8, 'Pagina ' . ( $pg + 1 ) . ' di ' . $total_pages . '  |  ' . gmdate( 'd.m.Y H:i' ), false, array( 0.70, 0.85, 1.0 ) );

			// Logo (destra, centrato verticalmente nella barra).
			if ( ! empty( $logo_img['path'] ) ) {
				$lx       = (int) round( $pw - $logo_w - 14.0 );
				$ly       = (int) round( $ph - $header_h + ( $header_h - $logo_h ) / 2 );
				$images[] = array(
					'path' => (string) $logo_img['path'],
					'x'    => $lx,
					'y'    => $ly,
					'w'    => (int) round( $logo_w ),
					'h'    => (int) round( $logo_h ),
				);
			}

			// Riga intestazione colonne.
			$ops .= $this->rect_fill( $margin_l, $tbl_hdr_y, $table_w, $tbl_hdr_h, $primary );
			$cx   = $margin_l + 3.0;
			foreach ( $columns as $col ) {
				$ops .= $this->text( $cx, $tbl_hdr_y + 5, 7.5, $col[0], true, array( 1, 1, 1 ) );
				$cx  += (float) $col[1];
			}

			// Righe dati.
			$alt = array( array( 0.95, 0.97, 1.0 ), array( 1, 1, 1 ) );
			$start_idx = $pg * $rows_per_pg;
			$end_idx = min( $start_idx + $rows_per_pg, $total_rows );

			for ( $ri = $start_idx; $ri < $end_idx; $ri++ ) {
				$r = $rows[ $ri ];
				$row_y = $tbl_hdr_y - (float) ( ( $ri - $start_idx + 1 ) * $row_h );
				$row_bg = $alt[ ( $ri - $start_idx ) % 2 ];
				$ops .= $this->rect_fill( $margin_l, $row_y, $table_w, $row_h, $row_bg );

				$nascita = ! empty( $r['date_of_birth'] ) ? date_i18n( 'd.m.Y', strtotime( (string) $r['date_of_birth'] ) ) : '-';
				$scad    = ! empty( $r['membership_expiry'] ) ? date_i18n( 'd.m.Y', strtotime( (string) $r['membership_expiry'] ) ) : '-';
				$has_paid = ! empty( $r['has_paid_fee'] ) && (int) $r['has_paid_fee'];
				$datapag  = ( $has_paid && ! empty( $r['payment_date'] ) ) ? date_i18n( 'd.m.Y', strtotime( substr( (string) $r['payment_date'], 0, 10 ) ) ) : '-';
				$pagato   = $has_paid ? 'Si' : 'No';
				$metodo  = $method_labels[ $r['payment_method'] ?? '' ] ?? (string) ( $r['payment_method'] ?? '-' );
				$tassa   = ! empty( $r['fee_amount'] ) ? number_format( (float) $r['fee_amount'], 2 ) : '-';
				$name    = trim( (string) $r['last_name'] . ', ' . (string) $r['first_name'] );
				$tipo    = $type_labels[ $r['member_type'] ?? '' ] ?? (string) ( $r['member_type'] ?? '-' );

				$cells = array(
					(string) ( $ri + 1 ),
					$name,
					(string) ( $r['email'] ?? '' ),
					$nascita,
					$tipo,
					$tassa,
					$pagato,
					$metodo,
					$datapag,
					$scad,
				);

				$cx = $margin_l + 3.0;
				foreach ( $columns as $ci => $col ) {
					$val = isset( $cells[ $ci ] ) ? (string) $cells[ $ci ] : '';
					$max = (int) $col[2];
					if ( strlen( $val ) > $max ) {
						$val = substr( $val, 0, $max - 1 ) . '.';
					}
					$ops .= $this->text( $cx, $row_y + 3.5, 7.0, $val );
					$cx  += (float) $col[1];
				}
			}

			// Footer.
			$ops .= $this->text( $margin_l, $footer_y, 7.5, $assoc_title . ' - ' . gmdate( 'd.m.Y H:i' ), false, array( 0.5, 0.5, 0.5 ) );

			$pages[] = array(
				'width'    => $pw,
				'height'   => $ph,
				'commands' => $ops,
				'images'   => $images,
			);
		}

		$tmp = wp_tempnam( 'sd-members-list' );
		$this->write_styled_pdf( $tmp, $pages );
		$content = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( file_exists( $tmp ) ) {
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		return $content;
	}

	/**
	 * Crea pagina ricevuta con layout curato.
	 *
	 * @param object $member Dati socio.
	 * @param object $payment Dati pagamento.
	 * @return array
	 */
	private function build_receipt_page( $member, $payment ) {
		$width         = 595.0;
		$height        = 842.0;
		$primary       = $this->hex_to_rgb( get_option( 'sd_payment_brand_primary', '#0055A5' ) );
		$secondary     = $this->hex_to_rgb( get_option( 'sd_payment_brand_secondary', '#00A3D8' ) );
		$assoc_title   = (string) get_option( 'sd_payment_association_title', 'Associazione ScubaDiabetes' );
		$footer_note   = (string) get_option( 'sd_payment_receipt_footer_note', 'Documento gestionale emesso dall\'associazione. Validita fiscale subordinata alla normativa applicabile e a verifica professionale in CH/IT.' );

		$member_name = trim( (string) $member->first_name . ' ' . (string) $member->last_name );
		$expiry      = ! empty( $member->membership_expiry ) ? date_i18n( 'd.m.Y', strtotime( (string) $member->membership_expiry ) ) : '-';
		$payment_dt  = ! empty( $payment->payment_date ) ? mysql2date( 'd.m.Y', $payment->payment_date, false ) : gmdate( 'd.m.Y' );
		$tx          = ! empty( $payment->provider_payment_id ) ? (string) $payment->provider_payment_id : (string) $payment->transaction_id;
		$method      = $this->payment_method_label( ! empty( $payment->payment_method ) ? (string) $payment->payment_method : '' );
		$amount      = 'CHF ' . number_format( (float) $payment->amount, 2, '.', '' );

		// Logo header (stessa logica della fattura)
		$logo_url   = 'https://scubadiabetes.ch/wp-content/uploads/2026/04/scubadiabetes_radius60.png';
		$logo_bg    = array(
			(int) round( $primary[0] * 255 ),
			(int) round( $primary[1] * 255 ),
			(int) round( $primary[2] * 255 ),
		);
		$logo_image = $this->resolve_qr_image_for_pdf( $logo_url, $logo_bg );
		$logo_h     = 76;
		$logo_w     = 76;
		$logo_x     = 28;
		$logo_y     = $height - 88 + (int) round( ( 88 - $logo_h ) / 2 );
		$text_x     = 120;
		if ( ! empty( $logo_image['path'] ) ) {
			$logo_meta = @getimagesize( (string) $logo_image['path'] );
			if ( is_array( $logo_meta ) && ! empty( $logo_meta[1] ) && (int) $logo_meta[1] > 0 ) {
				$logo_h = 76;
				$logo_w = (int) round( $logo_h * (int) $logo_meta[0] / (int) $logo_meta[1] );
				$logo_y = $height - 88 + (int) round( ( 88 - $logo_h ) / 2 );
				$text_x = $logo_x + $logo_w + 12;
			}
		}

		$ops  = '';
		$ops .= $this->rect_fill( 0, $height - 88, $width, 88, $primary );
		$ops .= $this->rect_fill( 0, $height - 96, $width, 8, $secondary );
		$ops .= $this->text( $text_x, $height - 42, 17, $assoc_title, true, array( 1, 1, 1 ) );
		$ops .= $this->text( $text_x, $height - 66, 11, 'Ricevuta pagamento tassa sociale', false, array( 1, 1, 1 ) );

		$ops .= $this->text( 28, $height - 124, 10, 'Numero ricevuta: ' . $this->receipt_number( $member, $payment ), true );
		$ops .= $this->text( 360, $height - 124, 10, 'Data emissione: ' . gmdate( 'd.m.Y H:i' ), false );

		$ops .= $this->rect_stroke( 28, $height - 312, 539, 170, array( 0.82, 0.84, 0.88 ) );
		$ops .= $this->text( 40, $height - 164, 11, 'Dati socio e pagamento', true );
		$ops .= $this->text( 40, $height - 188, 10, 'Socio: ' . $member_name );
		$ops .= $this->text( 40, $height - 208, 10, 'Numero socio: ' . (string) $member->member_number );
		$ops .= $this->text( 40, $height - 228, 10, 'Importo: ' . $amount );
		$ops .= $this->text( 40, $height - 248, 10, 'Metodo pagamento: ' . $method );
		$ops .= $this->text( 40, $height - 268, 10, 'Data pagamento: ' . $payment_dt );
		$ops .= $this->text( 40, $height - 288, 10, 'Riferimento transazione: ' . $tx );
		$ops .= $this->text( 320, $height - 188, 10, 'Scadenza iscrizione: ' . $expiry );
		$ops .= $this->text( 320, $height - 208, 10, 'Anno associativo: ' . ( ! empty( $payment->payment_year ) ? (string) $payment->payment_year : gmdate( 'Y' ) ) );

		$ops .= $this->text( 28, $height - 346, 11, 'Messaggio di benvenuto', true );
		$ops .= $this->text( 28, $height - 366, 9, 'IT: Grazie per il sostegno e benvenuto/a nell\'associazione per l\'anno in corso.' );
		$ops .= $this->text( 28, $height - 382, 9, 'FR: Merci pour votre soutien et bienvenue dans l\'association pour l\'annee en cours.' );
		$ops .= $this->text( 28, $height - 398, 9, 'DE: Vielen Dank fur Ihre Unterstutzung und willkommen im Verein fur das laufende Jahr.' );
		$ops .= $this->text( 28, $height - 414, 9, 'EN: Thank you for your support and welcome to the association for the current year.' );

		$ops .= $this->rect_fill( 28, 64, 539, 76, array( 0.96, 0.97, 0.99 ) );
		$ops .= $this->rect_stroke( 28, 64, 539, 76, array( 0.82, 0.84, 0.88 ) );
		$ops .= $this->text( 40, 122, 9, 'Nota fiscale', true );
		foreach ( $this->wrap_text_lines( $footer_note, 92 ) as $idx => $line ) {
			$ops .= $this->text( 40, 106 - ( $idx * 13 ), 8.5, $line );
		}

		$ops .= $this->text( 40, 38, 8, $assoc_title . ' - ' . home_url( '/' ), false, array( 0.36, 0.40, 0.46 ) );

		$images = array();
		if ( ! empty( $logo_image['path'] ) ) {
			$images[] = array(
				'path' => (string) $logo_image['path'],
				'x'    => $logo_x,
				'y'    => $logo_y,
				'w'    => $logo_w,
				'h'    => $logo_h,
			);
		}

		return array(
			'width'    => $width,
			'height'   => $height,
			'commands' => $ops,
			'images'   => $images,
		);
	}

	/**
	 * Crea le due pagine della tessera (fronte A/B) in formato carta.
	 *
	 * @param object $member Dati socio.
	 * @param object $payment Dati pagamento.
	 * @return array
	 */
	private function build_card_pages( $member, $payment ) {
		$width       = 242.65;
		$height      = 153.02;
		$r           = 9.0; // raggio angoli carta di credito (≈ 3.18 mm)
		$year        = ! empty( $payment->payment_year ) ? (string) $payment->payment_year : gmdate( 'Y' );
		$expiry      = ! empty( $member->membership_expiry ) ? date_i18n( 'd.m.Y', strtotime( (string) $member->membership_expiry ) ) : '-';
		$dob         = ! empty( $member->date_of_birth ) ? date_i18n( 'd.m.Y', strtotime( (string) $member->date_of_birth ) ) : '-';
		$assoc_title = (string) get_option( 'sd_payment_association_title', 'Associazione ScubaDiabetes' );
		$primary     = $this->hex_to_rgb( get_option( 'sd_payment_brand_primary', '#0055A5' ) );
		$secondary   = $this->hex_to_rgb( get_option( 'sd_payment_brand_secondary', '#00A3D8' ) );

		// Percorso clip intera tessera (angoli arrotondati)
		$card_clip = $this->rounded_rect_clip_path( 0, 0, $width, $height, $r );

		// Logo fronte A (bg = colore primario per preservare radius 60 del logo)
		$logo_url   = 'https://scubadiabetes.ch/wp-content/uploads/2026/04/scubadiabetes_radius60.png';
		$logo_bg_a  = array(
			(int) round( $primary[0] * 255 ),
			(int) round( $primary[1] * 255 ),
			(int) round( $primary[2] * 255 ),
		);
		$logo_image = $this->resolve_qr_image_for_pdf( $logo_url, $logo_bg_a );
		// Logo: colonna destra (x ≥ 130 pt) – mai sovrapposto al testo di sinistra.
		$logo_col_x = 130.0;
		$logo_col_w = $width - 10.0 - $logo_col_x; // ≈ 102 pt
		$logo_h_a   = 88.0;
		$logo_w_a   = 88.0;
		if ( ! empty( $logo_image['path'] ) ) {
			$logo_meta = @getimagesize( (string) $logo_image['path'] );
			if ( is_array( $logo_meta ) && ! empty( $logo_meta[1] ) && (int) $logo_meta[1] > 0 ) {
				$ratio    = (float) $logo_meta[0] / (float) $logo_meta[1];
				$logo_h_a = 88.0;
				$logo_w_a = 88.0 * $ratio;
				if ( $logo_w_a > $logo_col_w ) {
					$logo_w_a = $logo_col_w;
					$logo_h_a = $logo_w_a / $ratio;
				}
			}
		}
		$logo_x_a = (int) round( $logo_col_x + ( $logo_col_w - $logo_w_a ) / 2 );
		$logo_y_a = (int) round( ( $height - $logo_h_a ) / 2 );

		// ===== FRONTE A =====
		// Sfondo primario con angoli arrotondati (via clip)
		$front_a_ops  = "q\n" . $card_clip . "W n\n";
		$front_a_ops .= $this->rect_fill( 0, 0, $width, $height, $primary );
		// Striscia accent secondaria nella metà inferiore
		$front_a_ops .= $this->rect_fill( 0, 14, $width, 7, $secondary );
		// Testo area sinistra
		// Titolo associazione con wrap (colonna sinistra ≤ x 130 pt).
		$title_lines = $this->wrap_text_lines( strtoupper( $assoc_title ), 18 );
		$ty          = (float) ( $height - 20 );
		foreach ( $title_lines as $tl ) {
			$front_a_ops .= $this->text( 14, $ty, 8.5, $tl, true, array( 1, 1, 1 ) );
			$ty          -= 13;
		}
		$front_a_ops .= $this->text( 14, $ty - 2, 7.5, 'Tessera associativa', false, array( 0.80, 0.91, 1.0 ) );
		$front_a_ops .= $this->text( 14, $ty - 16, 9, 'Anno ' . $year, true, array( 1, 1, 1 ) );
		$front_a_ops .= "Q\n";
		// Bordo tessera arrotondato (fuori dal clip per resa pulita)
		$front_a_ops .= $this->rounded_rect_stroke( 0, 0, $width, $height, $r, array( 0.0, 0.33, 0.65 ), 1.2 );

		// ===== FRONTE B =====
		// Sfondo chiaro con angoli arrotondati
		$front_b_ops  = $this->rounded_rect_fill( 0, 0, $width, $height, $r, array( 0.97, 0.98, 1.0 ) );
		// Header primario in alto (clippato alla tessera per arrotondare angoli superiori)
		$front_b_ops .= "q\n" . $card_clip . "W n\n";
		$front_b_ops .= $this->rect_fill( 0, $height - 28, $width, 28, $primary );
		$front_b_ops .= "Q\n";
		// Testo header
		$front_b_ops .= $this->text( 14, $height - 18, 8.5, 'Tessera socio', true, array( 1, 1, 1 ) );
		// Dati socio
		$front_b_ops .= $this->text( 14, 106, 8.5, 'Nome: ' . (string) $member->first_name );
		$front_b_ops .= $this->text( 14, 92, 8.5, 'Cognome: ' . (string) $member->last_name );
		$front_b_ops .= $this->text( 14, 78, 8.5, 'Data di nascita: ' . $dob );
		$front_b_ops .= $this->text( 14, 64, 8.5, 'Numero socio: ' . (string) $member->member_number );
		$front_b_ops .= $this->text( 14, 50, 8.5, 'Tipo socio: ' . (string) $member->member_type );
		$front_b_ops .= $this->text( 14, 36, 8.5, 'Scadenza: ' . $expiry );
		// Bordo tessera arrotondato
		$front_b_ops .= $this->rounded_rect_stroke( 0, 0, $width, $height, $r, $primary, 1.2 );

		$front_a_images = array();
		if ( ! empty( $logo_image['path'] ) ) {
			$front_a_images[] = array(
				'path' => (string) $logo_image['path'],
				'x'    => $logo_x_a,
				'y'    => $logo_y_a,
				'w'    => $logo_w_a,
				'h'    => $logo_h_a,
			);
		}

		return array(
			array(
				'width'    => $width,
				'height'   => $height,
				'commands' => $front_a_ops,
				'images'   => $front_a_images,
			),
			array(
				'width'    => $width,
				'height'   => $height,
				'commands' => $front_b_ops,
			),
		);
	}

	/**
	 * Crea pagina fattura con dati socio, coordinate bancarie e riferimenti QR.
	 *
	 * @param object $member Dati socio.
	 * @param object $payment Dati pagamento.
	 * @return array
	 */
	private function build_invoice_page( $member, $payment ) {
		$width              = 595.0;
		$height             = 842.0;
		$primary            = $this->hex_to_rgb( get_option( 'sd_payment_brand_primary', '#0055A5' ) );
		$secondary          = $this->hex_to_rgb( get_option( 'sd_payment_brand_secondary', '#00A3D8' ) );
		$association_name   = (string) get_option( 'sd_payment_invoice_association_name', get_option( 'sd_payment_association_title', 'Associazione ScubaDiabetes' ) );
		$association_addr   = (string) get_option( 'sd_payment_invoice_association_address', '' );
		$association_postal = (string) get_option( 'sd_payment_invoice_association_postal_code', '' );
		$association_city   = (string) get_option( 'sd_payment_invoice_association_city', '' );
		$association_email  = (string) get_option( 'sd_payment_invoice_association_email', get_bloginfo( 'admin_email' ) );
		$association_phone  = (string) get_option( 'sd_payment_invoice_association_phone', '' );

		$bank_name    = (string) get_option( 'sd_payment_invoice_bank_name', '' );
		$bank_addr    = (string) get_option( 'sd_payment_invoice_bank_address', '' );
		$bank_postal  = (string) get_option( 'sd_payment_invoice_bank_postal_code', '' );
		$bank_city    = (string) get_option( 'sd_payment_invoice_bank_city', '' );
		$bank_iban    = (string) get_option( 'sd_payment_invoice_bank_iban', '' );
		$bank_swift   = (string) get_option( 'sd_payment_invoice_bank_swift', '' );
		$bank_bic     = (string) get_option( 'sd_payment_invoice_bank_bic', '' );
		$qr_payload   = (string) get_option( 'sd_payment_invoice_qr_payload', '' );
		$qr_image_url = (string) get_option( 'sd_payment_invoice_qr_image_url', '' );
		$qr_image     = $this->resolve_qr_image_for_pdf( $qr_image_url );

		$member_name = trim( (string) $member->first_name . ' ' . (string) $member->last_name );
		$invoice_no  = sprintf( 'INV-%s-%06d-%04d', ! empty( $payment->payment_year ) ? (string) $payment->payment_year : gmdate( 'Y' ), (int) $member->id, (int) $payment->id );
		$amount      = 'CHF ' . number_format( (float) $payment->amount, 2, '.', '' );

		// Logo associazione (sfondo = colore primario header per preservare angoli arrotondati)
		$logo_url   = 'https://scubadiabetes.ch/wp-content/uploads/2026/04/scubadiabetes_radius60.png';
		$logo_bg    = array(
			(int) round( $primary[0] * 255 ),
			(int) round( $primary[1] * 255 ),
			(int) round( $primary[2] * 255 ),
		);
		$logo_image = $this->resolve_qr_image_for_pdf( $logo_url, $logo_bg );

		// Calcola dimensioni logo mantenendo le proporzioni originali
		$logo_h    = 76;
		$logo_w    = 76;
		$logo_x    = 28;
		$logo_y    = $height - 88 + (int) round( ( 88 - $logo_h ) / 2 );
		$text_x    = 120;
		if ( ! empty( $logo_image['path'] ) ) {
			$logo_meta = @getimagesize( (string) $logo_image['path'] );
			if ( is_array( $logo_meta ) && ! empty( $logo_meta[1] ) && (int) $logo_meta[1] > 0 ) {
				$logo_h = 76;
				$logo_w = (int) round( $logo_h * (int) $logo_meta[0] / (int) $logo_meta[1] );
				$logo_y = $height - 88 + (int) round( ( 88 - $logo_h ) / 2 );
				$text_x = $logo_x + $logo_w + 12;
			}
		}

		$ops  = '';
		$ops .= $this->rect_fill( 0, $height - 88, $width, 88, $primary );
		$ops .= $this->rect_fill( 0, $height - 96, $width, 8, $secondary );
		$ops .= $this->text( $text_x, $height - 42, 17, $association_name, true, array( 1, 1, 1 ) );
		$ops .= $this->text( $text_x, $height - 66, 11, 'Fattura tassa sociale', false, array( 1, 1, 1 ) );

		$ops .= $this->text( 28, $height - 124, 10, 'Numero fattura: ' . $invoice_no, true );
		$ops .= $this->text( 360, $height - 124, 10, 'Data emissione: ' . gmdate( 'd.m.Y' ), false );

		$ops .= $this->rect_stroke( 28, $height - 246, 539, 88, array( 0.82, 0.84, 0.88 ) );
		$ops .= $this->text( 40, $height - 176, 11, 'Intestatario fattura', true );
		$ops .= $this->text( 40, $height - 196, 10, 'Socio: ' . $member_name );
		$ops .= $this->text( 40, $height - 214, 10, 'Numero socio: ' . (string) $member->member_number );
		$ops .= $this->text( 320, $height - 196, 10, 'Importo dovuto: ' . $amount, true );

		$ops .= $this->text( 28, $height - 272, 11, 'Dati associazione', true );
		$ops .= $this->text( 28, $height - 292, 9.5, $association_name );
		$ops .= $this->text( 28, $height - 308, 9.5, trim( $association_addr . ' - ' . $association_postal . ' ' . $association_city, ' -' ) );
		$ops .= $this->text( 28, $height - 324, 9.5, 'Email: ' . $association_email . ' | Tel: ' . $association_phone );

		$ops .= $this->rect_fill( 28, $height - 560, 539, 210, array( 0.96, 0.97, 0.99 ) );
		$ops .= $this->rect_stroke( 28, $height - 560, 539, 210, array( 0.82, 0.84, 0.88 ) );
		$ops .= $this->text( 40, $height - 376, 11, 'Coordinate bancarie per pagamento fattura', true );
		$ops .= $this->text( 40, $height - 398, 10, 'Banca: ' . $bank_name );
		$ops .= $this->text( 40, $height - 416, 10, 'Indirizzo banca: ' . trim( $bank_addr . ' - ' . $bank_postal . ' ' . $bank_city, ' -' ) );
		$ops .= $this->text( 40, $height - 434, 10, 'IBAN: ' . $bank_iban, true );
		$ops .= $this->text( 40, $height - 452, 10, 'SWIFT: ' . $bank_swift . ' | BIC: ' . $bank_bic );
		$ops .= $this->text( 40, $height - 470, 10, 'Causale: Quota sociale ' . ( ! empty( $payment->payment_year ) ? (string) $payment->payment_year : gmdate( 'Y' ) ) . ' - ' . (string) $member->member_number );

		$ops .= $this->text( 40, $height - 500, 10, 'QR pagamento', true );
		if ( '' !== trim( $qr_payload ) ) {
			foreach ( $this->wrap_text_lines( 'Payload QR: ' . $qr_payload, 88 ) as $idx => $line ) {
				$ops .= $this->text( 40, $height - 518 - ( $idx * 13 ), 8.5, $line );
			}
		} else {
			$ops .= $this->text( 40, $height - 518, 8.5, 'Payload QR non configurato nelle impostazioni.' );
		}

		$images = array();
		if ( ! empty( $logo_image['path'] ) ) {
			$images[] = array(
				'path' => (string) $logo_image['path'],
				'x'    => $logo_x,
				'y'    => $logo_y,
				'w'    => $logo_w,
				'h'    => $logo_h,
			);
		}
		if ( ! empty( $qr_image['path'] ) ) {
			$ops      .= $this->rect_stroke( 430, $height - 544, 120, 120, array( 0.82, 0.84, 0.88 ) );
			$images[] = array(
				'path' => (string) $qr_image['path'],
				'x'    => 430,
				'y'    => $height - 544,
				'w'    => 120,
				'h'    => 120,
			);
		} elseif ( '' !== trim( $qr_image_url ) ) {
			$ops .= $this->text( 40, $height - 560, 8.5, 'Immagine QR non disponibile o formato non supportato nel server.' );
		}

		$ops .= $this->text( 40, 56, 8, 'Stato fattura: IN ATTESA DI PAGAMENTO. Attivazione del socio al ricevimento accredito.', false, array( 0.36, 0.40, 0.46 ) );

		return array(
			'width'    => $width,
			'height'   => $height,
			'commands' => $ops,
			'images'   => $images,
		);
	}

	/**
	 * Genera PDF fattura per una registrazione attività.
	 *
	 * @param object $ctx Context registrazione (vedi SD_Activity_Payment_Flow::get_context_by_token).
	 * @return string|WP_Error Percorso del PDF generato.
	 */
	public function generate_activity_invoice_document( $ctx ) {
		if ( ! is_object( $ctx ) || empty( $ctx->registration_id ) ) {
			return new WP_Error( 'sd_act_inv_bad_ctx', __( 'Contesto registrazione non valido.', 'sd-logbook' ) );
		}

		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) || ! is_dir( $upload['basedir'] ) ) {
			return new WP_Error( 'sd_upload_dir_missing', __( 'Directory upload non disponibile.', 'sd-logbook' ) );
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'sd-documents/' . gmdate( 'Y' ) . '/activity-' . (int) $ctx->activity_id . '/';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sd_docs_dir_failed', __( 'Impossibile creare la cartella documenti.', 'sd-logbook' ) );
		}

		$file = $dir . 'fattura-attivita-' . (int) $ctx->registration_id . '-' . gmdate( 'Ymd-His' ) . '.pdf';
		$page = $this->build_activity_invoice_page( $ctx );
		$this->write_styled_pdf( $file, array( $page ) );

		return $file;
	}

	/**
	 * Genera PDF di conferma pagamento per una registrazione attività.
	 *
	 * @param object $ctx          Context registrazione (vedi SD_Activity_Payment_Flow::get_context_by_token).
	 * @param array  $payment_data Dati pagamento confermato.
	 * @return string|WP_Error Percorso del PDF generato.
	 */
	public function generate_activity_payment_confirmation_document( $ctx, $payment_data = array() ) {
		if ( ! is_object( $ctx ) || empty( $ctx->registration_id ) ) {
			return new WP_Error( 'sd_act_pay_bad_ctx', __( 'Contesto registrazione non valido.', 'sd-logbook' ) );
		}

		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) || ! is_dir( $upload['basedir'] ) ) {
			return new WP_Error( 'sd_upload_dir_missing', __( 'Directory upload non disponibile.', 'sd-logbook' ) );
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'sd-documents/' . gmdate( 'Y' ) . '/activity-' . (int) $ctx->activity_id . '/';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sd_docs_dir_failed', __( 'Impossibile creare la cartella documenti.', 'sd-logbook' ) );
		}

		$file  = $dir . 'conferma-pagamento-attivita-' . (int) $ctx->registration_id . '-' . gmdate( 'Ymd-His' ) . '.pdf';
		$pages = $this->build_activity_payment_confirmation_pages( $ctx, is_array( $payment_data ) ? $payment_data : array() );
		$this->write_styled_pdf( $file, $pages );

		return $file;
	}

	/**
	 * Costruisce HTML ordinato e leggibile dei dati registrazione attività.
	 *
	 * @param object $ctx Context registrazione.
	 * @return string
	 */
	public function build_activity_registration_summary_html( $ctx ) {
		$sections = $this->get_activity_registration_summary_sections( $ctx );
		if ( empty( $sections ) ) {
			return '<p style="color:#64748b;font-size:13px">' . esc_html__( 'Nessun dato modulo registrato.', 'sd-logbook' ) . '</p>';
		}

		$html = '';
		foreach ( $sections as $section ) {
			if ( empty( $section['rows'] ) ) {
				continue;
			}

			$html .= '<h4 style="color:#0055a5;margin:18px 0 8px">' . esc_html( (string) $section['label'] ) . '</h4>';
			$html .= '<table style="border-collapse:collapse;width:100%;font-size:14px;margin-bottom:16px">';
			foreach ( $section['rows'] as $row ) {
				$html .= '<tr>';
				$html .= '<td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc;width:38%"><strong>' . esc_html( (string) $row['label'] ) . '</strong></td>';
				$html .= '<td style="padding:6px 10px;border:1px solid #e2e8f0">' . nl2br( esc_html( (string) $row['value'] ) ) . '</td>';
				$html .= '</tr>';
			}
			$html .= '</table>';
		}

		return '' !== $html ? $html : '<p style="color:#64748b;font-size:13px">' . esc_html__( 'Nessun dato modulo registrato.', 'sd-logbook' ) . '</p>';
	}

	/**
	 * Costruisce le pagine PDF per la conferma pagamento attività.
	 *
	 * @param object $ctx          Context registrazione.
	 * @param array  $payment_data Dati pagamento confermato.
	 * @return array
	 */
	private function build_activity_payment_confirmation_pages( $ctx, array $payment_data ) {
		$width   = 595.0;
		$height  = 842.0;
		$primary = $this->hex_to_rgb( get_option( 'sd_payment_brand_primary', '#0055A5' ) );
		$secondary = $this->hex_to_rgb( get_option( 'sd_payment_brand_secondary', '#00A3D8' ) );

		$association_name = (string) get_option( 'sd_payment_association_title', 'Associazione ScubaDiabetes' );
		$participant_name = trim( (string) $ctx->first_name . ' ' . (string) $ctx->last_name );
		$activity_title   = (string) ( $ctx->activity_title ?? '' );
		$activity_date    = ! empty( $ctx->activity_start_date ) ? date_i18n( 'd.m.Y', strtotime( (string) $ctx->activity_start_date ) ) : '';

		$method_slug = sanitize_key( (string) ( $payment_data['payment_method'] ?? ( $ctx->payment_method ?? '' ) ) );
		$method      = $this->payment_method_label( $method_slug );
		$provider_id = sanitize_text_field( (string) ( $payment_data['provider_payment_id'] ?? ( $ctx->transaction_id ?? '' ) ) );
		$paid_at_raw = (string) ( $payment_data['payment_date'] ?? current_time( 'mysql' ) );
		$paid_at     = date_i18n( 'd.m.Y H:i', strtotime( $paid_at_raw ) );

		$amount_chf = 'CHF ' . number_format( (float) ( $payment_data['amount_chf'] ?? $ctx->price_chf ?? 0 ), 2, '.', '' );
		$amount_eur = 'EUR ' . number_format( (float) ( $payment_data['amount_eur'] ?? $ctx->price_eur ?? 0 ), 2, '.', '' );

		$registration_items = $this->build_activity_registration_data_lines( $ctx );
		if ( empty( $registration_items ) ) {
			$registration_items = array(
				array(
					't'          => 'r',
					'label_text' => __( 'Nota:', 'sd-logbook' ),
					'value_lines' => array( __( 'Nessun dato modulo registrato.', 'sd-logbook' ) ),
				),
			);
		}

		$first_page_capacity = 352;
		$next_page_capacity  = 590;
		$chunks              = $this->paginate_activity_registration_items( $registration_items, $first_page_capacity, $next_page_capacity );

		$pages = array();

		$logo_url   = 'https://scubadiabetes.ch/wp-content/uploads/2026/04/scubadiabetes_radius60.png';
		$logo_bg    = array(
			(int) round( $primary[0] * 255 ),
			(int) round( $primary[1] * 255 ),
			(int) round( $primary[2] * 255 ),
		);
		$logo_image = $this->resolve_qr_image_for_pdf( $logo_url, $logo_bg );
		$logo_h     = 76;
		$logo_w     = 76;
		$logo_x     = 28;
		$logo_y     = $height - 88 + (int) round( ( 88 - $logo_h ) / 2 );
		$text_x     = 120;
		if ( ! empty( $logo_image['path'] ) ) {
			$logo_meta = @getimagesize( (string) $logo_image['path'] );
			if ( is_array( $logo_meta ) && ! empty( $logo_meta[1] ) && (int) $logo_meta[1] > 0 ) {
				$logo_w = (int) round( $logo_h * (int) $logo_meta[0] / (int) $logo_meta[1] );
				$text_x = $logo_x + $logo_w + 12;
			}
		}

		// Prima pagina con riepilogo pagamento.
		$ops  = '';
		$ops .= $this->rect_fill( 0, $height - 88, $width, 88, $primary );
		$ops .= $this->rect_fill( 0, $height - 96, $width, 8, $secondary );
		$ops .= $this->text( $text_x, $height - 42, 16, $association_name, true, array( 1, 1, 1 ) );
		$ops .= $this->text( $text_x, $height - 66, 11, 'Conferma pagamento iscrizione attivita', false, array( 1, 1, 1 ) );

		$ops .= $this->text( 28, $height - 124, 10, 'Numero conferma: ACT-PAY-' . gmdate( 'Y' ) . '-' . (int) $ctx->registration_id, true );
		$ops .= $this->text( 360, $height - 124, 10, 'Data emissione: ' . gmdate( 'd.m.Y H:i' ) );

		$ops .= $this->rect_stroke( 28, $height - 332, 539, 188, array( 0.82, 0.84, 0.88 ) );
		$ops .= $this->text( 40, $height - 164, 11, 'Riepilogo iscrizione e pagamento', true );
		$ops .= $this->text( 40, $height - 184, 10, 'ID registrazione: #' . (int) $ctx->registration_id );
		$ops .= $this->text( 40, $height - 202, 10, 'Partecipante: ' . $participant_name );
		$ops .= $this->text( 40, $height - 220, 10, 'Email: ' . (string) $ctx->email );
		$ops .= $this->text( 40, $height - 238, 10, 'Attivita: ' . $activity_title );
		if ( '' !== $activity_date ) {
			$ops .= $this->text( 40, $height - 256, 10, 'Data attivita: ' . $activity_date );
		}
		if ( ! empty( $ctx->price_name ) ) {
			$ops .= $this->text( 40, $height - 274, 10, 'Tariffa: ' . (string) $ctx->price_name );
		}
		$ops .= $this->text( 320, $height - 184, 10, 'Stato pagamento: PAGATO', true );
		$ops .= $this->text( 320, $height - 202, 10, 'Metodo: ' . $method );
		$ops .= $this->text( 320, $height - 220, 10, 'Importo: ' . $amount_chf );
		$ops .= $this->text( 320, $height - 238, 10, '(' . $amount_eur . ')' );
		$ops .= $this->text( 320, $height - 256, 10, 'Data pagamento: ' . $paid_at );
		if ( '' !== trim( $provider_id ) ) {
			$ops .= $this->text( 320, $height - 274, 9, 'Transazione: ' . $provider_id );
		}

		$ops .= $this->text( 28, $height - 360, 11, 'Dati registrati nel modulo iscrizione', true );

		$y = $height - 382;
		foreach ( (array) ( $chunks[0] ?? array() ) as $item ) {
			$ops .= $this->render_activity_registration_item( $item, $y );
		}

		$ops .= $this->text( 28, 56, 8, 'Documento riepilogativo del pagamento elettronico/PayPal per iscrizione attivita.', false, array( 0.36, 0.40, 0.46 ) );

		$pages[] = array(
			'width'    => $width,
			'height'   => $height,
			'commands' => $ops,
			'images'   => ! empty( $logo_image['path'] ) ? array(
				array(
					'path' => (string) $logo_image['path'],
					'x'    => $logo_x,
					'y'    => $logo_y,
					'w'    => $logo_w,
					'h'    => $logo_h,
				),
			) : array(),
		);

		// Eventuali pagine aggiuntive con i dati modulo restanti.
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$page_ops  = '';
				$page_ops .= $this->rect_fill( 0, $height - 88, $width, 88, $primary );
				$page_ops .= $this->rect_fill( 0, $height - 96, $width, 8, $secondary );
				$page_ops .= $this->text( $text_x, $height - 42, 14, $association_name, true, array( 1, 1, 1 ) );
				$page_ops .= $this->text( $text_x, $height - 66, 10, 'Conferma pagamento attivita - dettaglio dati modulo (pag. ' . ( $i + 1 ) . ')', false, array( 1, 1, 1 ) );
				$page_ops .= $this->text( 28, $height - 124, 10, 'ID registrazione: #' . (int) $ctx->registration_id, true );
				$page_ops .= $this->text( 360, $height - 124, 10, 'Partecipante: ' . $participant_name );

				$page_y = $height - 156;
				foreach ( $chunks[ $i ] as $item ) {
					$page_ops .= $this->render_activity_registration_item( $item, $page_y );
				}

				$page_ops .= $this->text( 28, 56, 8, 'Pagina aggiuntiva dati registrazione.', false, array( 0.36, 0.40, 0.46 ) );

				$pages[] = array(
					'width'    => $width,
					'height'   => $height,
					'commands' => $page_ops,
					'images'   => ! empty( $logo_image['path'] ) ? array(
						array(
							'path' => (string) $logo_image['path'],
							'x'    => $logo_x,
							'y'    => $logo_y,
							'w'    => $logo_w,
							'h'    => $logo_h,
						),
					) : array(),
				);
			}
		}

		return $pages;
	}

	/**
	 * Restituisce item strutturati per il rendering PDF del modulo.
	 *
	 * @param object $ctx Context registrazione.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_activity_registration_data_lines( $ctx ) {
		$items    = array();
		$sections = $this->get_activity_registration_summary_sections( $ctx );

		foreach ( $sections as $section ) {
			if ( empty( $section['rows'] ) ) {
				continue;
			}

			$items[] = array(
				't'    => 's',
				'text' => strtoupper( remove_accents( (string) $section['label'] ) ),
			);
			foreach ( $section['rows'] as $row ) {
				$label_text  = $this->pdf_single_line_text( (string) $row['label'] . ':', 56 );
				$value_lines = $this->wrap_text_lines( (string) $row['value'], 46 );

				if ( empty( $value_lines ) ) {
					$value_lines = array( '' );
				}

				$items[] = array(
					't'           => 'r',
					'label_text'  => $label_text,
					'value_lines' => array_values( $value_lines ),
				);
			}
			$items[] = array( 't' => 'e' );
		}

		return array_values( array_filter( $items ) );
	}

	/**
	 * Renderizza un item del riepilogo modulo e aggiorna la coordinata Y.
	 *
	 * @param array<string,mixed> $item Item da renderizzare.
	 * @param float               $y    Coordinata verticale corrente.
	 * @return string
	 */
	private function render_activity_registration_item( array $item, &$y ) {
		$ops  = '';
		$type = isset( $item['t'] ) ? (string) $item['t'] : 'r';

		if ( 's' === $type ) {
			$ops .= $this->text( 40, $y, 8.8, (string) ( $item['text'] ?? '' ), true );
			$y   -= 14;
			return $ops;
		}

		if ( 'r' === $type ) {
			$label_text = isset( $item['label_text'] ) ? (string) $item['label_text'] : (string) ( $item['label'] ?? '' );
			$value_lines = isset( $item['value_lines'] ) && is_array( $item['value_lines'] ) ? $item['value_lines'] : array( '' );
			$row_lines   = max( 1, count( $value_lines ) );
			$row_height  = ( 12 * $row_lines ) + 2;

			$label_x       = 40;
			$label_w       = 268;
			$value_x       = 320;
			$label_bg_rgb  = array( 0.89, 0.95, 1.0 );
			$label_bg_y    = $y - $row_height + 2;
			$label_bg_h    = $row_height;
			$ops          .= $this->rect_fill( $label_x, $label_bg_y, $label_w, $label_bg_h, $label_bg_rgb );
			$ops          .= $this->text( $label_x + 4, $y, 8.3, $label_text, true, array( 0.06, 0.24, 0.42 ) );

			for ( $line_index = 0; $line_index < $row_lines; $line_index++ ) {
				$current_y = $y - ( 12 * $line_index );
				if ( isset( $value_lines[ $line_index ] ) ) {
					$ops .= $this->text( $value_x, $current_y, 8.4, (string) $value_lines[ $line_index ] );
				}
			}

			$y -= $row_height;
			return $ops;
		}

		if ( 'e' === $type ) {
			$y -= 8;
			return $ops;
		}

		$ops .= $this->text( 40, $y, 8.5, (string) ( $item['text'] ?? '' ) );
		$y   -= 12;
		return $ops;
	}

	/**
	 * Suddivide gli item in pagine rispettando la capienza disponibile.
	 *
	 * @param array<int,array<string,mixed>> $items Item del riepilogo.
	 * @param int                             $first_page_capacity Capienza prima pagina.
	 * @param int                             $next_page_capacity Capienza pagine successive.
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	private function paginate_activity_registration_items( array $items, $first_page_capacity, $next_page_capacity ) {
		$pages    = array();
		$current  = array();
		$used     = 0;
		$capacity = (int) $first_page_capacity;

		foreach ( $items as $item ) {
			$item_height = $this->get_activity_registration_item_height( $item );
			if ( ! empty( $current ) && ( $used + $item_height ) > $capacity ) {
				$pages[]  = $current;
				$current  = array();
				$used     = 0;
				$capacity = (int) $next_page_capacity;
			}

			$current[] = $item;
			$used     += $item_height;
		}

		if ( ! empty( $current ) ) {
			$pages[] = $current;
		}

		if ( empty( $pages ) ) {
			$pages[] = array();
		}

		return $pages;
	}

	/**
	 * Restituisce l'altezza verticale usata da un item nel PDF.
	 *
	 * @param array<string,mixed> $item Item del riepilogo.
	 * @return int
	 */
	private function get_activity_registration_item_height( array $item ) {
		$type = isset( $item['t'] ) ? (string) $item['t'] : 'r';

		if ( 's' === $type ) {
			return 14;
		}

		if ( 'r' === $type ) {
			$value_count = 1;
			if ( isset( $item['value_lines'] ) && is_array( $item['value_lines'] ) ) {
				$value_count = max( 1, count( $item['value_lines'] ) );
			}

			return ( 12 * $value_count ) + 2;
		}

		if ( 'e' === $type ) {
			return 8;
		}

		return 12;
	}

	/**
	 * Restituisce le sezioni ordinate con etichette e valori formattati come nel modulo.
	 *
	 * @param object $ctx Context registrazione.
	 * @return array<int,array{key:string,label:string,order:int,rows:array<int,array{label:string,value:string}>}>
	 */
	private function get_activity_registration_summary_sections( $ctx ) {
		$raw = isset( $ctx->registration_data ) ? (string) $ctx->registration_data : '';
		if ( '' === $raw ) {
			return array();
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return array();
		}

		$activity      = null;
		$form_fields   = array();
		$layout_order  = array();
		$section_ranks = array();
		if ( class_exists( 'SD_Activity_Manager' ) ) {
			$activity = SD_Activity_Manager::get_instance()->get_activity( (int) ( $ctx->activity_id ?? 0 ) );
			if ( is_array( $activity ) ) {
				$form_fields  = isset( $activity['form_fields'] ) && is_array( $activity['form_fields'] ) ? $activity['form_fields'] : array();
				$layout_order = isset( $activity['section_layout_order'] ) && is_array( $activity['section_layout_order'] ) ? $activity['section_layout_order'] : array();
			}
		}

		foreach ( $layout_order as $index => $section_key ) {
			$section_key = sanitize_key( (string) $section_key );
			if ( '' !== $section_key && ! isset( $section_ranks[ $section_key ] ) ) {
				$section_ranks[ $section_key ] = $index + 1;
			}
		}

		$sections      = array();
		$processed     = array();
		$consent_flags = $this->extract_activity_consent_flags( $data );

		foreach ( $form_fields as $field ) {
			$key        = sanitize_key( (string) ( $field['field_name'] ?? '' ) );
			$field_type = sanitize_key( (string) ( $field['field_type'] ?? 'text' ) );
			if ( '' === $key || in_array( $field_type, array( 'content', 'image', 'info' ), true ) ) {
				continue;
			}

			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$label = $this->normalize_activity_registration_label( (string) ( $field['field_label'] ?? '' ), $key );
			if ( $this->is_activity_consent_container_field( $key, $label ) ) {
				$processed[ $key ] = true;
				continue;
			}

			$value = $this->format_activity_registration_value( $data[ $key ], $field, $key, $consent_flags );
			$processed[ $key ] = true;
			if ( '' === trim( $value ) ) {
				continue;
			}

			$this->append_activity_summary_row(
				$sections,
				sanitize_key( (string) ( $field['section_key'] ?? 'additional' ) ),
				(string) ( $field['section_label'] ?? __( 'Informazioni aggiuntive', 'sd-logbook' ) ),
				intval( $field['section_order'] ?? 20 ),
				$section_ranks,
				$label,
				$value
			);
		}

		if ( ! empty( $data['birth_date'] ) && empty( $processed['birth_date'] ) ) {
			$this->append_activity_summary_row( $sections, 'personal', __( 'Dati personali', 'sd-logbook' ), 10, $section_ranks, __( 'Data di nascita', 'sd-logbook' ), $this->format_activity_registration_value( $data['birth_date'], array(), 'birth_date', $consent_flags ) );
		}
		if ( array_key_exists( 'is_minor', $data ) && empty( $processed['is_minor'] ) ) {
			$this->append_activity_summary_row( $sections, 'personal', __( 'Dati personali', 'sd-logbook' ), 10, $section_ranks, __( 'Minorenne', 'sd-logbook' ), $this->format_activity_registration_value( $data['is_minor'], array(), 'is_minor', $consent_flags ) );
		}
		if ( ! empty( $data['selected_price_names'] ) ) {
			$this->append_activity_summary_row( $sections, 'pricing', __( 'Selezione tariffa', 'sd-logbook' ), 30, $section_ranks, __( 'Tariffe selezionate', 'sd-logbook' ), $this->format_activity_registration_value( $data['selected_price_names'], array(), 'selected_price_names', $consent_flags ) );
		}

		if ( $consent_flags['ok1'] ) {
			$this->append_activity_summary_row( $sections, 'consents', __( 'Consensi', 'sd-logbook' ), 40, $section_ranks, __( 'Accettazione consenso: Informazioni e consenso a tutela della privacy', 'sd-logbook' ), __( 'Ok', 'sd-logbook' ) );
		}
		if ( $consent_flags['ok2'] ) {
			$this->append_activity_summary_row( $sections, 'consents', __( 'Consensi', 'sd-logbook' ), 40, $section_ranks, __( 'Accettazione consenso: Categorie particolari di dati personali', 'sd-logbook' ), __( 'Ok', 'sd-logbook' ) );
		}

		foreach ( $data as $key => $value ) {
			$normalized_key = sanitize_key( (string) $key );
			if ( isset( $processed[ $normalized_key ] ) || in_array( $normalized_key, array( 'selected_price_ids', 'selected_price_count' ), true ) ) {
				continue;
			}
			if ( $this->is_activity_consent_container_field( $normalized_key, '' ) ) {
				continue;
			}

			$label = $this->normalize_activity_registration_label( '', $normalized_key );
			$display = $this->format_activity_registration_value( $value, array(), $normalized_key, $consent_flags );
			if ( '' === trim( $display ) ) {
				continue;
			}

			$this->append_activity_summary_row( $sections, 'additional', __( 'Informazioni aggiuntive', 'sd-logbook' ), 20, $section_ranks, $label, $display );
		}

		usort(
			$sections,
			static function ( $left, $right ) {
				if ( (int) $left['rank'] !== (int) $right['rank'] ) {
					return (int) $left['rank'] - (int) $right['rank'];
				}
				if ( (int) $left['order'] !== (int) $right['order'] ) {
					return (int) $left['order'] - (int) $right['order'];
				}
				return strcmp( (string) $left['label'], (string) $right['label'] );
			}
		);

		return $sections;
	}

	/**
	 * Aggiunge una riga alla struttura riepilogo.
	 *
	 * @param array  $sections      Sezioni raccolte.
	 * @param string $section_key   Chiave sezione.
	 * @param string $section_label Etichetta sezione.
	 * @param int    $section_order Ordine sezione.
	 * @param array  $section_ranks Ordine esplicito layout.
	 * @param string $label         Etichetta riga.
	 * @param string $value         Valore riga.
	 * @return void
	 */
	private function append_activity_summary_row( array &$sections, $section_key, $section_label, $section_order, array $section_ranks, $label, $value ) {
		if ( '' === trim( (string) $value ) ) {
			return;
		}

		$key = '' !== sanitize_key( (string) $section_key ) ? sanitize_key( (string) $section_key ) : 'additional';
		if ( ! isset( $sections[ $key ] ) ) {
			$sections[ $key ] = array(
				'key'   => $key,
				'label' => '' !== trim( (string) $section_label ) ? (string) $section_label : __( 'Informazioni aggiuntive', 'sd-logbook' ),
				'order' => (int) $section_order,
				'rank'  => isset( $section_ranks[ $key ] ) ? (int) $section_ranks[ $key ] : 999,
				'rows'  => array(),
			);
		}

		$sections[ $key ]['rows'][] = array(
			'label' => (string) $label,
			'value' => (string) $value,
		);
	}

	/**
	 * Normalizza etichette note dei dati registrazione.
	 *
	 * @param string $label Etichetta raw.
	 * @param string $key   Chiave campo.
	 * @return string
	 */
	private function normalize_activity_registration_label( $label, $key ) {
		$normalized_key = sanitize_key( (string) $key );
		$clean_label    = sanitize_text_field( (string) $label );
		$map = array(
			'birth_date'          => __( 'Data di nascita', 'sd-logbook' ),
			'is_minor'            => __( 'Minorenne', 'sd-logbook' ),
			'luogo_di_nascita'    => __( 'Luogo di nascita', 'sd-logbook' ),
			'diabete_tipo'        => __( 'Tipo di diabete', 'sd-logbook' ),
			'celiachia'           => __( 'Celiachia', 'sd-logbook' ),
			'telefono_cellulare'  => __( 'Telefono cellulare', 'sd-logbook' ),
			'localita'            => __( 'Località', 'sd-logbook' ),
			'localit'             => __( 'Località', 'sd-logbook' ),
			'selected_price_names' => __( 'Tariffe selezionate', 'sd-logbook' ),
		);

		if ( isset( $map[ $normalized_key ] ) ) {
			return $map[ $normalized_key ];
		}

		if ( '' !== $clean_label ) {
			return $clean_label;
		}

		return ucfirst( str_replace( array( '_', '-' ), ' ', $normalized_key ) );
	}

	/**
	 * Formatta un valore registrazione usando le opzioni del campo quando disponibili.
	 *
	 * @param mixed  $value         Valore raw.
	 * @param array  $field         Definizione campo.
	 * @param string $key           Chiave campo.
	 * @param array  $consent_flags Flag consensi derivati.
	 * @return string
	 */
	private function format_activity_registration_value( $value, array $field, $key, array $consent_flags ) {
		$normalized_key = sanitize_key( (string) $key );

		if ( 'birth_date' === $normalized_key ) {
			$raw_date = trim( (string) $value );
			if ( '' === $raw_date ) {
				return '';
			}
			$timestamp = strtotime( $raw_date );
			return $timestamp ? date_i18n( 'd.m.Y', $timestamp ) : $raw_date;
		}

		if ( 'is_minor' === $normalized_key ) {
			return $this->is_truthy_registration_value( $value ) ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' );
		}

		if ( is_array( $value ) ) {
			if ( $this->is_activity_consent_container_field( $normalized_key, (string) ( $field['field_label'] ?? '' ) ) ) {
				return '';
			}
			$mapped = $this->map_activity_option_values_to_labels( $value, $field, $normalized_key );
			$mapped = array_values( array_filter( array_map( 'trim', $mapped ) ) );
			return implode( ', ', $mapped );
		}

		if ( $this->is_activity_consent_container_field( $normalized_key, (string) ( $field['field_label'] ?? '' ) ) ) {
			return '';
		}

		$mapped_scalar = $this->map_activity_option_values_to_labels( array( $value ), $field, $normalized_key );
		return trim( (string) ( $mapped_scalar[0] ?? '' ) );
	}

	/**
	 * Mappa valori campo a etichette opzione, con fallback per valori noti.
	 *
	 * @param array  $values Valori raw.
	 * @param array  $field  Definizione campo.
	 * @param string $key    Chiave campo.
	 * @return array
	 */
	private function map_activity_option_values_to_labels( array $values, array $field, $key ) {
		$options_map = array();
		foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$option_value = (string) ( $option['value'] ?? '' );
			if ( '' === $option_value ) {
				continue;
			}
			$options_map[ $option_value ] = (string) ( $option['label'] ?? $option_value );
		}

		$diabetes_map = array(
			't1'     => __( 'Tipo 1', 'sd-logbook' ),
			'tipo_1' => __( 'Tipo 1', 'sd-logbook' ),
			'tipo1'  => __( 'Tipo 1', 'sd-logbook' ),
			't2'     => __( 'Tipo 2', 'sd-logbook' ),
			'tipo_2' => __( 'Tipo 2', 'sd-logbook' ),
			'tipo2'  => __( 'Tipo 2', 'sd-logbook' ),
			't3c'    => __( 'Tipo 3C (Pancreasectomia, Pancreatite)', 'sd-logbook' ),
			'tipo_3c' => __( 'Tipo 3C (Pancreasectomia, Pancreatite)', 'sd-logbook' ),
			'tipo3c' => __( 'Tipo 3C (Pancreasectomia, Pancreatite)', 'sd-logbook' ),
		);

		$mapped = array();
		foreach ( $values as $value ) {
			$raw = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $raw ) {
				continue;
			}

			if ( isset( $options_map[ $raw ] ) ) {
				$mapped[] = $options_map[ $raw ];
				continue;
			}

			$normalized = sanitize_key( $raw );
			if ( 'diabete_tipo' === sanitize_key( (string) $key ) && isset( $diabetes_map[ $normalized ] ) ) {
				$mapped[] = $diabetes_map[ $normalized ];
				continue;
			}

			if ( $this->is_truthy_registration_value( $raw ) ) {
				$mapped[] = __( 'Ok', 'sd-logbook' );
				continue;
			}

			if ( in_array( strtolower( $raw ), array( 'si', 'sì', 'yes', 'no', 'true', 'false' ), true ) ) {
				$mapped[] = $this->is_truthy_registration_value( $raw ) ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' );
				continue;
			}

			$mapped[] = $raw;
		}

		return $mapped;
	}

	/**
	 * Estrae i flag consenso ok1/ok2 dai dati registrazione.
	 *
	 * @param array $data Dati registrazione.
	 * @return array{ok1:bool,ok2:bool}
	 */
	private function extract_activity_consent_flags( array $data ) {
		$flags = array(
			'ok1' => false,
			'ok2' => false,
		);

		foreach ( $data as $key => $value ) {
			$normalized_key = sanitize_key( (string) $key );
			if ( 'ok1' === $normalized_key ) {
				$flags['ok1'] = $flags['ok1'] || $this->is_truthy_registration_value( $value );
				continue;
			}
			if ( 'ok2' === $normalized_key ) {
				$flags['ok2'] = $flags['ok2'] || $this->is_truthy_registration_value( $value );
				continue;
			}

			if ( is_array( $value ) ) {
				$normalized_values = array_map(
					static function ( $item ) {
						return sanitize_key( (string) $item );
					},
					$value
				);
				$flags['ok1'] = $flags['ok1'] || in_array( 'ok1', $normalized_values, true );
				$flags['ok2'] = $flags['ok2'] || in_array( 'ok2', $normalized_values, true );
			}
		}

		return $flags;
	}

	/**
	 * Riconosce il contenitore raw dei consensi nel modulo.
	 *
	 * @param string $key   Chiave campo.
	 * @param string $label Etichetta campo.
	 * @return bool
	 */
	private function is_activity_consent_container_field( $key, $label ) {
		$normalized_key   = sanitize_key( (string) $key );
		$normalized_label = sanitize_key( remove_accents( (string) $label ) );

		if ( false !== strpos( $normalized_key, 'accettazione_consenso' ) ) {
			return true;
		}

		return false !== strpos( $normalized_label, 'accettazione_consenso' );
	}

	/**
	 * Valuta se un valore registrazione rappresenta un boolean true.
	 *
	 * @param mixed $value Valore raw.
	 * @return bool
	 */
	private function is_truthy_registration_value( $value ) {
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}

		$raw = strtolower( trim( (string) $value ) );
		return in_array( $raw, array( '1', 'true', 'yes', 'ok', 'si', 'sì' ), true );
	}

	/**
	 * Costruisce la pagina PDF della fattura attività.
	 *
	 * @param object $ctx Context registrazione.
	 * @return array
	 */
	private function build_activity_invoice_page( $ctx ) {
		$width              = 595.0;
		$height             = 842.0;
		$primary            = $this->hex_to_rgb( get_option( 'sd_payment_brand_primary', '#0055A5' ) );
		$secondary          = $this->hex_to_rgb( get_option( 'sd_payment_brand_secondary', '#00A3D8' ) );
		$association_name   = (string) get_option( 'sd_payment_invoice_association_name', get_option( 'sd_payment_association_title', 'Associazione ScubaDiabetes' ) );
		$association_addr   = (string) get_option( 'sd_payment_invoice_association_address', '' );
		$association_postal = (string) get_option( 'sd_payment_invoice_association_postal_code', '' );
		$association_city   = (string) get_option( 'sd_payment_invoice_association_city', '' );
		$association_email  = (string) get_option( 'sd_payment_invoice_association_email', get_bloginfo( 'admin_email' ) );
		$association_phone  = (string) get_option( 'sd_payment_invoice_association_phone', '' );

		$bank_name    = (string) get_option( 'sd_payment_invoice_bank_name', '' );
		$bank_addr    = (string) get_option( 'sd_payment_invoice_bank_address', '' );
		$bank_postal  = (string) get_option( 'sd_payment_invoice_bank_postal_code', '' );
		$bank_city    = (string) get_option( 'sd_payment_invoice_bank_city', '' );
		$bank_iban    = (string) get_option( 'sd_payment_invoice_bank_iban', '' );
		$bank_swift   = (string) get_option( 'sd_payment_invoice_bank_swift', '' );
		$bank_bic     = (string) get_option( 'sd_payment_invoice_bank_bic', '' );
		$qr_payload   = (string) get_option( 'sd_payment_invoice_qr_payload', '' );
		$qr_image_url = (string) get_option( 'sd_payment_invoice_qr_image_url', '' );
		$qr_image     = $this->resolve_qr_image_for_pdf( $qr_image_url );

		$participant_name = trim( (string) $ctx->first_name . ' ' . (string) $ctx->last_name );
		$activity_title   = (string) ( $ctx->activity_title ?? '' );
		$activity_date    = ! empty( $ctx->activity_start_date ) ? date_i18n( 'd.m.Y', strtotime( (string) $ctx->activity_start_date ) ) : '';
		$price_name       = (string) ( $ctx->price_name ?? '' );
		$invoice_no       = sprintf( 'INV-ACT-%s-%06d-%04d', gmdate( 'Y' ), (int) $ctx->activity_id, (int) $ctx->registration_id );
		$amount_chf       = 'CHF ' . number_format( (float) $ctx->price_chf, 2, '.', '' );
		$amount_eur       = 'EUR ' . number_format( (float) $ctx->price_eur, 2, '.', '' );

		// Logo.
		$logo_url   = 'https://scubadiabetes.ch/wp-content/uploads/2026/04/scubadiabetes_radius60.png';
		$logo_bg    = array(
			(int) round( $primary[0] * 255 ),
			(int) round( $primary[1] * 255 ),
			(int) round( $primary[2] * 255 ),
		);
		$logo_image = $this->resolve_qr_image_for_pdf( $logo_url, $logo_bg );

		$logo_h = 76;
		$logo_w = 76;
		$logo_x = 28;
		$logo_y = $height - 88 + (int) round( ( 88 - $logo_h ) / 2 );
		$text_x = 120;
		if ( ! empty( $logo_image['path'] ) ) {
			$logo_meta = @getimagesize( (string) $logo_image['path'] );
			if ( is_array( $logo_meta ) && ! empty( $logo_meta[1] ) && (int) $logo_meta[1] > 0 ) {
				$logo_w = (int) round( 76 * (int) $logo_meta[0] / (int) $logo_meta[1] );
				$text_x = $logo_x + $logo_w + 12;
			}
		}

		$ops  = '';
		$ops .= $this->rect_fill( 0, $height - 88, $width, 88, $primary );
		$ops .= $this->rect_fill( 0, $height - 96, $width, 8, $secondary );
		$ops .= $this->text( $text_x, $height - 42, 17, $association_name, true, array( 1, 1, 1 ) );
		$ops .= $this->text( $text_x, $height - 66, 11, 'Fattura iscrizione attività', false, array( 1, 1, 1 ) );

		$ops .= $this->text( 28, $height - 124, 10, 'Numero fattura: ' . $invoice_no, true );
		$ops .= $this->text( 360, $height - 124, 10, 'Data emissione: ' . gmdate( 'd.m.Y' ), false );

		// Box intestatario + attività.
		$ops .= $this->rect_stroke( 28, $height - 262, 539, 104, array( 0.82, 0.84, 0.88 ) );
		$ops .= $this->text( 40, $height - 176, 11, 'Intestatario fattura', true );
		$ops .= $this->text( 40, $height - 196, 10, 'Partecipante: ' . $participant_name );
		$ops .= $this->text( 40, $height - 212, 10, 'Email: ' . (string) $ctx->email );
		$ops .= $this->text( 40, $height - 228, 10, 'Attività: ' . $activity_title );
		if ( '' !== $activity_date ) {
			$ops .= $this->text( 40, $height - 244, 10, 'Data attività: ' . $activity_date );
		}
		if ( '' !== $price_name ) {
			$ops .= $this->text( 320, $height - 212, 10, 'Tariffa: ' . $price_name );
		}
		$ops .= $this->text( 320, $height - 196, 10, 'Importo dovuto: ' . $amount_chf, true );
		$ops .= $this->text( 320, $height - 228, 10, '(' . $amount_eur . ')' );

		$ops .= $this->text( 28, $height - 288, 11, 'Dati associazione', true );
		$ops .= $this->text( 28, $height - 308, 9.5, $association_name );
		$ops .= $this->text( 28, $height - 324, 9.5, trim( $association_addr . ' - ' . $association_postal . ' ' . $association_city, ' -' ) );
		$ops .= $this->text( 28, $height - 340, 9.5, 'Email: ' . $association_email . ' | Tel: ' . $association_phone );

		$ops .= $this->rect_fill( 28, $height - 576, 539, 210, array( 0.96, 0.97, 0.99 ) );
		$ops .= $this->rect_stroke( 28, $height - 576, 539, 210, array( 0.82, 0.84, 0.88 ) );
		$ops .= $this->text( 40, $height - 392, 11, 'Coordinate bancarie per pagamento fattura', true );
		$ops .= $this->text( 40, $height - 414, 10, 'Banca: ' . $bank_name );
		$ops .= $this->text( 40, $height - 432, 10, 'Indirizzo banca: ' . trim( $bank_addr . ' - ' . $bank_postal . ' ' . $bank_city, ' -' ) );
		$ops .= $this->text( 40, $height - 450, 10, 'IBAN: ' . $bank_iban, true );
		$ops .= $this->text( 40, $height - 468, 10, 'SWIFT: ' . $bank_swift . ' | BIC: ' . $bank_bic );
		$ops .= $this->text( 40, $height - 486, 10, 'Causale: Iscrizione ' . $activity_title . ' - ' . $participant_name );

		$ops .= $this->text( 40, $height - 516, 10, 'QR pagamento', true );
		if ( '' !== trim( $qr_payload ) ) {
			foreach ( $this->wrap_text_lines( 'Payload QR: ' . $qr_payload, 88 ) as $idx => $line ) {
				$ops .= $this->text( 40, $height - 534 - ( $idx * 13 ), 8.5, $line );
			}
		} else {
			$ops .= $this->text( 40, $height - 534, 8.5, 'Payload QR non configurato nelle impostazioni.' );
		}

		$images = array();
		if ( ! empty( $logo_image['path'] ) ) {
			$images[] = array(
				'path' => (string) $logo_image['path'],
				'x'    => $logo_x,
				'y'    => $logo_y,
				'w'    => $logo_w,
				'h'    => $logo_h,
			);
		}
		if ( ! empty( $qr_image['path'] ) ) {
			$ops      .= $this->rect_stroke( 430, $height - 560, 120, 120, array( 0.82, 0.84, 0.88 ) );
			$images[] = array(
				'path' => (string) $qr_image['path'],
				'x'    => 430,
				'y'    => $height - 560,
				'w'    => 120,
				'h'    => 120,
			);
		}

		$ops .= $this->text( 40, 56, 8, 'Stato fattura: IN ATTESA DI PAGAMENTO. Iscrizione confermata al ricevimento accredito.', false, array( 0.36, 0.40, 0.46 ) );

		return array(
			'width'    => $width,
			'height'   => $height,
			'commands' => $ops,
			'images'   => $images,
		);
	}

	/**
	 * Generatore PDF minimale con testo e forme (no dipendenze esterne).
	 *
	 * @param string $file_path Percorso output.
	 * @param array  $pages Lista pagine con width/height/commands.
	 * @return void
	 */
	private function write_styled_pdf( $file_path, $pages ) {
		$objects = array();
		$offsets = array();

		$add_obj = static function ( $content ) use ( &$objects ) {
			$objects[] = $content;
			return count( $objects );
		};

		$font_obj      = $add_obj( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>' );
		$font_bold_obj = $add_obj( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>' );
		$pages_id = $add_obj( '<< /Type /Pages /Kids [] /Count 0 >>' );

		$page_ids = array();
		foreach ( $pages as $page ) {
			$width  = (float) $page['width'];
			$height = (float) $page['height'];
			$stream = (string) $page['commands'];
			$images = ! empty( $page['images'] ) && is_array( $page['images'] ) ? $page['images'] : array();

			$xobject_entries = array();
			$draw_images     = '';
			foreach ( array_values( $images ) as $idx => $image ) {
				$img_path = isset( $image['path'] ) ? (string) $image['path'] : '';
				if ( '' === $img_path || ! file_exists( $img_path ) ) {
					continue;
				}

				$img_meta = @getimagesize( $img_path );
				if ( ! is_array( $img_meta ) || empty( $img_meta[0] ) || empty( $img_meta[1] ) || IMAGETYPE_JPEG !== (int) $img_meta[2] ) {
					continue;
				}

				$img_bytes = file_get_contents( $img_path );
				if ( false === $img_bytes ) {
					continue;
				}

				$img_width_px  = (int) $img_meta[0];
				$img_height_px = (int) $img_meta[1];
				$img_name      = 'Im' . ( $idx + 1 );
				$img_hex       = strtoupper( bin2hex( $img_bytes ) ) . '>';

				$img_obj = $add_obj(
					"<< /Type /XObject /Subtype /Image /Width {$img_width_px} /Height {$img_height_px} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter [/ASCIIHexDecode /DCTDecode] /Length " . strlen( $img_hex ) . " >>\nstream\n" . $img_hex . "\nendstream"
				);

				$xobject_entries[] = '/' . $img_name . ' ' . $img_obj . ' 0 R';
				$draw_images      .= sprintf(
					"q\n%.2f 0 0 %.2f %.2f %.2f cm\n/%s Do\nQ\n",
					(float) ( $image['w'] ?? 120 ),
					(float) ( $image['h'] ?? 120 ),
					(float) ( $image['x'] ?? 0 ),
					(float) ( $image['y'] ?? 0 ),
					$img_name
				);
			}

			if ( '' !== $draw_images ) {
				$stream .= $draw_images;
			}

			$content_obj = $add_obj( '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream" );
			$resources   = "<< /Font << /F1 {$font_obj} 0 R /F2 {$font_bold_obj} 0 R >>";
			if ( ! empty( $xobject_entries ) ) {
				$resources .= ' /XObject << ' . implode( ' ', $xobject_entries ) . ' >>';
			}
			$resources .= ' >>';
			$page_obj = $add_obj( "<< /Type /Page /Parent {$pages_id} 0 R /MediaBox [0 0 {$width} {$height}] /Resources {$resources} /Contents {$content_obj} 0 R >>" );
			$page_ids[]  = $page_obj;
		}

		$kids = array();
		foreach ( $page_ids as $pid ) {
			$kids[] = $pid . ' 0 R';
		}
		$objects[ $pages_id - 1 ] = '<< /Type /Pages /Kids [ ' . implode( ' ', $kids ) . ' ] /Count ' . count( $page_ids ) . ' >>';

		$catalog_id = $add_obj( "<< /Type /Catalog /Pages {$pages_id} 0 R >>" );

		$pdf = "%PDF-1.4\n";
		$obj_count = count( $objects );
		for ( $i = 0; $i < $obj_count; $i++ ) {
			$offsets[ $i + 1 ] = strlen( $pdf );
			$pdf              .= ( $i + 1 ) . " 0 obj\n" . $objects[ $i ] . "\nendobj\n";
		}

		$xref_offset = strlen( $pdf );
		$pdf        .= "xref\n";
		$pdf        .= '0 ' . ( $obj_count + 1 ) . "\n";
		$pdf        .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= $obj_count; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}

		$pdf .= "trailer\n";
		$pdf .= '<< /Size ' . ( $obj_count + 1 ) . ' /Root ' . $catalog_id . " 0 R >>\n";
		$pdf .= "startxref\n" . $xref_offset . "\n%%EOF";

		file_put_contents( $file_path, $pdf );
	}

	/**
	 * Comando testo PDF.
	 *
	 * @param float $x coordinata x.
	 * @param float $y coordinata y.
	 * @param float $size font size.
	 * @param string $text testo.
	 * @param bool $bold bold.
	 * @param array $rgb colore 0..1.
	 * @return string
	 */
	private function text( $x, $y, $size, $text, $bold = false, $rgb = array( 0, 0, 0 ) ) {
		$font = $bold ? 'F2' : 'F1';
		$line = $this->escape_pdf_text( $text );
		return sprintf( "%.3f %.3f %.3f rg\nBT\n/%s %.2f Tf\n1 0 0 1 %.2f %.2f Tm\n(%s) Tj\nET\n", $rgb[0], $rgb[1], $rgb[2], $font, $size, $x, $y, $line );
	}

	/**
	 * Rettangolo pieno.
	 *
	 * @param float $x x.
	 * @param float $y y.
	 * @param float $w width.
	 * @param float $h height.
	 * @param array $rgb colore.
	 * @return string
	 */
	private function rect_fill( $x, $y, $w, $h, $rgb ) {
		return sprintf( "%.3f %.3f %.3f rg\n%.2f %.2f %.2f %.2f re\nf\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h );
	}

	/**
	 * Percorso PDF rettangolo con angoli arrotondati (senza fill/stroke, per clip o riuso).
	 *
	 * @param float $x x.
	 * @param float $y y.
	 * @param float $w width.
	 * @param float $h height.
	 * @param float $r corner radius.
	 * @return string
	 */
	private function rounded_rect_clip_path( $x, $y, $w, $h, $r ) {
		$k  = 0.5523;
		$kr = $k * $r;
		// path: moveto bottom-left-corner, then clockwise bezier arcs at each corner.
		$path  = sprintf( "%.2f %.2f m\n", $x + $r, $y );
		$path .= sprintf( "%.2f %.2f l\n", $x + $w - $r, $y );
		$path .= sprintf( "%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x + $w - $r + $kr, $y, $x + $w, $y + $r - $kr, $x + $w, $y + $r );
		$path .= sprintf( "%.2f %.2f l\n", $x + $w, $y + $h - $r );
		$path .= sprintf( "%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x + $w, $y + $h - $r + $kr, $x + $w - $r + $kr, $y + $h, $x + $w - $r, $y + $h );
		$path .= sprintf( "%.2f %.2f l\n", $x + $r, $y + $h );
		$path .= sprintf( "%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x + $r - $kr, $y + $h, $x, $y + $h - $r + $kr, $x, $y + $h - $r );
		$path .= sprintf( "%.2f %.2f l\n", $x, $y + $r );
		$path .= sprintf( "%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x, $y + $r - $kr, $x + $r - $kr, $y, $x + $r, $y );
		$path .= "h\n";
		return $path;
	}

	/**
	 * Rettangolo riempito con angoli arrotondati.
	 *
	 * @param float $x x.
	 * @param float $y y.
	 * @param float $w width.
	 * @param float $h height.
	 * @param float $r radius.
	 * @param array $rgb colore fill.
	 * @return string
	 */
	private function rounded_rect_fill( $x, $y, $w, $h, $r, $rgb ) {
		return sprintf( "%.3f %.3f %.3f rg\n", $rgb[0], $rgb[1], $rgb[2] )
			. $this->rounded_rect_clip_path( $x, $y, $w, $h, $r )
			. "f\n";
	}

	/**
	 * Rettangolo bordo con angoli arrotondati.
	 *
	 * @param float $x x.
	 * @param float $y y.
	 * @param float $w width.
	 * @param float $h height.
	 * @param float $r radius.
	 * @param array $rgb colore bordo.
	 * @param float $line_width spessore linea.
	 * @return string
	 */
	private function rounded_rect_stroke( $x, $y, $w, $h, $r, $rgb, $line_width = 0.8 ) {
		return sprintf( "%.3f %.3f %.3f RG\n%.2f w\n", $rgb[0], $rgb[1], $rgb[2], $line_width )
			. $this->rounded_rect_clip_path( $x, $y, $w, $h, $r )
			. "S\n";
	}

	/**
	 * Rettangolo bordo.
	 *
	 * @param float $x x.
	 * @param float $y y.
	 * @param float $w width.
	 * @param float $h height.
	 * @param array $rgb colore.
	 * @return string
	 */
	private function rect_stroke( $x, $y, $w, $h, $rgb ) {
		return sprintf( "%.3f %.3f %.3f RG\n0.8 w\n%.2f %.2f %.2f %.2f re\nS\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h );
	}

	/**
	 * Escaping testo PDF.
	 *
	 * @param string $text testo.
	 * @return string
	 */
	private function escape_pdf_text( $text ) {
		$text = remove_accents( (string) $text );
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\(', '\)' ), $text );
	}

	/**
	 * Converte colore esadecimale in rgb normalizzato.
	 *
	 * @param string $hex colore.
	 * @return array
	 */
	private function hex_to_rgb( $hex ) {
		$hex = strtoupper( trim( (string) $hex ) );
		if ( ! preg_match( '/^#[A-F0-9]{6}$/', $hex ) ) {
			$hex = '#0055A5';
		}
		return array(
			hexdec( substr( $hex, 1, 2 ) ) / 255,
			hexdec( substr( $hex, 3, 2 ) ) / 255,
			hexdec( substr( $hex, 5, 2 ) ) / 255,
		);
	}

	/**
	 * Tronca testo a una sola riga aggiungendo ellissi se necessario.
	 *
	 * @param string $text Testo.
	 * @param int    $max_chars Lunghezza massima.
	 * @return string
	 */
	private function pdf_single_line_text( $text, $max_chars = 56 ) {
		$clean = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		$max   = max( 1, (int) $max_chars );
		if ( '' === $clean ) {
			return '';
		}

		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $clean ) : strlen( $clean );
		if ( $len <= $max ) {
			return $clean;
		}

		$slice_len = max( 1, $max - 1 );
		$slice     = function_exists( 'mb_substr' ) ? mb_substr( $clean, 0, $slice_len ) : substr( $clean, 0, $slice_len );
		return rtrim( (string) $slice ) . '...';
	}

	/**
	 * Spezza testo in righe semplici.
	 *
	 * @param string $text testo.
	 * @param int    $max_chars max caratteri.
	 * @return array
	 */
	private function wrap_text_lines( $text, $max_chars = 90 ) {
		$clean = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( '' === $clean ) {
			return array();
		}
		$wrapped = wordwrap( $clean, (int) $max_chars, "\n", true );
		return explode( "\n", $wrapped );
	}

	/**
	 * Restituisce l'etichetta leggibile del metodo di pagamento.
	 *
	 * @param string $slug Slug del metodo.
	 * @return string
	 */
	private function payment_method_label( $slug ) {
		$labels = array(
			'bonifico_iban' => 'Bonifico IBAN',
			'paypal'        => 'PayPal',
			'twint'         => 'TWINT',
			'carta_credito' => 'Carta di credito / debito',
			'apple_pay'     => 'Apple Pay',
			'google_pay'    => 'Google Pay',
			'fattura'       => 'Fattura',
			'famigliare'    => 'Famigliare',
			'staff'         => 'Staff',
		);
		return $labels[ $slug ] ?? ( '' !== $slug ? $slug : 'n/d' );
	}

	/**
	 * Numero ricevuta univoco leggibile.
	 *
	 * @param object $member socio.
	 * @param object $payment pagamento.
	 * @return string
	 */
	private function receipt_number( $member, $payment ) {
		$year = ! empty( $payment->payment_year ) ? (string) $payment->payment_year : gmdate( 'Y' );
		$pid  = ! empty( $payment->id ) ? (int) $payment->id : 0;
		$mid  = ! empty( $member->id ) ? (int) $member->id : 0;
		return sprintf( 'RCV-%s-%06d-%04d', $year, $mid, $pid );
	}

	/**
	 * Risolve immagine QR per embedding nel PDF (JPG diretto, PNG convertito).
	 *
	 * @param string $qr_image_url URL configurato nelle impostazioni.
	 * @return array{path:string}
	 */
	private function resolve_qr_image_for_pdf( $qr_image_url, $bg_rgb = array( 255, 255, 255 ) ) {
		$url = trim( (string) $qr_image_url );
		if ( '' === $url ) {
			return array();
		}

		$path = $this->map_upload_url_to_path( $url );
		if ( '' === $path || ! file_exists( $path ) ) {
			return array();
		}

		$meta = @getimagesize( $path );
		if ( ! is_array( $meta ) || empty( $meta[2] ) ) {
			return array();
		}

		if ( IMAGETYPE_JPEG === (int) $meta[2] ) {
			return array( 'path' => $path );
		}

		if ( IMAGETYPE_PNG === (int) $meta[2] ) {
			$jpg = $this->convert_png_to_jpeg( $path, $bg_rgb );
			if ( '' !== $jpg && file_exists( $jpg ) ) {
				return array( 'path' => $jpg );
			}
		}

		return array();
	}

	/**
	 * Converte URL media uploads in path locale.
	 *
	 * @param string $url URL media.
	 * @return string
	 */
	private function map_upload_url_to_path( $url ) {
		$upload = wp_upload_dir();
		$baseurl = isset( $upload['baseurl'] ) ? (string) $upload['baseurl'] : '';
		$basedir = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';

		if ( '' !== $baseurl && '' !== $basedir && 0 === strpos( $url, $baseurl ) ) {
			$relative = ltrim( substr( $url, strlen( $baseurl ) ), '/' );
			return trailingslashit( $basedir ) . $relative;
		}

		return '';
	}

	/**
	 * Converte PNG in JPG per il writer PDF minimale.
	 *
	 * @param string $png_path Percorso PNG.
	 * @return string
	 */
	private function convert_png_to_jpeg( $png_path, $bg_rgb = array( 255, 255, 255 ) ) {
		if ( ! function_exists( 'imagecreatefrompng' ) || ! function_exists( 'imagejpeg' ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}

		$src = @imagecreatefrompng( $png_path );
		if ( ! $src ) {
			return '';
		}

		$w = imagesx( $src );
		$h = imagesy( $src );
		if ( $w <= 0 || $h <= 0 ) {
			imagedestroy( $src );
			return '';
		}

		$bg_r = isset( $bg_rgb[0] ) ? max( 0, min( 255, (int) $bg_rgb[0] ) ) : 255;
		$bg_g = isset( $bg_rgb[1] ) ? max( 0, min( 255, (int) $bg_rgb[1] ) ) : 255;
		$bg_b = isset( $bg_rgb[2] ) ? max( 0, min( 255, (int) $bg_rgb[2] ) ) : 255;

		$dst = imagecreatetruecolor( $w, $h );
		$bg  = imagecolorallocate( $dst, $bg_r, $bg_g, $bg_b );
		imagefill( $dst, 0, 0, $bg );
		imagecopy( $dst, $src, 0, 0, 0, 0, $w, $h );

		$cache_dir = trailingslashit( dirname( $png_path ) ) . 'pdf-cache/';
		if ( ! wp_mkdir_p( $cache_dir ) ) {
			imagedestroy( $src );
			imagedestroy( $dst );
			return '';
		}

		$cache_key = md5( $png_path . '|' . (string) @filemtime( $png_path ) . '|' . $bg_r . ',' . $bg_g . ',' . $bg_b );
		$jpg_path  = $cache_dir . 'qr-' . $cache_key . '.jpg';
		$ok        = @imagejpeg( $dst, $jpg_path, 92 );

		imagedestroy( $src );
		imagedestroy( $dst );

		return $ok ? $jpg_path : '';
	}
}
