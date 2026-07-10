/**
 * Organization member search: annotated customer search with a
 * confirmation before moving a member out of another organization.
 *
 * global wb2b_members_params, wc_enhanced_select_params
 */
jQuery( function ( $ ) {
	var $search = $( ':input.wb2b-member-search' );

	if ( ! $search.length || 'undefined' === typeof wc_enhanced_select_params ) {
		return;
	}

	$search.selectWoo( {
		placeholder: $search.data( 'placeholder' ),
		minimumInputLength: 1,
		escapeMarkup: function ( m ) {
			return m;
		},
		ajax: {
			url: wc_enhanced_select_params.ajax_url,
			dataType: 'json',
			delay: 500,
			data: function ( params ) {
				return {
					term: params.term,
					action: wb2b_members_params.search_action,
					security: wc_enhanced_select_params.search_customers_nonce,
					organization_id: wb2b_members_params.organization_id
				};
			},
			processResults: function ( data ) {
				var terms = [];
				if ( data ) {
					$.each( data, function ( id, text ) {
						terms.push( { id: id, text: text } );
					} );
				}
				return { results: terms };
			},
			cache: true
		}
	} );

	// Selecting someone who belongs to another organization moves them;
	// make sure that is deliberate.
	$search.on( 'select2:selecting', function ( e ) {
		var data = e.params && e.params.args && e.params.args.data ? e.params.args.data : {};
		var text = data.text || '';
		var idx  = text.indexOf( wb2b_members_params.move_marker );

		if ( -1 === idx ) {
			return;
		}

		var org = text.substring( idx + wb2b_members_params.move_marker.length );
		if ( ! window.confirm( wb2b_members_params.i18n_move_confirm.replace( '%s', org ) ) ) {
			e.preventDefault();
		}
	} );
} );
