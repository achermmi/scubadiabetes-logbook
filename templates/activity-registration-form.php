<?php
/**
 * Template: Modulo iscrizione Attività
 *
 * Shortcode: [sd_iscrizione_attivita activity_id="X"]
 * 
 * Variabili disponibili:
 * @var int    $activity_id  ID dell'attività
 * @var string $modal_class  Classe CSS per modal (opzionale)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="sd-form-wrap sd-activity-registration-wrap" id="sd-activity-registration-page" data-activity-id="<?php echo esc_attr( $activity_id ); ?>">

	<!-- LOADING INDICATOR -->
	<div id="sd-reg-loading" class="sd-loading-spinner" style="display:none;">
		<div class="sd-spinner"></div>
		<p><?php esc_html_e( 'Caricamento modulo...', 'sd-logbook' ); ?></p>
	</div>

	<!-- ERROR MESSAGE -->
	<div id="sd-reg-error" class="sd-notice sd-notice-error" style="display:none;"></div>

	<!-- FORM CONTAINER -->
	<div id="sd-reg-form-container" style="display:none;">

		<div class="sd-form-header">
			<h2 class="sd-form-title" id="sd-activity-title">
				<?php esc_html_e( 'Iscrizione Attività', 'sd-logbook' ); ?>
			</h2>
			<p class="sd-form-subtitle" id="sd-activity-subtitle"></p>
		</div>

		<!-- ACTIVITY INFO CARD -->
		<div class="sd-form-section sd-activity-info">
			<div class="sd-activity-image-wrap" id="sd-activity-image-wrap" style="display:none;">
				<img id="sd-activity-image" class="sd-activity-image" src="" alt="<?php esc_attr_e( 'Immagine attività', 'sd-logbook' ); ?>">
			</div>
			<div class="sd-activity-details">
				<div class="sd-detail-item">
					<span class="sd-detail-label"><?php esc_html_e( 'Data Inizio', 'sd-logbook' ); ?>:</span>
					<span class="sd-detail-value" id="sd-activity-start-date"></span>
				</div>
				<div class="sd-detail-item">
					<span class="sd-detail-label"><?php esc_html_e( 'Data Fine', 'sd-logbook' ); ?>:</span>
					<span class="sd-detail-value" id="sd-activity-end-date"></span>
				</div>
				<div class="sd-detail-item">
					<span class="sd-detail-label"><?php esc_html_e( 'Luogo', 'sd-logbook' ); ?>:</span>
					<span class="sd-detail-value" id="sd-activity-location"></span>
				</div>
				<div class="sd-detail-item">
					<span class="sd-detail-label"><?php esc_html_e( 'Posti Disponibili', 'sd-logbook' ); ?>:</span>
					<span class="sd-detail-value" id="sd-activity-spots"></span>
				</div>
			</div>
			<div class="sd-activity-description" id="sd-activity-description"></div>
			<div class="sd-activity-extra-content" id="sd-activity-extra-content" style="display:none;"></div>
		</div>

		<!-- REGISTRATION FORM -->
		<form id="sd-activity-registration-form" novalidate>
			<?php wp_nonce_field( 'sd_nonce', 'nonce' ); ?>

			<!-- ===== SEZIONE 1: DATI PERSONALI ===== -->
			<div class="sd-form-section" id="sd-section-personal" data-section-key="personal" data-section-order="10" data-section-title="<?php echo esc_attr__( 'Dati Personali', 'sd-logbook' ); ?>">
				<h3 class="sd-section-title"><span class="sd-section-index">1.</span> <span class="sd-section-title-text"><?php esc_html_e( 'Dati Personali', 'sd-logbook' ); ?></span></h3>
				<div id="sd-personal-fields"></div>
				<div id="sd-minor-warning" class="sd-notice sd-notice-error" style="display:none;"></div>
			</div>

			<!-- ===== SEZIONE 2: CAMPI DINAMICI ===== -->
			<div class="sd-form-section" id="sd-dynamic-fields-section" data-section-key="additional" data-section-order="20" data-section-title="<?php echo esc_attr__( 'Informazioni Aggiuntive', 'sd-logbook' ); ?>">
				<h3 class="sd-section-title"><span class="sd-section-index">2.</span> <span class="sd-section-title-text"><?php esc_html_e( 'Informazioni Aggiuntive', 'sd-logbook' ); ?></span></h3>
				<div id="sd-dynamic-fields-container"></div>
			</div>

			<div id="sd-custom-sections-container"></div>

			<!-- ===== SEZIONE 3: SELEZIONE TARIFFA ===== -->
			<div class="sd-form-section" id="sd-pricing-section" data-section-key="pricing" data-section-order="30" data-section-title="<?php echo esc_attr__( 'Selezione Tariffa', 'sd-logbook' ); ?>">
				<h3 class="sd-section-title"><span class="sd-section-index">3.</span> <span class="sd-section-title-text"><?php esc_html_e( 'Selezione Tariffa', 'sd-logbook' ); ?></span></h3>
				<div id="sd-pricing-extra-fields"></div>
				<div class="sd-fee-cards" id="sd-fee-cards-container"></div>
				<div id="sd-price-total" class="sd-price-total" style="display:none;"></div>
				<div id="sd-price-error" class="sd-error-message" style="display:none;"></div>
			</div>

			<!-- ===== SEZIONE 4: CONSENSI ===== -->
			<div class="sd-form-section" id="sd-consents-section" data-section-key="consents" data-section-order="40" data-section-title="<?php echo esc_attr__( 'Consensi', 'sd-logbook' ); ?>">
				<h3 class="sd-section-title"><span class="sd-section-index">4.</span> <span class="sd-section-title-text"><?php esc_html_e( 'Consensi', 'sd-logbook' ); ?></span></h3>

				<div id="sd-consents-extra-fields"></div>
			</div>

			<!-- ===== PULSANTI ===== -->
			<div class="sd-form-actions">
				<button type="submit" class="sd-btn sd-btn-primary sd-btn-lg" id="sd-submit-btn">
					<?php esc_html_e( 'Procedi al Pagamento', 'sd-logbook' ); ?>
				</button>
				<button type="reset" class="sd-btn sd-btn-secondary sd-btn-lg" id="sd-reset-btn">
					<?php esc_html_e( 'Azzera', 'sd-logbook' ); ?>
				</button>
			</div>

			<!-- SUCCESS MESSAGE -->
			<div id="sd-reg-success" class="sd-notice sd-notice-success" style="display:none;">
				<?php esc_html_e( 'Iscrizione completata! Sei stato reindirizzato al pagamento...', 'sd-logbook' ); ?>
			</div>
		</form>

	</div>

</div>

<script>
	// Dati per JavaScript
	window.sdActivityRegistration = {
		activityId: <?php echo (int) $activity_id; ?>,
		detailsNonce: '<?php echo esc_attr( wp_create_nonce( 'sd_activity_nonce' ) ); ?>',
		actionNonce: '<?php echo esc_attr( wp_create_nonce( 'sd_nonce' ) ); ?>',
		ajaxUrl: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
		i18n: {
			fieldRequired: '<?php esc_attr_e( "Campo obbligatorio", "sd-logbook" ); ?>',
			birthDateRequired: '<?php esc_attr_e( "Inserisci una data di nascita valida", "sd-logbook" ); ?>',
			invalidEmail: '<?php esc_attr_e( "Email non valida", "sd-logbook" ); ?>',
			selectPrice: '<?php esc_attr_e( "--Seleziona--", "sd-logbook" ); ?>',
			agreeTerms: '<?php esc_attr_e( "Devi accettare i termini", "sd-logbook" ); ?>',
			loadingPrice: '<?php esc_attr_e( "Calcolo prezzo in €...", "sd-logbook" ); ?>',
			error: '<?php esc_attr_e( "Si è verificato un errore", "sd-logbook" ); ?>',
			success: '<?php esc_attr_e( "Iscrizione completata!", "sd-logbook" ); ?>',
			redirecting: '<?php esc_attr_e( "Reindirizzamento al pagamento...", "sd-logbook" ); ?>',
		}
	};
</script>
