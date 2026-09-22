/**
 * WP Events Calendar — admin.
 * En el filtro de Localizaciones, el desplegable de ayuntamientos solo muestra
 * los de la provincia elegida.
 */
( function () {
	'use strict';

	var province     = document.getElementById( 'f_province' );
	var municipality = document.getElementById( 'f_municipality' );
	if ( ! province || ! municipality ) {
		return;
	}

	function sync() {
		var prov = province.value;
		Array.prototype.forEach.call( municipality.options, function ( opt ) {
			if ( ! opt.value ) {
				return; // Opción "Todos".
			}
			var provs   = ( opt.getAttribute( 'data-province' ) || '' ).split( '|' );
			var visible = ! prov || provs.indexOf( prov ) !== -1;
			opt.hidden   = ! visible;
			opt.disabled = ! visible;
			if ( ! visible && opt.selected ) {
				municipality.value = '';
			}
		} );
	}

	province.addEventListener( 'change', sync );
	sync();
} )();

/**
 * Botón "Copiar" de la lista de shortcodes.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.wpec-copy' ) : null;
		if ( ! btn ) {
			return;
		}
		var text  = btn.getAttribute( 'data-copy' );
		var label = btn.textContent;
		var done  = function () {
			btn.textContent = btn.getAttribute( 'data-done' ) || label;
			setTimeout( function () { btn.textContent = label; }, 1500 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			// Alternativa para http:// (p. ej. MAMP en local).
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity  = '0';
			document.body.appendChild( ta );
			ta.select();
			try { document.execCommand( 'copy' ); done(); } catch ( err ) {}
			document.body.removeChild( ta );
		}
	} );
} )();
