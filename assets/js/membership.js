/**
 * JavaScript per il modulo iscrizione soci
 *
 * ScubaDiabetes Logbook - Membership Registration
 */
(function ($) {
	'use strict';

	// ===== STATO LOCALE =====
	var allergiesList    = [];
	var medicationsList  = [];
	var isMinorState     = false;

	// ===== INIZIALIZZAZIONE =====
	$(document).ready(function () {
		if (!$('#sd-registration-form').length) {
			return;
		}

		bindAgeCheck();
		bindGuardianToggle();
		bindCountryToggle();
		bindFeeCards();
		bindScubaToggle();
		bindDiabetesToggle();
		bindAllergies();
		bindMedications();
		bindCompanions();
		bindFamilyMembers();
		bindFormSubmit();
	});

	// ===== CALCOLO ETÀ E LOGICA MINORI =====
	function bindAgeCheck() {
		$('#date_of_birth').on('change', function () {
			var dob = $(this).val();
			if (!dob) {
				$('#sd-age-display').hide();
				return;
			}

			var birthDate = new Date(dob);
			var today     = new Date();
			var age       = today.getFullYear() - birthDate.getFullYear();
			var m         = today.getMonth() - birthDate.getMonth();
			if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
				age--;
			}

			var ageDisplay = $('#sd-age-display');
			ageDisplay.text(age + ' anni').show();

			var minor = age < 18;
			if (minor !== isMinorState || $('input[name="sotto_tutela"]:checked').val() === '1') {
				isMinorState = minor || $('input[name="sotto_tutela"]:checked').val() === '1';
				toggleGuardianSection(isMinorState);
			}

			// Auto-seleziona tassa 30 CHF per minorenni
			if (minor && !$('input[name="fee_amount"]:checked').val()) {
				$('input[name="fee_amount"][value="30"]').prop('checked', true).trigger('change');
				updateFeeCardStyle();
			}
		});
	}

	function bindGuardianToggle() {
		$('input[name="sotto_tutela"]').on('change', function () {
			var underGuardian = $(this).val() === '1';
			isMinorState = underGuardian || isMinor();
			toggleGuardianSection(isMinorState);
		});
	}

	function isMinor() {
		var dob = $('#date_of_birth').val();
		if (!dob) return false;
		var birthDate = new Date(dob);
		var today     = new Date();
		var age       = today.getFullYear() - birthDate.getFullYear();
		var m         = today.getMonth() - birthDate.getMonth();
		if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
			age--;
		}
		return age < 18;
	}

	function toggleGuardianSection(show) {
		var section = $('#sd-guardian-section');
		if (show) {
			section.slideDown(200);
			section.find('.sd-guardian-required').prop('required', true);
		} else {
			section.slideUp(200);
			section.find('.sd-guardian-required').prop('required', false);
		}
	}

	// ===== CANTONE / PAESE =====
	function bindCountryToggle() {
		$('#address_country').on('change', function () {
			var isCH = $(this).val() === 'CH';
			$('#sd-canton-row').toggle(isCH);
		}).trigger('change');
	}

	// ===== FEE CARDS =====
	function bindFeeCards() {
		$('#sd-fee-cards').on('change', 'input[type="radio"]', function () {
			updateFeeCardStyle();
			var fee = $(this).val();
			if (fee === '75') {
				$('#sd-family-section').slideDown(200);
			} else {
				$('#sd-family-section').slideUp(200);
			}
		});
	}

	function updateFeeCardStyle() {
		$('.sd-fee-card').each(function () {
			var checked = $(this).find('input[type="radio"]').prop('checked');
			$(this).find('.sd-fee-card-inner').toggleClass('sd-card-selected', checked);
		});
	}

	// ===== SEZIONE SUBACQUEO =====
	function bindScubaToggle() {
		$('#is_scuba').on('change', function () {
			if ($(this).prop('checked')) {
				$('#sd-scuba-section').slideDown(200);
			} else {
				$('#sd-scuba-section').slideUp(200);
			}
		});
	}

	// ===== LOGICA DIABETE =====
	function bindDiabetesToggle() {
		// Campo principale
		$('#diabetes_type').on('change', function () {
			var type = $(this).val();
			var isDiabetic = type !== 'non_diabetico' && type !== '';
			if (isDiabetic) {
				$('#sd-diabetology-section').slideDown(150);
			} else {
				$('#sd-diabetology-section').slideUp(150);
			}
		});

		// Familiari: event delegation per righe create dinamicamente
		$(document).on('change', '.sd-fam-diabetes-type', function () {
			var type = $(this).val();
			var isDiabetic = type !== 'non_diabetico' && type !== '';
			var $section = $(this).closest('.sd-field-row').find('.sd-fam-diabetology-section');
			if (isDiabetic) {
				$section.slideDown(150);
			} else {
				$section.slideUp(150);
			}
		});
	}

	// ===== ALLERGIE (TAG INPUT) =====
	function bindAllergies() {
		var input = $('#sd-allergy-input');

		input.on('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ',') {
				e.preventDefault();
				var val = $.trim($(this).val());
				if (val && !allergiesList.includes(val)) {
					allergiesList.push(val);
					renderAllergyTags();
					$(this).val('');
					updateAllergiesHidden();
				}
			}
		});

		$(document).on('click', '.sd-allergy-remove', function () {
			var idx = $(this).data('idx');
			allergiesList.splice(idx, 1);
			renderAllergyTags();
			updateAllergiesHidden();
		});
	}

	function renderAllergyTags() {
		var html = '';
		allergiesList.forEach(function (a, i) {
			html += '<span class="sd-tag">' + escapeHtml(a) +
				'<button type="button" class="sd-allergy-remove" data-idx="' + i + '">×</button>' +
				'</span>';
		});
		$('#sd-allergies-list').html(html);
	}

	function updateAllergiesHidden() {
		$('#allergies').val(JSON.stringify(allergiesList));
	}

	// ===== MEDICAMENTI =====
	function bindMedications() {
		$('#sd-add-medication').on('click', function () {
			addMedicationRow();
		});
	}

	function addMedicationRow() {
		var tpl = document.getElementById('sd-medication-template');
		if (!tpl) return;

		var clone = $(tpl.content.cloneNode(true));
		var idx   = medicationsList.length;
		medicationsList.push({ name: '', dosage: '', unit: 'mg', suspended: false });
		clone.find('.sd-row-num').text(idx + 1);
		clone.find('.sd-remove-row').data('med-idx', idx).on('click', function () {
			medicationsList.splice($(this).data('med-idx'), 1);
			$(this).closest('.sd-medication-row').remove();
			updateMedicationsHidden();
		});

		// Bind change events
		clone.find('.sd-med-name').on('input', function () {
			var i = $(this).closest('.sd-medication-row').index('.sd-medication-row');
			if (medicationsList[i]) medicationsList[i].name = $(this).val();
			updateMedicationsHidden();
		});
		clone.find('.sd-med-dosage').on('input', function () {
			var i = $(this).closest('.sd-medication-row').index('.sd-medication-row');
			if (medicationsList[i]) medicationsList[i].dosage = $(this).val();
			updateMedicationsHidden();
		});
		clone.find('.sd-med-unit').on('change', function () {
			var i = $(this).closest('.sd-medication-row').index('.sd-medication-row');
			if (medicationsList[i]) medicationsList[i].unit = $(this).val();
			updateMedicationsHidden();
		});
		clone.find('.sd-med-suspended').on('change', function () {
			var i = $(this).closest('.sd-medication-row').index('.sd-medication-row');
			if (medicationsList[i]) medicationsList[i].suspended = $(this).prop('checked');
			updateMedicationsHidden();
		});

		$('#sd-medications-list').append(clone);
	}

	function updateMedicationsHidden() {
		// Rileggi tutti i valori delle righe
		var meds = [];
		$('.sd-medication-row').each(function () {
			meds.push({
				name:      $(this).find('.sd-med-name').val() || '',
				dosage:    $(this).find('.sd-med-dosage').val() || '',
				unit:      $(this).find('.sd-med-unit').val() || 'mg',
				suspended: $(this).find('.sd-med-suspended').prop('checked'),
			});
		});
		$('#medications').val(JSON.stringify(meds));
	}

	// ===== ACCOMPAGNATORI =====
	function bindCompanions() {
		$('#sd-add-companion').on('click', function () {
			addRepeatable('#sd-companion-template', '#sd-companions-list', 'companionIdx');
		});

		$(document).on('click', '#sd-companions-list .sd-remove-row', function () {
			$(this).closest('.sd-repeatable-row').remove();
		});
	}

	// ===== FAMILIARI =====
	function bindFamilyMembers() {
		$('#sd-add-family-member').on('click', function () {
			addRepeatable('#sd-family-member-template', '#sd-family-members-list', 'familyIdx');
		});

		$(document).on('click', '#sd-family-members-list .sd-remove-row', function () {
			$(this).closest('.sd-repeatable-row').remove();
		});
	}

	// ===== HELPER: RIGHE RIPETIBILI =====
	var repeatableCounters = {};

	function addRepeatable(templateSelector, containerSelector, counterKey) {
		var tpl = document.querySelector(templateSelector);
		if (!tpl) return;

		if (!repeatableCounters[counterKey]) {
			repeatableCounters[counterKey] = 0;
		}

		var idx   = repeatableCounters[counterKey]++;
		var html  = tpl.innerHTML.replace(/__idx__/g, idx);
		var $row  = $(html);
		$row.find('.sd-row-num').text(idx + 1);
		$(containerSelector).append($row);
	}

	// ===== SUBMIT FORM =====
	function bindFormSubmit() {
		$('#sd-registration-form').on('submit', function (e) {
			e.preventDefault();

			var $form = $(this);
			var $btn  = $('#sd-reg-submit');
			var $msg  = $('#sd-reg-message');

			// Validazione HTML5 nativa
			if (typeof $form[0].reportValidity === 'function' && !$form[0].reportValidity()) {
				return;
			}

			// Validazione custom
			if (!$('#privacy_consent').prop('checked')) {
				showMessage('error', 'Devi accettare l\'informativa sulla privacy.');
				$('#privacy_consent')[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
				return;
			}

			// Validazione tassa
			if (!$('input[name="fee_amount"]:checked').val()) {
				showMessage('error', 'Seleziona una tassa associativa.');
				$('#sd-fee-cards')[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
				return;
			}

			// Validazione minore
			if (isMinorState) {
				var gName = $.trim($('#guardian_first_name').val());
				var gLast = $.trim($('#guardian_last_name').val());
				var gEmail = $.trim($('#guardian_email').val());
				var gPhone = $.trim($('#guardian_phone').val());
				if (!gName || !gLast || !gEmail || !gPhone) {
					showMessage('error', 'Compila tutti i dati del genitore/tutore.');
					$('#sd-guardian-section')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
					return;
				}
			}

			// Aggiorna hidden fields
			updateAllergiesHidden();
			updateMedicationsHidden();

			// Serializza form
			var formData = $form.serialize();
			formData += '&action=sd_register_member&nonce=' + sdMembership.nonce;

			$btn.prop('disabled', true).text('Invio in corso...');
			$msg.hide();

			$.ajax({
				url:    sdMembership.ajaxUrl,
				type:   'POST',
				data:   formData,
				success: function (resp) {
					if (resp.success) {
						showMessage('success', resp.data.message);
						$form.hide();
						window.scrollTo({ top: 0, behavior: 'smooth' });
					} else {
						showMessage('error', resp.data.message || 'Errore durante l\'invio. Riprova.');
						$btn.prop('disabled', false).text('Invia iscrizione');
					}
				},
				error: function () {
					showMessage('error', 'Errore di rete. Controlla la connessione e riprova.');
					$btn.prop('disabled', false).text('Invia iscrizione');
				},
			});
		});
	}

	// ===== HELPER =====
	function showMessage(type, html) {
		var $msg = $('#sd-reg-message');
		$msg.attr('class', 'sd-notice sd-notice-' + type)
			.html(html)
			.show();
		$msg[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	function escapeHtml(str) {
		return $('<div>').text(str).html();
	}

})(jQuery);
