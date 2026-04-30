<?php
/**
 * Template: Dashboard CGM Paziente
 *
 * Variabili disponibili (da SD_CGM_Dashboard::render_patient):
 * - $user_id        (int)
 * - $glycemia_unit  ('mg/dl' | 'mmol/l')
 * - $display_name   (string)
 * - $stats          (object) last_value, last_direction, last_time, last_device, avg_24h, tir_7d, total
 * - $device_name    (string) nome sorgente CGM es. "Nightscout"
 * - $tz             (DateTimeZone)
 *
 * @package SD_Logbook
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_mmol  = ( 'mmol/l' === $glycemia_unit );
$unit_lbl = $is_mmol ? 'mmol/L' : 'mg/dL';

/**
 * Formatta un valore in mg/dL secondo l'unità corrente.
 *
 * @param int|null $val
 * @param bool     $is_mmol
 * @return string
 */
if ( ! function_exists( 'sd_cgm_fmt' ) ) {
	function sd_cgm_fmt( $val, $is_mmol ) {
		if ( null === $val ) {
			return '—';
		}
		return $is_mmol ? number_format( $val / 18, 1 ) : (string) (int) $val;
	}
}
?>

<div class="sd-form-wrap sd-cgm-dash-wrap">

	<!-- ================================================================
	     HEADER
	     ================================================================ -->
	<div class="sd-cgm-header">
		<div class="sd-cgm-header-left">
			<h2 class="sd-cgm-page-title">
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:middle;margin-right:7px;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
				<?php esc_html_e( 'I miei dati CGM', 'sd-logbook' ); ?>
			</h2>
			<?php if ( $device_name ) : ?>
				<span class="sd-cgm-device-badge sd-cgm-src-<?php echo esc_attr( strtolower( $device_name ) ); ?>">
					<?php echo esc_html( $device_name ); ?>
				</span>
			<?php endif; ?>
		</div>
		<?php if ( $stats->last_time ) : ?>
			<div class="sd-cgm-last-sync">
				<span class="sd-cgm-sync-label"><?php esc_html_e( 'Ultima lettura', 'sd-logbook' ); ?></span>
				<span class="sd-cgm-sync-time">
					<?php
					$dt_local = new \DateTime( $stats->last_time, new \DateTimeZone( 'UTC' ) );
					$dt_local->setTimezone( $tz );
					echo esc_html( $dt_local->format( 'd/m/Y H:i' ) );
					?>
				</span>
			</div>
		<?php endif; ?>
	</div>

	<!-- ================================================================
	     STAT CARDS
	     ================================================================ -->
	<div class="sd-stats-grid sd-cgm-stats-grid">

		<!-- Ultima glicemia -->
		<div class="sd-stat-card sd-cgm-last-card <?php echo $stats->last_value !== null ? esc_attr( SD_CGM_Dashboard::glucose_class( $stats->last_value ) ) : ''; ?>">
			<div class="sd-stat-value">
				<?php if ( $stats->last_value !== null ) : ?>
					<?php echo esc_html( sd_cgm_fmt( $stats->last_value, $is_mmol ) ); ?>
					<small class="sd-cgm-unit"><?php echo esc_html( $unit_lbl ); ?></small>
					<span class="sd-cgm-arrow"><?php echo esc_html( SD_CGM_Dashboard::direction_arrow( $stats->last_direction ) ); ?></span>
				<?php else : ?>
					—
				<?php endif; ?>
			</div>
			<div class="sd-stat-label"><?php esc_html_e( 'Ultima glicemia', 'sd-logbook' ); ?></div>
			<?php if ( $stats->last_value !== null ) : ?>
				<div class="sd-cgm-stat-badge <?php echo esc_attr( SD_CGM_Dashboard::glucose_class( $stats->last_value ) ); ?>">
					<?php echo esc_html( SD_CGM_Dashboard::glucose_label( $stats->last_value ) ); ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Media 24h -->
		<div class="sd-stat-card">
			<div class="sd-stat-value">
				<?php echo esc_html( sd_cgm_fmt( $stats->avg_24h, $is_mmol ) ); ?>
				<?php if ( $stats->avg_24h !== null ) : ?>
					<small class="sd-cgm-unit"><?php echo esc_html( $unit_lbl ); ?></small>
				<?php endif; ?>
			</div>
			<div class="sd-stat-label"><?php esc_html_e( 'Media ultime 24h', 'sd-logbook' ); ?></div>
		</div>

		<!-- TIR 7 giorni -->
		<div class="sd-stat-card">
			<div class="sd-stat-value <?php echo ( $stats->tir_7d !== null && $stats->tir_7d >= 70 ) ? 'sd-tir-good' : ( $stats->tir_7d !== null ? 'sd-tir-warn' : '' ); ?>">
				<?php echo $stats->tir_7d !== null ? esc_html( $stats->tir_7d ) . '%' : '—'; ?>
			</div>
			<div class="sd-stat-label"><?php esc_html_e( 'TIR 7 giorni (70–180)', 'sd-logbook' ); ?></div>
		</div>

		<!-- Letture totali -->
		<div class="sd-stat-card">
			<div class="sd-stat-value"><?php echo esc_html( number_format( $stats->total, 0, ',', '.' ) ); ?></div>
			<div class="sd-stat-label"><?php esc_html_e( 'Letture totali', 'sd-logbook' ); ?></div>
		</div>

	</div>

	<!-- ================================================================
	     TOOLBAR: periodo + unità
	     ================================================================ -->
	<div class="sd-cgm-toolbar">
		<div class="sd-cgm-period-wrap">
			<button type="button" class="sd-cgm-period-btn active" data-period="24h"><?php esc_html_e( '24h', 'sd-logbook' ); ?></button>
			<button type="button" class="sd-cgm-period-btn" data-period="7d"><?php esc_html_e( '7 giorni', 'sd-logbook' ); ?></button>
			<button type="button" class="sd-cgm-period-btn" data-period="30d"><?php esc_html_e( '30 giorni', 'sd-logbook' ); ?></button>
			<button type="button" class="sd-cgm-period-btn" data-period="custom"><?php esc_html_e( 'Personalizzato', 'sd-logbook' ); ?></button>
		</div>
		<div id="sd-cgm-custom-range" style="display:none;" class="sd-cgm-custom-range">
			<input type="date" id="sd-cgm-from" class="sd-cgm-date-input" aria-label="<?php esc_attr_e( 'Data inizio', 'sd-logbook' ); ?>">
			<span aria-hidden="true">–</span>
			<input type="date" id="sd-cgm-to" class="sd-cgm-date-input" aria-label="<?php esc_attr_e( 'Data fine', 'sd-logbook' ); ?>">
			<button type="button" id="sd-cgm-apply-custom" class="sd-cgm-btn-apply"><?php esc_html_e( 'Applica', 'sd-logbook' ); ?></button>
		</div>
		<div class="sd-cgm-unit-toggle">
			<button type="button" class="sd-cgm-unit-btn <?php echo ! $is_mmol ? 'active' : ''; ?>" data-unit="mg/dl">mg/dL</button>
			<button type="button" class="sd-cgm-unit-btn <?php echo $is_mmol ? 'active' : ''; ?>" data-unit="mmol/l">mmol/L</button>
		</div>
	</div>

	<!-- ================================================================
	     GRAFICO
	     ================================================================ -->
	<div class="sd-cgm-chart-box">
		<canvas id="sd-cgm-chart"></canvas>
		<div id="sd-cgm-chart-overlay" class="sd-cgm-overlay">
			<?php esc_html_e( 'Caricamento grafico…', 'sd-logbook' ); ?>
		</div>
	</div>

	<!-- ================================================================
	     TABELLA STORICO
	     ================================================================ -->
	<div class="sd-cgm-section">
		<div class="sd-cgm-section-hdr">
			<h3 class="sd-cgm-section-title"><?php esc_html_e( 'Storico letture', 'sd-logbook' ); ?></h3>
			<span id="sd-cgm-total-label" class="sd-cgm-count-badge"></span>
		</div>
		<div id="sd-cgm-table-wrap">
			<div class="sd-cgm-loading"><?php esc_html_e( 'Caricamento…', 'sd-logbook' ); ?></div>
		</div>
		<div id="sd-cgm-pagination" class="sd-cgm-pagination"></div>
	</div>

</div>
