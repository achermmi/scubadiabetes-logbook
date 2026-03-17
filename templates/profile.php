<?php
/**
 * Template: Profilo Subacqueo - Record multipli
 * @package SD_Logbook
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$role_badges_html = SD_Roles::render_badges_html( $user_id );
?>

<div class="sd-form-wrap">

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
            <svg viewBox="0 0 48 48" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2"><circle cx="24" cy="16" r="10"/><path d="M8 42c0-8 7-14 16-14s16 6 16 14"/></svg>
        </div>
        <div class="sd-form-header-text"><h2><?php esc_html_e( 'Il mio profilo subacqueo', 'sd-logbook' ); ?></h2></div>
    </div>

    <!-- ============================================================ -->
    <!-- CERTIFICAZIONI SUB (multi-record) -->
    <!-- ============================================================ -->
    <div class="sd-section">
        <div class="sd-section-title">
            <span class="sd-section-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></span>
            <?php esc_html_e( 'Certificazioni Sub', 'sd-logbook' ); ?>
        </div>

        <!-- Lista certificazioni esistenti -->
        <div class="sd-record-list" id="sd-cert-list">
            <?php if ( ! empty( $certifications ) ) : foreach ( $certifications as $idx => $cert ) : ?>
            <div class="sd-record-card" data-index="<?php echo $idx; ?>">
                <div class="sd-record-main">
                    <div class="sd-record-title"><?php echo esc_html( $cert['agency'] ); ?> — <?php echo esc_html( $cert['level'] ); ?></div>
                    <div class="sd-record-sub">
                        <?php if ( $cert['date'] ) echo esc_html( date_i18n('d/m/Y', strtotime($cert['date'])) ); ?>
                        <?php if ( $cert['number'] ) echo ' · N° ' . esc_html( $cert['number'] ); ?>
                    </div>
                </div>
                <div class="sd-record-actions">
                    <button type="button" class="sd-rec-btn sd-rec-edit" data-type="certification" data-index="<?php echo $idx; ?>" title="Modifica">✎</button>
                    <button type="button" class="sd-rec-btn sd-rec-delete" data-type="certification" data-index="<?php echo $idx; ?>" title="Elimina">✕</button>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Form inline per aggiungere -->
        <div class="sd-add-form" id="sd-cert-form" style="display:none;">
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Agenzia', 'sd-logbook' ); ?> *</label>
                    <select name="cert_agency">
                        <option value=""><?php esc_html_e( 'Seleziona...', 'sd-logbook' ); ?></option>
                        <?php foreach ( array('PADI','SSI','ESA','FIPSAS','CMAS','NAUI','BSAC','SDI/TDI','RAID','Altro') as $a ) : ?>
                        <option value="<?php echo esc_attr($a); ?>"><?php echo esc_html($a); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Livello', 'sd-logbook' ); ?> *</label>
                    <select name="cert_level">
                        <option value=""><?php esc_html_e( 'Seleziona...', 'sd-logbook' ); ?></option>
                        <?php foreach ( array('Open Water','Advanced Open Water','Rescue Diver','Divemaster','Instructor','Altro') as $l ) : ?>
                        <option value="<?php echo esc_attr($l); ?>"><?php echo esc_html($l); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Data', 'sd-logbook' ); ?></label>
                    <input type="date" name="cert_date">
                </div>
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'N° Brevetto', 'sd-logbook' ); ?></label>
                    <input type="text" name="cert_number" placeholder="<?php esc_attr_e('Opzionale','sd-logbook'); ?>">
                </div>
            </div>
            <div class="sd-add-form-actions">
                <button type="button" class="sd-btn-save-record" data-type="certification"><?php esc_html_e( 'Salva', 'sd-logbook' ); ?></button>
                <button type="button" class="sd-btn-cancel-record" data-form="sd-cert-form"><?php esc_html_e( 'Annulla', 'sd-logbook' ); ?></button>
            </div>
        </div>

        <button type="button" class="sd-btn-add-record" data-form="sd-cert-form">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?php esc_html_e( 'Aggiungi certificazione', 'sd-logbook' ); ?>
        </button>
    </div>

    <!-- ============================================================ -->
    <!-- IDONEITÀ MEDICA (multi-record + upload) -->
    <!-- ============================================================ -->
    <div class="sd-section">
        <div class="sd-section-title">
            <span class="sd-section-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
            <?php esc_html_e( 'Idoneità medica', 'sd-logbook' ); ?>
        </div>

        <div class="sd-record-list" id="sd-clearance-list">
            <?php if ( ! empty( $medical_clearances ) ) : foreach ( $medical_clearances as $idx => $cl ) :
                $expiry_ts = ! empty( $cl['expiry'] ) ? strtotime( $cl['expiry'] ) : 0;
                $days_left = $expiry_ts ? round( ( $expiry_ts - time() ) / 86400 ) : null;
                $status_class = '';
                if ( $days_left !== null ) {
                    if ( $days_left < 0 ) $status_class = 'sd-record-expired';
                    elseif ( $days_left <= 30 ) $status_class = 'sd-record-expiring';
                    else $status_class = 'sd-record-valid';
                }
            ?>
            <div class="sd-record-card <?php echo $status_class; ?>" data-index="<?php echo $idx; ?>">
                <div class="sd-record-main">
                    <div class="sd-record-title">
                        <?php echo esc_html( date_i18n('d/m/Y', strtotime($cl['date'])) ); ?>
                        <?php if ( $cl['expiry'] ) : ?>
                            → <?php echo esc_html( date_i18n('d/m/Y', strtotime($cl['expiry'])) ); ?>
                        <?php endif; ?>
                        <?php if ( $days_left !== null ) : ?>
                            <?php if ( $days_left < 0 ) : ?>
                                <span class="sd-status-tag sd-tag-expired"><?php esc_html_e('SCADUTA','sd-logbook'); ?></span>
                            <?php elseif ( $days_left <= 30 ) : ?>
                                <span class="sd-status-tag sd-tag-expiring"><?php printf( esc_html__('%d gg','sd-logbook'), $days_left ); ?></span>
                            <?php else : ?>
                                <span class="sd-status-tag sd-tag-valid"><?php esc_html_e('VALIDA','sd-logbook'); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="sd-record-sub">
                        <?php if ( $cl['type'] ) echo esc_html( $cl['type'] ) . ' · '; ?>
                        <?php if ( $cl['doctor'] ) echo 'Dr. ' . esc_html( $cl['doctor'] ); ?>
                        <?php if ( ! empty( $cl['doc'] ) ) : ?>
                            · <a href="<?php echo esc_url( $cl['doc']['url'] ); ?>" target="_blank" class="sd-doc-link">📎 <?php echo esc_html( $cl['doc']['name'] ); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="sd-record-actions">
                    <button type="button" class="sd-rec-btn sd-rec-edit" data-type="medical_clearance" data-index="<?php echo $idx; ?>" title="Modifica">✎</button>
                    <button type="button" class="sd-rec-btn sd-rec-delete" data-type="medical_clearance" data-index="<?php echo $idx; ?>">✕</button>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="sd-add-form" id="sd-clearance-form" style="display:none;">
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Data rilascio', 'sd-logbook' ); ?> *</label>
                    <input type="date" name="clearance_date">
                </div>
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Scadenza', 'sd-logbook' ); ?></label>
                    <input type="date" name="clearance_expiry">
                </div>
            </div>
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Tipo visita', 'sd-logbook' ); ?></label>
                    <select name="clearance_type">
                        <option value=""><?php esc_html_e( 'Seleziona...', 'sd-logbook' ); ?></option>
                        <option value="sportiva"><?php esc_html_e( 'Sportiva agonistica', 'sd-logbook' ); ?></option>
                        <option value="non_agonistica"><?php esc_html_e( 'Sportiva non agonistica', 'sd-logbook' ); ?></option>
                        <option value="iperbarica"><?php esc_html_e( 'Iperbarica', 'sd-logbook' ); ?></option>
                        <option value="altro"><?php esc_html_e( 'Altro', 'sd-logbook' ); ?></option>
                    </select>
                </div>
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Medico', 'sd-logbook' ); ?></label>
                    <input type="text" name="clearance_doctor" placeholder="<?php esc_attr_e('Nome medico','sd-logbook'); ?>">
                </div>
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'Documento (PDF, JPG, PNG, ZIP — max 5 MB)', 'sd-logbook' ); ?></label>
                <input type="file" name="clearance_doc" accept=".pdf,.jpg,.jpeg,.png,.zip" class="sd-file-input-inline">
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'Note', 'sd-logbook' ); ?></label>
                <input type="text" name="clearance_notes">
            </div>
            <div class="sd-add-form-actions">
                <button type="button" class="sd-btn-save-record" data-type="medical_clearance"><?php esc_html_e( 'Salva', 'sd-logbook' ); ?></button>
                <button type="button" class="sd-btn-cancel-record" data-form="sd-clearance-form"><?php esc_html_e( 'Annulla', 'sd-logbook' ); ?></button>
            </div>
        </div>

        <button type="button" class="sd-btn-add-record" data-form="sd-clearance-form">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?php esc_html_e( 'Aggiungi idoneità', 'sd-logbook' ); ?>
        </button>
    </div>

    <!-- ============================================================ -->
    <!-- CONTATTI EMERGENZA (multi-record) -->
    <!-- ============================================================ -->
    <div class="sd-section">
        <div class="sd-section-title">
            <span class="sd-section-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.81.36 1.6.67 2.36a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.76.31 1.55.54 2.36.67A2 2 0 0 1 22 16.92z"/></svg></span>
            <?php esc_html_e( 'Contatti di emergenza', 'sd-logbook' ); ?>
        </div>

        <div class="sd-record-list" id="sd-contact-list">
            <?php if ( ! empty( $emergency_contacts ) ) : foreach ( $emergency_contacts as $idx => $ct ) : ?>
            <div class="sd-record-card" data-index="<?php echo $idx; ?>">
                <div class="sd-record-main">
                    <div class="sd-record-title"><?php echo esc_html( $ct['name'] ); ?></div>
                    <div class="sd-record-sub">
                        <?php echo esc_html( $ct['phone'] ); ?>
                        <?php if ( ! empty( $ct['email'] ) ) echo ' · <a href="mailto:' . esc_attr( $ct['email'] ) . '">' . esc_html( $ct['email'] ) . '</a>'; ?>
                        <?php if ( $ct['relationship'] ) echo ' · ' . esc_html( $ct['relationship'] ); ?>
                        <?php if ( ! empty( $ct['notes'] ) ) echo '<br><em>' . esc_html( $ct['notes'] ) . '</em>'; ?>
                    </div>
                </div>
                <div class="sd-record-actions">
                    <a href="tel:<?php echo esc_attr( $ct['phone'] ); ?>" class="sd-rec-btn sd-rec-call" title="Chiama">📞</a>
                    <button type="button" class="sd-rec-btn sd-rec-edit" data-type="emergency_contact" data-index="<?php echo $idx; ?>" title="Modifica">✎</button>
                    <button type="button" class="sd-rec-btn sd-rec-delete" data-type="emergency_contact" data-index="<?php echo $idx; ?>">✕</button>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="sd-add-form" id="sd-contact-form" style="display:none;">
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Nome e cognome', 'sd-logbook' ); ?> *</label>
                    <input type="text" name="contact_name">
                </div>
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Telefono', 'sd-logbook' ); ?> *</label>
                    <input type="tel" name="contact_phone" placeholder="+41 79...">
                </div>
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'E-mail', 'sd-logbook' ); ?></label>
                <input type="email" name="contact_email">
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'Relazione', 'sd-logbook' ); ?></label>
                <select name="contact_relationship">
                    <option value=""><?php esc_html_e( 'Seleziona...', 'sd-logbook' ); ?></option>
                    <?php foreach ( array(
                        'coniuge'   => __('Coniuge/Partner','sd-logbook'),
                        'genitore'  => __('Genitore','sd-logbook'),
                        'figlio'    => __('Figlio/a','sd-logbook'),
                        'fratello'  => __('Fratello/Sorella','sd-logbook'),
                        'amico'     => __('Amico/a','sd-logbook'),
                        'medico'    => __('Medico curante','sd-logbook'),
                        'altro'     => __('Altro','sd-logbook'),
                    ) as $val => $lab ) : ?>
                        <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($lab); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'Note', 'sd-logbook' ); ?></label>
                <textarea name="contact_notes" rows="2" placeholder="<?php esc_attr_e('Note aggiuntive','sd-logbook'); ?>"></textarea>
            </div>
            <div class="sd-add-form-actions">
                <button type="button" class="sd-btn-save-record" data-type="emergency_contact"><?php esc_html_e( 'Salva', 'sd-logbook' ); ?></button>
                <button type="button" class="sd-btn-cancel-record" data-form="sd-contact-form"><?php esc_html_e( 'Annulla', 'sd-logbook' ); ?></button>
            </div>
        </div>

        <button type="button" class="sd-btn-add-record" data-form="sd-contact-form">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?php esc_html_e( 'Aggiungi contatto', 'sd-logbook' ); ?>
        </button>
    </div>

    <!-- ============================================================ -->
    <!-- CONDIVISIONE DATI PER LA RICERCA (tutti gli utenti) -->
    <!-- ============================================================ -->
    <div class="sd-section">
        <div class="sd-section-title">
            <span class="sd-section-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg></span>
            <?php esc_html_e( 'Condivisione dati per la ricerca', 'sd-logbook' ); ?>
        </div>

        <form id="sd-sharing-form" class="sd-dive-form" novalidate>
            <input type="hidden" name="action" value="sd_save_sharing_preference">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'sd_profile_nonce' ); ?>">
            <?php
            $current_shared = 1;
            if ( $diabetes_profile && isset( $diabetes_profile->default_shared_for_research ) ) {
                $current_shared = (int) $diabetes_profile->default_shared_for_research;
            } else {
                // Check from DB if no diabetes profile loaded
                global $wpdb;
                $db_share       = new SD_Database();
                $share_val      = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT default_shared_for_research FROM {$db_share->table('diver_profiles')} WHERE user_id = %d",
                        $user_id
                    )
                );
                if ( null !== $share_val ) {
                    $current_shared = (int) $share_val;
                }
            }
            ?>
            <div class="sd-field">
                <label class="sd-checkbox-label">
                    <input type="checkbox" name="default_shared_for_research" value="1" <?php checked( $current_shared, 1 ); ?>>
                    <?php esc_html_e( 'Condividi le mie immersioni per la ricerca scientifica (impostazione predefinita)', 'sd-logbook' ); ?>
                </label>
                <p class="sd-field-help"><?php esc_html_e( 'Questa preferenza verrà applicata alle nuove immersioni. Puoi modificarla per ogni singola immersione.', 'sd-logbook' ); ?></p>
            </div>
            <div class="sd-add-form-actions">
                <button type="submit" class="sd-btn-save-record" id="sd-btn-save-sharing"><?php esc_html_e( 'Salva preferenza', 'sd-logbook' ); ?></button>
            </div>
        </form>
    </div>

    <!-- ============================================================ -->
    <!-- DATI DIABETE (solo diabetici — singolo record in DB) -->
    <!-- ============================================================ -->
    <?php if ( $is_diabetic ) : ?>
    <div class="sd-section sd-section-diabetes">
        <div class="sd-section-title sd-section-title-diabetes">
            <span class="sd-section-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C12 2 5 10 5 14a7 7 0 0 0 14 0c0-4-7-12-7-12z"/><line x1="12" y1="18" x2="12" y2="14"/><line x1="10" y1="16" x2="14" y2="16"/></svg></span>
            <?php esc_html_e( 'Dati diabete', 'sd-logbook' ); ?>
        </div>

        <?php $dp = $diabetes_profile; ?>
        <form id="sd-diabetes-form" class="sd-dive-form" novalidate>
            <input type="hidden" name="action" value="sd_save_diabetes_profile">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('sd_profile_nonce'); ?>">

            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Tipo diabete', 'sd-logbook' ); ?></label>
                    <select name="diabetes_type">
                        <?php foreach ( array('none'=>'Non specificato','tipo1'=>'Tipo 1','tipo2'=>'Tipo 2','altro'=>'Altro') as $v => $l ) : ?>
                        <option value="<?php echo $v; ?>" <?php selected( $dp->diabetes_type ?? 'none', $v ); ?>><?php echo esc_html($l); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Terapia', 'sd-logbook' ); ?></label>
                    <select name="therapy_type">
                        <?php foreach ( array('none'=>'Non specificata','mdi'=>'MDI (multi-iniettiva)','csii'=>'CSII (microinfusore)','orale'=>'Orale','mista'=>'Mista') as $v => $l ) : ?>
                        <option value="<?php echo $v; ?>" <?php selected( $dp->therapy_type ?? 'none', $v ); ?>><?php echo esc_html($l); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Unità glicemia', 'sd-logbook' ); ?></label>
                    <div class="sd-unit-toggle">
                        <?php $current_unit = $dp->glycemia_unit ?? 'mg/dl'; ?>
                        <button type="button" class="sd-unit-btn <?php echo $current_unit === 'mg/dl' ? 'active' : ''; ?>" data-value="mg/dl">mg/dL</button>
                        <button type="button" class="sd-unit-btn <?php echo $current_unit === 'mmol/l' ? 'active' : ''; ?>" data-value="mmol/l">mmol/L</button>
                    </div>
                    <input type="hidden" name="glycemia_unit" value="<?php echo esc_attr( $current_unit ); ?>">
                </div>
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'HbA1c (%)', 'sd-logbook' ); ?></label>
                    <input type="number" name="hba1c_last" step="0.1" min="3" max="20" value="<?php echo esc_attr( $dp->hba1c_last ?? '' ); ?>">
                </div>
            </div>
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Data HbA1c', 'sd-logbook' ); ?></label>
                    <input type="date" name="hba1c_date" value="<?php echo esc_attr( $dp->hba1c_date ?? '' ); ?>">
                </div>
            </div>
            <div class="sd-field">
                <label><input type="checkbox" name="uses_cgm" value="1" <?php checked( $dp->uses_cgm ?? 0, 1 ); ?>> <?php esc_html_e( 'Utilizzo CGM', 'sd-logbook' ); ?></label>
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'Dispositivo CGM', 'sd-logbook' ); ?></label>
                <select name="cgm_device">
                    <option value=""><?php esc_html_e('Seleziona...','sd-logbook'); ?></option>
                    <?php foreach ( array('FreeStyle Libre 2','FreeStyle Libre 3','Dexcom G6','Dexcom G7','Dexcom ONE','Medtronic Guardian 4','Eversense E3','Altro') as $c ) : ?>
                    <option value="<?php echo esc_attr($c); ?>" <?php selected( $dp->cgm_device ?? '', $c ); ?>><?php echo esc_html($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'Microinfusore', 'sd-logbook' ); ?></label>
                <input type="text" name="insulin_pump_model" value="<?php echo esc_attr( $dp->insulin_pump_model ?? '' ); ?>" placeholder="<?php esc_attr_e('es: Medtronic 780G','sd-logbook'); ?>">
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'Note', 'sd-logbook' ); ?></label>
                <textarea name="diabetes_notes" rows="2"><?php echo esc_textarea( $dp->notes ?? '' ); ?></textarea>
            </div>
            <div class="sd-add-form-actions">
                <button type="submit" class="sd-btn-save-record" id="sd-btn-save-diabetes"><?php esc_html_e( 'Salva dati diabete', 'sd-logbook' ); ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Messaggi globali -->
    <div class="sd-form-messages sd-profile-messages" id="sd-profile-messages" style="display:none;"></div>

</div>
