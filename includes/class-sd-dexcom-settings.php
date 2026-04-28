<?php
/**
 * Pagina impostazioni admin per l'integrazione Dexcom API Ufficiale
 *
 * Consente all'amministratore di inserire client_id, client_secret
 * e scegliere tra modalità sandbox e produzione.
 *
 * @package ScubaDiabetes_Logbook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SD_Dexcom_Settings
 *
 * Aggiunge una pagina sotto Impostazioni > "Dexcom API OAuth" nel
 * pannello di amministrazione WordPress.
 */
class SD_Dexcom_Settings {

	/** @var string Slug pagina WP admin */
	const PAGE_SLUG = 'sd-dexcom-oauth';

	/** @var string Gruppo opzioni per register_setting */
	const OPTION_GROUP = 'sd_dexcom_oauth_options';

	public function __construct() {
		add_action( 'admin_menu',  array( $this, 'add_settings_page' ) );
		add_action( 'admin_init',  array( $this, 'register_settings' ) );
	}

	/**
	 * Registra la pagina nell'admin menu WP sotto "Impostazioni".
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Dexcom API OAuth', 'sd-logbook' ),
			__( 'Dexcom OAuth', 'sd-logbook' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registra le opzioni WordPress per la sanitizzazione sicura.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			'sd_dexcom_oauth_client_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'sd_dexcom_oauth_client_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'sd_dexcom_oauth_sandbox',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
	}

	/**
	 * Renderizza la pagina impostazioni HTML.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permesso negato.', 'sd-logbook' ) );
		}

		$client_id     = get_option( 'sd_dexcom_oauth_client_id', '' );
		$client_secret = get_option( 'sd_dexcom_oauth_client_secret', '' );
		$sandbox       = (bool) get_option( 'sd_dexcom_oauth_sandbox', 0 );
		$redirect_uri  = SD_Dexcom_OAuth::get_redirect_uri();
		$configured    = SD_Dexcom_OAuth::is_configured();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dexcom API OAuth — Impostazioni', 'sd-logbook' ); ?></h1>

			<div style="max-width:780px;">

				<!-- Istruzioni -->
				<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #0073aa;padding:16px 20px;margin:20px 0;border-radius:3px;">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Come configurare l\'integrazione Dexcom', 'sd-logbook' ); ?></h3>
					<ol style="line-height:1.9;">
						<li><?php
							printf(
								/* translators: %s = URL portale Dexcom */
								esc_html__( 'Vai su %s e accedi con il tuo account developer.', 'sd-logbook' ),
								'<a href="https://developer.dexcom.com" target="_blank" rel="noopener">developer.dexcom.com</a>'
							);
						?></li>
						<li><?php esc_html_e( 'Crea o modifica la tua app e copia Client ID e Client Secret.', 'sd-logbook' ); ?></li>
						<li>
							<?php esc_html_e( 'Nel portale Dexcom, imposta il seguente Redirect URI nella sezione "Redirect URIs" della tua app:', 'sd-logbook' ); ?><br>
							<code style="display:inline-block;margin:8px 0;padding:8px 12px;background:#f0f0f1;border:1px solid #ccd0d4;border-radius:3px;font-size:13px;word-break:break-all;">
								<?php echo esc_html( $redirect_uri ); ?>
							</code>
							<br>
							<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $redirect_uri ); ?>').then(function(){this.textContent='Copiato!';}.bind(this))">
								<?php esc_html_e( 'Copia URI', 'sd-logbook' ); ?>
							</button>
						</li>
						<li><?php esc_html_e( 'Incolla Client ID e Client Secret nei campi qui sotto e salva.', 'sd-logbook' ); ?></li>
						<li><?php esc_html_e( 'Per il testing, usa la modalità Sandbox. Per accesso utenti reali, richiedi a Dexcom lo status "Limited Access" o "Full Access".', 'sd-logbook' ); ?></li>
					</ol>
				</div>

				<!-- Stato configurazione -->
				<?php if ( $configured ) : ?>
				<div style="background:#edfaef;border:1px solid #5cb85c;border-left:4px solid #5cb85c;padding:12px 16px;margin-bottom:20px;border-radius:3px;">
					✅ <?php esc_html_e( 'Integrazione configurata correttamente.', 'sd-logbook' ); ?>
					<?php if ( $sandbox ) : ?>
					— <strong><?php esc_html_e( 'Modalità SANDBOX attiva (dati di test)', 'sd-logbook' ); ?></strong>
					<?php endif; ?>
				</div>
				<?php else : ?>
				<div style="background:#fff8e5;border:1px solid #ffb900;border-left:4px solid #ffb900;padding:12px 16px;margin-bottom:20px;border-radius:3px;">
					⚠️ <?php esc_html_e( 'Integrazione non ancora configurata. Gli utenti non potranno connettere il proprio account Dexcom.', 'sd-logbook' ); ?>
				</div>
				<?php endif; ?>

				<!-- Form impostazioni -->
				<form method="post" action="options.php">
					<?php settings_fields( self::OPTION_GROUP ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="sd_dexcom_oauth_client_id"><?php esc_html_e( 'Client ID', 'sd-logbook' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="sd_dexcom_oauth_client_id"
									name="sd_dexcom_oauth_client_id"
									value="<?php echo esc_attr( $client_id ); ?>"
									class="regular-text"
									autocomplete="off"
									placeholder="es. abc12345-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
								>
								<p class="description"><?php esc_html_e( 'Trovalo nella sezione "My Apps" del portale developer Dexcom.', 'sd-logbook' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="sd_dexcom_oauth_client_secret"><?php esc_html_e( 'Client Secret', 'sd-logbook' ); ?></label>
							</th>
							<td>
								<input
									type="password"
									id="sd_dexcom_oauth_client_secret"
									name="sd_dexcom_oauth_client_secret"
									value="<?php echo esc_attr( $client_secret ); ?>"
									class="regular-text"
									autocomplete="new-password"
								>
								<p class="description"><?php esc_html_e( 'Non condividere mai questo valore. Viene salvato nel database WordPress.', 'sd-logbook' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Modalità', 'sd-logbook' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="sd_dexcom_oauth_sandbox" value="0" <?php checked( $sandbox, false ); ?>>
										<?php esc_html_e( 'Produzione (dati reali utenti)', 'sd-logbook' ); ?>
									</label><br>
									<label>
										<input type="radio" name="sd_dexcom_oauth_sandbox" value="1" <?php checked( $sandbox, true ); ?>>
										<?php esc_html_e( 'Sandbox (dati fittizi per sviluppo e test)', 'sd-logbook' ); ?>
									</label>
								</fieldset>
								<p class="description">
									<?php
									printf(
										/* translators: %s = URL documentazione Dexcom sandbox */
										esc_html__( 'In modalità sandbox, usa le credenziali di test descritte su %s.', 'sd-logbook' ),
										'<a href="https://developer.dexcom.com/docs/dexcom/sandbox-data" target="_blank" rel="noopener">Sandbox Data</a>'
									);
									?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Salva impostazioni', 'sd-logbook' ) ); ?>
				</form>

				<!-- Informazioni tecniche -->
				<hr>
				<h3><?php esc_html_e( 'Informazioni tecniche', 'sd-logbook' ); ?></h3>
				<table class="widefat striped" style="max-width:600px;">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Redirect URI', 'sd-logbook' ); ?></strong></td>
							<td><code><?php echo esc_html( $redirect_uri ); ?></code></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'API Base URL', 'sd-logbook' ); ?></strong></td>
							<td><code><?php echo esc_html( SD_Dexcom_OAuth::get_base_url() ); ?></code></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Scope richiesto', 'sd-logbook' ); ?></strong></td>
							<td><code>offline_access</code></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Sync automatico', 'sd-logbook' ); ?></strong></td>
							<td><?php esc_html_e( 'Ogni ora (WP Cron)', 'sd-logbook' ); ?></td>
						</tr>
					</tbody>
				</table>

			</div><!-- /max-width -->
		</div><!-- /wrap -->
		<?php
	}
}
