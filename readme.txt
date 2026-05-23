=== ScubaDiabetes Logbook ===
Contributors: mirkoachermann
Tags: diving, diabetes, logbook, scuba, medical
Requires at least: 6.0
Tested up to: 6.9.1
Requires PHP: 7.4
Stable tag: 1.3.88
License: GPLv2 or later

Logbook subacqueo per persone con diabete. Registrazione immersioni, monitoraggio glicemico, raccolta dati scientifici secondo il protocollo Diabete Sommerso.

== Descrizione ==

Plugin per WordPress che permette la registrazione e il monitoraggio delle immersioni subacquee per persone con e senza diabete.

== Installazione ==

1. Caricare la cartella `scubadiabetes-logbook` in `/wp-content/plugins/`
2. Attivare il plugin dal menu Plugins in WordPress
3. Assegnare i ruoli appropriati agli utenti

== Changelog ==

= 1.3.88 =
* Nuovo: Supporto immagini nel designer PDF — Media Library WP, ridimensionamento 2D, rotazione, riflesso orizzontale/verticale, opacità, modalità sfondo.
* Nuovo: Caricamento PDF come sfondo di pagina (richiede Ghostscript sul server per la preview).

= 1.3.85 =
* Nuovo: Designer PDF drag-and-drop per template attività/registrazioni (shortcode [sd_pdf_template_designer]).
  Permette di creare layout A4 posizionando liberamente i campi sul canvas, salvare i template su DB, generare PDF con dompdf.
* DB: aggiunta tabella sd_pdf_templates (bump DB_VERSION 3.8.0).

= 1.3.84 =
* Generazione PDF base per attività e registrazioni (PDF lista, scheda attività, scheda singola registrazione).

= 1.3.83 =
* Fix: badge stato e stato pagamento nella tabella registrazioni attività ora usano il pattern pill-wrap compatto (come la tabella rinnovi soci)

= 1.3.82 =
* Fix: corrispondenza chiave PHP→JS nella risposta AJAX registrazioni: era 'rows', ora 'registrations' (le registrazioni non venivano mai mostrate).
* Diagnostica: aggiunto error_log in ajax_registration_list per tracciare le chiamate.

= 1.3.81 =
* Fix: tab Registrazioni ora mostra correttamente le iscrizioni — corretto mismatch ID tbody (#sd-reg-tbody → #sd-reg-dashboard-tbody) che impediva il rendering della tabella.
* Fix: ordine colonne corretto (Data iscrizione prima di Importo) e aggiunta colonna "Ultima e-mail" con oggetto come tooltip.

= 1.3.80 =
* UX: tab Registrazioni auto-seleziona la prima attività disponibile se nessuna era stata aperta in modifica, correggendo il mancato caricamento della tabella al click del tab.

= 1.3.79 =
* UX: tab Registrazioni ora carica automaticamente le iscrizioni dell'attività corrente al click.
* Export Excel: inclusi tutti i campi del modulo di registrazione (dinamici) oltre ai campi fissi.
* Export Excel: corretto errore 500 (API PhpSpreadsheet v2+).

= 1.3.78 =
* i18n: supporto multilingua reale. Rimossa la forzatura dell'italiano (ora opzionale via `sd_logbook_force_italian`); WPML/Polylang/Loco Translate possono servire traduzioni in tutte le lingue.
* i18n: aggiunta cartella `languages/` con POT placeholder.
* i18n: aggiunto `wp_set_script_translations()` per tutti gli script principali (`sd-activity-admin`, `sd-profile`, `sd-medical`, `sd-dashboard`, `sd-membership-admin`, `sd-cgm-dashboard`, `sd-cgm-medical`, `sd-dive-edit`, `sd-dive-import`, `sd-diabetic-registry`).
* i18n: convertite ~30 stringhe JS hardcoded (`alert/confirm`) a `wp.i18n.__()` con dominio `sd-logbook`.
* i18n: wrappate label hardcoded in `templates/activity-admin.php` e blocchi HTML email in `class-sd-activity-payment-flow.php`.

= 1.0.0 =
* Prima release - Struttura database e ruoli utente
