/* AI Visibility Tracker — admin behaviour. No dependencies. */
( function () {
	'use strict';

	var cfg = window.AIVT || {};
	var t = cfg.i18n || {};

	function $( sel, root ) { return ( root || document ).querySelector( sel ); }
	function $$( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); }

	function sprintf( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str ).replace( /%(\d+\$)?d/g, function ( m, pos ) {
			return pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
		} );
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function api( method, path, body ) {
		return fetch( cfg.rest + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! res.ok ) {
					var err = new Error( ( data && data.message ) || t.error );
					err.data = data;
					err.status = res.status;
					throw err;
				}
				return data;
			} );
		} );
	}

	function setStatus( el, msg, kind ) {
		if ( ! el ) { return; }
		el.textContent = msg || '';
		el.classList.remove( 'is-ok', 'is-error' );
		if ( kind ) { el.classList.add( 'is-' + kind ); }
	}

	/* ---------- Settings: test engine connection ---------- */
	$$( '[data-aivt-test]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var box = btn.closest( '[data-aivt-engine]' );
			var status = $( '[data-aivt-test-status]', box );
			setStatus( status, t.testing );
			btn.disabled = true;
			api( 'POST', '/engines/' + box.getAttribute( 'data-aivt-engine' ) + '/test', {
				key: $( '[data-aivt-key]', box ).value.trim(),
				model: $( '[data-aivt-model]', box ).value.trim()
			} ).then( function ( data ) {
				setStatus( status, '✓ ' + data.message, 'ok' );
			} ).catch( function ( e ) {
				setStatus( status, '✕ ' + e.message, 'error' );
			} ).then( function () { btn.disabled = false; } );
		} );
	} );

	/* ---------- Settings: suggest other brand names ---------- */
	var suggest = $( '[data-aivt-suggest-names]' );
	if ( suggest ) {
		suggest.addEventListener( 'click', function () {
			var status = $( '[data-aivt-names-status]' );
			var input = $( '[data-aivt-aliases]' );
			suggest.disabled = true;
			setStatus( status, t.suggesting );
			api( 'POST', '/brand/names' ).then( function ( data ) {
				var current = input.value.split( ',' ).map( function ( v ) { return v.trim(); } ).filter( Boolean );
				var lower = current.map( function ( v ) { return v.toLowerCase(); } );
				var added = ( data.names || [] ).filter( function ( n ) { return lower.indexOf( n.toLowerCase() ) === -1; } );
				if ( ! added.length ) {
					setStatus( status, t.noNames );
				} else {
					input.value = current.concat( added ).join( ', ' );
					setStatus( status, t.namesAdded.replace( '%s', added.join( ', ' ) ), 'ok' );
					input.focus();
				}
			} ).catch( function ( e ) {
				setStatus( status, e.message, 'error' );
			} ).then( function () { suggest.disabled = false; } );
		} );
	}

	/* ---------- Prompts: generate, edit, delete confirm ---------- */
	var gen = $( '[data-aivt-generate]' );
	if ( gen ) {
		gen.addEventListener( 'click', function () {
			var status = $( '[data-aivt-generate-status]' );
			var count = parseInt( $( '#aivt-gen-count' ).value, 10 ) || 10;
			gen.disabled = true;
			setStatus( status, t.generating );
			api( 'POST', '/prompts/generate', { count: count } ).then( function ( data ) {
				setStatus( status, sprintf( t.generated, ( data.prompts || [] ).length ), 'ok' );
				window.setTimeout( function () { window.location.reload(); }, 1200 );
			} ).catch( function ( e ) {
				setStatus( status, e.message, 'error' );
				gen.disabled = false;
			} );
		} );
	}

	$$( '[data-aivt-edit-toggle]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var row = btn.closest( 'tr' );
			var form = $( '[data-aivt-prompt-edit]', row );
			var text = $( '[data-aivt-prompt-text]', row );
			var open = form.hidden;
			form.hidden = ! open;
			text.hidden = open;
			if ( open ) { $( 'textarea', form ).focus(); }
		} );
	} );

	$$( '[data-aivt-confirm-link]' ).forEach( function ( link ) {
		link.addEventListener( 'click', function ( e ) {
			if ( ! window.confirm( link.getAttribute( 'data-aivt-confirm-link' ) ) ) { e.preventDefault(); }
		} );
	} );

	$$( 'form[data-aivt-confirm="delete"]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( t.confirmDel ) ) { e.preventDefault(); }
		} );
	} );

	/* ---------- Runs: start, drive steps, show progress ---------- */
	var runId = parseInt( cfg.activeRun, 10 ) || 0;
	var stopping = false;

	function runUI() {
		return {
			status: $( '[data-aivt-run-status]' ),
			progress: $( '[data-aivt-progress]' ),
			fill: $( '[data-aivt-progress-fill]' ),
			bar: $( '[data-aivt-progress-bar]' ),
			runBtns: $$( '[data-aivt-run]' ),
			cancel: $( '[data-aivt-cancel]' )
		};
	}

	function showProgress( run ) {
		var ui = runUI();
		if ( ui.progress ) { ui.progress.hidden = false; }
		if ( ui.fill ) { ui.fill.style.width = run.percent + '%'; }
		if ( ui.bar ) { ui.bar.setAttribute( 'aria-valuenow', run.percent ); }
		if ( ui.cancel ) { ui.cancel.hidden = false; }
		ui.runBtns.forEach( function ( b ) { b.hidden = true; } );
		setStatus( ui.status, sprintf( t.progress, run.done + run.failed, run.total ) );
	}

	var failures = 0;

	// Worth trying again: no response at all (network, proxy timeouts such as
	// Cloudflare's 524) or a server error. The item itself is retried server-side.
	function isTemporary( e ) {
		return ! e.status || e.status >= 500 || 408 === e.status || 429 === e.status;
	}

	function loop() {
		if ( ! runId || stopping ) { return; }
		api( 'POST', '/runs/' + runId + '/step' ).then( function ( run ) {
			failures = 0;
			showProgress( run );
			if ( 'running' === run.status ) {
				loop();
			} else {
				setStatus( runUI().status, t.done );
				window.setTimeout( function () { window.location.reload(); }, 800 );
			}
		} ).catch( function ( e ) {
			failures++;
			if ( 401 === e.status || 403 === e.status ) {
				// Logged out or the page is too old: retrying cannot help.
				setStatus( runUI().status, t.sessionExpired, 'error' );
				return;
			}
			if ( ! isTemporary( e ) || failures >= 10 ) {
				setStatus( runUI().status, e.message + ' ' + t.continuesInBackground, 'error' );
				return;
			}
			setStatus( runUI().status, e.message, 'error' );
			window.setTimeout( loop, Math.min( 60000, 5000 * failures ) );
		} );
	}

	$$( '[data-aivt-run]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			// Say what it will cost before spending anything.
			api( 'GET', '/runs/estimate' ).then( function ( estimate ) {
				if ( ! window.confirm( t.confirmRun.replace( '%s', estimate.formatted ) ) ) {
					throw null;
				}
				setStatus( runUI().status, t.starting );
				return api( 'POST', '/runs' );
			} ).then( function ( run ) {
				runId = run.id;
				showProgress( run );
				loop();
			} ).catch( function ( e ) {
				if ( e ) { setStatus( runUI().status, e.message, 'error' ); }
				btn.disabled = false;
			} );
		} );
	} );

	var cancel = $( '[data-aivt-cancel]' );
	if ( cancel ) {
		cancel.addEventListener( 'click', function () {
			if ( ! runId || ! window.confirm( t.confirmStop ) ) { return; }
			stopping = true;
			cancel.disabled = true;
			api( 'POST', '/runs/' + runId + '/cancel' ).then( function () {
				window.location.reload();
			} ).catch( function ( e ) {
				stopping = false;
				cancel.disabled = false;
				setStatus( runUI().status, e.message, 'error' );
				loop();
			} );
		} );
	}

	if ( runId && $( '[data-aivt-runbar]' ) ) {
		api( 'GET', '/runs/' + runId ).then( function ( run ) {
			showProgress( run );
			loop();
		} );
	}

	/* ---------- Answer modal ---------- */
	var modal = $( '[data-aivt-modal]' );

	function renderResult( r ) {
		var summary = r.brand_mentioned === '1' || r.brand_mentioned === 1
			? '<span class="aivt-status-pill is-yes"><span class="dashicons dashicons-yes" aria-hidden="true"></span>' + esc( t.mentioned ) + '</span>'
			: '<span class="aivt-status-pill is-no"><span class="dashicons dashicons-minus" aria-hidden="true"></span>' + esc( t.notMentioned ) + '</span>';
		if ( r.brand_position ) { summary += '<span class="aivt-badge">' + esc( sprintf( t.position, r.brand_position ) ) + '</span>'; }
		if ( parseInt( r.brand_cited, 10 ) ) { summary += '<span class="aivt-badge aivt-badge--brand">' + esc( t.cited ) + '</span>'; }

		var labels = { brand: t.you, competitor: t.competitor, social: t.social, source: t.source };
		var sources = r.citations.length
			? '<ol class="aivt-sources">' + r.citations.map( function ( c ) {
				return '<li><span class="aivt-badge aivt-badge--' + esc( c.type ) + '">' + esc( labels[ c.type ] ) + '</span>' +
					'<a href="' + esc( c.url ) + '" target="_blank" rel="noopener noreferrer">' + esc( c.title || c.domain ) + '</a>' +
					( c.title ? '<span class="aivt-muted aivt-small">' + esc( c.domain ) + '</span>' : '' ) +
					( c.edit ? '<a class="aivt-small" href="' + esc( c.edit ) + '">' + esc( t.editPage ) + '</a>' : '' ) + '</li>';
			} ).join( '' ) + '</ol>'
			: '<p class="aivt-empty">' + esc( t.noSources ) + '</p>';

		var brands = r.mentions.length
			? '<ol class="aivt-mentions">' + r.mentions.map( function ( m ) {
				return '<li class="' + ( parseInt( m.is_brand, 10 ) ? 'is-brand' : '' ) + '">#' + esc( m.position ) + ' ' + esc( m.name ) + '</li>';
			} ).join( '' ) + '</ol>'
			: '<p class="aivt-empty">' + esc( t.noBrands ) + '</p>';

		// response_html is built server-side from escaped text (see Admin::format_answer).
		return '<div class="aivt-modal__summary">' + summary + '</div>' +
			'<div class="aivt-answer">' + r.response_html + '</div>' +
			'<h3>' + esc( t.brands ) + '</h3>' + brands +
			'<h3>' + esc( t.sources ) + '</h3>' + sources;
	}

	if ( modal ) {
		$$( '[data-aivt-result]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var body = $( '[data-aivt-modal-body]', modal );
				body.innerHTML = '<p class="aivt-muted">' + esc( t.loading ) + '</p>';
				$( '[data-aivt-modal-title]', modal ).textContent = '';
				$( '[data-aivt-modal-prompt]', modal ).textContent = '';
				modal.showModal();
				api( 'GET', '/results/' + btn.getAttribute( 'data-aivt-result' ) ).then( function ( r ) {
					var title = $( '[data-aivt-modal-title]', modal );
					title.innerHTML = '<span class="aivt-engine-tag aivt-engine--' + esc( r.engine ) + '"><span class="aivt-swatch" aria-hidden="true"></span>' + esc( r.engine_label ) + '</span>' +
						'<span class="aivt-muted aivt-small">' + esc( r.model ) + '</span>';
					$( '[data-aivt-modal-prompt]', modal ).textContent = '“' + r.prompt + '”';
					body.innerHTML = renderResult( r );
				} ).catch( function ( e ) {
					body.innerHTML = '<p class="aivt-status is-error">' + esc( e.message ) + '</p>';
				} );
			} );
		} );
		$( '[data-aivt-modal-close]', modal ).addEventListener( 'click', function () { modal.close(); } );
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal ) { modal.close(); }
		} );
	}

	/* ---------- Trend chart hover ---------- */
	$$( '[data-aivt-chart]' ).forEach( function ( chart ) {
		var svg = $( 'svg', chart );
		var tip = $( '[data-aivt-tooltip]', chart );
		var cross = $( '[data-aivt-crosshair]', chart );
		var vb = svg.viewBox.baseVal;

		function show( hit ) {
			var x = parseFloat( hit.getAttribute( 'data-x' ) );
			var rows = JSON.parse( hit.getAttribute( 'data-rows' ) || '[]' );
			cross.setAttribute( 'x1', x );
			cross.setAttribute( 'x2', x );
			cross.removeAttribute( 'hidden' );
			tip.innerHTML = '<strong>' + esc( hit.getAttribute( 'data-title' ) ) + '</strong>' + rows.map( function ( r ) {
				return '<div class="aivt-engine--' + esc( r.engine ) + '"><span class="aivt-swatch"></span><span>' + esc( r.label ) + '</span><span>' + esc( r.value ) + '</span></div>';
			} ).join( '' );
			tip.hidden = false;
			var scale = svg.getBoundingClientRect().width / vb.width;
			var left = Math.min( Math.max( x * scale, 80 ), svg.getBoundingClientRect().width - 80 );
			tip.style.left = left + 'px';
			tip.style.top = '8px';
		}
		function hide() {
			tip.hidden = true;
			cross.setAttribute( 'hidden', '' );
		}
		$$( '.aivt-chart__hit', svg ).forEach( function ( hit ) {
			hit.addEventListener( 'mouseenter', function () { show( hit ); } );
		} );
		svg.addEventListener( 'mouseleave', hide );
	} );
}() );
