<?php
/**
 * Impostazioni admin per le attività e i relativi pagamenti.
 *
 * @package SD_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SD_Activity_Settings {

	/** @var string */
	const PAGE_SLUG = 'sd-activity-settings';

	/** @var string */
	const OPTION_GROUP = 'sd_activity_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Registra pagina menu impostazioni.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Impostazioni Attività', 'sd-logbook' ),
			__( 'Impostazioni Attività', 'sd-logbook' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registra opzioni WP per le attività.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			'sd_activity_payment_checkout_url',
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'sd_activity_payment_confirmation_url',
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
	}

	/**
	 * Render pagina impostazioni.
	 *
	 * @return void
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Impostazioni Attività', 'sd-logbook' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="sd_activity_payment_checkout_url">
								<?php esc_html_e( 'URL Pagina Checkout Pagamento', 'sd-logbook' ); ?>
							</label>
						</th>
						<td>
							<input
								type="url"
								id="sd_activity_payment_checkout_url"
								name="sd_activity_payment_checkout_url"
								class="regular-text"
								value="<?php echo esc_url( get_option( 'sd_activity_payment_checkout_url', '' ) ); ?>"
								placeholder="<?php echo esc_attr( home_url( '/iscrizione-attivita-pagamento/' ) ); ?>"
							/>
							<p class="description">
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: default URL */
										__( 'URL della pagina con lo shortcode <code>[sd_activity_payment_checkout]</code>. Se vuota, usa: <code>%s</code>', 'sd-logbook' ),
										home_url( '/iscrizione-attivita-pagamento/' )
									)
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sd_activity_payment_confirmation_url">
								<?php esc_html_e( 'URL Pagina Conferma Pagamento', 'sd-logbook' ); ?>
							</label>
						</th>
						<td>
							<input
								type="url"
								id="sd_activity_payment_confirmation_url"
								name="sd_activity_payment_confirmation_url"
								class="regular-text"
								value="<?php echo esc_url( get_option( 'sd_activity_payment_confirmation_url', '' ) ); ?>"
								placeholder="<?php echo esc_attr( home_url( '/iscrizione-attivita-conferma/' ) ); ?>"
							/>
							<p class="description">
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: default URL */
										__( 'URL della pagina con lo shortcode <code>[sd_activity_payment_confirmation]</code>. Se vuota, usa: <code>%s</code>', 'sd-logbook' ),
										home_url( '/iscrizione-attivita-conferma/' )
									)
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Shortcodes Disponibili', 'sd-logbook' ); ?></h3>
				<p>
					<?php esc_html_e( 'Utilizza questi shortcode nelle tue pagine:', 'sd-logbook' ); ?>
				</p>
				<ul style="margin-left: 20px; list-style-type: disc;">
					<li>
						<code>[sd_iscrizione_attivita activity_id="X"]</code> —
						<?php esc_html_e( 'Modulo di iscrizione per un\'attività', 'sd-logbook' ); ?>
					</li>
					<li>
						<code>[sd_activity_payment_checkout]</code> —
						<?php esc_html_e( 'Pagina di scelta metodo pagamento (da configurare sopra)', 'sd-logbook' ); ?>
					</li>
					<li>
						<code>[sd_activity_payment_confirmation]</code> —
						<?php esc_html_e( 'Pagina di conferma dopo il pagamento (da configurare sopra)', 'sd-logbook' ); ?>
					</li>
					<li>
						<code>[sd_gestione_attivita]</code> —
						<?php esc_html_e( 'Dashboard amministrativa per gestire attività e iscrizioni (riservato admin)', 'sd-logbook' ); ?>
					</li>
				</ul>

				<?php submit_button(); ?>
			</form>

			<hr style="margin-top:40px;" />

			<h3><?php esc_html_e( 'Flusso di Pagamento', 'sd-logbook' ); ?></h3>
			<ol style="margin-left: 20px;">
				<li><?php esc_html_e( 'Utente compila modulo iscrizione [sd_iscrizione_attivita activity_id="X"]', 'sd-logbook' ); ?></li>
				<li><?php esc_html_e( 'Sistema crea registrazione e genera token pagamento (valido 48h)', 'sd-logbook' ); ?></li>
				<li><?php esc_html_e( 'Utente reindirizzato automaticamente a pagina checkout', 'sd-logbook' ); ?></li>
				<li><?php esc_html_e( 'Sceglie metodo: Stripe (carta/Apple Pay/Google Pay/TWINT), PayPal o Fattura', 'sd-logbook' ); ?></li>
				<li><?php esc_html_e( 'Pagamento elaborato e confermato', 'sd-logbook' ); ?></li>
				<li><?php esc_html_e( 'Email di conferma inviata al partecipante', 'sd-logbook' ); ?></li>
			</ol>

		</div>
		<?php
	}
}
