<?php
/**
 * Gestione Modelli Email
 *
 * Shortcode [sd_email_templates]: pagina CRUD per modelli email con supporto
 * a moduli iscrizione soci e moduli iscrizione attività.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Email_Templates {

	private const DEFAULT_MEMBERSHIP_FORM_KEY = 'membership:association';

	private const TEMPLATE_TYPES = array(
		'membership',
		'activity',
	);

	public function __construct() {
		add_shortcode( 'sd_email_templates', array( $this, 'render_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX
		add_action( 'wp_ajax_sd_email_tpl_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_sd_email_tpl_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_sd_email_tpl_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_sd_email_tpl_get', array( $this, 'ajax_get' ) );
		add_action( 'wp_ajax_sd_email_tpl_duplicate', array( $this, 'ajax_duplicate' ) );
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
		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		$forms = self::get_form_catalog();

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
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'sd_email_tpl_nonce' ),
				'forms'          => $forms,
				'tinymceAdvancedMceUrl' => plugins_url( 'mce/', WP_PLUGIN_DIR . '/tinymce-advanced/tinymce-advanced.php' ),
				'defaultFormKey' => self::get_default_form_key( $forms ),
				'strings' => array(
					'confirmDelete'  => __( 'Eliminare il modello selezionato?', 'sd-logbook' ),
					'confirmDuplicate' => __( 'Duplicare il modello selezionato?', 'sd-logbook' ),
					'saveSuccess'    => __( 'Modello salvato con successo.', 'sd-logbook' ),
					'deleteSuccess'  => __( 'Modello eliminato.', 'sd-logbook' ),
					'duplicateSuccess' => __( 'Modello duplicato con successo.', 'sd-logbook' ),
					'errorGeneric'   => __( 'Si è verificato un errore. Riprova.', 'sd-logbook' ),
					'unsavedChanges' => __( 'Ci sono modifiche non salvate. Continuare?', 'sd-logbook' ),
					'newTemplate'    => __( 'Nuovo modello', 'sd-logbook' ),
					'loading'        => __( 'Caricamento...', 'sd-logbook' ),
					'emptyTemplates' => __( 'Nessun modello salvato per questo modulo.', 'sd-logbook' ),
					'emptyVariables' => __( 'Seleziona un modulo per vedere le variabili disponibili.', 'sd-logbook' ),
					'incompatibleTemplate' => __( 'Questo template non è compatibile con il modulo selezionato.', 'sd-logbook' ),
				),
			)
		);
	}

	// =========================================================================
	// FORM E VARIABILI
	// =========================================================================

	/**
	 * Crea una definizione variabile coerente per UI, preview e compatibilità.
	 *
	 * @param string $name        Nome tecnico senza parentesi graffe.
	 * @param string $label       Etichetta breve.
	 * @param string $description Descrizione per la UI.
	 * @param string $sample      Valore di esempio per preview.
	 * @param string $group       Gruppo opzionale (es. 'medical').
	 * @return array<string,string>
	 */
	private static function make_variable( string $name, string $label, string $description, string $sample = '', string $group = '' ): array {
		$var = array(
			'tag'         => '{{' . sanitize_key( $name ) . '}}',
			'label'       => $label,
			'description' => $description,
			'sample'      => $sample,
		);
		if ( '' !== $group ) {
			$var['group'] = $group;
		}
		return $var;
	}

	/**
	 * Elenco delle variabili standard disponibili per tutti i moduli.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function get_standard_variables(): array {
		return array(
			self::make_variable( 'data_oggi_breve', __( 'Data oggi breve', 'sd-logbook' ), __( 'Data corrente in formato GG.MM.AAAA.', 'sd-logbook' ), '18.05.2026' ),
			self::make_variable( 'data_oggi_estesa', __( 'Data oggi estesa', 'sd-logbook' ), __( 'Data corrente in formato esteso italiano.', 'sd-logbook' ), '18 maggio 2026' ),
			self::make_variable( 'mese_oggi', __( 'Mese corrente', 'sd-logbook' ), __( 'Nome del mese corrente.', 'sd-logbook' ), 'maggio' ),
			self::make_variable( 'anno_oggi', __( 'Anno corrente', 'sd-logbook' ), __( 'Anno corrente in quattro cifre.', 'sd-logbook' ), '2026' ),
			self::make_variable( 'anno_prossimo', __( 'Anno prossimo', 'sd-logbook' ), __( 'Anno successivo a quello corrente.', 'sd-logbook' ), '2027' ),
			self::make_variable( 'email_associazione', __( 'E-mail associazione', 'sd-logbook' ), __( 'Indirizzo e-mail del segretariato/associazione.', 'sd-logbook' ), 'segreteria@scubadiabetes.ch' ),
			self::make_variable( 'telefono_associazione', __( 'Telefono associazione', 'sd-logbook' ), __( 'Numero di telefono dell’associazione, se configurato.', 'sd-logbook' ), '+41 79 000 00 00' ),
			self::make_variable( 'indirizzo_associazione', __( 'Indirizzo associazione', 'sd-logbook' ), __( 'Indirizzo postale dell’associazione, se configurato.', 'sd-logbook' ), 'Via Esempio 1, 6900 Lugano' ),
			self::make_variable( 'logo', __( 'Logo piccolo', 'sd-logbook' ), __( 'Logo associazione in formato HTML immagine.', 'sd-logbook' ), '<img src="logo-small.jpg" alt="Logo">' ),
			self::make_variable( 'logo_esteso', __( 'Logo esteso', 'sd-logbook' ), __( 'Logo esteso associazione in formato HTML immagine.', 'sd-logbook' ), '<img src="logo-large.jpg" alt="Logo">' ),
			self::make_variable( 'nome', __( 'Alias nome', 'sd-logbook' ), __( 'Alias compatibilità per il nome della persona iscritta.', 'sd-logbook' ), 'Mario' ),
			self::make_variable( 'cognome', __( 'Alias cognome', 'sd-logbook' ), __( 'Alias compatibilità per il cognome della persona iscritta.', 'sd-logbook' ), 'Rossi' ),
			self::make_variable( 'nome_completo', __( 'Alias nome completo', 'sd-logbook' ), __( 'Alias compatibilità per nome e cognome.', 'sd-logbook' ), 'Mario Rossi' ),
			self::make_variable( 'email_socio', __( 'Alias e-mail socio', 'sd-logbook' ), __( 'Alias compatibilità per l’e-mail della persona iscritta.', 'sd-logbook' ), 'mario.rossi@example.com' ),
			self::make_variable( 'scadenza', __( 'Scadenza iscrizione', 'sd-logbook' ), __( 'Data di scadenza dell’iscrizione del socio.', 'sd-logbook' ), '31.12.2026' ),
			self::make_variable( 'tipo_socio', __( 'Tipo socio', 'sd-logbook' ), __( 'Tipo socio leggibile (es. Attivo Capo Famiglia).', 'sd-logbook' ), 'Attivo' ),
			self::make_variable( 'tipo_iscrizione', __( 'Tipo iscrizione', 'sd-logbook' ), __( 'Tipo iscrizione/membership (es. individuale, famiglia).', 'sd-logbook' ), 'individuale' ),
			self::make_variable( 'tassa_sociale', __( 'Tassa/importo', 'sd-logbook' ), __( 'Importo formattato in CHF.', 'sd-logbook' ), 'CHF 50.00' ),
			self::make_variable( 'tassa_sociale_numero', __( 'Numero socio', 'sd-logbook' ), __( 'Numero o ID socio/registrazione.', 'sd-logbook' ), '1024' ),
		);
	}

	/**
	 * Variabili del modulo iscrizione associazione.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function get_membership_form_variables(): array {
		return array(
			self::make_variable( 'first_name', __( 'Nome', 'sd-logbook' ), __( 'Nome della persona che compila il modulo.', 'sd-logbook' ), 'Mario' ),
			self::make_variable( 'last_name', __( 'Cognome', 'sd-logbook' ), __( 'Cognome della persona che compila il modulo.', 'sd-logbook' ), 'Rossi' ),
			self::make_variable( 'date_of_birth', __( 'Data di nascita', 'sd-logbook' ), __( 'Data di nascita inserita nel modulo.', 'sd-logbook' ), '12.04.1990' ),
			self::make_variable( 'gender', __( 'Genere', 'sd-logbook' ), __( 'Genere selezionato nel modulo.', 'sd-logbook' ), 'M' ),
			self::make_variable( 'sotto_tutela', __( 'Sotto tutela legale', 'sd-logbook' ), __( 'Indica se la persona è sotto tutela legale.', 'sd-logbook' ), 'No' ),
			self::make_variable( 'birth_place', __( 'Luogo di nascita', 'sd-logbook' ), __( 'Luogo di nascita dichiarato.', 'sd-logbook' ), 'Lugano' ),
			self::make_variable( 'birth_country', __( 'Nazione di nascita', 'sd-logbook' ), __( 'Paese di nascita dichiarato.', 'sd-logbook' ), 'CH' ),
			self::make_variable( 'email', __( 'E-mail', 'sd-logbook' ), __( 'E-mail della persona iscritta.', 'sd-logbook' ), 'mario.rossi@example.com' ),
			self::make_variable( 'phone', __( 'Telefono', 'sd-logbook' ), __( 'Numero di telefono principale.', 'sd-logbook' ), '+41 79 000 00 00' ),
			self::make_variable( 'tshirt_size', __( 'Taglia maglietta', 'sd-logbook' ), __( 'Taglia maglietta selezionata.', 'sd-logbook' ), 'M' ),
			self::make_variable( 'diabetes_type', __( 'Tipo di diabete', 'sd-logbook' ), __( 'Tipo di diabete indicato nel modulo.', 'sd-logbook' ), 'Tipo 1' ),
			self::make_variable( 'diabetology_center', __( 'Centro diabetologico', 'sd-logbook' ), __( 'Centro diabetologico di riferimento.', 'sd-logbook' ), 'Ospedale Regionale Lugano' ),
			self::make_variable( 'address_street', __( 'Indirizzo', 'sd-logbook' ), __( 'Via e numero civico.', 'sd-logbook' ), 'Via Esempio 10' ),
			self::make_variable( 'address_postal', __( 'CAP', 'sd-logbook' ), __( 'Codice postale.', 'sd-logbook' ), '6900' ),
			self::make_variable( 'address_city', __( 'Località', 'sd-logbook' ), __( 'Città o località.', 'sd-logbook' ), 'Lugano' ),
			self::make_variable( 'address_country', __( 'Nazione', 'sd-logbook' ), __( 'Paese di residenza.', 'sd-logbook' ), 'CH' ),
			self::make_variable( 'address_canton', __( 'Cantone', 'sd-logbook' ), __( 'Cantone di residenza.', 'sd-logbook' ), 'TI' ),
			self::make_variable( 'fiscal_code', __( 'Codice fiscale / AVS', 'sd-logbook' ), __( 'Codice fiscale o numero AVS inserito.', 'sd-logbook' ), '756.1234.5678.97' ),
			self::make_variable( 'guardian_first_name', __( 'Nome tutore', 'sd-logbook' ), __( 'Nome del genitore/tutore legale.', 'sd-logbook' ), 'Laura' ),
			self::make_variable( 'guardian_last_name', __( 'Cognome tutore', 'sd-logbook' ), __( 'Cognome del genitore/tutore legale.', 'sd-logbook' ), 'Rossi' ),
			self::make_variable( 'guardian_role', __( 'Ruolo tutore', 'sd-logbook' ), __( 'Ruolo del genitore/tutore legale.', 'sd-logbook' ), 'Genitore' ),
			self::make_variable( 'guardian_dob', __( 'Data di nascita tutore', 'sd-logbook' ), __( 'Data di nascita del genitore/tutore.', 'sd-logbook' ), '1978-02-10' ),
			self::make_variable( 'guardian_birth_place', __( 'Luogo di nascita tutore', 'sd-logbook' ), __( 'Luogo di nascita del genitore/tutore.', 'sd-logbook' ), 'Bellinzona' ),
			self::make_variable( 'guardian_gender', __( 'Genere tutore', 'sd-logbook' ), __( 'Genere del genitore/tutore.', 'sd-logbook' ), 'F' ),
			self::make_variable( 'guardian_email', __( 'E-mail tutore', 'sd-logbook' ), __( 'E-mail del genitore/tutore.', 'sd-logbook' ), 'laura.rossi@example.com' ),
			self::make_variable( 'guardian_phone', __( 'Telefono tutore', 'sd-logbook' ), __( 'Telefono del genitore/tutore.', 'sd-logbook' ), '+41 78 000 00 00' ),
			self::make_variable( 'guardian_address', __( 'Indirizzo tutore', 'sd-logbook' ), __( 'Indirizzo del genitore/tutore.', 'sd-logbook' ), 'Via Famiglia 5' ),
			self::make_variable( 'guardian_city', __( 'Città tutore', 'sd-logbook' ), __( 'Località del genitore/tutore.', 'sd-logbook' ), 'Lugano' ),
			self::make_variable( 'guardian_postal', __( 'CAP tutore', 'sd-logbook' ), __( 'CAP del genitore/tutore.', 'sd-logbook' ), '6900' ),
			self::make_variable( 'guardian_country', __( 'Nazione tutore', 'sd-logbook' ), __( 'Paese del genitore/tutore.', 'sd-logbook' ), 'CH' ),
			self::make_variable( 'member_type', __( 'Tipo socio tecnico', 'sd-logbook' ), __( 'Valore tecnico del tipo socio.', 'sd-logbook' ), 'attivo' ),
			self::make_variable( 'membership_type', __( 'Tipo iscrizione tecnico', 'sd-logbook' ), __( 'Valore tecnico del tipo iscrizione.', 'sd-logbook' ), 'individuale' ),
			self::make_variable( 'fee_amount', __( 'Quota associativa', 'sd-logbook' ), __( 'Importo quota associativa numerico.', 'sd-logbook' ), '50.00' ),
			self::make_variable( 'is_scuba', __( 'Subacqueo', 'sd-logbook' ), __( 'Indica se la persona pratica attività subacquea.', 'sd-logbook' ), 'Sì' ),
			self::make_variable( 'weight', __( 'Peso', 'sd-logbook' ), __( 'Peso inserito nel modulo.', 'sd-logbook' ), '70.0' ),
			self::make_variable( 'height', __( 'Altezza', 'sd-logbook' ), __( 'Altezza inserita nel modulo.', 'sd-logbook' ), '170' ),
			self::make_variable( 'blood_type', __( 'Gruppo sanguigno', 'sd-logbook' ), __( 'Gruppo sanguigno dichiarato.', 'sd-logbook' ), 'A+' ),
			self::make_variable( 'default_shared_for_research', __( 'Consenso ricerca', 'sd-logbook' ), __( 'Consenso predefinito alla condivisione per ricerca.', 'sd-logbook' ), 'Sì' ),
			self::make_variable( 'privacy_consent', __( 'Consenso privacy', 'sd-logbook' ), __( 'Consenso privacy fornito nel modulo.', 'sd-logbook' ), 'Sì' ),
		);
	}

	/**
	 * Variabili base sempre disponibili per i moduli attività.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function get_activity_base_variables(): array {
		return array(
			self::make_variable( 'first_name', __( 'Nome', 'sd-logbook' ), __( 'Nome della persona iscritta all’attività.', 'sd-logbook' ), 'Mario' ),
			self::make_variable( 'last_name', __( 'Cognome', 'sd-logbook' ), __( 'Cognome della persona iscritta all’attività.', 'sd-logbook' ), 'Rossi' ),
			self::make_variable( 'email', __( 'E-mail', 'sd-logbook' ), __( 'E-mail della persona iscritta all’attività.', 'sd-logbook' ), 'mario.rossi@example.com' ),
			self::make_variable( 'birth_date', __( 'Data di nascita', 'sd-logbook' ), __( 'Data di nascita raccolta nel modulo attività.', 'sd-logbook' ), '1990-04-12' ),
			self::make_variable( 'is_minor', __( 'Minorenne', 'sd-logbook' ), __( 'Indica se la persona risulta minorenne dal modulo.', 'sd-logbook' ), 'No' ),
			self::make_variable( 'activity_title', __( 'Titolo attività', 'sd-logbook' ), __( 'Titolo dell’attività selezionata.', 'sd-logbook' ), 'Weekend in piscina' ),
			self::make_variable( 'activity_location', __( 'Luogo attività', 'sd-logbook' ), __( 'Luogo dell’attività.', 'sd-logbook' ), 'Lugano' ),
			self::make_variable( 'activity_start_date', __( 'Data inizio attività', 'sd-logbook' ), __( 'Data di inizio attività.', 'sd-logbook' ), '2026-06-01' ),
			self::make_variable( 'activity_end_date', __( 'Data fine attività', 'sd-logbook' ), __( 'Data di fine attività.', 'sd-logbook' ), '2026-06-02' ),
			self::make_variable( 'activity_description', __( 'Descrizione attività', 'sd-logbook' ), __( 'Descrizione dell’attività.', 'sd-logbook' ), 'Attività introduttiva in acqua confinata.' ),
			self::make_variable( 'selected_price_names', __( 'Tariffe selezionate', 'sd-logbook' ), __( 'Elenco delle tariffe selezionate nel modulo attività.', 'sd-logbook' ), 'Adulto, Noleggio' ),
			self::make_variable( 'selected_price_count', __( 'Numero tariffe selezionate', 'sd-logbook' ), __( 'Numero di tariffe selezionate.', 'sd-logbook' ), '2' ),
			self::make_variable( 'price_chf', __( 'Prezzo CHF', 'sd-logbook' ), __( 'Totale o prezzo registrato in CHF.', 'sd-logbook' ), '75.00' ),
			self::make_variable( 'price_eur', __( 'Prezzo EUR', 'sd-logbook' ), __( 'Totale o prezzo registrato in EUR.', 'sd-logbook' ), '77.50' ),
		);
	}

	/**
	 * Variabili mediche/profilo subacqueo (da sd_diver_profiles).
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function get_medical_variables(): array {
		return array(
			self::make_variable( 'med_hba1c_last', __( 'HbA1c', 'sd-logbook' ), __( 'Ultimo valore HbA1c del profilo subacqueo.', 'sd-logbook' ), '7.2', 'medical' ),
			self::make_variable( 'med_hba1c_date', __( 'Data HbA1c', 'sd-logbook' ), __( 'Data ultima misurazione HbA1c.', 'sd-logbook' ), '15.03.2026', 'medical' ),
			self::make_variable( 'med_hba1c_unit', __( 'Unità HbA1c', 'sd-logbook' ), __( 'Unità di misura HbA1c (%, mmol/mol).', 'sd-logbook' ), '%', 'medical' ),
			self::make_variable( 'med_uses_cgm', __( 'Usa CGM', 'sd-logbook' ), __( 'Indica se il subacqueo usa un CGM.', 'sd-logbook' ), 'Sì', 'medical' ),
			self::make_variable( 'med_cgm_device', __( 'Dispositivo CGM', 'sd-logbook' ), __( 'Modello CGM utilizzato.', 'sd-logbook' ), 'Dexcom G7', 'medical' ),
			self::make_variable( 'med_therapy_type', __( 'Tipo terapia', 'sd-logbook' ), __( 'Tipo di terapia insulinica.', 'sd-logbook' ), 'Penna insulinica', 'medical' ),
			self::make_variable( 'med_therapy_detail', __( 'Dettaglio terapia', 'sd-logbook' ), __( 'Dettaglio della terapia.', 'sd-logbook' ), 'Rapida + basale', 'medical' ),
			self::make_variable( 'med_insulin_pump_model', __( 'Modello microinfusore', 'sd-logbook' ), __( 'Modello del microinfusore, se applicabile.', 'sd-logbook' ), 'Omnipod 5', 'medical' ),
			self::make_variable( 'med_certification_level', __( 'Brevetto sub', 'sd-logbook' ), __( 'Livello di brevetto subacqueo.', 'sd-logbook' ), 'OWD', 'medical' ),
			self::make_variable( 'med_certification_agency', __( 'Ente brevetto', 'sd-logbook' ), __( 'Ente emittente il brevetto.', 'sd-logbook' ), 'PADI', 'medical' ),
			self::make_variable( 'med_certification_date', __( 'Data brevetto', 'sd-logbook' ), __( 'Data di conseguimento del brevetto.', 'sd-logbook' ), '10.06.2018', 'medical' ),
			self::make_variable( 'med_medical_clearance_date', __( 'Data visita medica', 'sd-logbook' ), __( 'Data ultima visita medica subacquea.', 'sd-logbook' ), '05.01.2026', 'medical' ),
			self::make_variable( 'med_medical_clearance_expiry', __( 'Scadenza visita medica', 'sd-logbook' ), __( 'Scadenza del certificato medico.', 'sd-logbook' ), '05.01.2027', 'medical' ),
			self::make_variable( 'med_allergies', __( 'Allergie', 'sd-logbook' ), __( 'Allergie dichiarate nel profilo.', 'sd-logbook' ), 'Nessuna', 'medical' ),
			self::make_variable( 'med_medications', __( 'Farmaci', 'sd-logbook' ), __( 'Farmaci regolari dichiarati nel profilo.', 'sd-logbook' ), 'Metformina', 'medical' ),
			self::make_variable( 'med_emergency_contact_name', __( 'Contatto emergenza', 'sd-logbook' ), __( 'Nome del contatto di emergenza.', 'sd-logbook' ), 'Marco Rossi', 'medical' ),
			self::make_variable( 'med_emergency_contact_phone', __( 'Tel. emergenza', 'sd-logbook' ), __( 'Telefono del contatto di emergenza.', 'sd-logbook' ), '+41 79 111 22 33', 'medical' ),
			self::make_variable( 'med_glycemia_unit', __( 'Unità glicemia', 'sd-logbook' ), __( 'Unità di misura glicemia (mg/dl o mmol/l).', 'sd-logbook' ), 'mg/dl', 'medical' ),
		);
	}

	/**
	 * Variabili certificazioni subacquee (da user meta sd_certifications).
	 */
	private static function get_certification_variables(): array {
		return array(
			self::make_variable( 'prof_certifications', __( 'Certificazioni sub', 'sd-logbook' ), __( 'Elenco certificazioni subacquee (agenzia, livello, data, N. brevetto).', 'sd-logbook' ), 'PADI — Divemaster (22/03/2026, N. 579526)', 'diving' ),
		);
	}

	/**
	 * Variabili contatti di emergenza (da user meta sd_emergency_contacts).
	 */
	private static function get_emergency_contact_variables(): array {
		return array(
			self::make_variable( 'prof_emergency_contacts', __( 'Contatti di emergenza', 'sd-logbook' ), __( 'Elenco contatti di emergenza (nome, telefono, relazione).', 'sd-logbook' ), 'Marco Rossi (+41 79 111 22 33, coniuge)', 'emergency' ),
		);
	}

	/**
	 * Recupera i moduli/form disponibili per l'editor template.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_form_catalog(): array {
		$forms = array();
		$forms[ self::DEFAULT_MEMBERSHIP_FORM_KEY ] = array(
			'key'       => self::DEFAULT_MEMBERSHIP_FORM_KEY,
			'type'      => 'membership',
			'group'     => __( 'Soci SDS', 'sd-logbook' ),
			'label'     => __( 'Soci SDS', 'sd-logbook' ),
			'color'     => 'indigo',
			'variables' => array_merge( self::get_standard_variables(), self::get_membership_form_variables(), self::get_medical_variables(), self::get_certification_variables(), self::get_emergency_contact_variables() ),
		);

		global $wpdb;
		$db         = new SD_Database();
		$activities = $wpdb->get_results(
			"SELECT id, title, start_date, end_date FROM {$db->table('activities')} ORDER BY start_date DESC, title ASC",
			ARRAY_A
		);

		foreach ( (array) $activities as $activity ) {
			$activity_id = (int) ( $activity['id'] ?? 0 );
			if ( $activity_id <= 0 ) {
				continue;
			}

			$variables = array_merge( self::get_standard_variables(), self::get_activity_base_variables() );
			$fields    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT field_name, field_label, section_label FROM {$db->table('activity_form_fields')} WHERE activity_id = %d ORDER BY section_order ASC, field_order ASC, id ASC",
					$activity_id
				),
				ARRAY_A
			);

			foreach ( (array) $fields as $field ) {
				$field_name = sanitize_key( $field['field_name'] ?? '' );
				if ( '' === $field_name ) {
					continue;
				}
				$variables[] = self::make_variable(
					$field_name,
					(string) ( $field['field_label'] ?: $field_name ),
					sprintf(
						/* translators: 1: field label, 2: section label */
						__( 'Campo attività "%1$s" dalla sezione "%2$s".', 'sd-logbook' ),
						(string) ( $field['field_label'] ?: $field_name ),
						(string) ( $field['section_label'] ?: __( 'Informazioni aggiuntive', 'sd-logbook' ) )
					),
					(string) ( $field['field_label'] ?: $field_name )
				);
			}

			$label = (string) ( $activity['title'] ?? '' );
			if ( ! empty( $activity['start_date'] ) ) {
				$label .= ' (' . mysql2date( 'd.m.Y', (string) $activity['start_date'] ) . ')';
			}

			$forms[ 'activity:' . $activity_id ] = array(
				'key'       => 'activity:' . $activity_id,
				'type'      => 'activity',
				'group'     => __( 'Iscrizioni attività', 'sd-logbook' ),
				'label'     => $label,
				'color'     => 'teal',
				'variables' => self::dedupe_variables( $variables ),
			);
		}

		return $forms;
	}

	/**
	 * Restituisce le variabili disponibili per un modulo specifico.
	 *
	 * @param string $form_key Chiave modulo.
	 * @return array<int,array<string,string>>
	 */
	public static function get_variable_list( string $form_key = self::DEFAULT_MEMBERSHIP_FORM_KEY ): array {
		$forms = self::get_form_catalog();
		if ( isset( $forms[ $form_key ]['variables'] ) && is_array( $forms[ $form_key ]['variables'] ) ) {
			return $forms[ $form_key ]['variables'];
		}

		return self::get_standard_variables();
	}

	/**
	 * Rimuove variabili duplicate mantenendo l'ultima descrizione utile.
	 *
	 * @param array<int,array<string,string>> $variables Variabili raw.
	 * @return array<int,array<string,string>>
	 */
	private static function dedupe_variables( array $variables ): array {
		$deduped = array();
		foreach ( $variables as $variable ) {
			$tag = strtolower( (string) ( $variable['tag'] ?? '' ) );
			if ( '' === $tag ) {
				continue;
			}
			$deduped[ $tag ] = $variable;
		}

		return array_values( $deduped );
	}

	/**
	 * Chiave modulo predefinita per l'editor.
	 *
	 * @param array<string,array<string,mixed>> $forms Catalogo moduli.
	 * @return string
	 */
	private static function get_default_form_key( array $forms ): string {
		if ( isset( $forms[ self::DEFAULT_MEMBERSHIP_FORM_KEY ] ) ) {
			return self::DEFAULT_MEMBERSHIP_FORM_KEY;
		}

		$keys = array_keys( $forms );
		return ! empty( $keys ) ? (string) $keys[0] : self::DEFAULT_MEMBERSHIP_FORM_KEY;
	}

	/**
	 * Estrae il tipo template dalla chiave modulo.
	 *
	 * @param string $form_key Chiave modulo.
	 * @return string
	 */
	private static function get_template_type_for_form( string $form_key ): string {
		return 0 === strpos( $form_key, 'activity:' ) ? 'activity' : 'membership';
	}

	/**
	 * Verifica che il tipo template sia consentito.
	 *
	 * @param string $template_type Tipo template.
	 * @return bool
	 */
	private static function is_valid_template_type( string $template_type ): bool {
		return in_array( $template_type, self::TEMPLATE_TYPES, true );
	}

	/**
	 * Estrae le variabili effettivamente usate in subject/body/signature.
	 *
	 * @param string $subject   Oggetto.
	 * @param string $body      Corpo.
	 * @param string $signature Firma.
	 * @return array<int,string>
	 */
	private static function extract_used_variables( string $subject, string $body, string $signature ): array {
		$matches = array();
		preg_match_all( '/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $subject . "\n" . $body . "\n" . $signature, $matches );

		if ( empty( $matches[1] ) || ! is_array( $matches[1] ) ) {
			return array();
		}

		$variables = array();
		foreach ( $matches[1] as $name ) {
			$key = sanitize_key( (string) $name );
			if ( '' !== $key ) {
				$variables[] = '{{' . $key . '}}';
			}
		}

		return array_values( array_unique( $variables ) );
	}

	/**
	 * Verifica la compatibilità di un template con un modulo specifico.
	 *
	 * @param object $row      Riga template dal DB.
	 * @param string $form_key Chiave modulo target.
	 * @return bool
	 */
	private static function is_template_compatible_with_form( object $row, string $form_key ): bool {
		$forms = self::get_form_catalog();
		if ( empty( $forms[ $form_key ] ) ) {
			return false;
		}

		$template_type = sanitize_key( (string) ( $row->template_type ?? 'membership' ) );
		if ( self::get_template_type_for_form( $form_key ) !== $template_type ) {
			return false;
		}

		$form_tags = array();
		foreach ( (array) $forms[ $form_key ]['variables'] as $variable ) {
			if ( empty( $variable['tag'] ) ) {
				continue;
			}
			$form_tags[ strtolower( (string) $variable['tag'] ) ] = true;
		}

		$used = array();
		if ( ! empty( $row->used_variables ) ) {
			$decoded = json_decode( (string) $row->used_variables, true );
			if ( is_array( $decoded ) ) {
				$used = array_map( 'strval', $decoded );
			}
		}
		if ( empty( $used ) ) {
			$used = self::extract_used_variables( (string) ( $row->subject ?? '' ), (string) ( $row->body ?? '' ), (string) ( $row->signature ?? '' ) );
		}

		foreach ( $used as $tag ) {
			if ( ! isset( $form_tags[ strtolower( (string) $tag ) ] ) ) {
				return false;
			}
		}

		return true;
	}

	// =========================================================================
	// RISOLUZIONE VARIABILI
	// =========================================================================

	/**
	 * Sostituisce le variabili {{...}} nel testo con i valori reali.
	 *
	 * @param string      $text    Testo con variabili.
	 * @param object|null $context Contesto generico del modulo/socio/registrazione.
	 * @return string
	 */
	public static function resolve( string $text, ?object $context = null ): string {
		$months_it = array(
			1  => 'gennaio',
			2  => 'febbraio',
			3  => 'marzo',
			4  => 'aprile',
			5  => 'maggio',
			6  => 'giugno',
			7  => 'luglio',
			8  => 'agosto',
			9  => 'settembre',
			10 => 'ottobre',
			11 => 'novembre',
			12 => 'dicembre',
		);

		$now   = new DateTime( 'now', new DateTimeZone( 'Europe/Zurich' ) );
		$month = (int) $now->format( 'n' );
		$year  = (int) $now->format( 'Y' );

		$today_breve  = $now->format( 'd.m.Y' );
		$today_estesa = $now->format( 'd' ) . ' ' . ( $months_it[ $month ] ?? $now->format( 'F' ) ) . ' ' . $year;
		$mese_oggi    = $months_it[ $month ] ?? $now->format( 'F' );
		$anno_oggi    = (string) $year;
		$anno_prox    = (string) ( $year + 1 );
		$context_map  = self::extract_context_variables( $context );
		$member_type_labels = array(
			'attivo'               => __( 'Attivo', 'sd-logbook' ),
			'attivo_capo_famiglia' => __( 'Attivo Capo Famiglia', 'sd-logbook' ),
			'attivo_famigliare'    => __( 'Attivo Famigliare', 'sd-logbook' ),
			'passivo'              => __( 'Passivo', 'sd-logbook' ),
			'accompagnatore'       => __( 'Accompagnatore', 'sd-logbook' ),
			'sostenitore'          => __( 'Sostenitore', 'sd-logbook' ),
			'onorario'             => __( 'Onorario', 'sd-logbook' ),
			'fondatore'            => __( 'Fondatore', 'sd-logbook' ),
		);

		// Valori derivati dal socio
		$scadenza           = '';
		$tipo_socio         = '';
		$numero_socio       = '';
		$tassa_sociale      = '';
		$tassa_sociale_num  = '0.00';
		$nome               = '';
		$cognome            = '';
		$nome_completo      = '';
		$email_socio        = '';
		$dob_formatted      = '';
		$diabetes_type_label = '';
		$gender_label        = '';
		$member_type_label   = '';
		$sotto_tutela_label  = '';
		$is_scuba_label      = '';
		$privacy_consent_label = '';
		$guardian_dob_formatted = '';
		$tshirt_size_val     = '';
		$birth_country_label = '';
		$guardian_country_label = '';
		$address_country_label = '';
		$has_guardian        = false;
		$weight_val          = '';
		$height_val          = '';
		$blood_type_val      = '';
		$research_consent_label = '';
		// Variabili mediche (profilo subacqueo).
		$med_hba1c_last             = '';
		$med_hba1c_date             = '';
		$med_hba1c_unit             = '';
		$med_uses_cgm               = '';
		$med_cgm_device             = '';
		$med_therapy_type           = '';
		$med_therapy_detail         = '';
		$med_insulin_pump_model     = '';
		$med_certification_level    = '';
		$med_certification_agency   = '';
		$med_certification_date     = '';
		$med_medical_clearance_date = '';
		$med_medical_clearance_expiry = '';
		$med_allergies              = '';
		$med_medications            = '';
		$med_emergency_contact_name  = '';
		$med_emergency_contact_phone = '';
		$med_glycemia_unit           = '';
		$prof_certifications         = '';
		$prof_emergency_contacts     = '';
		$email_associazione = (string) ( get_option( 'sd_secretariat_email' ) ?: get_option( 'admin_email' ) );

		// Mappa codici paese → nome per CH/IT/DE/FR/AT/LI + fallback.
		$country_names = array(
			'CH' => 'Svizzera',
			'IT' => 'Italia',
			'DE' => 'Germania',
			'FR' => 'Francia',
			'AT' => 'Austria',
			'LI' => 'Liechtenstein',
			'BE' => 'Belgio',
			'NL' => 'Paesi Bassi',
			'ES' => 'Spagna',
			'PT' => 'Portogallo',
			'GB' => 'Regno Unito',
			'US' => 'Stati Uniti',
		);

		if ( $context ) {
			if ( ! empty( $context->membership_expiry ) ) {
				$dt_exp = DateTime::createFromFormat( 'Y-m-d', (string) $context->membership_expiry );
				$scadenza = $dt_exp ? $dt_exp->format( 'd.m.Y' ) : (string) $context->membership_expiry;
			}
			$member_type_key = sanitize_key( (string) ( $context->member_type ?? '' ) );
			if ( isset( $member_type_labels[ $member_type_key ] ) ) {
				$tipo_socio        = (string) $member_type_labels[ $member_type_key ];
				$member_type_label = $tipo_socio;
			} else {
				$tipo_socio        = (string) $member_type_labels['attivo'];
				$member_type_label = $tipo_socio;
			}
			$numero_socio    = trim( (string) ( $context->member_number ?? '' ) );
			if ( '' === $numero_socio && ! empty( $context->id ) ) {
				$numero_socio = (string) $context->id;
			}
			$amount            = isset( $context->fee_amount ) ? (float) $context->fee_amount : ( isset( $context->price_chf ) ? (float) $context->price_chf : 0 );
			$tassa_sociale_num = number_format( $amount, 2 );
			$tassa_sociale = 'CHF ' . $tassa_sociale_num;
			$nome          = (string) ( $context->first_name ?? '' );
			$cognome       = (string) ( $context->last_name ?? '' );
			$nome_completo = trim( $nome . ' ' . $cognome );
			$email_socio   = (string) ( $context->email ?? '' );
			if ( ! empty( $context->date_of_birth ) ) {
				$dt_dob        = DateTime::createFromFormat( 'Y-m-d', (string) $context->date_of_birth );
				$dob_formatted = $dt_dob ? $dt_dob->format( 'd.m.Y' ) : (string) $context->date_of_birth;
			}

			// Tipo di diabete.
			$diabetes_type_labels = array(
				'tipo_1'          => __( 'Tipo 1', 'sd-logbook' ),
				'tipo_2'          => __( 'Tipo 2', 'sd-logbook' ),
				'tipo_3c'         => __( 'Tipo 3c', 'sd-logbook' ),
				'lada'            => 'LADA',
				'mody'            => 'MODY',
				'midd'            => 'MIDD',
				'altro'           => __( 'Altro', 'sd-logbook' ),
				'non_diabetico'   => __( 'Non diabetico', 'sd-logbook' ),
				'non_specificato' => __( 'Non specificato', 'sd-logbook' ),
			);
			$dt_key = sanitize_key( (string) ( $context->diabetes_type ?? '' ) );
			$diabetes_type_label = $diabetes_type_labels[ $dt_key ] ?? ucfirst( str_replace( '_', ' ', $dt_key ) );

			// Genere.
			$gender_raw    = strtoupper( trim( (string) ( $context->gender ?? '' ) ) );
			$gender_labels = array(
				'M' => __( 'Maschile', 'sd-logbook' ),
				'F' => __( 'Femminile', 'sd-logbook' ),
			);
			$gender_label  = $gender_labels[ $gender_raw ] ?? $gender_raw;

			// Booleani.
			$sotto_tutela_label      = ! empty( $context->sotto_tutela ) ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' );
			$is_scuba_label          = ! empty( $context->is_scuba ) ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' );
			$privacy_consent_label   = ! empty( $context->privacy_consent ) ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' );

			// guardian_dob formattato.
			if ( ! empty( $context->guardian_dob ) ) {
				$dt_gdob              = DateTime::createFromFormat( 'Y-m-d', (string) $context->guardian_dob );
				$guardian_dob_formatted = $dt_gdob ? $dt_gdob->format( 'd.m.Y' ) : (string) $context->guardian_dob;
			}

			// Campi tutore: valorizzare solo se esiste effettivamente un tutore.
			$has_guardian = ! empty( $context->guardian_first_name ) || ! empty( $context->guardian_last_name );
			if ( $has_guardian ) {
				$gc_code                = strtoupper( trim( (string) ( $context->guardian_country ?? '' ) ) );
				$guardian_country_label = $country_names[ $gc_code ] ?? $gc_code;
			}

			// Nazione di nascita.
			$bc_code             = strtoupper( trim( (string) ( $context->birth_country ?? '' ) ) );
			$birth_country_label = $country_names[ $bc_code ] ?? $bc_code;

			// Nazione di residenza.
			$ac_code               = strtoupper( trim( (string) ( $context->address_country ?? '' ) ) );
			$address_country_label = $country_names[ $ac_code ] ?? $ac_code;

			// Taglia maglietta (colonna DB = taglia_maglietta).
			$tshirt_size_val = (string) ( $context->taglia_maglietta ?? $context->tshirt_size ?? '' );

			// Dati da sd_diver_profiles (peso, altezza, gruppo sanguigno, consenso ricerca, dati medici).
			$user_id_for_profile = (int) ( $context->wp_user_id ?? 0 );
			if ( $user_id_for_profile > 0 ) {
				global $wpdb;
				$db_obj  = new SD_Database();
				$profile = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT * FROM ' . $db_obj->table( 'diver_profiles' ) . ' WHERE user_id = %d LIMIT 1',
						$user_id_for_profile
					)
				);
				if ( $profile ) {
					$weight_val    = '' !== (string) $profile->weight && null !== $profile->weight ? (string) $profile->weight : '';
					$height_val    = '' !== (string) $profile->height && null !== $profile->height ? (string) $profile->height : '';
					$blood_type_val = (string) ( $profile->blood_type ?? '' );
					$research_consent_label = $profile->default_shared_for_research ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' );

					// Campi medici.
					$med_hba1c_last  = null !== $profile->hba1c_last ? (string) $profile->hba1c_last : '';
					if ( ! empty( $profile->hba1c_date ) ) {
						$dt_hba1c        = DateTime::createFromFormat( 'Y-m-d', (string) $profile->hba1c_date );
						$med_hba1c_date  = $dt_hba1c ? $dt_hba1c->format( 'd.m.Y' ) : (string) $profile->hba1c_date;
					}
					$med_hba1c_unit  = (string) ( $profile->hba1c_unit ?? '%' );
					$med_uses_cgm    = $profile->uses_cgm ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' );
					$med_cgm_device  = (string) ( $profile->cgm_device ?? '' );
					// Terapia: mapping slug → label.
					$therapy_labels  = array(
						'none'           => '',
						'oral'           => __( 'Farmaci orali', 'sd-logbook' ),
						'insulin_pen'    => __( 'Penna insulinica', 'sd-logbook' ),
						'insulin_pump'   => __( 'Microinfusore', 'sd-logbook' ),
						'insulin_patch'  => __( 'Patch insulinica', 'sd-logbook' ),
						'diet_only'      => __( 'Solo dieta', 'sd-logbook' ),
						'other'          => __( 'Altro', 'sd-logbook' ),
					);
					$t_key               = sanitize_key( (string) ( $profile->therapy_type ?? 'none' ) );
					$med_therapy_type    = $therapy_labels[ $t_key ] ?? ucfirst( str_replace( '_', ' ', $t_key ) );
					$med_therapy_detail  = (string) ( $profile->therapy_detail ?? '' );
					if ( 'other' === sanitize_key( (string) ( $profile->therapy_detail ?? '' ) ) && ! empty( $profile->therapy_detail_other ) ) {
						$med_therapy_detail = (string) $profile->therapy_detail_other;
					}
					$med_insulin_pump_model = (string) ( $profile->insulin_pump_model ?? '' );
					if ( ! empty( $profile->insulin_pump_model_other ) ) {
						$med_insulin_pump_model = (string) $profile->insulin_pump_model_other;
					}
					// Certificazione.
					$med_certification_level  = (string) ( $profile->certification_level ?? '' );
					$med_certification_agency = (string) ( $profile->certification_agency ?? '' );
					if ( ! empty( $profile->certification_date ) ) {
						$dt_cert              = DateTime::createFromFormat( 'Y-m-d', (string) $profile->certification_date );
						$med_certification_date = $dt_cert ? $dt_cert->format( 'd.m.Y' ) : (string) $profile->certification_date;
					}
					// Visita medica.
					if ( ! empty( $profile->medical_clearance_date ) ) {
						$dt_mc                    = DateTime::createFromFormat( 'Y-m-d', (string) $profile->medical_clearance_date );
						$med_medical_clearance_date = $dt_mc ? $dt_mc->format( 'd.m.Y' ) : (string) $profile->medical_clearance_date;
					}
					if ( ! empty( $profile->medical_clearance_expiry ) ) {
						$dt_mce                      = DateTime::createFromFormat( 'Y-m-d', (string) $profile->medical_clearance_expiry );
						$med_medical_clearance_expiry = $dt_mce ? $dt_mce->format( 'd.m.Y' ) : (string) $profile->medical_clearance_expiry;
					}
					// Altro.
					// Allergie: array JSON di stringhe → lista leggibile.
					$allergies_raw = (string) ( $profile->allergies ?? '' );
					if ( '' !== $allergies_raw ) {
						$allergies_arr = json_decode( $allergies_raw, true );
						if ( is_array( $allergies_arr ) && ! empty( $allergies_arr ) ) {
							$allergies_clean = array_filter( array_map( 'trim', $allergies_arr ), 'strlen' );
							$med_allergies   = implode( ', ', array_values( $allergies_clean ) );
						} else {
							$med_allergies = '' === trim( $allergies_raw, '[] ' ) ? '' : $allergies_raw;
						}
					}
					// Farmaci: array JSON di oggetti {name, dosage, unit, suspended} → lista leggibile.
					$medications_raw = (string) ( $profile->medications ?? '' );
					if ( '' !== $medications_raw ) {
						$medications_arr = json_decode( $medications_raw, true );
						if ( is_array( $medications_arr ) && ! empty( $medications_arr ) ) {
							$meds_labels = array();
							foreach ( $medications_arr as $med ) {
								if ( ! is_array( $med ) || empty( $med['name'] ) ) {
									continue;
								}
								$label = sanitize_text_field( (string) $med['name'] );
								if ( ! empty( $med['dosage'] ) ) {
									$label .= ' ' . sanitize_text_field( (string) $med['dosage'] );
									if ( ! empty( $med['unit'] ) && 'altro' !== strtolower( (string) $med['unit'] ) ) {
										$label .= ' ' . sanitize_text_field( (string) $med['unit'] );
									}
								}
								if ( ! empty( $med['suspended'] ) ) {
									$label .= ' (' . __( 'sospeso', 'sd-logbook' ) . ')';
								}
								$meds_labels[] = $label;
							}
							$med_medications = implode( ', ', $meds_labels );
						}
					}
					$med_emergency_contact_name  = (string) ( $profile->emergency_contact_name ?? '' );
					$med_emergency_contact_phone = (string) ( $profile->emergency_contact_phone ?? '' );
					$med_glycemia_unit           = (string) ( $profile->glycemia_unit ?? 'mg/dl' );
				}
				// Certificazioni sub (da user meta sd_certifications).
				$certs_raw = get_user_meta( $user_id_for_profile, 'sd_certifications', true );
				if ( is_array( $certs_raw ) && ! empty( $certs_raw ) ) {
					$certs_labels = array();
					foreach ( $certs_raw as $cert ) {
						if ( ! is_array( $cert ) || empty( $cert['agency'] ) ) {
							continue;
						}
						$c_agency = sanitize_text_field( (string) $cert['agency'] );
						$c_level  = sanitize_text_field( (string) ( $cert['level'] ?? '' ) );
						$c_label  = $c_agency . ( $c_level ? ' — ' . $c_level : '' );
						$c_extras = array();
						if ( ! empty( $cert['date'] ) ) {
							$dt_cm      = DateTime::createFromFormat( 'Y-m-d', (string) $cert['date'] );
							$c_extras[] = $dt_cm ? $dt_cm->format( 'd.m.Y' ) : (string) $cert['date'];
						}
						if ( ! empty( $cert['number'] ) ) {
							$c_extras[] = 'N. ' . sanitize_text_field( (string) $cert['number'] );
						}
						if ( ! empty( $c_extras ) ) {
							$c_label .= ' (' . implode( ', ', $c_extras ) . ')';
						}
						$certs_labels[] = $c_label;
					}
					$prof_certifications = implode( ', ', $certs_labels );
				}
				// Contatti di emergenza (da user meta sd_emergency_contacts).
				$contacts_raw = get_user_meta( $user_id_for_profile, 'sd_emergency_contacts', true );
				if ( is_array( $contacts_raw ) && ! empty( $contacts_raw ) ) {
					$ec_labels = array();
					foreach ( $contacts_raw as $ec ) {
						if ( ! is_array( $ec ) || empty( $ec['name'] ) ) {
							continue;
						}
						$ec_name   = sanitize_text_field( (string) $ec['name'] );
						$ec_phone  = sanitize_text_field( (string) ( $ec['phone'] ?? '' ) );
						$ec_rel    = sanitize_text_field( (string) ( $ec['relationship'] ?? '' ) );
						$ec_extras = array();
						if ( $ec_phone ) {
							$ec_extras[] = $ec_phone;
						}
						if ( $ec_rel ) {
							$ec_extras[] = $ec_rel;
						}
						$ec_item = $ec_name;
						if ( ! empty( $ec_extras ) ) {
							$ec_item .= ' (' . implode( ', ', $ec_extras ) . ')';
						}
						$ec_labels[] = $ec_item;
					}
					$prof_emergency_contacts = implode( ', ', $ec_labels );
				}
			}
		}

		$map = array(
			'{{data_oggi_breve}}'   => $today_breve,
			'{{data_oggi_estesa}}'  => $today_estesa,
			'{{mese_oggi}}'         => $mese_oggi,
			'{{anno_oggi}}'         => $anno_oggi,
			'{{anno_prossimo}}'     => $anno_prox,
			'{{scadenza}}'          => $scadenza,
			'{{tipo_socio}}'        => $tipo_socio,
			'{{member_type}}'       => $member_type_label,
			'{{tassa_sociale}}'     => $tassa_sociale,
			'{{tassa_sociale_numero}}' => $numero_socio,
			'{{nome}}'              => $nome,
			'{{cognome}}'           => $cognome,
			'{{nome_completo}}'     => $nome_completo,
			'{{email_socio}}'       => $email_socio,
			'{{email_associazione}}' => $email_associazione,
			'{{tipo_iscrizione}}'   => (string) ( $context->membership_type ?? '' ),
			'{{telefono_associazione}}' => self::get_association_phone(),
			'{{indirizzo_associazione}}' => self::get_association_address(),
			'{{logo}}'              => self::get_logo_markup( false ),
			'{{logo_esteso}}'       => self::get_logo_markup( true ),
			'{{date_of_birth}}'     => $dob_formatted,
			'{{diabetes_type}}'     => $diabetes_type_label,
			'{{gender}}'            => $gender_label,
			'{{sotto_tutela}}'      => $sotto_tutela_label,
			'{{is_scuba}}'          => $is_scuba_label,
			'{{privacy_consent}}'   => $privacy_consent_label,
			'{{guardian_dob}}'           => $guardian_dob_formatted,
			'{{guardian_country}}'       => $guardian_country_label,
			// Campi tutore: svuotati quando il socio non è sotto tutela legale.
			'{{guardian_first_name}}'    => $has_guardian && $context ? (string) ( $context->guardian_first_name ?? '' ) : '',
			'{{guardian_last_name}}'     => $has_guardian && $context ? (string) ( $context->guardian_last_name ?? '' ) : '',
			'{{guardian_role}}'          => $has_guardian && $context ? (string) ( $context->guardian_role ?? '' ) : '',
			'{{guardian_birth_place}}'   => $has_guardian && $context ? (string) ( $context->guardian_birth_place ?? '' ) : '',
			'{{guardian_birth_country}}' => $has_guardian && $context ? (string) ( $context->guardian_birth_country ?? '' ) : '',
			'{{guardian_gender}}'        => $has_guardian && $context ? (string) ( $context->guardian_gender ?? '' ) : '',
			'{{guardian_email}}'         => $has_guardian && $context ? (string) ( $context->guardian_email ?? '' ) : '',
			'{{guardian_phone}}'         => $has_guardian && $context ? (string) ( $context->guardian_phone ?? '' ) : '',
			'{{guardian_address}}'       => $has_guardian && $context ? (string) ( $context->guardian_address ?? '' ) : '',
			'{{guardian_city}}'          => $has_guardian && $context ? (string) ( $context->guardian_city ?? '' ) : '',
			'{{guardian_postal}}'        => $has_guardian && $context ? (string) ( $context->guardian_postal ?? '' ) : '',
			'{{birth_country}}'          => $birth_country_label,
			'{{address_country}}'        => $address_country_label,
			'{{tshirt_size}}'       => $tshirt_size_val,
			'{{taglia_maglietta}}'  => $tshirt_size_val,
			'{{weight}}'            => $weight_val,
			'{{height}}'            => $height_val,
			'{{blood_type}}'        => $blood_type_val,
			'{{default_shared_for_research}}' => $research_consent_label,
			// Variabili mediche.
			'{{med_hba1c_last}}'              => $med_hba1c_last,
			'{{med_hba1c_date}}'              => $med_hba1c_date,
			'{{med_hba1c_unit}}'              => $med_hba1c_unit,
			'{{med_uses_cgm}}'                => $med_uses_cgm,
			'{{med_cgm_device}}'              => $med_cgm_device,
			'{{med_therapy_type}}'            => $med_therapy_type,
			'{{med_therapy_detail}}'          => $med_therapy_detail,
			'{{med_insulin_pump_model}}'      => $med_insulin_pump_model,
			'{{med_certification_level}}'     => $med_certification_level,
			'{{med_certification_agency}}'    => $med_certification_agency,
			'{{med_certification_date}}'      => $med_certification_date,
			'{{med_medical_clearance_date}}'  => $med_medical_clearance_date,
			'{{med_medical_clearance_expiry}}' => $med_medical_clearance_expiry,
			'{{med_allergies}}'               => $med_allergies,
			'{{med_medications}}'             => $med_medications,
			'{{med_emergency_contact_name}}'  => $med_emergency_contact_name,
			'{{med_emergency_contact_phone}}' => $med_emergency_contact_phone,
			'{{med_glycemia_unit}}'           => $med_glycemia_unit,
			// Variabili profilo subacqueo: certificazioni e contatti emergenza.
			'{{prof_certifications}}'         => $prof_certifications,
			'{{prof_emergency_contacts}}'     => $prof_emergency_contacts,
		);

		$resolved = str_replace( array_keys( array_merge( $context_map, $map ) ), array_values( array_merge( $context_map, $map ) ), $text );

		// Rimuovi eventuali tag {{...}} rimasti non risolti.
		$resolved = preg_replace( '/\{\{[a-z_]+\}\}/', '', $resolved );

		// Rimuovi voci <li> il cui valore risulta vuoto dopo la sostituzione
		// (es. "Telefono: " oppure "Genere: " con valore assente).
		$resolved = preg_replace_callback(
			'/<li>(.*?)<\/li>/si',
			function ( $m ) {
				// Decodifica entità HTML (&nbsp; ecc.) prima del confronto.
				$text_only = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' === $text_only || preg_match( '/:\s*$/', $text_only ) ) {
					return '';
				}
				return $m[0];
			},
			$resolved
		);

		// Rimuovi <ul>/<ol> rimasti senza voci.
		$resolved = preg_replace( '/<[uo]l>\s*<\/[uo]l>/si', '', $resolved );

		return $resolved;
	}

	/**
	 * Estrae le variabili raw da un contesto generico (object o registration_data).
	 *
	 * @param object|null $context Contesto template.
	 * @return array<string,string>
	 */
	private static function extract_context_variables( ?object $context ): array {
		if ( ! $context ) {
			return array();
		}

		$data = get_object_vars( $context );
		if ( isset( $data['registration_data'] ) ) {
			$registration_data = $data['registration_data'];
			if ( is_string( $registration_data ) ) {
				$registration_data = json_decode( $registration_data, true );
			}
			if ( is_array( $registration_data ) ) {
				$data = array_merge( $registration_data, $data );
			}
		}

		$map = array();
		foreach ( $data as $key => $value ) {
			$tag_key = sanitize_key( (string) $key );
			if ( '' === $tag_key || in_array( $tag_key, array( 'registration_data', 'used_variables' ), true ) ) {
				continue;
			}

			$map[ '{{' . $tag_key . '}}' ] = self::normalize_context_value( $value );
		}

		return $map;
	}

	/**
	 * Normalizza un valore del contesto per l'uso nel template.
	 *
	 * @param mixed $value Valore raw.
	 * @return string
	 */
	private static function normalize_context_value( $value ): string {
		if ( is_array( $value ) ) {
			$scalar_values = array();
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) || null === $item ) {
					$scalar_values[] = (string) $item;
				}
			}
			return implode( ', ', array_filter( array_map( 'trim', $scalar_values ), 'strlen' ) );
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Sì', 'sd-logbook' ) : __( 'No', 'sd-logbook' );
		}

		if ( is_numeric( $value ) ) {
			return (string) $value;
		}

		if ( null === $value ) {
			return '';
		}

		return trim( (string) $value );
	}

	/**
	 * Recupera il telefono dell'associazione da opzioni note.
	 *
	 * @return string
	 */
	private static function get_association_phone(): string {
		foreach ( array( 'sd_secretariat_phone', 'sd_association_phone', 'sd_phone' ) as $option_key ) {
			$value = trim( (string) get_option( $option_key, '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Recupera l'indirizzo dell'associazione da opzioni note.
	 *
	 * @return string
	 */
	private static function get_association_address(): string {
		foreach ( array( 'sd_secretariat_address', 'sd_association_address', 'sd_address' ) as $option_key ) {
			$value = trim( (string) get_option( $option_key, '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Costruisce il markup HTML del logo associazione.
	 *
	 * @param bool $extended Se usare il logo esteso.
	 * @return string
	 */
	private static function get_logo_markup( bool $extended ): string {
		$custom_logo_id = (int) get_theme_mod( 'custom_logo' );
		$logo_url       = '';

		if ( $custom_logo_id > 0 ) {
			$image = wp_get_attachment_image_src( $custom_logo_id, 'full' );
			if ( ! empty( $image[0] ) ) {
				$logo_url = (string) $image[0];
			}
		}

		if ( '' === $logo_url ) {
			$logo_url = $extended
				? site_url( '/wp-content/uploads/2026/04/scubadiabetes_radius60.png' )
				: site_url( '/wp-content/uploads/2026/04/cropped-cropped-ScubaDS-1.jpeg' );
		}

		return '<img src="' . esc_url( $logo_url ) . '" alt="Logo" style="max-height:' . ( $extended ? '120' : '80' ) . 'px;display:block;">';
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

		$db = new SD_Database();
		$db->create_email_template_tables();

		ob_start();
		$forms            = self::get_form_catalog();
		$default_form_key = self::get_default_form_key( $forms );
		$vars             = self::get_variable_list( $default_form_key );
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
		$db->create_email_template_tables();
		$form_key = sanitize_text_field( wp_unslash( $_POST['form_key'] ?? self::DEFAULT_MEMBERSHIP_FORM_KEY ) );
		$rows = $wpdb->get_results(
			"SELECT id, name, subject, template_type, source_form_key, used_variables, created_at, updated_at
			 FROM {$db->table('email_templates')}
			 ORDER BY name ASC"
		);
		$forms = self::get_form_catalog();
		$list  = array();
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $form_key ) && (string) $row->source_form_key !== $form_key ) {
				continue;
			}
			$row->source_form_label = isset( $forms[ $row->source_form_key ]['label'] ) ? (string) $forms[ $row->source_form_key ]['label'] : (string) $row->source_form_key;
			$list[] = $row;
		}

		wp_send_json_success( array( 'templates' => $list ) );
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
		$db->create_email_template_tables();
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

		$forms = self::get_form_catalog();
		$row->source_form_label = isset( $forms[ $row->source_form_key ]['label'] ) ? (string) $forms[ $row->source_form_key ]['label'] : (string) $row->source_form_key;

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
		$form_key  = sanitize_text_field( wp_unslash( $_POST['source_form_key'] ?? self::DEFAULT_MEMBERSHIP_FORM_KEY ) );
		$template_type = self::get_template_type_for_form( $form_key );
		// Il corpo e la firma sono HTML: usiamo wp_kses_post per sanificare ma preservare HTML
		$body      = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );
		$signature = wp_kses_post( wp_unslash( $_POST['signature'] ?? '' ) );
		$forms     = self::get_form_catalog();

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Il nome del modello è obbligatorio.', 'sd-logbook' ) ) );
		}
		if ( empty( $subject ) ) {
			wp_send_json_error( array( 'message' => __( "L'oggetto è obbligatorio.", 'sd-logbook' ) ) );
		}
		if ( empty( $body ) ) {
			wp_send_json_error( array( 'message' => __( 'Il corpo del messaggio è obbligatorio.', 'sd-logbook' ) ) );
		}
		if ( empty( $forms[ $form_key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Seleziona un modulo di iscrizione valido.', 'sd-logbook' ) ) );
		}
		if ( ! self::is_valid_template_type( $template_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Tipo template non valido.', 'sd-logbook' ) ) );
		}

		$used_variables = self::extract_used_variables( $subject, $body, $signature );
		$allowed_tags   = array();
		foreach ( (array) $forms[ $form_key ]['variables'] as $variable ) {
			if ( ! empty( $variable['tag'] ) ) {
				$allowed_tags[] = strtolower( (string) $variable['tag'] );
			}
		}
		foreach ( $used_variables as $tag ) {
			if ( ! in_array( strtolower( $tag ), $allowed_tags, true ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: variable tag */
							__( 'La variabile %s non è disponibile per il modulo selezionato.', 'sd-logbook' ),
							$tag
						),
					)
				);
			}
		}

		$data = array(
			'name'            => $name,
			'template_type'   => $template_type,
			'source_form_key' => $form_key,
			'subject'         => $subject,
			'body'            => $body,
			'signature'       => $signature,
			'used_variables'  => wp_json_encode( $used_variables ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s' );

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
				'source_form_key' => $form_key,
				'message' => __( 'Modello salvato con successo.', 'sd-logbook' ),
			)
		);
	}

	// =========================================================================
	// AJAX: DUPLICATE
	// =========================================================================

	public function ajax_duplicate() {
		check_ajax_referer( 'sd_email_tpl_nonce', 'nonce' );
		if ( ! $this->check_access() ) {
			wp_send_json_error( array( 'message' => __( 'Accesso negato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db = new SD_Database();
		$db->create_email_template_tables();
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

		$inserted = $wpdb->insert(
			$db->table( 'email_templates' ),
			array(
				'name'            => sprintf( __( '%s (copia)', 'sd-logbook' ), (string) $row->name ),
				'template_type'   => (string) $row->template_type,
				'source_form_key' => (string) $row->source_form_key,
				'subject'         => (string) $row->subject,
				'body'            => (string) $row->body,
				'signature'       => (string) $row->signature,
				'used_variables'  => (string) $row->used_variables,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			$detail = ! empty( $wpdb->last_error ) ? ' (' . $wpdb->last_error . ')' : '';
			wp_send_json_error( array( 'message' => __( 'Errore nella duplicazione.', 'sd-logbook' ) . $detail ) );
		}

		wp_send_json_success(
			array(
				'id'      => (int) $wpdb->insert_id,
				'message' => __( 'Modello duplicato con successo.', 'sd-logbook' ),
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
		$db->create_email_template_tables();
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
	 * @param object|null $member       Dati socio/registrazione per la sostituzione variabili.
	 * @param array       $args         Argomenti opzionali, es. form_key per compatibilità.
	 * @return array{subject:string, body:string, signature:string}|null
	 */
	public static function build( int $template_id, ?object $member = null, array $args = array() ): ?array {
		global $wpdb;
		$db  = new SD_Database();
		$db->create_email_template_tables();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('email_templates')} WHERE id = %d",
				$template_id
			)
		);
		if ( ! $row ) {
			return null;
		}
		$form_key = sanitize_text_field( (string) ( $args['form_key'] ?? '' ) );
		if ( '' !== $form_key && ! self::is_template_compatible_with_form( $row, $form_key ) ) {
			return null;
		}

		return array(
			'subject'   => self::resolve( (string) $row->subject, $member ),
			'body'      => self::resolve( (string) $row->body, $member ),
			'signature' => self::resolve( (string) $row->signature, $member ),
		);
	}

	/**
	 * Restituisce tutti i template come array semplice (id => name), filtrabili per tipo o modulo compatibile.
	 *
	 * @param array<string,string> $args Argomenti opzionali.
	 * @return array<int,string>
	 */
	public static function get_all_as_options( array $args = array() ): array {
		global $wpdb;
		$db   = new SD_Database();
		$db->create_email_template_tables();
		$args = wp_parse_args(
			$args,
			array(
				'template_type' => '',
				'form_key'      => '',
			)
		);
		$template_type = sanitize_key( (string) $args['template_type'] );
		$form_key      = sanitize_text_field( (string) $args['form_key'] );
		$rows = $wpdb->get_results(
			"SELECT id, name, template_type, source_form_key, subject, body, signature, used_variables FROM {$db->table('email_templates')} ORDER BY name ASC"
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( '' !== $template_type && sanitize_key( (string) $r->template_type ) !== $template_type ) {
				continue;
			}
			if ( '' !== $form_key && ! self::is_template_compatible_with_form( $r, $form_key ) ) {
				continue;
			}
			$out[ (int) $r->id ] = (string) $r->name;
		}
		return $out;
	}
}
