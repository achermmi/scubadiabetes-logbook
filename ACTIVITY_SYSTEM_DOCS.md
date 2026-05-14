# 🎯 GUIDA INTEGRAZIONE SISTEMA ATTIVITÀ ScubaDiabetes v3.7.0

## 📋 INDICE
1. [Architettura](#architettura)
2. [Database Schema](#database-schema)
3. [API CRUD](#api-crud)
4. [Integrazione Pagamenti](#integrazione-pagamenti)
5. [Template Frontend](#template-frontend)
6. [Dashboard Admin](#dashboard-admin)
7. [Esempi di Utilizzo](#esempi-di-utilizzo)

---

## ARCHITETTURA

### Componenti Implementati ✅
```
SD_Logbook (Main Plugin)
├── SD_Activity_Manager        ✅ API CRUD + AJAX handlers
├── SD_Currency_Converter      ✅ Conversione CHF/€ + caching
├── SD_Payment_Orchestrator    ✅ (Riusabile) Sistema pagamenti
└── (Nuovi) Template + Admin Dashboard
```

### Flusso Completo
```
FRONTEND (Utente)
  1. [Lista Attività] → Clicca "Iscriviti"
  2. [Modulo Iscrizione] → Compila campi dinamici
  3. [Selezione Tariffa] → Card-based UI (come soci)
  4. [Checkout Pagamento] → Metodo: Fattura/PayPal/Stripe
  5. [Conferma] → Ricevuta + Email

BACKEND (Admin)
  1. [Gestione Attività] → Crea/Modifica evento
  2. [Builder Modulo] → Aggiungi campi dinamici
  3. [Configurazione Tariffe] → Prezzo CHF + € auto
  4. [Dashboard Registrazioni] → Filtri + Esportazione
  5. [Gestione Pagamenti] → Mark as Paid + Email
```

---

## DATABASE SCHEMA

### Tabella: sd_activities
```sql
id, title, description, start_date, end_date, location,
max_participants, current_participants, event_status (draft|published|closed|archived),
thumbnail_url, form_configuration (JSON), price_configuration (JSON),
created_by, created_at, updated_at
```

### Tabella: sd_activity_registrations
```sql
id, activity_id (FK), member_id (nullable), email, first_name, last_name,
registration_data (JSON fields), status (registered|waitlist|cancelled),
payment_status (pending|paid|invoice_sent|cancelled),
payment_date, price_id (FK), price_chf, price_eur,
payment_method, transaction_id, invoice_number,
confirmation_token (per checkout), confirmation_expires_at,
invoice_pdf_path, receipt_pdf_path,
is_notification_sent, created_by, created_at, updated_at
```

### Tabella: sd_activity_payments
```sql
id, registration_id (FK), activity_id (FK), member_id,
amount_chf, amount_eur, payment_method,
status (in_attesa|completato|fallito),
transaction_id, provider_payment_id, confirmation_token,
invoice_pdf_path, receipt_pdf_path, completed_at,
provider_payload (JSON - risposta Stripe/PayPal)
```

### Tabella: sd_currency_rates
```sql
id, rate_date (UNIQUE), chf_to_eur, source (xe.com),
updated_at
```

---

## API CRUD

### Classe: SD_Activity_Manager

#### METODI PUBBLICI

##### Attività
```php
// Creare attività
$activity_id = SD_Activity_Manager::get_instance()->create_activity([
    'title'              => 'Settimana Blu 2026',
    'description'        => '...',
    'start_date'         => '2026-07-01 09:00:00',
    'end_date'           => '2026-07-07 17:00:00',
    'location'           => 'Portofino, Italy',
    'max_participants'   => 25,
    'event_status'       => 'draft',
    'thumbnail_url'      => 'https://...',
    'form_configuration' => [ /* JSON config */ ],
    'price_configuration'=> [ /* JSON config */ ],
]);

// Recuperare attività
$activity = SD_Activity_Manager::get_instance()->get_activity($activity_id);
// Ritorna: array con form_fields, prices, registrations_count decodificati

// Aggiornare attività
SD_Activity_Manager::get_instance()->update_activity($activity_id, [
    'title'        => 'Nuovo titolo',
    'event_status' => 'published',
]);

// Eliminare attività (cascata su FOREIGN KEY)
SD_Activity_Manager::get_instance()->delete_activity($activity_id);
```

##### Tariffe
```php
// Creare tariffa
$price_id = SD_Activity_Manager::get_instance()->create_price($activity_id, [
    'price_name'         => 'Corso OWD + Alloggio',
    'price_chf'          => 150.00,
    'price_eur'          => null, // Auto-calcolato da convert
    'currency_rate'      => 1.05,
    'currency_rate_date' => '2026-05-12',
    'is_default'         => true,
]);

// Recuperare tariffe
$prices = SD_Activity_Manager::get_instance()->get_activity_prices($activity_id);
// Ritorna: array di tariffe con CHF + EUR
```

##### Campi Modulo
```php
// Creare campo modulo
SD_Activity_Manager::get_instance()->create_form_field($activity_id, [
    'field_type'   => 'text',    // text|textarea|select|checkbox|number|date|file|image
    'field_name'   => 'luogo_nascita',
    'field_label'  => 'Luogo di Nascita',
    'placeholder'  => 'es. Milano',
    'is_required'  => true,
    'field_order'  => 1,
    'options'      => [], // Per select/checkbox
]);

// Recuperare campi
$fields = SD_Activity_Manager::get_instance()->get_form_fields($activity_id);
// Ritorna: array di campi ordinati per field_order
```

##### Iscrizioni
```php
// Registrare iscritto
$registration_id = SD_Activity_Manager::get_instance()->register_for_activity($activity_id, [
    'member_id'         => null,      // Se utente loggato
    'email'             => 'test@example.com',
    'first_name'        => 'Mario',
    'last_name'         => 'Rossi',
    'registration_data' => [          // Dati dai campi modulo
        'luogo_nascita' => 'Milano',
        'diabetes_type' => 'tipo_1',
        // ... altri campi
    ],
    'price_id'          => $price_id,
    'price_chf'         => 150.00,
    'price_eur'         => 157.50,    // Auto-calcolato
]);

// Recuperare iscrizioni con filtri
$registrations = SD_Activity_Manager::get_instance()->get_registrations($activity_id, [
    'per_page'       => 20,
    'page'           => 1,
    'status'         => 'registered',           // o 'waitlist'
    'payment_status' => 'paid',                 // o 'pending'|'invoice_sent'
    'search'         => 'mario',                // Cerca in nome/cognome/email
    'orderby'        => 'created_at',
    'order'          => 'DESC',
]);

// Contare iscritti (esclusi cancellati)
$count = SD_Activity_Manager::get_instance()->get_registrations_count($activity_id);

// Aggiornare stato pagamento iscrizione
SD_Activity_Manager::get_instance()->update_registration_payment_status(
    $registration_id,
    'paid',  // pending|paid|invoice_sent|cancelled
    [
        'payment_date'   => '2026-05-12',   // DD.MM.YYYY
        'payment_method' => 'fattura',
        'transaction_id' => 'TXN-001',
        'invoice_number' => 'INV-001',
    ]
);
```

---

## INTEGRAZIONE PAGAMENTI

### Strategia: Riuso SD_Payment_Orchestrator

Il sistema di attività riusa la stessa infrastruttura di pagamento dei soci:

#### 1. Prepare Checkout

```php
$payment_orchestrator = new SD_Payment_Orchestrator();

$payment_context = $payment_orchestrator->prepare_checkout_activity(
    $registration_id,     // Chiave esterna
    'activity',            // Tipo: 'activity' o 'membership'
    $amount_chf,
    'activity_registration'
);

// Ritorna:
// {
//   'token': 'abc123...',
//   'checkout_url': '/?sdpt=abc123&action=checkout_activity',
//   'confirmation_url': '/?sdpt=abc123&action=confirm_activity'
// }
```

#### 2. Metodi Pagamento Supportati

```
Fattura (Bonifico IBAN)
  - Genera PDF con QR TWINT
  - Email con allegato
  - Admin marca come pagato

PayPal
  - Redirecta a PayPal
  - Webhook conferma pagamento
  - Auto-marca come pagato

Stripe (Card + TWINT)
  - Checkout session
  - Card: Visa, Mastercard, Apple Pay, Google Pay
  - TWINT: CHF Svizzera (no EUR)
  - Webhook conferma
  - Auto-marca come pagato
```

#### 3. Flusso Webhook

```php
// In SD_Payment_Flow::handle_stripe_webhook()

if ('activity' === $payment_type && 'charge.succeeded' === $event) {
    $registration_id = $provider_payload['metadata']['registration_id'];
    
    // Aggiorna iscrizione
    SD_Activity_Manager::get_instance()->update_registration_payment_status(
        $registration_id,
        'paid',
        [
            'payment_date'      => date('d.m.Y'),
            'payment_method'    => 'stripe_card',
            'transaction_id'    => $charge['id'],
        ]
    );
    
    // Invia email
    sd_send_activity_payment_confirmation($registration_id);
}
```

---

## TEMPLATE FRONTEND

### Shortcode: [sd_attivita]

```php
// File: templates/activity-list.php
[sd_attivita]
// Mostra: Lista calendaria attività con "Iscriviti"
```

### Shortcode: [sd_iscrizione_attivita]

```php
// File: templates/activity-registration-form.php
// Parametri:
//   activity_id (required)
//   modal (true/false - default false)

// Uso:
[sd_iscrizione_attivita activity_id="5" modal="true"]

// Funzionalità:
// 1. Recupera campi modulo da SD_Activity_Manager
// 2. Rendering dinamico (text, select, checkbox, etc.)
// 3. Validazione lato client (JS)
// 4. Selezione tariffa (card-based)
// 5. Conversion CHF→EUR live (ajax sd_get_eur_price)
// 6. AJAX submit → register_for_activity()
// 7. Redirect a pagamento
```

### Template Modulo (layout)

```html
<form id="sd-activity-registration-form">
  <nonce>
  
  <!-- Sezione 1: Dati Personali -->
  <input type="text" name="first_name" required />
  <input type="text" name="last_name" required />
  <input type="email" name="email" required />
  
  <!-- Sezione 2: Campi Dinamici (da DB) -->
  <!-- Generated da get_form_fields() -->
  <select name="field_diabetes_type">...</select>
  <textarea name="field_altre_esigenze"></textarea>
  <!-- etc. -->
  
  <!-- Sezione 3: Selezione Tariffa -->
  <div class="sd-fee-cards">
    <label class="sd-fee-card">
      <input type="radio" name="price_id" value="1" />
      <div class="sd-fee-card-inner">
        <span class="sd-fee-price">CHF 150 = € 157.50</span>
        <span class="sd-fee-label">Corso OWD + Alloggio</span>
      </div>
    </label>
    <!-- ... altre tariffe ... -->
  </div>
  
  <!-- Sezione 4: Consenso -->
  <label>
    <input type="checkbox" name="privacy_consent" required />
    Ho letto l'informativa sulla privacy
  </label>
  
  <button type="submit">Procedi al Pagamento</button>
</form>

<script>
// AJAX POST: sd_activity_register
// Payload: { nonce, activity_id, first_name, last_name, email, 
//            price_id, field_*, privacy_consent }
// Response: { registration_id, redirect_url }
// Action: Redirect a pagamento
</script>
```

---

## DASHBOARD ADMIN

### Shortcode: [sd_gestione_attivita]

```php
// Accesso: manage_options

// Funzionalità:
// 1. Tab: Attività
//    - Lista attività con status badge
//    - Pulsanti: Modifica, Visualizza, Elimina
//    - Search + Filter per status

// 2. Tab: Modifica Attività
//    - Form: Titolo, Data inizio/fine, Location
//    - Builder Modulo: Drag-drop campi
//    - Configurazione Tariffe: Aggiungi/Modifica prezzi CHF+EUR
//    - Preview modulo

// 3. Tab: Registrazioni
//    - Tabella: Nome, Email, Status, Pagamento, Tariffa
//    - Filtri: Attività, Periodo, Status Pagamento, Search
//    - Esporta: CSV con tutti i dati
//    - Azioni: Modifica, Mark as Paid (con data), Risendi email

// 4. Tab: Pagamenti
//    - Filtro per payment_status
//    - Bulk: Invia reminder a non-pagati
//    - Download ricevuta/fattura
//    - Storico transazioni Stripe/PayPal
```

### Componenti da Sviluppare

```javascript
// File: assets/js/activity-admin.js

// 1. Activity CRUD
function saveActivity() { /* POST sd_activity_save */ }
function deleteActivity(id) { /* POST sd_activity_delete */ }

// 2. Form Field Builder
function addFormField() { /* Aggiungi riga */ }
function removeFormField(id) { /* Rimuovi riga */ }
function updateFormFieldOrder() { /* Drag-drop */ }

// 3. Price Manager
function addPrice() { /* Aggiungi tariffa */ }
function updatePrice(id) { /* Modifica */ }
function removePrice(id) { /* Elimina */ }
function autoCalculateEUR(priceCHF) { /* AJAX sd_get_eur_price */ }

// 4. Registrations Table
function filterRegistrations() { /* Filtri */ }
function searchRegistrations(term) { /* Search */ }
function updatePaymentStatus(regId, status) { /* POST sd_activity_registration_update_payment */ }
function exportRegistrations() { /* Export CSV */ }
```

---

## ESEMPI DI UTILIZZO

### Esempio 1: Creare un'Attività

```php
// File: Qualsiasi template/plugin

$manager = SD_Activity_Manager::get_instance();

// 1. Creare attività
$activity_id = $manager->create_activity([
    'title'         => 'Settimana Blu Portofino 2026',
    'description'   => 'Una settimana indimenticabile...',
    'start_date'    => '2026-07-01 09:00:00',
    'end_date'      => '2026-07-07 17:00:00',
    'location'      => 'Portofino, Italy',
    'max_participants' => 25,
    'event_status'  => 'draft',
]);

// 2. Aggiungere tariffe
$manager->create_price($activity_id, [
    'price_name'  => 'OWD + Alloggio',
    'price_chf'   => 150.00,
    'is_default'  => true,
]);

$manager->create_price($activity_id, [
    'price_name'  => 'Solo Corso',
    'price_chf'   => 75.00,
]);

// 3. Aggiungere campi modulo
$manager->create_form_field($activity_id, [
    'field_type'  => 'select',
    'field_name'  => 'diabetes_type',
    'field_label' => 'Tipo di Diabete',
    'is_required' => true,
    'field_order' => 1,
    'options'     => json_encode([
        'non_diabetico' => 'Non Diabetico',
        'tipo_1'        => 'Tipo 1',
        'tipo_2'        => 'Tipo 2',
    ]),
]);

$manager->create_form_field($activity_id, [
    'field_type'   => 'textarea',
    'field_name'   => 'altre_esigenze',
    'field_label'  => 'Altre Esigenze',
    'is_required'  => false,
    'field_order'  => 2,
]);

// 4. Pubblicare
$manager->update_activity($activity_id, [
    'event_status' => 'published',
]);
```

### Esempio 2: Iscrizione e Pagamento

```php
// File: Custom endpoint / AJAX handler

// Utente compila modulo e clicca "Iscriviti"

$registration_id = $manager->register_for_activity($activity_id, [
    'member_id' => get_current_user_id() ?: null,
    'email'     => 'user@example.com',
    'first_name' => 'Mario',
    'last_name'  => 'Rossi',
    'registration_data' => [
        'diabetes_type'  => 'tipo_1',
        'altre_esigenze' => 'Allergia al pesce',
    ],
    'price_id'   => 1,
    'price_chf'  => 150.00,
    'price_eur'  => 157.50,  // Calcolato da SD_Currency_Converter
]);

// Redirect a pagamento
$payment_orchestrator = new SD_Payment_Orchestrator();
$payment_context = $payment_orchestrator->prepare_checkout_activity(
    $registration_id,
    'activity',
    150.00
);

wp_redirect($payment_context['checkout_url']);
```

### Esempio 3: Aggiornare Pagamento (da Admin)

```php
// Admin segretariato riceve bonifico e marca come pagato

$manager->update_registration_payment_status(
    $registration_id,
    'paid',
    [
        'payment_date'   => date('d.m.Y'),
        'payment_method' => 'fattura',
        'invoice_number' => 'INV-2026-001',
    ]
);

// Invia email di conferma
do_action( 'sd_activity_payment_completed', $registration_id );
```

---

## NOTE IMPORTANTI

### Localizzazione
- Tutte le stringhe usano `__()` / `esc_html_e()` con domain `'sd-logbook'`
- Format date: `DD.MM.YYYY` per display
- Valuta: CHF primaria, EUR secondaria

### Sicurezza
- Tutti gli AJAX verificano nonce `sd_nonce`
- Input sanitizzato con `sanitize_*` functions
- Output escaped con `esc_html()`, `esc_url()`, `esc_attr()`
- Permessi verificati con `current_user_can()`

### Performance
- Currency converter usa caching giornaliero
- Form fields JSON in DB (1 query per attività)
- Registrazioni paginate (per_page default 20)

### Integrazione Stripe/PayPal
- Riusa classi esistenti: `SD_Payment_Stripe`, `SD_Payment_PayPal`, `SD_Payment_Fattura`
- Metadata Stripe include: `registration_id`, `activity_id`, `type: 'activity'`
- Webhook route: `/?sd_stripe_webhook=1` per activity

---

## RISORSE
- [XE.com API Docs](https://xecdapi.xe.com/)
- [Stripe API](https://stripe.com/docs/api)
- [PayPal API](https://developer.paypal.com/)
- [WordPress AJAX](https://developer.wordpress.org/plugins/javascript/ajax/)

---

**Versione**: 3.7.0 | **Ultima modifica**: 12 Maggio 2026 | **Autore**: Mirko Achermann
