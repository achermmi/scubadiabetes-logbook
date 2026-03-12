<?php
/**
 * Gestione Ruoli Utente
 *
 * Ruoli specifici del plugin:
 * - sd_diver_diabetic:    Subacqueo diabetico
 * - sd_diver:             Subacqueo NON diabetico
 * - sd_medical:           Medico
 * - sd_staff:             Staff
 * - administrator:        Admin WordPress (già esistente)
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Roles {

	/**
	 * Definizione dei ruoli del plugin
	 */
	private $roles = array();

	public function __construct() {
		$this->roles = array(
			'sd_diver_diabetic' => array(
				'display_name' => 'Subacqueo Diabetico',
				'capabilities' => array(
					'read'                    => true,
					'sd_log_dive'             => true,
					'sd_log_diabetes'         => true,
					'sd_view_own_dives'       => true,
					'sd_edit_own_dives'       => true,
					'sd_view_own_diabetes'    => true,
					'sd_edit_own_diabetes'    => true,
					'sd_view_own_dashboard'   => true,
					'sd_export_own_data'      => true,
				),
			),
			'sd_diver' => array(
				'display_name' => 'Subacqueo',
				'capabilities' => array(
					'read'                    => true,
					'sd_log_dive'             => true,
					'sd_view_own_dives'       => true,
					'sd_edit_own_dives'       => true,
					'sd_view_own_dashboard'   => true,
					'sd_export_own_data'      => true,
				),
			),
			'sd_medical' => array(
				'display_name' => 'Medico SD',
				'capabilities' => array(
					'read'                    => true,
					'sd_log_dive'             => true,
					'sd_log_diabetes'         => true,
					'sd_view_own_dives'       => true,
					'sd_edit_own_dives'       => true,
					'sd_view_own_diabetes'    => true,
					'sd_view_all_dives'       => true,
					'sd_view_all_diabetes'    => true,
					'sd_supervise'            => true,
					'sd_approve_dive'         => true,
					'sd_export_all_data'      => true,
					'sd_view_dashboard_all'   => true,
					'sd_manage_profiles'      => true,
				),
			),
			'sd_staff' => array(
				'display_name' => 'Staff SD',
				'capabilities' => array(
					'read'                    => true,
					'sd_log_dive'             => true,
					'sd_log_diabetes'         => true,
					'sd_view_own_dives'       => true,
					'sd_edit_own_dives'       => true,
					'sd_view_all_dives'       => true,
					'sd_view_all_diabetes'    => true,
					'sd_supervise'            => true,
					'sd_view_dashboard_all'   => true,
				),
			),
		);
	}

	/**
	 * Crea i ruoli personalizzati
	 */
	public function create_roles() {
		foreach ( $this->roles as $role_slug => $role_data ) {
			remove_role( $role_slug );
			add_role( $role_slug, $role_data['display_name'], $role_data['capabilities'] );
		}

		// Aggiungi le capabilities del plugin anche all'admin
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$all_caps = $this->get_all_capabilities();
			foreach ( $all_caps as $cap ) {
				$admin_role->add_cap( $cap );
			}
		}
	}

	/**
	 * Rimuovi i ruoli personalizzati
	 */
	public function remove_roles() {
		foreach ( array_keys( $this->roles ) as $role_slug ) {
			remove_role( $role_slug );
		}

		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$all_caps = $this->get_all_capabilities();
			foreach ( $all_caps as $cap ) {
				$admin_role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Ottieni tutte le capabilities uniche del plugin
	 */
	public function get_all_capabilities() {
		$all_caps = array();
		foreach ( $this->roles as $role_data ) {
			foreach ( array_keys( $role_data['capabilities'] ) as $cap ) {
				if ( $cap !== 'read' ) {
					$all_caps[] = $cap;
				}
			}
		}
		return array_unique( $all_caps );
	}

	/**
	 * Controlla se un utente è un subacqueo diabetico
	 */
	public static function is_diabetic_diver( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return in_array( 'sd_diver_diabetic', (array) $user->roles, true );
	}

	/**
	 * Controlla se un utente ha il ruolo di medico
	 */
	public static function is_medical( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return in_array( 'sd_medical', (array) $user->roles, true );
	}

	/**
	 * Controlla se un utente ha il ruolo di staff
	 */
	public static function is_staff( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return in_array( 'sd_staff', (array) $user->roles, true );
	}

	/**
	 * Controlla se un utente può supervisionare
	 */
	public static function can_supervise( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		return user_can( $user_id, 'sd_supervise' );
	}

	/**
	 * Controlla se un utente può vedere tutti i dati
	 */
	public static function can_view_all( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		return user_can( $user_id, 'sd_view_all_dives' );
	}

	/**
	 * Controlla se un utente può esportare tutti i dati
	 */
	public static function can_export_all( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		return user_can( $user_id, 'sd_export_all_data' );
	}

	/**
	 * Ottieni la lista dei ruoli del plugin
	 */
	public function get_roles() {
		return $this->roles;
	}

	/**
	 * Controlla se un utente è un subacqueo (diabetico o non)
	 */
	public static function is_diver( $user_id = null ) {
		if ( ! $user_id ) $user_id = get_current_user_id();
		$user = get_userdata( $user_id );
		if ( ! $user ) return false;
		$roles = (array) $user->roles;
		return in_array( 'sd_diver_diabetic', $roles, true ) || in_array( 'sd_diver', $roles, true );
	}

	/**
	 * Restituisce un array di badges HTML per tutti i ruoli SD dell'utente
	 * Utile per mostrare ruoli multipli (es: Medico + Subacqueo Diabetico)
	 */
	public static function get_role_badges( $user_id = null ) {
		if ( ! $user_id ) $user_id = get_current_user_id();
		$user = get_userdata( $user_id );
		if ( ! $user ) return array();

		$roles = (array) $user->roles;
		$badges = array();

		$map = array(
			'sd_diver_diabetic' => array( 'label' => __( 'Subacqueo Diabetico', 'sd-logbook' ), 'class' => 'sd-badge-diabetic' ),
			'sd_diver'          => array( 'label' => __( 'Subacqueo', 'sd-logbook' ),           'class' => 'sd-badge-diver' ),
			'sd_medical'        => array( 'label' => __( 'Medico', 'sd-logbook' ),              'class' => 'sd-badge-medical' ),
			'sd_staff'          => array( 'label' => __( 'Staff', 'sd-logbook' ),               'class' => 'sd-badge-staff' ),
		);

		foreach ( $map as $role => $info ) {
			if ( in_array( $role, $roles, true ) ) {
				$badges[] = $info;
			}
		}

		// Fallback if no SD role found
		if ( empty( $badges ) && in_array( 'administrator', $roles, true ) ) {
			$badges[] = array( 'label' => __( 'Admin', 'sd-logbook' ), 'class' => 'sd-badge-staff' );
		}

		return $badges;
	}

	/**
	 * Restituisce HTML dei badges per la user bar
	 */
	public static function render_badges_html( $user_id = null ) {
		$badges = self::get_role_badges( $user_id );
		$html = '';
		foreach ( $badges as $b ) {
			$html .= '<span class="sd-user-badge ' . esc_attr( $b['class'] ) . '">' . esc_html( $b['label'] ) . '</span> ';
		}
		return $html;
	}
}
