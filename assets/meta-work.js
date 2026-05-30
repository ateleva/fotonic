/* global FtncMetaData, wp */
/**
 * Fotonic — Work Details meta box repeaters
 *
 * Handles: Addresses, Services, Files (wp.media), Installments, Color palette.
 * Collaborators are handled by the Pro addon.
 *
 * Localized via wp_localize_script( 'fotonic-meta-work', 'FtncMetaData', {...} )
 */
(function() {
	var data = window.FtncMetaData && window.FtncMetaData.work ? window.FtncMetaData.work : {};

	var workServices   = data.services      || [];
	var workFiles      = data.files         || [];
	var installments   = data.installments  || [];
	var eventAddresses = data.addresses     || [];
	var servicesMap    = data.servicesMap   || {};

	var i18n = {
		addrPlaceholderLabel:  data.i18n_addr_label   || 'e.g. Church',
		addrPlaceholderStreet: data.i18n_addr_street  || 'Via Roma 1, Milano',
		remove:                data.i18n_remove        || 'Remove',
		noMediaLib:            data.i18n_no_media      || 'Media library not available.',
		mediaTitle:            data.i18n_media_title   || 'Select Files',
		mediaButton:           data.i18n_media_button  || 'Add to Work',
		attachmentId:          data.i18n_attach_id     || 'Attachment ID',
		paid:                  data.i18n_paid          || 'Paid',
		unpaid:                data.i18n_unpaid        || 'Unpaid',
		coupon:                data.i18n_coupon        || 'Coupon',
		defaultType:           data.i18n_default_type  || 'Default',
	};

	function esc( v ) {
		if ( ! v && v !== 0 ) { return ''; }
		return String( v ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	function syncServices()     { document.getElementById( 'ftnc_work_services_json' ).value = JSON.stringify( workServices ); }
	function syncFiles()        { document.getElementById( 'ftnc_work_files_json' ).value = JSON.stringify( workFiles ); }
	function syncInstallments() { document.getElementById( 'ftnc_installments_json' ).value = JSON.stringify( installments ); }
	function syncAddresses()    { document.getElementById( 'ftnc_event_addresses_json' ).value = JSON.stringify( eventAddresses ); }

	// -------------------------------------------------------------------------
	// Addresses repeater
	// -------------------------------------------------------------------------
	function renderAddresses() {
		var tbody = document.getElementById( 'ftnc-addresses-rows' );
		tbody.innerHTML = '';
		eventAddresses.forEach( function( addr, i ) {
			var tr = document.createElement( 'tr' );
			tr.innerHTML =
				'<td><input type="text" value="' + esc( addr.label ) + '" data-addrfield="label" data-addridx="' + i + '" class="regular-text" placeholder="' + esc( i18n.addrPlaceholderLabel ) + '"></td>' +
				'<td><input type="text" value="' + esc( addr.street ) + '" data-addrfield="street" data-addridx="' + i + '" class="large-text" placeholder="' + esc( i18n.addrPlaceholderStreet ) + '"></td>' +
				'<td><button type="button" class="button-link ftnc-remove-address" data-addridx="' + i + '" style="color:#a00">' + esc( i18n.remove ) + '</button></td>';
			tbody.appendChild( tr );
		} );
		document.querySelectorAll( '#ftnc-addresses-rows input[data-addrfield]' ).forEach( function( el ) {
			el.addEventListener( 'input', function() {
				var idx   = parseInt( this.dataset.addridx, 10 );
				var field = this.dataset.addrfield;
				eventAddresses[ idx ][ field ] = this.value;
				syncAddresses();
			} );
		} );
		document.querySelectorAll( '.ftnc-remove-address' ).forEach( function( el ) {
			el.addEventListener( 'click', function() {
				eventAddresses.splice( parseInt( this.dataset.addridx, 10 ), 1 );
				renderAddresses();
				syncAddresses();
			} );
		} );
	}

	var addAddrBtn = document.getElementById( 'ftnc-add-address' );
	if ( addAddrBtn ) {
		addAddrBtn.addEventListener( 'click', function() {
			eventAddresses.push( { label: '', street: '' } );
			renderAddresses();
			syncAddresses();
		} );
	}

	// -------------------------------------------------------------------------
	// Services repeater
	// -------------------------------------------------------------------------
	function renderServices() {
		var tbody = document.getElementById( 'ftnc-services-rows' );
		tbody.innerHTML = '';
		workServices.forEach( function( s, i ) {
			var title = servicesMap[ s.service_id ] ? esc( servicesMap[ s.service_id ].title ) : '(#' + s.service_id + ')';
			var tr = document.createElement( 'tr' );
			tr.innerHTML =
				'<td><strong>' + title + '</strong></td>' +
				'<td><input type="number" step="0.01" min="0" value="' + esc( s.price_override ) + '" data-field="price_override" data-idx="' + i + '" class="small-text"></td>' +
				'<td><input type="text" value="' + esc( s.notes_override ) + '" data-field="notes_override" data-idx="' + i + '" class="regular-text"></td>' +
				'<td><button type="button" class="button-link ftnc-remove-service" data-idx="' + i + '" style="color:#a00">' + esc( i18n.remove ) + '</button></td>';
			tbody.appendChild( tr );
		} );
		attachServiceListeners();
	}

	function attachServiceListeners() {
		document.querySelectorAll( '#ftnc-services-rows input[data-field]' ).forEach( function( el ) {
			el.addEventListener( 'input', function() {
				var idx   = parseInt( this.dataset.idx, 10 );
				var field = this.dataset.field;
				workServices[ idx ][ field ] = field === 'price_override' ? parseFloat( this.value ) || 0 : this.value;
				syncServices();
			} );
		} );
		document.querySelectorAll( '.ftnc-remove-service' ).forEach( function( el ) {
			el.addEventListener( 'click', function() {
				workServices.splice( parseInt( this.dataset.idx, 10 ), 1 );
				renderServices();
				syncServices();
			} );
		} );
	}

	var addServiceBtn = document.getElementById( 'ftnc-add-service' );
	if ( addServiceBtn ) {
		addServiceBtn.addEventListener( 'click', function() {
			var picker    = document.getElementById( 'ftnc-service-picker' );
			var id        = parseInt( picker.value, 10 );
			if ( ! id ) { return; }
			var basePrice = servicesMap[ id ] ? servicesMap[ id ].base_price : 0;
			workServices.push( { service_id: id, price_override: basePrice, notes_override: '' } );
			renderServices();
			syncServices();
			picker.value = '';
		} );
	}

	// -------------------------------------------------------------------------
	// Files (wp.media)
	// -------------------------------------------------------------------------
	function renderFiles() {
		var container = document.getElementById( 'ftnc-files-list' );
		container.innerHTML = '';
		workFiles.forEach( function( attachId, i ) {
			var div = document.createElement( 'div' );
			div.className = 'ftnc-file-row';
			div.innerHTML = '📎 ' + esc( i18n.attachmentId ) + ': <strong>' + esc( attachId ) + '</strong> ' +
				'<button type="button" class="button-link ftnc-remove-file" data-idx="' + i + '" style="color:#a00">' + esc( i18n.remove ) + '</button>';
			container.appendChild( div );
		} );
		document.querySelectorAll( '.ftnc-remove-file' ).forEach( function( el ) {
			el.addEventListener( 'click', function() {
				workFiles.splice( parseInt( this.dataset.idx, 10 ), 1 );
				renderFiles();
				syncFiles();
			} );
		} );
	}

	var addFileBtn = document.getElementById( 'ftnc-add-file' );
	if ( addFileBtn ) {
		addFileBtn.addEventListener( 'click', function() {
			if ( typeof wp === 'undefined' || ! wp.media ) {
				alert( i18n.noMediaLib );
				return;
			}
			var frame = wp.media( {
				title:    i18n.mediaTitle,
				button:   { text: i18n.mediaButton },
				multiple: true,
			} );
			frame.on( 'select', function() {
				var selection = frame.state().get( 'selection' );
				selection.each( function( attachment ) {
					if ( workFiles.indexOf( attachment.id ) === -1 ) {
						workFiles.push( attachment.id );
					}
				} );
				renderFiles();
				syncFiles();
			} );
			frame.open();
		} );
	}

	// -------------------------------------------------------------------------
	// Installments repeater
	// -------------------------------------------------------------------------
	function renderInstallments() {
		var tbody = document.getElementById( 'ftnc-installments-rows' );
		tbody.innerHTML = '';
		installments.forEach( function( inst, i ) {
			var isPaid       = inst.status === 'paid';
			var btnClass     = isPaid ? 'ftnc-status-paid' : 'ftnc-status-unpaid';
			var btnLabel     = isPaid ? i18n.paid : i18n.unpaid;
			var instType     = inst.type === 'coupon' ? 'coupon' : 'default';
			var typeLabel    = instType === 'coupon' ? i18n.coupon : i18n.defaultType;
			var typeBtnClass = instType === 'coupon' ? 'ftnc-type-coupon' : 'ftnc-type-default';
			var tr = document.createElement( 'tr' );
			tr.innerHTML =
				'<td><button type="button" class="ftnc-type-toggle ' + typeBtnClass + '" data-idx="' + i + '" style="border-radius:12px;padding:3px 10px;font-size:12px;border:none;cursor:pointer;">' + esc( typeLabel ) + '</button></td>' +
				'<td><input type="text" value="' + esc( inst.title ) + '" data-field="title" data-idx="' + i + '" class="regular-text"></td>' +
				'<td><input type="number" step="0.01" min="0" value="' + esc( inst.amount ) + '" data-field="amount" data-idx="' + i + '" class="small-text"></td>' +
				'<td><button type="button" class="ftnc-status-toggle ' + btnClass + '" data-idx="' + i + '">' + esc( btnLabel ) + '</button></td>' +
				'<td><button type="button" class="button-link ftnc-remove-installment" data-idx="' + i + '" style="color:#a00">' + esc( i18n.remove ) + '</button></td>';
			tbody.appendChild( tr );
		} );
		attachInstallmentListeners();
	}

	function attachInstallmentListeners() {
		document.querySelectorAll( '#ftnc-installments-rows input[data-field]' ).forEach( function( el ) {
			el.addEventListener( 'input', function() {
				var idx   = parseInt( this.dataset.idx, 10 );
				var field = this.dataset.field;
				installments[ idx ][ field ] = field === 'amount' ? parseFloat( this.value ) || 0 : this.value;
				syncInstallments();
			} );
		} );
		document.querySelectorAll( '.ftnc-status-toggle' ).forEach( function( el ) {
			el.addEventListener( 'click', function() {
				var idx     = parseInt( this.dataset.idx, 10 );
				var current = installments[ idx ].status;
				installments[ idx ].status = current === 'paid' ? 'unpaid' : 'paid';
				renderInstallments();
				syncInstallments();
			} );
		} );
		document.querySelectorAll( '.ftnc-type-toggle' ).forEach( function( el ) {
			el.addEventListener( 'click', function() {
				var idx = parseInt( this.dataset.idx, 10 );
				installments[ idx ].type = installments[ idx ].type === 'coupon' ? 'default' : 'coupon';
				renderInstallments();
				syncInstallments();
			} );
		} );
		document.querySelectorAll( '.ftnc-remove-installment' ).forEach( function( el ) {
			el.addEventListener( 'click', function() {
				installments.splice( parseInt( this.dataset.idx, 10 ), 1 );
				renderInstallments();
				syncInstallments();
			} );
		} );
	}

	var addInstBtn = document.getElementById( 'ftnc-add-installment' );
	if ( addInstBtn ) {
		addInstBtn.addEventListener( 'click', function() {
			installments.push( { title: '', amount: 0, status: 'unpaid', type: 'default' } );
			renderInstallments();
			syncInstallments();
		} );
	}

	// -------------------------------------------------------------------------
	// Color palette
	// -------------------------------------------------------------------------
	document.querySelectorAll( '.ftnc-color-swatch input[type=radio]' ).forEach( function( radio ) {
		radio.addEventListener( 'change', function() {
			document.querySelectorAll( '.ftnc-color-circle' ).forEach( function( c ) { c.textContent = ''; } );
			var circle = this.closest( '.ftnc-color-swatch' ).querySelector( '.ftnc-color-circle' );
			if ( circle ) { circle.textContent = '✓'; }
		} );
	} );

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------
	renderAddresses();
	renderServices();
	renderFiles();
	renderInstallments();
})();
