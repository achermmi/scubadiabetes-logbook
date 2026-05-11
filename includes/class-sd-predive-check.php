<?php
/**
 * Semaforo Pre-Immersione
 *
 * Shortcode [sd_predive_check] — mostra un semaforo di sicurezza pre-immersione
 * basato sull'ultima lettura CGM, il trend glicemico e il profilo diabete.
 *
 * Logica di valutazione (tutti i valori interni in mg/dL):
 *
 * ROSSO  — sconsigliato immergersi:
 *   - Nessuna lettura CGM nelle ultime 2 ore (stato ignoto)
 *   - Glicemia < 90 mg/dL
 *   - Glicemia > 300 mg/dL
 *   - Trend DoubleDown o TripleDown (calo rapido a qualsiasi valore)
 *   - Trend SingleDown o FortyFiveDown con glicemia < 130 mg/dL
 *
 * GIALLO — procedere con cautela / consultare il medico:
 *   - Glicemia 90–119 mg/dL (zona borderline)
 *   - Glicemia 250–300 mg/dL (iperglicemia elevata)
 *   - Glicemia 181–249 mg/dL (sopra range target)
 *   - Trend SingleDown o FortyFiveDown con glicemia 130–180 mg/dL
 *   - Ultima lettura 60–120 minuti fa (dati datati)
 *
 * VERDE  — condizioni favorevoli:
 *   - Glicemia 120–180 mg/dL con trend stabile, piatto o in salita
 *   - Ultima lettura entro 60 minuti
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Predive_Check {

	// Soglie cliniche (mg/dL) — allineate alle linee guida DAN/UHMS per T1DM
	const THRESHOLD_RED_LOW        = 90;
	const THRESHOLD_YELLOW_LOW     = 120;
	const THRESHOLD_YELLOW_HIGH    = 180;
	const THRESHOLD_ORANGE_HIGH    = 250;
	const THRESHOLD_RED_HIGH       = 300;
	const TREND_FALL_FAST          = array( 'TripleDown', 'DoubleDown' );
	const TREND_FALL_SLOW          = array( 'SingleDown', 'FortyFiveDown' );
	const STALE_YELLOW_MIN         = 60;
	const STALE_RED_MIN            = 120;

	// ================================================================
	// COSTRUTTORE
	// ================================================================

	public function __construct() {
		add_shortcode( 'sd_predive_check', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sd_predive_evaluate', array( $this, 'ajax_evaluate' ) );
	}

	// ================================================================
	// ASSETS
	// ================================================================

	public function enqueue_assets() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'sd_predive_check' ) ) {
			return;
		}

		$css_path = SD_LOGBOOK_PLUGIN_DIR . 'assets/css/predive-check.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : SD_LOGBOOK_VERSION;
		$js_path  = SD_LOGBOOK_PLUGIN_DIR . 'assets/js/predive-check.js';
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : SD_LOGBOOK_VERSION;

		wp_enqueue_style( 'sd-logbook-form', SD_LOGBOOK_PLUGIN_URL . 'assets/css/dive-form.css', array(), SD_LOGBOOK_VERSION );
		wp_enqueue_style( 'sd-predive-check', SD_LOGBOOK_PLUGIN_URL . 'assets/css/predive-check.css', array( 'sd-logbook-form' ), $css_ver );

		wp_enqueue_script( 'sd-predive-check', SD_LOGBOOK_PLUGIN_URL . 'assets/js/predive-check.js', array( 'jquery' ), $js_ver, true );
		wp_localize_script(
			'sd-predive-check',
			'sdPredive',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'sd_predive_nonce' ),
				'unit'         => $this->get_glycemia_unit( get_current_user_id() ),
				'refreshLabel' => __( 'Aggiorna', 'sd-logbook' ),
				'strings'      => array(
					'loading'    => __( 'Valutazione in corso…', 'sd-logbook' ),
					'error'      => __( 'Errore nel recupero dati. Riprova.', 'sd-logbook' ),
					'noData'     => __( 'Nessun dato CGM disponibile.', 'sd-logbook' ),
					'minAgo'     => __( 'min fa', 'sd-logbook' ),
					'hourAgo'    => __( 'ora fa', 'sd-logbook' ),
					'hoursAgo'   => __( 'ore fa', 'sd-logbook' ),
				),
			)
		);
	}

	// ================================================================
	// SHORTCODE
	// ================================================================

	public function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="sd-notice sd-notice-warning">'
				. __( 'Devi effettuare il login per usare il controllo pre-immersione.', 'sd-logbook' )
				. '</div>';
		}

		$user_id = get_current_user_id();
		if ( ! SD_Roles::is_diabetic_diver( $user_id ) && ! current_user_can( 'manage_options' ) ) {
			return '<div class="sd-notice sd-notice-error">'
				. __( 'Sezione riservata ai subacquei diabetici.', 'sd-logbook' )
				. '</div>';
		}

		$glycemia_unit = $this->get_glycemia_unit( $user_id );
		$has_cgm       = $this->user_has_cgm( $user_id );

		ob_start();
		include SD_LOGBOOK_PLUGIN_DIR . 'templates/predive-check.php';
		return ob_get_clean();
	}

	// ================================================================
	// AJAX
	// ================================================================

	public function ajax_evaluate() {
		if ( ! check_ajax_referer( 'sd_predive_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta.', 'sd-logbook' ) ) );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Non autenticato.', 'sd-logbook' ) ) );
		}
		if ( ! SD_Roles::is_diabetic_diver( $user_id ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accesso non autorizzato.', 'sd-logbook' ) ) );
		}

		$unit   = $this->get_glycemia_unit( $user_id );
		$result = $this->evaluate( $user_id, $unit );

		wp_send_json_success( $result );
	}

	// ================================================================
	// LOGICA DI VALUTAZIONE
	// ================================================================

	/**
	 * Valuta le condizioni di sicurezza pre-immersione.
	 *
	 * @param int    $user_id
	 * @param string $unit 'mg/dl' | 'mmol/l'
	 * @return array
	 */
	private function evaluate( $user_id, $unit ) {
		global $wpdb;

		$t   = $wpdb->prefix . 'sd_nightscout_readings';
		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$has_cgm = $this->user_has_cgm( $user_id );

		// Ultima lettura CGM
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$last = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT glucose_value, direction, reading_time
				 FROM {$t}
				 WHERE user_id = %d
				 ORDER BY reading_time DESC
				 LIMIT 1",
				$user_id
			)
		);

		$alerts         = array();
		$status         = 'green';
		$glucose        = null;
		$glucose_display = null;
		$direction      = 'NONE';
		$arrow          = '—';
		$last_min       = null;

		$is_mmol = ( 'mmol/l' === $unit );

		if ( ! $last ) {
			// Nessun dato CGM
			$status   = 'red';
			$alerts[] = array(
				'level' => 'danger',
				'text'  => __( 'Nessun dato CGM trovato. Non è possibile valutare la glicemia.', 'sd-logbook' ),
			);
			return $this->build_result( $status, $glucose, $glucose_display, $direction, $arrow, $last_min, $alerts, $unit, $has_cgm );
		}

		// Calcola minuti dall'ultima lettura
		$last_dt  = new \DateTime( $last->reading_time, new \DateTimeZone( 'UTC' ) );
		$diff_sec = $now->getTimestamp() - $last_dt->getTimestamp();
		$last_min = max( 0, (int) floor( $diff_sec / 60 ) );

		$glucose   = (int) $last->glucose_value;
		$direction = $last->direction ?: 'NONE';
		$arrow     = $this->direction_arrow( $direction );

		$glucose_display = $is_mmol
			? number_format( $glucose / 18, 1 )
			: (string) $glucose;

		// ——————————————————————————————————————————
		// Regole ROSSE (blocco immediato)
		// ——————————————————————————————————————————

		if ( $last_min >= self::STALE_RED_MIN ) {
			$status   = 'red';
			$alerts[] = array(
				'level' => 'danger',
				'text'  => sprintf(
					/* translators: %d minutes */
					__( 'Ultima lettura CGM %d minuti fa: i dati sono troppo datati per una valutazione sicura.', 'sd-logbook' ),
					$last_min
				),
			);
		}

		if ( $glucose < self::THRESHOLD_RED_LOW ) {
			$status   = 'red';
			$alerts[] = array(
				'level' => 'danger',
				'text'  => sprintf(
					/* translators: %s glucose value with unit */
					__( 'Ipoglicemia: glicemia %s. Non immergerti.', 'sd-logbook' ),
					$glucose_display . ' ' . ( $is_mmol ? 'mmol/L' : 'mg/dL' )
				),
			);
		} elseif ( $glucose > self::THRESHOLD_RED_HIGH ) {
			$status   = 'red';
			$alerts[] = array(
				'level' => 'danger',
				'text'  => sprintf(
					/* translators: %s glucose value with unit */
					__( 'Iperglicemia grave: glicemia %s. Correggi prima di immergerti.', 'sd-logbook' ),
					$glucose_display . ' ' . ( $is_mmol ? 'mmol/L' : 'mg/dL' )
				),
			);
		}

		if ( in_array( $direction, self::TREND_FALL_FAST, true ) ) {
			$status   = 'red';
			$alerts[] = array(
				'level' => 'danger',
				'text'  => sprintf(
					/* translators: %s arrow */
					__( 'Calo rapido della glicemia %s: il rischio ipoglicemia in immersione è elevato.', 'sd-logbook' ),
					$arrow
				),
			);
		} elseif ( in_array( $direction, self::TREND_FALL_SLOW, true ) && $glucose < 130 ) {
			$status   = 'red';
			$alerts[] = array(
				'level' => 'danger',
				'text'  => sprintf(
					/* translators: 1: arrow, 2: glucose value */
					__( 'Glicemia %2$s in calo %1$s: troppo vicina alla soglia di ipoglicemia.', 'sd-logbook' ),
					$arrow,
					$glucose_display . ' ' . ( $is_mmol ? 'mmol/L' : 'mg/dL' )
				),
			);
		}

		// ——————————————————————————————————————————
		// Regole GIALLE (attenzione — solo se non già rosso su questo aspetto)
		// ——————————————————————————————————————————

		if ( 'green' === $status ) {
			if ( $last_min >= self::STALE_YELLOW_MIN ) {
				$status   = 'yellow';
				$alerts[] = array(
					'level' => 'warning',
					'text'  => sprintf(
						/* translators: %d minutes */
						__( 'Ultima lettura %d minuti fa: considera di aggiornare la lettura prima di immergerti.', 'sd-logbook' ),
						$last_min
					),
				);
			}

			if ( $glucose >= self::THRESHOLD_RED_LOW && $glucose < self::THRESHOLD_YELLOW_LOW ) {
				$status   = 'yellow';
				$alerts[] = array(
					'level' => 'warning',
					'text'  => sprintf(
						/* translators: %s glucose value with unit */
						__( 'Glicemia %s: nella zona borderline. La maggior parte dei protocolli richiede almeno 120 mg/dL prima di immergersi.', 'sd-logbook' ),
						$glucose_display . ' ' . ( $is_mmol ? 'mmol/L' : 'mg/dL' )
					),
				);
			} elseif ( $glucose > self::THRESHOLD_YELLOW_HIGH && $glucose <= self::THRESHOLD_ORANGE_HIGH ) {
				$status   = 'yellow';
				$alerts[] = array(
					'level' => 'warning',
					'text'  => sprintf(
						/* translators: %s glucose value with unit */
						__( 'Glicemia %s: leggermente sopra il range target. Valuta con il tuo medico.', 'sd-logbook' ),
						$glucose_display . ' ' . ( $is_mmol ? 'mmol/L' : 'mg/dL' )
					),
				);
			} elseif ( $glucose > self::THRESHOLD_ORANGE_HIGH && $glucose <= self::THRESHOLD_RED_HIGH ) {
				$status   = 'yellow';
				$alerts[] = array(
					'level' => 'warning',
					'text'  => sprintf(
						/* translators: %s glucose value with unit */
						__( 'Iperglicemia: glicemia %s. Consulta il tuo medico prima di immergerti.', 'sd-logbook' ),
						$glucose_display . ' ' . ( $is_mmol ? 'mmol/L' : 'mg/dL' )
					),
				);
			}

			if ( in_array( $direction, self::TREND_FALL_SLOW, true ) && $glucose >= 130 && $glucose <= self::THRESHOLD_YELLOW_HIGH ) {
				$status   = 'yellow';
				$alerts[] = array(
					'level' => 'warning',
					'text'  => sprintf(
						/* translators: %s arrow */
						__( 'Glicemia in lieve calo %s: monitora la tendenza e considera uno spuntino.', 'sd-logbook' ),
						$arrow
					),
				);
			}
		}

		// ——————————————————————————————————————————
		// Messaggio verde (tutto ok)
		// ——————————————————————————————————————————

		if ( 'green' === $status ) {
			$alerts[] = array(
				'level' => 'success',
				'text'  => __( 'Glicemia nella zona ideale pre-immersione. Buona immersione!', 'sd-logbook' ),
			);
		}

		// ——————————————————————————————————————————
		// Consigli aggiuntivi (informativi, sempre mostrati)
		// ——————————————————————————————————————————

		$alerts[] = array(
			'level' => 'info',
			'text'  => __( 'Ricorda: porta sempre zucchero a rapido assorbimento in superficie e informa il tuo buddy della tua condizione.', 'sd-logbook' ),
		);

		return $this->build_result( $status, $glucose, $glucose_display, $direction, $arrow, $last_min, $alerts, $unit, $has_cgm );
	}

	/**
	 * Assembla l'array di risposta.
	 */
	private function build_result( $status, $glucose, $glucose_display, $direction, $arrow, $last_min, $alerts, $unit, $has_cgm = true ) {
		$is_mmol = ( 'mmol/l' === $unit );

		$recommendation_map = array(
			'green'  => __( 'Le condizioni glicemiche sono favorevoli. Puoi procedere con l\'immersione seguendo le normali precauzioni.', 'sd-logbook' ),
			'yellow' => __( 'Procedi con cautela. Rivaluta tra 15 minuti o consulta il tuo medico di riferimento.', 'sd-logbook' ),
			'red'    => __( 'Non immergerti. Correggi la glicemia e attendi la stabilizzazione prima di rivalutare.', 'sd-logbook' ),
		);

		return array(
			'status'          => $status,
			'glucose'         => $glucose,
			'glucose_display' => $glucose_display,
			'unit'            => $is_mmol ? 'mmol/L' : 'mg/dL',
			'has_cgm'         => (bool) $has_cgm,
			'direction'       => $direction,
			'arrow'           => $arrow,
			'last_min'        => $last_min,
			'alerts'          => $alerts,
			'recommendation'  => $has_cgm ? ( $recommendation_map[ $status ] ?? '' ) : '',
		);
	}

	// ================================================================
	// HELPERS
	// ================================================================

	private function get_glycemia_unit( $user_id ) {
		global $wpdb;
		$db   = new SD_Database();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$unit = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT glycemia_unit FROM {$db->table('diver_profiles')} WHERE user_id = %d",
				$user_id
			)
		);
		return in_array( $unit, array( 'mg/dl', 'mmol/l' ), true ) ? $unit : 'mg/dl';
	}

	private function user_has_cgm( $user_id ) {
		global $wpdb;
		$db  = new SD_Database();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT uses_cgm FROM {$db->table('diver_profiles')} WHERE user_id = %d",
				$user_id
			)
		);
		return (bool) $val;
	}

	private function direction_arrow( $dir ) {
		$map = array(
			'TripleUp'      => '↑↑↑',
			'DoubleUp'      => '↑↑',
			'SingleUp'      => '↑',
			'FortyFiveUp'   => '↗',
			'Flat'          => '→',
			'FortyFiveDown' => '↘',
			'SingleDown'    => '↓',
			'DoubleDown'    => '↓↓',
			'TripleDown'    => '↓↓↓',
			'NONE'          => '—',
		);
		return $map[ $dir ] ?? '—';
	}
}
