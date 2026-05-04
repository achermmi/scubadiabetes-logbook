-- =============================================================================
-- IMPORT SOCI DA CSV → WordPress + Plugin ScubaDiabetes
-- =============================================================================
-- Formato CSV atteso:  Nome,Cognome,ruolo,e-mail,diabete
-- Header obbligatorio: la prima riga viene saltata (IGNORE 1 LINES)
--
-- Valori fissi per tutti gli utenti importati:
--   member_type  = 'attivo'       (Tipo Socio = Attivo)
--   fee_amount   = 0.00           (Tassa = 0.00)
--   has_paid_fee = 1              (Pagato = Sì)
--   payment_date = oggi           (Data Pag. = oggi)
--   is_active    = 1              (Attivo = Sì)
--   is_scuba     = 1              (Sub = Sì)
--
-- Prefisso tabelle WP: 'wp_'  ← cambia se usi un prefisso diverso
--
-- ATTENZIONE:
--   • Password di accesso per tutti gli utenti importati: scuba2026diabetes
--     Viene salvata come MD5 (hash legacy WordPress). Al primo accesso
--     WordPress aggiorna automaticamente l'hash a phpass senza interventi.
--   • Testa sempre prima su un DB di sviluppo.
--   • Backup del database prima di eseguire.
-- =============================================================================


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 1 – Tabella temporanea di staging
-- ─────────────────────────────────────────────────────────────────────────────
DROP TEMPORARY TABLE IF EXISTS tmp_sd_import_users;

