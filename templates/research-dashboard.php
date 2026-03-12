<?php
/**
 * Template: Research Dashboard
 * @package SD_Logbook
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="sd-form-wrap sd-research-wrap">

    <!-- Header -->
    <div class="sd-research-header">
        <div class="sd-research-header-left">
            <h2>
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 21l-6-6m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0z"/></svg>
                <?php esc_html_e( 'Research Dashboard', 'sd-logbook' ); ?>
            </h2>
            <span class="sd-research-subtitle"><?php esc_html_e( 'Analisi dati glicemici per la ricerca scientifica', 'sd-logbook' ); ?></span>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- FILTRI -->
    <!-- ============================================================ -->
    <div class="sd-filters-panel">
        <div class="sd-filters-title"><?php esc_html_e( 'Filtri', 'sd-logbook' ); ?></div>
        <div class="sd-filters-grid">
            <div class="sd-filter-group sd-filter-year-group">
                <label><?php esc_html_e( 'Anno', 'sd-logbook' ); ?></label>
                <div class="sd-year-chips" id="sd-f-years">
                    <?php
                    global $wpdb;
                    $db_years = new SD_Database();
                    $current_year = (int) date( 'Y' );
                    // Anni con immersioni nel DB
                    $db_year_list = $wpdb->get_col( "SELECT DISTINCT YEAR(dive_date) FROM {$db_years->table('dives')} ORDER BY YEAR(dive_date) DESC" );
                    // Aggiungi anno corrente se non presente
                    if ( ! in_array( $current_year, $db_year_list ) ) {
                        array_unshift( $db_year_list, $current_year );
                    }
                    foreach ( $db_year_list as $y ) :
                    ?>
                    <label class="sd-year-chip">
                        <input type="checkbox" name="sd_year" value="<?php echo (int) $y; ?>" <?php echo (int) $y === $current_year ? 'checked' : ''; ?>>
                        <span><?php echo (int) $y; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="sd-filter-group">
                <label><?php esc_html_e( 'Da', 'sd-logbook' ); ?></label>
                <input type="date" id="sd-f-date-from">
            </div>
            <div class="sd-filter-group">
                <label><?php esc_html_e( 'A', 'sd-logbook' ); ?></label>
                <input type="date" id="sd-f-date-to">
            </div>
            <div class="sd-filter-group">
                <label><?php esc_html_e( 'Subacqueo', 'sd-logbook' ); ?></label>
                <select id="sd-f-diver">
                    <option value="all"><?php esc_html_e( 'Tutti', 'sd-logbook' ); ?></option>
                </select>
            </div>
            <div class="sd-filter-group">
                <label><?php esc_html_e( 'Decisione', 'sd-logbook' ); ?></label>
                <select id="sd-f-decision">
                    <option value="all"><?php esc_html_e( 'Tutte', 'sd-logbook' ); ?></option>
                    <option value="autorizzata"><?php esc_html_e( 'Autorizzata', 'sd-logbook' ); ?></option>
                    <option value="sospesa"><?php esc_html_e( 'Sospesa', 'sd-logbook' ); ?></option>
                    <option value="annullata"><?php esc_html_e( 'Annullata', 'sd-logbook' ); ?></option>
                </select>
            </div>
            <div class="sd-filter-group">
                <label><?php esc_html_e( 'Glicemia min', 'sd-logbook' ); ?></label>
                <input type="number" id="sd-f-glic-min" placeholder="mg/dL" min="0" max="600">
            </div>
            <div class="sd-filter-group">
                <label><?php esc_html_e( 'Glicemia max', 'sd-logbook' ); ?></label>
                <input type="number" id="sd-f-glic-max" placeholder="mg/dL" min="0" max="600">
            </div>
        </div>
        <div class="sd-filters-actions">
            <button type="button" class="sd-btn-search" id="sd-btn-search">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <?php esc_html_e( 'Cerca', 'sd-logbook' ); ?>
            </button>
            <button type="button" class="sd-btn-reset" id="sd-btn-reset"><?php esc_html_e( 'Reset', 'sd-logbook' ); ?></button>
            <button type="button" class="sd-btn-export-research" id="sd-btn-export">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?php esc_html_e( 'Export CSV', 'sd-logbook' ); ?>
            </button>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- STATS CARDS -->
    <!-- ============================================================ -->
    <div class="sd-research-stats" id="sd-research-stats" style="display:none;">
        <div class="sd-rstat"><span class="sd-rstat-val" id="rs-total">0</span><span class="sd-rstat-lbl"><?php esc_html_e( 'Immersioni', 'sd-logbook' ); ?></span></div>
        <div class="sd-rstat"><span class="sd-rstat-val sd-rstat-green" id="rs-auth">0</span><span class="sd-rstat-lbl"><?php esc_html_e( 'Autorizzate', 'sd-logbook' ); ?></span></div>
        <div class="sd-rstat"><span class="sd-rstat-val sd-rstat-yellow" id="rs-susp">0</span><span class="sd-rstat-lbl"><?php esc_html_e( 'Sospese', 'sd-logbook' ); ?></span></div>
        <div class="sd-rstat"><span class="sd-rstat-val sd-rstat-red" id="rs-canc">0</span><span class="sd-rstat-lbl"><?php esc_html_e( 'Annullate', 'sd-logbook' ); ?></span></div>
        <div class="sd-rstat"><span class="sd-rstat-val sd-rstat-red" id="rs-hypo">0</span><span class="sd-rstat-lbl"><?php esc_html_e( 'Ipoglicemie', 'sd-logbook' ); ?></span></div>
    </div>

    <!-- ============================================================ -->
    <!-- GRAFICI -->
    <!-- ============================================================ -->
    <div class="sd-charts-grid" id="sd-charts-grid" style="display:none;">
        <div class="sd-chart-box">
            <div class="sd-chart-title"><?php esc_html_e( 'Glicemia media per checkpoint', 'sd-logbook' ); ?></div>
            <canvas id="chart-checkpoint" height="220"></canvas>
        </div>
        <div class="sd-chart-box">
            <div class="sd-chart-title"><?php esc_html_e( 'Distribuzione glicemie', 'sd-logbook' ); ?></div>
            <canvas id="chart-distribution" height="220"></canvas>
        </div>
        <div class="sd-chart-box">
            <div class="sd-chart-title"><?php esc_html_e( 'Decisioni protocollo', 'sd-logbook' ); ?></div>
            <canvas id="chart-decisions" height="220"></canvas>
        </div>
        <div class="sd-chart-box">
            <div class="sd-chart-title"><?php esc_html_e( 'Andamento glicemico nel tempo', 'sd-logbook' ); ?></div>
            <canvas id="chart-timeline" height="220"></canvas>
        </div>
        <div class="sd-chart-box sd-chart-wide">
            <div class="sd-chart-title"><?php esc_html_e( 'Confronto annuale — media glicemia per checkpoint', 'sd-logbook' ); ?></div>
            <canvas id="chart-year-compare" height="220"></canvas>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TABELLA DATI -->
    <!-- ============================================================ -->
    <div class="sd-data-section" id="sd-data-section" style="display:none;">
        <div class="sd-data-title">
            <?php esc_html_e( 'Dati immersioni', 'sd-logbook' ); ?>
            <span class="sd-data-count" id="sd-data-count"></span>
        </div>
        <div class="sd-table-wrapper">
            <table class="sd-research-table" id="sd-research-table">
                <thead>
                    <tr class="sd-thead-group">
                        <th colspan="4" class="sd-th-sub"><?php esc_html_e( 'DATI SUBACQUEI', 'sd-logbook' ); ?></th>
                        <th colspan="5" class="sd-th-60">-60</th>
                        <th colspan="5" class="sd-th-30">-30</th>
                        <th colspan="5" class="sd-th-10">-10</th>
                        <th colspan="5" class="sd-th-post">POST</th>
                        <th class="sd-th-dec"><?php esc_html_e( 'DEC.', 'sd-logbook' ); ?></th>
                    </tr>
                    <tr class="sd-thead-cols">
                        <th><?php esc_html_e( 'Sub', 'sd-logbook' ); ?></th>
                        <th><?php esc_html_e( 'Data', 'sd-logbook' ); ?></th>
                        <th><?php esc_html_e( 'Sito', 'sd-logbook' ); ?></th>
                        <th><?php esc_html_e( 'Prof/Tempo', 'sd-logbook' ); ?></th>
                        <th>Glic</th><th>C/S</th><th>CHO</th><th>INS</th><th><?php esc_html_e( 'Provv.', 'sd-logbook' ); ?></th>
                        <th>Glic</th><th>C/S</th><th>CHO</th><th>INS</th><th><?php esc_html_e( 'Provv.', 'sd-logbook' ); ?></th>
                        <th>Glic</th><th>C/S</th><th>CHO</th><th>INS</th><th><?php esc_html_e( 'Provv.', 'sd-logbook' ); ?></th>
                        <th>Glic</th><th>C/S</th><th>CHO</th><th>INS</th><th><?php esc_html_e( 'Provv.', 'sd-logbook' ); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="sd-table-body"></tbody>
            </table>
        </div>
    </div>

    <!-- Empty state -->
    <div class="sd-research-empty" id="sd-research-empty">
        <svg viewBox="0 0 80 80" width="48" height="48" fill="none" stroke="#CBD5E1" stroke-width="1.5"><circle cx="40" cy="40" r="30"/><path d="M28 36c0-7 5-12 12-12s12 5 12 12"/><circle cx="32" cy="33" r="2" fill="#CBD5E1"/><circle cx="48" cy="33" r="2" fill="#CBD5E1"/></svg>
        <p><?php esc_html_e( 'Imposta i filtri e premi "Cerca" per visualizzare i dati.', 'sd-logbook' ); ?></p>
    </div>

    <!-- Loading -->
    <div class="sd-research-loading" id="sd-research-loading" style="display:none;">
        <div class="sd-spinner"></div>
        <p><?php esc_html_e( 'Caricamento dati...', 'sd-logbook' ); ?></p>
    </div>

</div>
