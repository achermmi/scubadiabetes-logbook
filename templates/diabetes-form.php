<?php
/**
 * Template: Sezione Dati Diabete nel Form Immersione
 *
 * Mostra i 4 checkpoint glicemici (-60, -30, -10, post)
 * con metodo C/S, freccia trend, CHO, insulina.
 * Include il grafico glicemico stile logbook.
 *
 * Visibile SOLO per utenti con ruolo sd_diver_diabetic
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<!-- ============================================================ -->
<!-- SEZIONE DIABETE: GLICEMIE E PROVVEDIMENTI -->
<!-- ============================================================ -->
<div class="sd-section sd-section-diabetes">
    <div class="sd-section-title sd-section-title-diabetes">
        <span class="sd-section-icon">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2C12 2 5 10 5 14a7 7 0 0 0 14 0c0-4-7-12-7-12z"/>
                <line x1="12" y1="18" x2="12" y2="14"/>
                <line x1="10" y1="16" x2="14" y2="16"/>
            </svg>
        </span>
        <?php esc_html_e( 'Glicemie e Provvedimenti', 'sd-logbook' ); ?>
    </div>

    <?php
    // Unità glicemica dell'utente corrente (default dal profilo)
    global $wpdb;
    $sd_db_unit = new SD_Database();
    $user_glycemia_unit = $wpdb->get_var( $wpdb->prepare(
        "SELECT glycemia_unit FROM {$sd_db_unit->table('diver_profiles')} WHERE user_id = %d",
        get_current_user_id()
    ) ) ?: 'mg/dl';
    $is_mmol = ( $user_glycemia_unit === 'mmol/l' );
    ?>

    <!-- Selettore unità di misura glicemica -->
    <div class="sd-unit-selector">
        <span class="sd-unit-selector-label"><?php esc_html_e( 'Unità di misura:', 'sd-logbook' ); ?></span>
        <div class="sd-unit-toggle-inline" id="sd-unit-toggle">
            <button type="button" class="sd-unit-btn-inline <?php echo ! $is_mmol ? 'active' : ''; ?>" data-unit="mg/dl">mg/dL</button>
            <button type="button" class="sd-unit-btn-inline <?php echo $is_mmol ? 'active' : ''; ?>" data-unit="mmol/l">mmol/L</button>
        </div>
    </div>

    <!-- Hidden: unità di input per il backend -->
    <input type="hidden" name="glycemia_input_unit" id="sd-glycemia-input-unit" value="<?php echo esc_attr( $user_glycemia_unit ); ?>">

    <!-- Grafico glicemico (canvas) -->
    <div class="sd-glycemia-chart-wrap">
        <canvas id="sd-glycemia-chart" width="560" height="260"></canvas>
        <div id="sd-glycemia-tooltip" class="sd-glycemia-tooltip"></div>
        <div class="sd-chart-legend" id="sd-chart-legend">
            <!-- Populated by JS based on unit -->
        </div>
    </div>

    <!-- 4 Checkpoint cards -->
    <div id="sd-cgm-import-bar" style="margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <button type="button" id="sd-cgm-import-btn" class="sd-btn-cgm-import">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:5px;">
                <path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2z"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            <?php esc_html_e( 'Importa letture da CGM', 'sd-logbook' ); ?>
        </button>
        <span id="sd-cgm-import-msg" style="font-size:0.85em;color:#555;display:none;"></span>
    </div>

    <div class="sd-checkpoints">

        <?php
        $checkpoints = array(
            '60'   => array( 'label' => '-60 min', 'color' => '#0097A7' ),
            '30'   => array( 'label' => '-30 min', 'color' => '#1565C0' ),
            '10'   => array( 'label' => '-10 min', 'color' => '#0B3D6E' ),
            'post' => array( 'label' => 'POST',    'color' => '#EA580C' ),
        );

        foreach ( $checkpoints as $key => $cp ) :
        ?>
        <div class="sd-checkpoint-card" data-checkpoint="<?php echo esc_attr( $key ); ?>">
            <div class="sd-checkpoint-header" style="background: <?php echo esc_attr( $cp['color'] ); ?>">
                <?php echo esc_html( $cp['label'] ); ?>
            </div>

            <!-- Glicemia capillare -->
            <div class="sd-cp-field">
                <label class="sd-glic-label-cap" data-cp="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Capillare (mg/dL)', 'sd-logbook' ); ?></label>
                <input type="number"
                       name="glic_<?php echo esc_attr( $key ); ?>_cap"
                       class="sd-glic-input sd-glic-cap"
                       data-cp="<?php echo esc_attr( $key ); ?>"
                       inputmode="numeric">
            </div>

            <!-- Glicemia sensore -->
            <div class="sd-cp-field">
                <label class="sd-glic-label-sens" data-cp="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Sensore (mg/dL)', 'sd-logbook' ); ?></label>
                <input type="number"
                       name="glic_<?php echo esc_attr( $key ); ?>_sens"
                       class="sd-glic-input sd-glic-sens"
                       data-cp="<?php echo esc_attr( $key ); ?>"
                       inputmode="numeric">
            </div>

            <!-- Freccia trend sensore -->
            <div class="sd-cp-field sd-trend-field" data-cp="<?php echo esc_attr( $key ); ?>">
                <label><?php esc_html_e( 'Freccia sensore', 'sd-logbook' ); ?></label>
                <div class="sd-trend-select" data-cp="<?php echo esc_attr( $key ); ?>">
                    <button type="button" class="sd-trend-btn" data-value="salita_rapida" title="<?php esc_attr_e( 'Salita rapida', 'sd-logbook' ); ?>">
                        <span class="sd-arrow sd-arrow-up-fast">↑↑</span>
                    </button>
                    <button type="button" class="sd-trend-btn" data-value="salita" title="<?php esc_attr_e( 'Salita', 'sd-logbook' ); ?>">
                        <span class="sd-arrow sd-arrow-up">↑</span>
                    </button>
                    <button type="button" class="sd-trend-btn" data-value="stabile" title="<?php esc_attr_e( 'Stabile', 'sd-logbook' ); ?>">
                        <span class="sd-arrow sd-arrow-stable">→</span>
                    </button>
                    <button type="button" class="sd-trend-btn" data-value="discesa" title="<?php esc_attr_e( 'Discesa', 'sd-logbook' ); ?>">
                        <span class="sd-arrow sd-arrow-down">↓</span>
                    </button>
                    <button type="button" class="sd-trend-btn" data-value="discesa_rapida" title="<?php esc_attr_e( 'Discesa rapida', 'sd-logbook' ); ?>">
                        <span class="sd-arrow sd-arrow-down-fast">↓↓</span>
                    </button>
                </div>
                <input type="hidden" name="glic_<?php echo esc_attr( $key ); ?>_trend" value="">
            </div>

            <!-- CHO Rapidi -->
            <div class="sd-cp-field">
                <label><?php esc_html_e( 'CHO rapidi (gr)', 'sd-logbook' ); ?></label>
                <input type="number" name="glic_<?php echo esc_attr( $key ); ?>_cho_rapidi" step="0.5" min="0" placeholder="0" inputmode="decimal">
            </div>

            <!-- CHO Lenti -->
            <div class="sd-cp-field">
                <label><?php esc_html_e( 'CHO lenti (gr)', 'sd-logbook' ); ?></label>
                <input type="number" name="glic_<?php echo esc_attr( $key ); ?>_cho_lenti" step="0.5" min="0" placeholder="0" inputmode="decimal">
            </div>

            <!-- Insulina -->
            <div class="sd-cp-field">
                <label><?php esc_html_e( 'INS (U)', 'sd-logbook' ); ?></label>
                <input type="number" name="glic_<?php echo esc_attr( $key ); ?>_insulin" step="0.1" min="0" placeholder="0" inputmode="decimal">
            </div>

            <!-- Note provvedimenti -->
            <div class="sd-cp-field">
                <label><?php esc_html_e( 'Provvedimenti', 'sd-logbook' ); ?></label>
                <input type="text" name="glic_<?php echo esc_attr( $key ); ?>_notes" placeholder="<?php esc_attr_e( 'es: 2 biscotti', 'sd-logbook' ); ?>">
            </div>
        </div>
        <?php endforeach; ?>

    </div>

    <!-- 4 Misure Extra -->
    <div class="sd-checkpoints-extra">
        <?php
        $sd_extras = array(
            'extra1' => array( 'label' => 'Extra 1', 'bg' => '#FEFCE8', 'border' => '#FEF08A' ),
            'extra2' => array( 'label' => 'Extra 2', 'bg' => '#FEF08A', 'border' => '#FDE68A' ),
            'extra3' => array( 'label' => 'Extra 3', 'bg' => '#FCD34D', 'border' => '#FBBF24' ),
            'extra4' => array( 'label' => 'Extra 4', 'bg' => '#F59E0B', 'border' => '#D97706' ),
        );
        foreach ( $sd_extras as $key => $ex ) :
        ?>
        <div class="sd-checkpoint-card sd-checkpoint-extra" style="border-color:<?php echo esc_attr( $ex['border'] ); ?>">
            <div class="sd-checkpoint-header-extra" style="background:<?php echo esc_attr( $ex['bg'] ); ?>">
                <?php echo esc_html( strtoupper( $ex['label'] ) ); ?>
            </div>

            <!-- Quando -->
            <div class="sd-cp-field">
                <label><?php esc_html_e( 'Momento', 'sd-logbook' ); ?></label>
                <select name="glic_<?php echo esc_attr( $key ); ?>_when" class="sd-extra-when-select">
                    <option value=""><?php esc_html_e( '— Seleziona quando —', 'sd-logbook' ); ?></option>
                    <option value="prima_60"><?php esc_html_e( '- 90 MIN', 'sd-logbook' ); ?></option>
                    <option value="prima_30"><?php esc_html_e( '- 45 MIN', 'sd-logbook' ); ?></option>
                    <option value="prima_10"><?php esc_html_e( '- 20 MIN', 'sd-logbook' ); ?></option>
                    <option value="prima_post"><?php esc_html_e( '- 5 MIN', 'sd-logbook' ); ?></option>
                    <option value="dopo_post"><?php esc_html_e( '+ 30 MIN', 'sd-logbook' ); ?></option>
                </select>
            </div>

            <!-- Capillare extra -->
            <div class="sd-cp-field">
                <label class="sd-glic-label-cap" data-cp="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Capillare (mg/dL)', 'sd-logbook' ); ?></label>
                <input type="number"
                       name="glic_<?php echo esc_attr( $key ); ?>_cap"
                       class="sd-glic-input sd-glic-cap"
                       data-cp="<?php echo esc_attr( $key ); ?>"
                       inputmode="numeric">
            </div>

            <!-- Sensore extra -->
            <div class="sd-cp-field">
                <label class="sd-glic-label-sens" data-cp="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Sensore (mg/dL)', 'sd-logbook' ); ?></label>
                <input type="number"
                       name="glic_<?php echo esc_attr( $key ); ?>_sens"
                       class="sd-glic-input sd-glic-sens"
                       data-cp="<?php echo esc_attr( $key ); ?>"
                       inputmode="numeric">
            </div>

            <!-- Trend extra -->
            <div class="sd-cp-field sd-trend-field" data-cp="<?php echo esc_attr( $key ); ?>">
                <label><?php esc_html_e( 'Freccia sensore', 'sd-logbook' ); ?></label>
                <div class="sd-trend-select" data-cp="<?php echo esc_attr( $key ); ?>">
                    <button type="button" class="sd-trend-btn" data-value="salita_rapida" title="<?php esc_attr_e( 'Salita rapida', 'sd-logbook' ); ?>">
                        <span class="sd-arrow sd-arrow-up-fast">↑↑</span>
                    </button>
                    <button type="button" class="sd-trend-btn" data-value="salita" title="<?php esc_attr_e( 'Salita', 'sd-logbook' ); ?>">
                        <span class="sd-arrow sd-arrow-up">↑</span>
                    </button>
                    <button type="button" class="sd-trend-btn" data-value="stabile" title="<?php esc_attr_e( 'Stabile', 'sd-logbook' ); ?>">
                        <span class="sd-arrow sd-arrow-stable">→</span>
                    </button>
                    <button type="button" class="sd-trend-btn" data-value="discesa" title="<?php esc_attr_e( 'Discesa', 'sd-logbook' ); ?>">
                        <span class="sd-arrow sd-arrow-down">↓</span>
                    </button>
                    <button type="button" class="sd-trend-btn" data-value="discesa_rapida" title="<?php esc_attr_e( 'Discesa rapida', 'sd-logbook' ); ?>">
                        <span class="sd-arrow sd-arrow-down-fast">↓↓</span>
                    </button>
                </div>
                <input type="hidden" name="glic_<?php echo esc_attr( $key ); ?>_trend" value="">
            </div>

            <!-- CHO rapidi extra -->
            <div class="sd-cp-field">
                <label><?php esc_html_e( 'CHO rapidi (gr)', 'sd-logbook' ); ?></label>
                <input type="number" name="glic_<?php echo esc_attr( $key ); ?>_cho_rapidi" step="0.5" min="0" placeholder="0" inputmode="decimal">
            </div>

            <!-- CHO lenti extra -->
            <div class="sd-cp-field">
                <label><?php esc_html_e( 'CHO lenti (gr)', 'sd-logbook' ); ?></label>
                <input type="number" name="glic_<?php echo esc_attr( $key ); ?>_cho_lenti" step="0.5" min="0" placeholder="0" inputmode="decimal">
            </div>

            <!-- Insulina extra -->
            <div class="sd-cp-field">
                <label><?php esc_html_e( 'INS (U)', 'sd-logbook' ); ?></label>
                <input type="number" name="glic_<?php echo esc_attr( $key ); ?>_insulin" step="0.1" min="0" placeholder="0" inputmode="decimal">
            </div>

            <!-- Note extra -->
            <div class="sd-cp-field">
                <label><?php esc_html_e( 'Provvedimenti', 'sd-logbook' ); ?></label>
                <input type="text" name="glic_<?php echo esc_attr( $key ); ?>_notes" placeholder="<?php esc_attr_e( 'es: 2 biscotti', 'sd-logbook' ); ?>">
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Decisione immersione (protocollo DS) -->
    <div class="sd-decision-bar" id="sd-decision-bar" style="display:none;">
        <div class="sd-decision-icon" id="sd-decision-icon"></div>
        <div class="sd-decision-text" id="sd-decision-text"></div>
    </div>
    <input type="hidden" name="dive_decision" id="sd-dive-decision" value="">
    <input type="hidden" name="dive_decision_reason" id="sd-dive-decision-reason" value="">

    <!-- Sezione aggiuntiva (collapsible) -->
    <details class="sd-details">
        <summary><?php esc_html_e( 'Terapia insulinica e chetonemia (avanzato)', 'sd-logbook' ); ?></summary>

        <!-- Chetonemia -->
        <div class="sd-subsection-label"><?php esc_html_e( 'Chetonemia', 'sd-logbook' ); ?></div>
        <div class="sd-field-row">
            <div class="sd-field sd-field-half">
                <label>
                    <input type="checkbox" name="ketone_checked" value="1" id="sd-ketone-check">
                    <?php esc_html_e( 'Controllata', 'sd-logbook' ); ?>
                </label>
            </div>
            <div class="sd-field sd-field-half">
                <label for="sd-ketone-val"><?php esc_html_e( 'Valore (mmol/L)', 'sd-logbook' ); ?></label>
                <input type="number" id="sd-ketone-val" name="ketone_value" step="0.1" min="0" max="10">
            </div>
        </div>

        <!-- Terapia insulinica -->
        <div class="sd-subsection-label"><?php esc_html_e( 'Riduzione insulina', 'sd-logbook' ); ?></div>
        <div class="sd-field-row">
            <div class="sd-field sd-field-half">
                <label>
                    <input type="checkbox" name="basal_insulin_reduced" value="1">
                    <?php esc_html_e( 'Basale ridotta', 'sd-logbook' ); ?>
                </label>
                <input type="number" name="basal_reduction_pct" placeholder="%" min="0" max="100" class="sd-inline-small">
            </div>
            <div class="sd-field sd-field-half">
                <label>
                    <input type="checkbox" name="bolus_insulin_reduced" value="1">
                    <?php esc_html_e( 'Bolo ridotto', 'sd-logbook' ); ?>
                </label>
                <input type="number" name="bolus_reduction_pct" placeholder="%" min="0" max="100" class="sd-inline-small">
            </div>
        </div>

        <!-- Microinfusore -->
        <div class="sd-field-row">
            <div class="sd-field sd-field-half">
                <label>
                    <input type="checkbox" name="pump_disconnected" value="1">
                    <?php esc_html_e( 'Pump disconnesso', 'sd-logbook' ); ?>
                </label>
            </div>
            <div class="sd-field sd-field-half">
                <label for="sd-pump-time"><?php esc_html_e( 'Durata disconn. (min)', 'sd-logbook' ); ?></label>
                <input type="number" id="sd-pump-time" name="pump_disconnect_time" min="0">
            </div>
        </div>

        <!-- Ipoglicemia durante immersione -->
        <div class="sd-subsection-label"><?php esc_html_e( 'Ipoglicemia in immersione', 'sd-logbook' ); ?></div>
        <div class="sd-field">
            <label>
                <input type="checkbox" name="hypo_during_dive" value="1" id="sd-hypo-check">
                <?php esc_html_e( 'Episodio ipoglicemico durante immersione', 'sd-logbook' ); ?>
            </label>
        </div>
        <div class="sd-field">
            <label for="sd-hypo-treat"><?php esc_html_e( 'Trattamento effettuato', 'sd-logbook' ); ?></label>
            <textarea id="sd-hypo-treat" name="hypo_treatment" rows="2" placeholder="<?php esc_attr_e( 'es: Risalita, glucosio gel in superficie', 'sd-logbook' ); ?>"></textarea>
        </div>

        <!-- Note diabete -->
        <div class="sd-field">
            <label for="sd-diabetes-notes"><?php esc_html_e( 'Note diabete', 'sd-logbook' ); ?></label>
            <textarea id="sd-diabetes-notes" name="diabetes_notes" rows="2"></textarea>
        </div>
    </details>
</div>
