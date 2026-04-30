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
    <!-- DATI PERSONALI (sezione collassabile) -->
    <!-- ============================================================ -->
    <?php $dp = $diabetes_profile; ?>
    <div class="sd-section sd-section-collapsible sd-section-collapsed">
        <div class="sd-section-title sd-section-toggle">
            <span class="sd-section-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
            <?php esc_html_e( 'Dati personali', 'sd-logbook' ); ?>
            <span class="sd-section-chevron"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></span>
        </div>

        <div class="sd-section-body">
            <!-- ID Ricerca -->
            <?php if ( ! empty( $dp->id_for_research ) && ! empty( $dp->default_shared_for_research ) ) : ?>
            <div class="sd-research-id-bar">
                <span class="sd-research-id-label"><?php esc_html_e( 'ID Ricerca', 'sd-logbook' ); ?></span>
                <code class="sd-research-id-value"><?php echo esc_html( $dp->id_for_research ); ?></code>
                <span class="sd-research-id-note"><?php esc_html_e( '(usato solo a scopo di ricerca)', 'sd-logbook' ); ?></span>
            </div>
            <?php endif; ?>

            <!-- Campi WordPress (sola lettura) -->
            <div class="sd-personal-wp-fields">
                <div class="sd-field-row">
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Nome', 'sd-logbook' ); ?></label>
                        <input type="text" value="<?php echo esc_attr( $current_user->first_name ); ?>" disabled>
                    </div>
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Cognome', 'sd-logbook' ); ?></label>
                        <input type="text" value="<?php echo esc_attr( $current_user->last_name ); ?>" disabled>
                    </div>
                </div>
                <div class="sd-field-row">
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Ruolo', 'sd-logbook' ); ?></label>
                        <input type="text" value="<?php echo esc_attr( $user_role_display ); ?>" disabled>
                    </div>
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'E-mail', 'sd-logbook' ); ?></label>
                        <input type="email" value="<?php echo esc_attr( $current_user->user_email ); ?>" disabled>
                    </div>
                </div>
                <p class="sd-field-help"><?php esc_html_e( 'Nome, cognome, ruolo ed e-mail si modificano dal profilo WordPress.', 'sd-logbook' ); ?></p>
            </div>

            <!-- Form campi aggiuntivi -->
            <form id="sd-personal-form" class="sd-dive-form" novalidate>
                <input type="hidden" name="action" value="sd_save_personal_data">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'sd_profile_nonce' ); ?>">

                <div class="sd-field-row">
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'GSM', 'sd-logbook' ); ?></label>
                        <input type="tel" name="personal_gsm" value="<?php echo esc_attr( $dp->gsm ?? '' ); ?>" placeholder="+41 79…">
                    </div>
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Telefono', 'sd-logbook' ); ?></label>
                        <input type="tel" name="personal_phone" value="<?php echo esc_attr( $dp->phone ?? '' ); ?>" placeholder="+41 91…">
                    </div>
                </div>
                <div class="sd-field">
                    <label><?php esc_html_e( 'Via', 'sd-logbook' ); ?></label>
                    <input type="text" name="personal_address" value="<?php echo esc_attr( $dp->address ?? '' ); ?>">
                </div>
                <div class="sd-field-row">
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'CAP', 'sd-logbook' ); ?></label>
                        <input type="text" name="personal_zip" value="<?php echo esc_attr( $dp->zip ?? '' ); ?>">
                    </div>
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Località', 'sd-logbook' ); ?></label>
                        <input type="text" name="personal_city" value="<?php echo esc_attr( $dp->city ?? '' ); ?>">
                    </div>
                </div>
                <div class="sd-field-row">
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Data di nascita', 'sd-logbook' ); ?></label>
                        <input type="date" name="personal_birth_date" value="<?php echo esc_attr( $dp->birth_date ?? '' ); ?>">
                    </div>
                    <div class="sd-field sd-field-quarter">
                        <label><?php esc_html_e( 'Peso (Kg)', 'sd-logbook' ); ?></label>
                        <input type="number" name="personal_weight" step="0.5" min="20" max="300" value="<?php echo esc_attr( $dp->weight ?? '' ); ?>">
                    </div>
                    <div class="sd-field sd-field-quarter">
                        <label><?php esc_html_e( 'Altezza (cm)', 'sd-logbook' ); ?></label>
                        <input type="number" name="personal_height" step="1" min="100" max="250" value="<?php echo esc_attr( $dp->height ?? '' ); ?>">
                    </div>
                </div>

                <div class="sd-field-row">
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Sesso', 'sd-logbook' ); ?></label>
                        <select name="personal_gender">
                            <option value=""><?php esc_html_e( 'Seleziona...', 'sd-logbook' ); ?></option>
                            <?php foreach ( array('M'=> __('M (Maschio)','sd-logbook'),'F'=> __('F (Femmina)','sd-logbook'),'NB'=> __('NB (Non binario)','sd-logbook'),'U'=> __('U (Non specificato)','sd-logbook')) as $val => $lab ) : ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected( $dp->gender ?? '', $val ); ?>><?php echo esc_html($lab); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sd-field sd-field-half">
                        <label><?php esc_html_e( 'Gruppo sanguigno', 'sd-logbook' ); ?></label>
                        <select name="personal_blood_type">
                            <option value=""><?php esc_html_e( 'Seleziona...', 'sd-logbook' ); ?></option>
                            <?php foreach ( array('A+','A-','B+','B-','AB+','AB-','0+','0-') as $bt ) : ?>
                            <option value="<?php echo esc_attr($bt); ?>" <?php selected( $dp->blood_type ?? '', $bt ); ?>><?php echo esc_html($bt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php
                // Decode allergies
                $allergies = array();
                if ( ! empty( $dp->allergies ) ) {
                    $decoded = json_decode( $dp->allergies, true );
                    if ( is_array( $decoded ) ) { $allergies = $decoded; }
                }
                // Decode medications
                $medications = array();
                if ( ! empty( $dp->medications ) ) {
                    $decoded = json_decode( $dp->medications, true );
                    if ( is_array( $decoded ) ) { $medications = $decoded; }
                }
                ?>

                <!-- ALLERGIE -->
                <div class="sd-field">
                    <div class="sd-item-list-header">
                        <span><?php esc_html_e( 'Allergie', 'sd-logbook' ); ?></span>
                    </div>
                    <div class="sd-item-list" id="sd-allergies-list">
                        <?php foreach ( $allergies as $allergy ) : ?>
                        <div class="sd-list-item">
                            <span class="sd-item-name"><?php echo esc_html( $allergy ); ?></span>
                            <button type="button" class="sd-item-delete" title="<?php esc_attr_e( 'Elimina', 'sd-logbook' ); ?>">✕</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="sd-item-add-row">
                        <input type="text" id="sd-allergy-input" placeholder="<?php esc_attr_e( 'Nuova allergia…', 'sd-logbook' ); ?>">
                        <button type="button" id="sd-add-allergy-btn" class="sd-item-add-btn">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <?php esc_html_e( 'Aggiungi', 'sd-logbook' ); ?>
                        </button>
                    </div>
                    <input type="hidden" name="personal_allergies" id="sd-allergies-json">
                </div>

                <!-- MEDICAMENTI -->
                <div class="sd-field">
                    <div class="sd-item-list-header">
                        <span><?php esc_html_e( 'Medicamenti', 'sd-logbook' ); ?></span>
                        <span class="sd-item-col-label"><?php esc_html_e( 'Sospeso', 'sd-logbook' ); ?></span>
                    </div>
                    <div class="sd-item-list" id="sd-medications-list">
                        <?php foreach ( $medications as $med ) :
                            $med_name    = is_array( $med ) ? ( $med['name'] ?? '' ) : (string) $med;
                            $med_sospeso = is_array( $med ) && ! empty( $med['sospeso'] );
                        ?>
                        <div class="sd-list-item sd-med-item">
                            <span class="sd-item-name"><?php echo esc_html( $med_name ); ?></span>
                            <label class="sd-sospeso-label">
                                <input type="checkbox" class="sd-sospeso-cb" <?php checked( $med_sospeso ); ?>>
                            </label>
                            <button type="button" class="sd-item-delete" title="<?php esc_attr_e( 'Elimina', 'sd-logbook' ); ?>">✕</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="sd-item-add-row">
                        <input type="text" id="sd-medication-input" placeholder="<?php esc_attr_e( 'Nuovo medicamento…', 'sd-logbook' ); ?>">
                        <button type="button" id="sd-add-medication-btn" class="sd-item-add-btn">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <?php esc_html_e( 'Aggiungi', 'sd-logbook' ); ?>
                        </button>
                    </div>
                    <input type="hidden" name="personal_medications" id="sd-medications-json">
                </div>

                <div class="sd-add-form-actions">
                    <button type="submit" class="sd-btn-save-record" id="sd-btn-save-personal"><?php esc_html_e( 'Salva dati personali', 'sd-logbook' ); ?></button>
                </div>
            </form>
        </div>
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
            <div class="sd-record-card" data-index="<?php echo $idx; ?>"
                 data-doc-name="<?php echo esc_attr( $cert['doc']['name'] ?? '' ); ?>"
                 data-doc-url="<?php echo esc_attr( $cert['doc']['url'] ?? '' ); ?>">
                <div class="sd-record-main">
                    <div class="sd-record-title"><?php echo esc_html( $cert['agency'] ); ?> — <?php echo esc_html( $cert['level'] ); ?></div>
                    <div class="sd-record-sub">
                        <?php if ( $cert['date'] ) echo esc_html( date_i18n('d/m/Y', strtotime($cert['date'])) ); ?>
                        <?php if ( $cert['number'] ) echo ' · N° ' . esc_html( $cert['number'] ); ?>
                        <?php if ( ! empty( $cert['doc'] ) ) : ?>
                            · <a href="<?php echo esc_url( $cert['doc']['url'] ); ?>" target="_blank" class="sd-doc-link">📎 <?php echo esc_html( $cert['doc']['name'] ); ?></a>
                        <?php endif; ?>
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
                        <?php foreach ( array(
                            'PADI'    => 'PADI',
                            'SSI'     => 'SSI',
                            'ESA'     => 'ESA',
                            'FIPSAS'  => 'FIPSAS',
                            'CMAS'    => 'CMAS',
                            'NAUI'    => 'NAUI',
                            'BSAC'    => 'BSAC',
                            'SDI/TDI' => 'SDI/TDI',
                            'RAID'    => 'RAID',
                            'Altro'   => __( 'Altro', 'sd-logbook' ),
                        ) as $a_val => $a_lab ) : ?>
                        <option value="<?php echo esc_attr( $a_val ); ?>"><?php echo esc_html( $a_lab ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Livello', 'sd-logbook' ); ?> *</label>
                    <select name="cert_level">
                        <option value=""><?php esc_html_e( 'Seleziona...', 'sd-logbook' ); ?></option>
                        <?php foreach ( array(
                            'Open Water'          => __( 'Open Water', 'sd-logbook' ),
                            'Advanced Open Water' => __( 'Advanced Open Water', 'sd-logbook' ),
                            'BLS'                 => __( 'BLS', 'sd-logbook' ),
                            'Rescue Diver'        => __( 'Rescue Diver', 'sd-logbook' ),
                            'Divemaster'          => __( 'Divemaster', 'sd-logbook' ),
                            'Ecodiver'            => __( 'Ecodiver', 'sd-logbook' ),
                            'Instructor'          => __( 'Instructor', 'sd-logbook' ),
                            'Muta Stagna'         => __( 'Muta Stagna', 'sd-logbook' ),
                            'Nitrox'              => __( 'Nitrox', 'sd-logbook' ),
                            'Notturna'            => __( 'Notturna', 'sd-logbook' ),
                            'Altro'               => __( 'Altro', 'sd-logbook' ),
                        ) as $l_val => $l_lab ) : ?>
                        <option value="<?php echo esc_attr( $l_val ); ?>"><?php echo esc_html( $l_lab ); ?></option>
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
            <div class="sd-field">
                <label><?php esc_html_e( 'Documento brevetto (PDF, JPG, PNG — max 5 MB)', 'sd-logbook' ); ?></label>
                <input type="file" name="cert_doc" accept=".pdf,.jpg,.jpeg,.png" class="sd-file-input-inline">
                <div class="sd-cert-doc-current" style="display:none;font-size:12px;color:var(--sd-gray-500);margin-top:4px;"></div>
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
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'E-mail', 'sd-logbook' ); ?></label>
                    <input type="email" name="contact_email">
                </div>
                <div class="sd-field sd-field-half">
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
    <?php if ( SD_Roles::is_diabetic_diver( $user_id ) ) : ?>
    <div class="sd-section sd-section-diabetes">
        <div class="sd-section-title sd-section-title-diabetes">
            <span class="sd-section-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C12 2 5 10 5 14a7 7 0 0 0 14 0c0-4-7-12-7-12z"/><line x1="12" y1="18" x2="12" y2="14"/><line x1="10" y1="16" x2="14" y2="16"/></svg></span>
            <?php esc_html_e( 'Dati diabete', 'sd-logbook' ); ?>
        </div>

        <form id="sd-diabetes-form" class="sd-dive-form" novalidate>
            <input type="hidden" name="action" value="sd_save_diabetes_profile">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('sd_profile_nonce'); ?>">
            <?php
            $selected_diabetes_type = $dp->diabetes_type ?? 'non_diabetico';
            $selected_diabetes_type = in_array( $selected_diabetes_type, array( 'tipo1', 'tipo_1' ), true ) ? 'tipo_1' : $selected_diabetes_type;
            $selected_diabetes_type = in_array( $selected_diabetes_type, array( 'tipo2', 'tipo_2' ), true ) ? 'tipo_2' : $selected_diabetes_type;
            if ( in_array( $selected_diabetes_type, array( 'none', 'non_specificato' ), true ) ) {
                $selected_diabetes_type = 'altro';
            }

            $selected_therapy_type = $dp->therapy_type ?? 'none';
            if ( 'orale' === $selected_therapy_type ) {
                $selected_therapy_type = 'ipoglicemizzante_orale';
            }
            if ( 'mista' === $selected_therapy_type ) {
                $selected_therapy_type = 'iniettiva_non_insulinica';
            }
            ?>

            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Tipo diabete', 'sd-logbook' ); ?></label>
                    <select name="diabetes_type">
                        <?php foreach ( array(
                            'non_diabetico' => __( 'Non diabetico', 'sd-logbook' ),
                            'tipo_1'        => __( 'Tipo 1', 'sd-logbook' ),
                            'tipo_2'        => __( 'Tipo 2', 'sd-logbook' ),
                            'tipo_3c'       => __( 'Tipo 3c (pancreasectomia, pancreatite)', 'sd-logbook' ),
                            'lada'          => 'LADA',
                            'mody'          => 'MODY',
                            'midd'          => 'MIDD',
                            'altro'         => __( 'Altro', 'sd-logbook' ),
                        ) as $v => $l ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $selected_diabetes_type, $v ); ?>><?php echo esc_html( $l ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Terapia', 'sd-logbook' ); ?></label>
                    <select name="therapy_type" id="sd-therapy-type">
                        <?php foreach ( array(
                            'none'  => __( 'Non specificata', 'sd-logbook' ),
                            'mdi'   => __( 'MDI (multi-iniettiva)', 'sd-logbook' ),
                            'csii'  => __( 'CSII (microinfusore open-loop)', 'sd-logbook' ),
                            'ahcl'  => __( 'AHCL (microinfusore closed-loop)', 'sd-logbook' ),
                            'ipoglicemizzante_orale' => __( 'Ipoglicemizzante orale', 'sd-logbook' ),
                            'iniettiva_non_insulinica' => __( 'Iniettiva non insulinica', 'sd-logbook' ),
                        ) as $v => $l ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $selected_therapy_type, $v ); ?>><?php echo esc_html( $l ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Dettaglio terapia', 'sd-logbook' ); ?></label>
                    <select name="therapy_detail" id="sd-therapy-detail" data-current="<?php echo esc_attr( $dp->therapy_detail ?? '' ); ?>">
                        <option value=""><?php esc_html_e( 'Seleziona terapia prima', 'sd-logbook' ); ?></option>
                    </select>
                </div>
                <div class="sd-field sd-field-half" id="sd-therapy-detail-other-wrap" style="display:none;">
                    <label><?php esc_html_e( 'Altro (specificare)', 'sd-logbook' ); ?></label>
                    <input type="text" name="therapy_detail_other" id="sd-therapy-detail-other" value="<?php echo esc_attr( $dp->therapy_detail_other ?? '' ); ?>">
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
                    <label><?php esc_html_e( 'HbA1c', 'sd-logbook' ); ?></label>
                    <input type="number" name="hba1c_last" step="0.1" min="3" max="130" value="<?php echo esc_attr( $dp->hba1c_last ?? '' ); ?>">
                </div>
            </div>
            <div class="sd-field-row">
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Data HbA1c', 'sd-logbook' ); ?></label>
                    <input type="date" name="hba1c_date" value="<?php echo esc_attr( $dp->hba1c_date ?? '' ); ?>">
                </div>
                <div class="sd-field sd-field-half">
                    <label><?php esc_html_e( 'Unità HbA1c', 'sd-logbook' ); ?></label>
                    <select name="hba1c_unit">
                        <option value="percent" <?php selected( $dp->hba1c_unit ?? 'percent', 'percent' ); ?>>%</option>
                        <option value="mmol_mol" <?php selected( $dp->hba1c_unit ?? 'percent', 'mmol_mol' ); ?>>mmol/mol</option>
                    </select>
                </div>
            </div>
            <div class="sd-field">
                <label><input type="checkbox" name="uses_cgm" value="1" <?php checked( $dp->uses_cgm ?? 0, 1 ); ?>> <?php esc_html_e( 'Utilizzo CGM', 'sd-logbook' ); ?></label>
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'Dispositivo CGM', 'sd-logbook' ); ?></label>
                <select name="cgm_device">
                    <option value=""><?php esc_html_e('Seleziona...','sd-logbook'); ?></option>
                    <?php foreach ( array(
                        'Abbott FreeStyle Libre 2 / 2+'  => 'Abbott FreeStyle Libre 2 / 2+',
                        'Abbott FreeStyle Libre 3 / 3+'  => 'Abbott FreeStyle Libre 3 / 3+',
                        'Dexcom G6'          => 'Dexcom G6',
                        'Dexcom G7'          => 'Dexcom G7',
                        'Dexcom ONE'         => 'Dexcom ONE',
                        'Medtronic Guardian 3' => 'Medtronic Guardian 3',
                        'Medtronic Guardian 4' => 'Medtronic Guardian 4',
                        'Medtronic Simplera' => 'Medtronic Simplera',
                        'Accu-Chek SmartGuide' => 'Accu-Chek SmartGuide',
                        'Eversense E3'       => 'Eversense E3',
                        'Eversense 365'      => 'Eversense 365',
                        'Medtrum TouchCare Nano' => 'Medtrum TouchCare Nano',
                        'Altro'              => __( 'Altro', 'sd-logbook' ),
                    ) as $c_val => $c_lab ) : ?>
                    <option value="<?php echo esc_attr( $c_val ); ?>" <?php selected( $dp->cgm_device ?? '', $c_val ); ?>><?php echo esc_html( $c_lab ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'Centro diabetologico di riferimento', 'sd-logbook' ); ?></label>
                <input type="text" name="diabetology_center" value="<?php echo esc_attr( $dp->diabetology_center ?? '' ); ?>" placeholder="<?php esc_attr_e( 'es: Ospedale Regionale Lugano', 'sd-logbook' ); ?>">
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'Microinfusore', 'sd-logbook' ); ?></label>
                <select name="insulin_pump_model" id="sd-insulin-pump-model" data-current="<?php echo esc_attr( $dp->insulin_pump_model ?? '' ); ?>">
                    <option value=""><?php esc_html_e( 'Seleziona...', 'sd-logbook' ); ?></option>
                    <?php foreach ( array(
                        'Medtronic 780G' => 'Medtronic 780G',
                        'Omnipod DASH' => 'Omnipod DASH',
                        'Omnipod 5' => 'Omnipod 5',
                        'Ypsopump CamAPS FX' => 'Ypsopump CamAPS FX',
                        'Tandem Control IQ' => 'Tandem Control IQ',
                        'Tandem Mobi' => 'Tandem Mobi',
                        'Medtrum TouchCare Nano' => 'Medtrum TouchCare Nano',
                        'Diabeloop DBLG1' => 'Diabeloop DBLG1',
                        'Altro' => __( 'Altro', 'sd-logbook' ),
                    ) as $p_val => $p_lab ) : ?>
                    <option value="<?php echo esc_attr( $p_val ); ?>" <?php selected( $dp->insulin_pump_model ?? '', $p_val ); ?>><?php echo esc_html( $p_lab ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="insulin_pump_model_other" id="sd-insulin-pump-model-other" value="<?php echo esc_attr( $dp->insulin_pump_model_other ?? '' ); ?>" placeholder="<?php esc_attr_e('Altro microinfusore (specificare)','sd-logbook'); ?>" style="display:none;margin-top:8px;">
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

    <!-- ============================================================ -->
    <!-- SERVER CGM INTERNO SCUBADIABETES (solo diabetici) -->
    <!-- ============================================================ -->
    <?php if ( SD_Roles::is_diabetic_diver( $user_id ) ) :
        $ns_data    = SD_Nightscout::get_profile_data( $user_id );
        $ns_srv     = SD_Nightscout_Server::get_server_profile_data( $user_id );
    ?>
    <div class="sd-section sd-section-ns-server">
        <div class="sd-section-title">
            <span class="sd-section-icon">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/>
                    <line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
                </svg>
            </span>
            <?php esc_html_e( 'Server CGM ScubaDiabetes', 'sd-logbook' ); ?>
            <span class="sd-ns-badge sd-ns-badge-connected"><?php esc_html_e( 'Interno', 'sd-logbook' ); ?></span>
        </div>

        <p class="sd-field-help" style="margin:0 0 16px;">
            <?php esc_html_e( 'Configura le tue app CGM (xDrip+, Loop, AndroidAPS, Spike, Juggluco…) per inviare i dati direttamente a ScubaDiabetes, senza bisogno di un server Nightscout esterno. I dati vengono usati per pre-compilare automaticamente il log immersioni.', 'sd-logbook' ); ?>
        </p>

        <?php if ( $ns_srv['readings_count'] > 0 && $ns_srv['last_reading'] ) :
            $lr = $ns_srv['last_reading'];
        ?>
        <div class="sd-ns-stats-bar">
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-val"><?php echo esc_html( $ns_srv['readings_count'] ); ?></span>
                <span class="sd-ns-stat-lbl"><?php esc_html_e( 'Letture CGM', 'sd-logbook' ); ?></span>
            </div>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-val"><?php echo esc_html( $lr->glucose_value ); ?> <small><?php echo esc_html( $lr->glucose_unit ); ?></small></span>
                <span class="sd-ns-stat-lbl"><?php esc_html_e( 'Ultima lettura', 'sd-logbook' ); ?></span>
            </div>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-val"><?php echo esc_html( human_time_diff( strtotime( $lr->reading_time ), current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'fa', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-lbl"><?php echo esc_html( $lr->device ?: 'CGM' ); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Credenziali per configurazione app CGM -->
        <div class="sd-ns-credentials" id="sd-ns-credentials">
            <div class="sd-ns-cred-field">
                <label class="sd-ns-cred-label"><?php esc_html_e( 'URL server (Nightscout URL)', 'sd-logbook' ); ?></label>
                <div class="sd-ns-cred-copy-row">
                    <code class="sd-ns-cred-value" id="sd-ns-srv-url"><?php echo esc_html( $ns_srv['api_url'] ); ?></code>
                    <button type="button" class="sd-btn-ns-copy" data-target="sd-ns-srv-url" title="<?php esc_attr_e( 'Copia URL', 'sd-logbook' ); ?>">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#1565C0" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        <span><?php esc_html_e( 'Copia', 'sd-logbook' ); ?></span>
                    </button>
                </div>
            </div>

            <div class="sd-ns-cred-field">
                <label class="sd-ns-cred-label"><?php esc_html_e( 'API_SECRET (token personale)', 'sd-logbook' ); ?></label>
                <?php if ( $ns_srv['has_token'] ) : ?>
                <div class="sd-ns-cred-copy-row">
                    <code class="sd-ns-cred-value sd-ns-token-masked" id="sd-ns-token-display">••••••••••••••••••••••••</code>
                    <button type="button" class="sd-btn-ns-copy" id="sd-ns-btn-reveal-token" data-token="<?php echo esc_attr( $ns_srv['token'] ); ?>" title="<?php esc_attr_e( 'Mostra / Copia', 'sd-logbook' ); ?>">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#1565C0" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <span id="sd-ns-reveal-label"><?php esc_html_e( 'Mostra', 'sd-logbook' ); ?></span>
                    </button>
                </div>
                <?php else : ?>
                <p class="sd-field-help" style="margin:4px 0 8px;"><?php esc_html_e( 'Nessun token generato.', 'sd-logbook' ); ?></p>
                <?php endif; ?>
            </div>

            <div class="sd-ns-cred-actions">
                <?php if ( ! $ns_srv['has_token'] ) : ?>
                <button type="button" class="sd-btn-save-record" id="sd-ns-btn-gen-token">
                    <?php esc_html_e( 'Genera token', 'sd-logbook' ); ?>
                </button>
                <?php else : ?>
                <button type="button" class="sd-btn-save-record" id="sd-ns-btn-regen-token">
                    <?php esc_html_e( 'Rigenera token', 'sd-logbook' ); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Guida configurazione app -->
        <div class="sd-ns-app-guide">
            <div class="sd-ns-guide-title"><?php esc_html_e( 'Come configurare le app CGM', 'sd-logbook' ); ?></div>
            <div class="sd-ns-guide-grid">
                <div class="sd-ns-guide-app">
                    <strong>xDrip+</strong>
                    <p><?php esc_html_e( 'Impostazioni → Cloud Upload → Nightscout sync → inserisci URL + API_SECRET', 'sd-logbook' ); ?></p>
                </div>
                <div class="sd-ns-guide-app">
                    <strong>AndroidAPS / Loop</strong>
                    <p><?php esc_html_e( 'Config → Nightscout Client → Nightscout URL + API secret', 'sd-logbook' ); ?></p>
                </div>
                <div class="sd-ns-guide-app">
                    <strong>Spike (iOS)</strong>
                    <p><?php esc_html_e( 'Impostazioni → Followers → Nightscout → URL + API_SECRET', 'sd-logbook' ); ?></p>
                </div>
                <div class="sd-ns-guide-app">
                    <strong>Juggluco</strong>
                    <p><?php esc_html_e( 'Mirror → Nightscout → inserisci URL e API_SECRET', 'sd-logbook' ); ?></p>
                </div>
            </div>
        </div>

        <div class="sd-ns-message" id="sd-ns-srv-message" style="display:none;"></div>
    </div>

    <!-- ============================================================ -->
    <!-- INTEGRAZIONE DEXCOM API UFFICIALE (solo diabetici) -->
    <!-- ============================================================ -->
    <?php $dx = SD_Dexcom_OAuth::get_profile_data( $user_id ); ?>
    <div class="sd-section sd-section-dexcom">
        <div class="sd-section-title">
            <span class="sd-section-icon">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M8 12h8M12 8l4 4-4 4"/>
                </svg>
            </span>
            <?php esc_html_e( 'Integrazione Dexcom', 'sd-logbook' ); ?>
            <?php if ( $dx['connected'] ) : ?>
                <span class="sd-ns-badge sd-ns-badge-connected"><?php esc_html_e( 'Connesso', 'sd-logbook' ); ?></span>
            <?php else : ?>
                <span class="sd-ns-badge sd-ns-badge-disconnected"><?php esc_html_e( 'Non connesso', 'sd-logbook' ); ?></span>
            <?php endif; ?>
        </div>

        <p class="sd-field-help" style="margin:0 0 16px;">
            <?php esc_html_e( 'Collega il tuo account Dexcom tramite l\'API ufficiale OAuth 2.0. Non vengono mai salvate le tue credenziali Dexcom: verrai reindirizzato alla pagina login di Dexcom dove potrai autenticarti direttamente.', 'sd-logbook' ); ?>
        </p>

        <?php if ( ! SD_Dexcom_OAuth::is_configured() ) : ?>
        <!-- App non configurata dall'admin -->
        <div class="sd-notice" style="background:#fff8e5;border-left:4px solid #ffb900;padding:12px 16px;border-radius:3px;">
            ⚠️ <?php esc_html_e( 'L\'integrazione Dexcom API non è ancora configurata. Contatta l\'amministratore del sito.', 'sd-logbook' ); ?>
        </div>

        <?php elseif ( $dx['connected'] ) : ?>
        <!-- Stato connessione attiva -->
        <div class="sd-ns-stats-bar" style="margin-bottom:16px;">
            <?php if ( $dx['last_glucose'] ) : ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Ultima lettura', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( $dx['last_glucose'] ); ?> mg/dL
                    <?php if ( $dx['last_trend'] ) : ?><small><?php echo esc_html( $dx['last_trend'] ); ?></small><?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <?php if ( $dx['last_read_time'] ) : ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Ora lettura', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $dx['last_read_time'] ) ) ); ?></span>
            </div>
            <?php endif; ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Letture salvate', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( $dx['readings_count'] ); ?></span>
            </div>
            <?php if ( $dx['last_sync_at'] ) : ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Ultimo sync', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $dx['last_sync_at'] ) ) ); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pulsanti azioni -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
            <button type="button" id="sd-dx-btn-sync" class="sd-btn-save-record">
                <?php esc_html_e( 'Sync Ora', 'sd-logbook' ); ?>
            </button>
            <button type="button" id="sd-dx-btn-disconnect" class="sd-btn-cancel-record">
                <?php esc_html_e( 'Disconnetti', 'sd-logbook' ); ?>
            </button>
        </div>

        <?php else : ?>
        <!-- Non ancora connesso -->
        <div style="margin-bottom:16px;">
            <button type="button" id="sd-dx-btn-connect" class="sd-btn-save-record">
                <?php esc_html_e( 'Connetti con Dexcom', 'sd-logbook' ); ?>
            </button>
            <p class="sd-field-help" style="margin-top:8px;">
                <?php esc_html_e( 'Verrai reindirizzato al sito Dexcom per autenticarti e autorizzare l\'accesso. Torna qui al termine.', 'sd-logbook' ); ?>
                <?php if ( SD_Dexcom_OAuth::is_sandbox() ) : ?>
                <br><strong><?php esc_html_e( '⚠ Modalità Sandbox attiva (dati di test)', 'sd-logbook' ); ?></strong>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <?php
        // Mostra messaggio da OAuth callback (es. ?dexcom_connected=1 o ?dexcom_error=...)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $oauth_conn  = sanitize_text_field( wp_unslash( $_GET['dexcom_connected'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $oauth_error = sanitize_text_field( wp_unslash( $_GET['dexcom_error'] ?? '' ) );
        if ( '1' === $oauth_conn ) : ?>
        <div class="sd-ns-message sd-ns-msg-success" style="display:block;">
            ✅ <?php esc_html_e( 'Account Dexcom connesso con successo!', 'sd-logbook' ); ?>
        </div>
        <?php elseif ( $oauth_error ) : ?>
        <div class="sd-ns-message sd-ns-msg-error" style="display:block;">
            <?php
            $err_map = array(
                'access_denied'  => __( 'Autorizzazione negata dall\'utente.', 'sd-logbook' ),
                'invalid_state'  => __( 'Sessione scaduta. Riprova.', 'sd-logbook' ),
                'missing_params' => __( 'Parametri OAuth mancanti.', 'sd-logbook' ),
            );
            echo esc_html( $err_map[ $oauth_error ] ?? $oauth_error );
            ?>
        </div>
        <?php endif; ?>

        <div class="sd-ns-message" id="sd-dx-message" style="display:none;"></div>
    </div>

    <!-- ============================================================ -->
    <!-- INTEGRAZIONE LIBREVIEW / FREESTYLE LIBRE (solo diabetici) -->
    <!-- ============================================================ -->
    <?php $lv = SD_LibreView::get_profile_data( $user_id ); ?>
    <div class="sd-section sd-section-libreview">
        <div class="sd-section-title">
            <span class="sd-section-icon">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M8 12h8M12 8v8"/>
                </svg>
            </span>
            <?php esc_html_e( 'Integrazione LibreView (FreeStyle Libre)', 'sd-logbook' ); ?>
            <?php if ( $lv['connected'] ) : ?>
                <span class="sd-ns-badge sd-ns-badge-connected"><?php esc_html_e( 'Connesso', 'sd-logbook' ); ?></span>
            <?php else : ?>
                <span class="sd-ns-badge sd-ns-badge-disconnected"><?php esc_html_e( 'Non connesso', 'sd-logbook' ); ?></span>
            <?php endif; ?>
        </div>

        <p class="sd-field-help" style="margin:0 0 16px;">
            <?php esc_html_e( 'Collega il tuo account LibreView (Abbott) per importare automaticamente le letture CGM dai sensori FreeStyle Libre 2, Libre 3 e Libre Pro. Le letture vengono sincronizzate ogni ora tramite le API LibreLinkUp.', 'sd-logbook' ); ?>
        </p>

        <?php if ( $lv['connected'] ) : ?>
        <!-- Stato connessione -->
        <div class="sd-ns-stats-bar" style="margin-bottom:16px;">
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Account', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( $lv['email'] ); ?></span>
            </div>
            <?php if ( $lv['last_glucose'] ) : ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Ultima lettura', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( $lv['last_glucose'] ); ?> mg/dL
                    <?php if ( $lv['last_trend'] ) : ?><small><?php echo esc_html( $lv['last_trend'] ); ?></small><?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Letture salvate', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( $lv['readings_count'] ); ?></span>
            </div>
            <?php if ( $lv['last_sync_at'] ) : ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Ultimo sync', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $lv['last_sync_at'] ) ) ); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pulsanti azioni -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
            <button type="button" id="sd-lv-btn-sync" class="sd-btn-save-record">
                <?php esc_html_e( 'Sync Ora', 'sd-logbook' ); ?>
            </button>
            <button type="button" id="sd-lv-btn-edit" class="sd-btn-cancel-record">
                <?php esc_html_e( 'Modifica Credenziali', 'sd-logbook' ); ?>
            </button>
            <button type="button" id="sd-lv-btn-disconnect" class="sd-btn-cancel-record">
                <?php esc_html_e( 'Disconnetti', 'sd-logbook' ); ?>
            </button>
        </div>
        <?php endif; ?>

        <!-- Form credenziali -->
        <div id="sd-lv-form" <?php echo $lv['connected'] ? 'style="display:none;"' : ''; ?>>
            <div class="sd-form-grid">
                <div class="sd-field">
                    <label for="sd-lv-email"><?php esc_html_e( 'Email LibreView', 'sd-logbook' ); ?></label>
                    <input type="email" id="sd-lv-email" name="libreview_email" autocomplete="off"
                           value="<?php echo $lv['connected'] ? esc_attr( $lv['email'] ) : ''; ?>"
                           placeholder="<?php esc_attr_e( 'es. mario.rossi@email.com', 'sd-logbook' ); ?>">
                    <p class="sd-field-help"><?php esc_html_e( 'La stessa email con cui accedi a LibreView.io o all\'app LibreLinkUp.', 'sd-logbook' ); ?></p>
                </div>
                <div class="sd-field">
                    <label for="sd-lv-password"><?php esc_html_e( 'Password LibreView', 'sd-logbook' ); ?></label>
                    <div class="sd-password-wrap">
                        <input type="password" id="sd-lv-password" name="libreview_password" autocomplete="new-password"
                               placeholder="<?php esc_attr_e( 'Password account LibreView', 'sd-logbook' ); ?>">
                        <button type="button" class="sd-password-toggle" data-target="sd-lv-password" aria-label="<?php esc_attr_e( 'Mostra/Nascondi password', 'sd-logbook' ); ?>">
                            <svg class="sd-pw-icon-show" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="sd-pw-icon-hide" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                <button type="button" id="sd-lv-btn-save" class="sd-btn-save-record">
                    <?php esc_html_e( 'Salva e Connetti', 'sd-logbook' ); ?>
                </button>
                <button type="button" id="sd-lv-btn-test" class="sd-btn-cancel-record">
                    <?php esc_html_e( 'Testa Connessione', 'sd-logbook' ); ?>
                </button>
                <?php if ( $lv['connected'] ) : ?>
                <button type="button" id="sd-lv-btn-cancel-edit" class="sd-btn-cancel-record">
                    <?php esc_html_e( 'Annulla', 'sd-logbook' ); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="sd-ns-message" id="sd-lv-message" style="display:none;"></div>
    </div>

    <!-- ============================================================ -->
    <!-- INTEGRAZIONE MEDTRONIC CARELINK (solo diabetici) -->
    <!-- ============================================================ -->
    <?php $cl = SD_CareLink::get_profile_data( $user_id ); ?>
    <div class="sd-section sd-section-carelink">
        <div class="sd-section-title">
            <span class="sd-section-icon">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                    <path d="M12 6v6l4 2"/>
                </svg>
            </span>
            <?php esc_html_e( 'Integrazione Medtronic CareLink', 'sd-logbook' ); ?>
            <?php if ( $cl['connected'] ) : ?>
                <span class="sd-ns-badge sd-ns-badge-connected"><?php esc_html_e( 'Connesso', 'sd-logbook' ); ?></span>
            <?php else : ?>
                <span class="sd-ns-badge sd-ns-badge-disconnected"><?php esc_html_e( 'Non connesso', 'sd-logbook' ); ?></span>
            <?php endif; ?>
        </div>

        <p class="sd-field-help" style="margin:0 0 16px;">
            <?php esc_html_e( 'Collega il tuo account Medtronic CareLink per importare automaticamente le letture CGM dai sensori Guardian (3, 4), Guardian Sensor 3 e SimplerA. Richiede un account CareLink Patient attivo. I dati vengono sincronizzati ogni ora.', 'sd-logbook' ); ?>
        </p>

        <?php if ( $cl['connected'] ) : ?>
        <!-- Stato connessione -->
        <div class="sd-ns-stats-bar" style="margin-bottom:16px;">
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Account', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( $cl['username'] ); ?></span>
            </div>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Server', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo 'carelink.minimed.eu' === $cl['server'] ? 'EU' : 'US'; ?></span>
            </div>
            <?php if ( $cl['last_glucose'] ) : ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Ultima lettura', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( $cl['last_glucose'] ); ?> mg/dL
                    <?php if ( $cl['last_trend'] ) : ?><small><?php echo esc_html( $cl['last_trend'] ); ?></small><?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Letture salvate', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( $cl['readings_count'] ); ?></span>
            </div>
            <?php if ( $cl['last_sync_at'] ) : ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Ultimo sync', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $cl['last_sync_at'] ) ) ); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pulsanti azioni -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
            <button type="button" id="sd-cl-btn-sync" class="sd-btn-save-record">
                <?php esc_html_e( 'Sync Ora', 'sd-logbook' ); ?>
            </button>
            <button type="button" id="sd-cl-btn-edit" class="sd-btn-cancel-record">
                <?php esc_html_e( 'Modifica Credenziali', 'sd-logbook' ); ?>
            </button>
            <button type="button" id="sd-cl-btn-disconnect" class="sd-btn-cancel-record">
                <?php esc_html_e( 'Disconnetti', 'sd-logbook' ); ?>
            </button>
        </div>
        <?php endif; ?>

        <!-- Form credenziali -->
        <div id="sd-cl-form" <?php echo $cl['connected'] ? 'style="display:none;"' : ''; ?>>
            <div class="sd-form-grid">
                <div class="sd-field">
                    <label for="sd-cl-username"><?php esc_html_e( 'Username CareLink', 'sd-logbook' ); ?></label>
                    <input type="text" id="sd-cl-username" name="carelink_username" autocomplete="off"
                           value="<?php echo $cl['connected'] ? esc_attr( $cl['username'] ) : ''; ?>"
                           placeholder="<?php esc_attr_e( 'Username o email CareLink', 'sd-logbook' ); ?>">
                    <p class="sd-field-help"><?php esc_html_e( 'Lo stesso username con cui accedi a carelink.minimed.eu.', 'sd-logbook' ); ?></p>
                </div>
                <div class="sd-field">
                    <label for="sd-cl-password"><?php esc_html_e( 'Password CareLink', 'sd-logbook' ); ?></label>
                    <div class="sd-password-wrap">
                        <input type="password" id="sd-cl-password" name="carelink_password" autocomplete="new-password"
                               placeholder="<?php esc_attr_e( 'Password account CareLink', 'sd-logbook' ); ?>">
                        <button type="button" class="sd-password-toggle" data-target="sd-cl-password" aria-label="<?php esc_attr_e( 'Mostra/Nascondi password', 'sd-logbook' ); ?>">
                            <svg class="sd-pw-icon-show" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="sd-pw-icon-hide" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <div class="sd-form-grid" style="margin-top:12px;">
                <div class="sd-field">
                    <label><?php esc_html_e( 'Server CareLink', 'sd-logbook' ); ?></label>
                    <div style="display:flex;gap:20px;align-items:center;margin-top:6px;">
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:normal;">
                            <input type="radio" name="carelink_server" id="sd-cl-server-eu" value="carelink.minimed.eu"
                                   <?php checked( 'carelink.minimed.eu', $cl['server'] ); ?>>
                            <?php esc_html_e( 'Europa (EU)', 'sd-logbook' ); ?>
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:normal;">
                            <input type="radio" name="carelink_server" id="sd-cl-server-us" value="carelink.minimed.com"
                                   <?php checked( 'carelink.minimed.com', $cl['server'] ); ?>>
                            <?php esc_html_e( 'USA / Canada (US)', 'sd-logbook' ); ?>
                        </label>
                    </div>
                    <p class="sd-field-help"><?php esc_html_e( 'Per utenti in Europa (inclusa Svizzera e Italia) seleziona EU. Per USA e Canada seleziona US.', 'sd-logbook' ); ?></p>
                </div>
                <div class="sd-field">
                    <label for="sd-cl-country"><?php esc_html_e( 'Codice paese', 'sd-logbook' ); ?></label>
                    <input type="text" id="sd-cl-country" name="carelink_country" maxlength="5" style="max-width:100px;"
                           value="<?php echo esc_attr( $cl['country_code'] ); ?>"
                           placeholder="ch">
                    <p class="sd-field-help"><?php esc_html_e( 'Es: ch (Svizzera), it (Italia), de (Germania), us (USA).', 'sd-logbook' ); ?></p>
                </div>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                <button type="button" id="sd-cl-btn-save" class="sd-btn-save-record">
                    <?php esc_html_e( 'Salva e Connetti', 'sd-logbook' ); ?>
                </button>
                <button type="button" id="sd-cl-btn-test" class="sd-btn-cancel-record">
                    <?php esc_html_e( 'Testa Connessione', 'sd-logbook' ); ?>
                </button>
                <?php if ( $cl['connected'] ) : ?>
                <button type="button" id="sd-cl-btn-cancel-edit" class="sd-btn-cancel-record">
                    <?php esc_html_e( 'Annulla', 'sd-logbook' ); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="sd-ns-message" id="sd-cl-message" style="display:none;"></div>
    </div>

    <!-- ============================================================ -->
    <!-- INTEGRAZIONE TIDEPOOL (solo diabetici) -->
    <!-- ============================================================ -->
    <?php $tp = SD_Tidepool::get_profile_data( $user_id ); ?>
    <div class="sd-section sd-section-tidepool">
        <div class="sd-section-title">
            <span class="sd-section-icon">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 6h18M3 12h18M3 18h18"/>
                </svg>
            </span>
            <?php esc_html_e( 'Integrazione Tidepool', 'sd-logbook' ); ?>
            <?php if ( $tp['connected'] ) : ?>
                <span class="sd-ns-badge sd-ns-badge-connected"><?php esc_html_e( 'Connesso', 'sd-logbook' ); ?></span>
            <?php else : ?>
                <span class="sd-ns-badge sd-ns-badge-disconnected"><?php esc_html_e( 'Non connesso', 'sd-logbook' ); ?></span>
            <?php endif; ?>
        </div>

        <p class="sd-field-help" style="margin:0 0 16px;">
            <?php esc_html_e( 'Collega il tuo account Tidepool per importare automaticamente le letture CGM. Tidepool è una piattaforma open-source gratuita che aggrega dati da sensori CGM, microinfusori e misuratori. I dati vengono sincronizzati ogni ora.', 'sd-logbook' ); ?>
        </p>

        <?php if ( $tp['connected'] ) : ?>
        <!-- Stato connessione -->
        <div class="sd-ns-stats-bar" style="margin-bottom:16px;">
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Account', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( $tp['email'] ); ?></span>
            </div>
            <?php if ( $tp['last_glucose'] ) : ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Ultima lettura', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( $tp['last_glucose'] ); ?> mg/dL
                    <?php if ( $tp['last_trend'] ) : ?><small><?php echo esc_html( $tp['last_trend'] ); ?></small><?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Letture salvate', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( $tp['readings_count'] ); ?></span>
            </div>
            <?php if ( $tp['last_sync_at'] ) : ?>
            <div class="sd-ns-stat">
                <span class="sd-ns-stat-label"><?php esc_html_e( 'Ultimo sync', 'sd-logbook' ); ?></span>
                <span class="sd-ns-stat-value"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $tp['last_sync_at'] ) ) ); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pulsanti azioni -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
            <button type="button" id="sd-tp-btn-sync" class="sd-btn-save-record">
                <?php esc_html_e( 'Sync Ora', 'sd-logbook' ); ?>
            </button>
            <button type="button" id="sd-tp-btn-edit" class="sd-btn-cancel-record">
                <?php esc_html_e( 'Modifica Credenziali', 'sd-logbook' ); ?>
            </button>
            <button type="button" id="sd-tp-btn-disconnect" class="sd-btn-cancel-record">
                <?php esc_html_e( 'Disconnetti', 'sd-logbook' ); ?>
            </button>
        </div>
        <?php endif; ?>

        <!-- Form credenziali -->
        <div id="sd-tp-form" <?php echo $tp['connected'] ? 'style="display:none;"' : ''; ?>>
            <div class="sd-form-grid">
                <div class="sd-field">
                    <label for="sd-tp-email"><?php esc_html_e( 'Email Tidepool', 'sd-logbook' ); ?></label>
                    <input type="email" id="sd-tp-email" name="tidepool_email" autocomplete="off"
                           value="<?php echo $tp['connected'] ? esc_attr( $tp['email'] ) : ''; ?>"
                           placeholder="<?php esc_attr_e( 'es. mario.rossi@email.com', 'sd-logbook' ); ?>">
                </div>
                <div class="sd-field">
                    <label for="sd-tp-password"><?php esc_html_e( 'Password Tidepool', 'sd-logbook' ); ?></label>
                    <div class="sd-password-wrap">
                        <input type="password" id="sd-tp-password" name="tidepool_password" autocomplete="new-password"
                               placeholder="<?php esc_attr_e( 'Password account Tidepool', 'sd-logbook' ); ?>">
                        <button type="button" class="sd-password-toggle" data-target="sd-tp-password" aria-label="<?php esc_attr_e( 'Mostra/Nascondi password', 'sd-logbook' ); ?>">
                            <svg class="sd-pw-icon-show" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="sd-pw-icon-hide" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                <button type="button" id="sd-tp-btn-save" class="sd-btn-save-record">
                    <?php esc_html_e( 'Salva e Connetti', 'sd-logbook' ); ?>
                </button>
                <button type="button" id="sd-tp-btn-test" class="sd-btn-cancel-record">
                    <?php esc_html_e( 'Testa Connessione', 'sd-logbook' ); ?>
                </button>
                <?php if ( $tp['connected'] ) : ?>
                <button type="button" id="sd-tp-btn-cancel-edit" class="sd-btn-cancel-record">
                    <?php esc_html_e( 'Annulla', 'sd-logbook' ); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="sd-ns-message" id="sd-tp-message" style="display:none;"></div>
    </div>

    <!-- ============================================================ -->
    <!-- INTEGRAZIONE NIGHTSCOUT (solo diabetici) -->
    <!-- ============================================================ -->
    <div class="sd-section sd-section-nightscout">
        <div class="sd-section-title">
            <span class="sd-section-icon">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
                </svg>
            </span>
            <?php esc_html_e( 'Connessione Nightscout', 'sd-logbook' ); ?>
            <?php if ( $ns_data['connected'] ) : ?>
                <span class="sd-ns-badge sd-ns-badge-connected"><?php esc_html_e( 'Connesso', 'sd-logbook' ); ?></span>
            <?php else : ?>
                <span class="sd-ns-badge sd-ns-badge-disconnected"><?php esc_html_e( 'Non connesso', 'sd-logbook' ); ?></span>
            <?php endif; ?>
        </div>

        <p class="sd-field-help" style="margin:0 0 16px;">
            <?php esc_html_e( 'Collega il tuo server Nightscout per importare automaticamente valori CGM e somministrazioni di insulina. I dati vengono sincronizzati ogni ora e possono essere usati per pre-compilare il log immersioni.', 'sd-logbook' ); ?>
            <a href="https://nightscout.github.io/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Cos\'è Nightscout?', 'sd-logbook' ); ?></a>
        </p>

        <?php if ( $ns_data['connected'] ) : ?>
        <!-- Stato connessione attiva -->
        <div class="sd-ns-status-bar" id="sd-ns-status-bar">
            <div class="sd-ns-status-info">
                <span class="sd-ns-url"><?php echo esc_html( $ns_data['url'] ); ?></span>
                <?php if ( $ns_data['last_sync'] ) : ?>
                <span class="sd-ns-lastsync">
                    <?php echo esc_html(
                        sprintf(
                            /* translators: %s: relative time string */
                            __( 'Ultimo sync: %s', 'sd-logbook' ),
                            human_time_diff( strtotime( $ns_data['last_sync'] ), current_time( 'timestamp' ) ) . ' ' . __( 'fa', 'sd-logbook' )
                        )
                    ); ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="sd-ns-actions">
                <button type="button" class="sd-btn-cancel-record" id="sd-ns-btn-test">
                    <?php esc_html_e( 'Test connessione', 'sd-logbook' ); ?>
                </button>
                <button type="button" class="sd-btn-save-record" id="sd-ns-btn-sync">
                    <?php esc_html_e( 'Sync ora', 'sd-logbook' ); ?>
                </button>
                <button type="button" class="sd-btn-cancel-record" id="sd-ns-btn-disconnect">
                    <?php esc_html_e( 'Disconnetti', 'sd-logbook' ); ?>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Form credenziali (sempre visibile se non connesso, collassabile se connesso) -->
        <div class="sd-ns-form-wrap" id="sd-ns-form-wrap" <?php echo $ns_data['connected'] ? 'style="display:none;"' : ''; ?>>
            <div class="sd-field">
                <label><?php esc_html_e( 'URL del server Nightscout', 'sd-logbook' ); ?> *</label>
                <input type="url" id="sd-ns-url" name="ns_url"
                       value="<?php echo esc_attr( $ns_data['url'] ); ?>"
                       placeholder="https://mio-nightscout.fly.dev">
                <p class="sd-field-help"><?php esc_html_e( 'URL completo del tuo server Nightscout (es. Fly.io, Render, Railway). Si raccomanda HTTPS.', 'sd-logbook' ); ?></p>
            </div>
            <div class="sd-field">
                <label><?php esc_html_e( 'API_SECRET (token)', 'sd-logbook' ); ?> *</label>
                <input type="password" id="sd-ns-token" name="ns_token"
                       value=""
                       placeholder="<?php echo $ns_data['connected'] ? esc_attr__( '(invariato)', 'sd-logbook' ) : ''; ?>"
                       autocomplete="new-password">
                <p class="sd-field-help"><?php esc_html_e( 'Il tuo API_SECRET Nightscout. Viene cifrato prima di essere salvato.', 'sd-logbook' ); ?></p>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                <button type="button" class="sd-btn-save-record" id="sd-ns-btn-save">
                    <?php esc_html_e( $ns_data['connected'] ? 'Aggiorna credenziali' : 'Connetti Nightscout', 'sd-logbook' ); ?>
                </button>
                <?php if ( $ns_data['connected'] ) : ?>
                <button type="button" class="sd-btn-cancel-record" id="sd-ns-btn-cancel-edit">
                    <?php esc_html_e( 'Annulla', 'sd-logbook' ); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( $ns_data['connected'] ) : ?>
        <button type="button" class="sd-btn-add-record" id="sd-ns-btn-edit-credentials" style="margin-top:8px;">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            <?php esc_html_e( 'Modifica credenziali', 'sd-logbook' ); ?>
        </button>
        <?php endif; ?>

        <div class="sd-ns-message" id="sd-ns-message" style="display:none;"></div>
    </div>
    <?php endif; ?>

    <!-- Messaggi globali -->
    <div class="sd-form-messages sd-profile-messages" id="sd-profile-messages" style="display:none;"></div>

    <!-- RECORD DETAIL MODAL -->
    <div class="sd-record-modal-overlay" id="sd-record-modal-overlay" style="display:none;">
        <div class="sd-record-modal">
            <div class="sd-record-modal-header">
                <h3 id="sd-record-modal-title"></h3>
                <button type="button" class="sd-record-modal-close" id="sd-record-modal-close" aria-label="<?php esc_attr_e('Chiudi','sd-logbook'); ?>">&times;</button>
            </div>
            <div class="sd-record-modal-body" id="sd-record-modal-body"></div>
            <div class="sd-record-modal-footer">
                <button type="button" class="sd-btn-record-modal-edit" id="sd-btn-record-modal-edit"><?php esc_html_e('Modifica','sd-logbook'); ?></button>
                <button type="button" class="sd-btn-record-modal-delete" id="sd-btn-record-modal-delete"><?php esc_html_e('Elimina','sd-logbook'); ?></button>
                <button type="button" class="sd-btn-record-modal-close"><?php esc_html_e('Chiudi','sd-logbook'); ?></button>
            </div>
        </div>
    </div>

</div>
