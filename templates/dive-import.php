<?php
/**
 * Template: Importazione Immersioni
 * @package SD_Logbook
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$current_user     = wp_get_current_user();
$display_name     = trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name;
$role_badges_html = SD_Roles::render_badges_html( get_current_user_id() );
?>

<div class="sd-form-wrap sd-import-wrap">

    <!-- User bar -->
    <div class="sd-user-bar">
        <div class="sd-user-avatar"><?php echo get_avatar( get_current_user_id(), 40, '', $display_name, array( 'class' => 'sd-avatar-img' ) ); ?></div>
        <div class="sd-user-info">
            <span class="sd-user-name"><?php echo esc_html( $display_name ); ?></span>
            <span class="sd-user-badges"><?php echo $role_badges_html; ?></span>
        </div>
    </div>

    <!-- Page header -->
    <div class="sd-form-header">
        <div class="sd-form-header-icon">
            <svg viewBox="0 0 48 48" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="24" cy="24" r="22"/>
                <polyline points="24 14 24 28"/>
                <polyline points="17 21 24 14 31 21"/>
                <line x1="14" y1="34" x2="34" y2="34"/>
            </svg>
        </div>
        <div class="sd-form-header-text">
            <h2><?php esc_html_e( 'Importa immersioni', 'sd-logbook' ); ?></h2>
            <span class="sd-dive-number"><?php esc_html_e( 'da Subsurface (.ssrf) o Shearwater Cloud (.db)', 'sd-logbook' ); ?></span>
        </div>
    </div>

    <!-- Global messages -->
    <div id="sd-import-messages" class="sd-form-messages" style="display:none;"></div>

    <!-- ============================================================ -->
    <!-- STEP 1: UPLOAD ZONE -->
    <!-- ============================================================ -->
    <div id="sd-step-upload">

        <div class="sd-section">
            <div class="sd-section-title">
                <span class="sd-section-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12H2"/><path d="M12 2l10 10-10 10"/></svg>
                </span>
                <?php esc_html_e( 'Formati supportati', 'sd-logbook' ); ?>
            </div>
            <div style="display:flex;gap:18px;flex-wrap:wrap;">
                <div style="flex:1;min-width:220px;padding:12px 14px;background:#E0F2F1;border-radius:8px;">
                    <div style="font-weight:800;font-size:14px;color:#00695C;margin-bottom:4px;">
                        🐙 Subsurface (.ssrf)
                    </div>
                    <div style="font-size:12px;color:#004D40;">
                        Esporta da <strong>File → Esporta → Subsurface XML</strong>.
                        Contiene sito, profondità, tempo, temperatura, bombole, compagni, note.
                    </div>
                </div>
                <div style="flex:1;min-width:220px;padding:12px 14px;background:#E3F2FD;border-radius:8px;">
                    <div style="font-weight:800;font-size:14px;color:#1565C0;margin-bottom:4px;">
                        🌊 Shearwater Cloud (.db)
                    </div>
                    <div style="font-size:12px;color:#0D47A1;">
                        Scarica il file <strong>.db</strong> dall'app Shearwater Cloud
                        (<em>Settings → Export → Download Cloud Backup</em>).
                        Contiene tutti i campi + pressione bombole in PSI.
                    </div>
                </div>
            </div>
        </div>

        <div class="sd-section">
            <div id="sd-upload-zone" class="sd-upload-zone">
                <div class="sd-upload-icon">
                    <svg viewBox="0 0 64 64" width="56" height="56" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M48 42c4.4-1.6 8-6 8-10.8A11.2 11.2 0 0 0 45.2 20a16 16 0 0 0-30.4 3.4A11.2 11.2 0 0 0 8 34c0 4.4 3 8.2 7.2 9.8"/>
                        <polyline points="42 38 32 28 22 38"/>
                        <line x1="32" y1="28" x2="32" y2="52"/>
                    </svg>
                </div>
                <div class="sd-upload-title"><?php esc_html_e( 'Trascina qui il tuo file', 'sd-logbook' ); ?></div>
                <div class="sd-upload-sub"><?php esc_html_e( 'oppure clicca per selezionarlo', 'sd-logbook' ); ?></div>
                <div class="sd-upload-formats">
                    <span class="sd-format-badge sd-format-ssrf">🐙 .ssrf · Subsurface</span>
                    <span class="sd-format-badge sd-format-shearwater">🌊 .db · Shearwater Cloud</span>
                </div>
                <button type="button" id="sd-btn-choose-file" class="sd-btn-upload-file">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <?php esc_html_e( 'Scegli file', 'sd-logbook' ); ?>
                </button>
                <input type="file" id="sd-import-file-input" accept=".ssrf,.db">
                <p style="font-size:11px;color:var(--sd-gray-400);margin-top:12px;"><?php esc_html_e( 'Max 50 MB · .ssrf · .db', 'sd-logbook' ); ?></p>
            </div>
        </div>

        <?php if ( current_user_can( 'manage_options' ) ) : ?>
        <div class="sd-section" id="sd-schema-debug-section" style="border:1px dashed #CBD5E1;background:#F8FAFC;">
            <div style="font-size:12px;font-weight:700;color:#64748B;margin-bottom:8px;">🔧 Diagnostica schema .db (solo admin)</div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="file" id="sd-schema-file-input" accept=".db" style="font-size:12px;">
                <button type="button" id="sd-btn-schema-dump" style="font-size:12px;padding:4px 10px;background:#3B82F6;color:#fff;border:none;border-radius:4px;cursor:pointer;">Analizza schema</button>
            </div>
            <pre id="sd-schema-output" style="font-size:11px;margin-top:10px;background:#1E293B;color:#E2E8F0;padding:12px;border-radius:6px;overflow:auto;max-height:400px;display:none;"></pre>
        </div>
        <?php endif; ?>

    </div>

    <!-- ============================================================ -->
    <!-- STEP 2: PROGRESS -->
    <!-- ============================================================ -->
    <div id="sd-step-progress" style="display:none;">
        <div class="sd-section">
            <div class="sd-import-progress">
                <svg viewBox="0 0 48 48" width="40" height="40" fill="none" stroke="var(--sd-blue)" stroke-width="2">
                    <circle cx="24" cy="24" r="20"/>
                    <path d="M24 14v10l6 3"/>
                </svg>
                <p style="margin-top:12px;font-size:14px;font-weight:600;color:var(--sd-gray-700);">
                    <?php esc_html_e( 'Analisi del file in corso…', 'sd-logbook' ); ?>
                </p>
                <div class="sd-progress-bar-wrap">
                    <div class="sd-progress-bar-fill"></div>
                </div>
                <p style="font-size:12px;color:var(--sd-gray-400);"><?php esc_html_e( 'Potrebbe richiedere alcuni secondi per file grandi.', 'sd-logbook' ); ?></p>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- STEP 3: PREVIEW + SELECTION -->
    <!-- ============================================================ -->
    <div id="sd-step-preview" style="display:none;">

        <!-- Summary bar -->
        <div class="sd-import-summary">
            <div class="sd-import-stat sd-stat-total">
                <span class="sd-import-stat-val" id="sd-stat-total">0</span>
                <span><?php esc_html_e( 'trovate', 'sd-logbook' ); ?></span>
            </div>
            <div style="width:1px;background:var(--sd-gray-300);height:30px;"></div>
            <div class="sd-import-stat sd-stat-new">
                <span class="sd-import-stat-val" id="sd-stat-new">0</span>
                <span><?php esc_html_e( 'nuove', 'sd-logbook' ); ?></span>
            </div>
            <div class="sd-import-stat sd-stat-dup">
                <span class="sd-import-stat-val" id="sd-stat-dup">0</span>
                <span><?php esc_html_e( 'duplicati', 'sd-logbook' ); ?></span>
            </div>
            <span class="sd-import-source-badge" id="sd-import-source"></span>
        </div>

        <!-- Select bar -->
        <div class="sd-select-bar">
            <span class="sd-select-bar-label"><?php esc_html_e( 'Selezione:', 'sd-logbook' ); ?></span>
            <button type="button" id="sd-btn-select-new" class="sd-btn-select-new">✅ <?php esc_html_e( 'Solo nuove', 'sd-logbook' ); ?></button>
            <button type="button" id="sd-btn-select-all" class="sd-btn-select-all"><?php esc_html_e( 'Tutte', 'sd-logbook' ); ?></button>
            <button type="button" id="sd-btn-deselect-all" class="sd-btn-select-all"><?php esc_html_e( 'Nessuna', 'sd-logbook' ); ?></button>
            <span id="sd-selected-count" class="sd-selected-count"></span>
        </div>

        <!-- Preview table -->
        <div class="sd-preview-table-wrap">
            <table class="sd-preview-table">
                <thead>
                    <tr>
                        <th></th>
                        <th><?php esc_html_e( 'Stato', 'sd-logbook' ); ?></th>
                        <th>#</th>
                        <th><?php esc_html_e( 'Marca', 'sd-logbook' ); ?></th>
                        <th><?php esc_html_e( 'Modello', 'sd-logbook' ); ?></th>
                        <th><?php esc_html_e( 'Data', 'sd-logbook' ); ?></th>
                        <th><?php esc_html_e( 'Sito', 'sd-logbook' ); ?></th>
                        <th><?php esc_html_e( 'Prof. max', 'sd-logbook' ); ?></th>
                        <th><?php esc_html_e( 'Durata', 'sd-logbook' ); ?></th>
                        <th><?php esc_html_e( 'T. acqua', 'sd-logbook' ); ?></th>
                        <th><?php esc_html_e( 'Pressioni', 'sd-logbook' ); ?></th>
                        <th><?php esc_html_e( 'Compagno', 'sd-logbook' ); ?></th>
                    </tr>
                </thead>
                <tbody id="sd-preview-tbody"></tbody>
            </table>
        </div>

        <!-- Legend -->
        <p style="font-size:11px;color:var(--sd-gray-400);margin-bottom:12px;">
            <span class="sd-new-badge">Nuovo</span> = non ancora nel logbook &nbsp;|&nbsp;
            <span class="sd-dup-badge">Duplicato</span> = già presente (stessa data/ora)
        </p>

        <!-- Actions -->
        <div class="sd-import-actions">
            <button type="button" id="sd-btn-confirm-import" class="sd-btn-import-confirm" disabled>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <?php esc_html_e( 'Importa le immersioni selezionate', 'sd-logbook' ); ?>
            </button>
            <button type="button" id="sd-btn-reset-import" class="sd-btn-import-reset">
                <?php esc_html_e( 'Annulla', 'sd-logbook' ); ?>
            </button>
        </div>

    </div>

    <!-- ============================================================ -->
    <!-- STEP 4: RESULT -->
    <!-- ============================================================ -->
    <div id="sd-step-result" style="display:none;">
        <div class="sd-section">
            <div id="sd-result-panel" class="sd-import-result sd-result-success">
                <div id="sd-result-icon" class="sd-result-icon">🎉</div>
                <div id="sd-result-title" class="sd-result-title"></div>
                <div id="sd-result-sub" class="sd-result-sub"></div>
                <?php
                $dash_page = get_page_by_path( 'dashboard' ) ?: get_page_by_path( 'logbook' );
                $dash_url  = $dash_page ? get_permalink( $dash_page ) : home_url( '/' );
                ?>
                <a href="<?php echo esc_url( $dash_url ); ?>" class="sd-btn-view-logbook">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <?php esc_html_e( 'Vai al logbook', 'sd-logbook' ); ?>
                </a>
                <button type="button" id="sd-btn-reset-import-result" class="sd-btn-import-reset" style="margin-top:10px;">
                    <?php esc_html_e( 'Importa un altro file', 'sd-logbook' ); ?>
                </button>
            </div>
        </div>
    </div>

</div>
