<?php
/**
 * ScubaDiabetes Activity System - Setup Configuration
 * 
 * File di configurazione iniziale per il sistema di attività.
 * Esegui questo file UNA SOLA VOLTA tramite WP-CLI o browser.
 * 
 * Uso da command line (WP-CLI):
 *   wp eval-file wp-content/plugins/scubadiabetes-logbook/setup-activity-system.php
 * 
 * Uso da browser:
 *   Accedi come admin, copia il contenuto in un file temporaneo nella root di WordPress,
 *   accedi al file tramite browser (es. https://scubadiabetes.dev/setup-activity.php)
 * 
 * ATTENZIONE: Elimina questo file dopo l'esecuzione!
 */

// Verificare che siamo in ambiente WordPress
if ( ! function_exists( 'update_option' ) ) {
    die( 'This file must be run within WordPress.' );
}

// Solo admin
if ( ! is_admin() && ! defined( 'WP_CLI' ) ) {
    die( 'Admin access required.' );
}

echo "🔧 Configurazione Sistema Attività ScubaDiabetes v3.7.0\n\n";

// ============================================================
// 1. CONVERSIONE VALUTA (XE.com API)
// ============================================================
echo "1️⃣ Configurazione XE.com API...\n";

// 🔑 IMPORTANTE: Sostituisci con la tua chiave API da https://www.xe.com/
$xe_api_key = 'dvt3r3mkhvdfq9r56gob0pg6t5'; // ← Inserisci qui la chiave API

if ( $xe_api_key !== 'dvt3r3mkhvdfq9r56gob0pg6t5' ) {
    update_option( 'sd_xe_api_key', $xe_api_key );
    echo "   ✅ XE API Key configurata\n";
} else {
    echo "   ⚠️ AVVISO: Inserisci la chiave API XE.com in questo file!\n";
    echo "   📖 Leggi: https://xecdapi.xe.com/ per ottenere la chiave\n";
}

// Fallback rate (se API non disponibile)
update_option( 'sd_currency_fallback_rate', 1.05 );
echo "   ✅ Fallback rate impostato a 1.05\n\n";

// ============================================================
// 2. STRIPE (Pagamenti Online - Card + TWINT)
// ============================================================
echo "2️⃣ Configurazione Stripe...\n";

$stripe_live_key = get_option( 'sd_payment_stripe_live_secret' );
if ( $stripe_live_key ) {
    echo "   ✅ Stripe configurato (chiave esistente)\n";
} else {
    echo "   ⚠️ AVVISO: Stripe non è configurato\n";
    echo "   📖 Configura in: WordPress Admin > Settings > Stripe\n";
}

$stripe_test_key = get_option( 'sd_payment_stripe_test_secret' );
if ( $stripe_test_key ) {
    echo "   ✅ Stripe Test Mode disponibile\n";
}
echo "\n";

// ============================================================
// 3. PAYPAL (Pagamenti Online)
// ============================================================
echo "3️⃣ Configurazione PayPal...\n";

$paypal_client_id = get_option( 'sd_payment_paypal_client_id' );
if ( $paypal_client_id ) {
    echo "   ✅ PayPal configurato (chiave esistente)\n";
} else {
    echo "   ⚠️ AVVISO: PayPal non è configurato\n";
    echo "   📖 Configura in: WordPress Admin > Settings > PayPal\n";
}
echo "\n";

// ============================================================
// 4. EMAIL SEGRETARIATO (Per notifiche)
// ============================================================
echo "4️⃣ Configurazione Email Segretariato...\n";

$secretariat_email = get_option( 'sd_secretariat_email' );
if ( $secretariat_email ) {
    echo "   ✅ Email segretariato: {$secretariat_email}\n";
} else {
    echo "   ⚠️ Email segretariato non configurata\n";
    echo "   📖 Configura in: WordPress Admin > Settings > Email\n";
}
echo "\n";

// ============================================================
// 5. ATTIVITÀ AMMINISTRATORE
// ============================================================
echo "5️⃣ Configurazione Ruoli e Permessi...\n";

// Assicurati che gli admin abbiano accesso al sistema attività
$admin_role = get_role( 'administrator' );
if ( $admin_role ) {
    $admin_role->add_cap( 'manage_activities' );
    $admin_role->add_cap( 'edit_activities' );
    $admin_role->add_cap( 'delete_activities' );
    echo "   ✅ Permessi admin configurati\n";
}
echo "\n";

// ============================================================
// RIEPILOGO CONFIGURAZIONE
// ============================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ CONFIGURAZIONE COMPLETATA\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "📋 CHECKLIST POST-CONFIGURAZIONE:\n";
echo "  [ ] Inserire XE.com API Key nel file (riga 36)\n";
echo "  [ ] Verificare Stripe Live/Test Keys configurate\n";
echo "  [ ] Verificare PayPal Client ID configurato\n";
echo "  [ ] Verificare Email Segretariato configurata\n";
echo "  [ ] Testare conversione valuta: CHF 100 → EUR ?\n";
echo "  [ ] Creare prima attività di test\n";
echo "  [ ] ELIMINARE questo file dopo l'uso!\n\n";

echo "🚀 PROSSIMI STEP:\n";
echo "  1. Accedi a WordPress Admin\n";
echo "  2. Naviga a Attività > Nuova Attività\n";
echo "  3. Crea una attività di test\n";
echo "  4. Aggiungi campi modulo (testo, select, ecc.)\n";
echo "  5. Configura tariffe (CHF + EUR auto)\n";
echo "  6. Prova il modulo iscrizione\n\n";

echo "📚 DOCUMENTAZIONE:\n";
echo "  📖 ACTIVITY_SYSTEM_DOCS.md - Guida API completa\n";
echo "  📖 IMPLEMENTATION_STATUS.md - Checklist implementazione\n";
echo "  📖 includes/class-sd-activity-manager.php - Codice API\n\n";

echo "✨ Configurazione iniziale completata con successo!\n";
?>
