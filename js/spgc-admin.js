/* global SPGC, jQuery */
(function ( $ ) {
	'use strict';

	var IDX            = SPGC.idx;
	var AJAX_URL       = SPGC.ajaxUrl;
	var SECURITY       = SPGC.security;
	var OP_MAP         = SPGC.opMap;
	var OP_LABELS      = SPGC.opLabels;
	var GATEWAYS       = SPGC.gateways;
	var CATEGORIES     = SPGC.categories;
	var ROLES          = SPGC.roles;
	var COUNTRIES      = SPGC.countries;
	var COND_TYPES     = SPGC.condTypes;
	var FREE_TYPES     = SPGC.freeCondTypes;   // e.g. ['category','product']
	var FREE_LIMIT     = SPGC.freeRuleLimit;   // e.g. 3
	var IS_PRO         = SPGC.isPro;
	var UPGRADE_URL    = SPGC.upgradeUrl;
	var I18N           = SPGC.i18n;

	/* -------------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------- */

	function esc( s ) {
		return $( '<span>' ).text( String( s ) ).html();
	}

	function isFreeType( ct ) {
		return FREE_TYPES.indexOf( ct ) > -1;
	}

	function buildOptions( map, selected ) {
		var html = '';
		$.each( map, function ( k, v ) {
			html += '<option value="' + esc( k ) + '"' + ( k === selected ? ' selected' : '' ) + '>' + esc( v ) + '</option>';
		} );
		return html;
	}

	function buildOpOptions( ct, sel ) {
		var ops  = OP_MAP[ ct ] || [ 'is', 'is_not' ];
		var html = '';
		$.each( ops, function ( _, k ) {
			html += '<option value="' + esc( k ) + '"' + ( k === sel ? ' selected' : '' ) + '>' + esc( OP_LABELS[ k ] ) + '</option>';
		} );
		return html;
	}

	/**
	 * Build the condition-type <select> options.
	 * Pro-only options get a suffix label and disabled attribute when not Pro.
	 */
	function buildCondTypeOptions( selected ) {
		var html = '';
		$.each( COND_TYPES, function ( k, v ) {
			var isPro  = ! isFreeType( k );
			var locked = isPro && ! IS_PRO;
			var label  = locked ? v + ' — ' + I18N.proSuffix : v;
			html += '<option value="' + esc( k ) + '"' +
				( k === selected ? ' selected' : '' ) +
				( locked ? ' disabled class="is-pro-opt"' : '' ) +
				'>' + esc( label ) + '</option>';
		} );
		return html;
	}

	function buildValueField( ruleIdx, condIdx, ct, val ) {
		var name = 'spgc_rules[' + ruleIdx + '][conditions][' + condIdx + '][value]';
		var vals = Array.isArray( val ) ? val : ( val ? [ val ] : [] );

		if ( 'category' === ct ) {
			var opts = '';
			$.each( CATEGORIES, function ( slug, lbl ) {
				var sel = vals.indexOf( slug ) > -1 ? ' selected' : '';
				opts += '<option value="' + esc( slug ) + '"' + sel + '>' + esc( lbl ) + '</option>';
			} );
			return '<select name="' + esc( name ) + '[]" multiple class="spgc-s2-multi">' + opts + '</select>';
		}

		if ( 'product' === ct ) {
			return '<select name="' + esc( name ) + '[]" multiple class="spgc-s2-product"></select>';
		}

		if ( 'user_role' === ct ) {
			return '<select name="' + esc( name ) + '">' + buildOptions( ROLES, vals[ 0 ] || '' ) + '</select>';
		}

		if ( 'country' === ct ) {
			return '<select name="' + esc( name ) + '" class="spgc-s2-country">' + buildOptions( COUNTRIES, vals[ 0 ] || '' ) + '</select>';
		}

		if ( 'shipping_method' === ct ) {
			var cur = vals[ 0 ] || '';
			var opt = cur ? '<option value="' + esc( cur ) + '" selected>' + esc( cur ) + '</option>' : '';
			return '<select name="' + esc( name ) + '" class="spgc-s2-ship">' + opt + '</select>';
		}

		return '<input type="number" name="' + esc( name ) + '" value="' + esc( vals[ 0 ] || '' ) + '" min="0" step="0.01" placeholder="0">';
	}

	function buildCondRow( ruleIdx, condIdx, isFirst ) {
		var defCt     = 'category';
		var removeBtn = isFirst
			? '<div></div>'
			: '<div class="spgc-field" style="justify-content:flex-end">' +
			  '<button type="button" class="spgc-remove-cond" title="' + esc( I18N.removeCond ) + '">' +
			  '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
			  '</button></div>';

		return '<div class="spgc-condition-row" data-rule="' + ruleIdx + '" data-cond="' + condIdx + '">' +
			'<div class="spgc-rule-grid">' +
			'<div class="spgc-field"><label>' + esc( I18N.ifLabel ) + '</label>' +
			'<select name="spgc_rules[' + ruleIdx + '][conditions][' + condIdx + '][condition_type]" class="spgc-ct">' +
			buildCondTypeOptions( defCt ) +
			'</select></div>' +
			'<div class="spgc-field"><label>' + esc( I18N.operator ) + '</label>' +
			'<select name="spgc_rules[' + ruleIdx + '][conditions][' + condIdx + '][operator]" class="spgc-op">' + buildOpOptions( defCt, 'is' ) + '</select></div>' +
			'<div class="spgc-field spgc-val-wrap"><label>' + esc( I18N.value ) + '</label>' +
			buildValueField( ruleIdx, condIdx, defCt, '' ) + '</div>' +
			removeBtn +
			'</div></div>';
	}

	function buildAndSeparator() {
		return '<div class="spgc-and-separator"><span>' + esc( I18N.andLabel ) + '</span></div>';
	}

	function buildCard( i ) {
		var gwOpts     = '<option value="">' + esc( I18N.select ) + '</option>' + buildOptions( GATEWAYS, '' );
		var addCondBtn = '<button type="button" class="spgc-add-cond-btn" data-rule="' + i + '">' +
			'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> ' +
			esc( I18N.addCond ) + '</button>';

		return '<div class="spgc-rule" data-index="' + i + '">' +
			'<div class="spgc-rule-topbar">' +
			'<span class="spgc-rule-num">' +
			'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>' +
			'<span class="spgc-rule-num-badge">' + ( i + 1 ) + '</span> ' +
			esc( I18N.rule ) + ' #' + ( i + 1 ) + '</span>' +
			'<button type="button" class="spgc-remove" title="' + esc( I18N.remove ) + '">' +
			'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
			'</button></div>' +
			'<div class="spgc-rule-body"><div class="spgc-conditions-wrap">' +
			buildCondRow( i, 0, true ) +
			'</div>' +
			addCondBtn + '</div>' +
			'<div class="spgc-then-row">' +
			'<div class="spgc-then-arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="5 12 12 19 19 12"/></svg></div>' +
			'<div class="spgc-field spgc-then-gw">' +
			'<div class="spgc-then-label"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 8 13.5 16.5 8 11 2 17"/><polyline points="16 8 22 8 22 14"/></svg> ' +
			esc( I18N.thenDisable ) + '</div>' +
			'<select name="spgc_rules[' + i + '][gateway]">' + gwOpts + '</select></div>' +
			'</div></div>';
	}

	/* -------------------------------------------------------------------------
	 * Select2 init / destroy
	 * ---------------------------------------------------------------------- */

	function initS2( card ) {
		card.find( '.spgc-s2-multi' ).each( function () {
			if ( ! $( this ).hasClass( 'select2-hidden-accessible' ) ) {
				$( this ).select2( { width: '100%', closeOnSelect: false } );
			}
		} );
		card.find( '.spgc-s2-country' ).each( function () {
			if ( ! $( this ).hasClass( 'select2-hidden-accessible' ) ) {
				$( this ).select2( { width: '100%' } );
			}
		} );
		card.find( '.spgc-s2-product' ).each( function () {
			if ( ! $( this ).hasClass( 'select2-hidden-accessible' ) ) {
				$( this ).select2( {
					width: '100%',
					multiple: true,
					minimumInputLength: 2,
					placeholder: I18N.searchProd,
					ajax: {
						url: AJAX_URL,
						dataType: 'json',
						delay: 300,
						data: function ( p ) {
							return { action: 'spgc_search_products', q: p.term, security: SECURITY };
						},
						processResults: function ( d ) {
							return { results: d.results };
						},
					},
				} );
			}
		} );
		card.find( '.spgc-s2-ship' ).each( function () {
			if ( ! $( this ).hasClass( 'select2-hidden-accessible' ) ) {
				$( this ).select2( {
					width: '100%',
					placeholder: I18N.searchShip,
					ajax: {
						url: AJAX_URL,
						dataType: 'json',
						data: function () {
							return { action: 'spgc_search_shipping', security: SECURITY };
						},
						processResults: function ( d ) {
							return { results: d.results };
						},
					},
				} );
			}
		} );
	}

	function destroyS2( wrap ) {
		wrap.find( 'select' ).each( function () {
			try {
				if ( $( this ).hasClass( 'select2-hidden-accessible' ) ) {
					$( this ).select2( 'destroy' );
				}
			} catch ( e ) {}
		} );
	}

	/* -------------------------------------------------------------------------
	 * Counting & UI state
	 * ---------------------------------------------------------------------- */

	function ruleCount() {
		return $( '#spgc-rules-list .spgc-rule' ).length;
	}

	function condCount( ruleCard ) {
		return ruleCard.find( '.spgc-condition-row' ).length;
	}

	function reindexConditions( ruleCard ) {
		ruleCard.find( '.spgc-condition-row' ).each( function ( ci ) {
			$( this ).attr( 'data-cond', ci );
			$( this ).find( '[name]' ).each( function () {
				var n = $( this ).attr( 'name' );
				n = n.replace( /\[conditions\]\[\d+\]/, '[conditions][' + ci + ']' );
				$( this ).attr( 'name', n );
			} );
		} );
	}

	/** Update the badge count and enable/disable the Add Rule button. */
	function updateUI() {
		var count    = ruleCount();
		var atLimit  = ! IS_PRO && count >= FREE_LIMIT;

		$( '#spgc-badge' ).text( count + ' ' + I18N.badge );
		$( '#spgc-stat-num' ).text( count );

		if ( atLimit ) {
			$( '#spgc-add-btn' ).prop( 'disabled', true ).addClass( 'is-disabled' );
			showLimitBanner();
		} else {
			$( '#spgc-add-btn' ).prop( 'disabled', false ).removeClass( 'is-disabled' );
			hideLimitBanner();
		}
	}

	function showLimitBanner() {
		if ( $( '#spgc-limit-banner-js' ).length ) {
			return;
		}
		var banner =
			'<div class="spgc-limit-banner" id="spgc-limit-banner-js">' +
			'<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
			'<div class="spgc-limit-banner-text">' +
			'<strong>' + esc( I18N.limitReached ) + '</strong>' +
			'<span>' + esc( I18N.upgradeCta ) + '</span>' +
			'</div>' +
			'<a href="' + esc( UPGRADE_URL ) + '" target="_blank" rel="noopener noreferrer">' +
			'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>' +
			' Upgrade to Pro' +
			'</a></div>';
		$( '#spgc-rules-list' ).before( banner );
	}

	function hideLimitBanner() {
		$( '#spgc-limit-banner-js' ).remove();
	}

	/* -------------------------------------------------------------------------
	 * Event handlers
	 * ---------------------------------------------------------------------- */

	/** Condition type change — rebuild operator & value fields */
	$( document ).on( 'change', '.spgc-ct', function () {
		var select = $( this );
		var ct     = select.val();

		// Guard: if a non-Pro user somehow selects a Pro option, revert it.
		if ( ! IS_PRO && ! isFreeType( ct ) ) {
			select.val( 'category' );
			ct = 'category';
			// Friendly prompt to upgrade.
			window.open( UPGRADE_URL, '_blank', 'noopener,noreferrer' );
			return;
		}

		var row  = select.closest( '.spgc-condition-row' );
		var card = row.closest( '.spgc-rule' );
		var ri   = card.data( 'index' );
		var ci   = row.data( 'cond' );

		row.find( '.spgc-op' ).html( buildOpOptions( ct, 'is' ) );
		var vw = row.find( '.spgc-val-wrap' );
		destroyS2( vw );
		vw.html( '<label>' + esc( I18N.value ) + '</label>' + buildValueField( ri, ci, ct, '' ) );
		initS2( row );
	} );

	/** Add AND condition */
	$( document ).on( 'click', '.spgc-add-cond-btn', function () {
		var card = $( this ).closest( '.spgc-rule' );
		var ri   = card.data( 'index' );
		var ci   = condCount( card );
		var wrap = card.find( '.spgc-conditions-wrap' );
		wrap.append( buildAndSeparator() );
		var newRow = $( buildCondRow( ri, ci, false ) );
		wrap.append( newRow );
		initS2( newRow );
	} );

	/** Remove AND condition */
	$( document ).on( 'click', '.spgc-remove-cond', function () {
		var row  = $( this ).closest( '.spgc-condition-row' );
		var card = row.closest( '.spgc-rule' );
		row.prev( '.spgc-and-separator' ).remove();
		row.remove();
		reindexConditions( card );
	} );

	/** Add rule — blocked at free limit */
	$( '#spgc-add-btn' ).on( 'click', function () {
		if ( ! IS_PRO && ruleCount() >= FREE_LIMIT ) {
			// Button should already be disabled, but guard anyway.
			window.open( UPGRADE_URL, '_blank', 'noopener,noreferrer' );
			return;
		}

		$( '#spgc-empty-state' ).remove();
		var card = $( buildCard( IDX ) );
		$( '#spgc-rules-list' ).append( card );
		initS2( card );
		IDX++;
		updateUI();
	} );

	/** Remove rule */
	$( document ).on( 'click', '.spgc-remove', function () {
		$( this ).closest( '.spgc-rule' ).remove();
		if ( ! $( '.spgc-rule' ).length ) {
			$( '#spgc-rules-list' ).prepend(
				'<div class="spgc-empty" id="spgc-empty-state">' +
				'<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>' +
				'<p>' + esc( I18N.noRules ) + '</p></div>'
			);
		}
		updateUI();
	} );

	/* -------------------------------------------------------------------------
	 * Init on page load
	 * ---------------------------------------------------------------------- */

	$( '.spgc-rule' ).each( function () {
		initS2( $( this ) );
	} );

	updateUI();

}( jQuery ) );