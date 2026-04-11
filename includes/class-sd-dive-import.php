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
		add_action( 'wp_ajax_sd_import_schema', array( $this, 'ajax_schema' ) );
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
	 * AJAX: Schema dump — for debugging Shearwater .db structure
	 * Returns every table/view with its columns and a sample of
	 * temperature-related values so we can identify the right source.
	 * ============================================================== */
	public function ajax_schema() {
		check_ajax_referer( 'sd_dive_import_nonce', 'nonce' );
		if ( ! current_user_can( 'sd_log_dive' ) ) {
			wp_send_json_error( array( 'message' => 'Non autorizzato' ) );
		}
		if ( empty( $_FILES['import_file'] ) || UPLOAD_ERR_OK !== $_FILES['import_file']['error'] ) {
			wp_send_json_error( array( 'message' => 'Nessun file.' ) );
		}

		$use_sqlite3 = class_exists( 'SQLite3' );
		$use_pdo     = ! $use_sqlite3 && class_exists( 'PDO' ) && in_array( 'sqlite', PDO::getAvailableDrivers(), true ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO

		if ( ! $use_sqlite3 && ! $use_pdo ) {
			wp_send_json_error( array( 'message' => 'SQLite non disponibile.' ) );
		}

		$tmp = sys_get_temp_dir() . '/sd_schema_' . uniqid() . '.db';
		if ( ! copy( $_FILES['import_file']['tmp_name'], $tmp ) ) {
			wp_send_json_error( array( 'message' => 'Impossibile copiare il file.' ) );
		}

		$q = function ( $sql ) use ( $tmp, $use_sqlite3 ) {
			try {
				return $use_sqlite3
					? $this->query_sqlite3( $tmp, $sql )
					: $this->query_pdo_sqlite( $tmp, $sql );
			} catch ( Exception $e ) {
				return array( '_error' => $e->getMessage() );
			}
		};

		// List all tables and views.
		$tables_rows = $q( "SELECT type, name FROM sqlite_master WHERE type IN ('table','view') ORDER BY type, name" );
		$schema      = array();

		foreach ( $tables_rows as $t ) {
			if ( isset( $t['_error'] ) ) {
				continue;
			}
			$tname = $t['name'];
			$ttype = $t['type'];

			// Row count.
			$cnt_r    = $q( "SELECT COUNT(*) AS n FROM \"{$tname}\"" );
			$row_count = isset( $cnt_r[0]['n'] ) ? (int) $cnt_r[0]['n'] : '?';

			// Get columns.
			$cols_raw = $q( "PRAGMA table_info(\"{$tname}\")" );
			$cols     = array();
			foreach ( $cols_raw as $c ) {
				if ( isset( $c['name'] ) ) {
					$cols[] = $c['name'] . ' (' . ( $c['type'] ?? '?' ) . ')';
				}
			}

			// For log_data: show calculated_values_from_samples explicitly.
			$calc_sample = null;
			if ( 'log_data' === strtolower( $tname ) ) {
				$ld_rows = $q( "SELECT log_id, calculated_values_from_samples FROM \"{$tname}\" LIMIT 2" );
				if ( ! empty( $ld_rows ) && ! isset( $ld_rows[0]['_error'] ) ) {
					$calc_sample = $ld_rows;
				}
			}

			// Sample first 3 rows, highlight temp-related columns.
			$temp_sample = array();
			$sample_rows = $q( "SELECT * FROM \"{$tname}\" LIMIT 3" );
			foreach ( $sample_rows as $sr ) {
				if ( isset( $sr['_error'] ) ) {
					break;
				}
				// Collect columns with "temp", "temperature", "waterTemp", or "calculated" in name.
				$temp_row = array();
				foreach ( $sr as $k => $v ) {
					if ( false !== stripos( $k, 'temp' )
						|| false !== stripos( $k, 'temperature' )
						|| false !== stripos( $k, 'calculated' )
					) {
						// Truncate long values to avoid huge output.
						$temp_row[ $k ] = is_string( $v ) && strlen( $v ) > 200 ? substr( $v, 0, 200 ) . '…' : $v;
					}
				}
				if ( ! empty( $temp_row ) ) {
					$temp_sample[] = $temp_row;
				}
			}

			$schema[ $tname ] = array(
				'type'        => $ttype,
				'row_count'   => $row_count,
				'columns'     => $cols,
				'temp_sample' => $temp_sample,
				'calc_sample' => $calc_sample,
			);
		}

		@unlink( $tmp );
		wp_send_json_success( array( 'schema' => $schema ) );
	}

	/* ================================================================
	 * AJAX: Preview (parse file, return dive list JSON)
	 * ============================================================== */
	public function ajax_preview() {
		check_ajax_referer( 'sd_dive_import_nonce', 'nonce' );
		if ( ! current_user_can( 'sd_log_dive' ) ) {
			wp_send_json_error( array( 'message' => 'Non autorizzato' ) );
		}

		if ( empty( $_FILES['import_file'] ) || UPLOAD_ERR_OK !== $_FILES['import_file']['error'] ) {
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
			if ( 'ssrf' === $ext ) {
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

		wp_send_json_success(
			array(
				'dives'     => $dives,
				'total'     => count( $dives ),
				'new'       => $new_count,
				'duplicate' => $dup_count,
				'source'    => strtoupper( $ext ),
			)
		);
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

		wp_send_json_success(
			array(
				'imported' => $imported,
				'skipped'  => $skipped,
				'errors'   => $errors,
			)
		);
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
				$gps       = trim( $sites[ $site_id ]['gps'] );
				if ( $gps ) {
					// Handle both "lat lng" (space) and "lat,lng" (comma) formats.
					$sep   = ( false !== strpos( $gps, ',' ) ) ? ',' : ' ';
					$parts = explode( $sep, $gps );
					if ( count( $parts ) >= 2 ) {
						$lat_v = (float) trim( $parts[0] );
						$lng_v = (float) trim( $parts[1] );
						// Sanity-check: valid lat/lng ranges.
						if ( $lat_v >= -90 && $lat_v <= 90 && $lng_v >= -180 && $lng_v <= 180 ) {
							$lat = $lat_v;
							$lng = $lng_v;
						}
					}
				}
			}

			// Depth / temp / computer info from divecomputer element
			$max_depth         = null;
			$avg_depth         = null;
			$temp_water        = null;
			$computer_brand    = null;
			$computer_model    = null;
			$computer_serial   = null;
			$computer_firmware = null;
			if ( isset( $d->divecomputer ) ) {
				$dc = $d->divecomputer;

				// Computer brand + model from model attribute, e.g. "Ratio iX3M 2 GPS Deep"
				$dc_model_raw = (string) $dc['model'];
				if ( $dc_model_raw ) {
					$model_parts    = explode( ' ', $dc_model_raw, 2 );
					$computer_brand = $model_parts[0];
					$computer_model = isset( $model_parts[1] ) ? $model_parts[1] : $model_parts[0];
				}

				// Serial from <fingerprint serial="..."> (inside <settings> or direct child)
				$fp = $dc->settings->fingerprint ?? ( $dc->fingerprint ?? null );
				if ( $fp ) {
					$computer_serial = (string) $fp['serial'] ?: null;
				}

				// Firmware version
				$fw = $dc->settings->firmware ?? ( $dc->firmware ?? null );
				if ( $fw ) {
					$computer_firmware = (string) $fw['version'] ?: ( (string) $fw ?: null );
				}

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

			// Buddy / notes
			// In Subsurface both <divemaster> and <buddy> represent dive companions.
			$dm_str     = (string) ( $d->divemaster ?? '' );
			$buddy_str  = (string) ( $d->buddy ?? '' );
			$name_parts = array_filter( array( $dm_str, $buddy_str ) );
			$buddy_name = ! empty( $name_parts ) ? implode( ', ', $name_parts ) : null;
			$notes      = (string) ( $d->notes ?? '' );

			// Visibility rating → string
			$visibility = null;
			$vis_val    = (int) $d['visibility'];
			if ( $vis_val >= 4 ) {
				$visibility = 'buona';
			} elseif ( $vis_val >= 2 ) {
				$visibility = 'media';
			} elseif ( $vis_val > 0 ) {
				$visibility = 'scarsa';
			}

			$dives[] = array(
				'source'             => 'subsurface',
				'dive_number'        => $dive_number,
				'dive_date'          => $date_str,
				'time_in'            => substr( $time_str, 0, 5 ),
				'dive_time'          => $dive_time,
				'site_name'          => $site_name ?: 'Sito sconosciuto',
				'site_latitude'      => $lat,
				'site_longitude'     => $lng,
				'max_depth'          => $max_depth,
				'avg_depth'          => $avg_depth,
				'temp_water'         => $temp_water,
				'pressure_start'     => $pressure_start,
				'pressure_end'       => $pressure_end,
				'tank_capacity'      => $tank_capacity,
				'gas_mix'            => $gas_mix,
				'nitrox_percentage'  => $nitrox_pct,
				'ballast_kg'         => $ballast_kg,
				'visibility'         => $visibility,
				'buddy_name'         => $buddy_name,
				'guide_name'         => null,
				'notes'              => $notes,
				'suit_type'          => null,
				'dive_type'          => null,
				'weather'            => null,
				'temp_air'           => null,
				'sea_condition'      => null,
				'current_strength'   => null,
				'computer_brand'     => $computer_brand,
				'computer_model'     => $computer_model,
				'computer_serial'    => $computer_serial,
				'computer_firmware'  => $computer_firmware,
				'imported_at'        => current_time( 'mysql' ),
				'shared_for_research' => 1,
			);
		}

		return $dives;
	}

	/* ================================================================
	 * Parser: Shearwater Cloud .db (SQLite3)
	 * ============================================================== */
	private function parse_shearwater_db( $path ) {
		$use_pdo = ! class_exists( 'SQLite3' ) && class_exists( 'PDO' ) && in_array( 'sqlite', PDO::getAvailableDrivers(), true ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- reading a local SQLite file, not the WP database
		$use_sqlite3 = class_exists( 'SQLite3' );

		if ( ! $use_sqlite3 && ! $use_pdo ) {
			throw new Exception( 'SQLite3 non disponibile sul server. Contatta il supporto hosting per abilitare l\'estensione php_sqlite3 o pdo_sqlite.' );
		}

		// Copy to writable temp location
		$tmp = sys_get_temp_dir() . '/sd_sw_' . uniqid() . '.db';
		if ( ! copy( $path, $tmp ) ) {
			throw new Exception( 'Impossibile copiare il file temporaneo.' );
		}

		// Main query. Includes direct Tank1 pressure fields (PSI) and GPS entry
		// point — both are reliably populated in sargof v12 Cloud Backup format.
		$sql = 'SELECT
				DiveId, DiveDate, DiveLengthTime,
				Depth, AverageDepth, AverageTemp, MinTemp, MaxTemp,
				Site, Location,
				Buddy,
				DiveNumber,
				Environment,
				Visibility, Weather, Conditions, Platform,
				AirTemperature,
				TankProfileData,
				Tank1PressureStart, Tank1PressureEnd,
				TankSize, Weight,
				GearNotes, Notes,
				Dress,
				GnssEntryLocation
			FROM dive_details
			ORDER BY DiveDate ASC';

		if ( $use_sqlite3 ) {
			$rows = $this->query_sqlite3( $tmp, $sql );
		} else {
			$rows = $this->query_pdo_sqlite( $tmp, $sql );
		}

		// Load water temperatures separately via schema discovery.
		// Returns [ DiveId => float °C ] or empty array if not available.
		$temps = $this->load_shearwater_temperatures( $tmp, $use_sqlite3 );

		// Load computer model / serial / firmware (best-effort, may return empty).
		// Map: [ dive_id => ['model'=>?, 'serial'=>?, 'firmware'=>?] ]
		// '_global' key = applies to all dives when not linked per-dive.
		$computer_info = $this->load_shearwater_computer_info( $tmp, $use_sqlite3 );
		$global_info   = $computer_info['_global'] ?? null;

		@unlink( $tmp );

		// Lookup maps — include both English and Italian labels
		// (Shearwater stores values in the app's language).
		$env_map = array(
			// English
			'Ocean/Sea'    => 'mare',
			'Lake/Quarry'  => 'lago',
			'River'        => 'fiume',
			'Pool'         => 'piscina',
			'Ice'          => 'ghiaccio',
			'Cave'         => 'grotta',
			// Italian
			'Oceano/Mare'  => 'mare',
			'Lago/Cava'    => 'lago',
			'Fiume'        => 'fiume',
			'Piscina'      => 'piscina',
			'Ghiaccio'     => 'ghiaccio',
			'Grotta'       => 'grotta',
		);
		$weather_map = array(
			// English
			'Sunny'        => 'sereno',
			'Cloudy'       => 'nuvoloso',
			'Overcast'     => 'nuvoloso',
			'Rain'         => 'pioggia',
			'Rainy'        => 'pioggia',
			// Italian
			'Soleggiato'   => 'sereno',
			'Nuvoloso'     => 'nuvoloso',
			'Coperto'      => 'nuvoloso',
			'Pioggia'      => 'pioggia',
		);
		$cond_map = array(
			// English
			'Calm'           => 'calmo',
			'Surge'          => 'mosso',
			'Waves'          => 'mosso',
			'Current'        => 'agitato',
			'Strong current' => 'agitato',
			// Italian
			'Calmo'          => 'calmo',
			'Corrente'       => 'agitato',
			'Corrente forte' => 'agitato',
			'Onde'           => 'mosso',
		);
		$dress_map = array(
			// English
			'Wet Suit'       => 'umida',
			'Semi Dry Suit'  => 'semistagna',
			'Dry Suit'       => 'stagna',
			'Drysuit'        => 'stagna',
			// Italian
			'Muta umida'     => 'umida',
			'Muta semi-stagna' => 'semistagna',
			'Muta semistagna'  => 'semistagna',
			'Muta stagna'    => 'stagna',
		);

		$dives = array();
		foreach ( $rows as $row ) {
			// Date/time
			$dt_parts = explode( ' ', $row['DiveDate'] );
			$date_str = $dt_parts[0] ?? '';
			$time_str = isset( $dt_parts[1] ) ? substr( $dt_parts[1], 0, 5 ) : null;

			// Duration: seconds → minutes
			$dive_time = $row['DiveLengthTime'] ? (int) round( (int) $row['DiveLengthTime'] / 60 ) : null;

			// Depth
			$max_depth = $row['Depth'] ? (float) $row['Depth'] : null;
			$avg_depth = $row['AverageDepth'] ? (float) $row['AverageDepth'] : null;

			// Water temperature — priority chain:
			// 1. Per-sample average from log_records/log_cache (most accurate).
			// 2. AverageTemp from dive_details view.
			// 3. Average of MinTemp + MaxTemp from dive_details (common in sargof v12).
			// 4. MinTemp alone (last resort).
			// Auto-detect Celsius × 10: realistic water temps are 0–45 °C;
			// values > 50 must be in the × 10 format used by Shearwater firmware.
			$dive_id    = $row['DiveId'];
			$temp_water = isset( $temps[ $dive_id ] ) ? $temps[ $dive_id ] : null;

			if ( null === $temp_water ) {
				$raw_candidates = array(
					$row['AverageTemp'] ?? null,
				);
				// Derive synthetic average from Min+Max if both present.
				$min_raw = $row['MinTemp'] ?? null;
				$max_raw = $row['MaxTemp'] ?? null;
				if ( null !== $min_raw && '' !== $min_raw && null !== $max_raw && '' !== $max_raw ) {
					$raw_candidates[] = ( (float) $min_raw + (float) $max_raw ) / 2.0;
				} elseif ( null !== $min_raw && '' !== $min_raw ) {
					$raw_candidates[] = (float) $min_raw;
				}

				foreach ( $raw_candidates as $raw ) {
					if ( null === $raw || '' === $raw ) {
						continue;
					}
					$v = (float) $raw;
					if ( $v > 0 ) {
						$temp_water = $v > 50 ? round( $v / 10.0, 1 ) : round( $v, 1 );
						break;
					}
				}
			}

			// Air temperature: same heuristic (AirTemperature in dive_details).
			$air_raw  = $row['AirTemperature'] ?? null;
			$temp_air = null;
			if ( null !== $air_raw && '' !== $air_raw ) {
				$air_f = (float) $air_raw;
				if ( $air_f > 0 ) {
					$temp_air = $air_f > 50 ? round( $air_f / 10.0, 1 ) : round( $air_f, 1 );
				}
			}

			// Site name
			$site_name = trim( $row['Site'] ?: $row['Location'] ?: 'Sito sconosciuto' );

			// Dive number
			$dive_number = $row['DiveNumber'] ? (int) $row['DiveNumber'] : null;

			// GPS from GnssEntryLocation (JSON: {"Latitude":45.123,"Longitude":8.456})
			$lat = null;
			$lng = null;
			if ( ! empty( $row['GnssEntryLocation'] ) ) {
				$gps = json_decode( $row['GnssEntryLocation'], true );
				if ( $gps && isset( $gps['Latitude'], $gps['Longitude'] ) ) {
					$lat = (float) $gps['Latitude'];
					$lng = (float) $gps['Longitude'];
				}
			}

			// Pressures: prefer direct Tank1PressureStart/End fields (PSI → bar).
			// These are plain numeric strings in dive_details, always present when
			// the computer had AI sensors. Fall back to TankProfileData JSON.
			$pressure_start = null;
			$pressure_end   = null;
			$gas_mix        = 'aria';
			$nitrox_pct     = null;
			$tank_capacity  = $row['TankSize'] ? (float) $row['TankSize'] : null;

			$t1s = $row['Tank1PressureStart'] ?? null;
			$t1e = $row['Tank1PressureEnd'] ?? null;
			if ( null !== $t1s && '' !== $t1s && is_numeric( $t1s ) && (float) $t1s > 0 ) {
				$pressure_start = (int) round( (float) $t1s * 0.0689476 );
			}
			if ( null !== $t1e && '' !== $t1e && is_numeric( $t1e ) && (float) $t1e > 0 ) {
				$pressure_end = (int) round( (float) $t1e * 0.0689476 );
			}

			// Fallback + gas mix from TankProfileData JSON.
			if ( ! empty( $row['TankProfileData'] ) ) {
				$tank_json = json_decode( $row['TankProfileData'], true );
				if ( $tank_json && isset( $tank_json['TankData'][0] ) ) {
					$t0 = $tank_json['TankData'][0];
					if ( null === $pressure_start && ! empty( $t0['StartPressurePSI'] ) && is_numeric( $t0['StartPressurePSI'] ) ) {
						$pressure_start = (int) round( (float) $t0['StartPressurePSI'] * 0.0689476 );
					}
					if ( null === $pressure_end && ! empty( $t0['EndPressurePSI'] ) && is_numeric( $t0['EndPressurePSI'] ) ) {
						$pressure_end = (int) round( (float) $t0['EndPressurePSI'] * 0.0689476 );
					}
				}
				if ( $tank_json && isset( $tank_json['GasProfiles'][0] ) ) {
					$o2 = (int) $tank_json['GasProfiles'][0]['O2Percent'];
					if ( $o2 > 0 && abs( $o2 - 21 ) > 1 ) {
						$gas_mix    = 'nitrox';
						$nitrox_pct = $o2;
					}
				}
			}

			// Ballast
			$ballast_kg = $row['Weight'] ? (float) $row['Weight'] : null;

			// Mapped fields
			$dive_type     = $env_map[ $row['Environment'] ] ?? null;
			$weather       = $weather_map[ $row['Weather'] ] ?? null;
			$sea_condition = $cond_map[ $row['Conditions'] ] ?? null;
			$suit_type     = $dress_map[ $row['Dress'] ] ?? null;

			// Visibility: parse numeric metres from string
			$vis_raw = $row['Visibility'] ?: '';
			preg_match( '/(\d+)/', $vis_raw, $vm );
			$vis_m      = isset( $vm[1] ) ? (int) $vm[1] : 0;
			$visibility = null;
			if ( $vis_m >= 15 ) {
				$visibility = 'buona';
			} elseif ( $vis_m >= 5 ) {
				$visibility = 'media';
			} elseif ( $vis_m > 0 ) {
				$visibility = 'scarsa';
			}

			// Notes
			$notes_parts = array_filter( array( $row['GearNotes'], $row['Notes'] ) );
			$notes       = implode( "\n", $notes_parts );

			// Computer info: brand is always Shearwater.
			// Prefer per-dive entry, fall back to global (one-row-per-computer tables).
			$per_dive_info = $computer_info[ $dive_id ] ?? null;
			$info_src      = $per_dive_info ?? $global_info;
			$sw_model    = $info_src['model'] ?? null;
			$sw_serial   = $info_src['serial'] ?? null;
			$sw_firmware = $info_src['firmware'] ?? null;

			$dives[] = array(
				'source'              => 'shearwater',
				'dive_number'         => $dive_number,
				'dive_date'           => $date_str,
				'time_in'             => $time_str,
				'dive_time'           => $dive_time,
				'site_name'           => $site_name,
				'site_latitude'       => $lat,
				'site_longitude'      => $lng,
				'max_depth'           => $max_depth,
				'avg_depth'           => $avg_depth,
				'temp_water'          => $temp_water,
				'temp_air'            => $temp_air,
				'pressure_start'      => $pressure_start,
				'pressure_end'        => $pressure_end,
				'tank_capacity'       => $tank_capacity,
				'gas_mix'             => $gas_mix,
				'nitrox_percentage'   => $nitrox_pct,
				'ballast_kg'          => $ballast_kg,
				'buddy_name'          => $row['Buddy'] ?: null,
				'guide_name'          => null,
				'notes'               => $notes ?: null,
				'dive_type'           => $dive_type,
				'visibility'          => $visibility,
				'weather'             => $weather,
				'sea_condition'       => $sea_condition,
				'suit_type'           => $suit_type,
				'current_strength'    => null,
				'computer_brand'      => 'Shearwater',
				'computer_model'      => $sw_model,
				'computer_serial'     => $sw_serial,
				'computer_firmware'   => $sw_firmware,
				'imported_at'         => current_time( 'mysql' ),
				'shared_for_research' => 1,
				'_shearwater_id'      => $dive_id,
			);
		}

		return $dives;
	}

	/* ================================================================
	 * Load water temperature per dive from a Shearwater Cloud .db file.
	 * Returns [ DiveId (string) => float °C ] or [] if unavailable.
	 *
	 * Schema discovered via the diagnostics tool (sargof v12):
	 *   dive_details.DiveId  →  dive_logs.diveId  →  dive_logs.id
	 *   dive_logs.id  →  dive_log_records.diveLogId
	 *   dive_log_records.waterTemp  (INTEGER, Celsius × 10)
	 *
	 * Generic fallback tries other known table/column variants so the
	 * code keeps working if Shearwater changes the schema in future.
	 * ============================================================== */
	private function load_shearwater_temperatures( $tmp, $use_sqlite3 ) {
		$q = function ( $sql ) use ( $tmp, $use_sqlite3 ) {
			try {
				return $use_sqlite3
					? $this->query_sqlite3( $tmp, $sql )
					: $this->query_pdo_sqlite( $tmp, $sql );
			} catch ( Exception $e ) {
				return array();
			}
		};

		// Case-insensitive name finder.
		$find_ci = function ( $needle, array $haystack ) {
			$lower = strtolower( $needle );
			foreach ( $haystack as $h ) {
				if ( strtolower( $h ) === $lower ) {
					return $h;
				}
			}
			return null;
		};

		$find_any = function ( array $candidates, array $haystack ) use ( $find_ci ) {
			foreach ( $candidates as $c ) {
				$found = $find_ci( $c, $haystack );
				if ( null !== $found ) {
					return $found;
				}
			}
			return null;
		};

		$get_cols = function ( $table ) use ( $q ) {
			$rows = $q( "PRAGMA table_info(\"{$table}\")" );
			return array_column( $rows, 'name' );
		};

		// All table names in the DB.
		$schema_rows = $q( "SELECT name FROM sqlite_master WHERE type IN ('table','view') ORDER BY name" );
		$all_tables  = array_column( $schema_rows, 'name' );

		// ── STRATEGY 1 (sargof v12): log_data.calculated_values_from_samples ──
		// log_data.log_id matches dive_details.DiveId exactly.
		// calculated_values_from_samples is a JSON string with AverageTemp in
		// real Celsius (not ×10). MinTemp = -273.0 means sensor not read → skip.
		$ld_table = $find_ci( 'log_data', $all_tables );
		if ( $ld_table ) {
			$ld_cols    = $get_cols( $ld_table );
			$ld_id_col  = $find_any( array( 'log_id', 'LogId', 'log_id', 'id' ), $ld_cols );
			$ld_json_col = $find_any( array( 'calculated_values_from_samples', 'CalculatedValuesFromSamples' ), $ld_cols );

			if ( $ld_id_col && $ld_json_col ) {
				$rows = $q( "SELECT \"{$ld_id_col}\" AS lid, \"{$ld_json_col}\" AS jdata FROM \"{$ld_table}\"" );
				$map  = array();
				foreach ( $rows as $r ) {
					if ( empty( $r['jdata'] ) ) {
						continue;
					}
					$json = json_decode( $r['jdata'], true );
					if ( ! $json ) {
						continue;
					}
					// AverageTemp is in real °C. Values ≤ -200 are absolute-zero
					// placeholders meaning the sensor had no valid reading.
					$avg_t = $json['AverageTemp'] ?? null;
					if ( null !== $avg_t && (float) $avg_t > -200 ) {
						$map[ $r['lid'] ] = round( (float) $avg_t, 1 );
					}
				}
				if ( ! empty( $map ) ) {
					return $map;
				}
			}
		}

		// ── STRATEGY 2: three-table join (older sargof schema) ───────
		// dive_details.DiveId → dive_logs.diveId → dive_log_records.waterTemp (×10)
		$dl_table  = $find_ci( 'dive_logs', $all_tables );
		$dlr_table = $find_ci( 'dive_log_records', $all_tables );

		if ( $dl_table && $dlr_table ) {
			$dl_cols  = $get_cols( $dl_table );
			$dlr_cols = $get_cols( $dlr_table );

			$dl_diveid = $find_any( array( 'diveId', 'DiveId', 'DiveID', 'dive_id' ), $dl_cols );
			$dl_id     = $find_any( array( 'id', 'Id', 'ID' ), $dl_cols );
			$dlr_logid = $find_any( array( 'diveLogId', 'DiveLogId', 'divelogid' ), $dlr_cols );
			$dlr_temp  = $find_any( array( 'waterTemp', 'WaterTemp', 'Temperature', 'Temp' ), $dlr_cols );

			if ( $dl_diveid && $dl_id && $dlr_logid && $dlr_temp ) {
				$sql  = "SELECT dl.\"{$dl_diveid}\" AS did,
					ROUND(
						AVG( CASE WHEN CAST(dlr.\"{$dlr_temp}\" AS REAL) > 0
							 THEN CAST(dlr.\"{$dlr_temp}\" AS REAL) ELSE NULL END
						) / 10.0, 1
					) AS avg_t
					FROM \"{$dl_table}\" dl
					JOIN \"{$dlr_table}\" dlr
					  ON CAST(dlr.\"{$dlr_logid}\" AS INTEGER) = dl.\"{$dl_id}\"
					GROUP BY dl.\"{$dl_diveid}\"";
				$rows = $q( $sql );
				$map  = array();
				foreach ( $rows as $r ) {
					if ( null !== $r['avg_t'] && '' !== $r['avg_t'] ) {
						$map[ $r['did'] ] = (float) $r['avg_t'];
					}
				}
				if ( ! empty( $map ) ) {
					return $map;
				}
			}
		}

		// ── STRATEGY 3: direct per-sample table with DiveId column ───
		$sample_tables   = array( 'log_records', 'dive_samples', 'samples', 'dive_data' );
		$id_candidates   = array( 'DiveId', 'DiveLogId', 'DiveID', 'dive_id' );
		$temp_candidates = array( 'waterTemp', 'Temperature', 'Temp', 'WaterTemp' );

		foreach ( $sample_tables as $tname ) {
			$actual = $find_ci( $tname, $all_tables );
			if ( ! $actual ) {
				continue;
			}
			$cols   = $get_cols( $actual );
			$id_col = $find_any( $id_candidates, $cols );
			$t_col  = $find_any( $temp_candidates, $cols );
			if ( ! $id_col || ! $t_col ) {
				continue;
			}
			$sql  = "SELECT \"{$id_col}\" AS sid,
				ROUND( AVG( CASE WHEN CAST(\"{$t_col}\" AS REAL) > 0 THEN CAST(\"{$t_col}\" AS REAL) ELSE NULL END ) / 10.0, 1 ) AS avg_t
				FROM \"{$actual}\"
				GROUP BY \"{$id_col}\"";
			$rows = $q( $sql );
			$map  = array();
			foreach ( $rows as $r ) {
				if ( null !== $r['avg_t'] && '' !== $r['avg_t'] ) {
					$map[ $r['sid'] ] = (float) $r['avg_t'];
				}
			}
			if ( ! empty( $map ) ) {
				return $map;
			}
		}

		return array();
	}

	/* ================================================================
	 * Load computer model / serial / firmware from a Shearwater .db.
	 *
	 * Returns a map keyed by DiveId (string) or '_global' (applied to
	 * all dives when the computer table has no DiveId column, which is
	 * the common sargof v12 layout).
	 * Each value: ['model'=>string|null, 'serial'=>string|null, 'firmware'=>string|null]
	 *
	 * Best-effort — returns [] if nothing is found.
	 * ============================================================== */
	private function load_shearwater_computer_info( $tmp, $use_sqlite3 ) {
		$q = function ( $sql ) use ( $tmp, $use_sqlite3 ) {
			try {
				return $use_sqlite3
					? $this->query_sqlite3( $tmp, $sql )
					: $this->query_pdo_sqlite( $tmp, $sql );
			} catch ( Exception $_e ) {
				return array();
			}
		};

		// JSON key candidates for each field (in priority order).
		$model_keys    = array( 'CustomerComputerName', 'customerComputerName', 'ProductName', 'productName', 'ComputerName', 'computerName', 'Name', 'name' );
		$serial_keys   = array( 'SerialNumber', 'serialNumber' );
		$firmware_keys = array( 'FirmwareVersion', 'firmwareVersion', 'Firmware', 'firmware' );

		$pick = function ( $json, $keys ) {
			foreach ( $keys as $k ) {
				if ( isset( $json[ $k ] ) && '' !== (string) $json[ $k ] ) {
					return trim( (string) $json[ $k ] );
				}
			}
			return null;
		};

		// Directly try the two known Shearwater computer tables, most useful first.
		$known_tables = array( 'CustomDiveComputer', 'StoredDiveComputer' );
		foreach ( $known_tables as $tname ) {
			$rows = $q( 'SELECT * FROM "' . $tname . '" LIMIT 10' );
			if ( empty( $rows ) ) {
				continue;
			}
			$out = array();
			foreach ( $rows as $r ) {
				$model    = null;
				$serial   = null;
				$firmware = null;

				// Try JsonData column first.
				$json_raw = $r['JsonData'] ?? ( $r['jsondata'] ?? null );
				if ( ! empty( $json_raw ) ) {
					$json = json_decode( $json_raw, true );
					if ( is_array( $json ) ) {
						$model    = $pick( $json, $model_keys );
						$serial   = $pick( $json, $serial_keys );
						$firmware = $pick( $json, $firmware_keys );
					}
				}
				// Serial may be a direct column (bigint PK in CustomDiveComputer).
				if ( null === $serial ) {
					$sn = $r['SerialNumber'] ?? ( $r['serialnumber'] ?? null );
					if ( null !== $sn && '' !== (string) $sn ) {
						$serial = (string) $sn;
					}
				}

				if ( ! $model && ! $serial && ! $firmware ) {
					continue;
				}

				// Use DiveId for per-dive mapping when present; otherwise global.
				$dive_id = $r['DiveId'] ?? ( $r['DiveID'] ?? ( $r['diveid'] ?? null ) );
				$key     = null !== $dive_id ? (string) $dive_id : '_global';

				// First entry per key wins (table is usually one-row-per-computer).
				if ( ! isset( $out[ $key ] ) ) {
					$out[ $key ] = array(
						'model'    => $model,
						'serial'   => $serial,
						'firmware' => $firmware,
					);
				}
			}
			if ( ! empty( $out ) ) {
				return $out;
			}
		}

		return array();
	}

	/* ================================================================
	 * SQLite helpers: abstraction over SQLite3 and PDO
	 * ============================================================== */
	private function query_sqlite3( $path, $sql ) {
		$db = new SQLite3( $path, SQLITE3_OPEN_READONLY );
		if ( ! $db ) {
			throw new Exception( 'Impossibile aprire il database Shearwater.' );
		}
		$result = $db->query( $sql );
		if ( ! $result ) {
			$msg = $db->lastErrorMsg() ?: ( 'code ' . $db->lastErrorCode() );
			$db->close();
			throw new Exception( 'Errore query SQLite3: ' . $msg );
		}
		$rows = array();
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$rows[] = $row;
		}
		$db->close();
		return $rows;
	}

	private function query_pdo_sqlite( $path, $sql ) {
		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- reading a local SQLite file, not the WP database
		try {
			$pdo  = new PDO( 'sqlite:' . $path );
			$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$stmt = $pdo->query( $sql );
			return $stmt->fetchAll( PDO::FETCH_ASSOC );
		} catch ( PDOException $e ) {
			throw new Exception( 'Errore PDO SQLite: ' . $e->getMessage() );
		}
		// phpcs:enable WordPress.DB.RestrictedClasses.mysql__PDO
	}

	/* ================================================================
	 * Duplicate check: same user + same date + time_in within 5 min + depth within 1m
	 * ============================================================== */
	private function is_duplicate( $user_id, $dive ) {
		global $wpdb;
		$db    = new SD_Database();
		$table = $db->table( 'dives' );

		$date = sanitize_text_field( $dive['dive_date'] ?? '' );
		if ( ! $date ) {
			return false;
		}

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
			if ( $count > 0 ) {
				return true;
			}
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
				if ( $count > 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	/* ================================================================
	 * Sanitize + type-cast raw dive array for DB insert
	 * ============================================================== */
	private function sanitize_dive_row( $raw, $user_id, $dive_number ) {
		$nullable_str = function ( $v ) {
			$s = sanitize_text_field( (string) $v );
			return '' === $s ? null : $s;
		};
		$nullable_float = function ( $v ) {
			if ( null === $v || '' === $v ) {
				return null;
			}
			return (float) $v;
		};
		$nullable_int = function ( $v ) {
			if ( null === $v || '' === $v ) {
				return null;
			}
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
			'time_out'            => ( ! empty( $raw['time_in'] ) && ! empty( $raw['dive_time'] ) )
				? gmdate( 'H:i', strtotime( $raw['time_in'] ) + (int) $raw['dive_time'] * 60 )
				: null,
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
			'computer_brand'      => $nullable_str( $raw['computer_brand'] ),
			'computer_model'      => $nullable_str( $raw['computer_model'] ),
			'computer_serial'     => $nullable_str( $raw['computer_serial'] ),
			'computer_firmware'   => $nullable_str( $raw['computer_firmware'] ),
			'imported_at'         => $nullable_str( $raw['imported_at'] ),
			'shared_for_research' => isset( $raw['shared_for_research'] ) ? (int) $raw['shared_for_research'] : 1,
		);
	}
}
