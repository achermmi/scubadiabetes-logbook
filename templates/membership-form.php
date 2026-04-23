<?php
/**
 * Template: Modulo iscrizione soci
 *
 * Variabili disponibili:
 * @var array  $countries     Lista paesi
 * @var array  $swiss_cantons Lista cantoni CH
 * @var string $current_year  Anno corrente
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="sd-form-wrap sd-membership-wrap" id="sd-registration-page">

	<div class="sd-form-header">
		<h2 class="sd-form-title">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: current year */
					__( 'Modulo di iscrizione ScubaDiabetes %s', 'sd-logbook' ),
					$current_year
				)
			);
			?>
		</h2>
		<p class="sd-form-subtitle"><?php esc_html_e( 'Compila il modulo per iscriverti all\'Associazione ScubaDiabetes.', 'sd-logbook' ); ?></p>
	</div>

	<div id="sd-reg-message" class="sd-notice" style="display:none;"></div>

	<form id="sd-registration-form" novalidate>
		<?php wp_nonce_field( 'sd_membership_nonce', 'nonce' ); ?>

		<!-- ===== SEZIONE 1: DATI ANAGRAFICI ===== -->
		<div class="sd-form-section">
			<h3 class="sd-section-title"><?php esc_html_e( '1. Dati anagrafici', 'sd-logbook' ); ?></h3>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-half">
					<label for="first_name" class="sd-label sd-label-required"><?php esc_html_e( 'Nome', 'sd-logbook' ); ?></label>
					<input type="text" id="first_name" name="first_name" class="sd-input" required autocomplete="given-name">
				</div>
				<div class="sd-field-group sd-field-half">
					<label for="last_name" class="sd-label sd-label-required"><?php esc_html_e( 'Cognome', 'sd-logbook' ); ?></label>
					<input type="text" id="last_name" name="last_name" class="sd-input" required autocomplete="family-name">
				</div>
			</div>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-third">
					<label for="date_of_birth" class="sd-label sd-label-required"><?php esc_html_e( 'Data di nascita', 'sd-logbook' ); ?></label>
					<input type="date" id="date_of_birth" name="date_of_birth" class="sd-input" required max="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>">
					<div id="sd-age-display" class="sd-field-note" style="display:none;"></div>
				</div>
				<div class="sd-field-group sd-field-third">
					<label for="gender" class="sd-label sd-label-required"><?php esc_html_e( 'Genere', 'sd-logbook' ); ?></label>
					<select id="gender" name="gender" class="sd-select" required>
						<option value=""><?php esc_html_e( '-- Seleziona --', 'sd-logbook' ); ?></option>
						<option value="M"><?php esc_html_e( 'Maschile (M)', 'sd-logbook' ); ?></option>
						<option value="F"><?php esc_html_e( 'Femminile (F)', 'sd-logbook' ); ?></option>
						<option value="NB"><?php esc_html_e( 'Non binario (NB)', 'sd-logbook' ); ?></option>
						<option value="U"><?php esc_html_e( 'Preferisco non indicare (U)', 'sd-logbook' ); ?></option>
					</select>
				</div>
				<div class="sd-field-group sd-field-third">
					<label class="sd-label sd-label-required"><?php esc_html_e( 'Sotto tutela legale', 'sd-logbook' ); ?></label>
					<div class="sd-radio-group">
						<label class="sd-radio-label">
							<input type="radio" name="sotto_tutela" value="0" checked> <?php esc_html_e( 'No', 'sd-logbook' ); ?>
						</label>
						<label class="sd-radio-label">
							<input type="radio" name="sotto_tutela" value="1"> <?php esc_html_e( 'Sì', 'sd-logbook' ); ?>
						</label>
					</div>
				</div>
			</div>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-half">
					<label for="birth_place" class="sd-label"><?php esc_html_e( 'Luogo di nascita', 'sd-logbook' ); ?></label>
					<input type="text" id="birth_place" name="birth_place" class="sd-input">
				</div>
				<div class="sd-field-group sd-field-half">
					<label for="birth_country" class="sd-label"><?php esc_html_e( 'Nazione di nascita', 'sd-logbook' ); ?></label>
					<select id="birth_country" name="birth_country" class="sd-select">
						<?php foreach ( $countries as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( 'CH', $code ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-half">
					<label for="email" class="sd-label sd-label-required"><?php esc_html_e( 'Email', 'sd-logbook' ); ?></label>
					<input type="email" id="email" name="email" class="sd-input" required autocomplete="email">
					<div class="sd-field-note"><?php esc_html_e( 'Sarà usata come username per accedere al portale', 'sd-logbook' ); ?></div>
				</div>
				<div class="sd-field-group sd-field-half">
					<label for="email_confirm" class="sd-label sd-label-required"><?php esc_html_e( 'Conferma Email', 'sd-logbook' ); ?></label>
					<input type="email" id="email_confirm" name="email_confirm" class="sd-input" required autocomplete="off">
					<div class="sd-field-note"><?php esc_html_e( 'Ripeti l\'indirizzo email per conferma', 'sd-logbook' ); ?></div>
				</div>
			</div>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-half">
					<label for="phone" class="sd-label sd-label-required"><?php esc_html_e( 'Telefono', 'sd-logbook' ); ?></label>
					<input type="tel" id="phone" name="phone" class="sd-input" required placeholder="+41 79 000 00 00" autocomplete="tel">
				</div>
				<div class="sd-field-group sd-field-half">
					<label for="tshirt_size" class="sd-label sd-label-required"><?php esc_html_e( 'Taglia Maglietta', 'sd-logbook' ); ?></label>
					<select id="tshirt_size" name="tshirt_size" class="sd-select" required>
						<option value=""><?php esc_html_e( '-- Seleziona taglia --', 'sd-logbook' ); ?></option>
						<optgroup label="<?php esc_attr_e( 'Bambino/a', 'sd-logbook' ); ?>">
							<option value="baby_2-3"><?php esc_html_e( '2–3 anni', 'sd-logbook' ); ?></option>
							<option value="baby_4-5"><?php esc_html_e( '4–5 anni', 'sd-logbook' ); ?></option>
							<option value="baby_6-8"><?php esc_html_e( '6–8 anni', 'sd-logbook' ); ?></option>
							<option value="baby_9-11"><?php esc_html_e( '9–11 anni', 'sd-logbook' ); ?></option>
							<option value="baby_12-14"><?php esc_html_e( '12–14 anni', 'sd-logbook' ); ?></option>
						</optgroup>
						<optgroup label="<?php esc_attr_e( 'Adulto/a', 'sd-logbook' ); ?>">
							<option value="XXS">XXS</option>
							<option value="XS">XS</option>
							<option value="S">S</option>
							<option value="M">M</option>
							<option value="L">L</option>
							<option value="XL">XL</option>
							<option value="XXL">XXL</option>
							<option value="XXXL">XXXL</option>
						</optgroup>
					</select>
				</div>
			</div>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-half">
					<label for="diabetes_type" class="sd-label sd-label-required"><?php esc_html_e( 'Diabete', 'sd-logbook' ); ?></label>
					<select id="diabetes_type" name="diabetes_type" class="sd-select" required>
						<option value=""><?php esc_html_e( '-- Seleziona --', 'sd-logbook' ); ?></option>
						<option value="non_diabetico"><?php esc_html_e( 'Non diabetico', 'sd-logbook' ); ?></option>
						<option value="tipo_1"><?php esc_html_e( 'Diabete Tipo 1', 'sd-logbook' ); ?></option>
						<option value="tipo_2"><?php esc_html_e( 'Diabete Tipo 2', 'sd-logbook' ); ?></option>
						<option value="non_specificato"><?php esc_html_e( 'Non specificato', 'sd-logbook' ); ?></option>
						<option value="altro"><?php esc_html_e( 'Altro', 'sd-logbook' ); ?></option>
					</select>
				</div>
				<div class="sd-field-group sd-field-half" id="sd-diabetology-section" style="display:none;">
					<label for="diabetology_center" class="sd-label"><?php esc_html_e( 'Centro diabetologico di riferimento', 'sd-logbook' ); ?></label>
					<input type="text" id="diabetology_center" name="diabetology_center" class="sd-input" placeholder="<?php esc_attr_e( 'es. Ospedale Regionale Lugano', 'sd-logbook' ); ?>">
				</div>
			</div>

			<h4 class="sd-subsection-title"><?php esc_html_e( 'Indirizzo', 'sd-logbook' ); ?></h4>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-full">
					<label for="address_street" class="sd-label sd-label-required"><?php esc_html_e( 'Via / Indirizzo', 'sd-logbook' ); ?></label>
					<input type="text" id="address_street" name="address_street" class="sd-input" required autocomplete="street-address">
				</div>
			</div>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-quarter">
					<label for="address_postal" class="sd-label sd-label-required"><?php esc_html_e( 'CAP', 'sd-logbook' ); ?></label>
					<input type="text" id="address_postal" name="address_postal" class="sd-input" required autocomplete="postal-code">
				</div>
				<div class="sd-field-group sd-field-half">
					<label for="address_city" class="sd-label sd-label-required"><?php esc_html_e( 'Località', 'sd-logbook' ); ?></label>
					<input type="text" id="address_city" name="address_city" class="sd-input" required autocomplete="address-level2">
				</div>
				<div class="sd-field-group sd-field-quarter">
					<label for="address_country" class="sd-label sd-label-required"><?php esc_html_e( 'Nazione', 'sd-logbook' ); ?></label>
					<select id="address_country" name="address_country" class="sd-select" required autocomplete="country">
						<?php foreach ( $countries as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( 'CH', $code ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="sd-field-row" id="sd-canton-row">
				<div class="sd-field-group sd-field-half">
					<label for="address_canton" class="sd-label"><?php esc_html_e( 'Cantone', 'sd-logbook' ); ?></label>
					<select id="address_canton" name="address_canton" class="sd-select">
						<option value=""><?php esc_html_e( '-- Seleziona cantone --', 'sd-logbook' ); ?></option>
						<?php foreach ( $swiss_cantons as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="sd-field-group sd-field-half">
					<label for="fiscal_code" class="sd-label"><?php esc_html_e( 'Codice fiscale / AVS', 'sd-logbook' ); ?></label>
					<input type="text" id="fiscal_code" name="fiscal_code" class="sd-input" placeholder="756.xxxx.xxxx.xx">
				</div>
			</div>
		</div>

		<!-- ===== SEZIONE 2: TUTORE (mostrata se minorenne o sotto tutela) ===== -->
		<div class="sd-form-section sd-minor-section" id="sd-guardian-section" style="display:none;">
			<h3 class="sd-section-title sd-section-warning">
				<?php esc_html_e( '2. Genitore / Tutore legale', 'sd-logbook' ); ?>
				<span class="sd-badge sd-badge-warning"><?php esc_html_e( 'Obbligatorio per minorenni', 'sd-logbook' ); ?></span>
			</h3>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-third">
					<label for="guardian_first_name" class="sd-label sd-label-required"><?php esc_html_e( 'Nome', 'sd-logbook' ); ?></label>
					<input type="text" id="guardian_first_name" name="guardian_first_name" class="sd-input sd-guardian-required">
				</div>
				<div class="sd-field-group sd-field-third">
					<label for="guardian_last_name" class="sd-label sd-label-required"><?php esc_html_e( 'Cognome', 'sd-logbook' ); ?></label>
					<input type="text" id="guardian_last_name" name="guardian_last_name" class="sd-input sd-guardian-required">
				</div>
				<div class="sd-field-group sd-field-third">
					<label for="guardian_role" class="sd-label sd-label-required"><?php esc_html_e( 'Ruolo', 'sd-logbook' ); ?></label>
					<select id="guardian_role" name="guardian_role" class="sd-select sd-guardian-required">
						<option value=""><?php esc_html_e( '-- Seleziona --', 'sd-logbook' ); ?></option>
						<option value="Genitore"><?php esc_html_e( 'Genitore', 'sd-logbook' ); ?></option>
						<option value="Tutore"><?php esc_html_e( 'Tutore legale', 'sd-logbook' ); ?></option>
					</select>
				</div>
			</div>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-quarter">
					<label for="guardian_dob" class="sd-label"><?php esc_html_e( 'Data di nascita', 'sd-logbook' ); ?></label>
					<input type="date" id="guardian_dob" name="guardian_dob" class="sd-input">
				</div>
				<div class="sd-field-group sd-field-quarter">
					<label for="guardian_gender" class="sd-label"><?php esc_html_e( 'Genere', 'sd-logbook' ); ?></label>
					<select id="guardian_gender" name="guardian_gender" class="sd-select">
						<option value="M"><?php esc_html_e( 'M', 'sd-logbook' ); ?></option>
						<option value="F"><?php esc_html_e( 'F', 'sd-logbook' ); ?></option>
						<option value="NB"><?php esc_html_e( 'NB', 'sd-logbook' ); ?></option>
						<option value="U"><?php esc_html_e( 'U', 'sd-logbook' ); ?></option>
					</select>
				</div>
				<div class="sd-field-group sd-field-half">
					<label for="guardian_birth_place" class="sd-label"><?php esc_html_e( 'Luogo di nascita', 'sd-logbook' ); ?></label>
					<input type="text" id="guardian_birth_place" name="guardian_birth_place" class="sd-input">
				</div>
			</div>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-half">
					<label for="guardian_email" class="sd-label sd-label-required"><?php esc_html_e( 'Email', 'sd-logbook' ); ?></label>
					<input type="email" id="guardian_email" name="guardian_email" class="sd-input sd-guardian-required">
				</div>
				<div class="sd-field-group sd-field-half">
					<label for="guardian_phone" class="sd-label sd-label-required"><?php esc_html_e( 'Telefono', 'sd-logbook' ); ?></label>
					<input type="tel" id="guardian_phone" name="guardian_phone" class="sd-input sd-guardian-required" placeholder="+41 79 000 00 00">
				</div>
			</div>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-full">
					<label for="guardian_address" class="sd-label"><?php esc_html_e( 'Indirizzo', 'sd-logbook' ); ?></label>
					<input type="text" id="guardian_address" name="guardian_address" class="sd-input">
				</div>
			</div>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-quarter">
					<label for="guardian_postal" class="sd-label"><?php esc_html_e( 'CAP', 'sd-logbook' ); ?></label>
					<input type="text" id="guardian_postal" name="guardian_postal" class="sd-input">
				</div>
				<div class="sd-field-group sd-field-half">
					<label for="guardian_city" class="sd-label"><?php esc_html_e( 'Città', 'sd-logbook' ); ?></label>
					<input type="text" id="guardian_city" name="guardian_city" class="sd-input">
				</div>
				<div class="sd-field-group sd-field-quarter">
					<label for="guardian_country" class="sd-label"><?php esc_html_e( 'Nazione', 'sd-logbook' ); ?></label>
					<select id="guardian_country" name="guardian_country" class="sd-select">
						<?php foreach ( $countries as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( 'CH', $code ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<!-- Accompagnatori autorizzati -->
			<h4 class="sd-subsection-title"><?php esc_html_e( 'Accompagnatori autorizzati', 'sd-logbook' ); ?></h4>
			<p class="sd-field-note"><?php esc_html_e( 'Persone autorizzate ad accompagnare il/la minore alle attività.', 'sd-logbook' ); ?></p>

			<div id="sd-companions-list"></div>

			<button type="button" id="sd-add-companion" class="sd-btn sd-btn-secondary sd-btn-sm">
				+ <?php esc_html_e( 'Aggiungi accompagnatore', 'sd-logbook' ); ?>
			</button>

			<!-- Template companion (nascosto) -->
			<template id="sd-companion-template">
				<div class="sd-companion-row sd-repeatable-row">
					<div class="sd-row-header">
						<strong><?php esc_html_e( 'Accompagnatore', 'sd-logbook' ); ?> <span class="sd-row-num"></span></strong>
						<button type="button" class="sd-remove-row" title="<?php esc_attr_e( 'Rimuovi', 'sd-logbook' ); ?>">✕</button>
					</div>
					<div class="sd-field-row">
						<div class="sd-field-group sd-field-quarter">
							<label class="sd-label sd-label-required"><?php esc_html_e( 'Nome', 'sd-logbook' ); ?></label>
							<input type="text" name="companions[__idx__][first_name]" class="sd-input" required>
						</div>
						<div class="sd-field-group sd-field-quarter">
							<label class="sd-label sd-label-required"><?php esc_html_e( 'Cognome', 'sd-logbook' ); ?></label>
							<input type="text" name="companions[__idx__][last_name]" class="sd-input" required>
						</div>
						<div class="sd-field-group sd-field-quarter">
							<label class="sd-label sd-label-required"><?php esc_html_e( 'Ruolo', 'sd-logbook' ); ?></label>
							<select name="companions[__idx__][companion_role]" class="sd-select" required>
								<option value=""><?php esc_html_e( '-- Seleziona --', 'sd-logbook' ); ?></option>
								<option value="genitore"><?php esc_html_e( 'Genitore', 'sd-logbook' ); ?></option>
								<option value="tutore"><?php esc_html_e( 'Tutore', 'sd-logbook' ); ?></option>
								<option value="educatore"><?php esc_html_e( 'Educatore', 'sd-logbook' ); ?></option>
								<option value="parente"><?php esc_html_e( 'Parente', 'sd-logbook' ); ?></option>
								<option value="medico"><?php esc_html_e( 'Personale medico', 'sd-logbook' ); ?></option>
								<option value="amico"><?php esc_html_e( 'Amico/a', 'sd-logbook' ); ?></option>
							</select>
						</div>
						<div class="sd-field-group sd-field-quarter">
							<label class="sd-label"><?php esc_html_e( 'Data nascita', 'sd-logbook' ); ?></label>
							<input type="date" name="companions[__idx__][date_of_birth]" class="sd-input">
						</div>
					</div>
					<div class="sd-field-row">
						<div class="sd-field-group sd-field-half">
							<label class="sd-label sd-label-required"><?php esc_html_e( 'Telefono', 'sd-logbook' ); ?></label>
							<input type="tel" name="companions[__idx__][phone]" class="sd-input" required>
						</div>
						<div class="sd-field-group sd-field-half">
							<label class="sd-label"><?php esc_html_e( 'Email', 'sd-logbook' ); ?></label>
							<input type="email" name="companions[__idx__][email]" class="sd-input">
						</div>
					</div>
				</div>
			</template>
		</div>

		<!-- ===== SEZIONE 3: ISCRIZIONE ===== -->
		<div class="sd-form-section">
			<h3 class="sd-section-title"><?php esc_html_e( '3. Iscrizione', 'sd-logbook' ); ?></h3>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-half">
					<label for="member_type" class="sd-label sd-label-required"><?php esc_html_e( 'Tipo di socio', 'sd-logbook' ); ?></label>
					<select id="member_type" name="member_type" class="sd-select" required>
						<option value="attivo"><?php esc_html_e( 'Socio Attivo', 'sd-logbook' ); ?></option>
						<option value="accompagnatore"><?php esc_html_e( 'Accompagnatore', 'sd-logbook' ); ?></option>
						<option value="sostenitore"><?php esc_html_e( 'Sostenitore', 'sd-logbook' ); ?></option>
						<option value="attivo_capo_famiglia"><?php esc_html_e( 'Attivo Capo Famiglia', 'sd-logbook' ); ?></option>
					</select>
					<div id="sd-member-type-locked-note" class="sd-field-note" style="display:none;">
						<?php esc_html_e( 'Il tipo è impostato automaticamente per l\'iscrizione famiglia.', 'sd-logbook' ); ?>
					</div>
				</div>
				<div class="sd-field-group sd-field-half">
					<label class="sd-label">&nbsp;</label>
					<label class="sd-checkbox-label sd-checkbox-large">
						<input type="checkbox" id="is_scuba" name="is_scuba" value="1">
						<span><?php esc_html_e( 'Sono un subacqueo / pratico immersioni', 'sd-logbook' ); ?></span>
					</label>
				</div>
			</div>

			<!-- Selezione tassa -->
			<h4 class="sd-subsection-title sd-label-required"><?php esc_html_e( 'Tassa associativa', esc_html( $current_year ) ); ?></h4>
			<div class="sd-fee-cards" id="sd-fee-cards">
				<label class="sd-fee-card">
					<input type="radio" name="fee_amount" value="30" required>
					<div class="sd-fee-card-inner">
						<span class="sd-fee-price">CHF 30</span>
						<span class="sd-fee-label"><?php esc_html_e( 'Ridotta', 'sd-logbook' ); ?></span>
						<span class="sd-fee-desc"><?php esc_html_e( 'Minorenni, over 65, AI', 'sd-logbook' ); ?></span>
					</div>
				</label>
				<label class="sd-fee-card">
					<input type="radio" name="fee_amount" value="50" required>
					<div class="sd-fee-card-inner">
						<span class="sd-fee-price">CHF 50</span>
						<span class="sd-fee-label"><?php esc_html_e( 'Adulti', 'sd-logbook' ); ?></span>
						<span class="sd-fee-desc"><?php esc_html_e( 'Iscrizione individuale', 'sd-logbook' ); ?></span>
					</div>
				</label>
				<label class="sd-fee-card">
					<input type="radio" name="fee_amount" value="75" required>
					<div class="sd-fee-card-inner">
						<span class="sd-fee-price">CHF 75</span>
						<span class="sd-fee-label"><?php esc_html_e( 'Famiglia', 'sd-logbook' ); ?></span>
						<span class="sd-fee-desc"><?php esc_html_e( 'Nucleo familiare', 'sd-logbook' ); ?></span>
					</div>
				</label>
			</div>

			<!-- Familiari (mostrata se 75 CHF) -->
			<div id="sd-family-section" style="display:none;">
				<h4 class="sd-subsection-title"><?php esc_html_e( 'Membri del nucleo familiare', 'sd-logbook' ); ?></h4>
				<p class="sd-field-note"><?php esc_html_e( 'Aggiungi i familiari che usufruiranno dell\'iscrizione famiglia. Verrà creato un account per ogni famigliare.', 'sd-logbook' ); ?></p>

				<div id="sd-family-members-list"></div>

				<button type="button" id="sd-add-family-member" class="sd-btn sd-btn-secondary sd-btn-sm">
					+ <?php esc_html_e( 'Aggiungi familiare', 'sd-logbook' ); ?>
				</button>

				<!-- Template familiare (nascosto) -->
				<template id="sd-family-member-template">
					<div class="sd-family-member-row sd-repeatable-row">
						<div class="sd-row-header">
							<strong><?php esc_html_e( 'Familiare', 'sd-logbook' ); ?> <span class="sd-row-num"></span></strong>
							<button type="button" class="sd-remove-row" title="<?php esc_attr_e( 'Rimuovi', 'sd-logbook' ); ?>">✕</button>
						</div>
						<div class="sd-field-row">
							<div class="sd-field-group sd-field-quarter">
								<label class="sd-label sd-label-required"><?php esc_html_e( 'Nome', 'sd-logbook' ); ?></label>
								<input type="text" name="family_members[__idx__][first_name]" class="sd-input sd-fam-required" required>
							</div>
							<div class="sd-field-group sd-field-quarter">
								<label class="sd-label sd-label-required"><?php esc_html_e( 'Cognome', 'sd-logbook' ); ?></label>
								<input type="text" name="family_members[__idx__][last_name]" class="sd-input sd-fam-required" required>
							</div>
							<div class="sd-field-group sd-field-quarter">
								<label class="sd-label sd-label-required"><?php esc_html_e( 'Data di nascita', 'sd-logbook' ); ?></label>
								<input type="date" name="family_members[__idx__][date_of_birth]" class="sd-input sd-fam-required" required>
							</div>
							<div class="sd-field-group sd-field-quarter">
								<label class="sd-label"><?php esc_html_e( 'Genere', 'sd-logbook' ); ?></label>
								<select name="family_members[__idx__][gender]" class="sd-select">
									<option value=""><?php esc_html_e( '-- Seleziona --', 'sd-logbook' ); ?></option>
									<option value="M"><?php esc_html_e( 'Maschile (M)', 'sd-logbook' ); ?></option>
									<option value="F"><?php esc_html_e( 'Femminile (F)', 'sd-logbook' ); ?></option>
									<option value="NB"><?php esc_html_e( 'Non binario (NB)', 'sd-logbook' ); ?></option>
									<option value="U"><?php esc_html_e( 'Non indicato (U)', 'sd-logbook' ); ?></option>
								</select>
							</div>
						</div>
						<div class="sd-field-row">
							<div class="sd-field-group sd-field-quarter">
								<label class="sd-label"><?php esc_html_e( 'Diabete', 'sd-logbook' ); ?></label>
								<select name="family_members[__idx__][diabetes_type]" class="sd-select sd-fam-diabetes-type">
									<option value="non_diabetico"><?php esc_html_e( 'Non diabetico', 'sd-logbook' ); ?></option>
									<option value="tipo_1"><?php esc_html_e( 'Tipo 1', 'sd-logbook' ); ?></option>
									<option value="tipo_2"><?php esc_html_e( 'Tipo 2', 'sd-logbook' ); ?></option>
									<option value="non_specificato"><?php esc_html_e( 'Non specificato', 'sd-logbook' ); ?></option>
									<option value="altro"><?php esc_html_e( 'Altro', 'sd-logbook' ); ?></option>
								</select>
							</div>
							<div class="sd-field-group sd-field-half sd-fam-diabetology-section" style="display:none;">
								<label class="sd-label"><?php esc_html_e( 'Centro diabetologico', 'sd-logbook' ); ?></label>
								<input type="text" name="family_members[__idx__][diabetology_center]" class="sd-input" placeholder="<?php esc_attr_e( 'es. Ospedale Regionale Lugano', 'sd-logbook' ); ?>">
							</div>
							<div class="sd-field-group sd-field-quarter" style="justify-content:center;">
								<label class="sd-checkbox-label" style="margin-bottom:0;">
									<input type="checkbox" name="family_members[__idx__][is_scuba]" value="1" class="sd-fam-scuba-check">
									<span><?php esc_html_e( 'Subacqueo', 'sd-logbook' ); ?></span>
								</label>
							</div>
						</div>
						<div class="sd-field-row">
							<div class="sd-field-group sd-field-half">
								<label class="sd-label sd-label-required"><?php esc_html_e( 'Telefono', 'sd-logbook' ); ?></label>
								<input type="tel" name="family_members[__idx__][phone]" class="sd-input sd-fam-required" placeholder="+41 79 000 00 00" required>
							</div>
							<div class="sd-field-group sd-field-half">
							<label class="sd-label"><?php esc_html_e( 'Taglia Maglietta', 'sd-logbook' ); ?></label>
							<select name="family_members[__idx__][tshirt_size]" class="sd-select">
								<option value=""><?php esc_html_e( '-- Seleziona taglia --', 'sd-logbook' ); ?></option>
								<optgroup label="<?php esc_attr_e( 'Bambino/a', 'sd-logbook' ); ?>">
									<option value="baby_2-3"><?php esc_html_e( '2–3 anni', 'sd-logbook' ); ?></option>
									<option value="baby_4-5"><?php esc_html_e( '4–5 anni', 'sd-logbook' ); ?></option>
									<option value="baby_6-8"><?php esc_html_e( '6–8 anni', 'sd-logbook' ); ?></option>
									<option value="baby_9-11"><?php esc_html_e( '9–11 anni', 'sd-logbook' ); ?></option>
									<option value="baby_12-14"><?php esc_html_e( '12–14 anni', 'sd-logbook' ); ?></option>
								</optgroup>
								<optgroup label="<?php esc_attr_e( 'Adulto/a', 'sd-logbook' ); ?>">
									<option value="XXS">XXS</option>
									<option value="XS">XS</option>
									<option value="S">S</option>
									<option value="M">M</option>
									<option value="L">L</option>
									<option value="XL">XL</option>
									<option value="XXL">XXL</option>
									<option value="XXXL">XXXL</option>
								</optgroup>
							</select>
						</div>
					</div>
					<div class="sd-field-row">
						<div class="sd-field-group sd-field-half">
							<label class="sd-label sd-label-required"><?php esc_html_e( 'Email', 'sd-logbook' ); ?></label>
							<input type="email" name="family_members[__idx__][email]" class="sd-input sd-fam-required sd-fam-email" required>
						</div>
						<div class="sd-field-group sd-field-half">
							<label class="sd-label sd-label-required"><?php esc_html_e( 'Conferma Email', 'sd-logbook' ); ?></label>
							<input type="email" name="family_members[__idx__][email_confirm]" class="sd-input sd-fam-required sd-fam-email-confirm" required>
						</div>
					</div>
					<div class="sd-field-row">
						<div class="sd-field-group sd-field-full">
							<div class="sd-consent-block">
								<label class="sd-checkbox-label">
									<input type="checkbox" name="family_members[__idx__][default_shared_for_research]" value="1" checked>
									<span><?php esc_html_e( 'Acconsento alla condivisione anonima dei miei dati per la ricerca scientifica sul diabete e le immersioni subacquee (protocollo Diabete Sommerso). I dati saranno trattati in forma anonima.', 'sd-logbook' ); ?></span>
								</label>
							</div>
							</div>
						</div>
					</div>
				</template>
			</div>
		</div>

		<!-- ===== SEZIONE 4: DATI SUBACQUEO (mostrata se is_scuba = true) ===== -->
		<div class="sd-form-section" id="sd-scuba-section" style="display:none;">
			<h3 class="sd-section-title"><?php esc_html_e( '4. Dati subacqueo', 'sd-logbook' ); ?></h3>

			<div class="sd-field-row">
				<div class="sd-field-group sd-field-quarter">
					<label for="weight" class="sd-label"><?php esc_html_e( 'Peso (kg)', 'sd-logbook' ); ?></label>
					<input type="number" id="weight" name="weight" class="sd-input" min="20" max="300" step="0.1" placeholder="70.0">
				</div>
				<div class="sd-field-group sd-field-quarter">
					<label for="height" class="sd-label"><?php esc_html_e( 'Altezza (cm)', 'sd-logbook' ); ?></label>
					<input type="number" id="height" name="height" class="sd-input" min="100" max="250" placeholder="170">
				</div>
				<div class="sd-field-group sd-field-half">
					<label for="blood_type" class="sd-label"><?php esc_html_e( 'Gruppo sanguigno', 'sd-logbook' ); ?></label>
					<select id="blood_type" name="blood_type" class="sd-select">
						<option value=""><?php esc_html_e( '-- Non specificato --', 'sd-logbook' ); ?></option>
						<?php foreach ( array( 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', '0+', '0-' ) as $bt ) : ?>
							<option value="<?php echo esc_attr( $bt ); ?>"><?php echo esc_html( $bt ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<!-- Allergie -->
			<div class="sd-field-group sd-field-full">
				<label class="sd-label"><?php esc_html_e( 'Allergie', 'sd-logbook' ); ?></label>
				<div class="sd-tag-input-wrap">
					<div id="sd-allergies-list" class="sd-tag-list"></div>
					<input type="text" id="sd-allergy-input" class="sd-input sd-tag-input" placeholder="<?php esc_attr_e( 'Scrivi un\'allergia e premi Invio', 'sd-logbook' ); ?>">
				</div>
				<input type="hidden" id="allergies" name="allergies" value="[]">
			</div>

			<!-- Medicamenti -->
			<div class="sd-field-group sd-field-full" style="margin-top:1rem;">
				<label class="sd-label"><?php esc_html_e( 'Medicamenti / Terapie', 'sd-logbook' ); ?></label>
				<div id="sd-medications-list"></div>
				<button type="button" id="sd-add-medication" class="sd-btn sd-btn-secondary sd-btn-sm">
					+ <?php esc_html_e( 'Aggiungi medicamento', 'sd-logbook' ); ?>
				</button>
				<input type="hidden" id="medications" name="medications" value="[]">

				<template id="sd-medication-template">
					<div class="sd-medication-row sd-repeatable-row">
						<div class="sd-row-header">
							<strong><?php esc_html_e( 'Medicamento', 'sd-logbook' ); ?> <span class="sd-row-num"></span></strong>
							<button type="button" class="sd-remove-row">✕</button>
						</div>
						<div class="sd-field-row">
							<div class="sd-field-group sd-field-third">
								<label class="sd-label"><?php esc_html_e( 'Nome farmaco', 'sd-logbook' ); ?></label>
								<input type="text" class="sd-input sd-med-name" placeholder="<?php esc_attr_e( 'es. Metformina', 'sd-logbook' ); ?>">
							</div>
							<div class="sd-field-group sd-field-sixth">
								<label class="sd-label"><?php esc_html_e( 'Dosaggio', 'sd-logbook' ); ?></label>
								<input type="text" class="sd-input sd-med-dosage" placeholder="es. 500">
							</div>
							<div class="sd-field-group sd-field-sixth">
								<label class="sd-label"><?php esc_html_e( 'Unità', 'sd-logbook' ); ?></label>
								<select class="sd-select sd-med-unit">
									<option value="mg">mg</option>
									<option value="ml">ml</option>
									<option value="UI">UI</option>
									<option value="%">%</option>
									<option value="altro"><?php esc_html_e( 'altro', 'sd-logbook' ); ?></option>
								</select>
							</div>
							<div class="sd-field-group sd-field-quarter">
								<label class="sd-label">&nbsp;</label>
								<label class="sd-checkbox-label">
									<input type="checkbox" class="sd-med-suspended">
									<span><?php esc_html_e( 'Sospeso', 'sd-logbook' ); ?></span>
								</label>
							</div>
						</div>
					</div>
				</template>
			</div>

		</div>

		<!-- ===== SEZIONE 5: CONSENSI ===== -->
		<div class="sd-form-section">
			<h3 class="sd-section-title"><?php esc_html_e( '5. Consensi', 'sd-logbook' ); ?></h3>

			<div class="sd-consent-block">
				<label class="sd-checkbox-label">
					<input type="checkbox" id="default_shared_for_research" name="default_shared_for_research" value="1" checked>
					<span>
						<?php esc_html_e( 'Acconsento alla condivisione anonima dei miei dati per la ricerca scientifica sul diabete e le immersioni subacquee (protocollo Diabete Sommerso). I dati saranno trattati in forma anonima.', 'sd-logbook' ); ?>
					</span>
				</label>
			</div>

			<div class="sd-consent-block sd-consent-required">
				<label class="sd-checkbox-label">
					<input type="checkbox" id="privacy_consent" name="privacy_consent" value="1" required>
					<span>
						<?php
						printf(
							/* translators: %s: privacy policy link */
							esc_html__( 'Ho letto e accetto l\'%s dell\'Associazione ScubaDiabetes. *', 'sd-logbook' ),
							'<a href="' . esc_url( home_url( '/privacy/' ) ) . '" target="_blank">' . esc_html__( 'Informativa sulla Privacy', 'sd-logbook' ) . '</a>'
						);
						?>
					</span>
				</label>
			</div>
		</div>

		<!-- ===== PULSANTE INVIO ===== -->
		<div class="sd-form-footer">
			<p class="sd-field-note"><?php esc_html_e( '* Campo obbligatorio', 'sd-logbook' ); ?></p>
			<button type="submit" id="sd-reg-submit" class="sd-btn sd-btn-primary sd-btn-large">
				<?php esc_html_e( 'Invia iscrizione', 'sd-logbook' ); ?>
			</button>
		</div>

	</form>
</div>
