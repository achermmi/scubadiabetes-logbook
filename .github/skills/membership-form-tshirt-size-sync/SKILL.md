---
name: membership-form-tshirt-size-sync
description: 'Workflow completo per sincronizzare e aggiornare le opzioni Taglia Maglietta nel modulo iscrizione soci WordPress, con verifiche frontend/backend e controllo regressioni. Usalo quando modifichi taglie bambino/adulto, etichette i18n, placeholder, o vuoi evitare disallineamenti tra campo principale e template familiari.'
argument-hint: 'Descrivi le nuove taglie, etichette e regole di validazione da applicare'
user-invocable: true
---

# Membership Form T-Shirt Size Sync

## Obiettivo
Mantenere coerenti le opzioni di taglia maglietta in tutti i punti del form iscrizione, riducendo errori tra UI, validazione lato client e backend.

Regola predefinita: il set taglie deve essere identico tra richiedente principale e familiari.

## Quando Usarlo
- Modifica elenco taglie bambino/adulto.
- Aggiornamento etichette visualizzate o placeholder.
- Allineamento tra campo principale e campi dei familiari.
- Verifica rapida dopo refactor del template iscrizione.

## Procedura
1. Raccogli i requisiti di modifica.
- Conferma il set finale di valori tecnici (value) e testi visibili (label).
- Conferma se la modifica vale per tutti i membri famiglia o solo per il richiedente principale.

2. Trova tutti i punti da aggiornare.
- Cerca `tshirt_size` nel workspace.
- Aggiorna almeno i due blocchi nel template iscrizione: campo principale e template familiari.

3. Applica le modifiche in modo simmetrico.
- Mantieni stessi `value` tra i blocchi quando la logica deve essere condivisa.
- Mantieni funzioni di escaping e traduzione WordPress (`esc_html_e`, `esc_attr_e`).
- Evita di introdurre caratteri o formattazioni inconsistenti tra opzioni parallele.
- Per questo progetto, mantieni il set taglie identico tra blocco principale e blocco familiari.

4. Valuta impatti su validazione e salvataggio.
- Controlla JS di validazione form per riferimenti diretti al campo `#tshirt_size`.
- Controlla backend che legge `$_POST['tshirt_size']` e verifica se esistono whitelist/enum da aggiornare.

5. Esegui controllo qualità.
- Verifica che il campo principale resti `required` se previsto.
- Verifica che il template familiari mantenga comportamento opzionale/obbligatorio atteso.
- Verifica che il placeholder iniziale sia coerente in tutti i select.

6. Verifica regressioni rapide.
- Simula compilazione base: nessuna taglia -> errore atteso.
- Selezione taglia valida -> submit senza errori su quel campo.
- Con fee famiglia, verifica che nuovi familiari mostrino lo stesso set taglie previsto.

7. Esegui verifica tecnica finale.
- Controlla eventuali errori/lint nel file modificato.
- Rileggi il diff e conferma che le modifiche siano limitate ai blocchi previsti.

## Decisioni e Branching
- Se viene chiesta eccezione con taglie diverse tra principale e familiari:
  usa set separati solo con approvazione esplicita e documenta la differenza nel change log.
- Se la modifica cambia i `value` persistiti:
  verifica compatibilita con dati storici e reportistica.
- Se emerge logica duplicata frequente:
  valuta estrazione in helper/template condiviso per ridurre drift.

## Checklist di Completamento
- Tutti i blocchi `tshirt_size` aggiornati e coerenti.
- Nessuna regressione nelle traduzioni/escaping WordPress.
- Validazione frontend e backend verificata.
- Flusso famiglia testato almeno con un familiare aggiunto.
