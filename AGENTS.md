# AGENTS.md — ScubaDiabetes Logbook

Plugin WordPress (PHP 8.2, WP ≥ 6.0) per logbook subacqueo, gestione soci, eventi/attività, pagamenti (Stripe/PayPal/Fattura/TWINT via Infomaniak) e integrazioni CGM (Nightscout, Dexcom, Tidepool, LibreView, CareLink). Lingua del progetto e dei messaggi utente: **italiano**.

## Comandi essenziali

- Install dev deps: `composer install`
- Lint (PHPCS WordPress standard): `./vendor/bin/phpcs -n`
- CI: `.github/workflows/php-lint.yml` (PHPCS su push/PR a `main`)
- Deploy: `.github/workflows/deploy.yml` (rsync via SSH dopo CI verde, matrice multi-target)
- Quick commit & push: [git-push.ps1](git-push.ps1)

Non c'è una suite di test PHPUnit. I file in `tools/` sono **script manuali** di esplorazione/debug API Infomaniak — non eseguirli automaticamente.

## Architettura (vista veloce)

- Bootstrap: [scubadiabetes-logbook.php](scubadiabetes-logbook.php) → classe singleton `SD_Logbook` che esegue `load_dependencies()` (require di tutto `includes/class-sd-*.php`) e `init_components()` su `init`.
- Versioni gestite a mano in 3 punti, vanno sempre **allineate**:
  - `SD_LOGBOOK_VERSION` (header file + costante) → usata per cache-busting di `wp_enqueue_*` (vedi nota in [.memories/repo/activity-ui-cache-and-ordering.md](#memorie-di-repository))
  - `SD_Logbook::DB_VERSION` → triggera migrazioni in `on_plugins_loaded()`
  - `readme.txt` header e changelog
- `src/Plugin.php` (namespace `ScubaDiabetes\Logbook\`, PSR-4) è uno scheletro non ancora attivo; **il codice reale vive in `includes/class-sd-*.php` (no namespace, prefix `SD_`)**. Non spostare classi senza richiesta esplicita.
- Database: tutte le tabelle create da [class-sd-database.php](includes/class-sd-database.php) con prefisso `{wpdb->prefix}sd_`. Migrazioni → aggiungi metodo + bump `DB_VERSION`.
- Ruoli/capabilities custom: [class-sd-roles.php](includes/class-sd-roles.php) (`sd_diver_diabetic`, `sd_diver`, `sd_medical`, `sd_staff`). Le capability `sd_*` sono registrate qui — non vengono validate da PHPCS (vedi `phpcs.xml`).
- Template frontend in `templates/*.php`, asset in `assets/{css,js}/`. Gli shortcode enqueueano JS/CSS con `SD_LOGBOOK_VERSION` come versione.
- Sistema pagamenti: orchestrator `SD_Payment_Orchestrator` + adapter (`paypal`, `stripe`, `fattura`, Infomaniak/TWINT). Documentazione di flusso: [ACTIVITY_SYSTEM_DOCS.md](ACTIVITY_SYSTEM_DOCS.md), stato implementazione: [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md), frontend reg.: [PHASE_4_FRONTEND_REGISTRATION_COMPLETE.md](PHASE_4_FRONTEND_REGISTRATION_COMPLETE.md).

## Convenzioni del codebase

- Stile: WordPress Coding Standards via [phpcs.xml](phpcs.xml). Molte regole sono **disabilitate intenzionalmente** (commenting, escape output, nonce verification, prepared SQL, file naming). Non riabilitarle né aggiungere docblock/refactor di stile su codice non toccato dalla richiesta.
- I18n: textdomain `sd-logbook`, locale forzato `it_IT` (`force_italian_locale`). Tutte le stringhe utente vanno wrappate (`__`, `esc_html_e`, `esc_attr_e`).
- Sicurezza: AJAX usa `check_ajax_referer`; tabelle interpolate in `$wpdb->prepare()` (regola DB disattivata in phpcs). Mantieni questo pattern.
- Naming file `includes/class-sd-*.php` con classi `SD_Xxx_Yyy`. Non rinominare in PSR-4.
- Le date business correnti nel progetto sono nel 2026 (vedi docs); non "correggere" verso anni precedenti.

## Pitfall ricorrenti

- Cache stale dopo modifica JS/CSS: assicurati che lo shortcode passi `SD_LOGBOOK_VERSION` a `wp_enqueue_script/style`.
- Ordinamento sezioni form attività: chiave canonica `pricing` (legacy `tariffe` va normalizzata) in `section_meta.layout_order`.
- Tariffe attività admin: il template [templates/activity-admin.php](templates/activity-admin.php) deve contenere gli ID che il JS si aspetta (`#sd-activity-price-form`, `#sd-price-name`, `#sd-price-chf`, `#sd-price-eur`, `#sd-price-rate-note`, `#sd-prices-list`).
- GitHub Actions: **non** usare `secrets.*` dentro `strategy.matrix` (parse error). Risolvere a runtime nello step.
- Taglie maglietta soci: i due blocchi (richiedente principale + familiari) vanno tenuti simmetrici → segui lo skill [membership-form-tshirt-size-sync](.github/skills/membership-form-tshirt-size-sync/SKILL.md).
- Infomaniak/TWINT: endpoint pubblico shop, non `/api/shop/...`. Dettagli operativi in memoria repo (`infomaniak-twint.md`).

## Memorie di repository

Note operative consolidate (consultare con il tool memory prima di lavorare nell'area corrispondente):

- `/memories/repo/activity-pricing-admin-ui.md`
- `/memories/repo/activity-ui-cache-and-ordering.md`
- `/memories/repo/github-actions-matrix-secrets.md`
- `/memories/repo/infomaniak-twint.md`

## Skill disponibili

- [.github/skills/membership-form-tshirt-size-sync/SKILL.md](.github/skills/membership-form-tshirt-size-sync/SKILL.md) — sync opzioni taglia maglietta nel form iscrizione soci.
