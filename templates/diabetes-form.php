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
        <canvas id="sd-glycemia-chart" width="560" height="200"></canvas>
        <div class="sd-chart-legend" id="sd-chart-legend">
            <!-- Populated by JS based on unit -->
        </div>
    </div>

    <!-- 4 Checkpoint cards -->
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

            <!-- Glicemia -->
            <div class="sd-cp-field">
                <label class="sd-glic-label" data-cp="<?php echo esc_attr( $key ); ?>">
                    <!-- Label set by JS -->
                </label>
                <input type="number"
                       name="glic_<?php echo esc_attr( $key ); ?>_value"
                       class="sd-glic-input"
                       data-cp="<?php echo esc_attr( $key ); ?>"
                       inputmode="numeric">
            </div>

            <!-- Metodo C / S -->
            <div class="sd-cp-field">
                <label><?php esc_html_e( 'Metodo', 'sd-logbook' ); ?></label>
                <div class="sd-method-toggle" data-cp="<?php echo esc_attr( $key ); ?>">
                    <button type="button" class="sd-method-btn" data-value="C" data-name="glic_<?php echo esc_attr( $key ); ?>_method">
                        <span class="sd-method-letter">C</span>
                        <span class="sd-method-label"><?php esc_html_e( 'Capillare', 'sd-logbook' ); ?></span>
                    </button>
                    <button type="button" class="sd-method-btn" data-value="S" data-name="glic_<?php echo esc_attr( $key ); ?>_method">
                        <span class="sd-method-letter">S</span>
                        <span class="sd-method-label"><?php esc_html_e( 'Sensore', 'sd-logbook' ); ?></span>
                    </button>
                </div>
                <input type="hidden" name="glic_<?php echo esc_attr( $key ); ?>_method" value="">
            </div>

            <!-- Freccia trend (solo se S) -->
            <div class="sd-cp-field sd-trend-field" data-cp="<?php echo esc_attr( $key ); ?>" style="display:none;">
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
