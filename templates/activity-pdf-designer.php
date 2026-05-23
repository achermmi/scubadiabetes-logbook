<?php
/**
 * Template: Designer PDF drag-and-drop
 * Shortcode: [sd_pdf_template_designer]
 *
 * @package ScubaDiabetes_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="sd-pdf-designer-wrap" class="sd-pdf-designer-wrap">

	<!-- ===== TOOLBAR ===== -->
	<div class="sd-pdf-toolbar">
		<div class="sd-pdf-toolbar-left">
			<input type="text" id="sd-tpl-name" placeholder="<?php esc_attr_e( 'Nome template…', 'sd-logbook' ); ?>" class="sd-pdf-tpl-name-input">
			<select id="sd-tpl-orientation" class="sd-pdf-select">
				<option value="portrait"><?php esc_html_e( 'Verticale (A4)', 'sd-logbook' ); ?></option>
				<option value="landscape"><?php esc_html_e( 'Orizzontale (A4)', 'sd-logbook' ); ?></option>
			</select>
		</div>
		<div class="sd-pdf-toolbar-center">
			<button id="sd-tpl-btn-new" class="sd-pdf-btn sd-pdf-btn-secondary"><?php esc_html_e( '+ Nuovo', 'sd-logbook' ); ?></button>
			<button id="sd-tpl-btn-load" class="sd-pdf-btn sd-pdf-btn-secondary"><?php esc_html_e( '📂 Carica', 'sd-logbook' ); ?></button>
			<button id="sd-tpl-btn-save" class="sd-pdf-btn sd-pdf-btn-primary"><?php esc_html_e( '💾 Salva', 'sd-logbook' ); ?></button>
			<button id="sd-tpl-btn-delete" class="sd-pdf-btn sd-pdf-btn-danger"><?php esc_html_e( '🗑 Elimina', 'sd-logbook' ); ?></button>
		</div>
		<div class="sd-pdf-toolbar-right">
			<button id="sd-tpl-btn-preview" class="sd-pdf-btn sd-pdf-btn-info"><?php esc_html_e( '👁 Anteprima PDF', 'sd-logbook' ); ?></button>
			<button id="sd-tpl-btn-gen-all" class="sd-pdf-btn sd-pdf-btn-success"><?php esc_html_e( '📄 Genera tutti', 'sd-logbook' ); ?></button>
		</div>
	</div>

	<!-- ===== STATO ===== -->
	<div id="sd-pdf-status" class="sd-pdf-status" style="display:none;"></div>

	<!-- ===== BODY ===== -->
	<div class="sd-pdf-body">

		<!-- PANNELLO SINISTRO: campi disponibili -->
		<div class="sd-pdf-sidebar">
			<div class="sd-pdf-sidebar-section">
				<h4><?php esc_html_e( 'Attività', 'sd-logbook' ); ?></h4>
				<select id="sd-activity-select" class="sd-pdf-select sd-pdf-full-width">
					<option value=""><?php esc_html_e( '— Nessuna attività —', 'sd-logbook' ); ?></option>
					<?php
					global $wpdb;
					$activities = $wpdb->get_results(
						'SELECT id, title FROM ' . $wpdb->prefix . 'sd_activities ORDER BY start_date DESC LIMIT 100',
						ARRAY_A
					);
					foreach ( $activities as $act ) {
						echo '<option value="' . intval( $act['id'] ) . '">' . esc_html( $act['title'] ) . '</option>';
					}
					?>
				</select>
			</div>

			<div class="sd-pdf-sidebar-section">
				<h4><?php esc_html_e( 'Campi Attività', 'sd-logbook' ); ?></h4>
				<div id="sd-fields-activity" class="sd-field-list">
					<?php foreach ( SD_PDF_Template_Designer::ACTIVITY_FIELDS as $key => $label ) : ?>
					<div class="sd-field-chip" data-type="<?php echo esc_attr( $key ); ?>" data-label="<?php echo esc_attr( $label ); ?>" draggable="true">
						<span class="sd-chip-icon">📋</span> <?php echo esc_html( $label ); ?>
					</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="sd-pdf-sidebar-section">
				<h4><?php esc_html_e( 'Campi Registrazione', 'sd-logbook' ); ?></h4>
				<div id="sd-fields-reg" class="sd-field-list">
					<?php foreach ( SD_PDF_Template_Designer::FIXED_FIELDS as $key => $label ) : ?>
					<div class="sd-field-chip" data-type="<?php echo esc_attr( $key ); ?>" data-label="<?php echo esc_attr( $label ); ?>" draggable="true">
						<span class="sd-chip-icon">👤</span> <?php echo esc_html( $label ); ?>
					</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="sd-pdf-sidebar-section" id="sd-dyn-fields-section" style="display:none;">
				<h4><?php esc_html_e( 'Campi Modulo', 'sd-logbook' ); ?></h4>
				<div id="sd-fields-dynamic" class="sd-field-list"></div>
			</div>

			<div class="sd-pdf-sidebar-section">
				<h4><?php esc_html_e( 'Testo Libero', 'sd-logbook' ); ?></h4>
				<div class="sd-field-chip" data-type="text_label" data-label="Testo" draggable="true">
					<span class="sd-chip-icon">✏️</span> <?php esc_html_e( 'Testo fisso', 'sd-logbook' ); ?>
				</div>
			</div>
		</div>

		<!-- CANVAS A4 -->
		<div class="sd-pdf-canvas-wrap">
			<div id="sd-pdf-canvas" class="sd-pdf-canvas">
				<!-- gli elementi vengono inseriti via JS -->
			</div>
		</div>

		<!-- PANNELLO DESTRO: proprietà elemento selezionato -->
		<div class="sd-pdf-props" id="sd-pdf-props">
			<h4><?php esc_html_e( 'Proprietà', 'sd-logbook' ); ?></h4>
			<div id="sd-props-empty" class="sd-props-empty">
				<?php esc_html_e( 'Seleziona un elemento sul canvas per modificarne le proprietà.', 'sd-logbook' ); ?>
			</div>
			<div id="sd-props-form" style="display:none;">
				<label><?php esc_html_e( 'Etichetta', 'sd-logbook' ); ?>
					<input type="text" id="sd-prop-label" class="sd-pdf-input">
				</label>
				<label class="sd-prop-checkbox">
					<input type="checkbox" id="sd-prop-label-show">
					<?php esc_html_e( 'Mostra etichetta', 'sd-logbook' ); ?>
				</label>
				<div id="sd-prop-label-pos-wrap" style="display:none;">
					<label><?php esc_html_e( 'Posizione etichetta', 'sd-logbook' ); ?>
						<select id="sd-prop-label-pos" class="sd-pdf-input">
							<option value="above"><?php esc_html_e( 'Sopra', 'sd-logbook' ); ?></option>
							<option value="below"><?php esc_html_e( 'Sotto', 'sd-logbook' ); ?></option>
							<option value="before"><?php esc_html_e( 'Prima (inline)', 'sd-logbook' ); ?></option>
							<option value="after"><?php esc_html_e( 'Dopo (inline)', 'sd-logbook' ); ?></option>
						</select>
					</label>
				</div>
				<label><?php esc_html_e( 'Testo (solo tipo Testo fisso)', 'sd-logbook' ); ?>
					<input type="text" id="sd-prop-custom-text" class="sd-pdf-input">
				</label>
				<label><?php esc_html_e( 'Prefisso', 'sd-logbook' ); ?>
					<input type="text" id="sd-prop-prefix" class="sd-pdf-input sd-input-short">
				</label>
				<label><?php esc_html_e( 'Suffisso', 'sd-logbook' ); ?>
					<input type="text" id="sd-prop-suffix" class="sd-pdf-input sd-input-short">
				</label>
				<label><?php esc_html_e( 'Larghezza (mm)', 'sd-logbook' ); ?>
					<input type="number" id="sd-prop-width" class="sd-pdf-input" min="5" max="280" step="1">
				</label>
				<label><?php esc_html_e( 'Dimensione font (pt)', 'sd-logbook' ); ?>
					<input type="number" id="sd-prop-fontsize" class="sd-pdf-input" min="6" max="72" step="1">
				</label>
				<label class="sd-prop-checkbox">
					<input type="checkbox" id="sd-prop-bold">
					<?php esc_html_e( 'Grassetto', 'sd-logbook' ); ?>
				</label>
				<label class="sd-prop-checkbox">
					<input type="checkbox" id="sd-prop-italic">
					<?php esc_html_e( 'Corsivo', 'sd-logbook' ); ?>
				</label>
				<label><?php esc_html_e( 'Colore testo', 'sd-logbook' ); ?>
					<input type="color" id="sd-prop-color" class="sd-pdf-input sd-input-color" value="#000000">
				</label>
				<label><?php esc_html_e( 'X (mm)', 'sd-logbook' ); ?>
					<input type="number" id="sd-prop-x" class="sd-pdf-input" min="0" max="280" step="0.5">
				</label>
				<label><?php esc_html_e( 'Y (mm)', 'sd-logbook' ); ?>
					<input type="number" id="sd-prop-y" class="sd-pdf-input" min="0" max="380" step="0.5">
				</label>
				<button id="sd-prop-delete" class="sd-pdf-btn sd-pdf-btn-danger sd-pdf-full-width"><?php esc_html_e( '🗑 Rimuovi elemento', 'sd-logbook' ); ?></button>
			</div>

			<!-- Multi-selezione -->
			<div id="sd-props-multi" style="display:none;">
				<div id="sd-multi-count" style="font-size:12px;font-weight:700;color:#2980b9;margin-bottom:6px;"></div>
				<p style="font-size:10px;color:#95a5a6;margin:0 0 10px 0;"><?php esc_html_e( 'Ctrl+clic per aggiungere/rimuovere. Trascina l\'area vuota per selezionare.', 'sd-logbook' ); ?></p>
				<h4><?php esc_html_e( 'Allineamento', 'sd-logbook' ); ?></h4>
				<div class="sd-multi-align-grid">
					<button class="sd-pdf-btn sd-pdf-btn-secondary sd-align-btn" data-align="left">&#8592; <?php esc_html_e( 'Sin.', 'sd-logbook' ); ?></button>
					<button class="sd-pdf-btn sd-pdf-btn-secondary sd-align-btn" data-align="centerH">&#8596; <?php esc_html_e( 'C.H', 'sd-logbook' ); ?></button>
					<button class="sd-pdf-btn sd-pdf-btn-secondary sd-align-btn" data-align="right">&#8594; <?php esc_html_e( 'Des.', 'sd-logbook' ); ?></button>
					<button class="sd-pdf-btn sd-pdf-btn-secondary sd-align-btn" data-align="top">&#8593; <?php esc_html_e( 'Alto', 'sd-logbook' ); ?></button>
					<button class="sd-pdf-btn sd-pdf-btn-secondary sd-align-btn" data-align="centerV">&#8597; <?php esc_html_e( 'C.V', 'sd-logbook' ); ?></button>
					<button class="sd-pdf-btn sd-pdf-btn-secondary sd-align-btn" data-align="bottom">&#8595; <?php esc_html_e( 'Basso', 'sd-logbook' ); ?></button>
				</div>
				<h4><?php esc_html_e( 'Stessa dimensione', 'sd-logbook' ); ?></h4>
				<label><?php esc_html_e( 'Larghezza (mm)', 'sd-logbook' ); ?>
					<div class="sd-multi-input-row">
						<input type="number" id="sd-multi-width" class="sd-pdf-input" min="5" max="280" step="1">
						<button id="sd-multi-apply-width" class="sd-pdf-btn sd-pdf-btn-primary">&#10003;</button>
					</div>
				</label>
				<label><?php esc_html_e( 'Font (pt)', 'sd-logbook' ); ?>
					<div class="sd-multi-input-row">
						<input type="number" id="sd-multi-fontsize" class="sd-pdf-input" min="6" max="72" step="1">
						<button id="sd-multi-apply-fontsize" class="sd-pdf-btn sd-pdf-btn-primary">&#10003;</button>
					</div>
				</label>
				<button id="sd-multi-deselect" class="sd-pdf-btn sd-pdf-btn-secondary sd-pdf-full-width"><?php esc_html_e( 'Deseleziona tutti', 'sd-logbook' ); ?></button>
				<button id="sd-multi-delete" class="sd-pdf-btn sd-pdf-btn-danger sd-pdf-full-width"><?php esc_html_e( '🗑 Elimina selezionati', 'sd-logbook' ); ?></button>
			</div>
		</div>

	</div><!-- /.sd-pdf-body -->

	<!-- MODAL: lista template salvati -->
	<div id="sd-tpl-modal" class="sd-pdf-modal" style="display:none;">
		<div class="sd-pdf-modal-inner">
			<div class="sd-pdf-modal-header">
				<h3><?php esc_html_e( 'Carica Template', 'sd-logbook' ); ?></h3>
				<button class="sd-pdf-modal-close" id="sd-tpl-modal-close">&times;</button>
			</div>
			<div class="sd-pdf-modal-body">
				<table class="sd-pdf-tpl-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nome', 'sd-logbook' ); ?></th>
							<th><?php esc_html_e( 'Orientamento', 'sd-logbook' ); ?></th>
							<th><?php esc_html_e( 'Modificato', 'sd-logbook' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="sd-tpl-modal-rows"></tbody>
				</table>
			</div>
		</div>
	</div>

</div><!-- /#sd-pdf-designer-wrap -->
