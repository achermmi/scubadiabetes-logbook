<?php
/**
 * Profilo Subacqueo - Record multipli
 *
 * Ogni sezione (certificazioni, idoneità, contatti emergenza) supporta
 * N record memorizzati come JSON in user_meta:
 *   sd_certifications    -> array di certificazioni
 *   sd_medical_clearances -> array di idoneità
 *   sd_emergency_contacts -> array di contatti
 *   sd_medical_docs       -> array di documenti caricati
 *
 * I dati diabete rimangono nella tabella sd_diver_profiles (1 record per utente)
 *
 * @package SD_Logbook
 */

if (! defined('ABSPATH') ) {
    exit;
}

class SD_Diver_Profile
{

    public function __construct()
    {
        add_shortcode('sd_diver_profile', array( $this, 'render_profile' ));
        add_action('wp_enqueue_scripts', array( $this, 'enqueue_assets' ));

        // AJAX handlers
        add_action('wp_ajax_sd_save_certification', array( $this, 'save_certification' ));
        add_action('wp_ajax_sd_delete_certification', array( $this, 'delete_certification' ));
        add_action('wp_ajax_sd_save_medical_clearance', array( $this, 'save_medical_clearance' ));
        add_action('wp_ajax_sd_delete_medical_clearance', array( $this, 'delete_medical_clearance' ));
        add_action('wp_ajax_sd_save_emergency_contact', array( $this, 'save_emergency_contact' ));
        add_action('wp_ajax_sd_delete_emergency_contact', array( $this, 'delete_emergency_contact' ));
        add_action('wp_ajax_sd_delete_medical_doc', array( $this, 'delete_medical_doc' ));
        add_action('wp_ajax_sd_save_diabetes_profile', array( $this, 'save_diabetes_profile' ));
    }

