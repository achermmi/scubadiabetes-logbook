<?php
/**
 * Template: Gestione modelli email
 *
 * Variabili disponibili:
 * @var array $vars  Elenco variabili supportate (tag + label)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="sd-form-wrap sd-email-tpl-wrap" id="sd-email-tpl-page">

	<div class="sd-form-header">
		<h2 class="sd-form-title"><?php esc_html_e( 'Modelli Email', 'sd-logbook' ); ?></h2>
	</div>

	<!-- Messaggi -->
	<div class="sd-notice sd-email-tpl-message" id="sd-email-tpl-message" style="display:none;"></div>

	<!-- Layout a due colonne: lista | editor -->
	<div class="sd-email-tpl-layout">

		<!-- ==================== LISTA MODELLI ==================== -->
		<div class="sd-email-tpl-sidebar" id="sd-email-tpl-sidebar">
			<div class="sd-email-tpl-sidebar-header">
				<h3><?php esc_html_e( 'Modelli salvati', 'sd-logbook' ); ?></h3>
				<button type="button" class="sd-btn sd-btn-primary sd-btn-sm" id="sd-email-tpl-new">
					+ <?php esc_html_e( 'Nuovo modello', 'sd-logbook' ); ?>
				</button>
			</div>
			<div class="sd-email-tpl-list" id="sd-email-tpl-list">
				<div class="sd-email-tpl-loading" id="sd-email-tpl-loading">
					<?php esc_html_e( 'Caricamento...', 'sd-logbook' ); ?>
				</div>
			</div>
		</div>

		<!-- ==================== EDITOR ==================== -->
		<div class="sd-email-tpl-editor" id="sd-email-tpl-editor">

			<!-- Form nascosto di default -->
			<form id="sd-email-tpl-form" autocomplete="off" novalidate>
				<input type="hidden" id="sd-tpl-id" name="id" value="0">

				<!-- VARIABILI DISPONIBILI IN ALTO -->
				<div class="sd-email-tpl-vars-section">
					<h4 class="sd-email-tpl-vars-title"><?php esc_html_e( 'Variabili disponibili', 'sd-logbook' ); ?></h4>
					<div class="sd-var-chips sd-var-chips-main">
						<?php foreach ( $vars as $v ) : ?>
							<button type="button" class="sd-var-chip" data-var="<?php echo esc_attr( $v['tag'] ); ?>"
								title="<?php echo esc_attr( $v['label'] ); ?>">
								<?php echo esc_html( $v['tag'] ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Preset rapidi -->
				<div class="sd-field-row sd-preset-row">
					<label class="sd-field-label" for="sd-tpl-preset">
						<?php esc_html_e( 'Preset rapidi', 'sd-logbook' ); ?>
					</label>
					<div class="sd-preset-controls">
						<select id="sd-tpl-preset" class="sd-field-input sd-preset-select">
							<option value=""><?php esc_html_e( 'Seleziona un modello predefinito...', 'sd-logbook' ); ?></option>
							<option value="reminder_renewal"><?php esc_html_e( 'Reminder rinnovo annuale', 'sd-logbook' ); ?></option>
							<option value="payment_solicit"><?php esc_html_e( 'Sollecito pagamento quota', 'sd-logbook' ); ?></option>
							<option value="welcome_member"><?php esc_html_e( 'Benvenuto nuovo socio', 'sd-logbook' ); ?></option>
						</select>
						<button type="button" class="sd-btn sd-btn-secondary" id="sd-tpl-apply-preset">
							<?php esc_html_e( 'Applica preset', 'sd-logbook' ); ?>
						</button>
					</div>
				</div>

				<!-- Nome modello -->
				<div class="sd-field-row">
					<label class="sd-field-label" for="sd-tpl-name">
						<?php esc_html_e( 'Nome modello', 'sd-logbook' ); ?> <span class="required">*</span>
					</label>
					<input type="text" id="sd-tpl-name" name="name" class="sd-field-input"
						placeholder="<?php esc_attr_e( 'Es. Reminder rinnovo annuale', 'sd-logbook' ); ?>" required>
				</div>

				<!-- Oggetto -->
				<div class="sd-field-row">
					<label class="sd-field-label" for="sd-tpl-subject">
						<?php esc_html_e( 'Oggetto', 'sd-logbook' ); ?> <span class="required">*</span>
					</label>
					<input type="text" id="sd-tpl-subject" name="subject" class="sd-field-input"
						placeholder="<?php esc_attr_e( 'Es. Rinnovo iscrizione {{anno_oggi}} — {{nome}} {{cognome}}', 'sd-logbook' ); ?>" required>
				</div>

				<!-- Layout a due colonne: Editor | Anteprima -->
				<div class="sd-email-tpl-editor-layout">

					<!-- Editor (sinistra) -->
					<div class="sd-email-tpl-editor-col">

						<!-- Corpo -->
						<div class="sd-field-row">
							<label class="sd-field-label" for="sd-tpl-body">
								<?php esc_html_e( 'Corpo email (HTML)', 'sd-logbook' ); ?> <span class="required">*</span>
							</label>
							<div class="sd-codemirror-wrap" id="sd-tpl-body-wrap">
								<textarea id="sd-tpl-body" name="body" class="sd-codemirror-textarea" rows="16"
									placeholder="<?php esc_attr_e( '<p>Caro/a <strong>{{nome}} {{cognome}}</strong>,</p>', 'sd-logbook' ); ?>"></textarea>
							</div>
						</div>

						<!-- Firma -->
						<div class="sd-field-row">
							<label class="sd-field-label" for="sd-tpl-signature">
								<?php esc_html_e( 'Firma (HTML)', 'sd-logbook' ); ?>
							</label>
							<div class="sd-codemirror-wrap" id="sd-tpl-signature-wrap">
								<textarea id="sd-tpl-signature" name="signature" class="sd-codemirror-textarea" rows="8"
									placeholder="<?php esc_attr_e( '<p>Cordiali saluti,<br>Segretariato ScubaDiabetes<br><a href=\"mailto:{{email_associazione}}\">{{email_associazione}}</a></p>', 'sd-logbook' ); ?>"></textarea>
							</div>
						</div>

					</div><!-- /.sd-email-tpl-editor-col -->

					<!-- Anteprima (destra) -->
					<div class="sd-email-tpl-preview-col" id="sd-tpl-preview-col" style="display:none;">
						<label class="sd-field-label"><?php esc_html_e( 'Anteprima', 'sd-logbook' ); ?></label>
						<div class="sd-tpl-preview" id="sd-tpl-preview">
							<div class="sd-tpl-preview-subject" id="sd-tpl-preview-subject"></div>
							<div class="sd-tpl-preview-body" id="sd-tpl-preview-body"></div>
							<div class="sd-tpl-preview-signature" id="sd-tpl-preview-signature"></div>
						</div>
					</div><!-- /.sd-email-tpl-preview-col -->

				</div><!-- /.sd-email-tpl-editor-layout -->

				<!-- Azioni form -->
				<div class="sd-email-tpl-form-actions">
					<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm" id="sd-tpl-toggle-preview">
						<?php esc_html_e( 'Mostra anteprima', 'sd-logbook' ); ?>
					</button>
					<div class="sd-email-tpl-form-actions-right">
						<button type="button" class="sd-btn sd-btn-secondary" id="sd-tpl-cancel">
							<?php esc_html_e( 'Annulla', 'sd-logbook' ); ?>
						</button>
						<button type="submit" class="sd-btn sd-btn-primary" id="sd-tpl-save">
							<?php esc_html_e( 'Salva modello', 'sd-logbook' ); ?>
						</button>
					</div>
				</div>

			</form>

			<!-- Placeholder quando nessun modello è selezionato -->
			<div class="sd-email-tpl-placeholder" id="sd-email-tpl-placeholder">
				<p><?php esc_html_e( 'Seleziona un modello dalla lista o crea un nuovo modello.', 'sd-logbook' ); ?></p>
			</div>

		</div><!-- /.sd-email-tpl-editor -->
	</div><!-- /.sd-email-tpl-layout -->

	<!-- Legenda variabili -->
	<div class="sd-email-tpl-vars-legend">
		<h4><?php esc_html_e( 'Variabili disponibili', 'sd-logbook' ); ?></h4>
		<table class="sd-email-tpl-vars-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Variabile', 'sd-logbook' ); ?></th>
					<th><?php esc_html_e( 'Descrizione', 'sd-logbook' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $vars as $v ) : ?>
					<tr>
						<td><code><?php echo esc_html( $v['tag'] ); ?></code></td>
						<td><?php echo esc_html( $v['label'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

</div><!-- /#sd-email-tpl-page -->
