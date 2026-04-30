<?php
/**
 * Template: Pannello CGM Medico
 *
 * Variabili disponibili (da SD_CGM_Dashboard::render_medical):
 * - $display_name (string)
 *
 * I dati vengono caricati via AJAX da cgm-medical.js.
 *
 * @package SD_Logbook
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="sd-form-wrap sd-cgm-medical-wrap">

	<!-- ================================================================
	     HEADER
	     ================================================================ -->
	<div class="sd-cgm-header">
		<div class="sd-cgm-header-left">
			<h2 class="sd-cgm-page-title">
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:middle;margin-right:7px;"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
				<?php esc_html_e( 'Pannello CGM — Dati Pazienti', 'sd-logbook' ); ?>
			</h2>
		</div>
		<div class="sd-cgm-last-sync">
			<span class="sd-cgm-sync-label"><?php echo esc_html( $display_name ); ?></span>
		</div>
	</div>

	<!-- ================================================================
	     FILTRI
	     ================================================================ -->
	<div class="sd-cgm-medical-filters">
		<div class="sd-cgm-filter-row">

			<div class="sd-cgm-filter-group">
				<label class="sd-cgm-filter-label" for="sd-cgm-m-search">
					<?php esc_html_e( 'Cerca paziente', 'sd-logbook' ); ?>
				</label>
				<input type="text" id="sd-cgm-m-search" class="sd-cgm-filter-input"
				       placeholder="<?php esc_attr_e( 'Nome, cognome o email…', 'sd-logbook' ); ?>">
			</div>

			<div class="sd-cgm-filter-group">
				<label class="sd-cgm-filter-label" for="sd-cgm-m-from">
					<?php esc_html_e( 'Dal', 'sd-logbook' ); ?>
				</label>
				<input type="date" id="sd-cgm-m-from" class="sd-cgm-filter-input sd-cgm-date-input">
			</div>

			<div class="sd-cgm-filter-group">
				<label class="sd-cgm-filter-label" for="sd-cgm-m-to">
					<?php esc_html_e( 'Al', 'sd-logbook' ); ?>
				</label>
				<input type="date" id="sd-cgm-m-to" class="sd-cgm-filter-input sd-cgm-date-input">
			</div>

			<div class="sd-cgm-filter-group">
				<label class="sd-cgm-filter-label" for="sd-cgm-m-filter">
					<?php esc_html_e( 'Glicemia', 'sd-logbook' ); ?>
				</label>
				<select id="sd-cgm-m-filter" class="sd-cgm-filter-input">
					<option value="all"><?php esc_html_e( 'Tutte le letture', 'sd-logbook' ); ?></option>
					<option value="anomalous"><?php esc_html_e( 'Anomale (&lt; 70 o &gt; 180)', 'sd-logbook' ); ?></option>
					<option value="normal"><?php esc_html_e( 'Nella norma (70–180)', 'sd-logbook' ); ?></option>
					<option value="low"><?php esc_html_e( 'Solo ipoglicemia (&lt; 70)', 'sd-logbook' ); ?></option>
					<option value="high"><?php esc_html_e( 'Solo iperglicemia (&gt; 180)', 'sd-logbook' ); ?></option>
				</select>
			</div>

			<div class="sd-cgm-filter-group">
				<label class="sd-cgm-filter-label" for="sd-cgm-m-cgm-type">
					<?php esc_html_e( 'Tipo di CGM', 'sd-logbook' ); ?>
				</label>
				<select id="sd-cgm-m-cgm-type" class="sd-cgm-filter-input">
					<option value=""><?php esc_html_e( 'Tutti', 'sd-logbook' ); ?></option>
					<option value="Nightscout">Nightscout</option>
					<option value="Dexcom">Dexcom</option>
					<option value="LibreView">LibreView</option>
					<option value="CareLink">CareLink</option>
					<option value="Tidepool">Tidepool</option>
				</select>
			</div>

			<div class="sd-cgm-filter-group sd-cgm-filter-actions">
				<button type="button" id="sd-cgm-m-apply" class="sd-cgm-btn-apply">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<?php esc_html_e( 'Filtra', 'sd-logbook' ); ?>
				</button>
				<button type="button" id="sd-cgm-m-reset" class="sd-cgm-btn-reset">
					<?php esc_html_e( 'Reset', 'sd-logbook' ); ?>
				</button>
			</div>

		</div>
	</div>

	<!-- ================================================================
	     STATISTICHE (popolate da JS)
	     ================================================================ -->
	<div id="sd-cgm-m-stats" class="sd-stats-grid sd-cgm-stats-grid" style="display:none;">
		<div class="sd-stat-card">
			<div class="sd-stat-value" id="sd-cgm-m-stat-total">—</div>
			<div class="sd-stat-label"><?php esc_html_e( 'Letture trovate', 'sd-logbook' ); ?></div>
		</div>
		<div class="sd-stat-card">
			<div class="sd-stat-value" id="sd-cgm-m-stat-users">—</div>
			<div class="sd-stat-label"><?php esc_html_e( 'Pazienti coinvolti', 'sd-logbook' ); ?></div>
		</div>
		<div class="sd-stat-card">
			<div class="sd-stat-value sd-tir-warn" id="sd-cgm-m-stat-anom">—</div>
			<div class="sd-stat-label"><?php esc_html_e( 'Letture anomale', 'sd-logbook' ); ?></div>
		</div>
		<div class="sd-stat-card">
			<div class="sd-stat-value" id="sd-cgm-m-stat-pct">—</div>
			<div class="sd-stat-label"><?php esc_html_e( '% anomalie', 'sd-logbook' ); ?></div>
		</div>
	</div>

	<!-- ================================================================
	     TABELLA (popolata da JS)
	     ================================================================ -->
	<div class="sd-cgm-section">
		<div class="sd-cgm-section-hdr">
			<h3 class="sd-cgm-section-title"><?php esc_html_e( 'Letture CGM', 'sd-logbook' ); ?></h3>
			<span id="sd-cgm-m-count" class="sd-cgm-count-badge"></span>
		</div>
		<div id="sd-cgm-m-table-wrap">
			<div class="sd-cgm-loading"><?php esc_html_e( 'Caricamento…', 'sd-logbook' ); ?></div>
		</div>
		<div id="sd-cgm-m-pagination" class="sd-cgm-pagination"></div>
	</div>

</div>
