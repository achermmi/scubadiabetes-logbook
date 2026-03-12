<?php
/**
 * Template: Form Registrazione Immersione
 *
 * Variabili disponibili:
 * - $user_id (int)
 * - $is_diabetic (bool)
 * - $next_number (int)
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Dati utente loggato
$current_user = wp_get_current_user();
$display_name = trim( $current_user->first_name . ' ' . $current_user->last_name );
if ( empty( $display_name ) ) {
    $display_name = $current_user->display_name;
}

// Badge ruoli (supporta ruoli multipli)
$role_badges_html = SD_Roles::render_badges_html( $user_id );
?>

<div class="sd-form-wrap">

    <!-- User bar -->
    <div class="sd-user-bar">
        <div class="sd-user-avatar">
            <?php echo get_avatar( $user_id, 40, '', $display_name, array( 'class' => 'sd-avatar-img' ) ); ?>
        </div>
        <div class="sd-user-info">
            <span class="sd-user-name"><?php echo esc_html( $display_name ); ?></span>
            <span class="sd-user-badges"><?php echo $role_badges_html; ?></span>
        </div>
        <div class="sd-user-dive-count">
            <?php
            global $wpdb;
            $db_count = new SD_Database();
            $total_dives = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$db_count->table('dives')} WHERE user_id = %d",
                $user_id
            ) );
            ?>
            <span class="sd-count-number"><?php echo intval( $total_dives ); ?></span>
            <span class="sd-count-label"><?php esc_html_e( 'immersioni', 'sd-logbook' ); ?></span>
        </div>
    </div>

    <!-- Header -->
    <div class="sd-form-header">
        <div class="sd-form-header-icon">
            <svg viewBox="0 0 48 48" width="48" height="48" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="24" cy="24" r="23" stroke="currentColor" stroke-width="2"/>
                <path d="M24 8c-2 0-3.5 1-4 3l-1 6c-.5 3 1 5 3 6v10c0 2 1 4 2 4s2-2 2-4V23c2-1 3.5-3 3-6l-1-6c-.5-2-2-3-4-3z" fill="currentColor"/>
                <circle cx="24" cy="12" r="2" fill="white"/>
            </svg>
        </div>
        <div class="sd-form-header-text">
            <h2><?php esc_html_e( 'Registra Immersione', 'sd-logbook' ); ?></h2>
            <span class="sd-dive-number"><?php printf( esc_html__( 'Immersione N° %d', 'sd-logbook' ), $next_number ); ?></span>
        </div>
    </div>

    <!-- Messaggi -->
    <div class="sd-form-messages" style="display:none;"></div>

    <!-- Form -->
    <form id="sd-dive-form" class="sd-dive-form" novalidate>
        <input type="hidden" name="action" value="sd_save_dive">
        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'sd_dive_form_nonce' ); ?>">
        <input type="hidden" name="dive_number" value="<?php echo esc_attr( $next_number ); ?>">

        <!-- ============================================================ -->
        <!-- SEZIONE 1: DATA E SITO -->
        <!-- ============================================================ -->
        <div class="sd-section">
            <div class="sd-section-title">
                <span class="sd-section-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
                <?php esc_html_e( 'Data e Sito', 'sd-logbook' ); ?>
            </div>

            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label for="sd-dive-date"><?php esc_html_e( 'Data', 'sd-logbook' ); ?> <span class="sd-required">*</span></label>
                    <input type="date" id="sd-dive-date" name="dive_date" required value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>">
                </div>
                <div class="sd-field sd-field-half">
                    <label for="sd-dive-number"><?php esc_html_e( 'N° Immersione', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-dive-number" name="dive_number" value="<?php echo esc_attr( $next_number ); ?>" min="1">
                </div>
            </div>

            <div class="sd-field">
                <label for="sd-site-name"><?php esc_html_e( 'Sito di immersione', 'sd-logbook' ); ?> <span class="sd-required">*</span></label>
                <input type="text" id="sd-site-name" name="site_name" required placeholder="<?php esc_attr_e( 'es: Canalone Acitrezza', 'sd-logbook' ); ?>">
            </div>
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label for="sd-site-lat"><?php esc_html_e( 'Latitudine', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-site-lat" name="site_latitude" step="0.0000001" placeholder="<?php esc_attr_e( 'es: 37.5667', 'sd-logbook' ); ?>">
                </div>
                <div class="sd-field sd-field-half">
                    <label for="sd-site-lng"><?php esc_html_e( 'Longitudine', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-site-lng" name="site_longitude" step="0.0000001" placeholder="<?php esc_attr_e( 'es: 15.1667', 'sd-logbook' ); ?>">
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- SEZIONE 2: EQUIPAGGIAMENTO -->
        <!-- ============================================================ -->
        <div class="sd-section">
            <div class="sd-section-title">
                <span class="sd-section-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C8 2 6 5 6 8v3c0 2-1 3-2 4h16c-1-1-2-2-2-4V8c0-3-2-6-6-6z"/><ellipse cx="12" cy="20" rx="3" ry="2"/></svg>
                </span>
                <?php esc_html_e( 'Equipaggiamento', 'sd-logbook' ); ?>
            </div>

            <!-- Bombole -->
            <div class="sd-subsection-label"><?php esc_html_e( 'Bombole', 'sd-logbook' ); ?></div>
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label for="sd-tank-count"><?php esc_html_e( 'N° Bombole', 'sd-logbook' ); ?></label>
                    <select id="sd-tank-count" name="tank_count">
                        <option value="1">1</option>
                        <option value="2">2 (bibo)</option>
                        <option value="3">3</option>
                    </select>
                </div>
            </div>
            <div class="sd-field-row sd-field-row-3">
                <div class="sd-field">
                    <label for="sd-gas-mix"><?php esc_html_e( 'Miscela', 'sd-logbook' ); ?></label>
                    <select id="sd-gas-mix" name="gas_mix">
                        <option value="aria"><?php esc_html_e( 'Aria', 'sd-logbook' ); ?></option>
                        <option value="nitrox"><?php esc_html_e( 'Nitrox', 'sd-logbook' ); ?></option>
                        <option value="trimix"><?php esc_html_e( 'Trimix', 'sd-logbook' ); ?></option>
                    </select>
                </div>
                <div class="sd-field">
                    <label for="sd-tank-capacity"><?php esc_html_e( 'Capacità (L)', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-tank-capacity" name="tank_capacity" step="0.1" placeholder="15">
                </div>
                <div class="sd-field sd-field-nitrox" style="display:none;">
                    <label for="sd-nitrox-pct"><?php esc_html_e( '%O₂', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-nitrox-pct" name="nitrox_percentage" step="0.1" min="21" max="100" placeholder="32">
                </div>
            </div>

            <!-- Zavorra -->
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label for="sd-ballast"><?php esc_html_e( 'Zavorra (kg)', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-ballast" name="ballast_kg" step="0.5" placeholder="6">
                </div>
                <div class="sd-field sd-field-half">
                    <label for="sd-suit-type"><?php esc_html_e( 'Protezione', 'sd-logbook' ); ?></label>
                    <div class="sd-icon-select" data-name="suit_type">
                        <button type="button" class="sd-icon-btn" data-value="umida" title="<?php esc_attr_e( 'Umida', 'sd-logbook' ); ?>">
                            <svg viewBox="0 0 32 32" width="28" height="28"><path d="M16 2c-3 0-5 2-5 5v6c0 2-1 4-1 6v7c0 2 2 4 6 4s6-2 6-4v-7c0-2-1-4-1-6V7c0-3-2-5-5-5z" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                            <span><?php esc_html_e( 'Umida', 'sd-logbook' ); ?></span>
                        </button>
                        <button type="button" class="sd-icon-btn" data-value="semistagna" title="<?php esc_attr_e( 'Semistagna', 'sd-logbook' ); ?>">
                            <svg viewBox="0 0 32 32" width="28" height="28"><path d="M16 2c-3 0-5 2-5 5v6c0 2-1 4-1 6v7c0 2 2 4 6 4s6-2 6-4v-7c0-2-1-4-1-6V7c0-3-2-5-5-5z" fill="currentColor" opacity="0.3" stroke="currentColor" stroke-width="1.5"/></svg>
                            <span><?php esc_html_e( 'Semi', 'sd-logbook' ); ?></span>
                        </button>
                        <button type="button" class="sd-icon-btn" data-value="stagna" title="<?php esc_attr_e( 'Stagna', 'sd-logbook' ); ?>">
                            <svg viewBox="0 0 32 32" width="28" height="28"><path d="M16 2c-3 0-5 2-5 5v6c0 2-1 4-1 6v7c0 2 2 4 6 4s6-2 6-4v-7c0-2-1-4-1-6V7c0-3-2-5-5-5z" fill="currentColor" opacity="0.6" stroke="currentColor" stroke-width="1.5"/></svg>
                            <span><?php esc_html_e( 'Stagna', 'sd-logbook' ); ?></span>
                        </button>
                    </div>
                    <input type="hidden" name="suit_type" value="">
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- SEZIONE 3: INIZIO / FINE IMMERSIONE -->
        <!-- ============================================================ -->
        <div class="sd-section">
            <div class="sd-section-title">
                <span class="sd-section-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </span>
                <?php esc_html_e( 'Inizio / Fine Immersione', 'sd-logbook' ); ?>
            </div>

            <div class="sd-two-col-box">
                <!-- Colonna Inizio -->
                <div class="sd-col-box sd-col-start">
                    <div class="sd-col-box-title"><?php esc_html_e( 'INIZIO', 'sd-logbook' ); ?></div>
                    <div class="sd-field">
                        <label for="sd-time-in"><?php esc_html_e( 'Ora', 'sd-logbook' ); ?></label>
                        <input type="time" id="sd-time-in" name="time_in">
                    </div>
                    <div class="sd-field">
                        <label for="sd-pressure-start"><?php esc_html_e( 'Bar', 'sd-logbook' ); ?></label>
                        <input type="number" id="sd-pressure-start" name="pressure_start" placeholder="200" min="0" max="300">
                    </div>
                </div>
                <!-- Colonna Fine -->
                <div class="sd-col-box sd-col-end">
                    <div class="sd-col-box-title"><?php esc_html_e( 'FINE', 'sd-logbook' ); ?></div>
                    <div class="sd-field">
                        <label for="sd-time-out"><?php esc_html_e( 'Ora', 'sd-logbook' ); ?></label>
                        <input type="time" id="sd-time-out" name="time_out">
                    </div>
                    <div class="sd-field">
                        <label for="sd-pressure-end"><?php esc_html_e( 'Bar', 'sd-logbook' ); ?></label>
                        <input type="number" id="sd-pressure-end" name="pressure_end" placeholder="50" min="0" max="300">
                    </div>
                </div>
            </div>

            <!-- Profondità e tempo -->
            <div class="sd-field-row sd-field-row-3">
                <div class="sd-field">
                    <label for="sd-max-depth"><?php esc_html_e( 'Prof. max (m)', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-max-depth" name="max_depth" step="0.1" placeholder="30.6">
                </div>
                <div class="sd-field">
                    <label for="sd-avg-depth"><?php esc_html_e( 'Prof. media (m)', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-avg-depth" name="avg_depth" step="0.1">
                </div>
                <div class="sd-field">
                    <label for="sd-dive-time"><?php esc_html_e( 'Tempo (min)', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-dive-time" name="dive_time" placeholder="54">
                </div>
            </div>

            <!-- Soste -->
            <div class="sd-subsection-label"><?php esc_html_e( 'Soste di sicurezza', 'sd-logbook' ); ?></div>
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label for="sd-safety-depth"><?php esc_html_e( 'Profondità (m)', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-safety-depth" name="safety_stop_depth" step="0.1" placeholder="5">
                </div>
                <div class="sd-field sd-field-half">
                    <label for="sd-safety-time"><?php esc_html_e( 'Durata (min)', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-safety-time" name="safety_stop_time" placeholder="3">
                </div>
            </div>

            <!-- Deco (collapsible) -->
            <details class="sd-details">
                <summary><?php esc_html_e( 'Deco stop / Deep stop (avanzato)', 'sd-logbook' ); ?></summary>
                <div class="sd-subsection-label"><?php esc_html_e( 'Deco stop', 'sd-logbook' ); ?></div>
                <div class="sd-field-row">
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Prof. (m)', 'sd-logbook' ); ?></label>
                        <input type="number" name="deco_stop_depth" step="0.1">
                    </div>
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Durata (min)', 'sd-logbook' ); ?></label>
                        <input type="number" name="deco_stop_time">
                    </div>
                </div>
                <div class="sd-subsection-label"><?php esc_html_e( 'Deep stop', 'sd-logbook' ); ?></div>
                <div class="sd-field-row">
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Prof. (m)', 'sd-logbook' ); ?></label>
                        <input type="number" name="deep_stop_depth" step="0.1">
                    </div>
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Durata (min)', 'sd-logbook' ); ?></label>
                        <input type="number" name="deep_stop_time">
                    </div>
                </div>
            </details>
        </div>

        <!-- ============================================================ -->
        <!-- SEZIONE 4: INGRESSO IN ACQUA -->
        <!-- ============================================================ -->
        <div class="sd-section">
            <div class="sd-section-title">
                <span class="sd-section-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 18c2-1 4-1 6 0s4 1 6 0 4-1 6 0"/><path d="M2 22c2-1 4-1 6 0s4 1 6 0 4-1 6 0"/><path d="M12 2v12"/><path d="M8 8l4 4 4-4"/></svg>
                </span>
                <?php esc_html_e( 'Ingresso in acqua', 'sd-logbook' ); ?>
            </div>

            <div class="sd-icon-select sd-icon-select-wide" data-name="entry_type">
                <button type="button" class="sd-icon-btn" data-value="riva">
                    <svg viewBox="0 0 40 40" width="36" height="36"><path d="M5 30c3-2 7-2 10 0s7 2 10 0 7-2 10 0" fill="none" stroke="currentColor" stroke-width="2"/><path d="M10 28v-8l10-10 10 10v8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                    <span><?php esc_html_e( 'Riva', 'sd-logbook' ); ?></span>
                </button>
                <button type="button" class="sd-icon-btn" data-value="barca">
                    <svg viewBox="0 0 40 40" width="36" height="36"><path d="M5 25l5 5h20l5-5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M10 25v-10h20v10" fill="none" stroke="currentColor" stroke-width="1.5"/><line x1="20" y1="8" x2="20" y2="15" stroke="currentColor" stroke-width="1.5"/></svg>
                    <span><?php esc_html_e( 'Barca', 'sd-logbook' ); ?></span>
                </button>
                <button type="button" class="sd-icon-btn" data-value="drift">
                    <svg viewBox="0 0 40 40" width="36" height="36"><path d="M5 20c5-3 10 3 15 0s10 3 15 0" fill="none" stroke="currentColor" stroke-width="2"/><path d="M15 15l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                    <span><?php esc_html_e( 'Drift', 'sd-logbook' ); ?></span>
                </button>
            </div>
            <input type="hidden" name="entry_type" value="">
        </div>

        <!-- ============================================================ -->
        <!-- SEZIONE 5: CONDIZIONI -->
        <!-- ============================================================ -->
        <div class="sd-section">
            <div class="sd-section-title">
                <span class="sd-section-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                </span>
                <?php esc_html_e( 'Condizioni', 'sd-logbook' ); ?>
            </div>

            <!-- Meteo -->
            <div class="sd-subsection-label"><?php esc_html_e( 'Meteo', 'sd-logbook' ); ?></div>
            <div class="sd-icon-select sd-icon-select-wide" data-name="weather">
                <button type="button" class="sd-icon-btn" data-value="sereno">
                    <svg viewBox="0 0 40 40" width="36" height="36"><circle cx="20" cy="20" r="8" fill="#FFD700" stroke="#F59E0B" stroke-width="1.5"/><g stroke="#F59E0B" stroke-width="1.5"><line x1="20" y1="4" x2="20" y2="8"/><line x1="20" y1="32" x2="20" y2="36"/><line x1="4" y1="20" x2="8" y2="20"/><line x1="32" y1="20" x2="36" y2="20"/></g></svg>
                    <span><?php esc_html_e( 'Sereno', 'sd-logbook' ); ?></span>
                </button>
                <button type="button" class="sd-icon-btn" data-value="nuvoloso">
                    <svg viewBox="0 0 40 40" width="36" height="36"><path d="M10 28a7 7 0 0 1-1-14 10 10 0 0 1 19-2 7 7 0 0 1 2 14z" fill="#CBD5E1" stroke="#94A3B8" stroke-width="1.5"/></svg>
                    <span><?php esc_html_e( 'Nuvoloso', 'sd-logbook' ); ?></span>
                </button>
                <button type="button" class="sd-icon-btn" data-value="pioggia">
                    <svg viewBox="0 0 40 40" width="36" height="36"><path d="M10 24a6 6 0 0 1-1-12 9 9 0 0 1 17-1 6 6 0 0 1 1 12z" fill="#94A3B8" stroke="#64748B" stroke-width="1.5"/><line x1="14" y1="28" x2="12" y2="34" stroke="#3B82F6" stroke-width="2" stroke-linecap="round"/><line x1="20" y1="28" x2="18" y2="34" stroke="#3B82F6" stroke-width="2" stroke-linecap="round"/><line x1="26" y1="28" x2="24" y2="34" stroke="#3B82F6" stroke-width="2" stroke-linecap="round"/></svg>
                    <span><?php esc_html_e( 'Pioggia', 'sd-logbook' ); ?></span>
                </button>
            </div>
            <input type="hidden" name="weather" value="">

            <!-- Temperature -->
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label for="sd-temp-air"><?php esc_html_e( 'Temp. aria (°C)', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-temp-air" name="temp_air" step="0.1" placeholder="33">
                </div>
                <div class="sd-field sd-field-half">
                    <label for="sd-temp-water"><?php esc_html_e( 'Temp. acqua (°C)', 'sd-logbook' ); ?></label>
                    <input type="number" id="sd-temp-water" name="temp_water" step="0.1" placeholder="17">
                </div>
            </div>

            <!-- Tipo immersione -->
            <div class="sd-subsection-label"><?php esc_html_e( 'Immersione', 'sd-logbook' ); ?></div>
            <div class="sd-icon-select sd-icon-select-wide" data-name="dive_type">
                <button type="button" class="sd-icon-btn" data-value="mare">
                    <svg viewBox="0 0 40 40" width="36" height="36"><path d="M4 20c3-3 6 3 9 0s6 3 9 0 6 3 9 0" fill="none" stroke="#0EA5E9" stroke-width="2"/><path d="M4 26c3-2 6 2 9 0s6 2 9 0 6 2 9 0" fill="none" stroke="#0EA5E9" stroke-width="1.5" opacity="0.5"/><circle cx="30" cy="10" r="5" fill="#FBBF24" stroke="#F59E0B" stroke-width="1"/></svg>
                    <span><?php esc_html_e( 'Mare', 'sd-logbook' ); ?></span>
                </button>
                <button type="button" class="sd-icon-btn" data-value="lago">
                    <svg viewBox="0 0 40 40" width="36" height="36"><ellipse cx="20" cy="24" rx="16" ry="8" fill="none" stroke="#0EA5E9" stroke-width="2"/><path d="M10 12c2-4 6-6 10-6s8 2 10 6" fill="none" stroke="#16A34A" stroke-width="2"/><line x1="20" y1="6" x2="20" y2="16" stroke="#16A34A" stroke-width="1.5"/></svg>
                    <span><?php esc_html_e( 'Lago', 'sd-logbook' ); ?></span>
                </button>
                <button type="button" class="sd-icon-btn" data-value="fiume">
                    <svg viewBox="0 0 40 40" width="36" height="36"><path d="M8 8c4 6 0 12 4 18s8 6 12 6" fill="none" stroke="#0EA5E9" stroke-width="2.5"/><path d="M16 6c4 6 0 12 4 18s8 6 12 6" fill="none" stroke="#0EA5E9" stroke-width="1.5" opacity="0.4"/></svg>
                    <span><?php esc_html_e( 'Fiume', 'sd-logbook' ); ?></span>
                </button>
                <button type="button" class="sd-icon-btn" data-value="piscina">
                    <svg viewBox="0 0 40 40" width="36" height="36"><rect x="6" y="12" width="28" height="18" rx="3" fill="none" stroke="#0EA5E9" stroke-width="2"/><path d="M6 20h28" stroke="#0EA5E9" stroke-width="1" opacity="0.3"/><path d="M6 24h28" stroke="#0EA5E9" stroke-width="1" opacity="0.3"/></svg>
                    <span><?php esc_html_e( 'Piscina', 'sd-logbook' ); ?></span>
                </button>
                <button type="button" class="sd-icon-btn" data-value="ghiaccio">
                    <svg viewBox="0 0 40 40" width="36" height="36"><line x1="20" y1="6" x2="20" y2="34" stroke="#38BDF8" stroke-width="2"/><line x1="6" y1="20" x2="34" y2="20" stroke="#38BDF8" stroke-width="2"/><line x1="10" y1="10" x2="30" y2="30" stroke="#38BDF8" stroke-width="1.5"/><line x1="30" y1="10" x2="10" y2="30" stroke="#38BDF8" stroke-width="1.5"/></svg>
                    <span><?php esc_html_e( 'Ghiaccio', 'sd-logbook' ); ?></span>
                </button>
                <button type="button" class="sd-icon-btn" data-value="grotta">
                    <svg viewBox="0 0 40 40" width="36" height="36"><path d="M6 34c0-14 6-24 14-24s14 10 14 24" fill="none" stroke="#64748B" stroke-width="2"/><path d="M12 34c0-8 3-14 8-14s8 6 8 14" fill="none" stroke="#64748B" stroke-width="1.5" opacity="0.5"/></svg>
                    <span><?php esc_html_e( 'Grotta', 'sd-logbook' ); ?></span>
                </button>
            </div>
            <input type="hidden" name="dive_type" value="">

            <!-- Condizioni acqua -->
            <div class="sd-subsection-label"><?php esc_html_e( 'Condizioni', 'sd-logbook' ); ?></div>
            <div class="sd-icon-select sd-icon-select-wide" data-name="sea_condition">
                <button type="button" class="sd-icon-btn" data-value="calmo">
                    <svg viewBox="0 0 40 40" width="36" height="36"><path d="M4 20h32" stroke="currentColor" stroke-width="2"/><path d="M4 26h32" stroke="currentColor" stroke-width="1.5" opacity="0.5"/></svg>
                    <span><?php esc_html_e( 'Calmo', 'sd-logbook' ); ?></span>
                </button>
                <button type="button" class="sd-icon-btn" data-value="mosso">
                    <svg viewBox="0 0 40 40" width="36" height="36"><path d="M4 20c3-3 6 3 9 0s6 3 9 0 6 3 9 0" fill="none" stroke="currentColor" stroke-width="2"/><path d="M4 27c3-2 6 2 9 0s6 2 9 0 6 2 9 0" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.5"/></svg>
                    <span><?php esc_html_e( 'Mosso', 'sd-logbook' ); ?></span>
                </button>
                <button type="button" class="sd-icon-btn" data-value="agitato">
                    <svg viewBox="0 0 40 40" width="36" height="36"><path d="M4 18c3-5 6 5 9 0s6 5 9 0 6 5 9 0" fill="none" stroke="currentColor" stroke-width="2.5"/><path d="M4 26c3-4 6 4 9 0s6 4 9 0 6 4 9 0" fill="none" stroke="currentColor" stroke-width="2" opacity="0.5"/></svg>
                    <span><?php esc_html_e( 'Agitato', 'sd-logbook' ); ?></span>
                </button>
            </div>
            <input type="hidden" name="sea_condition" value="">

            <!-- Corrente e Visibilità -->
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Corrente', 'sd-logbook' ); ?></label>
                    <div class="sd-icon-select sd-icon-select-small" data-name="current_strength">
                        <button type="button" class="sd-icon-btn sd-btn-sm" data-value="debole"><?php esc_html_e( 'Debole', 'sd-logbook' ); ?></button>
                        <button type="button" class="sd-icon-btn sd-btn-sm" data-value="media"><?php esc_html_e( 'Media', 'sd-logbook' ); ?></button>
                        <button type="button" class="sd-icon-btn sd-btn-sm" data-value="forte"><?php esc_html_e( 'Forte', 'sd-logbook' ); ?></button>
                    </div>
                    <input type="hidden" name="current_strength" value="">
                </div>
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Visibilità', 'sd-logbook' ); ?></label>
                    <div class="sd-icon-select sd-icon-select-small" data-name="visibility">
                        <button type="button" class="sd-icon-btn sd-btn-sm" data-value="buona"><?php esc_html_e( 'Buona', 'sd-logbook' ); ?></button>
                        <button type="button" class="sd-icon-btn sd-btn-sm" data-value="media"><?php esc_html_e( 'Media', 'sd-logbook' ); ?></button>
                        <button type="button" class="sd-icon-btn sd-btn-sm" data-value="scarsa"><?php esc_html_e( 'Scarsa', 'sd-logbook' ); ?></button>
                    </div>
                    <input type="hidden" name="visibility" value="">
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- SEZIONE 6: NOTE E COMPAGNI -->
        <!-- ============================================================ -->
        <div class="sd-section">
            <div class="sd-section-title">
                <span class="sd-section-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <?php esc_html_e( 'Note e Compagni', 'sd-logbook' ); ?>
            </div>

            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label for="sd-buddy"><?php esc_html_e( 'Compagno', 'sd-logbook' ); ?></label>
                    <input type="text" id="sd-buddy" name="buddy_name" placeholder="<?php esc_attr_e( 'Nome compagno', 'sd-logbook' ); ?>">
                </div>
                <div class="sd-field sd-field-half">
                    <label for="sd-guide"><?php esc_html_e( 'Guida', 'sd-logbook' ); ?></label>
                    <input type="text" id="sd-guide" name="guide_name" placeholder="<?php esc_attr_e( 'Nome guida', 'sd-logbook' ); ?>">
                </div>
            </div>

            <div class="sd-field">
                <label for="sd-sightings"><?php esc_html_e( 'Cosa ho visto', 'sd-logbook' ); ?></label>
                <textarea id="sd-sightings" name="sightings" rows="3" placeholder="<?php esc_attr_e( 'Pesci, coralli, relitti...', 'sd-logbook' ); ?>"></textarea>
            </div>

            <div class="sd-field">
                <label for="sd-notes"><?php esc_html_e( 'Note', 'sd-logbook' ); ?></label>
                <textarea id="sd-notes" name="notes" rows="2"></textarea>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- SEZIONE DIABETE (solo per subacquei diabetici) -->
        <!-- ============================================================ -->
        <?php if ( $is_diabetic ) : ?>
            <?php include SD_LOGBOOK_PLUGIN_DIR . 'templates/diabetes-form.php'; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- PULSANTE SALVA -->
        <!-- ============================================================ -->
        <div class="sd-form-actions">
            <button type="submit" class="sd-btn-save" id="sd-btn-save">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <span><?php esc_html_e( 'Salva Immersione', 'sd-logbook' ); ?></span>
            </button>
        </div>

    </form>
</div>
