<?php
/**
 * Template: Dashboard Riepilogo Immersioni
 *
 * Variabili disponibili:
 * - $user_id, $is_diabetic, $can_view_all
 * - $dives (array), $stats (object)
 * - $display_name
 *
 * @package SD_Logbook
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$role_badges_html = SD_Roles::render_badges_html( $user_id );
?>

<div class="sd-form-wrap sd-dashboard-wrap">

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
            <span class="sd-count-number"><?php echo intval( $stats->total_dives ); ?></span>
            <span class="sd-count-label"><?php esc_html_e( 'immersioni', 'sd-logbook' ); ?></span>
        </div>
    </div>

    <!-- Stats cards -->
    <div class="sd-stats-grid">
        <div class="sd-stat-card<?php echo $stats->max_depth_dive_id ? ' sd-btn-detail sd-stat-card--clickable' : ''; ?>"<?php echo $stats->max_depth_dive_id ? ' data-dive-id="' . esc_attr( $stats->max_depth_dive_id ) . '" style="cursor:pointer;"' : ''; ?>>
            <div class="sd-stat-value"><?php echo $stats->max_depth ? esc_html( $stats->max_depth ) . 'm' : '—'; ?></div>
            <div class="sd-stat-label"><?php esc_html_e( 'Prof. massima', 'sd-logbook' ); ?></div>
        </div>
        <div class="sd-stat-card">
            <div class="sd-stat-value">
                <?php
                if ( $stats->total_time ) {
                    $hours = floor( $stats->total_time / 60 );
                    $mins  = $stats->total_time % 60;
                    printf( '%02d h %02d min', $hours, $mins );
                } else {
                    echo '—';
                }
                ?>
            </div>
            <div class="sd-stat-label"><?php esc_html_e( 'Tempo totale', 'sd-logbook' ); ?></div>
        </div>
        <div class="sd-stat-card">
            <div class="sd-stat-value"><?php echo $stats->unique_sites ?: '—'; ?></div>
            <div class="sd-stat-label"><?php esc_html_e( 'Siti visitati', 'sd-logbook' ); ?></div>
        </div>
        <div class="sd-stat-card">
            <div class="sd-stat-value"><?php echo $stats->last_dive_date ? esc_html( date_i18n( 'd/m/Y', strtotime( $stats->last_dive_date ) ) ) : '—'; ?></div>
            <div class="sd-stat-label"><?php esc_html_e( 'Ultima immersione', 'sd-logbook' ); ?></div>
        </div>
    </div>

    <!-- Actions bar -->
    <div class="sd-actions-bar">
        <h2 class="sd-dash-title">
            <?php
            if ( $can_view_all ) {
                esc_html_e( 'Tutte le immersioni', 'sd-logbook' );
            } else {
                esc_html_e( 'Le mie immersioni', 'sd-logbook' );
            }
            ?>
        </h2>
        <div class="sd-actions-buttons">
            <button type="button" class="sd-btn-export" id="sd-btn-export">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?php esc_html_e( 'Export CSV', 'sd-logbook' ); ?>
            </button>
        </div>
    </div>

    <!-- Dive list -->
    <?php if ( empty( $dives ) ) : ?>
        <div class="sd-empty-state">
            <svg viewBox="0 0 80 80" width="64" height="64" fill="none" stroke="#CBD5E1" stroke-width="2">
                <circle cx="40" cy="40" r="35"/>
                <path d="M40 20c-3 0-5 2-5 5v10c0 3 1 5 2 7v8c0 3 1 5 3 5s3-2 3-5v-8c1-2 2-4 2-7V25c0-3-2-5-5-5z"/>
            </svg>
            <p><?php esc_html_e( 'Nessuna immersione registrata.', 'sd-logbook' ); ?></p>
        </div>
    <?php else : ?>
        <div class="sd-dive-list">
            <?php foreach ( $dives as $dive ) :
                global $wpdb;
                $db_check = new SD_Database();
                $has_diabetes = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$db_check->table('dive_diabetes')} WHERE dive_id = %d",
                    $dive->id
                ) );
                $edit_count = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$db_check->table('dive_edits')} WHERE dive_id = %d",
                    $dive->id
                ) );
                $lat_val    = (float) ( $dive->site_latitude ?? 0 );
                $lng_val    = (float) ( $dive->site_longitude ?? 0 );
                $has_coords = $lat_val != 0.0 && $lng_val != 0.0;
            ?>
            <div class="sd-dive-card"
                 data-dive-id="<?php echo esc_attr( $dive->id ); ?>"
                 <?php if ( $has_coords ) : ?>
                 data-lat="<?php echo esc_attr( $dive->site_latitude ); ?>"
                 data-lng="<?php echo esc_attr( $dive->site_longitude ); ?>"
                 <?php endif; ?>>
                <div class="sd-dive-card-left">
                    <div class="sd-dive-num">#<?php echo esc_html( $dive->dive_number ?: $dive->id ); ?></div>
                    <div class="sd-dive-date"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $dive->dive_date ) ) ); ?></div>
                </div>
                <div class="sd-dive-card-center">
                    <div class="sd-dive-site"><?php echo esc_html( $dive->site_name ); ?></div>
                    <div class="sd-dive-meta">
                        <?php if ( $dive->max_depth ) : ?>
                            <span class="sd-meta-item">
                                <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="2" x2="8" y2="14"/><polyline points="4 10 8 14 12 10"/></svg>
                                <?php echo esc_html( $dive->max_depth ); ?>m
                            </span>
                        <?php endif; ?>
                        <?php if ( $dive->dive_time ) : ?>
                            <span class="sd-meta-item">
                                <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><circle cx="8" cy="8" r="6"/><polyline points="8 4 8 8 11 9"/></svg>
                                <?php echo esc_html( $dive->dive_time ); ?>'
                            </span>
                        <?php endif; ?>
                        <?php if ( $dive->temp_water ) : ?>
                            <span class="sd-meta-item"><?php echo esc_html( $dive->temp_water ); ?>°C</span>
                        <?php endif; ?>
                        <?php if ( $has_diabetes ) : ?>
                            <span class="sd-meta-badge sd-meta-diabetes">
                                <svg viewBox="0 0 16 16" width="10" height="10" fill="currentColor"><path d="M8 1C8 1 3 6 3 9a5 5 0 0 0 10 0c0-3-5-8-5-8z"/></svg>
                                GLI
                            </span>
                        <?php endif; ?>
                        <?php if ( $edit_count > 0 ) : ?>
                            <span class="sd-meta-badge sd-meta-edited"><?php echo intval( $edit_count ); ?> mod.</span>
                        <?php endif; ?>
                    </div>
                    <?php if ( $can_view_all && ! empty( $dive->diver_name ) ) : ?>
                        <div class="sd-dive-diver"><?php echo esc_html( $dive->diver_name ); ?></div>
                    <?php endif; ?>
                </div>
                <div class="sd-dive-card-right sd-dive-actions">
                    <button type="button" class="sd-btn-detail sd-btn-card-action" data-dive-id="<?php echo esc_attr( $dive->id ); ?>" title="<?php esc_attr_e( 'Dettagli', 'sd-logbook' ); ?>">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <?php esc_html_e( 'Dettagli', 'sd-logbook' ); ?>
                    </button>
                    <button type="button" class="sd-btn-edit-dive sd-btn-card-action sd-btn-card-edit" data-dive-id="<?php echo esc_attr( $dive->id ); ?>" title="<?php esc_attr_e( 'Modifica', 'sd-logbook' ); ?>">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        <?php esc_html_e( 'Modifica', 'sd-logbook' ); ?>
                    </button>
                    <button type="button" class="sd-btn-history-dive sd-btn-card-action sd-btn-card-history" data-dive-id="<?php echo esc_attr( $dive->id ); ?>" title="<?php esc_attr_e( 'Storico modifiche', 'sd-logbook' ); ?>">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?php esc_html_e( 'Storico', 'sd-logbook' ); ?>
                    </button>
                    <button type="button"
                        class="sd-btn-card-action sd-btn-card-map<?php echo $has_coords ? ' sd-btn-card-map--active sd-btn-open-map' : ' sd-btn-card-map--disabled'; ?>"
                        <?php if ( $has_coords ) : ?>
                        data-lat="<?php echo esc_attr( $dive->site_latitude ); ?>"
                        data-lng="<?php echo esc_attr( $dive->site_longitude ); ?>"
                        data-title="<?php echo esc_attr( $dive->site_name ); ?>"
                        <?php endif; ?>
                        title="<?php echo $has_coords ? esc_attr__( 'Mostra posizione', 'sd-logbook' ) : esc_attr__( 'Posizione non disponibile', 'sd-logbook' ); ?>"
                        <?php echo $has_coords ? '' : 'disabled'; ?>>
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- PANNELLO MODIFICA (inline, nascosto) -->
    <!-- ============================================================ -->
    <div class="sd-edit-panel" id="sd-edit-panel" style="display:none;">
        <div class="sd-edit-panel-header">
            <h3 id="sd-edit-panel-title"><?php esc_html_e( 'Modifica Immersione', 'sd-logbook' ); ?></h3>
            <button type="button" class="sd-edit-panel-close" id="sd-edit-panel-close">&times;</button>
        </div>
        <div class="sd-edit-panel-body" id="sd-edit-panel-body">
            <div class="sd-loading"><?php esc_html_e( 'Caricamento...', 'sd-logbook' ); ?></div>
        </div>
        <div class="sd-edit-panel-footer">
            <button type="button" class="sd-btn-save-edit" id="sd-btn-save-edit">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <?php esc_html_e( 'Salva Modifiche', 'sd-logbook' ); ?>
            </button>
            <button type="button" class="sd-btn-cancel-edit" id="sd-btn-cancel-edit">
                <?php esc_html_e( 'Chiudi', 'sd-logbook' ); ?>
            </button>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- PANNELLO STORICO (inline, nascosto) -->
    <!-- ============================================================ -->
    <div class="sd-history-panel" id="sd-history-panel" style="display:none;">
        <div class="sd-history-panel-header">
            <h3><?php esc_html_e( 'Storico modifiche', 'sd-logbook' ); ?></h3>
            <button type="button" class="sd-history-panel-close" id="sd-history-panel-close">&times;</button>
        </div>
        <div class="sd-history-panel-body" id="sd-history-panel-body">
            <div class="sd-loading"><?php esc_html_e( 'Caricamento...', 'sd-logbook' ); ?></div>
        </div>
    </div>

    <!-- Messaggi modifica -->
    <div class="sd-form-messages sd-edit-messages" id="sd-edit-messages" style="display:none;"></div>

</div>

<!-- ============================================================ -->
<!-- MODAL DETTAGLIO -->
<!-- ============================================================ -->
<div class="sd-modal-overlay" id="sd-modal-overlay" style="display:none;">
    <div class="sd-modal">
        <div class="sd-modal-header">
            <h3 id="sd-modal-title"><?php esc_html_e( 'Dettaglio Immersione', 'sd-logbook' ); ?></h3>
            <button type="button" class="sd-modal-close" id="sd-modal-close">&times;</button>
        </div>
        <div class="sd-modal-body" id="sd-modal-body">
            <div class="sd-loading"><?php esc_html_e( 'Caricamento...', 'sd-logbook' ); ?></div>
        </div>
        <div class="sd-modal-footer">
            <button type="button" class="sd-btn-delete-dive" id="sd-btn-delete-dive">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                <?php esc_html_e( 'Elimina', 'sd-logbook' ); ?>
            </button>
            <div style="display:flex;gap:8px;">
                <button type="button" class="sd-btn-modal-edit" id="sd-btn-modal-edit">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php esc_html_e( 'Modifica', 'sd-logbook' ); ?>
                </button>
                <button type="button" class="sd-btn-close-modal" id="sd-btn-close-modal">
                    <?php esc_html_e( 'Chiudi', 'sd-logbook' ); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL MAPPA (Leaflet) -->
<!-- ============================================================ -->
<div id="sd-map-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);z-index:100001;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:92%;max-width:720px;height:520px;background:#fff;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#0D47A1;color:#fff;flex-shrink:0;">
            <span id="sd-map-title" style="font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>
                <span id="sd-map-title-text"></span>
            </span>
            <button id="sd-map-close" style="background:rgba(255,255,255,0.2);border:none;color:#fff;font-size:22px;cursor:pointer;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;line-height:1;">&times;</button>
        </div>
        <div id="sd-map-container" style="flex:1;"></div>
    </div>
</div>
