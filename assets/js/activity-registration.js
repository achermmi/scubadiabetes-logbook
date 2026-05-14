/**
 * Activity Registration Form - JavaScript
 * 
 * Gestisce:
 * - Caricamento dinamico campi modulo
 * - Rendering tariffe con card-based UI
 * - Conversione CHF→EUR in tempo reale
 * - Validazione lato client
 * - AJAX submission form
 */

(function ($) {
	'use strict';

	const ActivityRegistration = {
		// State
		activity: null,
		fields: [],
		prices: [],
		currentPrice: null,

		// Init
		init: function () {
			this.cacheElements();
			this.bindEvents();
			this.loadActivity();
		},

		// Cache DOM elements
		cacheElements: function () {
			this.$container = $('#sd-activity-registration-page');
			this.$form = $('#sd-activity-registration-form');
			this.$loading = $('#sd-reg-loading');
			this.$error = $('#sd-reg-error');
			this.$formContainer = $('#sd-reg-form-container');
			this.$dynamicFields = $('#sd-dynamic-fields-container');
			this.$personalFields = $('#sd-personal-fields');
			this.$pricingFields = $('#sd-pricing-extra-fields');
			this.$consentsFields = $('#sd-consents-extra-fields');
			this.$minorWarning = $('#sd-minor-warning');
			this.$customSections = $('#sd-custom-sections-container');
			this.$feeCards = $('#sd-fee-cards-container');
			this.$priceTotal = $('#sd-price-total');
			this.$submitBtn = $('#sd-submit-btn');
			this.$priceError = $('#sd-price-error');
			this.$activityExtraContent = $('#sd-activity-extra-content');
		},

		// Bind events
		bindEvents: function () {
			const self = this;

			// Form submission
			this.$form.on('submit', function (e) {
				e.preventDefault();
				self.validateForm();
			});

			// Multi price selection - real-time total conversion
			$(document).on('change', 'input[name="price_ids[]"]', function () {
				self.updatePriceDisplay();
			});

			// Email validation
			$(document).on('blur', '#sd-email', function () {
				self.validateEmail($(this));
			});

			$(document).on('change blur', '#sd-birth-date', function () {
				self.updateMinorWarning();
			});

			$(document).on('change input', '.sd-dynamic-field', function () {
				self.applyConditionalVisibility();
			});
		},

		getPersonalFieldSpans: function () {
			const formConfig = this.activity && this.activity.form_configuration ? this.activity.form_configuration : {};
			return (formConfig.personal_field_spans && typeof formConfig.personal_field_spans === 'object') ? formConfig.personal_field_spans : {};
		},

		getPersonalFieldOrder: function () {
			const defaults = ['first_name', 'last_name', 'email', 'birth_date'];
			const formConfig = this.activity && this.activity.form_configuration ? this.activity.form_configuration : {};
			const savedOrder = Array.isArray(formConfig.personal_field_order) ? formConfig.personal_field_order : [];
			const baseTokens = defaults.map(function (key) {
				return 'base:' + key;
			});
			const customTokens = (this.fields || [])
				.filter(function (field) {
					return (field.section_key || 'additional') === 'personal';
				})
				.sort(function (a, b) {
					return parseInt(a.field_order || 0, 10) - parseInt(b.field_order || 0, 10);
				})
				.map(function (field) {
					return 'field:' + parseInt(field.id, 10);
				});
			const merged = [];

			savedOrder.forEach(function (token) {
				if ((baseTokens.indexOf(token) !== -1 || customTokens.indexOf(token) !== -1) && merged.indexOf(token) === -1) {
					merged.push(token);
				}
			});

			baseTokens.forEach(function (token) {
				if (merged.indexOf(token) === -1) {
					merged.push(token);
				}
			});

			customTokens.forEach(function (token) {
				if (merged.indexOf(token) === -1) {
					merged.push(token);
				}
			});

			return merged;
		},

		renderPersonalFields: function () {
			const self = this;
			const order = this.getPersonalFieldOrder();
			const spans = this.getPersonalFieldSpans();
			const baseMap = {
				first_name: { label: 'Nome', type: 'text', id: 'sd-first-name', name: 'first_name', placeholder: 'es. Mario', autocomplete: 'given-name' },
				last_name: { label: 'Cognome', type: 'text', id: 'sd-last-name', name: 'last_name', placeholder: 'es. Rossi', autocomplete: 'family-name' },
				email: { label: 'Email', type: 'email', id: 'sd-email', name: 'email', placeholder: 'es. mario@example.com', autocomplete: 'email' },
				birth_date: { label: 'Data di nascita', type: 'date', id: 'sd-birth-date', name: 'birth_date' },
			};
			const customMap = {};
			(this.fields || []).forEach(function (field) {
				if ((field.section_key || 'additional') === 'personal') {
					customMap['field:' + parseInt(field.id, 10)] = field;
				}
			});

			let html = '';
			order.forEach(function (token) {
				const span = parseInt(spans[token] || 12, 10) === 6 ? 6 : 12;
				if (token.indexOf('base:') === 0) {
					const key = token.replace(/^base:/, '');
					const baseField = baseMap[key];
					if (!baseField) {
						return;
					}
					html += '<div class="sd-field-row sd-field-span-' + span + '" data-personal-token="' + self.escapeHtml(token) + '" data-personal-type="base">';
					html += '<div class="sd-field-group sd-field-full">';
					html += '<label for="' + baseField.id + '" class="sd-label sd-label-required">' + self.escapeHtml(baseField.label) + '</label>';
					if (baseField.type === 'date') {
						html += '<input type="date" id="' + baseField.id + '" name="' + baseField.name + '" class="sd-input" required max="' + self.getTodayYmd() + '" autocomplete="birth-date">';
					} else {
						html += '<input type="' + baseField.type + '" id="' + baseField.id + '" name="' + baseField.name + '" class="sd-input" required autocomplete="' + baseField.autocomplete + '" placeholder="' + self.escapeHtml(baseField.placeholder) + '">';
					}
					html += '<div class="sd-error-message" style="display:none;"></div>';
					html += '</div></div>';
					return;
				}

				const field = customMap[token];
				if (!field) {
					return;
				}
				html += self.renderFieldMarkup(field, { span: span });
			});

			this.$personalFields.html(html);
		},

		// Load activity data from API
		loadActivity: function () {
			const self = this;
			const activityId = window.sdActivityRegistration.activityId;

			this.$loading.show();

			$.ajax({
				url: window.sdActivityRegistration.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sd_activity_get_details',
					activity_id: activityId,
					nonce: window.sdActivityRegistration.detailsNonce,
				},
				success: function (response) {
					if (response.success) {
						self.activity = response.data.activity;
						self.fields = response.data.form_fields || [];
						self.prices = response.data.prices || [];

						// Ensure all field options are objects, not strings
						self.fields.forEach(function (field) {
							if (typeof field.options === 'string') {
								try {
									field.options = JSON.parse(field.options);
								} catch (e) {
									field.options = {};
								}
							}
						});

						self.renderActivityInfo();
						self.renderPersonalFields();
						self.renderDynamicFields();
						self.renderPriceCards();
						self.updateMinorWarning();
						self.$formContainer.show();
					} else {
						self.showError(response.data.message || 'Errore nel caricamento');
					}
				},
				error: function () {
					self.showError(window.sdActivityRegistration.i18n.error);
				},
				complete: function () {
					self.$loading.hide();
				},
			});
		},

		renderPersonalBaseFields: function () {
			const order = this.getPersonalBaseFieldOrder();
			let html = '';

			order.forEach((fieldKey) => {
				switch (fieldKey) {
					case 'first_name':
						html += '<div class="sd-field-row">';
						html += '<div class="sd-field-group sd-field-full">';
						html += '<label for="sd-first-name" class="sd-label sd-label-required">Nome</label>';
						html += '<input type="text" id="sd-first-name" name="first_name" class="sd-input" required autocomplete="given-name" placeholder="es. Mario">';
						html += '<div class="sd-error-message" style="display:none;"></div>';
						html += '</div></div>';
						break;
					case 'last_name':
						html += '<div class="sd-field-row">';
						html += '<div class="sd-field-group sd-field-full">';
						html += '<label for="sd-last-name" class="sd-label sd-label-required">Cognome</label>';
						html += '<input type="text" id="sd-last-name" name="last_name" class="sd-input" required autocomplete="family-name" placeholder="es. Rossi">';
						html += '<div class="sd-error-message" style="display:none;"></div>';
						html += '</div></div>';
						break;
					case 'email':
						html += '<div class="sd-field-row">';
						html += '<div class="sd-field-group sd-field-full">';
						html += '<label for="sd-email" class="sd-label sd-label-required">Email</label>';
						html += '<input type="email" id="sd-email" name="email" class="sd-input" required autocomplete="email" placeholder="es. mario@example.com">';
						html += '<div class="sd-error-message" style="display:none;"></div>';
						html += '</div></div>';
						break;
					case 'birth_date':
						html += '<div class="sd-field-row">';
						html += '<div class="sd-field-group sd-field-full">';
						html += '<label for="sd-birth-date" class="sd-label sd-label-required">Data di nascita</label>';
						html += '<input type="date" id="sd-birth-date" name="birth_date" class="sd-input" required max="' + this.getTodayYmd() + '">';
						html += '<div class="sd-error-message" style="display:none;"></div>';
						html += '</div></div>';
						break;
				}
			});

			this.$personalBaseFields.html(html);
		},

		getPersonalBaseFieldOrder: function () {
			const defaults = ['first_name', 'last_name', 'email', 'birth_date'];
			const formConfig = this.activity && this.activity.form_configuration ? this.activity.form_configuration : {};
			const configured = Array.isArray(formConfig.personal_base_field_order) ? formConfig.personal_base_field_order : [];
			const merged = [];

			configured.forEach(function (key) {
				if (defaults.indexOf(key) !== -1 && merged.indexOf(key) === -1) {
					merged.push(key);
				}
			});

			defaults.forEach(function (key) {
				if (merged.indexOf(key) === -1) {
					merged.push(key);
				}
			});

			return merged;
		},

		// Render activity info
		renderActivityInfo: function () {
			const activity = this.activity;

			$('#sd-activity-title').text(activity.title);
			$('#sd-activity-subtitle').text(
				activity.location ? 'LUOGO: ' + activity.location : 'Scopri i dettagli dell\'attività'
			);

			// Format dates (DD.MM.YYYY) and times (HH:MM).
			const rawStartDate = activity.start_date || activity.start_date_formatted || '';
			const rawEndDate = activity.end_date || activity.end_date_formatted || '';
			const startDate = this.formatDate(rawStartDate);
			const endDate = this.formatDate(rawEndDate);
			const startTime = this.extractTime(activity.start_date_formatted || rawStartDate);
			const endTime = this.extractTime(activity.end_date_formatted || rawEndDate);
			const hideTimes = startTime === '00:00' && endTime === '00:00';

			if (hideTimes) {
				$('#sd-activity-start-date').text(startDate);
				$('#sd-activity-end-date').text(endDate);
			} else {
				$('#sd-activity-start-date').text(startDate + ' ore ' + startTime);
				$('#sd-activity-end-date').text(endDate + ' ore ' + endTime);
			}
			$('#sd-activity-location').text(activity.location || '-');

			const imageUrl = String(activity.thumbnail_url || '').trim();
			if (imageUrl) {
				$('#sd-activity-image').attr('src', this.escapeHtml(imageUrl));
				$('#sd-activity-image-wrap').show();
			} else {
				$('#sd-activity-image').attr('src', '');
				$('#sd-activity-image-wrap').hide();
			}

			const maxParticipants = parseInt(activity.max_participants) || 0;
			const currentParticipants = parseInt(activity.current_participants) || 0;
			const availableSpots = Math.max(0, maxParticipants - currentParticipants);

			let spotsText = availableSpots + ' / ' + maxParticipants;
			if (availableSpots === 0) {
				spotsText += ' ⚠️ Attività piena (lista d\'attesa)';
			}
			$('#sd-activity-spots').text(spotsText);

			if (activity.description) {
				$('#sd-activity-description').html(this.sanitizeHtml(activity.description));
				$('#sd-activity-description').show();
			} else {
				$('#sd-activity-description').empty();
				$('#sd-activity-description').hide();
			}

			const extraContentHtml = (this.fields || [])
				.filter(function (field) {
					return field.section_key === 'activity_data' && field.field_type === 'content' && String(field.content || '').trim();
				})
				.map((field) => '<div class="sd-content-block">' + this.sanitizeHtml(field.content || '') + '</div>')
				.join('');

			if (extraContentHtml) {
				$('#sd-activity-extra-content').html(extraContentHtml).show();
			} else {
				$('#sd-activity-extra-content').empty().hide();
			}

			this.syncActivityInfoBlockOrder();
		},

		// Render dynamic form fields
		renderDynamicFields: function () {
			const self = this;
			const sections = {};

			this.$personalFields.empty();
			this.$dynamicFields.empty();
			this.$pricingFields.empty();
			this.$consentsFields.empty();
			this.$activityExtraContent.empty().hide();
			this.renderPersonalFields();
			this.$customSections.empty();

			const hasPersonalFields = this.fields.some(function (field) {
				return (field.section_key || 'additional') === 'personal';
			});

			this.fields.forEach(function (field) {
				const sectionKey = field.section_key || 'additional';
				if (sectionKey === 'personal') {
					return;
				}
				if (!sections[sectionKey]) {
					const fallbackOrder = parseInt(field.section_order || self.getDefaultSectionOrder(sectionKey), 10);
					sections[sectionKey] = {
						key: sectionKey,
						label: field.section_label || self.getDefaultSectionLabel(sectionKey),
						order: self.getConfiguredSectionOrder(sectionKey, fallbackOrder),
						fields: [],
					};
				}
				sections[sectionKey].fields.push(field);
			});

			Object.keys(sections).sort(function (a, b) {
				const orderA = parseInt(sections[a].order || 0, 10) || 0;
				const orderB = parseInt(sections[b].order || 0, 10) || 0;
				if (orderA !== orderB) {
					return orderA - orderB;
				}
				if (a === 'additional' && b !== 'additional') {
					return 1;
				}
				if (b === 'additional' && a !== 'additional') {
					return -1;
				}
				return String(a).localeCompare(String(b));
			}).forEach(function (sectionKey) {
				const section = sections[sectionKey];
				let html = '';
				section.fields.forEach(function (field) {
					html += self.renderFieldMarkup(field);
				});

				switch (sectionKey) {
					case 'personal':
						break;
					case 'pricing':
						self.$pricingFields.html(html);
						break;
					case 'consents':
						self.$consentsFields.html(html);
						break;
					case 'additional':
						self.$dynamicFields.html(html);
						break;
					case 'activity_data':
							// Render all activity_data fields (not only content) in the activity info card.
							if ($.trim(html).length) {
								self.$activityExtraContent.html(html).show();
							}
						break;
					default:
						self.$customSections.append(
							'<div class="sd-form-section" data-section-key="' + self.escapeHtml(section.key) + '" data-section-order="' + parseInt(section.order, 10) + '" data-section-title="' + self.escapeHtml(section.label) + '">' +
								'<h3 class="sd-section-title"><span class="sd-section-index"></span> <span class="sd-section-title-text">' + self.escapeHtml(section.label) + '</span></h3>' +
								html +
							'</div>'
						);
				}
			});

			if (hasPersonalFields) {
				sections.personal = {
					key: 'personal',
					label: this.getDefaultSectionLabel('personal'),
					order: this.getDefaultSectionOrder('personal'),
					fields: [],
				};
			}

			$('#sd-dynamic-fields-section').toggle(!!(sections.additional && sections.additional.fields.length));
			this.syncSectionOrder(sections);
			this.applyConditionalVisibility();
		},

		renderFieldMarkup: function (field, options) {
			const self = this;
			const fieldId = 'sd-field-' + field.id;
			const fieldName = 'field_' + field.field_name;
			const required = field.is_required ? 'required' : '';
			const requiredClass = field.is_required ? 'sd-label-required' : '';
			const isSingleCheckbox = field.field_type === 'checkbox' && (!field.options || !field.options.length);
			const rowClass = options && options.span === 6 ? ' sd-field-span-6' : '';
			let html = '';

			// Content type - no input field, just HTML content
			if (field.field_type === 'content') {
				html += '<div class="sd-field-row sd-field-content" data-field-id="' + parseInt(field.id, 10) + '">';
				html += '<div class="sd-field-group sd-field-full">';
				html += '<div class="sd-content-block">';
				html += self.sanitizeHtml(field.content || '');
				html += '</div>';
				html += '</div>';
				html += '</div>';
				return html;
			}

			// Image type - display or upload
			if (field.field_type === 'image') {
				// Ensure options is an object (decode JSON if needed)
				let imgConfig = field.options || {};
				if (typeof imgConfig === 'string') {
					try {
						imgConfig = JSON.parse(imgConfig);
					} catch (e) {
						imgConfig = {};
					}
				}
				const imageType = imgConfig.image_type || 'display';
				const alignH = imgConfig.image_align_h || 'left';
				const alignV = imgConfig.image_align_v || 'top';
				const imageClasses = 'sd-field-row sd-field-image sd-image-align-h-' + alignH + ' sd-image-align-v-' + alignV;
				
				if (imageType === 'display') {
					// Display image (static)
					const imageUrl = imgConfig.image_url || '';
					const altText = imgConfig.image_alt_text || field.field_label || 'Image';
					const width = imgConfig.image_width || 'auto';
					const height = imgConfig.image_height || 'auto';
					const styleStr = (width || height) ? ' style="' + 
						(width && width !== 'auto' ? 'max-width:' + width + 'px;' : '') +
						(height && height !== 'auto' ? 'max-height:' + height + 'px;' : '') +
						(imgConfig.image_aspect_ratio ? 'object-fit:contain;' : '') +
						'"' : '';
					
					html += '<div class="' + imageClasses + '" data-field-id="' + parseInt(field.id, 10) + '">';
					html += '<div class="sd-field-group sd-field-full">';
					html += '<div class="sd-image-wrapper">';
					if (imageUrl) {
						html += '<img class="sd-display-image"' + styleStr + ' src="' + self.escapeHtml(imageUrl) + '" alt="' + self.escapeHtml(altText) + '">';
					} else {
						html += '<div class="sd-image-placeholder">Immagine non disponibile</div>';
					}
					html += '</div>';
					html += '</div>';
					html += '</div>';
				} else if (imageType === 'upload') {
					// Upload image (user input)
					html += '<div class="' + imageClasses + '" data-field-id="' + parseInt(field.id, 10) + '">';
					html += '<div class="sd-field-group sd-field-full">';
					html += '<label for="' + fieldId + '" class="sd-label ' + requiredClass + '">';
					html += self.escapeHtml(field.field_label);
					html += '</label>';
					html += '<input type="file" id="' + fieldId + '" name="' + fieldName + '" class="sd-input sd-input-file sd-dynamic-field" accept="image/*" ' + required + '>';
					html += '<div class="sd-error-message" style="display:none;"></div>';
					html += '</div>';
					html += '</div>';
				}
				return html;
			}

			html += '<div class="sd-field-row' + rowClass + '" data-field-id="' + parseInt(field.id, 10) + '">';
			html += '<div class="sd-field-group sd-field-full">';

			if (!isSingleCheckbox) {
				html += '<label for="' + fieldId + '" class="sd-label ' + requiredClass + '">';
				html += self.escapeHtml(field.field_label);
				html += '</label>';
			}

			switch (field.field_type) {
				case 'text':
					html += '<input type="text" id="' + fieldId + '" name="' + fieldName + '" class="sd-input sd-dynamic-field" ' + required + ' placeholder="' + self.escapeHtml(field.placeholder || '') + '">';
					break;
				case 'textarea':
					html += '<textarea id="' + fieldId + '" name="' + fieldName + '" class="sd-input sd-dynamic-field" ' + required + ' placeholder="' + self.escapeHtml(field.placeholder || '') + '" rows="4"></textarea>';
					break;
				case 'select':
					html += '<select id="' + fieldId + '" name="' + fieldName + '" class="sd-select sd-dynamic-field" ' + required + '>';
					html += '<option value="">' + window.sdActivityRegistration.i18n.selectPrice + '</option>';
					(field.options || []).forEach(function (option) {
						html += '<option value="' + self.escapeHtml(option.value || '') + '">' + self.escapeHtml(option.label || '') + '</option>';
					});
					html += '</select>';
					break;
				case 'checkbox':
					if (field.options && field.options.length) {
						html += '<div class="sd-checkbox-group">';
						field.options.forEach(function (option) {
							const checkboxId = fieldId + '-' + self.escapeHtml(option.value || '');
							html += '<label class="sd-checkbox-label">';
							html += '<input type="checkbox" id="' + checkboxId + '" name="' + fieldName + '[]" class="sd-dynamic-field" value="' + self.escapeHtml(option.value || '') + '"' + (field.is_required ? ' required' : '') + '>';
							html += '<span>' + self.escapeHtml(option.label || '') + '</span>';
							html += '</label>';
						});
						html += '</div>';
					} else {
						html += '<label class="sd-checkbox-label sd-checkbox-single">';
						html += '<input type="checkbox" id="' + fieldId + '" name="' + fieldName + '" class="sd-dynamic-field" value="1"' + (field.is_required ? ' required' : '') + '>';
						html += '<span>' + self.escapeHtml(field.field_label) + '</span>';
						html += '</label>';
					}
					break;
				case 'radio':
					html += '<div class="sd-radio-group">';
					(field.options || []).forEach(function (option) {
						const radioId = fieldId + '-' + self.escapeHtml(option.value || '');
						html += '<label class="sd-radio-label">';
						html += '<input type="radio" id="' + radioId + '" name="' + fieldName + '" class="sd-dynamic-field" value="' + self.escapeHtml(option.value || '') + '"' + (field.is_required ? ' required' : '') + '>';
						html += '<span>' + self.escapeHtml(option.label || '') + '</span>';
						html += '</label>';
					});
					html += '</div>';
					break;
				case 'date':
					html += '<input type="date" id="' + fieldId + '" name="' + fieldName + '" class="sd-input sd-dynamic-field" ' + required + '>';
					break;
				case 'number':
					html += '<input type="number" id="' + fieldId + '" name="' + fieldName + '" class="sd-input sd-dynamic-field" ' + required + ' placeholder="0">';
					break;
				default:
					html += '<input type="text" id="' + fieldId + '" name="' + fieldName + '" class="sd-input sd-dynamic-field" ' + required + ' placeholder="' + self.escapeHtml(field.placeholder || '') + '">';
			}

			html += '<div class="sd-error-message" style="display:none;"></div>';
			html += '</div>';
			html += '</div>';
			return html;
		},

		syncSectionOrder: function (sections) {
			const self = this;
			const hasPriceCards = Array.isArray(this.prices) && this.prices.length > 0;
			const fixedOrders = {
				personal: this.getConfiguredSectionOrder('personal', sections.personal ? sections.personal.order : this.getDefaultSectionOrder('personal')),
				additional: this.getConfiguredSectionOrder('additional', sections.additional ? sections.additional.order : this.getDefaultSectionOrder('additional')),
				pricing: this.getConfiguredSectionOrder('pricing', sections.pricing ? sections.pricing.order : this.getDefaultSectionOrder('pricing')),
				consents: this.getConfiguredSectionOrder('consents', sections.consents ? sections.consents.order : this.getDefaultSectionOrder('consents')),
			};

			$('#sd-section-personal').attr('data-section-order', fixedOrders.personal).toggle(!!sections.personal);
			if ($.trim(this.$customSections.html()).length) {
				this.$customSections.insertBefore('#sd-dynamic-fields-section');
			}
			$('#sd-dynamic-fields-section').attr('data-section-order', fixedOrders.additional).toggle(!!sections.additional);
			$('#sd-pricing-section').attr('data-section-order', fixedOrders.pricing).toggle(!!sections.pricing || hasPriceCards);
			$('#sd-consents-section').attr('data-section-order', fixedOrders.consents).toggle(!!sections.consents);

			const customNodes = [];
			Object.keys(sections).forEach(function (key) {
				if (key === 'personal' || key === 'additional' || key === 'pricing' || key === 'consents') {
					return;
				}
				const $node = self.$customSections.children('.sd-form-section[data-section-key="' + key + '"]');
				if ($node.length) {
					$node.attr('data-section-order', parseInt(sections[key].order || 0, 10) || 0);
					customNodes.push($node.get(0));
				}
			});
			customNodes.sort(function (a, b) {
				return parseInt($(a).attr('data-section-order'), 10) - parseInt($(b).attr('data-section-order'), 10);
			});

			const orderedNodes = [];
			const pushSectionNode = function (selector) {
				const $node = self.$form.children(selector + ':visible').first();
				if ($node.length) {
					orderedNodes.push($node.get(0));
				}
			};

			pushSectionNode('#sd-section-personal[data-section-key]');
			customNodes.forEach(function (node) {
				orderedNodes.push(node);
			});
			pushSectionNode('#sd-dynamic-fields-section[data-section-key]');
			pushSectionNode('#sd-pricing-section[data-section-key]');
			pushSectionNode('#sd-consents-section[data-section-key]');

			this.$customSections.before($(orderedNodes));
			this.renumberSections();
		},

		renumberSections: function () {
			let index = 1;
			this.$form.find('.sd-form-section[data-section-key]').each(function () {
				$(this).find('.sd-section-index').first().text(index + '.');
				index += 1;
			});
		},

		getActivityDataLayoutOrder: function () {
			// Base activity info is always fixed at the top in this order.
			return ['core', 'thumbnail', 'description', 'extra_fields'];
		},

		ensureActivityInfoBlocks: function () {
			const $info = this.$container.find('.sd-activity-info').first();
			const $image = $('#sd-activity-image-wrap').attr('data-activity-block', 'thumbnail');
			const $details = $info.find('.sd-activity-details').first().attr('data-activity-block', 'core');
			const $description = $('#sd-activity-description').attr('data-activity-block', 'description');
			const $extra = $('#sd-activity-extra-content').attr('data-activity-block', 'extra_fields');

			return {
				info: $info,
				core: $details,
				thumbnail: $image,
				description: $description,
				extra_fields: $extra,
			};
		},

		syncActivityInfoBlockOrder: function () {
			const blocks = this.ensureActivityInfoBlocks();
			const orderedNodes = [];
			const seen = {};

			this.getActivityDataLayoutOrder().forEach(function (key) {
				if (blocks[key] && blocks[key].length) {
					orderedNodes.push(blocks[key][0]);
					seen[key] = true;
				}
			});

			['core', 'thumbnail', 'description', 'extra_fields'].forEach(function (key) {
				if (!seen[key] && blocks[key] && blocks[key].length) {
					orderedNodes.push(blocks[key][0]);
				}
			});

			blocks.info.append($(orderedNodes));
		},

		getEventStatusLabel: function (status) {
			switch (String(status || 'draft')) {
				case 'published': return 'Pubblicata';
				case 'closed': return 'Conclusa';
				case 'archived': return 'Archiviata';
				case 'draft':
				default: return 'Bozza';
			}
		},

		getDefaultSectionLabel: function (sectionKey) {
			switch (sectionKey) {
				case 'personal': return 'Dati Personali';
				case 'pricing': return 'Selezione Tariffa';
				case 'consents': return 'Consensi';
				case 'additional':
				default: return 'Informazioni Aggiuntive';
			}
		},

		getDefaultSectionOrder: function (sectionKey) {
			switch (sectionKey) {
				case 'personal': return 10;
				case 'pricing': return 30;
				case 'consents': return 40;
				case 'additional':
				default: return 20;
			}
		},

		getSectionMeta: function () {
			const formConfig = this.activity && this.activity.form_configuration ? this.activity.form_configuration : {};
			if (!formConfig || typeof formConfig.section_meta !== 'object' || !formConfig.section_meta) {
				return {};
			}

			return formConfig.section_meta;
		},

		getConfiguredSectionOrder: function (sectionKey, fallbackOrder) {
			const meta = this.getSectionMeta();
			const metaKey = sectionKey === 'pricing' ? 'tariffe' : sectionKey;
			const metaEntry = meta[metaKey] && typeof meta[metaKey] === 'object' ? meta[metaKey] : null;
			const configuredOrder = metaEntry ? parseInt(metaEntry.order || 0, 10) : 0;

			if (configuredOrder > 0) {
				return configuredOrder;
			}

			return parseInt(fallbackOrder || this.getDefaultSectionOrder(sectionKey), 10);
		},

		getFieldConditionsMap: function () {
			const formConfig = this.activity && this.activity.form_configuration ? this.activity.form_configuration : {};
			if (!formConfig || typeof formConfig.field_conditions !== 'object' || !formConfig.field_conditions) {
				return {};
			}
			return formConfig.field_conditions;
		},

		normalizeConditionValue: function (value) {
			return String(value === undefined || value === null ? '' : value).trim().toLowerCase();
		},

		getFieldCurrentValueById: function (fieldId) {
			const idNum = parseInt(fieldId, 10) || 0;
			if (!idNum) {
				return '';
			}

			const field = (this.fields || []).find(function (item) {
				return parseInt(item.id, 10) === idNum;
			});
			if (!field) {
				return '';
			}

			const baseName = 'field_' + field.field_name;
			if (field.field_type === 'checkbox' && field.options && field.options.length) {
				return $('input[name="' + baseName + '[]"]:checked').map(function () { return $(this).val(); }).get().join(', ');
			}
			if (field.field_type === 'checkbox') {
				return $('input[name="' + baseName + '"]').is(':checked') ? '1' : '';
			}
			if (field.field_type === 'radio') {
				return $('input[name="' + baseName + '"]:checked').val() || '';
			}

			return $('[name="' + baseName + '"]').val() || '';
		},

		getFieldCurrentLabelById: function (fieldId) {
			const idNum = parseInt(fieldId, 10) || 0;
			if (!idNum) {
				return '';
			}

			const field = (this.fields || []).find(function (item) {
				return parseInt(item.id, 10) === idNum;
			});
			if (!field) {
				return '';
			}

			const baseName = 'field_' + field.field_name;
			if (field.field_type === 'select') {
				const $selected = $('[name="' + baseName + '"] option:selected');
				return $selected.length ? ($selected.text() || '') : '';
			}
			if (field.field_type === 'radio') {
				const selected = $('input[name="' + baseName + '"]:checked').val() || '';
				const opt = (field.options || []).find(function (item) {
					return String(item.value || '') === String(selected);
				});
				return opt ? String(opt.label || '') : '';
			}

			return '';
		},

		setFieldRowVisibility: function (targetFieldId, shouldShow) {
			const $row = $('.sd-field-row[data-field-id="' + parseInt(targetFieldId, 10) + '"]');
			if (!$row.length) {
				return;
			}

			const $controls = $row.find('input, select, textarea');

			if (shouldShow) {
				$row.show();
				$controls.each(function () {
					const $ctrl = $(this);
					$ctrl.prop('disabled', false);
					if (String($ctrl.attr('data-required-original') || '') === '1') {
						$ctrl.attr('required', 'required');
					}
				});
				return;
			}

			$controls.each(function () {
				const $ctrl = $(this);
				if ($ctrl.attr('data-required-original') === undefined) {
					$ctrl.attr('data-required-original', $ctrl.is('[required]') ? '1' : '0');
				}
				$ctrl.removeAttr('required');
				$ctrl.removeClass('sd-input-error');
				if ($ctrl.is(':checkbox') || $ctrl.is(':radio')) {
					$ctrl.prop('checked', false);
				} else {
					$ctrl.val('');
				}
				$ctrl.prop('disabled', true);
			});

			$row.find('.sd-error-message').hide().text('');
			$row.hide();
		},

		applyConditionalVisibility: function () {
			const rules = this.getFieldConditionsMap();
			const self = this;

			const evaluateRule = function (rule) {
				const sourceId = parseInt(rule && rule.source_field_id, 10) || 0;
				const operator = (rule && rule.operator === 'not_equals') ? 'not_equals' : 'equals';
				const expected = self.normalizeConditionValue(rule && rule.value ? rule.value : '');
				const current = self.normalizeConditionValue(self.getFieldCurrentValueById(sourceId));
				const currentLabel = self.normalizeConditionValue(self.getFieldCurrentLabelById(sourceId));

				if (!sourceId || expected === '') {
					return true;
				}

				if (operator === 'not_equals') {
					return (current !== expected && currentLabel !== expected);
				}

				return (current === expected || currentLabel === expected);
			};

			Object.keys(rules).forEach(function (targetKey) {
				const targetId = parseInt(targetKey, 10) || 0;
				if (!targetId) {
					return;
				}

				const entry = rules[targetKey] || {};
				const mode = entry.mode === 'or' ? 'or' : 'and';
				const ruleList = Array.isArray(entry.rules)
					? entry.rules
					: ((entry && entry.source_field_id) ? [entry] : []);
				const validRules = ruleList.filter(function (rule) {
					return (parseInt(rule && rule.source_field_id, 10) || 0) > 0 && String(rule && rule.value ? rule.value : '').trim() !== '';
				});
				let shouldShow = true;

				if (!validRules.length) {
					shouldShow = true;
				} else if (
					mode === 'or' &&
					validRules.every(function (rule) { return rule.operator === 'not_equals'; })
				) {
					// UX-friendly behavior: "A diverso da X OR diverso da Y" on the same source
					// is commonly intended as exclusion set (different from both X and Y).
					// Treat this specific pattern as AND over the not_equals rules.
					shouldShow = validRules.every(function (rule) {
						return evaluateRule(rule);
					});
				} else if (mode === 'or') {
					shouldShow = validRules.some(function (rule) {
						return evaluateRule(rule);
					});
				} else {
					shouldShow = validRules.every(function (rule) {
						return evaluateRule(rule);
					});
				}

				self.setFieldRowVisibility(targetId, shouldShow);
			});
		},

		// Render price cards
		renderPriceCards: function () {
			const self = this;

			if (this.prices.length === 0) {
				$('#sd-pricing-section').show();
				this.$feeCards.html('');
				this.$priceError.text('Nessuna tariffa disponibile per questa attivita. Contatta l\'organizzatore.').show();
				return;
			}

			this.$priceError.hide().text('');
			$('#sd-pricing-section').show();

			let html = '';

			this.prices.forEach(function (price) {
				const isDefault = price.is_default ? 'checked' : '';
				const cardClass = isDefault ? 'sd-fee-card-selected' : '';

				html += '<label class="sd-fee-card ' + cardClass + '">';
				html += '<input type="checkbox" name="price_ids[]" class="sd-price-checkbox" value="' + price.id + '" data-price-chf="' + parseFloat(price.price_chf) + '" data-price-eur="' + parseFloat(price.price_eur || price.price_chf * 1.05) + '" ' + isDefault + '>';
				html += '<div class="sd-fee-card-inner">';
				html += '<div class="sd-fee-card-header">';
				html += '<span class="sd-fee-label">' + self.escapeHtml(price.price_name) + '</span>';
				html += '</div>';
				html += '<div class="sd-fee-card-price">';
				html += '<div class="sd-price-chf">CHF <strong>' + parseFloat(price.price_chf).toFixed(2) + '</strong></div>';
				html += '<div class="sd-price-eur" data-price-eur="' + parseFloat(price.price_eur || price.price_chf * 1.05).toFixed(2) + '">';
				html += '= € <strong>' + parseFloat(price.price_eur || price.price_chf * 1.05).toFixed(2) + '</strong>';
				html += '</div>';
				html += '<div class="sd-price-rate">' + window.sdActivityRegistration.i18n.loadingPrice + '</div>';
				html += '</div>';
				html += '</div>';
				html += '</label>';
			});

			this.$feeCards.html(html);

			// Keep configured default selection; fallback to first only if none is marked default.
			if (this.prices.length > 0) {
				if (!$('input[name="price_ids[]"]:checked').length) {
					$('input[name="price_ids[]"]:first').prop('checked', true);
				}
				this.updatePriceDisplay();
			}
		},

		// Update total price display with real-time EUR conversion
		updatePriceDisplay: function () {
			const self = this;
			const selectedInputs = $('input[name="price_ids[]"]:checked');
			const selectedPriceIds = selectedInputs.map(function () {
				return parseInt($(this).val(), 10) || 0;
			}).get().filter(function (id) { return id > 0; });

			if (!selectedPriceIds.length) {
				this.currentPrice = null;
				$('.sd-fee-card').removeClass('sd-fee-card-selected');
				this.$priceTotal.hide().empty();
				return;
			}

			const selectedPrices = this.prices.filter(function (price) {
				return selectedPriceIds.indexOf(parseInt(price.id, 10)) !== -1;
			});

			if (!selectedPrices.length) {
				this.currentPrice = null;
				this.$priceTotal.hide().empty();
				return;
			}

			const totalChf = selectedPrices.reduce(function (sum, price) {
				return sum + parseFloat(price.price_chf || 0);
			}, 0);

			const fallbackEur = selectedPrices.reduce(function (sum, price) {
				const eur = parseFloat(price.price_eur || 0);
				if (eur > 0) {
					return sum + eur;
				}
				return sum + (parseFloat(price.price_chf || 0) * 1.05);
			}, 0);

			const labels = selectedPrices.map(function (price) {
				return String(price.price_name || 'Tariffa');
			});

			this.currentPrice = {
				id: selectedPriceIds[0],
				ids: selectedPriceIds,
				price_name: labels.join(' + '),
				price_chf: totalChf,
				price_eur: fallbackEur,
			};

			// Update card styling (also apply inline so it works regardless of CSS cache)
			$('.sd-fee-card').removeClass('sd-fee-card-selected');
			$('.sd-fee-card .sd-fee-card-inner').css({
				border: '',
				background: '',
				boxShadow: '',
				transform: ''
			});
			$('.sd-fee-card .sd-fee-card-inner .sd-selected-badge').remove();

			selectedInputs.closest('.sd-fee-card').addClass('sd-fee-card-selected');
			selectedInputs.closest('.sd-fee-card').find('.sd-fee-card-inner').css({
				border: '2px solid #1f75be',
				background: 'linear-gradient(140deg, #dff0ff 0%, #cae4ff 100%)',
				boxShadow: '0 0 0 3px rgba(31,117,190,0.22), 0 24px 44px rgba(0,85,165,0.22)',
				transform: 'translateY(-2px)'
			});
			selectedInputs.closest('.sd-fee-card').find('.sd-fee-card-inner').each(function () {
				if (!$(this).find('.sd-selected-badge').length) {
					$(this).prepend('<span class="sd-selected-badge" style="position:absolute;top:10px;left:10px;background:#1f75be;color:#fff;font-size:0.65rem;font-weight:800;letter-spacing:0.04em;text-transform:uppercase;padding:0.18rem 0.45rem;border-radius:999px;line-height:1.2;z-index:2;">Selezionata</span>');
				}
			});

			this.$priceTotal.html(
				'<div class="sd-price-total-row"><span class="sd-price-total-label">Totale selezionato: </span><strong>CHF ' + parseFloat(totalChf).toFixed(2) + '</strong></div>' +
				'<div class="sd-price-total-sub">= EUR ' + parseFloat(fallbackEur).toFixed(2) + ' (stima)</div>'
			).show();

			// Fetch real-time EUR conversion for the selected total CHF
			$.ajax({
				url: window.sdActivityRegistration.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sd_get_eur_price',
					price_chf: parseFloat(totalChf),
					nonce: window.sdActivityRegistration.actionNonce,
				},
				success: function (response) {
					if (response.success) {
						const priceEur = response.data.price_eur;
						const rate = response.data.rate;

						self.$priceTotal.html(
							'<div class="sd-price-total-row"><span class="sd-price-total-label">Totale selezionato: </span><strong>CHF ' + parseFloat(totalChf).toFixed(2) + '</strong></div>' +
							'<div class="sd-price-total-sub">= EUR ' + parseFloat(priceEur).toFixed(2) + '</div>' +
							'<div class="sd-price-total-rate">Tasso: 1 CHF = ' + parseFloat(rate).toFixed(4) + ' EUR</div>'
						).show();

						self.currentPrice.price_eur = parseFloat(priceEur);
					}
				},
			});
		},

		// Validate entire form
		validateForm: function () {
			let isValid = true;

			// Reset error messages
			$('.sd-error-message').hide();

			// Validate name
			if (!$('#sd-first-name').val().trim()) {
				this.showFieldError($('#sd-first-name'), window.sdActivityRegistration.i18n.fieldRequired);
				isValid = false;
			}

			// Validate last name
			if (!$('#sd-last-name').val().trim()) {
				this.showFieldError($('#sd-last-name'), window.sdActivityRegistration.i18n.fieldRequired);
				isValid = false;
			}

			// Validate email
			if (!$('#sd-email').val().trim()) {
				this.showFieldError($('#sd-email'), window.sdActivityRegistration.i18n.fieldRequired);
				isValid = false;
			} else if (!this.isValidEmail($('#sd-email').val())) {
				this.showFieldError($('#sd-email'), window.sdActivityRegistration.i18n.invalidEmail);
				isValid = false;
			}

			const birthDateValue = $('#sd-birth-date').val().trim();
			if (!birthDateValue) {
				this.showFieldError($('#sd-birth-date'), window.sdActivityRegistration.i18n.birthDateRequired || window.sdActivityRegistration.i18n.fieldRequired);
				isValid = false;
			} else {
				const info = this.getMinorInfoFromBirthDate(birthDateValue);
				if (info.age === null || info.age < 0) {
					this.showFieldError($('#sd-birth-date'), window.sdActivityRegistration.i18n.birthDateRequired || window.sdActivityRegistration.i18n.fieldRequired);
					isValid = false;
				}
			}

			this.updateMinorWarning();

			// Validate multi-price selection
			if (!$('input[name="price_ids[]"]:checked').length) {
				this.$priceError.text('Seleziona almeno una tariffa.').show();
				isValid = false;
			} else {
				this.$priceError.hide().text('');
			}

			// Validate dynamic required fields (gestione gruppi checkbox/radio inclusa)
			const validatedNames = {};
			$('.sd-dynamic-field[required]').each((i, el) => {
				const $el = $(el);
				if (!$el.closest('.sd-field-row').is(':visible')) {
					return;
				}
				const name = $el.attr('name');
				if (!name || validatedNames[name]) {
					return;
				}
				validatedNames[name] = true;

				if ($el.is(':checkbox')) {
					const selector = name.endsWith('[]') ? 'input[name="' + name + '"]' : 'input[name="' + name + '"]';
					if (!$(selector + ':checked').length) {
						this.showFieldError($el, window.sdActivityRegistration.i18n.fieldRequired);
						isValid = false;
					}
					return;
				}

				if ($el.is(':radio')) {
					if (!$('input[name="' + name + '"]:checked').length) {
						this.showFieldError($el, window.sdActivityRegistration.i18n.fieldRequired);
						isValid = false;
					}
					return;
				}

				if (!$el.val() || !$el.val().trim()) {
					this.showFieldError($el, window.sdActivityRegistration.i18n.fieldRequired);
					isValid = false;
				}
			});

			if (isValid) {
				this.submitForm();
			} else {
				// Scroll to first error
				const $firstError = $('.sd-error-message:visible').first();
				if ($firstError.length) {
					$('html, body').animate({ scrollTop: $firstError.offset().top - 100 }, 300);
				}
			}
		},

		// Validate email
		validateEmail: function ($input) {
			const email = $input.val().trim();
			if (email && !this.isValidEmail(email)) {
				this.showFieldError($input, window.sdActivityRegistration.i18n.invalidEmail);
			}
		},

		// Check if email is valid
		isValidEmail: function (email) {
			const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
			return re.test(email);
		},

		// Show field error
		showFieldError: function ($input, message) {
			$input.closest('.sd-field-group').find('.sd-error-message').text(message).show();
			$input.closest('.sd-field-group').find('input, textarea, select').addClass('sd-input-error');
		},

		// Submit form via AJAX
		submitForm: function () {
			const self = this;
			const selectedPriceIds = $('input[name="price_ids[]"]:checked').map(function () {
				return parseInt($(this).val(), 10) || 0;
			}).get().filter(function (id) { return id > 0; });

			if (!selectedPriceIds.length || !this.currentPrice) {
				this.$priceError.text('Seleziona almeno una tariffa.').show();
				this.$submitBtn.prop('disabled', false).text('Procedi al Pagamento');
				return;
			}

			// Disable button
			this.$submitBtn.prop('disabled', true).text(window.sdActivityRegistration.i18n.redirecting);

			// Collect form data
			const formData = new FormData(this.$form[0]);
			formData.set('nonce', window.sdActivityRegistration.actionNonce);
			formData.append('action', 'sd_activity_register');
			formData.append('activity_id', window.sdActivityRegistration.activityId);
			formData.append('price_id', this.currentPrice.id);
			formData.append('price_ids', JSON.stringify(selectedPriceIds));
			formData.append('price_chf', parseFloat(this.currentPrice.price_chf));
			formData.append('price_eur', parseFloat(this.currentPrice.price_eur));

			// Collect dynamic field data
			const registrationData = {};
			this.fields.forEach(function (field) {
				const $row = $('.sd-field-row[data-field-id="' + parseInt(field.id, 10) + '"]');
				if ($row.length && !$row.is(':visible')) {
					registrationData[field.field_name] = (field.field_type === 'checkbox' && field.options && field.options.length) ? [] : '';
					return;
				}

				const baseName = 'field_' + field.field_name;
				if (field.field_type === 'checkbox' && field.options && field.options.length) {
					registrationData[field.field_name] = $('input[name="' + baseName + '[]"]:checked').map(function () { return $(this).val(); }).get();
					return;
				}
				if (field.field_type === 'checkbox') {
					registrationData[field.field_name] = $('input[name="' + baseName + '"]').is(':checked') ? '1' : '';
					return;
				}
				if (field.field_type === 'radio') {
					registrationData[field.field_name] = $('input[name="' + baseName + '"]:checked').val() || '';
					return;
				}
				registrationData[field.field_name] = $('[name="' + baseName + '"]').val() || '';
			});

			registrationData.birth_date = $('#sd-birth-date').val() || '';
			registrationData.is_minor = this.getMinorInfoFromBirthDate(registrationData.birth_date).isMinor ? 1 : 0;
			registrationData.selected_price_ids = selectedPriceIds;

			formData.append('registration_data', JSON.stringify(registrationData));

			$.ajax({
				url: window.sdActivityRegistration.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function (response) {
					if (response.success) {
						self.showSuccess();
						if (response.data.redirect_url) {
							setTimeout(function () {
								window.location.href = response.data.redirect_url;
							}, 1000);
						} else {
							self.$submitBtn.prop('disabled', false).text(response.data.message || window.sdActivityRegistration.i18n.success);
						}
					} else {
						self.showError(response.data.message || window.sdActivityRegistration.i18n.error);
						self.$submitBtn.prop('disabled', false).text('Procedi al Pagamento');
					}
				},
				error: function () {
					self.showError(window.sdActivityRegistration.i18n.error);
					self.$submitBtn.prop('disabled', false).text('Procedi al Pagamento');
				},
			});
		},

		// Show success message
		showSuccess: function () {
			$('#sd-reg-success').show();
		},

		// Show error message
		showError: function (message) {
			this.$error.html('<strong>Errore:</strong> ' + this.escapeHtml(message)).show();
			$('html, body').animate({ scrollTop: this.$error.offset().top - 100 }, 300);
		},

		// Format date (YYYY-MM-DD to DD.MM.YYYY)
		formatDate: function (dateString) {
			if (!dateString) {
				return '-';
			}

			const text = String(dateString).trim();
			const italianMatch = text.match(/^(\d{2})[./-](\d{2})[./-](\d{4})/);
			if (italianMatch) {
				return italianMatch[1] + '.' + italianMatch[2] + '.' + italianMatch[3];
			}

			const date = new Date(text.replace(' ', 'T'));
			if (isNaN(date.getTime())) {
				return '-';
			}
			return ('0' + date.getDate()).slice(-2) + '.' + 
				   ('0' + (date.getMonth() + 1)).slice(-2) + '.' + 
				   date.getFullYear();
		},

		extractTime: function (dateString) {
			if (!dateString) {
				return '00:00';
			}

			const text = String(dateString).trim();
			const match = text.match(/\b(\d{2}):(\d{2})(?::\d{2})?\b/);
			if (match) {
				return match[1] + ':' + match[2];
			}

			const parsed = new Date(text.replace(' ', 'T'));
			if (isNaN(parsed.getTime())) {
				return '00:00';
			}

			return ('0' + parsed.getHours()).slice(-2) + ':' + ('0' + parsed.getMinutes()).slice(-2);
		},

		getTodayYmd: function () {
			const now = new Date();
			return now.getFullYear() + '-' + ('0' + (now.getMonth() + 1)).slice(-2) + '-' + ('0' + now.getDate()).slice(-2);
		},

		getMinorInfoFromBirthDate: function (value) {
			const raw = String(value || '').trim();
			if (!raw) {
				return { isMinor: false, age: null };
			}

			const birth = new Date(raw + 'T00:00:00');
			if (isNaN(birth.getTime())) {
				return { isMinor: false, age: null };
			}

			const now = new Date();
			let age = now.getFullYear() - birth.getFullYear();
			const monthDiff = now.getMonth() - birth.getMonth();
			if (monthDiff < 0 || (monthDiff === 0 && now.getDate() < birth.getDate())) {
				age -= 1;
			}

			return {
				isMinor: age >= 0 && age < 18,
				age: age,
			};
		},

		updateMinorWarning: function () {
			const value = $('#sd-birth-date').val() || '';
			const info = this.getMinorInfoFromBirthDate(value);
			if (info.isMinor) {
				this.$minorWarning.html('<strong>Attenzione:</strong> risulti minorenne (' + this.escapeHtml(String(info.age)) + ' anni). Serve il consenso di un genitore/tutore per partecipare.').show();
			} else {
				this.$minorWarning.hide().empty();
			}
		},

		// Escape HTML
		escapeHtml: function (text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		// Sanitize HTML (basic)
		sanitizeHtml: function (html) {
			// Basic sanitization - remove script tags
			return html.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
		},
	};

	// Initialize on document ready
	$(document).ready(function () {
		ActivityRegistration.init();
	});

})(jQuery);
