<?php
/**
 * Template: Gestione soci - Lista e filtri
 *
 * Variabili disponibili:
 * @var array  $stats        Statistiche (total, paid, unpaid, income)
 * @var string $current_year Anno corrente
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="sd-form-wrap sd-management-wrap" id="sd-management-page">

	<div class="sd-form-header">
		<h2 class="sd-form-title"><?php esc_html_e( 'Gestione Soci', 'sd-logbook' ); ?></h2>
	</div>

	<!-- Statistiche rapide -->
	<div class="sd-stats-bar">
		<div class="sd-stat-card sd-stat-clickable" data-filter-reset="1" title="<?php esc_attr_e( 'Mostra tutti i soci', 'sd-logbook' ); ?>">
			<span class="sd-stat-value" id="sd-stat-total"><?php echo esc_html( $stats['total'] ); ?></span>
			<span class="sd-stat-label"><?php esc_html_e( 'Soci totali', 'sd-logbook' ); ?></span>
		</div>
		<div class="sd-stat-card sd-stat-success sd-stat-clickable" data-filter-field="pagato" data-filter-value="1" title="<?php esc_attr_e( 'Filtra: hanno pagato', 'sd-logbook' ); ?>">
			<span class="sd-stat-value" id="sd-stat-paid"><?php echo esc_html( $stats['paid'] ); ?></span>
			<span class="sd-stat-label"><?php esc_html_e( 'Hanno pagato', 'sd-logbook' ); ?></span>
		</div>
		<div class="sd-stat-card sd-stat-warning sd-stat-clickable" data-filter-field="pagato" data-filter-value="0" title="<?php esc_attr_e( 'Filtra: in attesa pagamento', 'sd-logbook' ); ?>">
			<span class="sd-stat-value" id="sd-stat-unpaid"><?php echo esc_html( $stats['unpaid'] ); ?></span>
			<span class="sd-stat-label"><?php esc_html_e( 'In attesa pagamento', 'sd-logbook' ); ?></span>
		</div>
		<div class="sd-stat-card sd-stat-info sd-stat-clickable" data-filter-field="pagato" data-filter-value="1" title="<?php esc_attr_e( 'Filtra: soci che hanno pagato', 'sd-logbook' ); ?>">
			<span class="sd-stat-value" id="sd-stat-income">CHF <?php echo esc_html( number_format( $stats['income'], 2 ) ); ?></span>
			<span class="sd-stat-label"><?php echo esc_html( sprintf( __( 'Incassato %s', 'sd-logbook' ), $current_year ) ); ?></span>
		</div>
		<div class="sd-stat-card sd-stat-danger sd-stat-clickable" data-filter-field="pagato" data-filter-value="0" title="<?php esc_attr_e( 'Filtra: soci non pagato', 'sd-logbook' ); ?>">
			<span class="sd-stat-value" id="sd-stat-expected">CHF <?php echo esc_html( number_format( $stats['expected'], 2 ) ); ?></span>
			<span class="sd-stat-label"><?php echo esc_html( sprintf( __( 'Non pagato %s', 'sd-logbook' ), $current_year ) ); ?></span>
		</div>
		<div class="sd-stat-card sd-stat-success sd-stat-clickable" data-filter-field="is_active" data-filter-value="1" title="<?php esc_attr_e( 'Filtra: soci attivi', 'sd-logbook' ); ?>">
			<span class="sd-stat-value" id="sd-stat-active-yes"><?php echo esc_html( $stats['active_yes'] ); ?></span>
			<span class="sd-stat-label"><?php esc_html_e( 'Soci attivi', 'sd-logbook' ); ?></span>
		</div>
		<div class="sd-stat-card sd-stat-warning sd-stat-clickable" data-filter-field="is_active" data-filter-value="0" title="<?php esc_attr_e( 'Filtra: soci non attivi', 'sd-logbook' ); ?>">
			<span class="sd-stat-value" id="sd-stat-active-no"><?php echo esc_html( $stats['active_no'] ); ?></span>
			<span class="sd-stat-label"><?php esc_html_e( 'Soci non attivi', 'sd-logbook' ); ?></span>
		</div>
	</div>

	<!-- Navigazione tab -->
	<div class="sd-tabs" id="sd-mgmt-tabs" role="tablist">
		<button type="button" class="sd-tab-btn active" data-tab="gestione" role="tab"><?php esc_html_e( 'Gestione Soci', 'sd-logbook' ); ?></button>
		<button type="button" class="sd-tab-btn" data-tab="mailing" role="tab"><?php esc_html_e( 'Mailing Soci', 'sd-logbook' ); ?></button>
	</div>

	<!-- TAB 2: Mailing Soci -->
	<div class="sd-tab-content" id="sd-tab-mailing">

	<!-- Filtri Mailing Soci -->
	<div class="sd-filter-bar" id="sd-mailing-filter-bar">
		<form id="sd-mailing-filters" class="sd-filters-form">
			<div class="sd-filter-row">

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-mailing-filter-search"><?php esc_html_e( 'Ricerca', 'sd-logbook' ); ?></label>
					<input type="text" id="sd-mailing-filter-search" name="search" class="sd-input sd-input-sm" placeholder="<?php esc_attr_e( 'Nome, Cognome, Email...', 'sd-logbook' ); ?>">
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-mailing-filter-pagato"><?php esc_html_e( 'Stato pagamento', 'sd-logbook' ); ?></label>
					<select name="pagato" id="sd-mailing-filter-pagato" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti (pagato)', 'sd-logbook' ); ?></option>
						<option value="1"><?php esc_html_e( 'Pagato', 'sd-logbook' ); ?></option>
						<option value="0"><?php esc_html_e( 'Non pagato', 'sd-logbook' ); ?></option>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-mailing-filter-anno"><?php esc_html_e( 'Anno', 'sd-logbook' ); ?></label>
					<select name="anno" id="sd-mailing-filter-anno" class="sd-select sd-select-sm">
						<?php for ( $y = intval( $current_year ); $y >= intval( $current_year ) - 5; $y-- ) : ?>
							<option value="<?php echo esc_attr( $y ); ?>" <?php selected( $current_year, $y ); ?>>
								<?php echo esc_html( $y ); ?>
							</option>
						<?php endfor; ?>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-mailing-filter-tassa"><?php esc_html_e( 'Tassa', 'sd-logbook' ); ?></label>
					<select name="fee_amount" id="sd-mailing-filter-tassa" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutte le tasse', 'sd-logbook' ); ?></option>
						<option value="30">CHF 30</option>
						<option value="50">CHF 50</option>
						<option value="75">CHF 75</option>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-mailing-filter-scuba"><?php esc_html_e( 'Subacqueo', 'sd-logbook' ); ?></label>
					<select name="is_scuba" id="sd-mailing-filter-scuba" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti (sub)', 'sd-logbook' ); ?></option>
						<option value="1"><?php esc_html_e( 'Subacqueo', 'sd-logbook' ); ?></option>
						<option value="0"><?php esc_html_e( 'Non subacqueo', 'sd-logbook' ); ?></option>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-mailing-filter-diabetes"><?php esc_html_e( 'Diabete', 'sd-logbook' ); ?></label>
					<select name="diabetes_type" id="sd-mailing-filter-diabetes" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti (diabete)', 'sd-logbook' ); ?></option>
						<option value="tipo_1"><?php esc_html_e( 'Tipo 1', 'sd-logbook' ); ?></option>
						<option value="tipo_2"><?php esc_html_e( 'Tipo 2', 'sd-logbook' ); ?></option>
						<option value="tipo_3c"><?php esc_html_e( 'Tipo 3c', 'sd-logbook' ); ?></option>
						<option value="lada">LADA</option>
						<option value="mody">MODY</option>
						<option value="midd">MIDD</option>
						<option value="non_diabetico"><?php esc_html_e( 'Non diabetico', 'sd-logbook' ); ?></option>
						<option value="altro"><?php esc_html_e( 'Altro', 'sd-logbook' ); ?></option>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-mailing-filter-type"><?php esc_html_e( 'Tipo socio', 'sd-logbook' ); ?></label>
					<select name="member_type" id="sd-mailing-filter-type" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti i tipi', 'sd-logbook' ); ?></option>
						<option value="attivo"><?php esc_html_e( 'Attivo', 'sd-logbook' ); ?></option>
						<option value="attivo_capo_famiglia"><?php esc_html_e( 'Attivo Capo Famiglia', 'sd-logbook' ); ?></option>
						<option value="attivo_famigliare"><?php esc_html_e( 'Attivo Famigliare', 'sd-logbook' ); ?></option>
						<option value="passivo"><?php esc_html_e( 'Passivo', 'sd-logbook' ); ?></option>
						<option value="accompagnatore"><?php esc_html_e( 'Accompagnatore', 'sd-logbook' ); ?></option>
						<option value="sostenitore"><?php esc_html_e( 'Sostenitore', 'sd-logbook' ); ?></option>
						<option value="onorario"><?php esc_html_e( 'Onorario', 'sd-logbook' ); ?></option>
						<option value="fondatore"><?php esc_html_e( 'Fondatore', 'sd-logbook' ); ?></option>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-mailing-filter-active"><?php esc_html_e( 'Socio attivo', 'sd-logbook' ); ?></label>
					<select name="is_active" id="sd-mailing-filter-active" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti (attivo)', 'sd-logbook' ); ?></option>
						<option value="1"><?php esc_html_e( 'Socio attivo', 'sd-logbook' ); ?></option>
						<option value="0"><?php esc_html_e( 'Non attivo', 'sd-logbook' ); ?></option>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-mailing-filter-role"><?php esc_html_e( 'Ruolo WordPress', 'sd-logbook' ); ?></label>
					<select name="wp_role" id="sd-mailing-filter-role" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti i ruoli WP', 'sd-logbook' ); ?></option>
						<option value="sd_diver_diabetic"><?php esc_html_e( 'Subacqueo Diabetico', 'sd-logbook' ); ?></option>
						<option value="sd_diver"><?php esc_html_e( 'Subacqueo', 'sd-logbook' ); ?></option>
						<option value="sd_staff"><?php esc_html_e( 'Staff', 'sd-logbook' ); ?></option>
						<option value="sd_medical"><?php esc_html_e( 'Medico', 'sd-logbook' ); ?></option>
						<option value="subscriber"><?php esc_html_e( 'Subscriber', 'sd-logbook' ); ?></option>
					</select>
				</div>

				<div class="sd-filter-main-actions">
					<button type="submit" class="sd-btn sd-btn-primary sd-btn-sm sd-action-btn">
						<?php esc_html_e( 'Cerca', 'sd-logbook' ); ?>
					</button>
					<button type="button" id="sd-mailing-btn-reset" class="sd-btn sd-btn-secondary sd-btn-sm sd-action-btn">
						<?php esc_html_e( 'Reset', 'sd-logbook' ); ?>
					</button>
				</div>

			</div>
		</form>
	</div>

	<!-- Cruscotto rinnovi soci -->
	<div class="sd-renewals-dashboard" id="sd-renewals-dashboard">
		<div class="sd-renewals-header">
			<h3><?php esc_html_e( 'Cruscotto mailing e rinnovo dei Soci', 'sd-logbook' ); ?></h3>
			<p><?php esc_html_e( 'Stato iscrizione, scadenza e invio e-mail rapido ai soci attivi.', 'sd-logbook' ); ?></p>
		</div>
		<div class="sd-renewals-loading" id="sd-renewals-loading" style="display:none;">
			<?php esc_html_e( 'Caricamento cruscotto rinnovi...', 'sd-logbook' ); ?>
		</div>
		<div class="sd-renewals-message sd-notice" id="sd-renewals-message" style="display:none;"></div>

		<!-- Selezione modello email -->
		<div class="sd-renewals-template-row">
			<label class="sd-renewals-template-label" for="sd-renewals-template-id">
				<?php esc_html_e( 'Modello e-mail:', 'sd-logbook' ); ?>
			</label>
			<select id="sd-renewals-template-id" class="sd-field-input sd-renewals-template-select">
				<option value="0"><?php esc_html_e( '— Seleziona modello e-mail —', 'sd-logbook' ); ?></option>
				<?php
				if ( class_exists( 'SD_Email_Templates' ) ) {
					foreach ( SD_Email_Templates::get_all_as_options( array( 'template_type' => 'membership', 'form_key' => 'membership:association' ) ) as $tpl_id => $tpl_name ) {
						echo '<option value="' . esc_attr( $tpl_id ) . '">' . esc_html( $tpl_name ) . '</option>';
					}
				}
				?>
			</select>
			<label class="sd-renewals-template-label" for="sd-renewals-pdf-template-id" style="margin-left:12px;">
				<?php esc_html_e( 'Modelli PDF da allegare:', 'sd-logbook' ); ?>
			</label>
			<select id="sd-renewals-pdf-template-id" class="sd-field-input sd-renewals-template-select" multiple size="4" style="min-width:180px;height:auto;vertical-align:top;">
			</select>
			<span style="font-size:11px;color:#888;margin-left:4px;display:inline-block;vertical-align:top;max-width:120px;line-height:1.3;"><?php esc_html_e( 'Ctrl+clic per selezionare più template', 'sd-logbook' ); ?></span>
		</div>

		<div class="sd-renewals-tools">
			<div class="sd-renewals-quick-filters" id="sd-renewals-quick-filters" role="group" aria-label="<?php esc_attr_e( 'Filtro rapido rinnovi', 'sd-logbook' ); ?>">
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-renewals-filter is-active" data-renewals-filter="all"><?php esc_html_e( 'Tutti', 'sd-logbook' ); ?></button>
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-renewals-filter" data-renewals-filter="scaduti"><?php esc_html_e( 'Solo scaduti', 'sd-logbook' ); ?></button>
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-renewals-filter" data-renewals-filter="in_scadenza"><?php esc_html_e( 'Solo in scadenza', 'sd-logbook' ); ?></button>
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-renewals-filter" data-renewals-filter="non_pagati"><?php esc_html_e( 'Solo non pagati', 'sd-logbook' ); ?></button>
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-renewals-filter" data-renewals-filter="valid_email"><?php esc_html_e( 'Solo con e-mail valida', 'sd-logbook' ); ?></button>
			</div>
			<button type="button" class="sd-btn sd-btn-primary sd-btn-sm" id="sd-renewals-bulk-remind"><?php esc_html_e( 'Invia e-mail massivo', 'sd-logbook' ); ?></button>
			<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm" id="sd-renewals-email-all-active"><?php esc_html_e( 'Invia e-mail a tutti i soci attivi', 'sd-logbook' ); ?></button>
		</div>
		<div class="sd-renewals-table-wrap">
			<table class="sd-renewals-table" id="sd-renewals-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Socio', 'sd-logbook' ); ?></th>
						<th><?php esc_html_e( 'Email', 'sd-logbook' ); ?></th>
						<th><?php esc_html_e( 'Stato Iscrizione', 'sd-logbook' ); ?></th>
						<th><?php esc_html_e( 'Scadenza', 'sd-logbook' ); ?></th>
						<th><?php esc_html_e( 'Importo Dovuto', 'sd-logbook' ); ?></th>
						<th><?php esc_html_e( 'Ultima e-mail', 'sd-logbook' ); ?></th>
						<th><?php esc_html_e( 'Azione', 'sd-logbook' ); ?></th>
					</tr>
				</thead>
				<tbody id="sd-renewals-tbody">
					<tr>
						<td colspan="7" class="sd-table-empty"><?php esc_html_e( 'Caricamento in corso...', 'sd-logbook' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>

	</div><!-- /sd-tab-mailing -->

	<!-- TAB 1: Gestione Soci -->
	<div class="sd-tab-content active" id="sd-tab-gestione">

	<!-- Barra filtri -->
	<div class="sd-filter-bar">
		<form id="sd-member-filters" class="sd-filters-form">

			<!-- Riga 1: Ricerca, Stato pagamento, Anno, Tassa, Subacqueo, Diabete -->
			<div class="sd-filter-row">
				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-filter-search"><?php esc_html_e( 'Ricerca', 'sd-logbook' ); ?></label>
					<input type="text" id="sd-filter-search" name="search" class="sd-input sd-input-sm" placeholder="<?php esc_attr_e( 'Nome, Cognome, Email...', 'sd-logbook' ); ?>">
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-filter-pagato"><?php esc_html_e( 'Stato pagamento', 'sd-logbook' ); ?></label>
					<select name="pagato" id="sd-filter-pagato" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti (pagato)', 'sd-logbook' ); ?></option>
						<option value="1"><?php esc_html_e( 'Pagato', 'sd-logbook' ); ?></option>
						<option value="0"><?php esc_html_e( 'Non pagato', 'sd-logbook' ); ?></option>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-filter-anno"><?php esc_html_e( 'Anno', 'sd-logbook' ); ?></label>
					<select name="anno" id="sd-filter-anno" class="sd-select sd-select-sm">
						<?php for ( $y = intval( $current_year ); $y >= intval( $current_year ) - 5; $y-- ) : ?>
							<option value="<?php echo esc_attr( $y ); ?>" <?php selected( $current_year, $y ); ?>>
								<?php echo esc_html( $y ); ?>
							</option>
						<?php endfor; ?>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-filter-tassa"><?php esc_html_e( 'Tassa', 'sd-logbook' ); ?></label>
					<select name="fee_amount" id="sd-filter-tassa" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutte le tasse', 'sd-logbook' ); ?></option>
						<option value="30">CHF 30</option>
						<option value="50">CHF 50</option>
						<option value="75">CHF 75</option>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-filter-scuba"><?php esc_html_e( 'Subacqueo', 'sd-logbook' ); ?></label>
					<select name="is_scuba" id="sd-filter-scuba" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti (sub)', 'sd-logbook' ); ?></option>
						<option value="1"><?php esc_html_e( 'Subacqueo', 'sd-logbook' ); ?></option>
						<option value="0"><?php esc_html_e( 'Non subacqueo', 'sd-logbook' ); ?></option>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-filter-diabetes"><?php esc_html_e( 'Diabete', 'sd-logbook' ); ?></label>
					<select name="diabetes_type" id="sd-filter-diabetes" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti (diabete)', 'sd-logbook' ); ?></option>
						<option value="tipo_1"><?php esc_html_e( 'Tipo 1', 'sd-logbook' ); ?></option>
						<option value="tipo_2"><?php esc_html_e( 'Tipo 2', 'sd-logbook' ); ?></option>
						<option value="tipo_3c"><?php esc_html_e( 'Tipo 3c', 'sd-logbook' ); ?></option>
						<option value="lada">LADA</option>
						<option value="mody">MODY</option>
						<option value="midd">MIDD</option>
						<option value="non_diabetico"><?php esc_html_e( 'Non diabetico', 'sd-logbook' ); ?></option>
						<option value="altro"><?php esc_html_e( 'Altro', 'sd-logbook' ); ?></option>
					</select>
				</div>
			</div>

			<!-- Riga 2: Tipo socio, Socio attivo, Ruolo WP + Cerca / Reset / Elimina inline -->
			<div class="sd-filter-row-2">
				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-filter-type"><?php esc_html_e( 'Tipo socio', 'sd-logbook' ); ?></label>
					<select name="member_type" id="sd-filter-type" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti i tipi', 'sd-logbook' ); ?></option>
						<option value="attivo"><?php esc_html_e( 'Attivo', 'sd-logbook' ); ?></option>
						<option value="attivo_capo_famiglia"><?php esc_html_e( 'Attivo Capo Famiglia', 'sd-logbook' ); ?></option>
						<option value="attivo_famigliare"><?php esc_html_e( 'Attivo Famigliare', 'sd-logbook' ); ?></option>
						<option value="passivo"><?php esc_html_e( 'Passivo', 'sd-logbook' ); ?></option>
						<option value="accompagnatore"><?php esc_html_e( 'Accompagnatore', 'sd-logbook' ); ?></option>
						<option value="sostenitore"><?php esc_html_e( 'Sostenitore', 'sd-logbook' ); ?></option>
						<option value="onorario"><?php esc_html_e( 'Onorario', 'sd-logbook' ); ?></option>
						<option value="fondatore"><?php esc_html_e( 'Fondatore', 'sd-logbook' ); ?></option>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-filter-active"><?php esc_html_e( 'Socio attivo', 'sd-logbook' ); ?></label>
					<select name="is_active" id="sd-filter-active" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti (attivo)', 'sd-logbook' ); ?></option>
						<option value="1"><?php esc_html_e( 'Socio attivo', 'sd-logbook' ); ?></option>
						<option value="0"><?php esc_html_e( 'Non attivo', 'sd-logbook' ); ?></option>
					</select>
				</div>

				<div class="sd-filter-group">
					<label class="sd-filter-label" for="sd-filter-role"><?php esc_html_e( 'Ruolo WordPress', 'sd-logbook' ); ?></label>
					<select name="wp_role" id="sd-filter-role" class="sd-select sd-select-sm">
						<option value=""><?php esc_html_e( 'Tutti i ruoli WP', 'sd-logbook' ); ?></option>
						<option value="sd_diver_diabetic"><?php esc_html_e( 'Subacqueo Diabetico', 'sd-logbook' ); ?></option>
						<option value="sd_diver"><?php esc_html_e( 'Subacqueo', 'sd-logbook' ); ?></option>
						<option value="sd_staff"><?php esc_html_e( 'Staff', 'sd-logbook' ); ?></option>
						<option value="sd_medical"><?php esc_html_e( 'Medico', 'sd-logbook' ); ?></option>
						<option value="subscriber"><?php esc_html_e( 'Subscriber', 'sd-logbook' ); ?></option>
					</select>
				</div>

				<div class="sd-filter-main-actions">
					<button type="submit" id="sd-btn-search" class="sd-btn sd-btn-primary sd-btn-sm sd-action-btn">
						<?php esc_html_e( 'Cerca', 'sd-logbook' ); ?>
					</button>
					<button type="button" id="sd-btn-reset" class="sd-btn sd-btn-secondary sd-btn-sm sd-action-btn">
						<?php esc_html_e( 'Reset', 'sd-logbook' ); ?>
					</button>
					<button type="button" id="sd-delete-selected" class="sd-btn sd-btn-danger sd-btn-sm sd-action-btn">
						<?php esc_html_e( 'Elimina iscrizione', 'sd-logbook' ); ?>
					</button>
				</div>
			</div>

			<!-- Riga 3: export -->
			<div class="sd-filter-export-row">
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-action-btn" id="sd-export-csv" data-format="csv">
					↓ CSV
				</button>
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-action-btn" id="sd-export-xlsx" data-format="xlsx">
					↓ Excel
				</button>
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-action-btn" id="sd-export-pdf">
					↓ PDF
				</button>
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-action-btn" id="sd-export-vcf" title="<?php esc_attr_e( 'Esporta mailing list vCard (.vcf) compatibile con Outlook, eM Client, Apple Mail, Thunderbird', 'sd-logbook' ); ?>">
					📇 <?php esc_html_e( 'Mailing list', 'sd-logbook' ); ?>
				</button>
			</div>

		</form>
	</div>

	<!-- Messaggio/spinner caricamento -->
	<div id="sd-members-loading" class="sd-loading" style="display:none;">
		<?php esc_html_e( 'Caricamento...', 'sd-logbook' ); ?>
	</div>
	<div id="sd-members-message" class="sd-notice" style="display:none;"></div>

	<!-- Tabella soci -->
	<div class="sd-table-wrap">
		<table class="sd-members-table" id="sd-members-table">
			<thead>
				<tr>
					<th><input type="checkbox" id="sd-select-all-members" aria-label="<?php esc_attr_e( 'Seleziona tutti i soci', 'sd-logbook' ); ?>"></th>
					<th><?php esc_html_e( 'Cognome, Nome', 'sd-logbook' ); ?></th>
					<th><?php esc_html_e( 'Email', 'sd-logbook' ); ?></th>
					<th><?php esc_html_e( 'Data Nascita', 'sd-logbook' ); ?></th>
					<th><?php esc_html_e( 'Tassa', 'sd-logbook' ); ?></th>
					<th><?php esc_html_e( 'Pagato', 'sd-logbook' ); ?></th>
					<th><?php esc_html_e( 'Data Pag.', 'sd-logbook' ); ?></th>
					<th><?php esc_html_e( 'Tipo socio', 'sd-logbook' ); ?></th>						<th><?php esc_html_e( 'Attivo', 'sd-logbook' ); ?></th>					<th><?php esc_html_e( 'Sub', 'sd-logbook' ); ?></th>
					<th><?php esc_html_e( 'Diab.', 'sd-logbook' ); ?></th>
					<th><?php esc_html_e( 'Taglia', 'sd-logbook' ); ?></th>
					<th><?php esc_html_e( 'Ruolo WP', 'sd-logbook' ); ?></th>
					<th><?php esc_html_e( 'Azioni', 'sd-logbook' ); ?></th>
				</tr>
			</thead>
			<tbody id="sd-members-tbody">
				<tr>
					<td colspan="14" class="sd-table-empty"><?php esc_html_e( 'Caricamento in corso...', 'sd-logbook' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Paginazione -->
	<div class="sd-pagination" id="sd-pagination" style="display:none;">
		<button type="button" id="sd-prev-page" class="sd-btn sd-btn-secondary sd-btn-sm">
			&laquo; <?php esc_html_e( 'Precedente', 'sd-logbook' ); ?>
		</button>
		<span id="sd-page-info" class="sd-page-info"></span>
		<button type="button" id="sd-next-page" class="sd-btn sd-btn-secondary sd-btn-sm">
			<?php esc_html_e( 'Successivo', 'sd-logbook' ); ?> &raquo;
		</button>
	</div>

	</div><!-- /sd-tab-gestione -->

</div>

<!-- Modal anteprima e-mail -->
<div id="sd-email-preview-modal" class="sd-email-preview-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="sd-email-preview-modal-title">
	<div class="sd-email-preview-backdrop"></div>
	<div class="sd-email-preview-dialog">
		<div class="sd-email-preview-header">
			<div class="sd-email-preview-title-wrap">
				<span class="sd-email-preview-icon">&#9993;</span>
				<span id="sd-email-preview-modal-title" class="sd-email-preview-title"><?php esc_html_e( 'Anteprima e-mail', 'sd-logbook' ); ?></span>
			</div>
			<div class="sd-email-preview-controls">
				<button type="button" id="sd-email-preview-zoom-out" class="sd-email-preview-ctrl-btn" title="<?php esc_attr_e( 'Riduci zoom', 'sd-logbook' ); ?>">&#8722;</button>
				<span id="sd-email-preview-zoom-label" class="sd-email-preview-zoom-label">100%</span>
				<button type="button" id="sd-email-preview-zoom-in" class="sd-email-preview-ctrl-btn" title="<?php esc_attr_e( 'Aumenta zoom', 'sd-logbook' ); ?>">&#43;</button>
				<button type="button" id="sd-email-preview-zoom-reset" class="sd-email-preview-ctrl-btn" title="<?php esc_attr_e( 'Reimposta zoom', 'sd-logbook' ); ?>">&#10006;&nbsp;100%</button>
				<button type="button" id="sd-email-preview-fullscreen" class="sd-email-preview-ctrl-btn" title="<?php esc_attr_e( 'Ingrandisci finestra', 'sd-logbook' ); ?>">&#x26F6;</button>
				<button type="button" id="sd-email-preview-close" class="sd-email-preview-close-btn" title="<?php esc_attr_e( 'Chiudi', 'sd-logbook' ); ?>" aria-label="<?php esc_attr_e( 'Chiudi anteprima', 'sd-logbook' ); ?>">&times;</button>
			</div>
		</div>
		<div class="sd-email-preview-meta" id="sd-email-preview-meta">
			<span class="sd-email-preview-meta-row"><strong><?php esc_html_e( 'A:', 'sd-logbook' ); ?></strong> <span id="sd-email-preview-to"></span></span>
			<span class="sd-email-preview-meta-row"><strong><?php esc_html_e( 'Oggetto:', 'sd-logbook' ); ?></strong> <span id="sd-email-preview-subject"></span></span>
		</div>
		<div class="sd-email-preview-body-wrap" id="sd-email-preview-body-wrap">
			<div id="sd-email-preview-loading" class="sd-email-preview-loading"><?php esc_html_e( 'Caricamento anteprima...', 'sd-logbook' ); ?></div>
			<div id="sd-email-preview-error" class="sd-email-preview-error" style="display:none;"></div>
			<div id="sd-email-preview-body-scaler" class="sd-email-preview-body-scaler">
				<div id="sd-email-preview-body" class="sd-email-preview-body"></div>
			</div>
		</div>
	</div>
</div>
