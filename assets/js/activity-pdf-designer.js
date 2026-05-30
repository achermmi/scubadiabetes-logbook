/* global sdPdfDesigner, jQuery */
(function ($) {
	'use strict';

	// =========================================================================
	// COSTANTI
	// =========================================================================

	// Canvas A4 portrait a 3.78 px/mm
	var PX_PER_MM    = 794 / 210;   // ~3.781
	var PAGE_W_MM    = 210;
	var PAGE_H_MM    = 297;
	var CANVAS_W_PX  = 794;
	var CANVAS_H_PX  = 1123;

	// =========================================================================
	// STATO
	// =========================================================================

	var state = {
		templateId:     0,
		templateType:   'activity',   // 'activity' | 'member'
		orientation:    'portrait_hf',
		elements:       [],          // array di oggetti elemento
		selectedId:     null,
		selectedIds:    [],          // multi-selezione
		nextId:         1,
		activityId:     0,
		dynamicFields:  {},
		isDragging:     false,
		dragOffsetX:    0,
		dragOffsetY:    0,
		isResizing:     false,
		resizeStartX:   0,
		resizeStartW:   0,
		isBoxSelecting: false,
		layout: {
			style:              'branded',
			header_title:       '',
			header_subtitle:    '',
			header_bg:          '#0055A5',
			accent_bg:          '#00A3D8',
			logo_url:           '',
			logo_attachment_id: 0,
			show_page_numbers:  true,
			show_date:          true,
			footer_note:        '',
		},
	};

	// =========================================================================
	// UTILS
	// =========================================================================

	function pxToMm(px) { return parseFloat((px / PX_PER_MM).toFixed(2)); }
	function mmToPx(mm) { return Math.round(mm * PX_PER_MM); }

	function clamp(val, min, max) { return Math.min(max, Math.max(min, val)); }

	function esc(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function showStatus(msg, type) {
		var $s = $('#sd-pdf-status');
		$s.removeClass('ok error info').addClass(type || 'info').html(esc(msg)).show();
		clearTimeout($s.data('timer'));
		$s.data('timer', setTimeout(function () { $s.fadeOut(); }, 4000));
	}

	function generateId() {
		return 'el_' + (state.nextId++);
	}

	function getPageDims() {
		if (state.orientation === 'landscape' || state.orientation === 'landscape_hf') {
			return { w: 297, h: 210, canvasW: 1123, canvasH: 794 };
		}
		return { w: 210, h: 297, canvasW: 794, canvasH: 1123 };
	}

	// =========================================================================
	// ELEMENTI: CREAZIONE / RENDERING
	// =========================================================================

	function defaultElement(type, label) {
		var el = {
			id:          generateId(),
			type:        type,
			label:       label || type,
			x:           20,
			y:           20,
			width:       80,
			font_size:   11,
			font_bold:   false,
			font_italic: false,
			color:       '#000000',
			prefix:      '',
			suffix:         '',
			label_show:     false,
			label_position: 'above',
			custom_text:    '',
		};
		if ( 'image' === type ) {
			el.url           = '';
			el.attachment_id = 0;
			el.height        = 50;
			el.rotation      = 0;
			el.flip_h        = false;
			el.flip_v        = false;
			el.opacity       = 1.0;
			el.is_background = false;
			el.border_radius = 0;
			el.bg_color      = '';
		}
		return el;
	}

	function previewValue(el) {
		var allFields = $.extend({}, sdPdfDesigner.fixedFields, sdPdfDesigner.activityFields, sdPdfDesigner.memberFields, state.dynamicFields);
		if (el.type === 'text_label') {
			return el.custom_text || '(testo libero)';
		}
		var label = allFields[el.type] || el.type;
		return '{ ' + label + ' }';
	}

	function buildElementDOM(el) {
		if ( 'image' === el.type ) {
			return buildImageDOM(el);
		}
		var dims  = getPageDims();
		var xPx   = mmToPx(el.x);
		var yPx   = mmToPx(el.y);
		var wPx   = mmToPx(el.width);
		var hPx   = el.height > 0 ? mmToPx(el.height) : 0;

		var style = [
			'left:'   + xPx + 'px',
			'top:'    + yPx + 'px',
			'width:'  + wPx + 'px',
			'font-size:' + el.font_size + 'pt',
			'color:' + el.color,
		];
		if (hPx > 0) { style.push('height:' + hPx + 'px', 'overflow:hidden'); }
		if (el.font_bold)   { style.push('font-weight:bold'); }
		if (el.font_italic) { style.push('font-style:italic'); }

		var valText  = esc(el.prefix + previewValue(el) + el.suffix);
		var pos      = el.label_position || 'above';
		var lblBlock = el.label_show ? '<span class="sd-el-label">' + esc(el.label) + '</span>' : '';
		var valBlock = '<span class="sd-el-value">' + valText + '</span>';
		var innerHtml;

		if (!el.label_show) {
			innerHtml = valBlock;
		} else if (pos === 'above') {
			innerHtml = lblBlock + valBlock;
		} else if (pos === 'below') {
			innerHtml = valBlock + lblBlock;
		} else if (pos === 'before') {
			innerHtml = '<span class="sd-el-label" style="display:inline;">' + esc(el.label) + ': </span>' +
				'<span class="sd-el-value" style="display:inline;">' + valText + '</span>';
		} else if (pos === 'after') {
			innerHtml = '<span class="sd-el-value" style="display:inline;">' + valText + '</span>' +
				'<span class="sd-el-label" style="display:inline;margin-left:3px;">' + esc(el.label) + '</span>';
		} else {
			innerHtml = valBlock;
		}

		return $('<div>')
			.addClass('sd-canvas-element')
			.attr('data-id', el.id)
			.html(innerHtml + '<div class="sd-el-resize"></div><div class="sd-el-resize-s"></div>')
			.attr('style', style.join(';'));
	}

	function buildImageDOM(el) {
		var xPx  = mmToPx(el.x);
		var yPx  = mmToPx(el.y);
		var wPx  = mmToPx(el.width);
		var hPx  = mmToPx(el.height || 50);
		var opacity = (el.opacity !== undefined) ? el.opacity : 1;
		var transforms = [];
		if ( el.rotation ) { transforms.push('rotate(' + el.rotation + 'deg)'); }
		if ( el.flip_h )   { transforms.push('scaleX(-1)'); }
		if ( el.flip_v )   { transforms.push('scaleY(-1)'); }
		var style = [
			'left:'   + xPx + 'px',
			'top:'    + yPx + 'px',
			'width:'  + wPx + 'px',
			'height:' + hPx + 'px',
			'opacity:' + opacity,
			'overflow:hidden',
		];
		if ( transforms.length ) { style.push('transform:' + transforms.join(' ')); }
		if ( el.is_background )  { style.push('z-index:0'); }
		var br = parseFloat(el.border_radius) || 0;
		if ( br > 0 ) { style.push('border-radius:' + mmToPx(br) + 'px'); }
		var inner;
		if ( el.url ) {
			inner = '<img src="' + esc(el.url) + '" style="width:100%;height:100%;object-fit:contain;display:block;" draggable="false">';
		} else if ( el.bg_color ) {
			inner = '<div style="width:100%;height:100%;background:' + esc(el.bg_color) + ';display:block;"></div>';
		} else {
			inner = '<div class="sd-img-placeholder"><span>\uD83D\uDCF7</span></div>';
		}
		return $('<div>')
			.addClass('sd-canvas-element sd-canvas-image')
			.attr('data-id', el.id)
			.html(inner + '<div class="sd-el-resize"></div><div class="sd-el-resize-s"></div><div class="sd-el-resize-se"></div>')
			.attr('style', style.join(';'));
	}

	function renderAllElements() {
		var $canvas = $('#sd-pdf-canvas');
		$canvas.find('.sd-canvas-element').remove();
		state.elements.forEach(function (el) {
			var $el = buildElementDOM(el);
			bindElementEvents($el);
			$canvas.append($el);
		});
		updateSelectionDOM();
	}

	function renderElement(el) {
		var $existing = $('#sd-pdf-canvas').find('[data-id="' + el.id + '"]');
		if ($existing.length) {
			var $new = buildElementDOM(el);
			bindElementEvents($new);
			$existing.replaceWith($new);
		} else {
			var $new2 = buildElementDOM(el);
			bindElementEvents($new2);
			$('#sd-pdf-canvas').append($new2);
		}
		updateSelectionDOM();
	}

	function updateSelectionDOM() {
		$('#sd-pdf-canvas .sd-canvas-element').each(function () {
			$(this).toggleClass('selected', state.selectedIds.indexOf($(this).data('id')) !== -1);
		});
	}

	function getSelectedEls() {
		return state.elements.filter(function (e) { return state.selectedIds.indexOf(e.id) !== -1; });
	}

	// =========================================================================
	// SELEZIONE ELEMENTO
	// =========================================================================

	function updatePropsPanel() {
		var count = state.selectedIds.length;
		if (count === 0) {
			$('#sd-props-empty').show();
			$('#sd-props-form').hide();
			$('#sd-props-image').hide();
			$('#sd-props-multi').hide();
			return;
		}
		if (count === 1) {
			var el = state.elements.find(function (e) { return e.id === state.selectedIds[0]; });
			if (!el) { return; }
			$('#sd-props-empty').hide();
			$('#sd-props-multi').hide();
			if ( 'image' === el.type ) {
				$('#sd-props-form').hide();
				$('#sd-props-image').show();
				$('#sd-img-width').val(el.width);
				$('#sd-img-height').val(el.height || 50);
				$('#sd-img-x').val(el.x);
				$('#sd-img-y').val(el.y);
				$('#sd-img-rotation').val(el.rotation || 0);
				$('#sd-img-flip-h').prop('checked', !!el.flip_h);
				$('#sd-img-flip-v').prop('checked', !!el.flip_v);
				var opPct = Math.round((el.opacity !== undefined ? el.opacity : 1) * 100);
				$('#sd-img-opacity').val(opPct);
				$('#sd-img-opacity-val').text(opPct + '%');
				$('#sd-img-is-bg').prop('checked', !!el.is_background);
				$('#sd-img-border-radius').val(el.border_radius || 0);
				$('#sd-img-bg-color').val(el.bg_color || '#ffffff');
				return;
			}
			$('#sd-props-form').show();
			$('#sd-props-image').hide();
			$('#sd-prop-label').val(el.label);
			$('#sd-prop-label-show').prop('checked', el.label_show);
			$('#sd-prop-label-pos').val(el.label_position || 'above');
			$('#sd-prop-label-pos-wrap').toggle(!!el.label_show);
			$('#sd-prop-custom-text').val(el.custom_text).closest('label').toggle(el.type === 'text_label');
			$('#sd-prop-prefix').val(el.prefix);
			$('#sd-prop-suffix').val(el.suffix);
			$('#sd-prop-width').val(el.width);
			$('#sd-prop-fontsize').val(el.font_size);
			$('#sd-prop-bold').prop('checked', el.font_bold);
			$('#sd-prop-italic').prop('checked', el.font_italic);
			$('#sd-prop-color').val(el.color);
			$('#sd-prop-x').val(el.x);
			$('#sd-prop-y').val(el.y);
			$('#sd-prop-height').val(el.height || 0);
			return;
		}
		// Multi-selezione
		$('#sd-props-empty').hide();
		$('#sd-props-form').hide();
		$('#sd-props-image').hide();
		$('#sd-props-multi').show();
		$('#sd-multi-count').text(count + ' elementi selezionati');
		var first = getSelectedEls()[0];
		if (first) {
			$('#sd-multi-width').val(first.width);
			$('#sd-multi-fontsize').val(first.font_size);
		}
	}

	function selectElement(id) {
		state.selectedIds = id ? [id] : [];
		state.selectedId  = id;
		updateSelectionDOM();
		updatePropsPanel();
	}

	function toggleSelect(id) {
		var idx = state.selectedIds.indexOf(id);
		if (idx === -1) {
			state.selectedIds.push(id);
		} else {
			state.selectedIds.splice(idx, 1);
		}
		state.selectedId = state.selectedIds.length === 1 ? state.selectedIds[0] : null;
		updateSelectionDOM();
		updatePropsPanel();
	}

	function clearAllSelection() {
		selectElement(null);
	}

	// =========================================================================
	// ALLINEAMENTO E DIMENSIONI MULTI-SELEZIONE
	// =========================================================================

	function alignSelected(type) {
		var els = getSelectedEls();
		if (els.length < 2) { return; }
		var $canvas = $('#sd-pdf-canvas');
		function elH(e) { return pxToMm($canvas.find('[data-id="' + e.id + '"]').outerHeight() || 20); }
		var xs  = els.map(function (e) { return e.x; });
		var ys  = els.map(function (e) { return e.y; });
		var x2s = els.map(function (e) { return e.x + e.width; });
		var y2s = els.map(function (e) { return e.y + elH(e); });
		switch (type) {
			case 'left':
				var minX = Math.min.apply(null, xs);
				els.forEach(function (e) { e.x = minX; });
				break;
			case 'right':
				var maxX2 = Math.max.apply(null, x2s);
				els.forEach(function (e) { e.x = maxX2 - e.width; });
				break;
			case 'top':
				var minY = Math.min.apply(null, ys);
				els.forEach(function (e) { e.y = minY; });
				break;
			case 'bottom':
				var maxY2 = Math.max.apply(null, y2s);
				els.forEach(function (e) { e.y = maxY2 - elH(e); });
				break;
			case 'centerH':
				var cxVal = (Math.min.apply(null, xs) + Math.max.apply(null, x2s)) / 2;
				els.forEach(function (e) { e.x = cxVal - e.width / 2; });
				break;
			case 'centerV':
				var cyVal = (Math.min.apply(null, ys) + Math.max.apply(null, y2s)) / 2;
				els.forEach(function (e) { e.y = cyVal - elH(e) / 2; });
				break;
		}
		var dims = getPageDims();
		els.forEach(function (e) {
			e.x = parseFloat(Math.max(0, Math.min(dims.w - e.width, e.x)).toFixed(2));
			e.y = parseFloat(Math.max(0, Math.min(dims.h - 5, e.y)).toFixed(2));
		});
		renderAllElements();
	}

	function applySameWidth(width) {
		width = parseFloat(width) || 0;
		if (width < 5) { return; }
		getSelectedEls().forEach(function (e) { e.width = width; });
		renderAllElements();
	}

	function applySameFontSize(size) {
		size = parseInt(size, 10) || 0;
		if (size < 6) { return; }
		getSelectedEls().forEach(function (e) { e.font_size = size; });
		renderAllElements();
	}

	function updateSelectedFromProps() {
		if (!state.selectedId) { return; }
		var el = state.elements.find(function (e) { return e.id === state.selectedId; });
		if (!el) { return; }
		if ( 'image' === el.type ) {
			el.width        = parseFloat($('#sd-img-width').val())  || 60;
			el.height       = parseFloat($('#sd-img-height').val()) || 50;
			el.x            = parseFloat($('#sd-img-x').val()) || 0;
			el.y            = parseFloat($('#sd-img-y').val()) || 0;
			el.rotation     = parseInt($('#sd-img-rotation').val(), 10) || 0;
			el.flip_h       = $('#sd-img-flip-h').is(':checked');
			el.flip_v       = $('#sd-img-flip-v').is(':checked');
			el.opacity      = parseFloat($('#sd-img-opacity').val()) / 100 || 1.0;
			el.is_background  = $('#sd-img-is-bg').is(':checked');
			el.border_radius  = parseFloat($('#sd-img-border-radius').val()) || 0;
			el.bg_color       = $('#sd-img-bg-color').val() || '';
			renderElement(el);
			return;
		}
		el.label          = $('#sd-prop-label').val();
		el.label_show     = $('#sd-prop-label-show').is(':checked');
		el.label_position = $('#sd-prop-label-pos').val() || 'above';
		$('#sd-prop-label-pos-wrap').toggle(el.label_show);
		el.custom_text    = $('#sd-prop-custom-text').val();
		el.prefix         = $('#sd-prop-prefix').val();
		el.suffix         = $('#sd-prop-suffix').val();
		el.width          = parseFloat($('#sd-prop-width').val()) || 60;
		el.height         = parseFloat($('#sd-prop-height').val()) || 0;
		el.font_size      = parseInt($('#sd-prop-fontsize').val(), 10) || 11;
		el.font_bold      = $('#sd-prop-bold').is(':checked');
		el.font_italic    = $('#sd-prop-italic').is(':checked');
		el.color          = $('#sd-prop-color').val();
		el.x              = parseFloat($('#sd-prop-x').val()) || 0;
		el.y              = parseFloat($('#sd-prop-y').val()) || 0;
		renderElement(el);
	}

	// =========================================================================
	// DRAG DAL CANVAS (mousedown → mousemove → mouseup)
	// =========================================================================

	function bindElementEvents($el) {
		$el.on('mousedown', function (e) {
			if ($(e.target).hasClass('sd-el-resize')) { return; }
			e.preventDefault();
			e.stopPropagation();

			var id = $(this).data('id');

			// Ctrl/Meta → toggle multi-selezione (senza drag)
			if (e.ctrlKey || e.metaKey) {
				toggleSelect(id);
				return;
			}

			var isInMulti = state.selectedIds.length > 1 && state.selectedIds.indexOf(id) !== -1;
			if (!isInMulti) {
				selectElement(id);
			}

			var $canvas    = $('#sd-pdf-canvas');
			var canvasRect = $canvas[0].getBoundingClientRect();
			var dims       = getPageDims();
			state.isDragging = true;

			if (state.selectedIds.length > 1 && state.selectedIds.indexOf(id) !== -1) {
				// MULTI-DRAG: sposta tutti gli elementi selezionati insieme
				var startCX   = e.clientX;
				var startCY   = e.clientY;
				var snapshots = getSelectedEls().map(function (mel) {
					return { id: mel.id, x: mel.x, y: mel.y };
				});
				$(document).on('mousemove.sddrag', function (ev) {
					if (!state.isDragging) { return; }
					var dX = pxToMm(ev.clientX - startCX);
					var dY = pxToMm(ev.clientY - startCY);
					snapshots.forEach(function (snap) {
						var mel = state.elements.find(function (e) { return e.id === snap.id; });
						if (!mel) { return; }
						mel.x = parseFloat(Math.max(0, Math.min(dims.w - mel.width, snap.x + dX)).toFixed(2));
						mel.y = parseFloat(Math.max(0, Math.min(dims.h - 5, snap.y + dY)).toFixed(2));
						$canvas.find('[data-id="' + mel.id + '"]').css({ left: mmToPx(mel.x) + 'px', top: mmToPx(mel.y) + 'px' });
					});
				});
			} else {
				// SINGLE-DRAG
				var el = state.elements.find(function (el) { return el.id === id; });
				if (!el) { state.isDragging = false; return; }
				state.dragOffsetX = e.clientX - canvasRect.left - mmToPx(el.x);
				state.dragOffsetY = e.clientY - canvasRect.top  - mmToPx(el.y);
				$(document).on('mousemove.sddrag', function (ev) {
					if (!state.isDragging) { return; }
					var nx = ev.clientX - canvasRect.left - state.dragOffsetX;
					var ny = ev.clientY - canvasRect.top  - state.dragOffsetY;
					nx = clamp(nx, 0, dims.canvasW - mmToPx(el.width));
					ny = clamp(ny, 0, dims.canvasH - 16);
					el.x = pxToMm(nx);
					el.y = pxToMm(ny);
					$el.css({ left: nx + 'px', top: ny + 'px' });
					$('#sd-prop-x').val(el.x);
					$('#sd-prop-y').val(el.y);
				});
			}

			$(document).on('mouseup.sddrag', function () {
				state.isDragging = false;
				$(document).off('mousemove.sddrag mouseup.sddrag');
			});
		});

		// Resize handle E (larghezza)
		$el.find('.sd-el-resize').on('mousedown', function (e) {
			e.preventDefault();
			e.stopPropagation();

			var id = $el.data('id');
			var el = state.elements.find(function (el) { return el.id === id; });
			if (!el) { return; }

			state.isResizing   = true;
			state.resizeStartX = e.clientX;
			state.resizeStartW = el.width;

			$(document).on('mousemove.sdresize', function (ev) {
				if (!state.isResizing) { return; }
				var deltaX = ev.clientX - state.resizeStartX;
				var newW   = Math.max(10, state.resizeStartW + pxToMm(deltaX));
				el.width   = parseFloat(newW.toFixed(2));
				$el.css('width', mmToPx(el.width) + 'px');
				$('#sd-prop-width').val(el.width);
				$('#sd-img-width').val(el.width);
			});

			$(document).on('mouseup.sdresize', function () {
				state.isResizing = false;
				$(document).off('mousemove.sdresize mouseup.sdresize');
			});
		});

		// Resize handle S (altezza — immagini e campi testo con altezza impostata)
		$el.find('.sd-el-resize-s').on('mousedown', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var id = $el.data('id');
			var el = state.elements.find(function (el) { return el.id === id; });
			if (!el) { return; }
			state.isResizing = true;
			var startY       = e.clientY;
			var startH       = el.height || ( el.type === 'image' ? 50 : 10 );
			$(document).on('mousemove.sdresizeh', function (ev) {
				if (!state.isResizing) { return; }
				var deltaY = ev.clientY - startY;
				el.height  = parseFloat(Math.max(5, startH + pxToMm(deltaY)).toFixed(2));
				$el.css('height', mmToPx(el.height) + 'px');
				if ( el.type === 'image' ) {
					$('#sd-img-height').val(el.height);
				} else {
					$('#sd-prop-height').val(el.height);
				}
			});
			$(document).on('mouseup.sdresizeh', function () {
				state.isResizing = false;
				$(document).off('mousemove.sdresizeh mouseup.sdresizeh');
			});
		});

		// Resize handle SE (larghezza + altezza — solo immagini)
		$el.find('.sd-el-resize-se').on('mousedown', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var id = $el.data('id');
			var el = state.elements.find(function (el) { return el.id === id; });
			if (!el || el.type !== 'image') { return; }
			state.isResizing = true;
			var startX       = e.clientX;
			var startY       = e.clientY;
			var startW       = el.width;
			var startH       = el.height || 50;
			$(document).on('mousemove.sdresizese', function (ev) {
				if (!state.isResizing) { return; }
				el.width  = parseFloat(Math.max(10, startW + pxToMm(ev.clientX - startX)).toFixed(2));
				el.height = parseFloat(Math.max(5,  startH + pxToMm(ev.clientY - startY)).toFixed(2));
				$el.css({ width: mmToPx(el.width) + 'px', height: mmToPx(el.height) + 'px' });
				$('#sd-img-width').val(el.width);
				$('#sd-img-height').val(el.height);
			});
			$(document).on('mouseup.sdresizese', function () {
				state.isResizing = false;
				$(document).off('mousemove.sdresizese mouseup.sdresizese');
			});
		});
	}

	// =========================================================================
	// MEDIA LIBRARY WORDPRESS
	// =========================================================================

	function openMediaLibrary(callback) {
		if ( typeof wp === 'undefined' || ! wp.media ) {
			alert('La Media Library di WordPress non è disponibile in questa pagina.');
			return;
		}
		var frame = wp.media({
			title:    'Seleziona immagine',
			button:   { text: 'Usa questa immagine' },
			multiple: false,
		});
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			// Per i PDF usa la preview generata da WP se disponibile
			var imageUrl = att.url;
			if ( att.mime === 'application/pdf' ) {
				if ( att.sizes && att.sizes.full ) {
					imageUrl = att.sizes.full.url;
				} else if ( att.sizes && att.sizes.large ) {
					imageUrl = att.sizes.large.url;
				} else {
					alert('Nessuna anteprima disponibile per questo PDF. Carica il PDF su WordPress (richiede Ghostscript sul server) oppure converti la pagina in PNG/JPEG prima di caricarla.');
					return;
				}
			}
			att.url = imageUrl;
			callback(att);
		});
		frame.open();
	}

	function addImageElement(att) {
		var dims = getPageDims();
		var el   = defaultElement('image', att.filename || att.title || 'Immagine');
		el.url           = att.url;
		el.attachment_id = att.id || 0;
		// Scala proporzionalmente per stare entro 80mm di larghezza
		var maxWmm = 80;
		var natWmm = ( att.width  || 500 ) / 3.7795;
		var natHmm = ( att.height || 375 ) / 3.7795;
		var scale  = Math.min(1, maxWmm / natWmm);
		el.width  = parseFloat((natWmm * scale).toFixed(2));
		el.height = parseFloat((natHmm * scale).toFixed(2));
		el.x = parseFloat((dims.w / 2 - el.width / 2).toFixed(2));
		el.y = 60;
		addElement(el);
		selectElement(el.id);
	}

	function addBackgroundElement(att) {
		var dims = getPageDims();
		var el   = defaultElement('image', 'Sfondo');
		el.url           = att.url;
		el.attachment_id = att.id || 0;
		el.width         = dims.w;
		el.height        = dims.h;
		el.x             = 0;
		el.y             = 0;
		el.opacity       = 0.5;
		el.is_background = true;
		addElement(el);
		selectElement(el.id);
	}

	// =========================================================================
	// DROP DAL SIDEBAR ONTO CANVAS
	// =========================================================================

	function initCanvasDrop() {
		var $canvas = $('#sd-pdf-canvas');

		// HTML5 DnD
		$canvas.on('dragover', function (e) {
			e.preventDefault();
			$(this).addClass('drag-over');
		});

		$canvas.on('dragleave', function () {
			$(this).removeClass('drag-over');
		});

		$canvas.on('drop', function (e) {
			e.preventDefault();
			$(this).removeClass('drag-over');

			var type  = e.originalEvent.dataTransfer.getData('text/plain');
			var label = e.originalEvent.dataTransfer.getData('application/sd-label');

			if (!type) { return; }

			var canvasRect = $canvas[0].getBoundingClientRect();
			var xPx = e.originalEvent.clientX - canvasRect.left;
			var yPx = e.originalEvent.clientY - canvasRect.top;

			var el    = defaultElement(type, label);
			el.x      = pxToMm(clamp(xPx - mmToPx(el.width / 2), 0, canvasRect.width - mmToPx(el.width)));
			el.y      = pxToMm(clamp(yPx - 12, 0, canvasRect.height - 20));

			addElement(el);
			selectElement(el.id);
		});
	}

	// =========================================================================
	// CHIP DRAG FROM SIDEBAR
	// =========================================================================

	function initChipDrag() {
		$(document).on('dragstart', '.sd-field-chip', function (e) {
			var type  = $(this).data('type');
			var label = $(this).data('label') || type;
			e.originalEvent.dataTransfer.setData('text/plain', type);
			e.originalEvent.dataTransfer.setData('application/sd-label', label);
			e.originalEvent.dataTransfer.effectAllowed = 'copy';
		});

		// Doppio click → aggiunge al centro del canvas
		$(document).on('dblclick', '.sd-field-chip', function () {
			var type  = $(this).data('type');
			var label = $(this).data('label') || type;
			var dims  = getPageDims();
			var el    = defaultElement(type, label);
			el.x = parseFloat((dims.w / 2 - el.width / 2).toFixed(2));
			el.y = 60;
			addElement(el);
			selectElement(el.id);
		});
	}

	// =========================================================================
	// GESTIONE LISTA ELEMENTI
	// =========================================================================

	function addElement(el) {
		state.elements.push(el);
		renderElement(el);
	}

	function removeElement(id) {
		state.elements = state.elements.filter(function (el) { return el.id !== id; });
		$('#sd-pdf-canvas').find('[data-id="' + id + '"]').remove();
		var idx = state.selectedIds.indexOf(id);
		if (idx !== -1) { state.selectedIds.splice(idx, 1); }
		if (state.selectedId === id) { state.selectedId = null; }
		updatePropsPanel();
	}

	// =========================================================================
	// CANVAS CLICK / RUBBER-BAND SELECTION
	// =========================================================================

	function initCanvasClick() {
		var $canvas = $('#sd-pdf-canvas');
		$canvas.on('mousedown', function (e) {
			if (!$(e.target).is('#sd-pdf-canvas')) { return; }
			if (e.ctrlKey || e.metaKey) { return; }

			var canvasRect = $canvas[0].getBoundingClientRect();
			var startX     = e.clientX - canvasRect.left;
			var startY     = e.clientY - canvasRect.top;

			clearAllSelection();
			state.isBoxSelecting = true;

			var $box = $('<div id="sd-box-select"></div>').appendTo($canvas);
			$box.css({ left: startX + 'px', top: startY + 'px', width: 0, height: 0 });

			$(document).on('mousemove.sdbox', function (ev) {
				if (!state.isBoxSelecting) { return; }
				var cx = ev.clientX - canvasRect.left;
				var cy = ev.clientY - canvasRect.top;
				$box.css({
					left:   Math.min(cx, startX) + 'px',
					top:    Math.min(cy, startY) + 'px',
					width:  Math.abs(cx - startX) + 'px',
					height: Math.abs(cy - startY) + 'px',
				});
			});

			$(document).on('mouseup.sdbox', function (ev) {
				if (!state.isBoxSelecting) { return; }
				state.isBoxSelecting = false;
				$(document).off('mousemove.sdbox mouseup.sdbox');

				var cx    = ev.clientX - canvasRect.left;
				var cy    = ev.clientY - canvasRect.top;
				var selX1 = Math.min(cx, startX);
				var selY1 = Math.min(cy, startY);
				var selX2 = Math.max(cx, startX);
				var selY2 = Math.max(cy, startY);
				$box.remove();

				// Click semplice (senza trascinamento) → deseleziona già fatto
				if (selX2 - selX1 < 5 && selY2 - selY1 < 5) { return; }

				// Trova tutti gli elementi sovrapposti al rettangolo
				var selIds = [];
				state.elements.forEach(function (el) {
					var elX1 = mmToPx(el.x);
					var elY1 = mmToPx(el.y);
					var elX2 = elX1 + mmToPx(el.width);
					var $dom = $canvas.find('[data-id="' + el.id + '"]');
					var elY2 = elY1 + ($dom.length ? $dom.outerHeight() : 20);
					if (elX1 < selX2 && elX2 > selX1 && elY1 < selY2 && elY2 > selY1) {
						selIds.push(el.id);
					}
				});

				if (selIds.length > 0) {
					state.selectedIds = selIds;
					state.selectedId  = selIds.length === 1 ? selIds[0] : null;
					updateSelectionDOM();
					updatePropsPanel();
				}
			});
		});
	}

	// =========================================================================
	// ORIENTAMENTO
	// =========================================================================

	function getOrientationLabel(o) {
		var labels = {
			'portrait_hf':  'A4 Verticale + Intestazione',
			'landscape_hf': 'A4 Orizzontale + Intestazione',
			'portrait':     'A4 Verticale',
			'landscape':    'A4 Orizzontale',
			'credit_card':  'Tessera (85.6×54mm)',
		};
		return labels[o] || o;
	}

	function applyOrientation(orientation) {
		state.orientation = orientation;
		var $c = $('#sd-pdf-canvas');
		var isLandscape = (orientation === 'landscape' || orientation === 'landscape_hf');
		var isBranded   = (orientation === 'portrait_hf' || orientation === 'landscape_hf');
		if (isLandscape) {
			$c.addClass('landscape');
		} else {
			$c.removeClass('landscape');
		}
		$('#sd-layout-panel').toggle(isBranded);
		state.layout.style = isBranded ? 'branded' : 'plain';
	}

	// =========================================================================
	// LAYOUT BRANDED (intestazione / piè di pagina)
	// =========================================================================

	function getDefaultLayout() {
		return {
			style:              'branded',
			header_title:       '',
			header_subtitle:    '',
			header_bg:          '#0055A5',
			accent_bg:          '#00A3D8',
			logo_url:           '',
			logo_attachment_id: 0,
			show_page_numbers:  true,
			show_date:          true,
			footer_note:        '',
		};
	}

	function applyLayoutToUI(layout) {
		$('#sd-layout-title').val(layout.header_title || '');
		$('#sd-layout-subtitle').val(layout.header_subtitle || '');
		$('#sd-layout-header-bg').val(layout.header_bg || '#0055A5');
		$('#sd-layout-accent-bg').val(layout.accent_bg || '#00A3D8');
		$('#sd-layout-logo-url').val(layout.logo_url || '');
		$('#sd-layout-logo-att').val(layout.logo_attachment_id || 0);
		$('#sd-layout-show-page-num').prop('checked', layout.show_page_numbers !== false);
		$('#sd-layout-show-date').prop('checked', layout.show_date !== false);
	}

	function readLayoutFromUI() {
		state.layout.header_title       = $('#sd-layout-title').val();
		state.layout.header_subtitle    = $('#sd-layout-subtitle').val();
		state.layout.header_bg          = $('#sd-layout-header-bg').val();
		state.layout.accent_bg          = $('#sd-layout-accent-bg').val();
		state.layout.logo_url           = $('#sd-layout-logo-url').val();
		state.layout.logo_attachment_id = parseInt($('#sd-layout-logo-att').val(), 10) || 0;
		state.layout.show_page_numbers  = $('#sd-layout-show-page-num').is(':checked');
		state.layout.show_date          = $('#sd-layout-show-date').is(':checked');
	}

	// =========================================================================
	// AJAX: SALVA TEMPLATE
	// =========================================================================

	function saveTemplate() {
		var name = $('#sd-tpl-name').val().trim();
		if (!name) {
			showStatus('Inserisci un nome per il template.', 'error');
			return;
		}
		readLayoutFromUI();

		$.post(sdPdfDesigner.ajaxUrl, {
			action:          'sd_pdf_tpl_save',
			nonce:           sdPdfDesigner.nonce,
			template_id:     state.templateId,
			name:            name,
			orientation:     state.orientation,
			template_type:   state.templateType,
			activity_id:     state.activityId,
			elements_json:   JSON.stringify(state.elements),
			layout_json:     JSON.stringify(state.layout),
		}, function (resp) {
			if (resp.success) {
				state.templateId = resp.data.template_id;
				showStatus(resp.data.message, 'ok');
			} else {
				showStatus(resp.data.message || 'Errore salvataggio.', 'error');
			}
		}).fail(function () {
			showStatus('Errore di rete.', 'error');
		});
	}

	// =========================================================================
	// AJAX: LISTA TEMPLATE → MODAL
	// =========================================================================

	function openLoadModal() {
		$.post(sdPdfDesigner.ajaxUrl, {
			action: 'sd_pdf_tpl_list',
			nonce:  sdPdfDesigner.nonce,
		}, function (resp) {
			if (!resp.success) {
				showStatus(resp.data.message || 'Errore caricamento lista.', 'error');
				return;
			}
			var rows = resp.data.templates;
			var html = '';
			if (!rows.length) {
				html = '<tr><td colspan="4" style="color:#999;text-align:center;">Nessun template salvato.</td></tr>';
			} else {
				rows.forEach(function (t) {
					html += '<tr>';
					html += '<td>' + esc(t.name) + '</td>';
					html += '<td>' + esc(getOrientationLabel(t.orientation)) + '</td>';
					html += '<td>' + esc((t.updated_at || '').substring(0, 16)) + '</td>';
					html += '<td><button class="sd-pdf-btn sd-pdf-btn-primary sd-btn-tpl-load" data-id="' + esc(t.id) + '">Carica</button></td>';
					html += '</tr>';
				});
			}
			$('#sd-tpl-modal-rows').html(html);
			$('#sd-tpl-modal').show();
		}).fail(function () {
			showStatus('Errore di rete.', 'error');
		});
	}

	// =========================================================================
	// AJAX: CARICA TEMPLATE
	// =========================================================================

	function loadTemplate(id) {
		$.post(sdPdfDesigner.ajaxUrl, {
			action:      'sd_pdf_tpl_load',
			nonce:       sdPdfDesigner.nonce,
			template_id: id,
		}, function (resp) {
			if (!resp.success) {
				showStatus(resp.data.message || 'Errore caricamento.', 'error');
				return;
			}
			var tpl = resp.data.template;
			state.templateId   = tpl.id;
			state.orientation  = tpl.orientation || 'portrait_hf';
			state.templateType = tpl.template_type || 'activity';
			state.elements     = tpl.elements || [];
			state.activityId   = parseInt(tpl.activity_id, 10) || 0;
			if (tpl.layout && typeof tpl.layout === 'object') {
				state.layout = $.extend({}, getDefaultLayout(), tpl.layout);
			} else {
				state.layout = getDefaultLayout();
			}
			// Ricalcola nextId
			state.nextId = 1;
			state.elements.forEach(function (el) {
				var num = parseInt((el.id || '').replace('el_', ''), 10);
				if (num >= state.nextId) { state.nextId = num + 1; }
			});

			$('#sd-tpl-name').val(tpl.name);
			$('#sd-tpl-orientation').val(state.orientation);
			applyOrientation(state.orientation);
			applyLayoutToUI(state.layout);
			applyTemplateType(state.templateType);
			if (state.activityId > 0) {
				$('#sd-activity-select').val(state.activityId);
				loadDynamicFields(state.activityId);
			}
			renderAllElements();
			selectElement(null);
			$('#sd-tpl-modal').hide();
			showStatus('Template "' + tpl.name + '" caricato.', 'ok');
		}).fail(function () {
			showStatus('Errore di rete.', 'error');
		});
	}

	// =========================================================================
	// AJAX: ELIMINA TEMPLATE
	// =========================================================================

	function deleteTemplate() {
		if (!state.templateId) {
			showStatus('Nessun template da eliminare (non ancora salvato).', 'error');
			return;
		}
		if (!window.confirm('Eliminare questo template?')) { return; }

		$.post(sdPdfDesigner.ajaxUrl, {
			action:      'sd_pdf_tpl_delete',
			nonce:       sdPdfDesigner.nonce,
			template_id: state.templateId,
		}, function (resp) {
			if (resp.success) {
				resetDesigner();
				showStatus('Template eliminato.', 'ok');
			} else {
				showStatus(resp.data.message || 'Errore eliminazione.', 'error');
			}
		}).fail(function () {
			showStatus('Errore di rete.', 'error');
		});
	}

	// =========================================================================
	// AJAX: CAMPI DINAMICI
	// =========================================================================

	function loadDynamicFields(activityId) {
		if (!activityId) {
			state.dynamicFields = {};
			$('#sd-dyn-fields-section').hide();
			$('#sd-fields-dynamic').empty();
			return;
		}

		$.post(sdPdfDesigner.ajaxUrl, {
			action:      'sd_pdf_tpl_fields',
			nonce:       sdPdfDesigner.nonce,
			activity_id: activityId,
		}, function (resp) {
			if (!resp.success) { return; }
			state.dynamicFields = resp.data.dynamic_fields || {};
			var $list = $('#sd-fields-dynamic').empty();
			var hasFields = false;
			$.each(state.dynamicFields, function (key, label) {
				hasFields = true;
				$list.append(
					$('<div>')
						.addClass('sd-field-chip')
						.attr({ 'data-type': key, 'data-label': label, draggable: true })
						.html('<span class="sd-chip-icon">📝</span> ' + esc(label))
				);
			});
			$('#sd-dyn-fields-section').toggle(hasFields);
			// Aggiorna preview degli elementi già sul canvas
			renderAllElements();
		});
	}

	// =========================================================================
	// AJAX: GENERA PDF (anteprima)
	// =========================================================================

	function generatePreview() {
		if (!state.templateId) {
			showStatus('Salva prima il template.', 'error');
			return;
		}
		if (state.templateType === 'member') {
			downloadPdf({
				action:       'sd_pdf_tpl_gen_member',
				nonce:        sdPdfDesigner.nonce,
				template_id:  state.templateId,
				member_id:    0,
			}, 'anteprima_socio.pdf');
		} else {
			downloadPdf({
				action:          'sd_pdf_tpl_generate',
				nonce:           sdPdfDesigner.nonce,
				template_id:     state.templateId,
				activity_id:     state.activityId,
				registration_id: 0,
			}, 'anteprima_template.pdf');
		}
	}

	// =========================================================================
	// AJAX: GENERA PDF (tutti)
	// =========================================================================

	function generateAll() {
		if (!state.templateId) {
			showStatus('Salva prima il template.', 'error');
			return;
		}
		if (state.templateType === 'member') {
			downloadPdf({
				action:      'sd_pdf_tpl_gen_all_members',
				nonce:       sdPdfDesigner.nonce,
				template_id: state.templateId,
				filter_type: 'all',
			}, 'soci_template.pdf');
		} else {
			if (!state.activityId) {
				showStatus('Seleziona un\'attività prima di generare i PDF.', 'error');
				return;
			}
			downloadPdf({
				action:      'sd_pdf_tpl_gen_all',
				nonce:       sdPdfDesigner.nonce,
				template_id: state.templateId,
				activity_id: state.activityId,
			}, 'iscrizioni_template.pdf');
		}
	}

	// =========================================================================
	// HELPER: DOWNLOAD PDF BLOB
	// =========================================================================

	function downloadPdf(postData, fallbackFilename) {
		showStatus('Generazione PDF in corso…', 'info');
		var xhr = new XMLHttpRequest();
		xhr.open('POST', sdPdfDesigner.ajaxUrl, true);
		xhr.responseType = 'blob';
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.onload = function () {
			var contentType = xhr.getResponseHeader('Content-Type') || '';
			if (xhr.status === 200 && contentType.indexOf('application/pdf') !== -1) {
				var url  = URL.createObjectURL(xhr.response);
				var link = document.createElement('a');
				link.href     = url;
				link.download = fallbackFilename;
				link.click();
				URL.revokeObjectURL(url);
				showStatus('PDF generato.', 'ok');
			} else {
				// JSON error
				var reader = new FileReader();
				reader.onload = function () {
					try {
						var json = JSON.parse(reader.result);
						showStatus((json.data && json.data.message) || 'Errore generazione PDF.', 'error');
					} catch (err) {
						showStatus('Errore generazione PDF.', 'error');
					}
				};
				reader.readAsText(xhr.response);
			}
		};
		xhr.onerror = function () { showStatus('Errore di rete.', 'error'); };

		// Serializza postData
		var params = [];
		for (var k in postData) {
			if (Object.prototype.hasOwnProperty.call(postData, k)) {
				params.push(encodeURIComponent(k) + '=' + encodeURIComponent(postData[k]));
			}
		}
		xhr.send(params.join('&'));
	}

	// =========================================================================
	// RESET DESIGNER
	// =========================================================================

	function resetDesigner() {
		state.templateId   = 0;
		state.templateType = 'activity';
		state.elements     = [];
		state.selectedId   = null;
		state.selectedIds  = [];
		state.activityId   = 0;
		state.nextId       = 1;
		state.orientation  = 'portrait_hf';
		state.layout       = getDefaultLayout();
		$('#sd-tpl-name').val('');
		$('#sd-tpl-orientation').val('portrait_hf');
		applyOrientation('portrait_hf');
		applyLayoutToUI(state.layout);
		applyTemplateType('activity');
		renderAllElements();
		selectElement(null);
	}

	// =========================================================================
	// SHORTCUTS TASTIERA (frecce per micro-spostamenti)
	// =========================================================================

	function initKeyboard() {
		$(document).on('keydown', function (e) {
			if (state.selectedIds.length === 0) { return; }
			if ($(e.target).is('input, textarea, select')) { return; }

			// DEL: elimina tutti gli elementi selezionati
			if (e.which === 46) {
				var toDelete = state.selectedIds.slice();
				toDelete.forEach(function (id) { removeElement(id); });
				return;
			}

			var els  = getSelectedEls();
			if (!els.length) { return; }
			var step  = e.shiftKey ? 0.5 : 2;
			var moved = false;
			var dims  = getPageDims();

			switch (e.which) {
				case 37: els.forEach(function (el) { el.x = Math.max(0, el.x - step); }); moved = true; break;
				case 39: els.forEach(function (el) { el.x = Math.min(dims.w - el.width, el.x + step); }); moved = true; break;
				case 38: els.forEach(function (el) { el.y = Math.max(0, el.y - step); }); moved = true; break;
				case 40: els.forEach(function (el) { el.y = Math.min(dims.h - 5, el.y + step); }); moved = true; break;
			}

			if (moved) {
				e.preventDefault();
				els.forEach(function (el) {
					el.x = parseFloat(el.x.toFixed(2));
					el.y = parseFloat(el.y.toFixed(2));
					$('#sd-pdf-canvas').find('[data-id="' + el.id + '"]').css({ left: mmToPx(el.x) + 'px', top: mmToPx(el.y) + 'px' });
				});
				if (state.selectedIds.length === 1) {
					$('#sd-prop-x').val(els[0].x);
					$('#sd-prop-y').val(els[0].y);
				}
			}
		});
	}

	// =========================================================================
	// TIPO TEMPLATE (activity / member)
	// =========================================================================

	function applyTemplateType(type) {
		state.templateType = type || 'activity';
		$('.sd-pdf-type-btn').removeClass('is-active');
		$('.sd-pdf-type-btn[data-type="' + state.templateType + '"]').addClass('is-active');
		if (state.templateType === 'member') {
			$('#sd-sections-activity').hide();
			$('#sd-sections-member').show();
		} else {
			$('#sd-sections-activity').show();
			$('#sd-sections-member').hide();
		}
	}

	// =========================================================================

	$(function () {
		// Canvas drop
		initCanvasDrop();
		// Chip drag
		initChipDrag();
		// Canvas click deselect
		initCanvasClick();
		// Keyboard
		initKeyboard();

		// Bottoni toolbar
		$('#sd-tpl-btn-new').on('click', function () {
			if (state.elements.length > 0) {
				if (!window.confirm('Creare un nuovo template? Le modifiche non salvate andranno perse.')) { return; }
			}
			resetDesigner();
		});

		// Tipo template (Attività / Soci)
		$(document).on('click', '.sd-pdf-type-btn', function () {
			applyTemplateType($(this).data('type'));
		});

		$('#sd-tpl-btn-save').on('click', saveTemplate);

		$('#sd-tpl-btn-load').on('click', openLoadModal);

		$('#sd-tpl-btn-delete').on('click', deleteTemplate);

		$('#sd-tpl-btn-preview').on('click', generatePreview);

		$('#sd-tpl-btn-gen-all').on('click', generateAll);

		// Carica template dal modal
		$(document).on('click', '.sd-btn-tpl-load', function () {
			var id = parseInt($(this).data('id'), 10);
			loadTemplate(id);
		});

		// Chiudi modal
		$('#sd-tpl-modal-close').on('click', function () { $('#sd-tpl-modal').hide(); });
		$('#sd-tpl-modal').on('click', function (e) {
			if ($(e.target).is('#sd-tpl-modal')) { $('#sd-tpl-modal').hide(); }
		});

		// Orientamento
		$('#sd-tpl-orientation').on('change', function () {
			applyOrientation($(this).val());
		});

		// Layout panel: sincronizza state.layout su ogni modifica
		$('#sd-layout-panel').on('input change', 'input', function () {
			readLayoutFromUI();
		});

		// Media library per logo intestazione
		$('#sd-layout-logo-btn').on('click', function () {
			if (typeof wp === 'undefined' || !wp.media) { return; }
			var frame = wp.media({ title: 'Scegli logo', button: { text: 'Usa immagine' }, multiple: false });
			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				$('#sd-layout-logo-url').val(att.url);
				$('#sd-layout-logo-att').val(att.id);
				readLayoutFromUI();
			});
			frame.open();
		});

		// Attività select
		$('#sd-activity-select').on('change', function () {
			state.activityId = parseInt($(this).val(), 10) || 0;
			loadDynamicFields(state.activityId);
		});

		// Pannello props: aggiorna su change
		$('#sd-props-form').on('input change', 'input, select', function () {
			updateSelectedFromProps();
		});

		// Rimuovi elemento selezionato
		$('#sd-prop-delete').on('click', function () {
			if (state.selectedId) { removeElement(state.selectedId); }
		});

		// Multi-selezione: allineamento
		$(document).on('click', '.sd-align-btn', function () {
			alignSelected($(this).data('align'));
		});

		// Multi-selezione: stessa larghezza
		$('#sd-multi-apply-width').on('click', function () {
			applySameWidth($('#sd-multi-width').val());
		});

		// Multi-selezione: stesso font
		$('#sd-multi-apply-fontsize').on('click', function () {
			applySameFontSize($('#sd-multi-fontsize').val());
		});

		// Multi-selezione: deseleziona tutti
		$('#sd-multi-deselect').on('click', function () {
			clearAllSelection();
		});

		// Multi-selezione: elimina selezionati
		$('#sd-multi-delete').on('click', function () {
			var toDelete = state.selectedIds.slice();
			toDelete.forEach(function (id) { removeElement(id); });
		});

		// ===== IMMAGINI =====

		// Chip "Aggiungi immagine" (sidebar)
		$('#sd-chip-image').on('click', function () {
			openMediaLibrary(function (att) { addImageElement(att); });
		});

		// Chip "Imposta sfondo" (sidebar)
		$('#sd-chip-background').on('click', function () {
			openMediaLibrary(function (att) { addBackgroundElement(att); });
		});

		// Pannello immagine: aggiorna props su change
		$('#sd-props-image').on('input change', 'input, select', function () {
			updateSelectedFromProps();
		});

		// Bottone "Cambia immagine"
		$('#sd-img-change').on('click', function () {
			openMediaLibrary(function (att) {
				var el = state.elements.find(function (e) { return e.id === state.selectedId; });
				if (!el || el.type !== 'image') { return; }
				el.url           = att.url;
				el.attachment_id = att.id || 0;
				renderElement(el);
			});
		});

		// Bottone "Adatta a pagina intera"
		$('#sd-img-fullpage').on('click', function () {
			var el = state.elements.find(function (e) { return e.id === state.selectedId; });
			if (!el || el.type !== 'image') { return; }
			var dims = getPageDims();
			el.x      = 0;
			el.y      = 0;
			el.width  = dims.w;
			el.height = dims.h;
			renderElement(el);
			updatePropsPanel();
		});

		// Bottone "Rimuovi immagine"
		$('#sd-img-delete').on('click', function () {
			if (state.selectedId) { removeElement(state.selectedId); }
		});

		// Slider opacità immagine: aggiorna etichetta in tempo reale
		$('#sd-img-opacity').on('input', function () {
			$('#sd-img-opacity-val').text($(this).val() + '%');
		});
	});

})(jQuery);