    public function enqueue_assets()
    {
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'sd_diver_profile') ) {
            wp_enqueue_style('sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION);
            wp_enqueue_style('sd-profile', SD_LOGBOOK_PLUGIN_URL . 'assets/css/profile.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION);
            wp_enqueue_script('sd-profile', SD_LOGBOOK_PLUGIN_URL . 'assets/js/profile.js', array( 'jquery' ), SD_LOGBOOK_VERSION, true);
            wp_localize_script(
                'sd-profile', 'sdProfile', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('sd_profile_nonce'),
                ) 
            );
        }
    }

    public function render_profile( $atts )
    {
        if (! is_user_logged_in() ) {
            return '<div class="sd-notice sd-notice-warning">'
                . __('Devi effettuare il login.', 'sd-logbook')
                . ' <a href="' . SD_Logbook::get_login_url() . '">' . __('Accedi', 'sd-logbook') . '</a></div>';
        }

        $user_id     = get_current_user_id();
        $is_diabetic = SD_Roles::is_diabetic_diver($user_id);

        // Load data
        $certifications     = get_user_meta($user_id, 'sd_certifications', true) ?: array();
        $medical_clearances = get_user_meta($user_id, 'sd_medical_clearances', true) ?: array();
        $emergency_contacts = get_user_meta($user_id, 'sd_emergency_contacts', true) ?: array();
        $medical_docs       = get_user_meta($user_id, 'sd_medical_docs', true) ?: array();

        // Diabetes profile from DB
        global $wpdb;
        $db = new SD_Database();
        $diabetes_profile = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$db->table('diver_profiles')} WHERE user_id = %d", $user_id
            ) 
        );

        $current_user = wp_get_current_user();
        $display_name = trim($current_user->first_name . ' ' . $current_user->last_name);
        if (empty($display_name) ) { $display_name = $current_user->display_name;
        }

        ob_start();
        include SD_LOGBOOK_PLUGIN_DIR . 'templates/profile.php';
        return ob_get_clean();
    }

    // ================================================================
    // CERTIFICAZIONI
    // ================================================================
    public function save_certification()
    {
        check_ajax_referer('sd_profile_nonce', 'nonce');
        $user_id = get_current_user_id();
        $certs = get_user_meta($user_id, 'sd_certifications', true) ?: array();

        $new = array(
            'agency' => sanitize_text_field($_POST['agency'] ?? ''),
            'level'  => sanitize_text_field($_POST['level'] ?? ''),
            'date'   => sanitize_text_field($_POST['cert_date'] ?? ''),
            'number' => sanitize_text_field($_POST['cert_number'] ?? ''),
        );

        if (empty($new['agency']) || empty($new['level']) ) {
            wp_send_json_error(array( 'message' => __('Agenzia e livello sono obbligatori.', 'sd-logbook') ));
        }

        $edit_index = isset($_POST['edit_index']) && $_POST['edit_index'] !== '' ? absint($_POST['edit_index']) : -1;
        if ($edit_index >= 0 && isset($certs[ $edit_index ]) ) {
            $certs[ $edit_index ] = $new;
        } else {
            $certs[] = $new;
        }

        update_user_meta($user_id, 'sd_certifications', $certs);
        wp_send_json_success(array( 'message' => __('Certificazione salvata.', 'sd-logbook'), 'data' => $new ));
    }

    public function delete_certification()
    {
        check_ajax_referer('sd_profile_nonce', 'nonce');
        $user_id = get_current_user_id();
        $index = absint($_POST['index'] ?? -1);
        $certs = get_user_meta($user_id, 'sd_certifications', true) ?: array();
        if (isset($certs[ $index ]) ) {
            array_splice($certs, $index, 1);
            update_user_meta($user_id, 'sd_certifications', $certs);
        }
        wp_send_json_success();
    }

    // ================================================================
    // IDONEITÀ MEDICA
    // ================================================================
    public function save_medical_clearance()
    {
        check_ajax_referer('sd_profile_nonce', 'nonce');
        $user_id = get_current_user_id();
        $clearances = get_user_meta($user_id, 'sd_medical_clearances', true) ?: array();

        $new = array(
            'date'        => sanitize_text_field($_POST['clearance_date'] ?? ''),
            'expiry'      => sanitize_text_field($_POST['clearance_expiry'] ?? ''),
            'doctor'      => sanitize_text_field($_POST['clearance_doctor'] ?? ''),
            'type'        => sanitize_text_field($_POST['clearance_type'] ?? ''),
            'notes'       => sanitize_text_field($_POST['clearance_notes'] ?? ''),
        );

        if (empty($new['date']) ) {
            wp_send_json_error(array( 'message' => __('La data è obbligatoria.', 'sd-logbook') ));
        }

        // Handle file upload
        if (! empty($_FILES['clearance_doc']) && $_FILES['clearance_doc']['error'] === UPLOAD_ERR_OK ) {
            $doc_result = $this->upload_single_doc($user_id, $_FILES['clearance_doc']);
            if ($doc_result ) {
                $new['doc'] = $doc_result;
            }
        }

        $edit_index = isset($_POST['edit_index']) && $_POST['edit_index'] !== '' ? absint($_POST['edit_index']) : -1;
        if ($edit_index >= 0 && isset($clearances[ $edit_index ]) ) {
            // Keep existing doc if no new upload
            if (empty($new['doc']) && ! empty($clearances[ $edit_index ]['doc']) ) {
                $new['doc'] = $clearances[ $edit_index ]['doc'];
            }
            $clearances[ $edit_index ] = $new;
        } else {
            $clearances[] = $new;
        }

        // Sort by date desc
        usort(
            $clearances, function ( $a, $b ) {
                return strcmp($b['date'], $a['date']);
            } 
        );

        update_user_meta($user_id, 'sd_medical_clearances', $clearances);
        wp_send_json_success(array( 'message' => __('Idoneità salvata.', 'sd-logbook') ));
    }

    public function delete_medical_clearance()
    {
        check_ajax_referer('sd_profile_nonce', 'nonce');
        $user_id = get_current_user_id();
        $index = absint($_POST['index'] ?? -1);
        $clearances = get_user_meta($user_id, 'sd_medical_clearances', true) ?: array();
        if (isset($clearances[ $index ]) ) {
            // Delete associated doc
            if (! empty($clearances[ $index ]['doc']['path']) && file_exists($clearances[ $index ]['doc']['path']) ) {
                unlink($clearances[ $index ]['doc']['path']);
            }
            array_splice($clearances, $index, 1);
            update_user_meta($user_id, 'sd_medical_clearances', $clearances);
        }
        wp_send_json_success();
    }

    // ================================================================
    // CONTATTI EMERGENZA
    // ================================================================
    public function save_emergency_contact()
    {
        check_ajax_referer('sd_profile_nonce', 'nonce');
        $user_id = get_current_user_id();
        $contacts = get_user_meta($user_id, 'sd_emergency_contacts', true) ?: array();

        $new = array(
            'name'         => sanitize_text_field($_POST['contact_name'] ?? ''),
            'phone'        => sanitize_text_field($_POST['contact_phone'] ?? ''),
            'relationship' => sanitize_text_field($_POST['contact_relationship'] ?? ''),
        );

        if (empty($new['name']) || empty($new['phone']) ) {
            wp_send_json_error(array( 'message' => __('Nome e telefono obbligatori.', 'sd-logbook') ));
        }

        $edit_index = isset($_POST['edit_index']) && $_POST['edit_index'] !== '' ? absint($_POST['edit_index']) : -1;
        if ($edit_index >= 0 && isset($contacts[ $edit_index ]) ) {
            $contacts[ $edit_index ] = $new;
        } else {
            $contacts[] = $new;
        }

        update_user_meta($user_id, 'sd_emergency_contacts', $contacts);
        wp_send_json_success(array( 'message' => __('Contatto salvato.', 'sd-logbook') ));
    }

    public function delete_emergency_contact()
    {
        check_ajax_referer('sd_profile_nonce', 'nonce');
        $user_id = get_current_user_id();
        $index = absint($_POST['index'] ?? -1);
        $contacts = get_user_meta($user_id, 'sd_emergency_contacts', true) ?: array();
        if (isset($contacts[ $index ]) ) {
            array_splice($contacts, $index, 1);
            update_user_meta($user_id, 'sd_emergency_contacts', $contacts);
        }
        wp_send_json_success();
    }

    // ================================================================
    // DOCUMENTI MEDICI (legacy delete)
    // ================================================================
    public function delete_medical_doc()
    {
        check_ajax_referer('sd_profile_nonce', 'nonce');
        $user_id = get_current_user_id();
        $index = absint($_POST['doc_index'] ?? -1);
        $docs = get_user_meta($user_id, 'sd_medical_docs', true) ?: array();
        if (isset($docs[ $index ]) ) {
            if (! empty($docs[ $index ]['path']) && file_exists($docs[ $index ]['path']) ) {
                unlink($docs[ $index ]['path']);
            }
            array_splice($docs, $index, 1);
            update_user_meta($user_id, 'sd_medical_docs', $docs);
        }
        wp_send_json_success();
    }

    // ================================================================
    // PROFILO DIABETE
    // ================================================================
    public function save_diabetes_profile()
    {
        check_ajax_referer('sd_profile_nonce', 'nonce');
        $user_id = get_current_user_id();

        if (! SD_Roles::is_diabetic_diver($user_id) ) {
            wp_send_json_error(array( 'message' => 'Non autorizzato' ));
        }

        $data = array(
            'user_id'            => $user_id,
            'is_diabetic'        => 1,
            'diabetes_type'      => sanitize_text_field($_POST['diabetes_type'] ?? 'none'),
            'therapy_type'       => sanitize_text_field($_POST['therapy_type'] ?? 'none'),
            'hba1c_last'         => ! empty($_POST['hba1c_last']) ? floatval($_POST['hba1c_last']) : null,
            'hba1c_date'         => sanitize_text_field($_POST['hba1c_date'] ?? '') ?: null,
            'uses_cgm'           => ! empty($_POST['uses_cgm']) ? 1 : 0,
            'cgm_device'         => sanitize_text_field($_POST['cgm_device'] ?? '') ?: null,
            'insulin_pump_model' => sanitize_text_field($_POST['insulin_pump_model'] ?? '') ?: null,
            'glycemia_unit'      => in_array($_POST['glycemia_unit'] ?? '', array( 'mg/dl', 'mmol/l' ), true) ? $_POST['glycemia_unit'] : 'mg/dl',
            'notes'              => sanitize_textarea_field($_POST['diabetes_notes'] ?? '') ?: null,
        );

        global $wpdb;
        $db = new SD_Database();
        $table = $db->table('diver_profiles');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id = %d", $user_id));

        if ($existing ) {
            $wpdb->update($table, $data, array( 'user_id' => $user_id ));
        } else {
            $wpdb->insert($table, $data);
        }

        wp_send_json_success(array( 'message' => __('Dati diabete aggiornati.', 'sd-logbook') ));
    }

    // ================================================================
    // HELPER: Upload singolo documento
    // ================================================================
    private function upload_single_doc( $user_id, $file )
    {
        $allowed = array( 'pdf', 'jpg', 'jpeg', 'png', 'zip' );
        $max_size = 5 * 1024 * 1024;

        $name = sanitize_file_name($file['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($ext, $allowed, true) || $file['size'] > $max_size ) {
            return null;
        }

        $upload_dir = wp_upload_dir();
        $sd_dir = $upload_dir['basedir'] . '/sd-medical-docs/' . $user_id;
        wp_mkdir_p($sd_dir);

        $unique_name = time() . '-' . $name;
        $dest = $sd_dir . '/' . $unique_name;

        if (move_uploaded_file($file['tmp_name'], $dest) ) {
            return array(
                'name' => $name,
                'url'  => $upload_dir['baseurl'] . '/sd-medical-docs/' . $user_id . '/' . $unique_name,
                'path' => $dest,
                'date' => date_i18n('d/m/Y'),
            );
        }
        return null;
    }
}
