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

				$nascita = ! empty( $r['date_of_birth'] ) ? date_i18n( 'd.m.Y', strtotime( (string) $r['date_of_birth'] ) ) : '—';
				$scad    = ! empty( $r['membership_expiry'] ) ? date_i18n( 'd.m.Y', strtotime( (string) $r['membership_expiry'] ) ) : '—';
				$datapag = ! empty( $r['payment_date'] ) ? date_i18n( 'd.m.Y', strtotime( substr( (string) $r['payment_date'], 0, 10 ) ) ) : '—';
				$pagato  = ( ! empty( $r['has_paid_fee'] ) && (int) $r['has_paid_fee'] ) ? 'Si' : 'No';
				$metodo  = $method_labels[ $r['payment_method'] ?? '' ] ?? (string) ( $r['payment_method'] ?? '-' );
				$tassa   = ! empty( $r['fee_amount'] ) ? number_format( (float) $r['fee_amount'], 2 ) : '-';
				$name    = trim( (string) $r['last_name'] . ', ' . (string) $r['first_name'] );

				$cells = array(
					(string) ( $ri + 1 ),
					$name,
					(string) ( $r['email'] ?? '' ),
					$nascita,
					(string) ( $r['member_type'] ?? '' ),
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