CREATE TEMPORARY TABLE tmp_sd_import_users (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    nome         VARCHAR(100),
    cognome      VARCHAR(100),
    ruolo_raw    VARCHAR(100),
    email        VARCHAR(255),
    diabete_raw  VARCHAR(50),   -- valore grezzo dal CSV
    diabete_type VARCHAR(30),   -- valore normalizzato (enum del plugin)
    ruolo_wp     VARCHAR(50)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 2 – Inserisci i dati del CSV
--
--   Aggiungi/modifica una riga per ogni socio da importare.
--   Supporta da 1 a 99 soci (o piu') con un INSERT per riga.
--   Colonne: nome, cognome, ruolo_raw, email, diabete_raw
--
--   Valori validi per ruolo_raw:
--     sd_diver_diabetic | sd_diver | sd_medical | sd_staff
--     (se lasci vuoto o non riconosciuto → sd_diver di default)
--
--   Valori validi per diabete_raw:
--     non_diabetico | tipo_1 | tipo_2 | tipo_3c | lada | mody | midd | altro
--     (se lasci vuoto → non_diabetico di default)
--     Se ruolo è sd_diver ma diabete non è non_diabetico → promosso automaticamente a sd_diver_diabetic
-- ─────────────────────────────────────────────────────────────────────────────
-- ▼▼▼ SOSTITUISCI QUESTE RIGHE CON I TUOI DATI ▼▼▼
--  nome          cognome          ruolo_raw               email                           diabete_raw
INSERT INTO tmp_sd_import_users (nome, cognome, ruolo_raw, email, diabete_raw)
VALUES ('Monica',   'Fiumicelli',  'Subacqueo diabetico',  'monicafium@icloud.com',       'Tipo 1');

INSERT INTO tmp_sd_import_users (nome, cognome, ruolo_raw, email, diabete_raw)
VALUES ('Elisa',    'Colombini',   'Subacqueo diabetico',  'elicolo12@gmail.com',         'Tipo 1');

INSERT INTO tmp_sd_import_users (nome, cognome, ruolo_raw, email, diabete_raw)
VALUES ('Dario',    'Bellini',     'Subacqueo',            'dariobellini.db@gmail.com',   'Non diabetico');

INSERT INTO tmp_sd_import_users (nome, cognome, ruolo_raw, email, diabete_raw)
VALUES ('Rita',     'Pettinello',  'Subacqueo',            'rita.pettinello@gmail.com',   'Non diabetico');

INSERT INTO tmp_sd_import_users (nome, cognome, ruolo_raw, email, diabete_raw)
VALUES ('Franco',   'Donzelli',    'Subacqueo',            'francodonzelli74@gmail.com',  'Non diabetico');

INSERT INTO tmp_sd_import_users (nome, cognome, ruolo_raw, email, diabete_raw)
VALUES ('Giovanni', 'Donzelli',    'Subacqueo diabetico',  'giodonzelli72@gmail.com',     'Tipo 1');

INSERT INTO tmp_sd_import_users (nome, cognome, ruolo_raw, email, diabete_raw)
VALUES ('Giovanni', 'Careddu',     'Subacqueo diabetico',  'gcareddu58@gmail.com',        'Tipo 1');
-- ▲▲▲ FINE DATI ▲▲▲


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 3 – Normalizzazione e mapping ruolo CSV → ruolo WordPress
--
--   I ruoli SD del plugin sono:
--     sd_diver_diabetic  → Subacqueo Diabetico
--     sd_diver           → Subacqueo (non diabetico)
--     sd_medical         → Medico
--     sd_staff           → Staff
--
--   Valore di default se il ruolo non è riconosciuto: 'sd_diver'
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE tmp_sd_import_users
SET
    nome         = TRIM(nome),
    cognome      = TRIM(cognome),
    ruolo_raw    = TRIM(ruolo_raw),
    -- Rimuove CR e LF residui da CSV Windows
    email        = LOWER(TRIM(REPLACE(REPLACE(email, CHAR(13), ''), CHAR(10), ''))),
    diabete_raw  = TRIM(REPLACE(REPLACE(diabete_raw, CHAR(13), ''), CHAR(10), '')),
    -- Normalizza diabete: mappa i valori CSV ai valori enum del plugin
    diabete_type = CASE
                       WHEN LOWER(TRIM(diabete_raw)) IN ('non_diabetico', 'non diabetico', 'no', '0', '') THEN 'non_diabetico'
                       WHEN LOWER(TRIM(diabete_raw)) IN ('tipo_1', 'tipo 1', 'type 1', 't1', '1')         THEN 'tipo_1'
                       WHEN LOWER(TRIM(diabete_raw)) IN ('tipo_2', 'tipo 2', 'type 2', 't2', '2')         THEN 'tipo_2'
                       WHEN LOWER(TRIM(diabete_raw)) IN ('tipo_3c', 'tipo 3c', 'type 3c', 't3c', '3c')    THEN 'tipo_3c'
                       WHEN LOWER(TRIM(diabete_raw)) IN ('lada')                                           THEN 'lada'
                       WHEN LOWER(TRIM(diabete_raw)) IN ('mody')                                           THEN 'mody'
                       WHEN LOWER(TRIM(diabete_raw)) IN ('midd')                                           THEN 'midd'
                       WHEN LOWER(TRIM(diabete_raw)) IN ('altro', 'other', 'other/altro')                  THEN 'altro'
                       ELSE 'non_diabetico'  -- default se vuoto o non riconosciuto
                   END,
    -- Ruolo WP: se il ruolo CSV è esplicitamente sd_diver_diabetic lo usa;
    -- altrimenti, se il diabete è diverso da non_diabetico → sd_diver_diabetic automaticamente
    ruolo_wp     = CASE
                       WHEN LOWER(TRIM(ruolo_raw)) IN ('sd_diver_diabetic', 'subacqueo diabetico', 'diabetico', 'diver_diabetic')
                           THEN 'sd_diver_diabetic'
                       WHEN LOWER(TRIM(ruolo_raw)) IN ('sd_medical', 'medico', 'doctor', 'medical')
                           THEN 'sd_medical'
                       WHEN LOWER(TRIM(ruolo_raw)) IN ('sd_staff', 'staff')
                           THEN 'sd_staff'
                       -- Se ruolo CSV è sd_diver ma diabete != non_diabetico → promuovi a diabetico
                       WHEN LOWER(TRIM(ruolo_raw)) IN ('sd_diver', 'subacqueo', 'diver')
                            AND LOWER(TRIM(diabete_raw)) NOT IN ('non_diabetico', 'non diabetico', 'no', '0', '')
                           THEN 'sd_diver_diabetic'
                       WHEN LOWER(TRIM(ruolo_raw)) IN ('sd_diver', 'subacqueo', 'diver')
                           THEN 'sd_diver'
                       -- Nessun ruolo specificato: usa diabete per determinare il ruolo
                       WHEN LOWER(TRIM(diabete_raw)) NOT IN ('non_diabetico', 'non diabetico', 'no', '0', '')
                           THEN 'sd_diver_diabetic'
                       ELSE 'sd_diver'  -- default
                   END;

-- Elimina righe con email vuota o non valida
DELETE FROM tmp_sd_import_users
WHERE email = '' OR email NOT LIKE '%_@_%.__%';

-- Elimina righe duplicate all'interno del CSV stesso (mantieni la prima occorrenza)
DELETE FROM tmp_sd_import_users
WHERE id NOT IN (
    SELECT min_id FROM (
        SELECT MIN(id) AS min_id
        FROM tmp_sd_import_users
        GROUP BY email
    ) AS sub
);


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 4 – Crea gli utenti WordPress (solo se non esistono già per email)
--
--   Password: "scuba2026diabetes"
--   Il valore MD5('scuba2026diabetes') è riconosciuto da WordPress come hash
--   legacy: all'accesso WordPress verifica la corrispondenza MD5 e aggiorna
--   automaticamente l'hash a phpass senza richiedere azioni aggiuntive.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO wp_users (
    user_login,
    user_pass,
    user_nicename,
    user_email,
    user_registered,
    display_name,
    user_status
)
SELECT
    i.email,
    MD5('scuba2026diabetes'),   -- password: scuba2026diabetes (hash MD5 legacy WP)
    -- user_nicename: slug url-friendly
    LOWER(REPLACE(REPLACE(CONCAT(i.nome, '-', i.cognome), ' ', '-'), '\'', '')),
    i.email,
    NOW(),
    CONCAT(i.nome, ' ', i.cognome),
    0
FROM tmp_sd_import_users i
LEFT JOIN wp_users u ON u.user_email = i.email
WHERE u.ID IS NULL;


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 5 – Meta WordPress (usermeta)
-- ─────────────────────────────────────────────────────────────────────────────

-- first_name
INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
SELECT u.ID, 'first_name', i.nome
FROM tmp_sd_import_users i
JOIN wp_users u ON u.user_email = i.email
WHERE NOT EXISTS (
    SELECT 1 FROM wp_usermeta um
    WHERE um.user_id = u.ID AND um.meta_key = 'first_name'
);

-- last_name
INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
SELECT u.ID, 'last_name', i.cognome
FROM tmp_sd_import_users i
JOIN wp_users u ON u.user_email = i.email
WHERE NOT EXISTS (
    SELECT 1 FROM wp_usermeta um
    WHERE um.user_id = u.ID AND um.meta_key = 'last_name'
);

-- nickname
INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
SELECT u.ID, 'nickname', CONCAT(i.nome, ' ', i.cognome)
FROM tmp_sd_import_users i
JOIN wp_users u ON u.user_email = i.email
WHERE NOT EXISTS (
    SELECT 1 FROM wp_usermeta um
    WHERE um.user_id = u.ID AND um.meta_key = 'nickname'
);

-- wp_capabilities (serializzazione PHP: a:1:{s:LENGTH:"ruolo";b:1;})
-- LENGTH() restituisce i byte della stringa — corretto per la deserializzazione PHP
INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
SELECT
    u.ID,
    'wp_capabilities',
    CONCAT('a:1:{s:', LENGTH(i.ruolo_wp), ':"', i.ruolo_wp, '";b:1;}')
FROM tmp_sd_import_users i
JOIN wp_users u ON u.user_email = i.email
WHERE NOT EXISTS (
    SELECT 1 FROM wp_usermeta um
    WHERE um.user_id = u.ID AND um.meta_key = 'wp_capabilities'
);

-- wp_user_level (0 per ruoli non-admin)
INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
SELECT u.ID, 'wp_user_level', '0'
FROM tmp_sd_import_users i
JOIN wp_users u ON u.user_email = i.email
WHERE NOT EXISTS (
    SELECT 1 FROM wp_usermeta um
    WHERE um.user_id = u.ID AND um.meta_key = 'wp_user_level'
);

-- session_tokens (array vuoto — evita warning WP all'accesso)
INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
SELECT u.ID, 'session_tokens', 'a:0:{}'
FROM tmp_sd_import_users i
JOIN wp_users u ON u.user_email = i.email
WHERE NOT EXISTS (
    SELECT 1 FROM wp_usermeta um
    WHERE um.user_id = u.ID AND um.meta_key = 'session_tokens'
);


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 6 – Inserisce il record socio in wp_sd_members
--
--   Campi fissi richiesti:
--     member_type  = 'attivo'   (Tipo Socio)
--     fee_amount   = 0.00       (Tassa)
--     has_paid_fee = 1          (Pagato = Sì)
--     is_active    = 1          (Attivo = Sì)
--     is_scuba     = 1          (Sub = Sì)
--
--   Campi derivati dallo schema del plugin (class-sd-database.php):
--     membership_type  → 'individuale' (default per iscrizioni singole)
--     diabetes_type    → dal campo diabete del CSV (normalizzato)
--     roles            → campo text con il nome del ruolo WP
--     member_since     → data odierna
--     membership_expiry→ 31 dicembre anno corrente
--     privacy_consent  → 1 (import amministrativo)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO wp_sd_members (
    wp_user_id,
    first_name,
    last_name,
    email,
    member_type,
    membership_type,
    is_active,
    has_paid_fee,
    is_scuba,
    fee_amount,
    diabetes_type,
    roles,
    member_since,
    membership_expiry,
    registered_at,
    privacy_consent,
    consent_date
)
SELECT
    u.ID,
    i.nome,
    i.cognome,
    i.email,
    'attivo',
    'individuale',
    1,                                                          -- is_active = Sì
    1,                                                          -- has_paid_fee = Sì
    1,                                                          -- is_scuba = Sì
    0.00,                                                       -- fee_amount = 0.00
    i.diabete_type,                                             -- dal campo diabete del CSV
    i.ruolo_wp,                                                 -- campo text roles
    CURDATE(),
    STR_TO_DATE(CONCAT(YEAR(CURDATE()), '-12-31'), '%Y-%m-%d'), -- scadenza fine anno
    NOW(),
    1,                                                          -- privacy_consent = Sì
    NOW()
FROM tmp_sd_import_users i
JOIN wp_users u ON u.user_email = i.email
LEFT JOIN wp_sd_members m ON m.email = i.email
WHERE m.id IS NULL;


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 7 – Inserisce il pagamento in wp_sd_payments
--
--   status        = 'completato'  (come da logica class-sd-membership-admin.php)
--   payment_date  = oggi          (Data Pag.)
--   amount        = 0.00          (Tassa esentata)
--   payment_year  = anno corrente
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO wp_sd_payments (
    member_id,
    amount,
    currency,
    payment_date,
    payment_method,
    payment_year,
    status,
    notes
)
SELECT
    m.id,
    0.00,
    'CHF',
    NOW(),                  -- Data Pag. = oggi
    'bonifico_iban',
    YEAR(CURDATE()),
    'completato',           -- status = completato (has_paid_fee=1)
    'Import CSV - quota esentata'
FROM tmp_sd_import_users i
JOIN wp_sd_members m ON m.email = i.email
LEFT JOIN wp_sd_payments p
       ON p.member_id = m.id
      AND p.payment_year = YEAR(CURDATE())
WHERE p.id IS NULL;


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 8 – Verifica risultato import
-- ─────────────────────────────────────────────────────────────────────────────
SELECT
    i.nome,
    i.cognome,
    i.email,
    i.diabete_raw                           AS diabete_csv,
    i.diabete_type                          AS diabete_normalizzato,
    i.ruolo_wp                              AS ruolo_wp,
    u.ID                                    AS wp_user_id,
    m.id                                    AS member_id,
    m.member_type,
    m.membership_type,
    m.is_active,
    m.has_paid_fee,
    m.is_scuba,
    m.fee_amount,
    m.diabetes_type,
    m.member_since,
    m.membership_expiry,
    p.status                                AS payment_status,
    DATE(p.payment_date)                    AS payment_date,
    p.amount                                AS payment_amount
FROM tmp_sd_import_users i
LEFT JOIN wp_users        u ON u.user_email = i.email
LEFT JOIN wp_sd_members   m ON m.email      = i.email
LEFT JOIN wp_sd_payments  p ON p.member_id  = m.id
                           AND p.payment_year = YEAR(CURDATE())
ORDER BY i.cognome, i.nome;

-- Fine import
