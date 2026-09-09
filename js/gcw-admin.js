/**
 * Gateway Conditioner for WooCommerce - Admin JavaScript
 *
 * @package GatewayConditionerForWooCommerce
 * @version 2.2.0
 */

/* global GCW, jQuery */
( function ( $ ) {
	'use strict';

	var AJAX_URL          = GCW.ajaxUrl;
	var SECURITY          = GCW.security;
	var OP_MAP            = GCW.opMap || {};
	var OP_LABELS         = GCW.opLabels || {};
	var GATEWAYS          = GCW.gateways || {};
	var CATEGORIES        = GCW.categories || {};
	var ROLES             = GCW.roles || {};
	var COUNTRIES         = GCW.countries || {};
	var SHIPPING_METHODS  = GCW.shippingMethods || [];
	var COND_TYPES        = GCW.condTypes || {};
	var I18N              = GCW.i18n || {};

	/* -------------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------- */

	function esc( s ) {
		return $( '<span>' ).text( String( s ) ).html();
	}

	function buildOptions( map, selected ) {
		var html = '';
		$.each( map, function ( k, v ) {
			html += '<option value="' + esc( k ) + '"' + ( k === selected ? ' selected="selected"' : '' ) + '>' + esc( v ) + '</option>';
		} );
		return html;
	}

	function buildShippingOptions( selected ) {
		var html = '<option value="">' + esc( I18N.select || '— select —' ) + '</option>';
		if ( Array.isArray( SHIPPING_METHODS ) ) {
			$.each( SHIPPING_METHODS, function ( _, item ) {
				if ( item && item.id ) {
					var isSel = String( item.id ) === String( selected );
					html += '<option value="' + esc( item.id ) + '"' + ( isSel ? ' selected="selected"' : '' ) + '>' + esc( item.text ) + '</option>';
				}
			} );
		}
		return html;
	}

	function buildOpOptions( ct, sel ) {
		var ops  = OP_MAP[ ct ] || [ 'is', 'is_not' ];
		var html = '';
		$.each( ops, function ( _, k ) {
			var label = OP_LABELS[ k ] ? OP_LABELS[ k ] : k;
			html += '<option value="' + esc( k ) + '"' + ( k === sel ? ' selected="selected"' : '' ) + '>' + esc( label ) + '</option>';
		} );
		return html;
	}

	function buildCondTypeOptions( selected ) {
		var html = '';
		$.each( COND_TYPES, function ( k, v ) {
			html += '<option value="' + esc( k ) + '"' + ( k === selected ? ' selected="selected"' : '' ) + '>' + esc( v ) + '</option>';
		} );
		return html;
	}

	function buildValueField( ruleIdx, condIdx, ct, val ) {
		var name = 'gcw_rules[' + ruleIdx + '][conditions][' + condIdx + '][value]';
		var vals = Array.isArray( val ) ? val : ( val ? [ val ] : [] );

		if ( 'category' === ct ) {
			var opts = '';
			$.each( CATEGORIES, function ( slug, lbl ) {
				var sel = vals.indexOf( slug ) > -1 ? ' selected="selected"' : '';
				opts += '<option value="' + esc( slug ) + '"' + sel + '>' + esc( lbl ) + '</option>';
			} );
			return '<select name="' + esc( name ) + '[]" multiple class="gcw-s2-multi">' + opts + '</select>';
		}

		if ( 'product' === ct ) {
			return '<select name="' + esc( name ) + '[]" multiple class="gcw-s2-product"></select>';
		}

		if ( 'user_role' === ct ) {
			return '<select name="' + esc( name ) + '">' + buildOptions( ROLES, vals[ 0 ] || '' ) + '</select>';
		}

		if ( 'country' === ct ) {
			return '<select name="' + esc( name ) + '" class="gcw-s2-country">' + buildOptions( COUNTRIES, vals[ 0 ] || '' ) + '</select>';
		}

		if ( 'shipping_method' === ct ) {
			return '<select name="' + esc( name ) + '" class="gcw-s2-ship">' + buildShippingOptions( vals[ 0 ] || '' ) + '</select>';
		}

		return '<input type="number" name="' + esc( name ) + '" value="' + esc( vals[ 0 ] || '' ) + '" min="0" step="0.01" placeholder="0">';
	}

	function buildCondRow( ruleIdx, condIdx, isFirst ) {
		var defCt     = 'category';
		var removeBtn = isFirst
			? '<div></div>'
			: '<div class="gcw-field" style="justify-content:flex-end">' +
			  '<button type="button" class="gcw-remove-cond" title="' + esc( I18N.removeCond ) + '">' +
			  '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
			  '</button></div>';

		return '<div class="gcw-condition-row" data-rule="' + ruleIdx + '" data-cond="' + condIdx + '">' +
			'<div class="gcw-rule-grid">' +
			'<div class="gcw-field"><label>' + esc( I18N.ifLabel ) + '</label>' +
			'<select name="gcw_rules[' + ruleIdx + '][conditions][' + condIdx + '][condition_type]" class="gcw-ct">' +
			buildCondTypeOptions( defCt ) +
			'</select></div>' +
			'<div class="gcw-field"><label>' + esc( I18N.operator ) + '</label>' +
			'<select name="gcw_rules[' + ruleIdx + '][conditions][' + condIdx + '][operator]" class="gcw-op">' + buildOpOptions( defCt, 'is' ) + '</select></div>' +
			'<div class="gcw-field gcw-val-wrap"><label>' + esc( I18N.value ) + '</label>' +
			buildValueField( ruleIdx, condIdx, defCt, '' ) + '</div>' +
			removeBtn +
			'</div></div>';
	}

	function buildAndSeparator() {
		return '<div class="gcw-and-separator"><span>' + esc( I18N.andLabel ) + '</span></div>';
	}

	function buildCard( i ) {
		var gwOpts     = '<option value="">' + esc( I18N.select ) + '</option>' + buildOptions( GATEWAYS, '' );
		var addCondBtn = '<button type="button" class="gcw-add-cond-btn" data-rule="' + i + '">' +
			'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> ' +
			esc( I18N.addCond ) + '</button>';

		return '<div class="gcw-rule" data-index="' + i + '">' +
			'<div class="gcw-rule-topbar">' +
			'<span class="gcw-rule-num">' +
			'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>' +
			'<span class="gcw-rule-num-badge">' + ( i + 1 ) + '</span> ' +
			'<span class="gcw-rule-num-text">' + esc( I18N.rule ) + ' #' + ( i + 1 ) + '</span></span>' +
			'<button type="button" class="gcw-remove" title="' + esc( I18N.remove ) + '">' +
			'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
			'</button></div>' +
			'<div class="gcw-rule-body"><div class="gcw-conditions-wrap">' +
			buildCondRow( i, 0, true ) +
			'</div>' +
			addCondBtn + '</div>' +
			'<div class="gcw-then-row">' +
			'<div class="gcw-then-arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="5 12 12 19 19 12"/></svg></div>' +
			'<div class="gcw-field gcw-then-gw">' +
			'<div class="gcw-then-label"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 8 13.5 16.5 8 11 2 17"/><polyline points="16 8 22 8 22 14"/></svg> ' +
			esc( I18N.thenDisable ) + '</div>' +
			'<select name="gcw_rules[' + i + '][gateway]">' + gwOpts + '</select></div>' +
			'</div></div>';
	}

	/* -------------------------------------------------------------------------
	 * Select2 init / destroy
	 * ---------------------------------------------------------------------- */

	function initS2( card ) {
		if ( typeof $.fn.select2 !== 'function' ) {
			return;
		}

		card.find( '.gcw-s2-multi' ).each( function () {
			if ( ! $( this ).hasClass( 'select2-hidden-accessible' ) ) {
				$( this ).select2( { width: '100%', closeOnSelect: false } );
			}
		} );

		card.find( '.gcw-s2-country' ).each( function () {
			if ( ! $( this ).hasClass( 'select2-hidden-accessible' ) ) {
				$( this ).select2( { width: '100%' } );
			}
		} );

		card.find( '.gcw-s2-ship' ).each( function () {
			if ( ! $( this ).hasClass( 'select2-hidden-accessible' ) ) {
				$( this ).select2( { width: '100%' } );
			}
		} );

		card.find( '.gcw-s2-product' ).each( function () {
			if ( ! $( this ).hasClass( 'select2-hidden-accessible' ) ) {
				$( this ).select2( {
					width: '100%',
					multiple: true,
					minimumInputLength: 2,
					placeholder: I18N.searchProd,
					ajax: {
						url: AJAX_URL,
						dataType: 'json',
						delay: 250,
						data: function ( p ) {
							return { action: 'gcw_search_products', q: p.term, security: SECURITY };
						},
						processResults: function ( d ) {
							return { results: ( d && d.results ) ? d.results : [] };
						},
					},
				} );
			}
		} );
	}

	function destroyS2( wrap ) {
		if ( typeof $.fn.select2 !== 'function' ) {
			return;
		}
		wrap.find( 'select' ).each( function () {
			try {
				if ( $( this ).hasClass( 'select2-hidden-accessible' ) ) {
					$( this ).select2( 'destroy' );
				}
			} catch ( e ) {}
		} );
	}

	/* -------------------------------------------------------------------------
	 * Counting & Re-indexing
	 * ---------------------------------------------------------------------- */

	function ruleCount() {
		return $( '#gcw-rules-list .gcw-rule' ).length;
	}

	function condCount( ruleCard ) {
		return ruleCard.find( '.gcw-condition-row' ).length;
	}

	function reindexConditions( ruleCard ) {
		ruleCard.find( '.gcw-condition-row' ).each( function ( ci ) {
			var row = $( this );
			row.attr( 'data-cond', ci );
			row.find( '[name]' ).each( function () {
				var n = $( this ).attr( 'name' );
				if ( n ) {
					n = n.replace( /\[conditions\]\[\d+\]/, '[conditions][' + ci + ']' );
					$( this ).attr( 'name', n );
				}
			} );
		} );
	}

	function reindexRules() {
		$( '#gcw-rules-list .gcw-rule' ).each( function ( ri ) {
			var card = $( this );
			card.attr( 'data-index', ri );
			card.find( '.gcw-rule-num-badge' ).first().text( ri + 1 );
			card.find( '.gcw-rule-num-text' ).first().text( ( I18N.rule || 'Rule' ) + ' #' + ( ri + 1 ) );
			card.find( '.gcw-add-cond-btn' ).attr( 'data-rule', ri );

			card.find( '.gcw-condition-row' ).each( function () {
				$( this ).attr( 'data-rule', ri );
			} );

			card.find( '[name]' ).each( function () {
				var name = $( this ).attr( 'name' );
				if ( name ) {
					name = name.replace( /^gcw_rules\[\d+\]/, 'gcw_rules[' + ri + ']' );
					$( this ).attr( 'name', name );
				}
			} );
		} );
	}

	function updateUI() {
		var count = ruleCount();
		var badgeText = count + ' ' + ( count === 1 ? ( I18N.rule || 'rule' ) : ( I18N.rules || 'rules' ) );
		$( '#gcw-badge' ).text( badgeText );
		$( '#gcw-stat-num' ).text( count );
	}

	/* -------------------------------------------------------------------------
	 * Event Handlers
	 * ---------------------------------------------------------------------- */

	/** Condition type change — rebuild operator & value fields */
	$( document ).on( 'change', '.gcw-ct', function () {
		var select = $( this );
		var ct     = select.val();
		var row    = select.closest( '.gcw-condition-row' );
		var card   = row.closest( '.gcw-rule' );
		var ri     = card.attr( 'data-index' );
		var ci     = row.attr( 'data-cond' );

		row.find( '.gcw-op' ).html( buildOpOptions( ct, 'is' ) );
		var vw = row.find( '.gcw-val-wrap' );
		destroyS2( vw );
		vw.html( '<label>' + esc( I18N.value ) + '</label>' + buildValueField( ri, ci, ct, '' ) );
		initS2( row );
	} );

	/** Add AND condition */
	$( document ).on( 'click', '.gcw-add-cond-btn', function () {
		var card = $( this ).closest( '.gcw-rule' );
		var ri   = card.attr( 'data-index' );
		var ci   = condCount( card );
		var wrap = card.find( '.gcw-conditions-wrap' );
		wrap.append( buildAndSeparator() );
		var newRow = $( buildCondRow( ri, ci, false ) );
		wrap.append( newRow );
		initS2( newRow );
	} );

	/** Remove AND condition */
	$( document ).on( 'click', '.gcw-remove-cond', function () {
		var row  = $( this ).closest( '.gcw-condition-row' );
		var card = row.closest( '.gcw-rule' );
		row.prev( '.gcw-and-separator' ).remove();
		row.remove();
		reindexConditions( card );
	} );

	/** Add rule */
	$( '#gcw-add-btn' ).on( 'click', function () {
		$( '#gcw-empty-state' ).remove();
		var currentCount = ruleCount();
		var card         = $( buildCard( currentCount ) );
		$( '#gcw-rules-list' ).append( card );
		initS2( card );
		reindexRules();
		updateUI();
	} );

	/** Remove rule */
	$( document ).on( 'click', '.gcw-remove', function () {
		$( this ).closest( '.gcw-rule' ).remove();
		if ( ! $( '.gcw-rule' ).length ) {
			$( '#gcw-rules-list' ).prepend(
				'<div class="gcw-empty" id="gcw-empty-state">' +
				'<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>' +
				'<p>' + esc( I18N.noRules ) + '</p></div>'
			);
		}
		reindexRules();
		updateUI();
	} );

	/* -------------------------------------------------------------------------
	 * Init on page load
	 * ---------------------------------------------------------------------- */

	$( function () {
		$( '.gcw-rule' ).each( function () {
			initS2( $( this ) );
		} );
		updateUI();
	} );

}( jQuery ) );