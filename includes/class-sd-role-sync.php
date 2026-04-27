<?php
/**
 * Sincronizzazione automatica ruoli → soci
 *
 * Quando a un utente WordPress viene assegnato uno dei ruoli gestiti
 * (sd_diver_diabetic, sd_diver, sd_staff, sd_medical, subscriber),
 * il sistema verifica se esiste già un record in sd_members.
 * Se non esiste, lo crea utilizzando i dati disponibili da wp_users
 * e sd_diver_profiles.
 *
 * Regola diabetes_type per sd_diver_diabetic:
 *   - Nuovo record o valore attuale NULL → 'altro'
 *   - Valore già presente nel DB         → invariato
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Role_Sync {

	/**
	 * Ruoli che attivano la sincronizzazione
	 */
	const TRIGGER_ROLES = array(
		'sd_diver_diabetic',
		'sd_diver',
		'sd_staff',
		'sd_medical',
		'subscriber',
	);

	public function __construct() {
		// set_role() → usato dall'admin WP e da assign_wp_role()
		add_action( 'set_user_role', array( $this, 'on_role_assigned' ), 20, 2 );
		// add_role() → usato quando si aggiunge un ruolo secondario
		add_action( 'add_user_role', array( $this, 'on_role_assigned' ), 20, 2 );
	}

	/**
	 * Callback comune per set_user_role / add_user_role
	 *
	 * @param int    $user_id ID utente WordPress
	 * @param string $role    Ruolo assegnato
	 */
	public function on_role_assigned( $user_id, $role ) {
		if ( ! in_array( $role, self::TRIGGER_ROLES, true ) ) {
			return;
		}

		// Evita loop ricorsivi (assign_wp_role può ri-triggerare)
		static $processing = array();
		if ( ! empty( $processing[ $user_id ] ) ) {
			return;
		}
		$processing[ $user_id ] = true;

		$this->maybe_create_member( $user_id, $role );
		SD_Membership_Helper::sync_diabetes_consistency_for_user( $user_id );

		unset( $processing[ $user_id ] );
	}

	/**
	 * Crea o aggiorna il record socio al cambio di ruolo
	 *
	 * @param int    $user_id ID utente WordPress
	 * @param string $role    Ruolo assegnato
	 */
	private function maybe_create_member( $user_id, $role ) {
		global $wpdb;
		$db = new SD_Database();

		// Carica dati utente WP
		$wp_user = get_userdata( $user_id );
		if ( ! $wp_user ) {
			return;
		}

		// --- Caso A: record già esistente per wp_user_id ---
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, diabetes_type FROM {$db->table('members')} WHERE wp_user_id = %d LIMIT 1",
				$user_id
			)
		);

		if ( $existing ) {
			if ( 'subscriber' !== $role ) {
				$attrs  = $this->role_to_attrs( $role );
				$update = array(
					'is_scuba'    => $attrs['is_scuba'],
					'member_type' => $attrs['member_type'],
				);
				// Per sd_diver_diabetic: correggi anche il caso legacy non_diabetico.
				if ( 'sd_diver_diabetic' === $role ) {
					if ( empty( $existing->diabetes_type ) || 'non_diabetico' === $existing->diabetes_type ) {
						$update['diabetes_type'] = 'altro';
					}
					// altrimenti mantieni il valore presente
				} else {
					$update['diabetes_type'] = $attrs['diabetes_type'];
				}
				$wpdb->update(
					$db->table( 'members' ),
					$update,
					array( 'id' => $existing->id )
				);
				// Sincronizza diver profile se ruolo subacqueo
				if ( $attrs['is_scuba'] ) {
					$this->sync_diver_profile_from_member( $user_id, $db, $wpdb );
				}
			}
			// subscriber su membro esistente: non modificare nulla
			return;
		}

		// --- Caso B: record esistente per email (wp_user_id non collegato) ---
		$by_email = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, diabetes_type FROM {$db->table('members')} WHERE email = %s LIMIT 1",
				$wp_user->user_email
			)
		);

		if ( $by_email ) {
			$update = array( 'wp_user_id' => $user_id );
			$is_scuba_role = false;
			if ( 'subscriber' !== $role ) {
				$attrs                = $this->role_to_attrs( $role );
				$update['is_scuba']   = $attrs['is_scuba'];
				$update['member_type'] = $attrs['member_type'];
				if ( 'sd_diver_diabetic' === $role ) {
					if ( empty( $by_email->diabetes_type ) || 'non_diabetico' === $by_email->diabetes_type ) {
						$update['diabetes_type'] = 'altro';
					}
				} else {
					$update['diabetes_type'] = $attrs['diabetes_type'];
				}
				$is_scuba_role = $attrs['is_scuba'];
			}
			$wpdb->update(
				$db->table( 'members' ),
				$update,
				array( 'id' => $by_email->id )
			);
			// Sincronizza diver profile se ruolo subacqueo
			if ( $is_scuba_role ) {
				$this->sync_diver_profile_from_member( $user_id, $db, $wpdb );
			}
			return;
		}

		// --- Caso C: nessun record esistente → crea nuovo ---
		$this->create_member( $user_id, $role, $wp_user, $db );
		// Sincronizza diver profile dopo la creazione del membro
		$attrs = $this->role_to_attrs( $role );
		if ( $attrs['is_scuba'] ) {
			$this->sync_diver_profile_from_member( $user_id, $db, $wpdb );
		}
	}

	/**
	 * Inserisce un nuovo record in sd_members
	 *
	 * @param int        $user_id ID utente WordPress
	 * @param string     $role    Ruolo assegnato
	 * @param WP_User    $wp_user Oggetto utente WordPress
	 * @param SD_Database $db     Istanza database
	 */
	private function create_member( $user_id, $role, $wp_user, $db ) {
		global $wpdb;

		// Recupera nome e cognome da user meta
		$first_name = get_user_meta( $user_id, 'first_name', true );
		$last_name  = get_user_meta( $user_id, 'last_name', true );

		// Fallback: split display_name
		if ( empty( $first_name ) && empty( $last_name ) && ! empty( $wp_user->display_name ) ) {
			$parts      = explode( ' ', $wp_user->display_name, 2 );
			$first_name = $parts[0];
			$last_name  = isset( $parts[1] ) ? $parts[1] : '';
		}
		if ( empty( $first_name ) ) {
			$first_name = $wp_user->user_login;
		}

		// Carica profilo subacqueo se esiste
		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('diver_profiles')} WHERE user_id = %d LIMIT 1",
				$user_id
			)
		);

		$attrs = $this->role_to_attrs( $role );

		// diabetes_type per sd_diver_diabetic: 'altro' come default
		// (il profilo non sovrascrive: l'operatore deve impostarlo esplicitamente)
		$diabetes_type = $attrs['diabetes_type'];

		$registered_at = $wp_user->user_registered;

		$member_data = array(
			'wp_user_id'        => $user_id,
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'email'             => $wp_user->user_email,
			'is_active'         => 1,
			'has_paid_fee'      => 0,
			'member_type'       => $attrs['member_type'],
			'is_scuba'          => $attrs['is_scuba'],
			'diabetes_type'     => $diabetes_type,
			'member_since'      => gmdate( 'Y-m-d', strtotime( $registered_at ) ),
			'membership_expiry' => gmdate( 'Y-12-31' ),
			'registered_at'     => $registered_at,
			'privacy_consent'   => 0,
		);

		// Integra dati dal profilo subacqueo se disponibili
		if ( $profile ) {
			if ( ! empty( $profile->birth_date ) ) {
				$member_data['date_of_birth'] = $profile->birth_date;
			}
			if ( ! empty( $profile->gender ) ) {
				$member_data['gender'] = $profile->gender;
			}
			if ( ! empty( $profile->phone ) ) {
				$member_data['phone'] = $profile->phone;
			} elseif ( ! empty( $profile->gsm ) ) {
				$member_data['phone'] = $profile->gsm;
			}
			if ( ! empty( $profile->address ) ) {
				$member_data['address_street'] = $profile->address;
			}
			if ( ! empty( $profile->zip ) ) {
				$member_data['address_postal'] = $profile->zip;
			}
			if ( ! empty( $profile->city ) ) {
				$member_data['address_city'] = $profile->city;
			}
			if ( ! empty( $profile->diabetology_center ) ) {
				$member_data['diabetology_center'] = $profile->diabetology_center;
			}
		}

		$wpdb->insert( $db->table( 'members' ), $member_data );
	}

	/**
	 * Crea o aggiorna wp_sd_diver_profiles con i dati anagrafici da wp_sd_members.
	 * I campi anagrafici vengono compilati solo se vuoti; diabetology_center viene invece
	 * mantenuto sincronizzato con sd_members (sorgente di verita lato iscrizione/admin).
	 *
	 * @param int         $user_id ID utente WordPress
	 * @param SD_Database $db      Istanza database
	 * @param wpdb        $wpdb    Istanza wpdb
	 */
	private function sync_diver_profile_from_member( $user_id, $db, $wpdb ) {
		// Legge il record socio
		$member = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('members')} WHERE wp_user_id = %d LIMIT 1",
				$user_id
			)
		);
		if ( ! $member ) {
			return;
		}

		// Legge il profilo subacqueo esistente (se presente)
		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$db->table('diver_profiles')} WHERE user_id = %d LIMIT 1",
				$user_id
			)
		);

		// Mappa campi members → diver_profiles (sovrascrive solo se il campo di destinazione è vuoto)
		$sync_fields = array(
			'phone'      => $member->phone,
			'address'    => $member->address_street,
			'zip'        => $member->address_postal,
			'city'       => $member->address_city,
			'birth_date' => $member->date_of_birth,
			'gender'     => $member->gender,
		);

		if ( $profile ) {
			// Aggiorna solo i campi vuoti nel profilo esistente
			$update = array();
			foreach ( $sync_fields as $profile_field => $member_value ) {
				if ( ! empty( $member_value ) && empty( $profile->$profile_field ) ) {
					$update[ $profile_field ] = $member_value;
				}
			}
			if ( isset( $member->diabetology_center ) ) {
				$member_center  = sanitize_text_field( (string) $member->diabetology_center );
				$profile_center = isset( $profile->diabetology_center ) ? (string) $profile->diabetology_center : '';
				if ( $member_center !== $profile_center ) {
					$update['diabetology_center'] = '' !== $member_center ? $member_center : null;
				}
			}
			if ( ! empty( $update ) ) {
				$wpdb->update( $db->table( 'diver_profiles' ), $update, array( 'user_id' => $user_id ) );
			}
		} else {
			// Crea nuovo profilo con i dati anagrafici disponibili
			$insert = array( 'user_id' => $user_id );
			foreach ( $sync_fields as $profile_field => $member_value ) {
				if ( ! empty( $member_value ) ) {
					$insert[ $profile_field ] = $member_value;
				}
			}
			if ( ! empty( $member->diabetology_center ) ) {
				$insert['diabetology_center'] = sanitize_text_field( (string) $member->diabetology_center );
			}
			$wpdb->insert( $db->table( 'diver_profiles' ), $insert );
		}
	}

	/**
	 * Mappa ruolo WP → attributi strutturali del record socio
	 * Non include diabetes_type per sd_diver_diabetic (gestito separatamente).
	 *
	 * @param string $role Ruolo WordPress
	 * @return array { member_type, is_scuba, diabetes_type }
	 */
	private function role_to_attrs( $role ) {
		switch ( $role ) {
			case 'sd_diver_diabetic':
				return array(
					'member_type'   => 'attivo',
					'is_scuba'      => 1,
					'diabetes_type' => 'altro',
				);

			case 'sd_diver':
				return array(
					'member_type'   => 'attivo',
					'is_scuba'      => 1,
					'diabetes_type' => 'non_diabetico',
				);

			case 'sd_staff':
				return array(
					'member_type'   => 'staff',
					'is_scuba'      => 0,
					'diabetes_type' => 'non_diabetico',
				);

			case 'sd_medical':
				return array(
					'member_type'   => 'medico',
					'is_scuba'      => 0,
					'diabetes_type' => 'non_diabetico',
				);

			case 'subscriber':
			default:
				return array(
					'member_type'   => 'attivo',
					'is_scuba'      => 0,
					'diabetes_type' => 'non_diabetico',
				);
		}
	}
}
