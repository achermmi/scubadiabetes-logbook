# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Panoramica del progetto

Plugin WordPress (PHP 8.2, WP ≥ 6.0) per logbook subacqueo e monitoraggio glicemico dell'associazione ScubaDiabetes.ch. Funzionalità principali: registrazione immersioni, monitoraggio glicemia, integrazioni CGM, gestione soci, iscrizioni ad attività/eventi e pagamenti (Stripe/PayPal/Fattura/TWINT via Infomaniak). **Lingua del progetto: italiano** — tutte le stringhe visibili all'utente devono essere in italiano e wrappate con `__()`, `esc_html_e()` o `esc_attr_e()` usando il textdomain `sd-logbook`.

## Comandi essenziali

```bash
# Installa le dipendenze dev (PHPCS + WordPress Coding Standards)
composer install

# Esegui il linter (obbligatorio prima di ogni commit — la CI rigetta le violazioni)
./vendor/bin/phpcs -n

# Non esiste una suite di test — il linting è l'unico controllo automatico
```

La CI esegue `phpcs -n` su ogni push/PR verso `main`. Il deploy su Infomaniak (via rsync su SSH) parte automaticamente dopo che la CI è verde.

## Architettura

### Bootstrap

`scubadiabetes-logbook.php` → singleton `SD_Logbook`:
1. `load_dependencies()` — `require_once` di tutti i file `includes/class-sd-*.php` + autoloader Composer
2. `init_hooks()` — registra gli hook di attivazione/disattivazione, `plugins_loaded` e `init`
3. `init_components()` — istanzia tutte le classi di servizio; ognuna registra autonomamente shortcode e handler AJAX

### Versioni (3 punti da tenere sempre allineati)

- Costante `SD_LOGBOOK_VERSION` (header + define) — usata come versione di cache-busting per `wp_enqueue_script/style`
- `SD_Logbook::DB_VERSION` — trigera le migrazioni dello schema in `on_plugins_loaded()`
- Header e changelog in `readme.txt`

**Aggiornare sempre tutti e tre** ad ogni rilascio. CSS/JS stale dopo una modifica è quasi sempre un version bump dimenticato.

### Organizzazione del codice

- **`includes/class-sd-*.php`** — tutta la logica di business reale; nessun namespace; prefisso classi `SD_`
- **`templates/*.php`** — HTML frontend renderizzato via `ob_start()` + `include`; inclusi dagli shortcode
- **`assets/js/`** e **`assets/css/`** — un file per funzionalità; caricati dalla classe shortcode corrispondente tramite `wp_localize_script()` per i dati AJAX
- **`src/Plugin.php`** — scheletro PSR-4 (`ScubaDiabetes\Logbook\`), non ancora attivo; non spostare classi qui senza richiesta esplicita
- **`vendor/`** — dipendenze Composer: PhpSpreadsheet (export Excel), dompdf (generazione PDF)

### Database

Tutte le tabelle sono create da `SD_Database` con prefisso `{$wpdb->prefix}sd_`. Per aggiungere una modifica allo schema: aggiungere un metodo a `SD_Database`, chiamarlo da `activate()` e fare il bump di `DB_VERSION`. Le tabelle **non vengono mai eliminate alla disattivazione** (preservazione dei dati scientifici).

Principali gruppi di tabelle: `sd_dives` / `sd_dive_diabetes` (logbook), `sd_members` / `sd_diver_profiles` (soci), `sd_activity_*` (eventi), `sd_payments` / `sd_activity_payments` (transazioni), tabelle `sd_*_oauth` e di sync (integrazioni CGM), `sd_pdf_templates`, `sd_email_templates`, `sd_audit_log`.

### Ruoli utente

Definiti in `SD_Roles`: `sd_diver_diabetic`, `sd_diver`, `sd_medical`, `sd_staff`. Le capability custom `sd_*` non vengono validate da PHPCS (regola disabilitata in `phpcs.xml`).

### Pattern AJAX

Tutta l'UI dinamica passa per WordPress AJAX. Ogni azione viene registrata due volte:
```php
add_action( 'wp_ajax_sd_my_action', ... );
add_action( 'wp_ajax_nopriv_sd_my_action', ... );  // quando serve accesso pubblico
```
Gli handler chiamano `check_ajax_referer()` prima di elaborare. I nomi di tabella possono essere interpolati in `$wpdb->prepare()` — la regola PreparedSQL è degradata a warning in `.phpcs.xml`.

### Sistema pagamenti

`SD_Payment_Orchestrator` smista le richieste agli adapter: `SD_Payment_Stripe`, `SD_Payment_PayPal`, `SD_Payment_Fattura` (fattura manuale). Valuta base: CHF; conversione EUR via API XE.com, con cache in `sd_currency_rates`. Tasso di fallback: 1.05. Vedere `ACTIVITY_SYSTEM_DOCS.md` per i dettagli del flusso di pagamento.

### Regole di linting

`.phpcs.xml` (strict, usato dalla CI): standard WordPress-Core, PHP 8.0+, warning soppressi (`-n`), scansiona `includes/`, `src/` e il file principale del plugin.

`phpcs.xml` (lenient, legacy): ~15 regole disabilitate tra cui escaping, verifica nonce, commenti e naming dei file. **Non riabilitare queste regole né aggiungere docblock o refactoring di stile su codice non toccato dalla richiesta.**

## Convenzioni del codebase

- **Naming file**: `includes/class-sd-kebab-case.php` con classe `SD_PascalCase`. Non rinominare in PSR-4.
- **Registrazione shortcode**: ogni classe componente registra i propri shortcode nel costruttore.
- **Date**: le date business nel progetto sono nel 2026 — non "correggere" verso anni precedenti.
- **GitHub Actions**: non usare mai `secrets.*` dentro `strategy.matrix` (errore di parse); risolvere i secret a runtime dentro lo step.

## Pitfall ricorrenti

- **Ordinamento sezioni form attività**: la chiave canonica è `pricing` (la chiave legacy `tariffe` va normalizzata) in `section_meta.layout_order`.
- **Template admin attività**: `templates/activity-admin.php` deve contenere gli ID attesi dal JS: `#sd-activity-price-form`, `#sd-price-name`, `#sd-price-chf`, `#sd-price-eur`, `#sd-price-rate-note`, `#sd-prices-list`.
- **Taglie maglietta** nel form iscrizione soci: i due blocchi (richiedente principale + familiari) vanno tenuti simmetrici — vedere `.github/skills/membership-form-tshirt-size-sync/SKILL.md`.
- **Infomaniak/TWINT**: usa l'endpoint pubblico del shop, non `/api/shop/...`.

## Skill disponibili

- `.github/skills/membership-form-tshirt-size-sync/SKILL.md` — sync delle opzioni taglia maglietta nel form di iscrizione soci.
