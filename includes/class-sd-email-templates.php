<?php
/**
 * Gestione Modelli Email
 *
 * Shortcode [sd_email_templates]: pagina CRUD per modelli email con variabili dinamiche.
 *
 * Variabili supportate nei template:
 *   {{data_oggi_breve}}     → DD.MM.YYYY
 *   {{data_oggi_estesa}}    → DD MMMM YYYY (es. 11 maggio 2026)
 *   {{mese_oggi}}           → nome mese corrente
 *   {{anno_oggi}}           → anno corrente (YYYY)
 *   {{anno_prossimo}}       → anno corrente + 1
 *   {{scadenza}}            → data scadenza iscrizione (DD.MM.YYYY)
 *   {{tipo_socio}}          → tipo iscrizione (es. individuale)
 *   {{tassa_sociale}}       → importo quota (CHF XX.XX)
 *   {{tassa_sociale_numero}}→ importo quota numerico (XX.XX)
 *   {{nome}}                → nome socio
 *   {{cognome}}             → cognome socio
 *   {{nome_completo}}       → nome + cognome socio
 *   {{email_socio}}         → email socio
 *   {{email_associazione}}  → email segretariato
 *   {{logo}}                → logo piccolo associazione
 *   {{logo_esteso}}         → logo esteso associazione
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Email_Templates {

	public function __construct() {
		add_shortcode( 'sd_email_templates', array( $this, 'render_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX
		add_action( 'wp_ajax_sd_email_tpl_list',   array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_sd_email_tpl_save',   array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_sd_email_tpl_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_sd_email_tpl_get',    array( $this, 'ajax_get' ) );
	}

	// =========================================================================
	// ACCESSO
	// =========================================================================

	private function check_access(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'sd_staff' );
	}

	// =========================================================================
	// ASSETS
	// =========================================================================

	public function enqueue_assets() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}
		if ( ! has_shortcode( $post->post_content, 'sd_email_templates' ) ) {
			return;
		}

		// CodeMirror (HTML mode) — bundled con WordPress >= 5.0
		if ( function_exists( 'wp_enqueue_editor' ) ) {
			wp_enqueue_editor();
		}

		wp_enqueue_style(
			'sd-email-templates',
			SD_LOGBOOK_PLUGIN_URL . 'assets/css/email-templates.css',
			array(),
			filemtime( SD_LOGBOOK_PLUGIN_DIR . 'assets/css/email-templates.css' )
		);

		wp_enqueue_script(
			'sd-email-templates',
			SD_LOGBOOK_PLUGIN_URL . 'assets/js/email-templates.js',
			array( 'jquery', 'editor' ),
			filemtime( SD_LOGBOOK_PLUGIN_DIR . 'assets/js/email-templates.js' ),
			true
		);

		wp_localize_script(
			'sd-email-templates',
			'sdEmailTpl',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sd_email_tpl_nonce' ),
				'vars'    => self::get_variable_list(),
				'strings' => array(
					'confirmDelete'  => __( 'Eliminare il modello selezionato?', 'sd-logbook' ),
					'saveSuccess'    => __( 'Modello salvato con successo.', 'sd-logbook' ),
					'deleteSuccess'  => __( 'Modello eliminato.', 'sd-logbook' ),
					'errorGeneric'   => __( 'Si è verificato un errore. Riprova.', 'sd-logbook' ),
					'unsavedChanges' => __( 'Ci sono modifiche non salvate. Continuare?', 'sd-logbook' ),
					'newTemplate'    => __( 'Nuovo modello', 'sd-logbook' ),
				),
			)
		);
	}

	// =========================================================================
	// VARIABILI SUPPORTATE
	// =========================================================================

	/**
	 * Elenco delle variabili per UI e documentazione.
	 */
	public static function get_variable_list(): array {
		return array(
			array( 'tag' => '{{data_oggi_breve}}',    'label' => __( 'Data oggi (DD.MM.YYYY)', 'sd-logbook' ) ),
			array( 'tag' => '{{data_oggi_estesa}}',   'label' => __( 'Data oggi (es. 11 maggio 2026)', 'sd-logbook' ) ),
			array( 'tag' => '{{mese_oggi}}',           'label' => __( 'Mese corrente', 'sd-logbook' ) ),
			array( 'tag' => '{{anno_oggi}}',           'label' => __( 'Anno corrente', 'sd-logbook' ) ),
			array( 'tag' => '{{anno_prossimo}}',       'label' => __( 'Anno prossimo', 'sd-logbook' ) ),
			array( 'tag' => '{{scadenza}}',            'label' => __( 'Scadenza iscrizione', 'sd-logbook' ) ),
			array( 'tag' => '{{tipo_socio}}',          'label' => __( 'Tipo socio', 'sd-logbook' ) ),
			array( 'tag' => '{{tassa_sociale}}',       'label' => __( 'Tassa sociale (CHF)', 'sd-logbook' ) ),
			array( 'tag' => '{{tassa_sociale_numero}}', 'label' => __( 'Tassa sociale (numero)', 'sd-logbook' ) ),
			array( 'tag' => '{{nome}}',                'label' => __( 'Nome socio', 'sd-logbook' ) ),
			array( 'tag' => '{{cognome}}',             'label' => __( 'Cognome socio', 'sd-logbook' ) ),
			array( 'tag' => '{{nome_completo}}',       'label' => __( 'Nome completo socio', 'sd-logbook' ) ),
			array( 'tag' => '{{email_socio}}',         'label' => __( 'E-mail socio', 'sd-logbook' ) ),
			array( 'tag' => '{{email_associazione}}',  'label' => __( 'E-mail associazione', 'sd-logbook' ) ),
			array( 'tag' => '{{logo}}',                'label' => __( 'Logo (piccolo)', 'sd-logbook' ) ),
			array( 'tag' => '{{logo_esteso}}',         'label' => __( 'Logo (esteso)', 'sd-logbook' ) ),
		);
	}

	// =========================================================================
	// RISOLUZIONE VARIABILI
	// =========================================================================

	/**
	 * Sostituisce le variabili {{...}} nel testo con i valori reali.
	 *
	 * @param string      $text   Testo con variabili.
	 * @param object|null $member Oggetto socio (opzionale, con first_name, last_name, email, membership_expiry, fee_amount, membership_type).
	 * @return string
	 */
	public static function resolve( string $text, ?object $member = null ): string {
		$months_it = array(
			1  => 'gennaio', 2  => 'febbraio', 3  => 'marzo',    4  => 'aprile',
			5  => 'maggio',  6  => 'giugno',   7  => 'luglio',   8  => 'agosto',
			9  => 'settembre', 10 => 'ottobre', 11 => 'novembre', 12 => 'dicembre',
		);

		$now   = new DateTime( 'now', new DateTimeZone( 'Europe/Zurich' ) );
		$month = (int) $now->format( 'n' );
		$year  = (int) $now->format( 'Y' );

		$today_breve  = $now->format( 'd.m.Y' );
		$today_estesa = $now->format( 'd' ) . ' ' . ( $months_it[ $month ] ?? $now->format( 'F' ) ) . ' ' . $year;
		$mese_oggi    = $months_it[ $month ] ?? $now->format( 'F' );
		$anno_oggi    = (string) $year;
		$anno_prox    = (string) ( $year + 1 );

		// Valori derivati dal socio
		$scadenza           = '';
		$tipo_socio         = '';
		$tassa_sociale      = '';
		$tassa_sociale_num  = '0.00';
		$nome               = '';
		$cognome            = '';
		$nome_completo      = '';
		$email_socio        = '';
		$email_associazione = (string) ( get_option( 'sd_secretariat_email' ) ?: get_option( 'admin_email' ) );

		if ( $member ) {
			if ( ! empty( $member->membership_expiry ) ) {
				$dt_exp = DateTime::createFromFormat( 'Y-m-d', (string) $member->membership_expiry );
				$scadenza = $dt_exp ? $dt_exp->format( 'd.m.Y' ) : (string) $member->membership_expiry;
			}
			$tipo_socio    = ucfirst( (string) ( $member->membership_type ?? '' ) );
			$tassa_sociale_num = number_format( (float) ( $member->fee_amount ?? 0 ), 2 );
			$tassa_sociale = 'CHF ' . $tassa_sociale_num;
			$nome          = (string) ( $member->first_name ?? '' );
			$cognome       = (string) ( $member->last_name ?? '' );
			$nome_completo = trim( $nome . ' ' . $cognome );
			$email_socio   = (string) ( $member->email ?? '' );
		}

		$map = array(
			'{{data_oggi_breve}}'   => $today_breve,
			'{{data_oggi_estesa}}'  => $today_estesa,
			'{{mese_oggi}}'         => $mese_oggi,
			'{{anno_oggi}}'         => $anno_oggi,
			'{{anno_prossimo}}'     => $anno_prox,
			'{{scadenza}}'          => $scadenza,
			'{{tipo_socio}}'        => $tipo_socio,
			'{{tassa_sociale}}'     => $tassa_sociale,
			'{{tassa_sociale_numero}}' => $tassa_sociale_num,
			'{{nome}}'              => $nome,
			'{{cognome}}'           => $cognome,
			'{{nome_completo}}'     => $nome_completo,
			'{{email_socio}}'       => $email_socio,
			'{{email_associazione}}' => $email_associazione,
			'{{logo}}'              => '<img src="' . site_url( '/wp-content/uploads/2026/04/cropped-cropped-ScubaDS-1.jpeg' ) . '" alt="Logo" style="max-height:80px;display:block;">',
			'{{logo_esteso}}'       => '<img src="' . site_url( '/wp-content/uploads/2026/04/scubadiabetes_radius60.png' ) . '" alt="Logo" style="max-height:120px;display:block;">',
		);

		return str_replace( array_keys( $map ), array_values( $map ), $text );
	}

	// =========================================================================
	// SHORTCODE
	// =========================================================================

	public function render_page( $atts ): string {
		if ( ! $this->check_access() ) {
			return '<div class="sd-notice sd-notice-error">'
				. esc_html__( 'Accesso negato. Questa pagina è riservata allo staff.', 'sd-logbook' )
				. '</div>';
		}

		ob_start();
		$vars = self::get_variable_list();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/email-templates.php';
		return ob_get_clean();
	}

	// =========================================================================
	// AJAX: LIST
	// =========================================================================

	public function ajax_list() {
		check_ajax_referer( 'sd_email_tpl_nonce', 'nonce' );
		if ( ! $this->check_access() ) {
			wp_send_json_error( array( 'message' => __( 'Accesso negato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db   = new SD_Database();
		$rows = $wpdb->get_results(
			"SELECT id, name, subject, created_at, updated_at
			 FROM {$db->table('email_templates')}
			 ORDER BY name ASC"
		);

		wp_send_json_success( array( 'templates' => $rows ?: array() ) );
	}

	// =========================================================================
	// AJAX: GET (singolo)
	// =========================================================================

	public function ajax_get() {
		check_ajax_referer( 'sd_email_tpl_nonce', 'nonce' );
		if ( ! $this->check_access() ) {
			wp_send_json_error( array( 'message' => __( 'Accesso negato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db = new SD_Database();
		$id = absint( $_POST['id'] ?? 0 );
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'ID non valido.', 'sd-logbook' ) ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('email_templates')} WHERE id = %d",
				$id
			)
		);

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Modello non trovato.', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'template' => $row ) );
	}

	// =========================================================================
	// AJAX: SAVE (create / update)
	// =========================================================================

	public function ajax_save() {
		check_ajax_referer( 'sd_email_tpl_nonce', 'nonce' );
		if ( ! $this->check_access() ) {
			wp_send_json_error( array( 'message' => __( 'Accesso negato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db = new SD_Database();

		// Garantisce schema aggiornato (tabella/colonne) anche su installazioni pregresse.
		$db->create_email_template_tables();

		$id        = absint( $_POST['id'] ?? 0 );
		$name      = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$subject   = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		// Il corpo e la firma sono HTML: usiamo wp_kses_post per sanificare ma preservare HTML
		$body      = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );
		$signature = wp_kses_post( wp_unslash( $_POST['signature'] ?? '' ) );

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Il nome del modello è obbligatorio.', 'sd-logbook' ) ) );
		}
		if ( empty( $subject ) ) {
			wp_send_json_error( array( 'message' => __( "L'oggetto è obbligatorio.", 'sd-logbook' ) ) );
		}
		if ( empty( $body ) ) {
			wp_send_json_error( array( 'message' => __( 'Il corpo del messaggio è obbligatorio.', 'sd-logbook' ) ) );
		}

		$data = array(
			'name'      => $name,
			'subject'   => $subject,
			'body'      => $body,
			'signature' => $signature,
		);
		$formats = array( '%s', '%s', '%s', '%s' );

		if ( $id > 0 ) {
			// Update
			$result = $wpdb->update(
				$db->table( 'email_templates' ),
				$data,
				array( 'id' => $id ),
				$formats,
				array( '%d' )
			);
			if ( false === $result ) {
				$detail = ! empty( $wpdb->last_error ) ? ' (' . $wpdb->last_error . ')' : '';
				wp_send_json_error( array( 'message' => __( 'Errore nel salvataggio.', 'sd-logbook' ) . $detail ) );
			}
		} else {
			// Insert
			$result = $wpdb->insert( $db->table( 'email_templates' ), $data, $formats );
			if ( ! $result ) {
				$detail = ! empty( $wpdb->last_error ) ? ' (' . $wpdb->last_error . ')' : '';
				wp_send_json_error( array( 'message' => __( 'Errore nel salvataggio.', 'sd-logbook' ) . $detail ) );
			}
			$id = (int) $wpdb->insert_id;
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'message' => __( 'Modello salvato con successo.', 'sd-logbook' ),
			)
		);
	}

	// =========================================================================
	// AJAX: DELETE
	// =========================================================================

	public function ajax_delete() {
		check_ajax_referer( 'sd_email_tpl_nonce', 'nonce' );
		if ( ! $this->check_access() ) {
			wp_send_json_error( array( 'message' => __( 'Accesso negato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db = new SD_Database();
		$id = absint( $_POST['id'] ?? 0 );

		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'ID non valido.', 'sd-logbook' ) ) );
		}

		$result = $wpdb->delete( $db->table( 'email_templates' ), array( 'id' => $id ), array( '%d' ) );
		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Errore durante l\'eliminazione.', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Modello eliminato.', 'sd-logbook' ) ) );
	}

	// =========================================================================
	// HELPER PUBBLICO: recupera template per ID
	// =========================================================================

	/**
	 * Carica un template dal DB e ne restituisce subject, body e signature già risolti.
	 *
	 * @param int         $template_id  ID del modello.
	 * @param object|null $member       Dati socio per la sostituzione variabili.
	 * @return array{subject:string, body:string, signature:string}|null
	 */
	public static function build( int $template_id, ?object $member = null ): ?array {
		global $wpdb;
		$db  = new SD_Database();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('email_templates')} WHERE id = %d",
				$template_id
			)
		);
		if ( ! $row ) {
			return null;
		}

		return array(
			'subject'   => self::resolve( (string) $row->subject, $member ),
			'body'      => self::resolve( (string) $row->body, $member ),
			'signature' => self::resolve( (string) $row->signature, $member ),
		);
	}

	/**
	 * Restituisce tutti i template come array semplice (id => name).
	 */
	public static function get_all_as_options(): array {
		global $wpdb;
		$db   = new SD_Database();
		$rows = $wpdb->get_results(
			"SELECT id, name FROM {$db->table('email_templates')} ORDER BY name ASC"
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->id ] = (string) $r->name;
		}
		return $out;
	}
}
