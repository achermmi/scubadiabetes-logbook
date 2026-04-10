<?php
/**
 * Importazione Immersioni da Computer di Immersione
 *
 * Supporta:
 *  - Subsurface (.ssrf)  — XML con tutti i dati dell'immersione
 *  - Shearwater Cloud (.db) — SQLite3 esportato dall'app Shearwater Cloud
 *
 * Shortcode: [sd_dive_import]
 *
 * Flusso:
 *  1. L'utente carica il file
 *  2. Il backend analizza e restituisce un'anteprima delle immersioni trovate
 *  3. L'utente seleziona quali importare (pre-filtrate per i duplicati)
 *  4. Conferma → INSERT nelle tabelle sd_dives
 *
 * Anti-duplicati: controllo su (user_id, dive_date, time_in, max_depth)
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Dive_Import {

	public function __construct() {
		add_shortcode( 'sd_dive_import', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sd_import_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_sd_import_confirm', array( $this, 'ajax_confirm' ) );
	}

	/* ================================================================
	 * Assets
	 * ============================================================== */
	public function enqueue_assets() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'sd_dive_import' ) ) {
			return;
		}
		wp_enqueue_style( 'sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-dive-import', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-import.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
		wp_enqueue_script( 'sd-dive-import', SD_LOGBOOK_PLUGIN_URL . 'assets/js/dive-import.js', array( 'jquery' ), SD_LOGBOOK_VERSION, true );
		wp_localize_script(
			'sd-dive-import',
			'sdImport',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sd_dive_import_nonce' ),
			)
		);
	}

	/* ================================================================
	 * Shortcode render
	 * ============================================================== */
	public function render() {
		if ( ! is_user_logged_in() ) {
			return '<div class="sd-notice sd-notice-warning">'
				. __( 'Devi effettuare il login per importare immersioni.', 'sd-logbook' )
				. ' <a href="' . SD_Logbook::get_login_url() . '">' . __( 'Accedi', 'sd-logbook' ) . '</a></div>';
		}
		if ( ! current_user_can( 'sd_log_dive' ) ) {
			return '<div class="sd-notice sd-notice-error">' . __( 'Non hai i permessi.', 'sd-logbook' ) . '</div>';
		}
		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/dive-import.php';
		return ob_get_clean();
	}

	/* ================================================================
	 * AJAX: Preview (parse file, return dive list JSON)
	 * ============================================================== */
	public function ajax_preview() {
		check_ajax_referer( 'sd_dive_import_nonce', 'nonce' );
		if ( ! current_user_can( 'sd_log_dive' ) ) {
			wp_send_json_error( array( 'message' => 'Non autorizzato' ) );
		}

		if ( empty( $_FILES['import_file'] ) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( array( 'message' => 'Nessun file ricevuto o errore di upload.' ) );
		}

		$file      = $_FILES['import_file'];
		$tmp_path  = $file['tmp_name'];
		$orig_name = sanitize_file_name( $file['name'] );
		$ext       = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );

		// Max 50 MB
		if ( $file['size'] > 50 * 1024 * 1024 ) {
			wp_send_json_error( array( 'message' => 'File troppo grande (max 50 MB).' ) );
		}

		$allowed = array( 'ssrf', 'db' );
		if ( ! in_array( $ext, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => 'Formato non supportato. Usa .ssrf (Subsurface) o .db (Shearwater Cloud).' ) );
		}

		try {
			if ( $ext === 'ssrf' ) {
				$dives = $this->parse_ssrf( $tmp_path );
			} else {
				$dives = $this->parse_shearwater_db( $tmp_path );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => 'Errore parsing: ' . $e->getMessage() ) );
		}

		if ( empty( $dives ) ) {
			wp_send_json_error( array( 'message' => 'Nessuna immersione trovata nel file.' ) );
		}

		// Mark duplicates
		$user_id = get_current_user_id();
		foreach ( $dives as &$dive ) {
			$dive['is_duplicate'] = $this->is_duplicate( $user_id, $dive );
		}
		unset( $dive );

		$new_count = count( array_filter( $dives, fn( $d ) => ! $d['is_duplicate'] ) );
		$dup_count = count( $dives ) - $new_count;

		wp_send_json_success( array(
			'dives'     => $dives,
			'total'     => count( $dives ),
			'new'       => $new_count,
			'duplicate' => $dup_count,
			'source'    => strtoupper( $ext ),
		) );
	}

	/* ================================================================
	 * AJAX: Confirm import (insert selected dives)
	 * ============================================================== */
	public function ajax_confirm() {
		check_ajax_referer( 'sd_dive_import_nonce', 'nonce' );
		if ( ! current_user_can( 'sd_log_dive' ) ) {
			wp_send_json_error( array( 'message' => 'Non autorizzato' ) );
		}

		$raw = file_get_contents( 'php://input' );
		$payload = json_decode( $raw, true );
		if ( ! isset( $payload['dives'] ) || ! is_array( $payload['dives'] ) ) {
			wp_send_json_error( array( 'message' => 'Dati non validi.' ) );
		}

		$user_id  = get_current_user_id();
		global $wpdb;
		$db       = new SD_Database();
		$table    = $db->table( 'dives' );

		// Next dive number
		$last_num = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(dive_number) FROM {$table} WHERE user_id = %d", $user_id )
		);

		$imported = 0;
		$skipped  = 0;
		$errors   = array();

		foreach ( $payload['dives'] as $raw_dive ) {
			// Safety: re-check duplicate at insert time
			if ( $this->is_duplicate( $user_id, $raw_dive ) ) {
				$skipped++;
				continue;
			}

			$last_num++;
			$data = $this->sanitize_dive_row( $raw_dive, $user_id, $last_num );

			$result = $wpdb->insert( $table, $data );
			if ( false === $result ) {
				$errors[] = $raw_dive['site_name'] . ' (' . $raw_dive['dive_date'] . ')';
				$last_num--; // rollback counter
			} else {
				$imported++;
			}
		}

		wp_send_json_success( array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		) );
	}

	/* ================================================================
	 * Parser: Subsurface .ssrf (XML)
	 * ============================================================== */
	private function parse_ssrf( $path ) {
		$xml_str = file_get_contents( $path );
		if ( ! $xml_str ) {
			throw new Exception( 'Impossibile leggere il file.' );
		}

		// Silence XML errors, parse
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $xml_str );
		if ( ! $xml ) {
			throw new Exception( 'File SSRF non valido (XML malformato).' );
		}

		// Build site UUID → name/GPS map
		$sites = array();
		if ( isset( $xml->divesites->site ) ) {
			foreach ( $xml->divesites->site as $site ) {
				$uuid           = (string) $site['uuid'];
				$sites[ $uuid ] = array(
					'name' => (string) $site['name'],
					'gps'  => (string) $site['gps'],
				);
			}
		}

		$dives = array();
		if ( ! isset( $xml->dives->dive ) ) {
			return $dives;
		}

		foreach ( $xml->dives->dive as $d ) {
			// Date / time
			$date_str = (string) $d['date'];   // 2025-11-22
			$time_str = (string) $d['time'];   // 15:40:15

			// Duration "32:10 min" → minutes
			$dur_raw  = (string) $d['duration'];
			preg_match( '/(\d+):(\d+)\s*min/', $dur_raw, $m );
			$dive_time = isset( $m[1] ) ? ( (int) $m[1] ) : null;

			// Dive number
			$dive_number = (int) $d['number'] ?: null;

			// Site
			$site_name = '';
			$lat       = null;
			$lng       = null;
			$site_id   = (string) $d['divesiteid'];
			if ( $site_id && isset( $sites[ $site_id ] ) ) {
				$site_name = $sites[ $site_id ]['name'];
				$gps       = $sites[ $site_id ]['gps'];
				if ( $gps ) {
					$parts = explode( ' ', $gps );
					if ( count( $parts ) >= 2 ) {
						$lat = (float) $parts[0];
						$lng = (float) $parts[1];
					}
				}
			}

			// Depth / temp from divecomputer element
			$max_depth  = null;
			$avg_depth  = null;
			$temp_water = null;
			if ( isset( $d->divecomputer ) ) {
				$dc = $d->divecomputer;
				if ( isset( $dc->depth ) ) {
					$mx = (string) $dc->depth['max'];
					$av = (string) $dc->depth['mean'];
					preg_match( '/[\d.]+/', $mx, $mxm );
					preg_match( '/[\d.]+/', $av, $avm );
					$max_depth = isset( $mxm[0] ) ? (float) $mxm[0] : null;
					$avg_depth = isset( $avm[0] ) ? (float) $avm[0] : null;
				}
				if ( isset( $dc->temperature ) ) {
					$tw = (string) $dc->temperature['water'];
					preg_match( '/[\d.]+/', $tw, $twm );
					$temp_water = isset( $twm[0] ) ? (float) $twm[0] : null;
				}
				// Pressure from samples (first and last pressure0)
				$pressures = array();
				if ( isset( $dc->sample ) ) {
					foreach ( $dc->sample as $s ) {
						$p = (string) $s['pressure0'];
						preg_match( '/[\d.]+/', $p, $pm );
						if ( isset( $pm[0] ) ) {
							$pressures[] = (float) $pm[0];
						}
					}
				}
				$pressure_start = ! empty( $pressures ) ? (int) reset( $pressures ) : null;
				$pressure_end   = ! empty( $pressures ) ? (int) end( $pressures ) : null;
			} else {
				$pressure_start = null;
				$pressure_end   = null;
			}

			// Tank
			$tank_capacity = null;
			$gas_mix       = 'aria';
			$nitrox_pct    = null;
			if ( isset( $d->cylinder ) ) {
				$sz = (string) $d->cylinder['size'];
				preg_match( '/[\d.]+/', $sz, $szm );
				$tank_capacity = isset( $szm[0] ) ? (float) $szm[0] : null;
				// O2 percent
				$o2 = (string) $d->cylinder['o2'];
				preg_match( '/[\d.]+/', $o2, $o2m );
				if ( isset( $o2m[0] ) ) {
					$pct = (float) $o2m[0];
					if ( $pct > 0 && abs( $pct - 21 ) > 1 ) {
						$gas_mix    = 'nitrox';
						$nitrox_pct = $pct;
					}
				}
			}

			// Ballast
			$ballast_kg = null;
			if ( isset( $d->weightsystem ) ) {
				$w = (string) $d->weightsystem['weight'];
				preg_match( '/[\d.]+/', $w, $wm );
				$ballast_kg = isset( $wm[0] ) ? (float) $wm[0] : null;
			}

			// Buddy / notes / divemaster
			$buddy_name = (string) ( $d->buddy ?? '' );
			$guide_name = (string) ( $d->divemaster ?? '' );
			$notes      = (string) ( $d->notes ?? '' );

			// Visibility rating → string
			$visibility = null;
			$vis_val    = (int) $d['visibility'];
			if ( $vis_val >= 4 ) $visibility = 'buona';
			elseif ( $vis_val >= 2 ) $visibility = 'media';
			elseif ( $vis_val > 0 ) $visibility = 'scarsa';

			$dives[] = array(
				'source'          => 'subsurface',
				'dive_number'     => $dive_number,
				'dive_date'       => $date_str,
				'time_in'         => substr( $time_str, 0, 5 ),
				'dive_time'       => $dive_time,
				'site_name'       => $site_name ?: 'Sito sconosciuto',
				'site_latitude'   => $lat,
				'site_longitude'  => $lng,
				'max_depth'       => $max_depth,
				'avg_depth'       => $avg_depth,
				'temp_water'      => $temp_water,
				'pressure_start'  => $pressure_start,
				'pressure_end'    => $pressure_end,
				'tank_capacity'   => $tank_capacity,
				'gas_mix'         => $gas_mix,
				'nitrox_percentage' => $nitrox_pct,
				'ballast_kg'      => $ballast_kg,
				'visibility'      => $visibility,
				'buddy_name'      => $buddy_name,
				'guide_name'      => $guide_name,
				'notes'           => $notes,
				'suit_type'       => null,
				'dive_type'       => null,
				'weather'         => null,
				'temp_air'        => null,
				'sea_condition'   => null,
				'current_strength' => null,
				'shared_for_research' => 1,
			);
		}

		return $dives;
	}

	/* ================================================================
	 * Parser: Shearwater Cloud .db (SQLite3)
	 * ============================================================== */
	private function parse_shearwater_db( $path ) {
		if ( ! class_exists( 'SQLite3' ) ) {
			throw new Exception( 'SQLite3 non disponibile sul server.' );
		}

		// Copy to writable temp location
		$tmp = sys_get_temp_dir() . '/sd_sw_' . uniqid() . '.db';
		if ( ! copy( $path, $tmp ) ) {
			throw new Exception( 'Impossibile copiare il file temporaneo.' );
		}

		$db = new SQLite3( $tmp, SQLITE3_OPEN_READONLY );
		if ( ! $db ) {
			throw new Exception( 'Impossibile aprire il database Shearwater.' );
		}

		$result = $db->query(
			"SELECT
				DiveId, DiveDate, DiveLengthTime,
				Depth, AverageDepth, AverageTemp, MinTemp,
				Site, Location,
				Buddy,
				DiveNumber,
				Environment,
				Visibility, Weather, Conditions, Platform,
				AirTemperature,
				TankProfileData,
				TankSize, Weight,
				GearNotes, Notes,
				Dress
			FROM dive_details
			ORDER BY DiveDate ASC"
		);

		$dives = array();
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			// Date: '2025-11-22 15:42:32' → date + time
			$dt_parts = explode( ' ', $row['DiveDate'] );
			$date_str = $dt_parts[0] ?? '';
			$time_str = isset( $dt_parts[1] ) ? substr( $dt_parts[1], 0, 5 ) : null;

			// Duration in seconds → minutes
			$dive_time = $row['DiveLengthTime'] ? (int) round( (int) $row['DiveLengthTime'] / 60 ) : null;

			// Depth (already in meters in newer firmware, check value)
			$max_depth = $row['Depth'] ? (float) $row['Depth'] : null;
			$avg_depth = $row['AverageDepth'] ? (float) $row['AverageDepth'] : null;

			// Shearwater stores temperature in Celsius × 10 in log_records, but
			// dive_details AverageTemp is already in °C
			$temp_water = $row['AverageTemp'] ? (float) $row['AverageTemp'] : null;
			$temp_air   = $row['AirTemperature'] ? (float) $row['AirTemperature'] : null;

			// Site name: prefer Site, fallback Location
			$site_name = trim( $row['Site'] ?: $row['Location'] ?: 'Sito sconosciuto' );

			// GPS from GeolocationWaypoints (not selected, skip for now)

			// Dive number
			$dive_number = $row['DiveNumber'] ? (int) $row['DiveNumber'] : null;

			// Tank data from JSON TankProfileData
			$pressure_start = null;
			$pressure_end   = null;
			$gas_mix        = 'aria';
			$nitrox_pct     = null;
			$tank_capacity  = $row['TankSize'] ? (float) $row['TankSize'] : null;

			if ( ! empty( $row['TankProfileData'] ) ) {
				$tank_json = json_decode( $row['TankProfileData'], true );
				if ( $tank_json && isset( $tank_json['TankData'][0] ) ) {
					$t0 = $tank_json['TankData'][0];
					// Pressures in PSI → bar (1 PSI = 0.0689476 bar)
					if ( ! empty( $t0['StartPressurePSI'] ) && is_numeric( $t0['StartPressurePSI'] ) ) {
						$pressure_start = (int) round( (float) $t0['StartPressurePSI'] * 0.0689476 );
					}
					if ( ! empty( $t0['EndPressurePSI'] ) && is_numeric( $t0['EndPressurePSI'] ) ) {
						$pressure_end = (int) round( (float) $t0['EndPressurePSI'] * 0.0689476 );
					}
					// Gas mix
					if ( isset( $tank_json['GasProfiles'][0] ) ) {
						$o2 = (int) $tank_json['GasProfiles'][0]['O2Percent'];
						if ( $o2 > 0 && abs( $o2 - 21 ) > 1 ) {
							$gas_mix    = 'nitrox';
							$nitrox_pct = $o2;
						}
					}
				}
			}

			// Ballast in kg (stored as numeric string)
			$ballast_kg = $row['Weight'] ? (float) $row['Weight'] : null;

			// Environment → dive_type mapping
			$env_map = array(
				'Ocean/Sea'    => 'mare',
				'Lake/Quarry'  => 'lago',
				'River'        => 'fiume',
				'Pool'         => 'piscina',
				'Ice'          => 'ghiaccio',
				'Cave'         => 'grotta',
			);
			$dive_type = $env_map[ $row['Environment'] ] ?? null;

			// Visibility string
			$vis_raw = $row['Visibility'] ?: '';
			preg_match( '/(\d+)/', $vis_raw, $vm );
			$vis_m      = isset( $vm[1] ) ? (int) $vm[1] : 0;
			$visibility = null;
			if ( $vis_m >= 15 ) $visibility = 'buona';
			elseif ( $vis_m >= 5 ) $visibility = 'media';
			elseif ( $vis_m > 0 ) $visibility = 'scarsa';

			// Weather
			$weather_map = array(
				'Sunny'     => 'sereno',
				'Cloudy'    => 'nuvoloso',
				'Overcast'  => 'nuvoloso',
				'Rain'      => 'pioggia',
				'Rainy'     => 'pioggia',
			);
			$weather = $weather_map[ $row['Weather'] ] ?? null;

			// Sea condition from Conditions field
			$cond_map = array(
				'Calm'    => 'calmo',
				'Surge'   => 'mosso',
				'Waves'   => 'mosso',
				'Current' => 'agitato',
				'Strong current' => 'agitato',
			);
			$sea_condition = $cond_map[ $row['Conditions'] ] ?? null;

			// Suit type
			$dress_map = array(
				'Wet Suit'        => 'umida',
				'Semi Dry Suit'   => 'semistagna',
				'Dry Suit'        => 'stagna',
				'Drysuit'         => 'stagna',
			);
			$suit_type = $dress_map[ $row['Dress'] ] ?? null;

			// Notes (merge GearNotes + Notes)
			$notes_parts = array_filter( array( $row['GearNotes'], $row['Notes'] ) );
			$notes       = implode( "\n", $notes_parts );

			$dives[] = array(
				'source'            => 'shearwater',
				'dive_number'       => $dive_number,
				'dive_date'         => $date_str,
				'time_in'           => $time_str,
				'dive_time'         => $dive_time,
				'site_name'         => $site_name,
				'site_latitude'     => null,
				'site_longitude'    => null,
				'max_depth'         => $max_depth,
				'avg_depth'         => $avg_depth,
				'temp_water'        => $temp_water,
				'temp_air'          => $temp_air,
				'pressure_start'    => $pressure_start,
				'pressure_end'      => $pressure_end,
				'tank_capacity'     => $tank_capacity,
				'gas_mix'           => $gas_mix,
				'nitrox_percentage' => $nitrox_pct,
				'ballast_kg'        => $ballast_kg,
				'buddy_name'        => $row['Buddy'] ?: null,
				'guide_name'        => null,
				'notes'             => $notes ?: null,
				'dive_type'         => $dive_type,
				'visibility'        => $visibility,
				'weather'           => $weather,
				'sea_condition'     => $sea_condition,
				'suit_type'         => $suit_type,
				'current_strength'  => null,
				'shared_for_research' => 1,
				'_shearwater_id'    => $row['DiveId'], // extra field, not inserted
			);
		}

		$db->close();
		@unlink( $tmp );
		return $dives;
	}

	/* ================================================================
	 * Duplicate check: same user + same date + time_in within 5 min + depth within 1m
	 * ============================================================== */
	private function is_duplicate( $user_id, $dive ) {
		global $wpdb;
		$db    = new SD_Database();
		$table = $db->table( 'dives' );

		$date = sanitize_text_field( $dive['dive_date'] ?? '' );
		if ( ! $date ) return false;

		// Check 1: same date + time (within 10 minutes)
		$time_in = sanitize_text_field( $dive['time_in'] ?? '' );
		if ( $time_in ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table}
					 WHERE user_id = %d
					   AND dive_date = %s
					   AND ABS(TIME_TO_SEC(TIMEDIFF(time_in, %s))) < 600",
					$user_id,
					$date,
					$time_in . ':00'
				)
			);
			if ( $count > 0 ) return true;
		} else {
			// Fallback: same date + same max_depth ± 0.5m
			$max_depth = (float) ( $dive['max_depth'] ?? 0 );
			if ( $max_depth > 0 ) {
				$count = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$table}
						 WHERE user_id = %d
						   AND dive_date = %s
						   AND ABS(max_depth - %f) < 0.5",
						$user_id,
						$date,
						$max_depth
					)
				);
				if ( $count > 0 ) return true;
			}
		}
		return false;
	}

	/* ================================================================
	 * Sanitize + type-cast raw dive array for DB insert
	 * ============================================================== */
	private function sanitize_dive_row( $raw, $user_id, $dive_number ) {
		$nullable_str = function( $v ) {
			$s = sanitize_text_field( (string) $v );
			return $s === '' ? null : $s;
		};
		$nullable_float = function( $v ) {
			if ( $v === null || $v === '' ) return null;
			return (float) $v;
		};
		$nullable_int = function( $v ) {
			if ( $v === null || $v === '' ) return null;
			return (int) $v;
		};

		return array(
			'user_id'             => $user_id,
			'dive_number'         => $dive_number,
			'dive_date'           => sanitize_text_field( $raw['dive_date'] ),
			'site_name'           => sanitize_text_field( $raw['site_name'] ?? 'Sito importato' ),
			'site_latitude'       => $nullable_float( $raw['site_latitude'] ),
			'site_longitude'      => $nullable_float( $raw['site_longitude'] ),
			'time_in'             => $nullable_str( $raw['time_in'] ),
			'time_out'            => null,
			'pressure_start'      => $nullable_int( $raw['pressure_start'] ),
			'pressure_end'        => $nullable_int( $raw['pressure_end'] ),
			'max_depth'           => $nullable_float( $raw['max_depth'] ),
			'avg_depth'           => $nullable_float( $raw['avg_depth'] ),
			'dive_time'           => $nullable_int( $raw['dive_time'] ),
			'tank_count'          => 1,
			'tank_capacity'       => $nullable_float( $raw['tank_capacity'] ),
			'gas_mix'             => $nullable_str( $raw['gas_mix'] ) ?? 'aria',
			'nitrox_percentage'   => $nullable_float( $raw['nitrox_percentage'] ),
			'ballast_kg'          => $nullable_float( $raw['ballast_kg'] ),
			'entry_type'          => null,
			'dive_type'           => $nullable_str( $raw['dive_type'] ),
			'weather'             => $nullable_str( $raw['weather'] ),
			'temp_air'            => $nullable_float( $raw['temp_air'] ),
			'temp_water'          => $nullable_float( $raw['temp_water'] ),
			'sea_condition'       => $nullable_str( $raw['sea_condition'] ),
			'current_strength'    => $nullable_str( $raw['current_strength'] ),
			'visibility'          => $nullable_str( $raw['visibility'] ),
			'suit_type'           => $nullable_str( $raw['suit_type'] ),
			'sightings'           => null,
			'other_equipment'     => null,
			'notes'               => $nullable_str( $raw['notes'] ),
			'buddy_name'          => $nullable_str( $raw['buddy_name'] ),
			'guide_name'          => $nullable_str( $raw['guide_name'] ),
			'shared_for_research' => isset( $raw['shared_for_research'] ) ? (int) $raw['shared_for_research'] : 1,
		);
	}
}
