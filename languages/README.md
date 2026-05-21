# Traduzioni — ScubaDiabetes Logbook

Lingua sorgente: **italiano (it_IT)**.

Dalla versione 1.3.78 il plugin **non forza più** la lingua italiana: WPML,
Polylang, Loco Translate e il selettore di lingua di WordPress sono pienamente
supportati.

## File presenti

- `sd-logbook.pot` — template di traduzione (placeholder; rigenerare con
  `wp i18n make-pot . languages/sd-logbook.pot` per estrarre tutte le stringhe).
- `sd-logbook-<locale>.po` / `.mo` — traduzioni per locale specifici (es.
  `sd-logbook-fr_FR.mo`, `sd-logbook-de_DE.mo`, `sd-logbook-en_US.mo`).
- `sd-logbook-<locale>-<handle>.json` — traduzioni JavaScript prodotte da
  `wp i18n make-json` per gli script che usano `wp.i18n` (es.
  `sd-logbook-fr_FR-sd-activity-admin.json`).

## Forzare comunque l'italiano

Se per qualche ragione si vuole ripristinare il vecchio comportamento:

```php
add_filter( 'sd_logbook_force_italian', '__return_true' );
```

oppure salvare l'opzione `sd_logbook_force_italian` a `1`.
