<?php
/**
 * Template: Pannello Medico / Staff
 * @package SD_Logbook
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$role_badges_html = SD_Roles::render_badges_html( get_current_user_id() );
?>

<div class="sd-form-wrap sd-medical-wrap">

    <!-- User bar -->
    <div class="sd-user-bar">
        <div class="sd-user-avatar"><?php echo get_avatar( get_current_user_id(), 40, '', $display_name, array('class'=>'sd-avatar-img') ); ?></div>
        <div class="sd-user-info">
            <span class="sd-user-name"><?php echo esc_html( $display_name ); ?></span>
            <span class="sd-user-badges"><?php echo $role_badges_html; ?></span>
        </div>
    </div>

    <!-- Stats -->
    <div class="sd-stats-grid">
        <div class="sd-stat-card">
            <div class="sd-stat-value"><?php echo $total_divers; ?></div>
            <div class="sd-stat-label"><?php esc_html_e( 'Subacquei', 'sd-logbook' ); ?></div>
        </div>
        <div class="sd-stat-card">
            <div class="sd-stat-value"><?php echo $diabetic_count; ?></div>
            <div class="sd-stat-label"><?php esc_html_e( 'Diabetici', 'sd-logbook' ); ?></div>
        </div>
        <div class="sd-stat-card">
            <div class="sd-stat-value"><?php echo $total_dives; ?></div>
            <div class="sd-stat-label"><?php esc_html_e( 'Immersioni totali', 'sd-logbook' ); ?></div>
        </div>
    </div>

    <!-- Actions bar -->
    <div class="sd-actions-bar">
        <h2 class="sd-dash-title"><?php esc_html_e( 'Subacquei registrati', 'sd-logbook' ); ?></h2>
        <div class="sd-actions-buttons">
            <!-- Filtro -->
            <select id="sd-filter-role" class="sd-filter-select">
                <option value="all"><?php esc_html_e( 'Tutti', 'sd-logbook' ); ?></option>
                <option value="diabetic"><?php esc_html_e( 'Solo diabetici', 'sd-logbook' ); ?></option>
                <option value="non-diabetic"><?php esc_html_e( 'Non diabetici', 'sd-logbook' ); ?></option>
            </select>
            <?php if ( SD_Roles::can_export_all( get_current_user_id() ) ) : ?>
            <button type="button" class="sd-btn-export" id="sd-btn-research-export">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?php esc_html_e( 'Export ricerca', 'sd-logbook' ); ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Diver list -->
    <div class="sd-diver-list" id="sd-diver-list">
        <?php foreach ( $divers as $diver ) : ?>
        <?php if ( $diver->dive_count < 1 ) continue; ?>
        <div class="sd-diver-card" data-diver-id="<?php echo esc_attr( $diver->ID ); ?>" data-diabetic="<?php echo $diver->is_diabetic ? '1' : '0'; ?>">
            <div class="sd-diver-card-avatar">
                <?php echo get_avatar( $diver->ID, 36, '', $diver->full_name, array('class'=>'sd-avatar-img') ); ?>
            </div>
            <div class="sd-diver-card-info">
                <div class="sd-diver-card-name">
                    <?php echo esc_html( $diver->full_name ); ?>
                    <?php if ( $diver->is_diabetic ) : ?>
                        <span class="sd-meta-badge sd-meta-diabetes">
                            <svg viewBox="0 0 16 16" width="10" height="10" fill="currentColor"><path d="M8 1C8 1 3 6 3 9a5 5 0 0 0 10 0c0-3-5-8-5-8z"/></svg>
                            DM
                        </span>
                    <?php endif; ?>
                </div>
                <div class="sd-diver-card-meta">
                    <?php echo esc_html( $diver->dive_count ); ?> <?php esc_html_e( 'immersioni', 'sd-logbook' ); ?>
                    <?php if ( $diver->last_dive ) : ?>
                        · <?php esc_html_e( 'ultima', 'sd-logbook' ); ?>: <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $diver->last_dive ) ) ); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sd-diver-card-arrow">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

</div>

<!-- Pannello dettaglio subacqueo (slide-in) -->
<div class="sd-panel-overlay" id="sd-panel-overlay" style="display:none;">
    <div class="sd-panel">
        <div class="sd-panel-header">
            <button type="button" class="sd-panel-back" id="sd-panel-back">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <h3 id="sd-panel-title"><?php esc_html_e( 'Dettaglio subacqueo', 'sd-logbook' ); ?></h3>
        </div>
        <div class="sd-panel-body" id="sd-panel-body">
            <div class="sd-loading"><?php esc_html_e( 'Caricamento...', 'sd-logbook' ); ?></div>
        </div>
    </div>
</div>
