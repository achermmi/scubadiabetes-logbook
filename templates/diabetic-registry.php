<?php
/**
 * Template: Registro Soci Diabetici
 * Shortcode: [sd_diabetic_registry]
 * @package SD_Logbook
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$years = SD_Diabetic_Registry::get_years();
?>
<div class="sd-form-wrap sd-dreg-wrap" id="sd-dreg-root">

    <!-- ======================================================
         FILTRI
         ====================================================== -->
    <div class="sd-dreg-filters">
        <h2 class="sd-dreg-page-title">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C12 2 5 10 5 14a7 7 0 0 0 14 0c0-4-7-12-7-12z"/><line x1="12" y1="18" x2="12" y2="14"/><line x1="10" y1="16" x2="14" y2="16"/></svg>
            <?php esc_html_e( 'Registro Soci Diabetici', 'sd-logbook' ); ?>
        </h2>

        <div class="sd-dreg-filter-grid">
            <!-- Ricerca testuale -->
            <div class="sd-dreg-filter-item sd-dreg-filter-wide">
                <label for="dreg-search"><?php esc_html_e( 'Cerca per nome / email', 'sd-logbook' ); ?></label>
                <div class="sd-dreg-search-wrap">
                    <svg class="sd-dreg-search-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    <input type="text" id="dreg-search" class="sd-dreg-input" placeholder="<?php esc_attr_e( 'Nome, cognome o email…', 'sd-logbook' ); ?>">
                </div>
            </div>

            <!-- Tipo diabete -->
            <div class="sd-dreg-filter-item">
                <label for="dreg-diabetes-type"><?php esc_html_e( 'Tipo diabete', 'sd-logbook' ); ?></label>
                <select id="dreg-diabetes-type" class="sd-dreg-select">
                    <option value=""><?php esc_html_e( 'Tutti', 'sd-logbook' ); ?></option>
                    <option value="tipo_1">Tipo 1</option>
                    <option value="tipo_2">Tipo 2</option>
                    <option value="tipo_3c">Tipo 3c</option>
                    <option value="lada">LADA</option>
                    <option value="mody">MODY</option>
                    <option value="midd">MIDD</option>
                    <option value="non_specificato"><?php esc_html_e( 'Non specificato', 'sd-logbook' ); ?></option>
                    <option value="altro"><?php esc_html_e( 'Altro', 'sd-logbook' ); ?></option>
                </select>
            </div>

            <!-- Terapia -->
            <div class="sd-dreg-filter-item">
                <label for="dreg-therapy"><?php esc_html_e( 'Terapia', 'sd-logbook' ); ?></label>
                <select id="dreg-therapy" class="sd-dreg-select">
                    <option value=""><?php esc_html_e( 'Tutti', 'sd-logbook' ); ?></option>
                    <option value="mdi">MDI</option>
                    <option value="csii">CSII</option>
                    <option value="ahcl">AHCL</option>
                    <option value="ipoglicemizzante_orale"><?php esc_html_e( 'Orale', 'sd-logbook' ); ?></option>
                    <option value="iniettiva_non_insulinica"><?php esc_html_e( 'Iniettiva non insulinica', 'sd-logbook' ); ?></option>
                    <option value="none"><?php esc_html_e( 'Non specificata', 'sd-logbook' ); ?></option>
                </select>
            </div>

            <!-- CGM -->
            <div class="sd-dreg-filter-item">
                <label for="dreg-cgm"><?php esc_html_e( 'CGM', 'sd-logbook' ); ?></label>
                <select id="dreg-cgm" class="sd-dreg-select">
                    <option value=""><?php esc_html_e( 'Tutti', 'sd-logbook' ); ?></option>
                    <option value="1"><?php esc_html_e( 'Con CGM', 'sd-logbook' ); ?></option>
                    <option value="0"><?php esc_html_e( 'Senza CGM', 'sd-logbook' ); ?></option>
                </select>
            </div>

            <!-- Tipo socio -->
            <div class="sd-dreg-filter-item">
                <label for="dreg-member-type"><?php esc_html_e( 'Tipo socio', 'sd-logbook' ); ?></label>
                <select id="dreg-member-type" class="sd-dreg-select">
                    <option value=""><?php esc_html_e( 'Tutti', 'sd-logbook' ); ?></option>
                    <option value="attivo"><?php esc_html_e( 'Attivo', 'sd-logbook' ); ?></option>
                    <option value="attivo_capo_famiglia"><?php esc_html_e( 'Capofamiglia', 'sd-logbook' ); ?></option>
                    <option value="attivo_famigliare"><?php esc_html_e( 'Famigliare', 'sd-logbook' ); ?></option>
                    <option value="onorario"><?php esc_html_e( 'Onorario', 'sd-logbook' ); ?></option>
                </select>
            </div>

            <!-- Anno iscrizione -->
            <div class="sd-dreg-filter-item">
                <label for="dreg-year"><?php esc_html_e( 'Anno iscrizione', 'sd-logbook' ); ?></label>
                <select id="dreg-year" class="sd-dreg-select">
                    <option value=""><?php esc_html_e( 'Tutti', 'sd-logbook' ); ?></option>
                    <?php foreach ( $years as $y ) : ?>
                    <option value="<?php echo esc_attr( $y ); ?>"><?php echo esc_html( $y ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Pulsanti azione -->
            <div class="sd-dreg-filter-item sd-dreg-filter-actions">
                <button type="button" id="sd-dreg-btn-search" class="sd-dreg-btn-primary">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    <?php esc_html_e( 'Filtra', 'sd-logbook' ); ?>
                </button>
                <button type="button" id="sd-dreg-btn-reset" class="sd-dreg-btn-secondary">
                    <?php esc_html_e( 'Reset', 'sd-logbook' ); ?>
                </button>
                <button type="button" id="sd-dreg-btn-export" class="sd-dreg-btn-export">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    CSV
                </button>
            </div>
        </div>
    </div>

    <!-- ======================================================
         STATS STRIP
         ====================================================== -->
    <div class="sd-dreg-stats" id="sd-dreg-stats" style="display:none;">
        <span id="sd-dreg-count-label"><!-- JS --></span>
    </div>

    <!-- ======================================================
         TABELLA RISULTATI
         ====================================================== -->
    <div class="sd-dreg-table-wrap">
        <div id="sd-dreg-loading" class="sd-dreg-loading" style="display:none;">
            <div class="sd-dreg-spinner"></div>
            <?php esc_html_e( 'Caricamento…', 'sd-logbook' ); ?>
        </div>

        <div id="sd-dreg-empty" class="sd-dreg-empty" style="display:none;">
            <?php esc_html_e( 'Nessun socio diabetico trovato con i filtri selezionati.', 'sd-logbook' ); ?>
        </div>

        <table class="sd-dreg-table" id="sd-dreg-table" style="display:none;">
            <thead>
                <tr>
                    <th class="sd-dreg-col-expand"></th>
                    <th class="sd-dreg-col-name"><?php esc_html_e( 'Nome', 'sd-logbook' ); ?></th>
                    <th class="sd-dreg-col-diab"><?php esc_html_e( 'Tipo diabete', 'sd-logbook' ); ?></th>
                    <th class="sd-dreg-col-therapy"><?php esc_html_e( 'Terapia', 'sd-logbook' ); ?></th>
                    <th class="sd-dreg-col-center"><?php esc_html_e( 'Centro diabetologico', 'sd-logbook' ); ?></th>
                    <th class="sd-dreg-col-hba1c">HbA1c</th>
                    <th class="sd-dreg-col-cgm">CGM</th>
                    <th class="sd-dreg-col-type"><?php esc_html_e( 'Tipo socio', 'sd-logbook' ); ?></th>
                    <th class="sd-dreg-col-year"><?php esc_html_e( 'Anno', 'sd-logbook' ); ?></th>
                </tr>
            </thead>
            <tbody id="sd-dreg-tbody">
                <!-- righe inserite da JS -->
            </tbody>
        </table>
    </div>

</div><!-- .sd-dreg-wrap -->
