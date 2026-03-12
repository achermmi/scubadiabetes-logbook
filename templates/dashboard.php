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
if (! defined('ABSPATH') ) { exit;
}

$role_badges_html = SD_Roles::render_badges_html($user_id);
?>

<div class="sd-form-wrap sd-dashboard-wrap">

    <!-- User bar -->
    <div class="sd-user-bar">
        <div class="sd-user-avatar">
            <?php echo get_avatar($user_id, 40, '', $display_name, array( 'class' => 'sd-avatar-img' )); ?>
        </div>
        <div class="sd-user-info">
            <span class="sd-user-name"><?php echo esc_html($display_name); ?></span>
            <span class="sd-user-badges"><?php echo $role_badges_html; ?></span>
        </div>
        <div class="sd-user-dive-count">
            <span class="sd-count-number"><?php echo intval($stats->total_dives); ?></span>
            <span class="sd-count-label"><?php esc_html_e('immersioni', 'sd-logbook'); ?></span>
        </div>
    </div>

    <!-- Stats cards -->
    <div class="sd-stats-grid">
        <div class="sd-stat-card">
            <div class="sd-stat-value"><?php echo $stats->max_depth ? esc_html($stats->max_depth) . 'm' : '—'; ?></div>
            <div class="sd-stat-label"><?php esc_html_e('Prof. massima', 'sd-logbook'); ?></div>
        </div>
        <div class="sd-stat-card">
            <div class="sd-stat-value">
                <?php
                if ($stats->total_time ) {
                    $hours = floor($stats->total_time / 60);
                    $mins  = $stats->total_time % 60;
                    printf('%02d h %02d min', $hours, $mins);
                } else {
                    echo '—';
                }
                ?>
            </div>
            <div class="sd-stat-label"><?php esc_html_e('Tempo totale', 'sd-logbook'); ?></div>
        </div>
        <div class="sd-stat-card">
            <div class="sd-stat-value"><?php echo $stats->unique_sites ?: '—'; ?></div>
            <div class="sd-stat-label"><?php esc_html_e('Siti visitati', 'sd-logbook'); ?></div>
        </div>
        <div class="sd-stat-card">
            <div class="sd-stat-value"><?php echo $stats->last_dive_date ? esc_html(date_i18n('d/m/Y', strtotime($stats->last_dive_date))) : '—'; ?></div>
            <div class="sd-stat-label"><?php esc_html_e('Ultima immersione', 'sd-logbook'); ?></div>
        </div>
    </div>

    <!-- Actions bar -->
    <div class="sd-actions-bar">
        <h2 class="sd-dash-title">
            <?php
            if ($can_view_all ) {
                esc_html_e('Tutte le immersioni', 'sd-logbook');
            } else {
                esc_html_e('Le mie immersioni', 'sd-logbook');
            }
            ?>
        </h2>
        <div class="sd-actions-buttons">
            <button type="button" class="sd-btn-export" id="sd-btn-export">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?php esc_html_e('Export CSV', 'sd-logbook'); ?>
            </button>
        </div>
    </div>

    <!-- Dive list -->
    <?php if (empty($dives) ) : ?>
        <div class="sd-empty-state">
            <svg viewBox="0 0 80 80" width="64" height="64" fill="none" stroke="#CBD5E1" stroke-width="2">
                <circle cx="40" cy="40" r="35"/>
                <path d="M40 20c-3 0-5 2-5 5v10c0 3 1 5 2 7v8c0 3 1 5 3 5s3-2 3-5v-8c1-2 2-4 2-7V25c0-3-2-5-5-5z"/>
            </svg>
            <p><?php esc_html_e('Nessuna immersione registrata.', 'sd-logbook'); ?></p>
        </div>
    <?php else : ?>
        <div class="sd-dive-list">
            <?php foreach ( $dives as $dive ) :
                // Check if has diabetes data
                global $wpdb;
                $db_check = new SD_Database();
                $has_diabetes = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$db_check->table('dive_diabetes')} WHERE dive_id = %d",
                        $dive->id
                    ) 
                );
                ?>
            <div class="sd-dive-card" data-dive-id="<?php echo esc_attr($dive->id); ?>">
                <div class="sd-dive-card-left">
                    <div class="sd-dive-num">#<?php echo esc_html($dive->dive_number ?: $dive->id); ?></div>
                    <div class="sd-dive-date"><?php echo esc_html(date_i18n('d/m/Y', strtotime($dive->dive_date))); ?></div>
                </div>
                <div class="sd-dive-card-center">
                    <div class="sd-dive-site"><?php echo esc_html($dive->site_name); ?></div>
                    <div class="sd-dive-meta">
                        <?php if ($dive->max_depth ) : ?>
                            <span class="sd-meta-item">
                                <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="2" x2="8" y2="14"/><polyline points="4 10 8 14 12 10"/></svg>
                                <?php echo esc_html($dive->max_depth); ?>m
                            </span>
                        <?php endif; ?>
                        <?php if ($dive->dive_time ) : ?>
                            <span class="sd-meta-item">
                                <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><circle cx="8" cy="8" r="6"/><polyline points="8 4 8 8 11 9"/></svg>
                                <?php echo esc_html($dive->dive_time); ?>'
                            </span>
                        <?php endif; ?>
                        <?php if ($dive->temp_water ) : ?>
                            <span class="sd-meta-item"><?php echo esc_html($dive->temp_water); ?>°C</span>
                        <?php endif; ?>
                        <?php if ($has_diabetes ) : ?>
                            <span class="sd-meta-badge sd-meta-diabetes">
                                <svg viewBox="0 0 16 16" width="10" height="10" fill="currentColor"><path d="M8 1C8 1 3 6 3 9a5 5 0 0 0 10 0c0-3-5-8-5-8z"/></svg>
                                GLI
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($can_view_all && ! empty($dive->diver_name) ) : ?>
                        <div class="sd-dive-diver"><?php echo esc_html($dive->diver_name); ?></div>
                    <?php endif; ?>
                </div>
                <div class="sd-dive-card-right">
                    <button type="button" class="sd-btn-detail" data-dive-id="<?php echo esc_attr($dive->id); ?>" title="<?php esc_attr_e('Dettaglio', 'sd-logbook'); ?>">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <?php esc_html_e('Dettagli', 'sd-logbook'); ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Modal dettaglio immersione -->
<div class="sd-modal-overlay" id="sd-modal-overlay" style="display:none;">
    <div class="sd-modal">
        <div class="sd-modal-header">
            <h3 id="sd-modal-title"><?php esc_html_e('Dettaglio Immersione', 'sd-logbook'); ?></h3>
            <button type="button" class="sd-modal-close" id="sd-modal-close">&times;</button>
        </div>
        <div class="sd-modal-body" id="sd-modal-body">
            <div class="sd-loading"><?php esc_html_e('Caricamento...', 'sd-logbook'); ?></div>
        </div>
        <div class="sd-modal-footer">
            <button type="button" class="sd-btn-delete-dive" id="sd-btn-delete-dive">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                <?php esc_html_e('Elimina', 'sd-logbook'); ?>
            </button>
            <button type="button" class="sd-btn-close-modal" id="sd-btn-close-modal">
                <?php esc_html_e('Chiudi', 'sd-logbook'); ?>
            </button>
        </div>
    </div>
</div>
