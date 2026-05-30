/* global FtncMetaData */
/**
 * Fotonic — Customer People repeater
 *
 * Depends on: wp-mediaelement (none), no jQuery required.
 * Localized via wp_localize_script( 'fotonic-meta-customer', 'FtncMetaData', {...} )
 */
(function() {
	var data   = window.FtncMetaData && window.FtncMetaData.customer ? window.FtncMetaData.customer : {};
	var people = data.people || [];

	var i18n = {
		remove:       data.i18n_remove       || 'Remove',
		atLeastOne:   data.i18n_at_least_one || 'At least one person is required.',
	};

	function render() {
		var tbody = document.getElementById( 'ftnc-people-rows' );
		tbody.innerHTML = '';
		people.forEach( function( p, i ) {
			var tr = document.createElement( 'tr' );
			tr.style.borderTop = '1px solid #ddd';
			tr.innerHTML =
				'<td><input type="text" class="regular-text" value="' + esc( p.first_name ) + '" data-field="first_name" data-idx="' + i + '"></td>' +
				'<td><input type="text" class="regular-text" value="' + esc( p.last_name ) + '" data-field="last_name" data-idx="' + i + '"></td>' +
				'<td><input type="email" class="regular-text" value="' + esc( p.email ) + '" data-field="email" data-idx="' + i + '"></td>' +
				'<td><input type="text" class="regular-text" value="' + esc( p.phone ) + '" data-field="phone" data-idx="' + i + '"></td>' +
				'<td><input type="text" class="small-text" value="' + esc( p.nationality ) + '" data-field="nationality" data-idx="' + i + '"></td>' +
				'<td><input type="text" class="regular-text" value="' + esc( p.instagram_username ) + '" data-field="instagram_username" data-idx="' + i + '" placeholder="@username"></td>' +
				'<td><input type="text" class="regular-text" value="' + esc( p.address ) + '" data-field="address" data-idx="' + i + '"></td>' +
				'<td><input type="text" class="small-text" value="' + esc( p.tin ) + '" data-field="tin" data-idx="' + i + '"></td>' +
				'<td style="text-align:center"><input type="radio" name="ftnc_is_main" value="' + i + '"' + ( p.is_main ? ' checked' : '' ) + '></td>' +
				'<td><button type="button" class="button-link ftnc-remove-person" data-idx="' + i + '" style="color:#a00">' + esc( i18n.remove ) + '</button></td>';
			tbody.appendChild( tr );
		} );
		attachListeners();
	}

	function esc( v ) {
		if ( ! v ) { return ''; }
		return String( v ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	function sync() {
		document.getElementById( 'ftnc_people_json' ).value = JSON.stringify( people );
	}

	function attachListeners() {
		// Text field changes.
		document.querySelectorAll( '#ftnc-people-rows input[data-field]' ).forEach( function( el ) {
			el.addEventListener( 'input', function() {
				var idx   = parseInt( this.dataset.idx, 10 );
				var field = this.dataset.field;
				people[ idx ][ field ] = this.value;
				sync();
			} );
		} );

		// Radio is_main.
		document.querySelectorAll( 'input[name="ftnc_is_main"]' ).forEach( function( el ) {
			el.addEventListener( 'change', function() {
				var selected = parseInt( this.value, 10 );
				people.forEach( function( p, i ) { p.is_main = ( i === selected ); } );
				sync();
			} );
		} );

		// Remove buttons.
		document.querySelectorAll( '.ftnc-remove-person' ).forEach( function( el ) {
			el.addEventListener( 'click', function() {
				var idx = parseInt( this.dataset.idx, 10 );
				if ( people.length <= 1 ) {
					alert( i18n.atLeastOne );
					return;
				}
				people.splice( idx, 1 );
				// Ensure one is main.
				if ( ! people.some( function( p ) { return p.is_main; } ) ) {
					people[ 0 ].is_main = true;
				}
				render();
				sync();
			} );
		} );
	}

	var addBtn = document.getElementById( 'ftnc-add-person' );
	if ( addBtn ) {
		addBtn.addEventListener( 'click', function() {
			people.push( { first_name: '', last_name: '', email: '', phone: '', nationality: '', instagram_username: '', address: '', tin: '', is_main: false } );
			render();
			sync();
		} );
	}

	render();
})();
