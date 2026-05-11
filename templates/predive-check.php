<?php
/**
 * Template: Semaforo Pre-Immersione
 *
 * Variabili disponibili (da SD_Predive_Check::render):
 * - $user_id        (int)
 * - $glycemia_unit  ('mg/dl' | 'mmol/l')
 * - $has_cgm        (bool)
 *
 * Il semaforo è aggiornato via AJAX (sd_predive_evaluate).
 *
 * @package SD_Logbook
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="sd-form-wrap sd-predive-wrap" id="sd-predive-widget"
     data-unit="<?php echo esc_attr( $glycemia_unit ); ?>">

	<!-- ================================================================
	     HEADER
	     ================================================================ -->
	<div class="sd-predive-header">
		<h2 class="sd-predive-title">
			<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
			     stroke-width="2.2" style="vertical-align:middle;margin-right:7px;">
				<circle cx="12" cy="12" r="10"/>
				<polyline points="12 6 12 12 16 14"/>
			</svg>
			<?php esc_html_e( 'Controllo Pre-Immersione', 'sd-logbook' ); ?>
		</h2>
		<button type="button" class="sd-btn sd-btn-secondary sd-predive-refresh" id="sd-predive-refresh">
			<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
			     stroke-width="2.2" style="vertical-align:middle;margin-right:4px;">
				<polyline points="23 4 23 10 17 10"/>
				<path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
			</svg>
			<?php esc_html_e( 'Aggiorna', 'sd-logbook' ); ?>
		</button>
	</div>

	<!-- ================================================================
	     STATO DI CARICAMENTO
	     ================================================================ -->
	<div class="sd-predive-loading" id="sd-predive-loading">
		<div class="sd-predive-spinner"></div>
		<p><?php esc_html_e( 'Valutazione in corso…', 'sd-logbook' ); ?></p>
	</div>

	<!-- ================================================================
	     CORPO PRINCIPALE (nascosto finché JS non popola i dati)
	     ================================================================ -->
	<div class="sd-predive-body" id="sd-predive-body" style="display:none;">

		<!-- Semaforo -->
		<div class="sd-predive-semaphore-row">
			<div class="sd-predive-semaphore" id="sd-predive-semaphore" aria-live="polite">
				<div class="sd-semaphore-light sd-semaphore-red"   id="sd-light-red"></div>
				<div class="sd-semaphore-light sd-semaphore-yellow" id="sd-light-yellow"></div>
				<div class="sd-semaphore-light sd-semaphore-green" id="sd-light-green"></div>
			</div>

			<!-- Valori numerici -->
			<div class="sd-predive-glucose-block">
				<div class="sd-predive-glucose-value" id="sd-predive-glucose">—</div>
				<div class="sd-predive-glucose-meta">
					<span class="sd-predive-arrow" id="sd-predive-arrow">—</span>
					<span class="sd-predive-age"   id="sd-predive-age"></span>
				</div>
				<div class="sd-predive-status-label" id="sd-predive-status-label"></div>
			</div>
		</div>

		<!-- Raccomandazione principale -->
		<div class="sd-predive-recommendation" id="sd-predive-recommendation"></div>

		<!-- Lista avvisi -->
		<ul class="sd-predive-alerts" id="sd-predive-alerts"></ul>

		<!-- Timestamp ultima valutazione -->
		<p class="sd-predive-timestamp" id="sd-predive-timestamp"></p>

	</div>

	<!-- ================================================================
	     MESSAGGIO ERRORE
	     ================================================================ -->
	<div class="sd-predive-error sd-notice sd-notice-error" id="sd-predive-error" style="display:none;"></div>

	<!-- ================================================================
	     AVVISO SE NON HA CGM CONFIGURATO
	     ================================================================ -->
	<?php if ( ! $has_cgm ) : ?>
		<div class="sd-notice sd-notice-warning sd-predive-no-cgm">
			<strong><?php esc_html_e( 'Nessun dispositivo CGM configurato.', 'sd-logbook' ); ?></strong>
			<?php esc_html_e( 'Collega il tuo sensore CGM nel profilo per ricevere valutazioni automatiche.', 'sd-logbook' ); ?>
		</div>
	<?php endif; ?>

	<!-- ================================================================
	     NOTE LEGALI / DISCLAIMER
	     ================================================================ -->
	<p class="sd-predive-disclaimer">
		<svg class="sd-predive-disclaimer-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
		     stroke-width="2.2" aria-hidden="true">
			<circle cx="12" cy="12" r="10"/>
			<line x1="12" y1="8" x2="12" y2="12"/>
			<line x1="12" y1="16" x2="12.01" y2="16"/>
		</svg>
		<?php esc_html_e( 'Questo strumento è un supporto decisionale e non sostituisce il giudizio clinico del tuo medico.', 'sd-logbook' ); ?><br>
		<?php esc_html_e( 'Segui sempre le indicazioni del protocollo Diabete Sommerso.', 'sd-logbook' ); ?>
	</p>

</div><!-- .sd-predive-wrap -->
