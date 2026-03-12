<?php
/**
 * Research Dashboard - Analisi dati per medici/ricercatori
 *
 * Shortcode [sd_research_dashboard]
 * Tabella dati stile FOGLIO_X_DATI_X_PAZ + grafici Chart.js + filtri avanzati
 *
 * @package SD_Logbook
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Research_Dashboard {

	public function __construct() {
		add_shortcode( 'sd_research_dashboard', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sd_research_query', array( $this, 'query_data' ) );
		add_action( 'wp_ajax_sd_research_export', array( $this, 'export_csv' ) );
	}

	public function enqueue_assets() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'sd_research_dashboard' ) ) {
			return;
		}

		wp_enqueue_style( 'sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-research', SD_LOGBOOK_PLUGIN_URL . 'assets/css/research.css', array( 'sd-logbook-form' ), SD_LOGBOOK_VERSION );
		wp_enqueue_script( 'chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', array(), '4.4.1', true );
		wp_enqueue_script( 'sd-research', SD_LOGBOOK_PLUGIN_URL . 'assets/js/research.js', array( 'jquery', 'chartjs' ), SD_LOGBOOK_VERSION, true );

		// Diver list for filter
		$divers = get_users(
			array(
				'role__in' => array( 'sd_diver_diabetic' ),
				'orderby'  => 'display_name',
			)
		);
		$list   = array();
		foreach ( $divers as $u ) {
			$name   = trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name;
			$list[] = array(
				'id'   => $u->ID,
				'name' => $name,
			);
		}

		wp_localize_script(
			'sd-research',
			'sdResearch',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sd_research_nonce' ),
				'divers'  => $list,
			)
		);
	}

	public function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="sd-notice sd-notice-warning">'
				. __( 'Devi effettuare il login per vedere la research dashboard.', 'sd-logbook' )
				. ' <a href="' . SD_Logbook::get_login_url() . '">' . __( 'Accedi', 'sd-logbook' ) . '</a>'
				. '</div>';
		}
		$uid = get_current_user_id();
		if ( ! SD_Roles::is_medical( $uid ) && ! current_user_can( 'administrator' ) ) {
			return '<div class="sd-notice sd-notice-error">Accesso riservato a medici e ricercatori.</div>';
		}
		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/research-dashboard.php';
		return ob_get_clean();
	}

	/*
	================================================================
		AJAX: Query dati con filtri
		================================================================ */
	public function query_data() {
		check_ajax_referer( 'sd_research_nonce', 'nonce' );
		$uid = get_current_user_id();
		if ( ! SD_Roles::is_medical( $uid ) && ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( array( 'message' => 'Non autorizzato' ) );
		}

		$rows = $this->run_query();
		$agg  = $this->aggregate( $rows );

		wp_send_json_success(
			array(
				'rows'  => $rows,
				'count' => count( $rows ),
				'agg'   => $agg,
			)
		);
	}

	/*
	================================================================
		AJAX: Export CSV filtrato
		================================================================ */
	public function export_csv() {
		check_ajax_referer( 'sd_research_nonce', 'nonce' );
		$uid = get_current_user_id();
		if ( ! SD_Roles::is_medical( $uid ) && ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( array( 'message' => 'Non autorizzato' ) );
		}

		$rows = $this->run_query();
		if ( empty( $rows ) ) {
			wp_send_json_error( array( 'message' => 'Nessun dato' ) );
		}

		$filename   = 'sd-research-' . gmdate( 'Y-m-d-His' ) . '.csv';
		$upload_dir = wp_upload_dir();
		$filepath   = $upload_dir['basedir'] . '/' . $filename;
		$fp         = fopen( $filepath, 'w' );
		fwrite( $fp, "\xEF\xBB\xBF" );

		$h = array(
			'Subacqueo',
			'Data',
			'Sito',
			'Ora_in',
			'Ora_out',
			'Prof_max',
			'Prof_media',
			'Tempo_min',
			'Gas',
			'Nitrox%',
			'T_acqua',
			'Glic-60',
			'Met-60',
			'Trend-60',
			'CHOr-60',
			'CHOl-60',
			'INS-60',
			'Note-60',
			'Glic-30',
			'Met-30',
			'Trend-30',
			'CHOr-30',
			'CHOl-30',
			'INS-30',
			'Note-30',
			'Glic-10',
			'Met-10',
			'Trend-10',
			'CHOr-10',
			'CHOl-10',
			'INS-10',
			'Note-10',
			'Glic-POST',
			'Met-POST',
			'Trend-POST',
			'CHOr-POST',
			'CHOl-POST',
			'INS-POST',
			'Note-POST',
			'Decisione',
			'Motivo',
			'Chetoni_val',
			'Ipo',
		);
		fputcsv( $fp, $h, ';' );

		foreach ( $rows as $r ) {
			fputcsv(
				$fp,
				array(
					$r->diver_name,
					$r->dive_date,
					$r->site_name,
					$r->time_in,
					$r->time_out,
					$r->max_depth,
					$r->avg_depth,
					$r->dive_time,
					$r->gas_mix,
					$r->nitrox_percentage,
					$r->temp_water,
					$r->glic_60_value,
					$r->glic_60_method,
					$r->glic_60_trend,
					$r->glic_60_cho_rapidi,
					$r->glic_60_cho_lenti,
					$r->glic_60_insulin,
					$r->glic_60_notes,
					$r->glic_30_value,
					$r->glic_30_method,
					$r->glic_30_trend,
					$r->glic_30_cho_rapidi,
					$r->glic_30_cho_lenti,
					$r->glic_30_insulin,
					$r->glic_30_notes,
					$r->glic_10_value,
					$r->glic_10_method,
					$r->glic_10_trend,
					$r->glic_10_cho_rapidi,
					$r->glic_10_cho_lenti,
					$r->glic_10_insulin,
					$r->glic_10_notes,
					$r->glic_post_value,
					$r->glic_post_method,
					$r->glic_post_trend,
					$r->glic_post_cho_rapidi,
					$r->glic_post_cho_lenti,
					$r->glic_post_insulin,
					$r->glic_post_notes,
					$r->dive_decision,
					$r->dive_decision_reason,
					$r->ketone_value,
					$r->hypo_during_dive,
				),
				';'
			);
		}
		fclose( $fp );

		wp_send_json_success(
			array(
				'url'      => $upload_dir['baseurl'] . '/' . $filename,
				'filename' => $filename,
				'count'    => count( $rows ),
			)
		);
	}

	/*
	================================================================
		Query builder condiviso
		================================================================ */
	private function run_query() {
		global $wpdb;
		$db = new SD_Database();

		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $_POST['date_from'] ) ) {
			$where[] = 'd.dive_date >= %s';
			$vals[]  = sanitize_text_field( $_POST['date_from'] ); }
		if ( ! empty( $_POST['date_to'] ) ) {
			$where[] = 'd.dive_date <= %s';
			$vals[]  = sanitize_text_field( $_POST['date_to'] ); }

		// Years filter (multiple)
		if ( ! empty( $_POST['years'] ) ) {
			$years = array_map( 'absint', (array) $_POST['years'] );
			$years = array_filter(
				$years,
				function ( $y ) {
					return $y >= 2000 && $y <= 2100;
				}
			);
			if ( ! empty( $years ) ) {
				$year_clauses = array();
				foreach ( $years as $y ) {
					$year_clauses[] = $wpdb->prepare( 'YEAR(d.dive_date) = %d', $y );
				}
				$where[] = '(' . implode( ' OR ', $year_clauses ) . ')';
				// Override date_from/date_to if years are set
			}
		}

		if ( ! empty( $_POST['diver_id'] ) && 'all' !== $_POST['diver_id'] ) {
			$where[] = 'd.user_id = %d';
			$vals[]  = absint( $_POST['diver_id'] );
		}
		if ( ! empty( $_POST['decision'] ) && 'all' !== $_POST['decision'] ) {
			$where[] = 'dd.dive_decision = %s';
			$vals[]  = sanitize_text_field( $_POST['decision'] );
		}
		if ( ! empty( $_POST['glic_min'] ) ) {
			$gm      = absint( $_POST['glic_min'] );
			$where[] = '(COALESCE(dd.glic_60_value,0) >= %d OR COALESCE(dd.glic_30_value,0) >= %d OR COALESCE(dd.glic_10_value,0) >= %d OR COALESCE(dd.glic_post_value,0) >= %d)';
			$vals    = array_merge( $vals, array( $gm, $gm, $gm, $gm ) );
		}
		if ( ! empty( $_POST['glic_max'] ) ) {
			$gx      = absint( $_POST['glic_max'] );
			$where[] = '(dd.glic_10_value <= %d OR dd.glic_10_value IS NULL)';
			$vals[]  = $gx;
		}

		$w = implode( ' AND ', $where );

		$sql = "SELECT d.id, d.user_id, d.dive_number, d.dive_date, d.site_name,
					   d.time_in, d.time_out, d.max_depth, d.avg_depth, d.dive_time,
					   d.gas_mix, d.nitrox_percentage, d.temp_water,
					   dd.glic_60_value, dd.glic_60_method, dd.glic_60_trend, dd.glic_60_cho_rapidi, dd.glic_60_cho_lenti, dd.glic_60_insulin, dd.glic_60_notes,
					   dd.glic_30_value, dd.glic_30_method, dd.glic_30_trend, dd.glic_30_cho_rapidi, dd.glic_30_cho_lenti, dd.glic_30_insulin, dd.glic_30_notes,
					   dd.glic_10_value, dd.glic_10_method, dd.glic_10_trend, dd.glic_10_cho_rapidi, dd.glic_10_cho_lenti, dd.glic_10_insulin, dd.glic_10_notes,
					   dd.glic_post_value, dd.glic_post_method, dd.glic_post_trend, dd.glic_post_cho_rapidi, dd.glic_post_cho_lenti, dd.glic_post_insulin, dd.glic_post_notes,
					   dd.dive_decision, dd.dive_decision_reason, dd.ketone_value, dd.hypo_during_dive,
					   u.display_name, um_fn.meta_value as fn, um_ln.meta_value as ln
				FROM {$db->table('dives')} d
				INNER JOIN {$db->table('dive_diabetes')} dd ON d.id = dd.dive_id
				LEFT JOIN {$wpdb->users} u ON d.user_id = u.ID
				LEFT JOIN {$wpdb->usermeta} um_fn ON u.ID = um_fn.user_id AND um_fn.meta_key = 'first_name'
				LEFT JOIN {$wpdb->usermeta} um_ln ON u.ID = um_ln.user_id AND um_ln.meta_key = 'last_name'
				WHERE {$w}
				GROUP BY d.id
				ORDER BY d.dive_date DESC, d.time_in DESC
				LIMIT 500";

		$rows = ! empty( $vals ) ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql );

		foreach ( $rows as &$r ) {
			$r->diver_name = trim( $r->fn . ' ' . $r->ln ) ?: $r->display_name;
			unset( $r->fn, $r->ln, $r->display_name );
		}
		return $rows;
	}

	/*
	================================================================
		Aggregazioni per grafici
		================================================================ */
	private function aggregate( $rows ) {
		$a  = array(
			'decisions'     => array(
				'autorizzata' => 0,
				'sospesa'     => 0,
				'annullata'   => 0,
			),
			'glic_ranges'   => array(
				'<70'     => 0,
				'70-119'  => 0,
				'120-149' => 0,
				'150-250' => 0,
				'251-300' => 0,
				'>300'    => 0,
			),
			'by_checkpoint' => array(
				'-60'  => array(),
				'-30'  => array(),
				'-10'  => array(),
				'POST' => array(),
			),
			'timeline'      => array(),
			'hypo_count'    => 0,
			'by_diver'      => array(),
			'by_year'       => array(), // year => { cp => [values], decisions => {...}, count => N }
		);
		$tl = array();

		foreach ( $rows as $r ) {
			if ( $r->dive_decision && isset( $a['decisions'][ $r->dive_decision ] ) ) {
				++$a['decisions'][ $r->dive_decision ];
			}
			if ( $r->hypo_during_dive ) {
				++$a['hypo_count'];
			}

			// By year
			$year = substr( $r->dive_date, 0, 4 );
			if ( ! isset( $a['by_year'][ $year ] ) ) {
				$a['by_year'][ $year ] = array(
					'count'     => 0,
					'-60'       => array(),
					'-30'       => array(),
					'-10'       => array(),
					'POST'      => array(),
					'decisions' => array(
						'autorizzata' => 0,
						'sospesa'     => 0,
						'annullata'   => 0,
					),
				);
			}
			++$a['by_year'][ $year ]['count'];
			if ( $r->dive_decision && isset( $a['by_year'][ $year ]['decisions'][ $r->dive_decision ] ) ) {
				++$a['by_year'][ $year ]['decisions'][ $r->dive_decision ];
			}

			$dn = $r->diver_name;
			if ( ! isset( $a['by_diver'][ $dn ] ) ) {
				$a['by_diver'][ $dn ] = 0;
			}
			++$a['by_diver'][ $dn ];

			$cps = array(
				'-60'  => $r->glic_60_value,
				'-30'  => $r->glic_30_value,
				'-10'  => $r->glic_10_value,
				'POST' => $r->glic_post_value,
			);
			$all = array();
			foreach ( $cps as $cp => $v ) {
				if ( ! $v ) {
					continue;
				}
				$v                              = (int) $v;
				$a['by_checkpoint'][ $cp ][]    = $v;
				$a['by_year'][ $year ][ $cp ][] = $v;
				$all[]                          = $v;
				if ( $v < 70 ) {
					++$a['glic_ranges']['<70'];
				} elseif ( $v < 120 ) {
					++$a['glic_ranges']['70-119'];
				} elseif ( $v < 150 ) {
					++$a['glic_ranges']['120-149'];
				} elseif ( $v <= 250 ) {
					++$a['glic_ranges']['150-250'];
				} elseif ( $v <= 300 ) {
					++$a['glic_ranges']['251-300'];
				} else {
					++$a['glic_ranges']['>300'];
				}
			}
			if ( ! empty( $all ) ) {
				$d = $r->dive_date;
				if ( ! isset( $tl[ $d ] ) ) {
					$tl[ $d ] = array(
						's' => 0,
						'c' => 0,
					);
				}
				$tl[ $d ]['s'] += array_sum( $all ) / count( $all );
				++$tl[ $d ]['c'];
			}
		}

		ksort( $tl );
		foreach ( $tl as $d => $v ) {
			$a['timeline'][] = array(
				'date' => $d,
				'avg'  => round( $v['s'] / $v['c'] ),
			);
		}
		foreach ( $a['by_checkpoint'] as $cp => $vals ) {
			$a['by_checkpoint'][ $cp ] = ! empty( $vals ) ? round( array_sum( $vals ) / count( $vals ) ) : 0;
		}

		// Finalize by_year checkpoint averages
		ksort( $a['by_year'] );
		foreach ( $a['by_year'] as $year => &$yd ) {
			foreach ( array( '-60', '-30', '-10', 'POST' ) as $cp ) {
				$vals      = $yd[ $cp ];
				$yd[ $cp ] = ! empty( $vals ) ? round( array_sum( $vals ) / count( $vals ) ) : 0;
			}
		}

		return $a;
	}
}
