<?php
/**
 * Template: Dashboard Admin Attivita
 *
 * Shortcode: [sd_gestione_attivita]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>


<div class="sd-form-wrap sd-activity-admin-wrap" id="sd-activity-admin-page">
	<div class="sd-form-header">
		<h2 class="sd-form-title"><?php esc_html_e( 'Gestione Attivita', 'sd-logbook' ); ?></h2>
		<p class="sd-form-subtitle"><?php esc_html_e( 'Crea attivita, configura modulo/tariffe e gestisci iscrizioni e pagamenti.', 'sd-logbook' ); ?></p>
	</div>
	<div id="sd-global-message" class="sd-notice" style="display:none;"></div>

	<div class="sd-admin-tabs">
		<button type="button" class="sd-admin-tab is-active" data-tab="attivita"><?php esc_html_e('Attivita', 'sd-logbook'); ?></button>
		<button type="button" class="sd-admin-tab" data-tab="registrazioni"><?php esc_html_e('Registrazioni', 'sd-logbook'); ?></button>
		<button type="button" class="sd-admin-tab" data-tab="pagamenti"><?php esc_html_e('Pagamenti', 'sd-logbook'); ?></button>
	</div>

	   <div class="sd-admin-panel is-active" data-panel="attivita">
		   <div class="sd-activity-filters-row">
			   <input type="text" id="sd-activity-search" class="sd-input" placeholder="<?php esc_attr_e('Cerca per titolo o luogo...', 'sd-logbook'); ?>">
			   <select id="sd-activity-status-filter" class="sd-select">
				   <option value=""><?php esc_html_e('Tutti gli stati', 'sd-logbook'); ?></option>
				   <option value="draft"><?php esc_html_e('Draft', 'sd-logbook'); ?></option>
				   <option value="published"><?php esc_html_e('Pubblicata', 'sd-logbook'); ?></option>
				   <option value="closed"><?php esc_html_e('Conclusa', 'sd-logbook'); ?></option>
				   <option value="archived"><?php esc_html_e('Archiviata', 'sd-logbook'); ?></option>
			   </select>
			   <button type="button" id="sd-activity-filter-btn" class="sd-btn sd-btn-secondary"><?php esc_html_e('Filtra', 'sd-logbook'); ?></button>
			   <button type="button" id="sd-activity-new-btn" class="sd-btn sd-btn-primary"><?php esc_html_e('+ Nuova Attivita', 'sd-logbook'); ?></button>
		   </div>
		   <div class="sd-table-wrap" id="sd-activities-table-wrap">
			   <table class="sd-admin-table" id="sd-activities-table">
				   <thead>
					   <tr>
						   <th><?php esc_html_e( 'ID', 'sd-logbook' ); ?></th>
						   <th><?php esc_html_e( 'Titolo', 'sd-logbook' ); ?></th>
						   <th><?php esc_html_e( 'Data Inizio', 'sd-logbook' ); ?></th>
						   <th><?php esc_html_e( 'Luogo', 'sd-logbook' ); ?></th>
						   <th><?php esc_html_e( 'Posti', 'sd-logbook' ); ?></th>
						   <th><?php esc_html_e( 'Stato', 'sd-logbook' ); ?></th>
						   <th><?php esc_html_e( 'Azioni', 'sd-logbook' ); ?></th>
					   </tr>
				   </thead>
				   <tbody id="sd-activities-tbody">
					   <tr><td colspan="7" class="sd-table-empty"><?php esc_html_e( 'Caricamento attivita...', 'sd-logbook' ); ?></td></tr>
				   </tbody>
			   </table>
		   </div>
	   </div>

	<div class="sd-admin-panel" data-panel="registrazioni">
		<div class="sd-panel-placeholder">
			<?php esc_html_e('Seleziona un\'attività per vedere le registrazioni.', 'sd-logbook'); ?>
		</div>
		<!-- Qui verrà caricata la tabella delle registrazioni via JS -->
	</div>

	<div class="sd-admin-panel" data-panel="pagamenti">
		<div class="sd-panel-placeholder">
			<?php esc_html_e('Seleziona un\'attività per vedere i pagamenti.', 'sd-logbook'); ?>
		</div>
		<!-- Qui verrà caricata la tabella dei pagamenti via JS -->
	</div>

	   <!-- tabella spostata dentro il pannello attivita -->
	</section>

	<!-- TAB MODIFICA -->
	<section class="sd-admin-panel" data-panel="modifica">
		<div id="sd-modifica-sections-stack">
		<form id="sd-activity-form" class="sd-form-section" novalidate data-layout-key="activity_data">
			<input type="hidden" id="sd-activity-id" value="0">
			<div class="sd-section-headbar">
				<h3 class="sd-section-title"><span class="sd-section-title-text"><?php esc_html_e( 'Dati Attivita', 'sd-logbook' ); ?></span></h3>
				<div class="sd-section-headbar-tools">
					<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-section-rename" data-section="activity_data"><?php esc_html_e( 'Rinomina', 'sd-logbook' ); ?></button>
				</div>
			</div>

			<div id="sd-activity-static-order-controls" class="sd-static-order-controls" style="display:none;"></div>

			<div id="sd-activity-static-blocks">
				<div class="sd-activity-static-block" data-static-block="core">
					<div class="sd-field-row">
						<div class="sd-field-group sd-field-half">
							<label for="sd-activity-title" class="sd-label sd-label-required"><?php esc_html_e( 'Titolo', 'sd-logbook' ); ?></label>
							<input type="text" id="sd-activity-title" class="sd-input" required>
						</div>
						<div class="sd-field-group sd-field-half">
							<label for="sd-activity-location" class="sd-label"><?php esc_html_e( 'Luogo', 'sd-logbook' ); ?></label>
							<input type="text" id="sd-activity-location" class="sd-input">
						</div>
					</div>

					<div class="sd-field-row">
						<div class="sd-field-group sd-field-third">
							<label for="sd-activity-start" class="sd-label sd-label-required"><?php esc_html_e( 'Data Inizio', 'sd-logbook' ); ?></label>
							<input type="datetime-local" id="sd-activity-start" class="sd-input" required>
						</div>
						<div class="sd-field-group sd-field-third">
							<label for="sd-activity-end" class="sd-label sd-label-required"><?php esc_html_e( 'Data Fine', 'sd-logbook' ); ?></label>
							<input type="datetime-local" id="sd-activity-end" class="sd-input" required>
						</div>
						<div class="sd-field-group sd-field-third">
							<label for="sd-activity-max" class="sd-label"><?php esc_html_e( 'Max Partecipanti', 'sd-logbook' ); ?></label>
							<input type="number" id="sd-activity-max" class="sd-input" min="1">
						</div>
					</div>
				</div>

				<div class="sd-activity-static-block" data-static-block="thumbnail">
					<div class="sd-field-row">
						<div class="sd-field-group sd-field-full">
							<label for="sd-activity-thumbnail" class="sd-label"><?php esc_html_e( 'Immagine URL', 'sd-logbook' ); ?></label>
							<div class="sd-activity-thumbnail-row">
								<input type="url" id="sd-activity-thumbnail" class="sd-input">
								<button type="button" id="sd-activity-thumbnail-media-btn" class="sd-btn sd-btn-secondary"><?php esc_html_e( 'Media Library', 'sd-logbook' ); ?></button>
							</div>
							<div class="sd-activity-thumb-preview" id="sd-activity-thumb-preview">
								<div class="sd-activity-thumb-placeholder"><?php esc_html_e( 'Anteprima immagine attività', 'sd-logbook' ); ?></div>
							</div>
						</div>
					</div>
				</div>

				<div class="sd-activity-static-block" data-static-block="description">
					<div class="sd-field-row">
						<div class="sd-field-group sd-field-full">
							<label for="sd-activity-description" class="sd-label"><?php esc_html_e( 'Descrizione', 'sd-logbook' ); ?></label>
							<?php
							// Editor identico a quello dei Template Email (vedi class-sd-email-templates.php).
							// Render lato server come semplice <textarea>; l'init TinyMCE avviene UNA SOLA volta
							// lato client tramite wp.editor.initialize(), mai ri-inizializzato durante editActivity()
							// per evitare la ricorsione St.setDocument indotta da TADV.
							?>
							<textarea id="sd-activity-description" name="description" class="sd-codemirror-textarea" rows="16"></textarea>
						</div>
					</div>
				</div>

				<div class="sd-activity-static-block" data-static-block="status">
					<div class="sd-field-row">
						<div class="sd-field-group sd-field-full">
							<label for="sd-activity-status" class="sd-label"><?php esc_html_e( 'Stato', 'sd-logbook' ); ?></label>
							<select id="sd-activity-status" class="sd-select">
								<option value="draft"><?php esc_html_e( 'Draft', 'sd-logbook' ); ?></option>
								<option value="published"><?php esc_html_e( 'Pubblicata', 'sd-logbook' ); ?></option>
								<option value="closed"><?php esc_html_e( 'Conclusa', 'sd-logbook' ); ?></option>
								<option value="archived"><?php esc_html_e( 'Archiviata', 'sd-logbook' ); ?></option>
							</select>
						</div>
					</div>
				</div>

				<div class="sd-activity-static-block" data-static-block="extra_fields">
					<div id="sd-activity-data-extra-fields" class="sd-field-row" style="display:none;"></div>
				</div>
			</div>

			<div class="sd-form-actions">
				<button type="submit" class="sd-btn sd-btn-primary"><?php esc_html_e( 'Salva Attivita', 'sd-logbook' ); ?></button>
				<button type="button" id="sd-activity-form-reset" class="sd-btn sd-btn-secondary"><?php esc_html_e( 'Nuova', 'sd-logbook' ); ?></button>
			</div>

			<p id="sd-activity-shortcode-hint" class="sd-field-note" style="margin-top:0.75rem;">
				<?php esc_html_e( 'Salva l\'attivita per ottenere lo shortcode.', 'sd-logbook' ); ?>
			</p>
			<p>
				<button type="button" id="sd-copy-shortcode-btn" class="sd-btn sd-btn-secondary" style="display:none;">
					<?php esc_html_e( 'Copia shortcode', 'sd-logbook' ); ?>
				</button>
			</p>
		</form>

		<div class="sd-admin-grid-2" id="sd-sections-secondary-stack">
			<div class="sd-form-section" id="sd-section-tariffe" data-layout-key="tariffe">
				<h3 class="sd-section-title"><?php esc_html_e( 'Tariffe', 'sd-logbook' ); ?></h3>
				<p class="sd-field-note"><?php esc_html_e( 'Definisci le diverse tariffe dell\'attivita. Il valore EUR viene proposto automaticamente dal cambio CHF/EUR del giorno.', 'sd-logbook' ); ?></p>
				<form id="sd-activity-price-form">
					<input type="hidden" id="sd-price-id" value="0">
					<div class="sd-price-form-grid">
						<div class="sd-field-group">
							<label for="sd-price-name" class="sd-label sd-label-required"><?php esc_html_e( 'Nome tariffa', 'sd-logbook' ); ?></label>
							<input type="text" id="sd-price-name" class="sd-input" placeholder="<?php esc_attr_e( 'Es. Soci, Non soci, Early bird', 'sd-logbook' ); ?>" required>
						</div>
						<div class="sd-field-group">
							<label for="sd-price-chf" class="sd-label sd-label-required"><?php esc_html_e( 'Importo CHF', 'sd-logbook' ); ?></label>
							<input type="number" id="sd-price-chf" class="sd-input" min="0" step="0.01" inputmode="decimal" placeholder="520.00" required>
						</div>
						<div class="sd-field-group">
							<label for="sd-price-eur" class="sd-label"><?php esc_html_e( 'Importo EUR', 'sd-logbook' ); ?></label>
							<input type="number" id="sd-price-eur" class="sd-input" min="0" step="0.01" inputmode="decimal" placeholder="0.00" readonly>
						</div>
						<div class="sd-field-group sd-price-submit-wrap">
							<span class="sd-label sd-label-ghost" aria-hidden="true">&nbsp;</span>
							<button type="submit" id="sd-price-submit-btn" class="sd-btn sd-btn-secondary"><?php esc_html_e( 'Aggiungi Tariffa', 'sd-logbook' ); ?></button>
						</div>
					</div>
					<div class="sd-price-form-actions">
						<label class="sd-admin-inline-check">
							<input type="checkbox" id="sd-price-is-default" value="1">
							<span><?php esc_html_e( 'Tariffa predefinita', 'sd-logbook' ); ?></span>
						</label>
						<button type="button" id="sd-price-cancel-edit" class="sd-btn sd-btn-secondary sd-btn-sm" style="display:none;"><?php esc_html_e( 'Annulla modifica', 'sd-logbook' ); ?></button>
					</div>
					<p id="sd-price-rate-note" class="sd-field-note sd-price-rate-note">
						<?php esc_html_e( 'Salva prima l\'attivita per poter aggiungere tariffe.', 'sd-logbook' ); ?>
					</p>
				</form>
				<ul id="sd-prices-list" class="sd-mini-list">
					<li class="sd-mini-list-empty"><?php esc_html_e( 'Salva l\'attivita per aggiungere tariffe.', 'sd-logbook' ); ?></li>
				</ul>
			</div>

			<div class="sd-form-section" id="sd-section-campi-modulo" data-layout-key="campi_modulo">
				<h3 class="sd-section-title"><?php esc_html_e( 'Campi Modulo', 'sd-logbook' ); ?></h3>
				<form id="sd-activity-field-form">
					<input type="hidden" id="sd-field-id" value="0">
					<div class="sd-inline-form">
						<input type="text" id="sd-field-label" class="sd-input" placeholder="<?php esc_attr_e( 'Etichetta campo', 'sd-logbook' ); ?>" required>
						<select id="sd-field-type" class="sd-select">
							<option value="text">Text</option>
							<option value="textarea">Textarea</option>
							<option value="select">Select</option>
							<option value="checkbox">Checkbox</option>
							<option value="radio">Radio</option>
							<option value="date">Date</option>
							<option value="number">Number</option>
							<option value="content"><?php esc_html_e( 'Contenuto Formattato', 'sd-logbook' ); ?></option>
							<option value="image"><?php esc_html_e( 'Immagine', 'sd-logbook' ); ?></option>
						</select>
						<button type="submit" class="sd-btn sd-btn-secondary" id="sd-field-submit-btn"><?php esc_html_e( 'Aggiungi Campo', 'sd-logbook' ); ?></button>
					</div>
					<div class="sd-field-builder-meta">
						<select id="sd-field-section" class="sd-select">
							<option value="personal"><?php esc_html_e( 'Sezione: Dati Personali', 'sd-logbook' ); ?></option>
							<option value="activity_data"><?php esc_html_e( 'Sezione: Dati Attivita', 'sd-logbook' ); ?></option>
							<option value="additional" selected><?php esc_html_e( 'Sezione: Informazioni Aggiuntive', 'sd-logbook' ); ?></option>
							<option value="pricing"><?php esc_html_e( 'Sezione: Selezione Tariffa', 'sd-logbook' ); ?></option>
							<option value="consents"><?php esc_html_e( 'Sezione: Consensi', 'sd-logbook' ); ?></option>
							<option value="__new__"><?php esc_html_e( 'Nuova sezione personalizzata', 'sd-logbook' ); ?></option>
						</select>
						<input type="number" id="sd-field-section-order" class="sd-input" min="1" step="1" value="20" placeholder="<?php esc_attr_e( 'Ordine sezione', 'sd-logbook' ); ?>">
						<label class="sd-admin-inline-check">
							<input type="checkbox" id="sd-field-required" value="1">
							<span><?php esc_html_e( 'Obbligatorio', 'sd-logbook' ); ?></span>
						</label>
					</div>
					<div id="sd-custom-section-wrap" class="sd-inline-form sd-custom-section-row" style="display:none;">
						<input type="text" id="sd-custom-section-label" class="sd-input" placeholder="<?php esc_attr_e( 'Titolo nuova sezione', 'sd-logbook' ); ?>">
						<input type="text" id="sd-custom-section-key" class="sd-input" placeholder="<?php esc_attr_e( 'Chiave sezione (opzionale)', 'sd-logbook' ); ?>">
						<button type="button" id="sd-field-cancel-edit" class="sd-btn sd-btn-secondary" style="display:none;"><?php esc_html_e( 'Annulla Modifica', 'sd-logbook' ); ?></button>
					</div>
					<div id="sd-field-options-wrap" style="display:none;">
						<p class="sd-field-options-label"><?php esc_html_e( 'Opzioni (aggiungi le scelte disponibili):', 'sd-logbook' ); ?></p>
						<div class="sd-inline-form sd-options-add-row">
							<input type="text" id="sd-option-label" class="sd-input" placeholder="<?php esc_attr_e( 'Etichetta opzione', 'sd-logbook' ); ?>">
							<input type="text" id="sd-option-value" class="sd-input" placeholder="<?php esc_attr_e( 'Valore (slug)', 'sd-logbook' ); ?>">
							<button type="button" id="sd-add-option-btn" class="sd-btn sd-btn-outline"><?php esc_html_e( '+ Opzione', 'sd-logbook' ); ?></button>
						</div>
						<ul id="sd-options-preview" class="sd-mini-list"></ul>
					</div>
					<div id="sd-field-content-wrap" style="display:none;">
						<p class="sd-field-options-label"><?php esc_html_e( 'Contenuto Formattato:', 'sd-logbook' ); ?></p>
						<textarea id="sd-field-content-editor" name="field_content" class="sd-codemirror-textarea" rows="12" style="width:100%; min-height:250px;"></textarea>
					</div>
					<div id="sd-field-image-wrap" style="display:none;">
						<p class="sd-field-options-label"><?php esc_html_e( 'Configurazione Immagine:', 'sd-logbook' ); ?></p>
						
						<!-- Image Preview -->
						<div class="sd-image-preview-container">
							<div id="sd-image-preview" class="sd-image-preview-box">
								<div class="sd-image-preview-placeholder">
									<?php esc_html_e( 'Anteprima immagine', 'sd-logbook' ); ?>
								</div>
							</div>
						</div>
						
						<div class="sd-field-builder-meta">
							<span class="sd-label"><?php esc_html_e( 'Tipo:', 'sd-logbook' ); ?></span>
							<div class="sd-radio-group">
								<label class="sd-admin-inline-check">
									<input type="radio" name="sd-image-type" value="display" checked>
									<span><?php esc_html_e( 'Visualizzazione (immagine statica)', 'sd-logbook' ); ?></span>
								</label>
								<label class="sd-admin-inline-check">
									<input type="radio" name="sd-image-type" value="upload">
									<span><?php esc_html_e( 'Upload (utente carica file)', 'sd-logbook' ); ?></span>
								</label>
							</div>
						</div>
						<div class="sd-field-builder-meta" id="sd-image-source-wrap">
							<label for="sd-image-url" class="sd-label"><?php esc_html_e( 'URL Immagine (per Display):', 'sd-logbook' ); ?></label>
							<div class="sd-inline-form">
								<input type="url" id="sd-image-url" class="sd-input" placeholder="https://example.com/image.jpg">
								<button type="button" id="sd-image-media-btn" class="sd-btn sd-btn-secondary"><?php esc_html_e( 'Media Library', 'sd-logbook' ); ?></button>
							</div>
						</div>
						<div class="sd-field-builder-meta">
							<span class="sd-label"><?php esc_html_e( 'Dimensionamento:', 'sd-logbook' ); ?></span>
							<div class="sd-inline-form">
								<input type="number" id="sd-image-width" class="sd-input" placeholder="<?php esc_attr_e( 'Larghezza (px)', 'sd-logbook' ); ?>" min="50">
								<input type="number" id="sd-image-height" class="sd-input" placeholder="<?php esc_attr_e( 'Altezza (px)', 'sd-logbook' ); ?>" min="50">
								<label class="sd-admin-inline-check">
									<input type="checkbox" id="sd-image-aspect-ratio" checked>
									<span><?php esc_html_e( 'Mantieni proporzioni', 'sd-logbook' ); ?></span>
								</label>
							</div>
						</div>
						<div class="sd-field-builder-meta">
							<span class="sd-label"><?php esc_html_e( 'Allineamento Orizzontale:', 'sd-logbook' ); ?></span>
							<div class="sd-radio-group">
								<label class="sd-admin-inline-check">
									<input type="radio" name="sd-image-align-h" value="left" checked>
									<span><?php esc_html_e( 'Sinistra', 'sd-logbook' ); ?></span>
								</label>
								<label class="sd-admin-inline-check">
									<input type="radio" name="sd-image-align-h" value="center">
									<span><?php esc_html_e( 'Centro', 'sd-logbook' ); ?></span>
								</label>
								<label class="sd-admin-inline-check">
									<input type="radio" name="sd-image-align-h" value="right">
									<span><?php esc_html_e( 'Destra', 'sd-logbook' ); ?></span>
								</label>
							</div>
						</div>
						<div class="sd-field-builder-meta">
							<span class="sd-label"><?php esc_html_e( 'Allineamento Verticale:', 'sd-logbook' ); ?></span>
							<div class="sd-radio-group">
								<label class="sd-admin-inline-check">
									<input type="radio" name="sd-image-align-v" value="top" checked>
									<span><?php esc_html_e( 'Alto', 'sd-logbook' ); ?></span>
								</label>
								<label class="sd-admin-inline-check">
									<input type="radio" name="sd-image-align-v" value="middle">
									<span><?php esc_html_e( 'Centro', 'sd-logbook' ); ?></span>
								</label>
								<label class="sd-admin-inline-check">
									<input type="radio" name="sd-image-align-v" value="bottom">
									<span><?php esc_html_e( 'Basso', 'sd-logbook' ); ?></span>
								</label>
							</div>
						</div>
						<div class="sd-field-builder-meta">
							<label for="sd-image-alt-text" class="sd-label"><?php esc_html_e( 'Testo Alt (per accessibilità):', 'sd-logbook' ); ?></label>
							<input type="text" id="sd-image-alt-text" class="sd-input" placeholder="<?php esc_attr_e( 'Descrizione immagine', 'sd-logbook' ); ?>">
						</div>
					</div>
					<div id="sd-field-condition-wrap" class="sd-field-builder-meta">
						<select id="sd-condition-mode" class="sd-select">
							<option value="and"><?php esc_html_e( 'Tutte vere (AND)', 'sd-logbook' ); ?></option>
							<option value="or"><?php esc_html_e( 'Almeno una vera (OR)', 'sd-logbook' ); ?></option>
						</select>
						<button type="button" id="sd-add-condition-rule" class="sd-btn sd-btn-secondary"><?php esc_html_e( '+ Condizione', 'sd-logbook' ); ?></button>
					</div>
					<div id="sd-condition-rules"></div>
					<p class="sd-field-options-label"><?php esc_html_e( 'Condizione: il campo sarà visibile solo quando la condizione è vera.', 'sd-logbook' ); ?></p>
					<div class="sd-field-builder-actions">
						<button type="button" id="sd-field-cancel-edit-secondary" class="sd-btn sd-btn-secondary" style="display:none;"><?php esc_html_e( 'Annulla Modifica', 'sd-logbook' ); ?></button>
					</div>
				</form>
			</div>
		</div>

		<div class="sd-form-section sd-form-section-wide" id="sd-section-sezioni-modulo" data-layout-key="sezioni_modulo">
			<h3 class="sd-section-title"><?php esc_html_e( 'Sezioni Modulo', 'sd-logbook' ); ?></h3>
			<div id="sd-fields-list" class="sd-fields-container"></div>
		</div>
		</div>
	</section>

	<!-- TAB REGISTRAZIONI -->
	<section class="sd-admin-panel" data-panel="registrazioni">
		<div class="sd-form-section">
			<div id="sd-reg-minor-alert" class="sd-notice sd-notice-error" style="display:none;"></div>

			<!-- Cruscotto Registrazioni (parallelo a Cruscotto Rinnovi Soci) -->
			<div class="sd-renewals-dashboard" id="sd-reg-dashboard">
				<div class="sd-renewals-header">
					<h3><?php esc_html_e( 'Cruscotto Registrazioni', 'sd-logbook' ); ?></h3>
					<p><?php esc_html_e( 'Stato pagamento e invio e-mail rapido alle persone iscritte all\'attività selezionata.', 'sd-logbook' ); ?></p>
				</div>
				<div class="sd-renewals-loading" id="sd-reg-dashboard-loading" style="display:none;">
					<?php esc_html_e( 'Caricamento cruscotto registrazioni...', 'sd-logbook' ); ?>
				</div>
				<div class="sd-renewals-message sd-notice" id="sd-reg-dashboard-message" style="display:none;"></div>

				<!-- Riga 1: selezione attività -->
				<div class="sd-renewals-template-row">
					<label class="sd-renewals-template-label" for="sd-reg-activity-id"><?php esc_html_e( 'Attività:', 'sd-logbook' ); ?></label>
					<select id="sd-reg-activity-id" class="sd-field-input sd-renewals-template-select"></select>
				</div>

				<div class="sd-renewals-tools">
					<!-- Riga 2: filtri rapidi + cerca -->
					<div class="sd-renewals-quick-filters" id="sd-reg-quick-filters" role="group" aria-label="<?php esc_attr_e( 'Filtro rapido registrazioni', 'sd-logbook' ); ?>">
						<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-filter is-active" data-reg-filter="all"><?php esc_html_e( 'Tutti', 'sd-logbook' ); ?></button>
						<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-filter" data-reg-filter="pending"><?php esc_html_e( 'Solo in attesa', 'sd-logbook' ); ?></button>
						<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-filter" data-reg-filter="paid"><?php esc_html_e( 'Solo pagati', 'sd-logbook' ); ?></button>
						<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-filter" data-reg-filter="invoice_requested"><?php esc_html_e( 'Solo fattura richiesta', 'sd-logbook' ); ?></button>
						<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-filter" data-reg-filter="valid_email"><?php esc_html_e( 'Solo con e-mail valida', 'sd-logbook' ); ?></button>
						<input type="text" id="sd-reg-search" class="sd-field-input sd-reg-search-inline" placeholder="<?php esc_attr_e( 'Cerca nome, cognome, email...', 'sd-logbook' ); ?>">
					</div>

					<!-- Riga 3: modello e-mail + azioni e-mail -->
					<div class="sd-renewals-bulk-group">
						<label class="sd-renewals-template-label" for="sd-reg-template-id"><?php esc_html_e( 'Modello e-mail:', 'sd-logbook' ); ?></label>
						<select id="sd-reg-template-id" class="sd-field-input sd-tpl-email-select">
							<option value="0"><?php esc_html_e( '— Seleziona modello e-mail —', 'sd-logbook' ); ?></option>
							<?php
							if ( class_exists( 'SD_Email_Templates' ) ) {
								foreach ( SD_Email_Templates::get_all_as_options( array( 'template_type' => 'activity' ) ) as $tpl_id => $tpl_name ) {
									echo '<option value="' . esc_attr( $tpl_id ) . '">' . esc_html( $tpl_name ) . '</option>';
								}
							}
							?>
						</select>
						<span class="sd-group-sep"></span>
						<button type="button" class="sd-reg-filter" id="sd-reg-bulk-email"><?php esc_html_e( 'Invia e-mail massivo', 'sd-logbook' ); ?></button>
						<button type="button" class="sd-reg-filter" id="sd-reg-email-all-paid"><?php esc_html_e( 'Invia e-mail a tutte le iscrizioni pagate', 'sd-logbook' ); ?></button>
					</div>

					<!-- Riga 4: esportazione e PDF -->
					<div class="sd-renewals-bulk-group sd-bulk-export">
						<span class="sd-action-group-icon" title="<?php esc_attr_e( 'Esportazione e PDF', 'sd-logbook' ); ?>">📤</span>
						<button type="button" class="sd-btn sd-btn-success sd-btn-sm" id="sd-reg-export-excel"><?php esc_html_e( 'Esporta Excel', 'sd-logbook' ); ?></button>
						<button type="button" class="sd-btn sd-btn-pdf sd-btn-sm" id="sd-reg-pdf-activity" title="<?php esc_attr_e( 'Scheda PDF attività selezionata', 'sd-logbook' ); ?>">📄 <?php esc_html_e( 'PDF Attività', 'sd-logbook' ); ?></button>
						<button type="button" class="sd-btn sd-btn-pdf sd-btn-sm" id="sd-reg-pdf-list" title="<?php esc_attr_e( 'PDF lista registrazioni (filtro corrente)', 'sd-logbook' ); ?>">📋 <?php esc_html_e( 'PDF Lista', 'sd-logbook' ); ?></button>
						<span class="sd-group-sep"></span>
						<select id="sd-reg-tpl-select" class="sd-select sd-tpl-select-inline" multiple size="3" style="height:auto;vertical-align:middle;min-width:160px;" title="<?php esc_attr_e( 'Ctrl+clic per selezionare più template PDF da allegare all\'e-mail', 'sd-logbook' ); ?>">
						</select>
						<button type="button" class="sd-btn sd-btn-info sd-btn-sm" id="sd-reg-tpl-pdf-all" title="<?php esc_attr_e( 'PDF tutte le registrazioni con il template selezionato', 'sd-logbook' ); ?>">📑 <?php esc_html_e( 'PDF Template (tutti)', 'sd-logbook' ); ?></button>
						<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm" id="sd-reg-export-vcf" title="<?php esc_attr_e( 'Esporta mailing list vCard (.vcf) compatibile con Outlook, eM Client, Apple Mail, Thunderbird', 'sd-logbook' ); ?>">📇 <?php esc_html_e( 'Mailing list', 'sd-logbook' ); ?></button>
					</div>
				</div>
				<div class="sd-renewals-table-wrap">
					<table class="sd-renewals-table" id="sd-reg-dashboard-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Iscritto', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Email', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Stato', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Stato Pagamento', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Data iscrizione', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Importo', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Ultima e-mail', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Azioni', 'sd-logbook' ); ?></th>
							</tr>
						</thead>
						<tbody id="sd-reg-dashboard-tbody">
							<tr>
								<td colspan="8" class="sd-table-empty"><?php esc_html_e( 'Seleziona un\'attività per vedere il cruscotto.', 'sd-logbook' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

		</div>
	</section>

	<!-- TAB PAGAMENTI -->
	<section class="sd-admin-panel" data-panel="pagamenti">
		<div class="sd-form-section">
			<div class="sd-admin-stats" id="sd-payment-stats">
				<div class="sd-stat-card"><span class="sd-stat-label"><?php esc_html_e( 'Totale Registrazioni', 'sd-logbook' ); ?></span><span class="sd-stat-value" id="sd-pay-total">0</span></div>
				<div class="sd-stat-card"><span class="sd-stat-label"><?php esc_html_e( 'Pagati', 'sd-logbook' ); ?></span><span class="sd-stat-value" id="sd-pay-paid">0</span></div>
				<div class="sd-stat-card"><span class="sd-stat-label"><?php esc_html_e( 'In Attesa', 'sd-logbook' ); ?></span><span class="sd-stat-value" id="sd-pay-pending">0</span></div>
			</div>
			<p class="sd-field-note"><?php esc_html_e( 'Le azioni di aggiornamento pagamento sono disponibili nella tab Registrazioni.', 'sd-logbook' ); ?></p>
		</div>
	</section>
</div>

<!-- Modal anteprima e-mail registrazione -->
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

<?php
// Initialize WYSIWYG editor for content fields
wp_enqueue_editor();
?>

<script>
window.sdActivityAdmin = {
	ajaxUrl: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
	nonce: '<?php echo esc_attr( wp_create_nonce( 'sd_nonce' ) ); ?>',
	currentChfEurRate: <?php echo esc_js( (string) ( class_exists( 'SD_Currency_Converter' ) ? floatval( SD_Currency_Converter::get_instance()->get_rate() ) : 0 ) ); ?>,
	tinymceAdvancedMceUrl: '<?php echo esc_url( plugins_url( 'mce/', WP_PLUGIN_DIR . '/tinymce-advanced/tinymce-advanced.php' ) ); ?>',
	strings: {
		confirmDelete: '<?php esc_attr_e( 'Eliminare questa attivita?', 'sd-logbook' ); ?>',
		saveFirst: '<?php esc_attr_e( 'Salva prima l\'attivita.', 'sd-logbook' ); ?>',
		error: '<?php esc_attr_e( 'Si e verificato un errore.', 'sd-logbook' ); ?>',
		loading: '<?php esc_attr_e( 'Caricamento...', 'sd-logbook' ); ?>',
		regDashboardLoadError: '<?php esc_attr_e( 'Errore nel caricamento del cruscotto registrazioni.', 'sd-logbook' ); ?>',
		regSelectActivity: '<?php esc_attr_e( 'Seleziona un\'attività per vedere il cruscotto.', 'sd-logbook' ); ?>',
		regEmailSent: '<?php esc_attr_e( 'E-mail inviata con successo.', 'sd-logbook' ); ?>',
		regEmailError: '<?php esc_attr_e( 'Invio e-mail non riuscito.', 'sd-logbook' ); ?>',
		previewLabel: '<?php esc_attr_e( 'Anteprima e-mail', 'sd-logbook' ); ?>',
		regSendEmailLabel: '<?php esc_attr_e( 'Invia e-mail', 'sd-logbook' ); ?>',
		regSendingLabel: '<?php esc_attr_e( 'Invio...', 'sd-logbook' ); ?>',
		regBulkSendingLabel: '<?php esc_attr_e( 'Invio massivo...', 'sd-logbook' ); ?>',
		regBulkDone: '<?php esc_attr_e( 'Invio massivo completato.', 'sd-logbook' ); ?>',
		regPaymentConfirmationLabel: '<?php esc_attr_e( 'Invia conferma pagamento', 'sd-logbook' ); ?>',
		regPaymentConfirmationSending: '<?php esc_attr_e( 'Invio conferma...', 'sd-logbook' ); ?>',
		regPaymentConfirmationSent: '<?php esc_attr_e( 'E-mail di conferma pagamento inviata con successo.', 'sd-logbook' ); ?>',
		regPaymentConfirmationError: '<?php esc_attr_e( 'Invio conferma pagamento non riuscito.', 'sd-logbook' ); ?>',
		regAllPaidLabel: '<?php esc_attr_e( 'Invia e-mail a tutte le iscrizioni pagate', 'sd-logbook' ); ?>',
		regAllPaidSendingLabel: '<?php esc_attr_e( 'Invio a tutti i pagati...', 'sd-logbook' ); ?>',
		regAllPaidConfirm: '<?php esc_attr_e( 'Inviare l\'e-mail a tutte le iscrizioni pagate dell\'attività selezionata?', 'sd-logbook' ); ?>',
		noTemplateSelected: '<?php esc_attr_e( 'Seleziona un modello e-mail prima di inviare.', 'sd-logbook' ); ?>'
	}
};

// Fallback anti-cache: traduce i valori status/payment_status in italiano
// anche se activity-admin.js viene servito da cache.
(function () {
	var map = {
		'draft': 'Bozza',
		'published': 'Pubblicata',
		'closed': 'Conclusa',
		'archived': 'Archiviata',
		'registered': 'Registrato',
		'waitlist': 'Lista d\'attesa',
		'cancelled': 'Annullato',
		'pending': 'In attesa',
		'paid': 'Pagato',
		'free': 'Gratuito',
		'invoice_requested': 'Fattura richiesta',
		'invoice_sent': 'Fattura inviata',
		'invoice_error': 'Errore invio fattura'
	};

	function normalizeKey(text) {
		return String(text || '').trim().toLowerCase();
	}

	function translateBadgesInTbody(tbodyId) {
		var tbody = document.getElementById(tbodyId);
		if (!tbody) {
			return;
		}

		var badges = tbody.querySelectorAll('.sd-status-badge');
		badges.forEach(function (badge) {
			var current = normalizeKey(badge.textContent);
			if (map[current]) {
				badge.textContent = map[current];
			}
		});
	}

	function observeTbody(tbodyId) {
		var tbody = document.getElementById(tbodyId);
		if (!tbody || typeof MutationObserver === 'undefined') {
			return;
		}

		var observer = new MutationObserver(function () {
			translateBadgesInTbody(tbodyId);
		});

		observer.observe(tbody, { childList: true, subtree: true });
	}

	document.addEventListener('DOMContentLoaded', function () {
		translateBadgesInTbody('sd-activities-tbody');
		translateBadgesInTbody('sd-reg-dashboard-tbody');
		observeTbody('sd-activities-tbody');
		observeTbody('sd-reg-dashboard-tbody');
	});
})();
</script>
