/* global tinyBackup */
( function () {
	'use strict';

	var config = window.tinyBackup;
	var sizes = null;
	var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	var numberFormat;

	try {
		numberFormat = new Intl.NumberFormat( document.documentElement.lang || undefined, { maximumFractionDigits: 1 } );
	} catch ( e ) {
		numberFormat = new Intl.NumberFormat( undefined, { maximumFractionDigits: 1 } );
	}

	function formatSize( bytes ) {
		var unit = 0;
		while ( bytes >= 1024 && unit < units.length - 1 ) {
			bytes /= 1024;
			unit++;
		}
		return numberFormat.format( bytes ) + ' ' + units[ unit ];
	}

	// Adds up measurements; any of them cut short makes the total a lower bound.
	function describe( parts ) {
		var bytes = 0;
		var complete = true;
		parts.forEach( function ( part ) {
			bytes += part.bytes;
			complete = complete && part.complete;
		} );
		return complete ? formatSize( bytes ) : config.i18n.moreThan.replace( '%s', formatSize( bytes ) );
	}

	function setText( selector, text ) {
		document.querySelectorAll( selector ).forEach( function ( element ) {
			element.textContent = text;
		} );
	}

	function render() {
		var skip = document.querySelector( 'input[name="skip_disposable"]' );
		var content = [ sizes.content ];
		if ( skip && ! skip.checked ) {
			content = content.concat( sizes.disposable );
		}

		setText( '[data-tiny-backup-size="content"]', describe( content ) );
		setText( '[data-tiny-backup-size="content-full"]', describe( content.concat( sizes.uploads ) ) );
		setText( '[data-tiny-backup-size="uploads"]', describe( [ sizes.uploads ] ) );
		setText(
			'[data-tiny-backup-disposable]',
			sizes.disposable.length
				? config.i18n.found.replace(
					'%s',
					sizes.disposable.map( function ( folder ) {
						return folder.name + ' (' + describe( [ folder ] ) + ')';
					} ).join( ', ' )
				)
				: config.i18n.nothing
		);
	}

	if ( ! config ) {
		return;
	}

	var body = new FormData();
	body.append( 'action', 'tiny_backup_sizes' );
	body.append( '_ajax_nonce', config.nonce );
	fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
		.then( function ( response ) {
			return response.json();
		} )
		.then( function ( result ) {
			if ( ! result || ! result.success ) {
				throw new Error( 'Size request failed' );
			}
			sizes = result.data;
			render();
		} )
		.catch( function () {
			setText( '[data-tiny-backup-size]', config.i18n.unavailable );
		} );

	document.addEventListener( 'change', function ( event ) {
		if ( sizes && 'skip_disposable' === event.target.name ) {
			render();
		}
	} );

	// The browser gives no signal when a download starts, so just acknowledge the click for a while.
	document.querySelectorAll( '.tiny-backup-card' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function () {
			var button = form.querySelector( '[type="submit"]' );
			var status = form.querySelector( '.tiny-backup-status' );
			setTimeout( function () {
				button.disabled = true;
			} );
			status.textContent = config.i18n.preparing;
			setTimeout( function () {
				button.disabled = false;
				status.textContent = '';
			}, 10000 );
		} );
	} );
}() );
