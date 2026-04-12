<?php
/**
 * Template: Modifica Immersioni
 *
 * Variabili: $user_id, $is_diabetic, $dives
 * @package SD_Logbook
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$current_user = wp_get_current_user();
$display_name = trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name;
$role_badges_html = SD_Roles::render_badges_html( $user_id );
?>

<div class="sd-form-wrap sd-edit-wrap">

    <!-- User bar -->
    <div class="sd-user-bar">
        <div class="sd-user-avatar"><?php echo get_avatar( $user_id, 40, '', $display_name, array('class'=>'sd-avatar-img') ); ?></div>
        <div class="sd-user-info">
            <span class="sd-user-name"><?php echo esc_html( $display_name ); ?></span>
            <span class="sd-user-badges"><?php echo $role_badges_html; ?></span>
        </div>
    </div>

    <div class="sd-form-header">
        <div class="sd-form-header-icon">
            <svg viewBox="0 0 48 48" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M32 6l10 10-24 24H8V30z"/>
                <line x1="26" y1="12" x2="36" y2="22"/>
            </svg>
        </div>
        <div class="sd-form-header-text">
            <h2><?php esc_html_e( 'Le mie immersioni', 'sd-logbook' ); ?></h2>
            <span class="sd-form-subtitle"><?php esc_html_e( 'Consulta e modifica le tue immersioni registrate', 'sd-logbook' ); ?></span>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- LISTA IMMERSIONI -->
    <!-- ============================================================ -->
    <?php if ( empty( $dives ) ) : ?>
        <div class="sd-empty-state">
            <p><?php esc_html_e( 'Nessuna immersione registrata.', 'sd-logbook' ); ?></p>
        </div>
    <?php else : ?>
    <div class="sd-edit-list" id="sd-edit-list">
        <?php foreach ( $dives as $dive ) : ?>
        <div class="sd-edit-card" data-dive-id="<?php echo esc_attr( $dive->id ); ?>">
            <div class="sd-edit-card-left">
                <div class="sd-edit-num">#<?php echo esc_html( $dive->dive_number ?: $dive->id ); ?></div>
                <div class="sd-edit-date"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $dive->dive_date ) ) ); ?></div>
            </div>
            <div class="sd-edit-card-center">
                <div class="sd-edit-site"><?php echo esc_html( $dive->site_name ); ?></div>
                <div class="sd-edit-meta">
                    <?php if ( $dive->max_depth ) : ?>
                        <span><?php echo esc_html( $dive->max_depth ); ?>m</span>
                    <?php endif; ?>
                    <?php if ( $dive->dive_time ) : ?>
                        <span><?php echo esc_html( $dive->dive_time ); ?>'</span>
                    <?php endif; ?>
                    <?php if ( $dive->has_diabetes ) : ?>
                        <span class="sd-meta-badge sd-meta-diabetes">GLI</span>
                    <?php endif; ?>
                    <?php if ( $dive->edit_count > 0 ) : ?>
                        <span class="sd-meta-badge sd-meta-edited" title="<?php printf( esc_attr__( '%d modifiche', 'sd-logbook' ), $dive->edit_count ); ?>">
                            <?php echo intval( $dive->edit_count ); ?> mod.
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sd-edit-card-right">
                <button type="button" class="sd-btn-edit-dive" data-dive-id="<?php echo esc_attr( $dive->id ); ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php esc_html_e( 'Modifica', 'sd-logbook' ); ?>
                </button>
                <button type="button" class="sd-btn-history-dive" data-dive-id="<?php echo esc_attr( $dive->id ); ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <?php esc_html_e( 'Storico', 'sd-logbook' ); ?>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- PANNELLO MODIFICA (modale inline, nascosto) -->
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
    <!-- PANNELLO STORICO (modale inline, nascosto) -->
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

    <!-- Messaggi -->
    <div class="sd-form-messages sd-edit-messages" id="sd-edit-messages" style="display:none;"></div>

</div>
