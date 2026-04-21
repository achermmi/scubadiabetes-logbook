<?php
/**
 * Gestione Soci - Pannello staff
 *
 * Shortcode [sd_gestione_soci]: lista soci con filtri, esportazione
 * Shortcode [sd_iscrizione_edit]: modifica dettaglio singolo socio (via ?member_id=X)
 *
 * Accesso: sd_staff, sd_medical, administrator
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Membership_Admin {

	public function __construct() {
		add_shortcode( 'sd_gestione_soci', array( $this, 'render_management' ) );
		add_shortcode( 'sd_iscrizione_edit', array( $this, 'render_edit' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers (solo utenti loggati)
		add_action( 'wp_ajax_sd_members_list', array( $this, 'get_members_list' ) );
		add_action( 'wp_ajax_sd_member_get', array( $this, 'get_member' ) );
		add_action( 'wp_ajax_sd_member_update', array( $this, 'update_member' ) );
		add_action( 'wp_ajax_sd_members_export', array( $this, 'export_members' ) );

		// WP Cron per reminder rinnovo
		add_action( 'sd_membership_renewal_check', array( $this, 'send_renewal_reminders' ) );
	}

	/**
	 * Aggiunge body class per layout full-width su desktop
	 */
	public function add_full_width_body_class( $classes ) {
		$classes[] = 'sd-full-width-page';
		return $classes;
	}

	/**
	 * Verifica accesso: solo staff, medico e admin
	 */
	private function check_access() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$uid = get_current_user_id();
		return current_user_can( 'administrator' ) || SD_Roles::is_staff( $uid ) || SD_Roles::is_medical( $uid );
	}

	/**
	 * Enqueue assets per le pagine admin
	 */
	public function enqueue_assets() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		$has_sc = has_shortcode( $post->post_content, 'sd_gestione_soci' )
			   || has_shortcode( $post->post_content, 'sd_iscrizione_edit' );

		if ( ! $has_sc ) {
			return;
		}

		add_filter( 'body_class', array( $this, 'add_full_width_body_class' ) );

		wp_enqueue_style( 'sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-membership', SD_LOGBOOK_PLUGIN_URL . 'assets/css/membership.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-membership-admin', SD_LOGBOOK_PLUGIN_URL . 'assets/css/membership-admin.css', array( 'sd-membership' ), SD_LOGBOOK_VERSION );

		wp_enqueue_script( 'sd-membership', SD_LOGBOOK_PLUGIN_URL . 'assets/js/membership.js', array( 'jquery' ), SD_LOGBOOK_VERSION, true );
		wp_enqueue_script( 'sd-membership-admin', SD_LOGBOOK_PLUGIN_URL . 'assets/js/membership-admin.js', array( 'jquery', 'sd-membership' ), SD_LOGBOOK_VERSION, true );

		// Cerca la pagina di modifica iscrizione
		$edit_page = get_page_by_path( 'modifica-socio' );
		$edit_url  = $edit_page ? get_permalink( $edit_page ) : home_url( '/modifica-socio/' );

		wp_localize_script(
			'sd-membership-admin',
			'sdMembAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'sd_membership_admin_nonce' ),
				'editUrl'    => $edit_url,
				'currentYear' => gmdate( 'Y' ),
				'membNonce'  => wp_create_nonce( 'sd_membership_nonce' ),
			)
		);
	}

	/**
	 * Rendering pagina gestione soci
	 */
	public function render_management( $atts ) {
		if ( ! $this->check_access() ) {
			return '<div class="sd-notice sd-notice-error">'
				. __( 'Accesso negato. Questa pagina è riservata allo staff.', 'sd-logbook' )
				. '</div>';
		}

		global $wpdb;
		$db           = new SD_Database();
		$current_year = gmdate( 'Y' );

		// Stats rapide
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db->table('members')} WHERE is_active = 1" );
		$paid   = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$db->table('members')} m
			 LEFT JOIN {$db->table('members')} pm ON pm.id = m.parent_member_id AND m.member_type = 'attivo_famigliare'
			 WHERE m.is_active = 1
			   AND CASE
			           WHEN m.member_type = 'attivo_famigliare' AND m.parent_member_id IS NOT NULL
			           THEN COALESCE(pm.has_paid_fee, 0)
			           ELSE m.has_paid_fee
			       END = 1"
		);
		$income = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) FROM {$db->table('payments')} WHERE status = 'completato' AND payment_year = %d",
				$current_year
			)
		);

		$expected = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(m.fee_amount),0) FROM {$db->table('members')} m
			 LEFT JOIN {$db->table('members')} pm ON pm.id = m.parent_member_id AND m.member_type = 'attivo_famigliare'
			 WHERE m.is_active = 1
			   AND CASE
			           WHEN m.member_type = 'attivo_famigliare' AND m.parent_member_id IS NOT NULL
			           THEN COALESCE(pm.has_paid_fee, 0)
			           ELSE m.has_paid_fee
			       END = 0"
		);

		$stats = array(
			'total'    => $total,
			'paid'     => $paid,
			'unpaid'   => $total - $paid,
			'income'   => $income,
			'expected' => $expected,
		);

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/membership-admin.php';
		return ob_get_clean();
	}

	/**
	 * Rendering pagina modifica singolo socio
	 */
	public function render_edit( $atts ) {
		if ( ! $this->check_access() ) {
			return '<div class="sd-notice sd-notice-error">'
				. __( 'Accesso negato.', 'sd-logbook' )
				. '</div>';
		}

		$member_id = absint( $_GET['member_id'] ?? 0 );

		if ( ! $member_id ) {
			return '<div class="sd-notice sd-notice-warning">'
				. __( 'Nessun socio selezionato. Torna alla <a href="javascript:history.back()">lista soci</a>.', 'sd-logbook' )
				. '</div>';
		}

		global $wpdb;
		$db     = new SD_Database();
		$member = SD_Membership_Helper::get_member_full( $member_id );

		if ( ! $member ) {
			return '<div class="sd-notice sd-notice-error">'
				. __( 'Socio non trovato.', 'sd-logbook' )
				. '</div>';
		}

		// Familiari e accompagnatori (da sd_family_members — relazione tradizionale)
		$family_members = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('family_members')} WHERE member_id = %d AND (is_companion = 0 OR is_companion IS NULL) ORDER BY id",
				$member_id
			)
		);
		$companions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('family_members')} WHERE member_id = %d AND is_companion = 1 ORDER BY id",
				$member_id
			)
		);

		// Famigliari registrati come utenti WP (da sd_members con parent_member_id)
		$registered_family_members = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.first_name, m.last_name, m.email, m.phone, m.date_of_birth,
				        m.gender, m.is_scuba, m.diabetes_type, m.is_active, m.fee_amount,
				        m.member_type, m.wp_user_id, m.member_since
				 FROM {$db->table('members')} m
				 WHERE m.parent_member_id = %d
				 ORDER BY m.last_name, m.first_name",
				$member_id
			)
		);

		// Storico pagamenti
		$payments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('payments')} WHERE member_id = %d ORDER BY payment_year DESC, created_at DESC",
				$member_id
			)
		);

		// Audit log (ultime 20 voci)
		$audit_log = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT al.*, u.display_name as actor_name
				 FROM {$db->table('audit_log')} al
				 LEFT JOIN {$wpdb->users} u ON u.ID = al.member_id
				 WHERE al.member_id = %d
				 ORDER BY al.created_at DESC
				 LIMIT 20",
				$member_id
			)
		);

		// Dati per i select
		$countries     = SD_Membership_Helper::get_countries();
		$swiss_cantons = SD_Membership_Helper::get_swiss_cantons();
		$current_year  = gmdate( 'Y' );

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/membership-edit.php';
		return ob_get_clean();
	}

	/**
	 * AJAX: lista soci con filtri e paginazione
	 */
	public function get_members_list() {
		check_ajax_referer( 'sd_membership_admin_nonce', 'nonce' );

		if ( ! $this->check_access() ) {
			wp_send_json_error( array( 'message' => __( 'Accesso negato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db = new SD_Database();

		$per_page    = min( absint( $_POST['per_page'] ?? 25 ), 100 );
		$paged       = max( 1, absint( $_POST['paged'] ?? 1 ) );
		$offset      = ( $paged - 1 ) * $per_page;
		$year        = absint( $_POST['anno'] ?? gmdate( 'Y' ) );

		// Filtri
		$search      = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$pagato      = isset( $_POST['pagato'] ) && '' !== $_POST['pagato'] ? absint( $_POST['pagato'] ) : null;
		$is_scuba    = isset( $_POST['is_scuba'] ) && '' !== $_POST['is_scuba'] ? absint( $_POST['is_scuba'] ) : null;
		$diabetes    = sanitize_text_field( wp_unslash( $_POST['diabetes_type'] ?? '' ) );
		$member_type = sanitize_text_field( wp_unslash( $_POST['member_type'] ?? '' ) );
		$wp_role     = sanitize_text_field( wp_unslash( $_POST['wp_role'] ?? '' ) );
		$fee_filter  = sanitize_text_field( wp_unslash( $_POST['fee_amount'] ?? '' ) );

		$where  = array( 'm.is_active = 1' );
		$params = array();

		if ( ! empty( $search ) ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(m.first_name LIKE %s OR m.last_name LIKE %s OR m.email LIKE %s)';
			$params  = array_merge( $params, array( $like, $like, $like ) );
		}
		if ( null !== $pagato ) {
			$where[]  = 'm.has_paid_fee = %d';
			$params[] = $pagato;
		}
		if ( null !== $is_scuba ) {
			$where[]  = 'm.is_scuba = %d';
			$params[] = $is_scuba;
		}
		if ( ! empty( $diabetes ) ) {
			$where[]  = 'm.diabetes_type = %s';
			$params[] = $diabetes;
		}
		if ( ! empty( $member_type ) ) {
			$where[]  = 'm.member_type = %s';
			$params[] = $member_type;
		}
		if ( ! empty( $fee_filter ) ) {
			$where[]  = 'm.fee_amount = %f';
			$params[] = floatval( $fee_filter );
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// Count totale
		$count_sql  = "SELECT COUNT(*) FROM {$db->table('members')} m {$where_sql}";
		$total      = $wpdb->get_var( ! empty( $params ) ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore

		// Query principale
		$query_sql = "SELECT m.id, m.first_name, m.last_name, m.email, m.phone,
		                     m.date_of_birth, m.gender, m.fee_amount,
		                     CASE
		                         WHEN m.member_type = 'attivo_famigliare' AND m.parent_member_id IS NOT NULL
		                         THEN COALESCE(pm.has_paid_fee, 0)
		                         ELSE m.has_paid_fee
		                     END AS has_paid_fee,
		                     m.member_type, m.is_scuba, m.diabetes_type, m.member_since,
		                     m.membership_expiry, m.sotto_tutela, m.registered_at,
		                     m.wp_user_id,
		                     p.amount as paid_amount, p.payment_date, p.payment_method, p.status as payment_status
		              FROM {$db->table('members')} m
		              LEFT JOIN {$db->table('payments')} p
		                ON p.member_id = m.id AND p.payment_year = %d
		              LEFT JOIN {$db->table('members')} pm
		                ON pm.id = m.parent_member_id AND m.member_type = 'attivo_famigliare'
		              {$where_sql}
		              ORDER BY m.last_name ASC, m.first_name ASC
		              LIMIT %d OFFSET %d";

		$all_params = array_merge( array( $year ), $params, array( $per_page, $offset ) );
		$rows       = $wpdb->get_results( $wpdb->prepare( $query_sql, $all_params ) ); // phpcs:ignore

		// Aggiungi ruolo WP
		foreach ( $rows as &$row ) {
			$row->wp_role_label = '';
			if ( $row->wp_user_id ) {
				$wp_user = get_userdata( $row->wp_user_id );
				if ( $wp_user ) {
					$roles           = (array) $wp_user->roles;
					$row->wp_roles   = $roles;
					$role_labels     = array();
					$wp_roles_obj    = wp_roles();
					foreach ( $roles as $r ) {
						if ( isset( $wp_roles_obj->role_names[ $r ] ) ) {
							$role_labels[] = translate_user_role( $wp_roles_obj->role_names[ $r ] );
						}
					}
					$row->wp_role_label = implode( ', ', $role_labels );
				}
			}

			// Applica filtro ruolo WP se impostato
			if ( ! empty( $wp_role ) ) {
				if ( ! in_array( $wp_role, (array) $row->wp_roles, true ) ) {
					$row = null;
				}
			}
		}
		unset( $row );

		// Filtra eventuale null (per filtro ruolo WP)
		$rows = array_filter( $rows );

		wp_send_json_success(
			array(
				'rows'     => array_values( $rows ),
				'total'    => (int) $total,
				'per_page' => $per_page,
				'paged'    => $paged,
			)
		);
	}

	/**
	 * AJAX: carica dati completi di un singolo socio
	 */
	public function get_member() {
		check_ajax_referer( 'sd_membership_admin_nonce', 'nonce' );

		if ( ! $this->check_access() ) {
			wp_send_json_error( array( 'message' => __( 'Accesso negato.', 'sd-logbook' ) ) );
		}

		$member_id = absint( $_POST['member_id'] ?? 0 );
		$member    = SD_Membership_Helper::get_member_full( $member_id );

		if ( ! $member ) {
			wp_send_json_error( array( 'message' => __( 'Socio non trovato.', 'sd-logbook' ) ) );
		}

		wp_send_json_success( array( 'member' => $member ) );
	}

	/**
	 * AJAX: aggiorna i dati di un socio (dati iscrizione + gestione)
	 */
	public function update_member() {
		check_ajax_referer( 'sd_membership_admin_nonce', 'nonce' );

		if ( ! $this->check_access() ) {
			wp_send_json_error( array( 'message' => __( 'Accesso negato.', 'sd-logbook' ) ) );
		}

		$member_id = absint( $_POST['member_id'] ?? 0 );
		if ( ! $member_id ) {
			wp_send_json_error( array( 'message' => __( 'ID socio non valido.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db = new SD_Database();

		// Carica dati vecchi per audit
		$old_data = SD_Membership_Helper::get_member_full( $member_id );
		if ( ! $old_data ) {
			wp_send_json_error( array( 'message' => __( 'Socio non trovato.', 'sd-logbook' ) ) );
		}

		// Campi gestione
		$has_paid_fee   = isset( $_POST['has_paid_fee'] ) ? absint( $_POST['has_paid_fee'] ) : null;
		$payment_date   = sanitize_text_field( wp_unslash( $_POST['payment_date'] ?? '' ) );
		$payment_method = sanitize_text_field( wp_unslash( $_POST['payment_method'] ?? '' ) );
		$payment_amount = ! empty( $_POST['payment_amount'] ) ? floatval( $_POST['payment_amount'] ) : null;
		$payment_year   = absint( $_POST['payment_year'] ?? gmdate( 'Y' ) );
		$notes          = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
		$member_type    = sanitize_text_field( wp_unslash( $_POST['member_type'] ?? '' ) );
		$is_active      = isset( $_POST['is_active'] ) ? absint( $_POST['is_active'] ) : 1;

		// Campi anagrafici base
		$update_data = array();

		$text_fields = array(
			'first_name',
			'last_name',
			'email',
			'phone',
			'birth_place',
			'birth_country',
			'gender',
			'address_street',
			'address_city',
			'address_postal',
			'address_country',
			'address_canton',
			'fiscal_code',
			'diabetology_center',
			'guardian_first_name',
			'guardian_last_name',
			'guardian_role',
			'guardian_birth_place',
			'guardian_birth_country',
			'guardian_gender',
			'guardian_email',
			'guardian_phone',
			'guardian_address',
			'guardian_city',
			'guardian_postal',
			'guardian_country',
		);

		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$update_data[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			}
		}

		if ( isset( $_POST['date_of_birth'] ) ) {
			$dob = sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ) );
			$update_data['date_of_birth'] = ! empty( $dob ) && strtotime( $dob ) ? $dob : null;
		}
		if ( isset( $_POST['guardian_dob'] ) ) {
			$gdob = sanitize_text_field( wp_unslash( $_POST['guardian_dob'] ) );
			$update_data['guardian_dob'] = ! empty( $gdob ) && strtotime( $gdob ) ? $gdob : null;
		}
		if ( isset( $_POST['membership_expiry'] ) ) {
			$exp = sanitize_text_field( wp_unslash( $_POST['membership_expiry'] ) );
			$update_data['membership_expiry'] = ! empty( $exp ) && strtotime( $exp ) ? $exp : null;
		}

		// Campi booleani/numerici
		if ( isset( $_POST['sotto_tutela'] ) ) {
			$update_data['sotto_tutela'] = absint( $_POST['sotto_tutela'] );
		}
		if ( isset( $_POST['is_scuba'] ) ) {
			$update_data['is_scuba'] = absint( $_POST['is_scuba'] );
		}
		if ( isset( $_POST['fee_amount'] ) ) {
			$update_data['fee_amount'] = floatval( $_POST['fee_amount'] );
		}
		if ( null !== $has_paid_fee ) {
			$update_data['has_paid_fee'] = $has_paid_fee;
		}
		if ( ! empty( $member_type ) ) {
			$update_data['member_type'] = $member_type;
		}
		if ( ! empty( $notes ) ) {
			$update_data['notes'] = $notes;
		}
		$update_data['is_active'] = $is_active;

		// Aggiorna tabella members
		if ( ! empty( $update_data ) ) {
			$wpdb->update( $db->table( 'members' ), $update_data, array( 'id' => $member_id ) );
		}

		// === Cascata disattivazione: se is_active passa a 0, disabilita anche i famigliari ===
		$old_is_active = isset( $old_data->is_active ) ? (int) $old_data->is_active : 1;
		$new_is_active = $is_active;

		if ( $old_is_active !== $new_is_active ) {
			// Disabilita o abilita l'utente WP dell'intestatario
			if ( $old_data->wp_user_id ) {
				if ( 0 === $new_is_active ) {
					SD_Membership_Helper::disable_wp_user( $old_data->wp_user_id );
				} else {
					SD_Membership_Helper::enable_wp_user( $old_data->wp_user_id );
				}
			}

			// Propaga ai famigliari registrati (solo per disattivazione, non per riattivazione a cascata)
			if ( 0 === $new_is_active ) {
				$family_members_wps = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, wp_user_id FROM {$db->table('members')} WHERE parent_member_id = %d",
						$member_id
					)
				);
				foreach ( $family_members_wps as $fm_row ) {
					$wpdb->update(
						$db->table( 'members' ),
						array( 'is_active' => 0 ),
						array( 'id' => $fm_row->id )
					);
					if ( $fm_row->wp_user_id ) {
						SD_Membership_Helper::disable_wp_user( $fm_row->wp_user_id );
					}
				}
			}
		} elseif ( 0 === $new_is_active && $old_data->wp_user_id ) {
			// Anche se is_active non è cambiato ma è 0, assicurati che l'utente sia disabilitato
			SD_Membership_Helper::disable_wp_user( $old_data->wp_user_id );
		}

		// Aggiorna pagamento
		if ( null !== $has_paid_fee ) {
			$pay_data = array(
				'status' => $has_paid_fee ? 'completato' : 'in_attesa',
			);
			if ( ! empty( $payment_date ) ) {
				$pay_data['payment_date'] = $payment_date;
			}
			if ( ! empty( $payment_method ) ) {
				$allowed_methods = array( 'twint', 'paypal', 'stripe', 'bonifico_iban' );
				if ( in_array( $payment_method, $allowed_methods, true ) ) {
					$pay_data['payment_method'] = $payment_method;
				}
			}
			if ( null !== $payment_amount ) {
				$pay_data['amount'] = $payment_amount;
			}
			$pay_data['registered_by'] = get_current_user_id();

			// Upsert pagamento
			$existing_pay = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$db->table('payments')} WHERE member_id = %d AND payment_year = %d",
					$member_id,
					$payment_year
				)
			);

			if ( $existing_pay ) {
				$wpdb->update( $db->table( 'payments' ), $pay_data, array( 'id' => $existing_pay ) );
			} else {
				$pay_data['member_id']    = $member_id;
				$pay_data['currency']     = 'CHF';
				$pay_data['payment_year'] = $payment_year;
				if ( empty( $pay_data['amount'] ) ) {
					$pay_data['amount'] = $old_data->fee_amount ?? 50;
				}
				if ( empty( $pay_data['payment_method'] ) ) {
					$pay_data['payment_method'] = 'bonifico_iban';
				}
				$wpdb->insert( $db->table( 'payments' ), $pay_data );
			}
		}

		// Aggiorna ruoli WP se richiesti:
		// Sostituisce SOLO i ruoli SD, non tocca administrator o altri ruoli nativi WP
		if ( isset( $_POST['wp_roles'] ) && $old_data->wp_user_id ) {
			$sd_roles   = array( 'sd_diver_diabetic', 'sd_diver', 'sd_staff', 'sd_medical' );
			$allowed_wp = array_merge( $sd_roles, array( 'subscriber' ) );

			// Sanitize e filtra i ruoli inviati
			$new_roles = array_filter(
				array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['wp_roles'] ) ),
				fn( $r ) => in_array( $r, $allowed_wp, true )
			);

			$wp_user = new WP_User( $old_data->wp_user_id );

			// Rimuovi tutti i ruoli SD precedenti (mai il ruolo administrator)
			foreach ( $sd_roles as $sd_role ) {
				$wp_user->remove_role( $sd_role );
			}
			if ( ! $wp_user->has_cap( 'administrator' ) ) {
				$wp_user->remove_role( 'subscriber' );
			}
			// Aggiungi i nuovi ruoli SD selezionati
			foreach ( $new_roles as $new_role ) {
				$wp_user->add_role( $new_role );
			}
			// Per non-admin senza ruoli selezionati, fallback a subscriber
			if ( empty( $new_roles ) && ! $wp_user->has_cap( 'administrator' ) ) {
				$wp_user->add_role( 'subscriber' );
			}
		}

		// Aggiorna profilo subacqueo se i campi esistono
		if ( $old_data->wp_user_id ) {
			$dp_fields = array();
			if ( isset( $_POST['weight'] ) ) {
				$dp_fields['weight'] = ! empty( $_POST['weight'] ) ? floatval( $_POST['weight'] ) : null;
			}
			if ( isset( $_POST['height'] ) ) {
				$dp_fields['height'] = ! empty( $_POST['height'] ) ? absint( $_POST['height'] ) : null;
			}
			if ( isset( $_POST['blood_type'] ) ) {
				$dp_fields['blood_type'] = sanitize_text_field( wp_unslash( $_POST['blood_type'] ) );
			}

			if ( ! empty( $dp_fields ) ) {
				$existing_dp = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$db->table('diver_profiles')} WHERE user_id = %d",
						$old_data->wp_user_id
					)
				);
				if ( $existing_dp ) {
					$wpdb->update( $db->table( 'diver_profiles' ), $dp_fields, array( 'user_id' => $old_data->wp_user_id ) );
				}
			}
		}

		// Audit log
		$new_data = array_merge( (array) $old_data, $update_data );
		SD_Membership_Helper::log_audit( $member_id, 'update', 'sd_members', $member_id, (array) $old_data, $new_data );

		wp_send_json_success( array( 'message' => __( 'Dati aggiornati con successo.', 'sd-logbook' ) ) );
	}

	/**
	 * AJAX: esporta soci in CSV o XLSX
	 */
	public function export_members() {
		check_ajax_referer( 'sd_membership_admin_nonce', 'nonce' );

		if ( ! $this->check_access() ) {
			wp_send_json_error( array( 'message' => __( 'Accesso negato.', 'sd-logbook' ) ) );
		}

		global $wpdb;
		$db     = new SD_Database();
		$format = sanitize_text_field( wp_unslash( $_POST['format'] ?? 'csv' ) );
		$year   = absint( $_POST['anno'] ?? gmdate( 'Y' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.first_name, m.last_name, m.email, m.phone,
				        m.date_of_birth, m.gender, m.address_street, m.address_postal,
				        m.address_city, m.address_country, m.fee_amount, m.has_paid_fee,
				        m.member_type, m.is_scuba, m.diabetes_type, m.member_since,
				        m.membership_expiry, m.registered_at,
				        p.payment_date, p.payment_method, p.status as payment_status
				 FROM {$db->table('members')} m
				 LEFT JOIN {$db->table('payments')} p
				   ON p.member_id = m.id AND p.payment_year = %d
				 WHERE m.is_active = 1
				 ORDER BY m.last_name, m.first_name",
				$year
			),
			ARRAY_A
		);

		$headers_row = array(
			__( 'ID', 'sd-logbook' ),
			__( 'Nome', 'sd-logbook' ),
			__( 'Cognome', 'sd-logbook' ),
			__( 'Email', 'sd-logbook' ),
			__( 'Telefono', 'sd-logbook' ),
			__( 'Data Nascita', 'sd-logbook' ),
			__( 'Genere', 'sd-logbook' ),
			__( 'Via', 'sd-logbook' ),
			__( 'CAP', 'sd-logbook' ),
			__( 'Città', 'sd-logbook' ),
			__( 'Paese', 'sd-logbook' ),
			__( 'Tassa CHF', 'sd-logbook' ),
			__( 'Pagato', 'sd-logbook' ),
			__( 'Tipo Socio', 'sd-logbook' ),
			__( 'Subacqueo', 'sd-logbook' ),
			__( 'Diabete', 'sd-logbook' ),
			__( 'Socio dal', 'sd-logbook' ),
			__( 'Scadenza', 'sd-logbook' ),
			__( 'Registrato il', 'sd-logbook' ),
			__( 'Data Pagamento', 'sd-logbook' ),
			__( 'Metodo Pagamento', 'sd-logbook' ),
			__( 'Stato Pagamento', 'sd-logbook' ),
		);

		$filename = 'soci-scubadiabetes-' . $year . '-' . gmdate( 'Ymd' );

		if ( 'xlsx' === $format ) {
			// Export come Spreadsheet XML (aperto da Excel/LibreOffice)
			header( 'Content-Type: application/vnd.ms-excel; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '.xls"' );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );

			echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
			echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
			echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
			echo '<Worksheet ss:Name="Soci"><Table>';

			// Intestazioni
			echo '<Row>';
			foreach ( $headers_row as $h ) {
				echo '<Cell><Data ss:Type="String">' . esc_html( $h ) . '</Data></Cell>';
			}
			echo '</Row>';

			foreach ( $rows as $row ) {
				echo '<Row>';
				foreach ( $row as $cell ) {
					$type = is_numeric( $cell ) ? 'Number' : 'String';
					echo '<Cell><Data ss:Type="' . $type . '">' . esc_html( (string) $cell ) . '</Data></Cell>';
				}
				echo '</Row>';
			}

			echo '</Table></Worksheet></Workbook>';
			exit;
		}

		// Default: CSV
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // BOM UTF-8
		fputcsv( $output, $headers_row, ';' );

		foreach ( $rows as $row ) {
			fputcsv( $output, array_values( $row ), ';' );
		}

		fclose( $output );
		exit;
	}

	/**
	 * WP Cron: invia reminder rinnovo 30 giorni prima della scadenza
	 */
	public function send_renewal_reminders() {
		global $wpdb;
		$db = new SD_Database();

		$thirty_days_from_now = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
		$today                = gmdate( 'Y-m-d' );

		// Solo soci attivi, non pagati, con scadenza entro 30 giorni
		$members = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('members')}
				 WHERE is_active = 1
				   AND has_paid_fee = 0
				   AND membership_expiry BETWEEN %s AND %s
				   AND member_type IN ('attivo', 'accompagnatore', 'sostenitore')",
				$today,
				$thirty_days_from_now
			)
		);

		foreach ( $members as $member ) {
			/* translators: 1: year, 2: expiry date */
			$subject = sprintf(
				__( '[ScubaDiabetes] Rinnovo iscrizione %1$s - Scadenza %2$s', 'sd-logbook' ),
				gmdate( 'Y' ),
				$member->membership_expiry
			);

			$body  = '<html><body>';
			/* translators: member full name */
			$body .= '<p>' . sprintf( __( 'Caro/a <strong>%s</strong>,', 'sd-logbook' ), esc_html( $member->first_name . ' ' . $member->last_name ) ) . '</p>';
			/* translators: membership expiry date */
			$body .= '<p>' . sprintf( __( 'La tua iscrizione all\'Associazione ScubaDiabetes scade il <strong>%s</strong>.', 'sd-logbook' ), esc_html( $member->membership_expiry ) ) . '</p>';
			$body .= '<p>' . __( 'Per rinnovare l\'iscrizione, effettua il pagamento della tassa annuale:', 'sd-logbook' ) . '</p>';
			/* translators: fee amount in CHF */
			$body .= '<ul><li>' . sprintf( __( 'Importo: <strong>CHF %s</strong>', 'sd-logbook' ), number_format( (float) $member->fee_amount, 2 ) ) . '</li></ul>';
			/* translators: admin email address */
			$body .= '<p>' . sprintf( __( 'Per informazioni contatta il segretariato: <a href="mailto:%1$s">%2$s</a>', 'sd-logbook' ), esc_attr( get_option( 'admin_email' ) ), esc_html( get_option( 'admin_email' ) ) ) . '</p>';
			$body .= '<p>' . __( 'Cordiali saluti,', 'sd-logbook' ) . '<br>ScubaDiabetes</p>';
			$body .= '</body></html>';

			wp_mail(
				$member->email,
				$subject,
				$body,
				array( 'Content-Type: text/html; charset=UTF-8' )
			);

			SD_Membership_Helper::log_audit( $member->id, 'renewal_reminder', 'sd_members', $member->id, null, array( 'expiry' => $member->membership_expiry ) );
		}
	}
}
