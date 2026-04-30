<?php
/**
 * Template: Modifica iscrizione singolo socio
 *
 * Variabili disponibili:
 * @var object $member         Dati completi del socio
 * @var array  $family_members Familiari
 * @var array  $companions     Accompagnatori
 * @var array  $payments       Storico pagamenti
 * @var array  $audit_log      Log modifiche
 * @var array  $countries      Lista paesi
 * @var array  $swiss_cantons  Lista cantoni
 * @var string $current_year   Anno corrente
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_admin = current_user_can( 'administrator' );

// Helper per safe output
function sd_val( $obj, $key, $default = '' ) {
	return isset( $obj->$key ) ? $obj->$key : $default;
}
?>
<div class="sd-form-wrap sd-edit-wrap" id="sd-edit-page">

	<div class="sd-form-header">
		<div class="sd-breadcrumb">
			<a href="javascript:history.back()" class="sd-back-link">← <?php esc_html_e( 'Torna alla lista', 'sd-logbook' ); ?></a>
		</div>
		<h2 class="sd-form-title">
			<?php echo esc_html( $member->first_name . ' ' . $member->last_name ); ?>
			<span class="sd-member-id">#<?php echo esc_html( $member->id ); ?></span>
		</h2>
		<div class="sd-member-badges">
			<?php if ( $member->is_scuba ) : ?>
				<span class="sd-badge sd-badge-blue"><?php esc_html_e( 'Subacqueo', 'sd-logbook' ); ?></span>
			<?php endif; ?>
			<?php if ( $member->is_diabetic ) : ?>
				<span class="sd-badge sd-badge-orange"><?php esc_html_e( 'Diabetico', 'sd-logbook' ); ?></span>
			<?php endif; ?>
			<?php if ( $member->sotto_tutela ) : ?>
				<span class="sd-badge sd-badge-warning"><?php esc_html_e( 'Sotto tutela', 'sd-logbook' ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<div id="sd-edit-message" class="sd-notice" style="display:none;"></div>

	<form id="sd-edit-form" data-member-id="<?php echo esc_attr( $member->id ); ?>">
		<input type="hidden" name="member_id" value="<?php echo esc_attr( $member->id ); ?>">

		<!-- TAB navigation -->
		<div class="sd-tabs">
			<button type="button" class="sd-tab-btn active" data-tab="anagrafica"><?php esc_html_e( 'Anagrafica', 'sd-logbook' ); ?></button>
			<button type="button" class="sd-tab-btn" data-tab="gestione"><?php esc_html_e( 'Gestione', 'sd-logbook' ); ?></button>
			<?php if ( $member->is_scuba ) : ?>
				<button type="button" class="sd-tab-btn" data-tab="subacqueo"><?php esc_html_e( 'Dati subacqueo', 'sd-logbook' ); ?></button>
			<?php endif; ?>
			<?php if ( $member->sotto_tutela || ! empty( $family_members ) || ! empty( $companions ) ) : ?>
				<button type="button" class="sd-tab-btn" data-tab="famiglia"><?php esc_html_e( 'Famiglia', 'sd-logbook' ); ?></button>
			<?php endif; ?>
			<?php
			$has_registered_family = ! empty( $registered_family_members );
			$family_tab_class      = $has_registered_family ? 'sd-tab-btn sd-tab-btn-highlight' : 'sd-tab-btn';
			?>
			<button type="button" class="<?php echo esc_attr( $family_tab_class ); ?>" data-tab="famigliari">
				<?php esc_html_e( 'Famigliari', 'sd-logbook' ); ?>
				<?php if ( $has_registered_family ) : ?>
					<span class="sd-tab-badge"><?php echo esc_html( count( $registered_family_members ) ); ?></span>
				<?php endif; ?>
			</button>
			<button type="button" class="sd-tab-btn" data-tab="pagamenti"><?php esc_html_e( 'Pagamenti', 'sd-logbook' ); ?></button>
			<button type="button" class="sd-tab-btn" data-tab="log"><?php esc_html_e( 'Log', 'sd-logbook' ); ?></button>
		</div>

		<!-- === TAB ANAGRAFICA === -->
		<div class="sd-tab-content active" id="sd-tab-anagrafica">
			<div class="sd-form-section">
				<h3 class="sd-section-title"><?php esc_html_e( 'Dati anagrafici', 'sd-logbook' ); ?></h3>

				<div class="sd-field-row">
					<div class="sd-field-group sd-field-half">
						<label class="sd-label sd-label-required"><?php esc_html_e( 'Nome', 'sd-logbook' ); ?></label>
						<input type="text" name="first_name" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'first_name' ) ); ?>" required>
					</div>
					<div class="sd-field-group sd-field-half">
						<label class="sd-label sd-label-required"><?php esc_html_e( 'Cognome', 'sd-logbook' ); ?></label>
						<input type="text" name="last_name" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'last_name' ) ); ?>" required>
					</div>
				</div>

				<div class="sd-field-row">
					<div class="sd-field-group sd-field-third">
						<label class="sd-label"><?php esc_html_e( 'Data di nascita', 'sd-logbook' ); ?></label>
						<input type="date" name="date_of_birth" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'date_of_birth' ) ); ?>">
					</div>
					<div class="sd-field-group sd-field-third">
						<label class="sd-label"><?php esc_html_e( 'Genere', 'sd-logbook' ); ?></label>
						<select name="gender" class="sd-select">
							<?php foreach ( array( 'M' => 'Maschile (M)', 'F' => 'Femminile (F)', 'NB' => 'Non binario (NB)', 'U' => 'Non indicato (U)' ) as $val => $lbl ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( sd_val( $member, 'gender' ), $val ); ?>>
									<?php echo esc_html( $lbl ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="sd-field-group sd-field-third">
						<label class="sd-label"><?php esc_html_e( 'Sotto tutela', 'sd-logbook' ); ?></label>
						<select name="sotto_tutela" class="sd-select">
							<option value="0" <?php selected( sd_val( $member, 'sotto_tutela', 0 ), 0 ); ?>><?php esc_html_e( 'No', 'sd-logbook' ); ?></option>
							<option value="1" <?php selected( sd_val( $member, 'sotto_tutela', 0 ), 1 ); ?>><?php esc_html_e( 'Sì', 'sd-logbook' ); ?></option>
						</select>
					</div>
				</div>

				<div class="sd-field-row">
					<div class="sd-field-group sd-field-half">
						<label class="sd-label"><?php esc_html_e( 'Luogo di nascita', 'sd-logbook' ); ?></label>
						<input type="text" name="birth_place" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'birth_place' ) ); ?>">
					</div>
					<div class="sd-field-group sd-field-half">
						<label class="sd-label"><?php esc_html_e( 'Nazione di nascita', 'sd-logbook' ); ?></label>
						<select name="birth_country" class="sd-select">
							<?php foreach ( $countries as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( sd_val( $member, 'birth_country', 'CH' ), $code ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="sd-field-row">
					<div class="sd-field-group sd-field-half">
						<label class="sd-label"><?php esc_html_e( 'Email', 'sd-logbook' ); ?></label>
						<input type="email" name="email" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'email' ) ); ?>">
					</div>
					<div class="sd-field-group sd-field-half">
						<label class="sd-label"><?php esc_html_e( 'Telefono', 'sd-logbook' ); ?></label>
						<input type="tel" name="phone" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'phone' ) ); ?>">
					</div>
				</div>

				<div class="sd-field-row">
					<div class="sd-field-group sd-field-full">
						<label class="sd-label"><?php esc_html_e( 'Via / Indirizzo', 'sd-logbook' ); ?></label>
						<input type="text" name="address_street" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'address_street' ) ); ?>">
					</div>
				</div>

				<div class="sd-field-row">
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'CAP', 'sd-logbook' ); ?></label>
						<input type="text" name="address_postal" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'address_postal' ) ); ?>">
					</div>
					<div class="sd-field-group sd-field-half">
						<label class="sd-label"><?php esc_html_e( 'Città', 'sd-logbook' ); ?></label>
						<input type="text" name="address_city" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'address_city' ) ); ?>">
					</div>
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Nazione', 'sd-logbook' ); ?></label>
						<select name="address_country" class="sd-select">
							<?php foreach ( $countries as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( sd_val( $member, 'address_country', 'CH' ), $code ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="sd-field-row">
					<div class="sd-field-group sd-field-half">
						<label class="sd-label"><?php esc_html_e( 'Cantone', 'sd-logbook' ); ?></label>
						<select name="address_canton" class="sd-select">
							<option value=""><?php esc_html_e( '-- Seleziona --', 'sd-logbook' ); ?></option>
							<?php foreach ( $swiss_cantons as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( sd_val( $member, 'address_canton' ), $code ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="sd-field-group sd-field-half">
						<label class="sd-label"><?php esc_html_e( 'Codice fiscale / AVS', 'sd-logbook' ); ?></label>
						<input type="text" name="fiscal_code" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'fiscal_code' ) ); ?>">
					</div>
				</div>

				<div class="sd-field-row">
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Taglia Maglietta', 'sd-logbook' ); ?></label>
						<select name="taglia_maglietta" class="sd-select">
							<option value=""><?php esc_html_e( '-- Non indicata --', 'sd-logbook' ); ?></option>
							<optgroup label="<?php esc_attr_e( 'Bambino/a', 'sd-logbook' ); ?>">
								<option value="baby_2-3"   <?php selected( sd_val( $member, 'taglia_maglietta' ), 'baby_2-3' ); ?>><?php esc_html_e( '2–3 anni', 'sd-logbook' ); ?></option>
								<option value="baby_4-5"   <?php selected( sd_val( $member, 'taglia_maglietta' ), 'baby_4-5' ); ?>><?php esc_html_e( '4–5 anni', 'sd-logbook' ); ?></option>
								<option value="baby_6-8"   <?php selected( sd_val( $member, 'taglia_maglietta' ), 'baby_6-8' ); ?>><?php esc_html_e( '6–8 anni', 'sd-logbook' ); ?></option>
								<option value="baby_9-11"  <?php selected( sd_val( $member, 'taglia_maglietta' ), 'baby_9-11' ); ?>><?php esc_html_e( '9–11 anni', 'sd-logbook' ); ?></option>
								<option value="baby_12-14" <?php selected( sd_val( $member, 'taglia_maglietta' ), 'baby_12-14' ); ?>><?php esc_html_e( '12–14 anni', 'sd-logbook' ); ?></option>
							</optgroup>
							<optgroup label="<?php esc_attr_e( 'Adulto/a', 'sd-logbook' ); ?>">
								<option value="XXS"  <?php selected( sd_val( $member, 'taglia_maglietta' ), 'XXS' ); ?>>XXS</option>
								<option value="XS"   <?php selected( sd_val( $member, 'taglia_maglietta' ), 'XS' ); ?>>XS</option>
								<option value="S"    <?php selected( sd_val( $member, 'taglia_maglietta' ), 'S' ); ?>>S</option>
								<option value="M"    <?php selected( sd_val( $member, 'taglia_maglietta' ), 'M' ); ?>>M</option>
								<option value="L"    <?php selected( sd_val( $member, 'taglia_maglietta' ), 'L' ); ?>>L</option>
								<option value="XL"   <?php selected( sd_val( $member, 'taglia_maglietta' ), 'XL' ); ?>>XL</option>
								<option value="XXL"  <?php selected( sd_val( $member, 'taglia_maglietta' ), 'XXL' ); ?>>XXL</option>
								<option value="XXXL" <?php selected( sd_val( $member, 'taglia_maglietta' ), 'XXXL' ); ?>>XXXL</option>
							</optgroup>
						</select>
					</div>
				</div>
				<div class="sd-field-row">
					<div class="sd-field-group sd-field-half">
						<label class="sd-label"><?php esc_html_e( 'Tipo diabete', 'sd-logbook' ); ?></label>
						<select name="diabetes_type" class="sd-select">
							<option value="non_diabetico" <?php selected( sd_val( $member, 'dp_diabetes_type', sd_val( $member, 'diabetes_type' ) ), 'non_diabetico' ); ?>><?php esc_html_e( 'Non diabetico', 'sd-logbook' ); ?></option>
							<option value="tipo_1" <?php selected( sd_val( $member, 'dp_diabetes_type', sd_val( $member, 'diabetes_type' ) ), 'tipo_1' ); ?>><?php esc_html_e( 'Tipo 1', 'sd-logbook' ); ?></option>
							<option value="tipo_2" <?php selected( sd_val( $member, 'dp_diabetes_type', sd_val( $member, 'diabetes_type' ) ), 'tipo_2' ); ?>><?php esc_html_e( 'Tipo 2', 'sd-logbook' ); ?></option>
							<option value="tipo_3c" <?php selected( sd_val( $member, 'dp_diabetes_type', sd_val( $member, 'diabetes_type' ) ), 'tipo_3c' ); ?>><?php esc_html_e( 'Tipo 3c (pancreasectomia, pancreatite)', 'sd-logbook' ); ?></option>
							<option value="lada" <?php selected( sd_val( $member, 'dp_diabetes_type', sd_val( $member, 'diabetes_type' ) ), 'lada' ); ?>>LADA</option>
							<option value="mody" <?php selected( sd_val( $member, 'dp_diabetes_type', sd_val( $member, 'diabetes_type' ) ), 'mody' ); ?>>MODY</option>
							<option value="midd" <?php selected( sd_val( $member, 'dp_diabetes_type', sd_val( $member, 'diabetes_type' ) ), 'midd' ); ?>>MIDD</option>
							<option value="altro" <?php selected( sd_val( $member, 'dp_diabetes_type', sd_val( $member, 'diabetes_type' ) ), 'altro' ); ?>><?php esc_html_e( 'Altro', 'sd-logbook' ); ?></option>
							<option value="non_specificato" <?php selected( sd_val( $member, 'dp_diabetes_type', sd_val( $member, 'diabetes_type' ) ), 'non_specificato' ); ?>><?php esc_html_e( 'Non specificato (legacy)', 'sd-logbook' ); ?></option>
						</select>
					</div>
				</div>			</div>

			<!-- Ruolo WP -->
			<div class="sd-form-section">
				<h3 class="sd-section-title"><?php esc_html_e( 'Ruolo WordPress', 'sd-logbook' ); ?></h3>
				<div class="sd-field-row">
					<div class="sd-field-group sd-field-half">
						<label class="sd-label"><?php esc_html_e( 'Ruolo WP attuale', 'sd-logbook' ); ?></label>
						<?php
						$wp_user_roles = array();
						if ( $member->wp_user_id ) {
							$wp_user_obj = get_userdata( $member->wp_user_id );
							if ( $wp_user_obj ) {
								$wp_user_roles = (array) $wp_user_obj->roles;
							}
						}
						?>
						<?php
						$is_admin = in_array( 'administrator', $wp_user_roles, true );
						if ( $is_admin ) : ?>
							<p class="sd-admin-badge"><strong><?php esc_html_e( 'Administrator (WordPress)', 'sd-logbook' ); ?></strong></p>
						<?php endif; ?>
						<div class="sd-checkbox-group">
							<?php
							$sd_role_options = array(
								'sd_diver_diabetic' => __( 'Subacqueo Diabetico', 'sd-logbook' ),
								'sd_diver'          => __( 'Subacqueo', 'sd-logbook' ),
								'sd_staff'          => __( 'Staff', 'sd-logbook' ),
								'sd_medical'        => __( 'Medico', 'sd-logbook' ),
								'subscriber'        => __( 'Subscriber', 'sd-logbook' ),
							);
							foreach ( $sd_role_options as $role_val => $role_label ) :
							?>
								<label class="sd-checkbox-label">
									<input type="checkbox" name="wp_roles[]" value="<?php echo esc_attr( $role_val ); ?>"
										<?php checked( in_array( $role_val, $wp_user_roles, true ) ); ?>>
									<?php echo esc_html( $role_label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="sd-field-group sd-field-half">
						<label class="sd-label"><?php esc_html_e( 'Subacqueo', 'sd-logbook' ); ?></label>
						<select name="is_scuba" class="sd-select">
							<option value="0" <?php selected( sd_val( $member, 'is_scuba', 0 ), 0 ); ?>><?php esc_html_e( 'No', 'sd-logbook' ); ?></option>
							<option value="1" <?php selected( sd_val( $member, 'is_scuba', 0 ), 1 ); ?>><?php esc_html_e( 'Sì', 'sd-logbook' ); ?></option>
						</select>
					</div>
				</div>
			</div>
		</div>

		<!-- === TAB GESTIONE === -->
		<div class="sd-tab-content" id="sd-tab-gestione">
			<div class="sd-form-section">
				<h3 class="sd-section-title"><?php esc_html_e( 'Dati di gestione', 'sd-logbook' ); ?></h3>

				<div class="sd-field-row">
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Tipo socio', 'sd-logbook' ); ?></label>
						<select name="member_type" class="sd-select">
							<?php
							$mt_options = array(
								'attivo'               => __( 'Attivo', 'sd-logbook' ),
								'attivo_capo_famiglia' => __( 'Attivo Capo Famiglia', 'sd-logbook' ),
								'attivo_famigliare'    => __( 'Attivo Famigliare', 'sd-logbook' ),
								'passivo'              => __( 'Passivo', 'sd-logbook' ),
								'accompagnatore'       => __( 'Accompagnatore', 'sd-logbook' ),
								'sostenitore'          => __( 'Sostenitore', 'sd-logbook' ),
								'onorario'             => __( 'Onorario', 'sd-logbook' ),
								'fondatore'            => __( 'Fondatore', 'sd-logbook' ),
							);
							foreach ( $mt_options as $mt_val => $mt_label ) :
							?>
								<option value="<?php echo esc_attr( $mt_val ); ?>" <?php selected( sd_val( $member, 'member_type', 'attivo' ), $mt_val ); ?>>
									<?php echo esc_html( $mt_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Tassa (CHF)', 'sd-logbook' ); ?></label>
						<input type="number" name="fee_amount" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'fee_amount', 50 ) ); ?>" min="0" step="0.01">
					</div>
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Scadenza iscrizione', 'sd-logbook' ); ?></label>
						<input type="date" name="membership_expiry" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'membership_expiry' ) ); ?>">
					</div>
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Socio attivo', 'sd-logbook' ); ?></label>
						<select name="is_active" class="sd-select">
							<option value="1" <?php selected( sd_val( $member, 'is_active', 1 ), 1 ); ?>><?php esc_html_e( 'Sì', 'sd-logbook' ); ?></option>
							<option value="0" <?php selected( sd_val( $member, 'is_active', 1 ), 0 ); ?>><?php esc_html_e( 'No', 'sd-logbook' ); ?></option>
						</select>
					</div>
				</div>

				<!-- Pagamento corrente -->
				<h4 class="sd-subsection-title"><?php echo esc_html( sprintf( __( 'Pagamento %s', 'sd-logbook' ), $current_year ) ); ?></h4>

				<?php
				// Trova pagamento anno corrente
				$current_pay = null;
				foreach ( $payments as $pay ) {
					if ( $pay->payment_year == $current_year ) {
						$current_pay = $pay;
						break;
					}
				}
				?>

				<div class="sd-field-row">
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Pagato', 'sd-logbook' ); ?></label>
						<?php if ( sd_val( $member, 'member_type' ) === 'attivo_famigliare' ) : ?>
							<input type="hidden" name="has_paid_fee" value="1">
							<input type="text" class="sd-input" value="<?php esc_attr_e( 'Famigliare', 'sd-logbook' ); ?>" readonly style="background:#f0f4f8;pointer-events:none;color:#555;">
						<?php else : ?>
							<select name="has_paid_fee" class="sd-select sd-payment-status-select">
								<option value="0" <?php selected( sd_val( $member, 'has_paid_fee', 0 ), 0 ); ?>><?php esc_html_e( 'Non pagato', 'sd-logbook' ); ?></option>
								<option value="1" <?php selected( sd_val( $member, 'has_paid_fee', 0 ), 1 ); ?>><?php esc_html_e( 'Pagato', 'sd-logbook' ); ?></option>
							</select>
						<?php endif; ?>
					</div>
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Data pagamento', 'sd-logbook' ); ?></label>
						<input type="date" name="payment_date" class="sd-input" value="<?php echo esc_attr( $current_pay ? substr( $current_pay->payment_date ?? '', 0, 10 ) : '' ); ?>">
					</div>
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Metodo', 'sd-logbook' ); ?></label>
						<select name="payment_method" class="sd-select">
							<option value="" <?php selected( $current_pay ? $current_pay->payment_method : '', '' ); ?>><?php esc_html_e( '-- Seleziona --', 'sd-logbook' ); ?></option>
							<option value="bonifico_iban" <?php selected( $current_pay ? $current_pay->payment_method : '', 'bonifico_iban' ); ?>><?php esc_html_e( 'Bonifico IBAN', 'sd-logbook' ); ?></option>
							<option value="twint" <?php selected( $current_pay ? $current_pay->payment_method : '', 'twint' ); ?>>TWINT</option>
							<option value="paypal" <?php selected( $current_pay ? $current_pay->payment_method : '', 'paypal' ); ?>>PayPal</option>
							<option value="carta_credito" <?php selected( $current_pay ? $current_pay->payment_method : '', 'carta_credito' ); ?>><?php esc_html_e( 'Carta di credito / debito', 'sd-logbook' ); ?></option>
							<option value="apple_pay" <?php selected( $current_pay ? $current_pay->payment_method : '', 'apple_pay' ); ?>>Apple Pay</option>
							<option value="google_pay" <?php selected( $current_pay ? $current_pay->payment_method : '', 'google_pay' ); ?>>Google Pay</option>
							<option value="fattura" <?php selected( $current_pay ? $current_pay->payment_method : '', 'fattura' ); ?>><?php esc_html_e( 'Fattura', 'sd-logbook' ); ?></option>
						</select>
					</div>
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Anno', 'sd-logbook' ); ?></label>
						<input type="number" name="payment_year" class="sd-input" value="<?php echo esc_attr( $current_year ); ?>" min="2020" max="2099">
					</div>
				</div>

				<!-- Osservazioni -->
				<div class="sd-field-group sd-field-full">
					<label class="sd-label"><?php esc_html_e( 'Osservazioni / Note', 'sd-logbook' ); ?></label>
					<textarea name="notes" class="sd-textarea" rows="4"><?php echo esc_textarea( sd_val( $member, 'notes' ) ); ?></textarea>
				</div>

				<!-- Info registrazione (read-only) -->
				<div class="sd-info-row">
					<span class="sd-info-label"><?php esc_html_e( 'Registrato il:', 'sd-logbook' ); ?></span>
					<span class="sd-info-value"><?php
					$reg_raw = sd_val( $member, 'registered_at' ) ?: sd_val( $member, 'user_registered' );
					echo esc_html( $reg_raw ? date_i18n( 'd/m/Y', strtotime( $reg_raw ) ) : '—' );
					?></span>
				</div>
				<?php if ( sd_val( $member, 'registered_by' ) ) : ?>
				<div class="sd-info-row">
					<span class="sd-info-label"><?php esc_html_e( 'Registrato da:', 'sd-logbook' ); ?></span>
					<span class="sd-info-value">
						<?php
						$reg_user = get_userdata( sd_val( $member, 'registered_by' ) );
						echo esc_html( $reg_user ? $reg_user->display_name : '—' );
						?>
					</span>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<!-- === TAB SUBACQUEO === -->
		<?php if ( $member->is_scuba ) : ?>
		<div class="sd-tab-content" id="sd-tab-subacqueo">
			<div class="sd-form-section">
				<h3 class="sd-section-title"><?php esc_html_e( 'Dati subacqueo', 'sd-logbook' ); ?></h3>

				<div class="sd-field-row">
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Peso (kg)', 'sd-logbook' ); ?></label>
						<input type="number" name="weight" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'weight' ) ); ?>" step="0.1">
					</div>
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Altezza (cm)', 'sd-logbook' ); ?></label>
						<input type="number" name="height" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'height' ) ); ?>">
					</div>
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Gruppo sanguigno', 'sd-logbook' ); ?></label>
						<select name="blood_type" class="sd-select">
							<option value="">—</option>
							<?php foreach ( array( 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', '0+', '0-' ) as $bt ) : ?>
								<option value="<?php echo esc_attr( $bt ); ?>" <?php selected( sd_val( $member, 'blood_type' ), $bt ); ?>>
									<?php echo esc_html( $bt ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="sd-field-group sd-field-quarter">
						<label class="sd-label"><?php esc_html_e( 'Tipo diabete', 'sd-logbook' ); ?></label>
						<?php
						$dt_labels = array(
							'non_diabetico'  => __( 'Non diabetico', 'sd-logbook' ),
							'tipo_1'         => __( 'Tipo 1', 'sd-logbook' ),
							'tipo_2'         => __( 'Tipo 2', 'sd-logbook' ),
							'tipo_3c'        => __( 'Tipo 3c', 'sd-logbook' ),
							'lada'           => 'LADA',
							'mody'           => 'MODY',
							'midd'           => 'MIDD',
							'altro'          => __( 'Altro', 'sd-logbook' ),
							'non_specificato' => __( 'Non specificato', 'sd-logbook' ),
						);
						$dt_val = sd_val( $member, 'dp_diabetes_type', sd_val( $member, 'diabetes_type', 'non_diabetico' ) );
						echo '<p class="sd-readonly-value">' . esc_html( $dt_labels[ $dt_val ] ?? $dt_val ) . '</p>';
						echo '<small class="sd-hint">' . esc_html__( 'Modificabile dalla scheda Anagrafica', 'sd-logbook' ) . '</small>';
						?>
					</div>
				</div>

				<?php
				$allergies_val = sd_val( $member, 'allergies', '[]' );
				$allergies_arr = json_decode( $allergies_val, true ) ?: array();
				$medications_val = sd_val( $member, 'medications', '[]' );
				$medications_arr = json_decode( $medications_val, true ) ?: array();
				?>

				<div class="sd-field-group sd-field-full">
					<label class="sd-label"><?php esc_html_e( 'Allergie', 'sd-logbook' ); ?></label>
					<?php if ( ! empty( $allergies_arr ) ) : ?>
						<div class="sd-readonly-list">
							<?php foreach ( $allergies_arr as $a ) : ?>
								<span class="sd-tag"><?php echo esc_html( $a ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p class="sd-field-note"><?php esc_html_e( 'Nessuna allergia registrata.', 'sd-logbook' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="sd-field-group sd-field-full" style="margin-top:1rem;">
					<label class="sd-label"><?php esc_html_e( 'Medicamenti', 'sd-logbook' ); ?></label>
					<?php if ( ! empty( $medications_arr ) ) : ?>
						<table class="sd-medications-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Farmaco', 'sd-logbook' ); ?></th>
									<th><?php esc_html_e( 'Dosaggio', 'sd-logbook' ); ?></th>
									<th><?php esc_html_e( 'Unità', 'sd-logbook' ); ?></th>
									<th><?php esc_html_e( 'Sospeso', 'sd-logbook' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $medications_arr as $med ) : ?>
									<tr <?php if ( ! empty( $med['suspended'] ) ) : ?>class="sd-suspended"<?php endif; ?>>
										<td><?php echo esc_html( $med['name'] ?? '' ); ?></td>
										<td><?php echo esc_html( $med['dosage'] ?? '' ); ?></td>
										<td><?php echo esc_html( $med['unit'] ?? '' ); ?></td>
										<td><?php echo ! empty( $med['suspended'] ) ? '✓' : ''; ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="sd-field-note"><?php esc_html_e( 'Nessun medicamento registrato.', 'sd-logbook' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="sd-field-group sd-field-full" style="margin-top:1rem;">
					<label class="sd-label"><?php esc_html_e( 'Centro diabetologico', 'sd-logbook' ); ?></label>
					<input type="text" name="diabetology_center" class="sd-input" value="<?php echo esc_attr( sd_val( $member, 'diabetology_center' ) ); ?>">
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- === TAB FAMIGLIA === -->
		<?php if ( $member->sotto_tutela || ! empty( $family_members ) || ! empty( $companions ) ) : ?>
		<div class="sd-tab-content" id="sd-tab-famiglia">
			<?php if ( $member->sotto_tutela ) : ?>
			<div class="sd-form-section">
				<h3 class="sd-section-title"><?php esc_html_e( 'Genitore / Tutore', 'sd-logbook' ); ?></h3>
				<div class="sd-info-grid">
					<div class="sd-info-row">
						<span class="sd-info-label"><?php esc_html_e( 'Nome:', 'sd-logbook' ); ?></span>
						<span class="sd-info-value"><?php echo esc_html( sd_val( $member, 'guardian_first_name' ) . ' ' . sd_val( $member, 'guardian_last_name' ) ); ?></span>
					</div>
					<div class="sd-info-row">
						<span class="sd-info-label"><?php esc_html_e( 'Ruolo:', 'sd-logbook' ); ?></span>
						<span class="sd-info-value"><?php echo esc_html( sd_val( $member, 'guardian_role' ) ); ?></span>
					</div>
					<div class="sd-info-row">
						<span class="sd-info-label"><?php esc_html_e( 'Email:', 'sd-logbook' ); ?></span>
						<span class="sd-info-value"><a href="mailto:<?php echo esc_attr( sd_val( $member, 'guardian_email' ) ); ?>"><?php echo esc_html( sd_val( $member, 'guardian_email' ) ); ?></a></span>
					</div>
					<div class="sd-info-row">
						<span class="sd-info-label"><?php esc_html_e( 'Telefono:', 'sd-logbook' ); ?></span>
						<span class="sd-info-value"><?php echo esc_html( sd_val( $member, 'guardian_phone' ) ); ?></span>
					</div>
					<div class="sd-info-row">
						<span class="sd-info-label"><?php esc_html_e( 'Indirizzo:', 'sd-logbook' ); ?></span>
						<span class="sd-info-value">
							<?php echo esc_html( sd_val( $member, 'guardian_address' ) . ' ' . sd_val( $member, 'guardian_postal' ) . ' ' . sd_val( $member, 'guardian_city' ) ); ?>
						</span>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $family_members ) ) : ?>
			<div class="sd-form-section">
				<h3 class="sd-section-title"><?php esc_html_e( 'Familiari', 'sd-logbook' ); ?></h3>
				<table class="sd-members-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nome', 'sd-logbook' ); ?></th>
							<th><?php esc_html_e( 'Data Nascita', 'sd-logbook' ); ?></th>
							<th><?php esc_html_e( 'Telefono', 'sd-logbook' ); ?></th>
							<th><?php esc_html_e( 'Email', 'sd-logbook' ); ?></th>
							<th><?php esc_html_e( 'Diabete', 'sd-logbook' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $family_members as $fm ) : ?>
							<tr>
								<td><?php echo esc_html( $fm->first_name . ' ' . $fm->last_name ); ?></td>
								<td><?php echo esc_html( $fm->date_of_birth ?? '—' ); ?></td>
								<td><?php echo esc_html( $fm->phone ?? '—' ); ?></td>
								<td><?php echo esc_html( $fm->email ?? '—' ); ?></td>
								<td><?php echo esc_html( $fm->diabetes_type ?? '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $companions ) ) : ?>
			<div class="sd-form-section">
				<h3 class="sd-section-title"><?php esc_html_e( 'Accompagnatori autorizzati', 'sd-logbook' ); ?></h3>
				<table class="sd-members-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nome', 'sd-logbook' ); ?></th>
							<th><?php esc_html_e( 'Ruolo', 'sd-logbook' ); ?></th>
							<th><?php esc_html_e( 'Telefono', 'sd-logbook' ); ?></th>
							<th><?php esc_html_e( 'Email', 'sd-logbook' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $companions as $comp ) : ?>
							<tr>
								<td><?php echo esc_html( $comp->first_name . ' ' . $comp->last_name ); ?></td>
								<td><?php echo esc_html( $comp->companion_role ?? '—' ); ?></td>
								<td><?php echo esc_html( $comp->phone ?? '—' ); ?></td>
								<td><?php echo esc_html( $comp->email ?? '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<!-- === TAB FAMIGLIARI (utenti WP registrati come famigliari) === -->
		<div class="sd-tab-content" id="sd-tab-famigliari">
			<div class="sd-form-section">
				<h3 class="sd-section-title"><?php esc_html_e( 'Famigliari registrati', 'sd-logbook' ); ?></h3>

				<?php if ( ! empty( $registered_family_members ) ) : ?>
					<?php
					// Cerca la pagina di modifica per costruire i link
					$edit_page     = get_page_by_path( 'modifica-socio' );
					$edit_base_url = $edit_page ? get_permalink( $edit_page ) : home_url( '/modifica-socio/' );
					?>
					<table class="sd-members-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Nome', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Email', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Data Nascita', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Diabete', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Socio attivo', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Azioni', 'sd-logbook' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $registered_family_members as $rfm ) : ?>
								<tr>
									<td><?php echo esc_html( $rfm->first_name . ' ' . $rfm->last_name ); ?></td>
									<td><?php echo esc_html( $rfm->email ?? '—' ); ?></td>
									<td><?php echo esc_html( $rfm->date_of_birth ? date_i18n( 'd/m/Y', strtotime( $rfm->date_of_birth ) ) : '—' ); ?></td>
									<td><?php echo esc_html( $rfm->diabetes_type ?? '—' ); ?></td>
									<td>
										<?php if ( (int) $rfm->is_active !== 0 ) : ?>
											<span class="sd-badge sd-badge-success" style="background:#d4edda;color:#155724;padding:2px 8px;border-radius:12px;font-size:0.8rem;"><?php esc_html_e( 'Sì', 'sd-logbook' ); ?></span>
										<?php else : ?>
											<span class="sd-badge sd-badge-danger" style="background:#f8d7da;color:#721c24;padding:2px 8px;border-radius:12px;font-size:0.8rem;"><?php esc_html_e( 'No', 'sd-logbook' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<a href="<?php echo esc_url( add_query_arg( 'member_id', $rfm->id, $edit_base_url ) ); ?>" class="sd-btn sd-btn-secondary sd-btn-sm">
											<?php esc_html_e( 'Modifica', 'sd-logbook' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="sd-field-note"><?php esc_html_e( 'Nessun famigliare registrato per questo intestatario.', 'sd-logbook' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<!-- === TAB PAGAMENTI === -->
		<div class="sd-tab-content" id="sd-tab-pagamenti">
			<div class="sd-form-section">
				<h3 class="sd-section-title"><?php esc_html_e( 'Storico pagamenti', 'sd-logbook' ); ?></h3>

				<?php if ( ! empty( $payments ) ) : ?>
					<table class="sd-members-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Anno', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Importo', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Stato', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Metodo', 'sd-logbook' ); ?></th>
								<th><?php esc_html_e( 'Data', 'sd-logbook' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $payments as $pay ) : ?>
								<tr>
									<td><?php echo esc_html( $pay->payment_year ); ?></td>
									<td>CHF <?php echo esc_html( number_format( (float) $pay->amount, 2 ) ); ?></td>
									<td>
										<span class="sd-status-badge sd-status-<?php echo esc_attr( $pay->status ); ?>">
											<?php echo esc_html( $pay->status ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $pay->payment_method ?? '—' ); ?></td>
									<td><?php echo esc_html( $pay->payment_date ? substr( $pay->payment_date, 0, 10 ) : '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="sd-field-note"><?php esc_html_e( 'Nessun pagamento registrato.', 'sd-logbook' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<!-- === TAB LOG === -->
		<div class="sd-tab-content" id="sd-tab-log">
			<div class="sd-form-section">
				<h3 class="sd-section-title"><?php esc_html_e( 'Log modifiche (ultimi 20)', 'sd-logbook' ); ?></h3>

				<?php if ( ! empty( $audit_log ) ) : ?>
					<div class="sd-audit-log">
						<?php foreach ( $audit_log as $entry ) : ?>
							<div class="sd-audit-entry">
								<span class="sd-audit-date"><?php echo esc_html( $entry->created_at ); ?></span>
								<span class="sd-audit-action sd-audit-<?php echo esc_attr( $entry->action ); ?>"><?php echo esc_html( $entry->action ); ?></span>
								<span class="sd-audit-table"><?php echo esc_html( $entry->table_name ?? '' ); ?></span>
								<?php if ( $entry->ip_address ) : ?>
									<span class="sd-audit-ip"><?php echo esc_html( $entry->ip_address ); ?></span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="sd-field-note"><?php esc_html_e( 'Nessuna modifica registrata.', 'sd-logbook' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Pulsanti azione -->
		<div class="sd-form-footer sd-edit-footer">
			<button type="submit" id="sd-edit-save" class="sd-btn sd-btn-primary">
				<?php esc_html_e( 'Salva modifiche', 'sd-logbook' ); ?>
			</button>
			<button type="button" id="sd-edit-cancel" class="sd-btn sd-btn-secondary" onclick="history.back()">
				<?php esc_html_e( 'Annulla', 'sd-logbook' ); ?>
			</button>
			<?php if ( $is_admin ) : ?>
				<button type="button" id="sd-edit-delete" class="sd-btn sd-btn-danger" data-member-id="<?php echo esc_attr( $member->id ); ?>">
					<?php esc_html_e( 'Elimina socio', 'sd-logbook' ); ?>
				</button>
			<?php endif; ?>
		</div>

	</form>
</div>
