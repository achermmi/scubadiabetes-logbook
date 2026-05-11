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

	<!-- Cruscotto rinnovi soci -->
	<div class="sd-renewals-dashboard" id="sd-renewals-dashboard">
		<div class="sd-renewals-header">
			<h3><?php esc_html_e( 'Cruscotto Rinnovi Soci', 'sd-logbook' ); ?></h3>
			<p><?php esc_html_e( 'Stato iscrizione, scadenza, importo dovuto e reminder email con un click.', 'sd-logbook' ); ?></p>
		</div>
		<div class="sd-renewals-loading" id="sd-renewals-loading" style="display:none;">
			<?php esc_html_e( 'Caricamento cruscotto rinnovi...', 'sd-logbook' ); ?>
		</div>
		<div class="sd-renewals-message sd-notice" id="sd-renewals-message" style="display:none;"></div>

		<!-- Selezione modello email per i reminder -->
		<div class="sd-renewals-template-row">
			<label class="sd-renewals-template-label" for="sd-renewals-template-id">
				<?php esc_html_e( 'Modello email reminder:', 'sd-logbook' ); ?>
			</label>
			<select id="sd-renewals-template-id" class="sd-field-input sd-renewals-template-select">
				<option value="0"><?php esc_html_e( '— Testo predefinito —', 'sd-logbook' ); ?></option>
				<?php
				if ( class_exists( 'SD_Email_Templates' ) ) {
					foreach ( SD_Email_Templates::get_all_as_options() as $tpl_id => $tpl_name ) {
						echo '<option value="' . esc_attr( $tpl_id ) . '">' . esc_html( $tpl_name ) . '</option>';
					}
				}
				?>
			</select>
		</div>

		<div class="sd-renewals-tools">
			<div class="sd-renewals-quick-filters" id="sd-renewals-quick-filters" role="group" aria-label="<?php esc_attr_e( 'Filtro rapido rinnovi', 'sd-logbook' ); ?>">
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-renewals-filter is-active" data-renewals-filter="all"><?php esc_html_e( 'Tutti', 'sd-logbook' ); ?></button>
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-renewals-filter" data-renewals-filter="scaduti"><?php esc_html_e( 'Solo scaduti', 'sd-logbook' ); ?></button>
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-renewals-filter" data-renewals-filter="in_scadenza"><?php esc_html_e( 'Solo in scadenza', 'sd-logbook' ); ?></button>
				<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-renewals-filter" data-renewals-filter="non_pagati"><?php esc_html_e( 'Solo non pagati', 'sd-logbook' ); ?></button>
			</div>
			<button type="button" class="sd-btn sd-btn-primary sd-btn-sm" id="sd-renewals-bulk-remind"><?php esc_html_e( 'Invia reminder massivo (in scadenza)', 'sd-logbook' ); ?></button>
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
						<th><?php esc_html_e( 'Ultimo Reminder', 'sd-logbook' ); ?></th>
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

	<!-- Barra filtri -->
	<div class="sd-filter-bar">
		<form id="sd-member-filters" class="sd-filters-form">

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

				<div class="sd-filter-group sd-filter-actions">
					<button type="submit" id="sd-btn-search" class="sd-btn sd-btn-primary sd-btn-sm sd-action-btn">
						<?php esc_html_e( 'Cerca', 'sd-logbook' ); ?>
					</button>
					<button type="button" id="sd-btn-reset" class="sd-btn sd-btn-secondary sd-btn-sm sd-action-btn">
						<?php esc_html_e( 'Reset', 'sd-logbook' ); ?>
					</button>
					<button type="button" id="sd-delete-selected" class="sd-btn sd-btn-danger sd-btn-sm sd-action-btn">
						<?php esc_html_e( 'Elimina iscrizione', 'sd-logbook' ); ?>
					</button>
					<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-action-btn" id="sd-export-csv" data-format="csv">
						↓ CSV
					</button>
					<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-action-btn" id="sd-export-xlsx" data-format="xlsx">
						↓ Excel
					</button>
					<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-action-btn" id="sd-export-pdf">
						↓ PDF
					</button>
				</div>
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

</div>
