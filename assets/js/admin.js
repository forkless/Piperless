/* global piperlessAdmin */
( function ( $ ) {
	'use strict';

	const admin = piperlessAdmin || {};

	// ── Cache stats on load ───────────────────────────────────────────────
	$( function () {
		refreshCacheStats();
		refreshLog();
		initPreview();
	} );

	// ── Test Piper ────────────────────────────────────────────────────────
	$( '#piperless-test-piper' ).on( 'click', function () {
		const $btn    = $( this );
		const $result = $( '#piperless-test-result' );

		$btn.prop( 'disabled', true );
		$result.html( '<span style="color:#666;">' + admin.i18n.testing + '</span>' );

		$.post( admin.ajaxUrl, {
			action: 'piperless_test_piper',
			nonce:  admin.nonce,
		} )
			.done( function ( resp ) {
				if ( resp.success ) {
					const version = resp.data.version
						? ' (v' + resp.data.version + ')'
						: '';
					const mode = resp.data.mode
						? ' [' + resp.data.mode + ' mode]'
						: '';
					$result.html(
						'<span style="color:#008a20;">&#10003; ' +
							admin.i18n.testOk + version + mode +
							'</span>'
					);
				} else {
					$result.html(
						'<span style="color:#d63638;">&#10007; ' +
							( resp.data.error || admin.i18n.testFail ) +
							'</span>'
					);
				}
			} )
			.fail( function () {
				$result.html(
					'<span style="color:#d63638;">&#10007; ' +
						admin.i18n.testFail +
						'</span>'
				);
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	// ── Cache Management ──────────────────────────────────────────────────
	function refreshCacheStats() {
		$.post( admin.ajaxUrl, {
			action: 'piperless_cache_stats',
			nonce:  admin.nonce,
		} ).done( function ( resp ) {
			if ( resp.success ) {
				const s = resp.data;
				const sizeMB = s.total_bytes
					? ( s.total_bytes / 1048576 ).toFixed( 2 )
					: '0.00';
				$( '#piperless-cache-stats' ).html(
					'<p>' +
						s.cached_files +
						' file(s) cached &middot; ' +
						sizeMB +
						' MB total' +
						'</p>'
				);
			}
		} );
	}

	$( '#piperless-flush-cache' ).on( 'click', function () {
		if ( ! confirm( admin.i18n.clearConfirm ) ) {
			return;
		}

		const $result = $( '#piperless-cache-result' );

		$.post( admin.ajaxUrl, {
			action: 'piperless_flush_cache',
			nonce:  admin.nonce,
		} )
			.done( function ( resp ) {
				if ( resp.success ) {
					$result.html(
						'<span style="color:#008a20;">' +
							resp.data.deleted +
							' file(s) ' +
							admin.i18n.flushed +
							'</span>'
					);
					refreshCacheStats();
					loadCacheBrowser( 1 );
				}
			} )
			.fail( function () {
				$result.html(
					'<span style="color:#d63638;">Error</span>'
				);
			} );
	} );

	$( '#piperless-clear-orphans' ).on( 'click', function () {
		if ( ! confirm( admin.i18n.orphansConfirm ) ) {
			return;
		}

		const $result = $( '#piperless-cache-result' );

		$.post( admin.ajaxUrl, {
			action: 'piperless_clear_orphans',
			nonce:  admin.nonce,
		} )
			.done( function ( resp ) {
				if ( resp.success ) {
					$result.html(
						'<span style="color:#008a20;">' +
							resp.data.deleted +
							' file(s) ' +
							admin.i18n.orphansCleared +
							'</span>'
					);
					refreshCacheStats();
					loadCacheBrowser( 1 );
				}
			} )
			.fail( function () {
				$result.html(
					'<span style="color:#d63638;">Error</span>'
				);
			} );
	} );

	// ── Log Viewer ────────────────────────────────────────────────────────
	function refreshLog() {
		const $log = $( '#piperless-log-viewer' );

		$.post( admin.ajaxUrl, {
			action: 'piperless_log_tail',
			nonce:  admin.nonce,
		} ).done( function ( resp ) {
			if ( resp.success && resp.data.lines ) {
				if ( resp.data.lines.length > 0 ) {
					$log.text( resp.data.lines.join( '\n' ) );
				} else {
					$log.text( '(Log empty.)' );
				}
			} else {
				$log.text( 'Log file not found at ' + ( resp.data.path || 'unknown path' ) );
			}
		} ).fail( function () {
			$log.text( 'Failed to load log.' );
		} );
	}

	$( '#piperless-refresh-log' ).on( 'click', refreshLog );

	$( '#piperless-clear-log' ).on( 'click', function () {
		$.post( admin.ajaxUrl, {
			action: 'piperless_clear_log',
			nonce:  admin.nonce,
		} ).done( function ( resp ) {
			if ( resp.success ) {
				$( '#piperless-log-viewer' ).text( admin.i18n.logCleared );
			}
		} );
	} );

	// ── Player Style Preview ──────────────────────────────────────────────
	function initPreview() {
		const $select = $( '#player_style' );
		const $preview = $( '#piperless-preview-player' );

		if ( ! $select.length || ! $preview.length ) {
			return;
		}

		// Apply initial style.
		applyStyle( $select.val() );

		// Listen for changes.
		$select.on( 'change', function () {
			applyStyle( $( this ).val() );
		} );

		function applyStyle( style ) {
			// Remove all theme classes.
			$preview.removeClass( function ( index, className ) {
				return ( className.match( /(^|\s)piperless-player--\S+/g ) || [] )
					.join( ' ' );
			} );

			// Add the new theme class.
			if ( style && style !== 'custom' ) {
				$preview.addClass( 'piperless-player--' + style );
			} else if ( style === 'custom' ) {
				$preview.addClass( 'piperless-player--classic' );
			}
		}
	}

	// ── Cache Browser ─────────────────────────────────────────────────────
	let cachePage     = 1;
	let cacheSortKey  = 'created';
	let cacheSortAsc  = false;

	function loadCacheBrowser( page ) {
		const $browser = $( '#piperless-cache-browser' );
		$browser.html( '<p>' + 'Loading…' + '</p>' );

		$.post( admin.ajaxUrl, {
			action: 'piperless_cache_browse',
			nonce:  admin.nonce,
			page:   page || 1,
			per_page: 15,
		} ).done( function ( resp ) {
			if ( ! resp.success || ! resp.data ) {
				$browser.html( '<p>Failed to load cache entries.</p>' );
				return;
			}

			const d    = resp.data;
			cachePage  = page || 1;
			let html   = '';

			if ( d.total === 0 ) {
				html = '<p>No cached audio files.</p>';
			} else {
				html += '<table class="wp-list-table widefat fixed striped">';
				html += '<thead><tr>';
				html += '<th><input type="checkbox" class="piperless-select-all"></th>';
				html += '<th>Post ID</th><th>Title</th>';
				html += '<th class="piperless-sortable" data-sort="size">Size <span class="piperless-sort-arrow"></span></th>';
				html += '<th class="piperless-sortable" data-sort="created">Created <span class="piperless-sort-arrow"></span></th>';
				html += '<th>Format</th><th>Model</th><th>Shortcode</th><th>Status</th><th>Actions</th>';
				html += '</tr></thead><tbody>';

				// Apply client-side sort if active.
				let entries = d.entries;
				if ( cacheSortKey === 'size' ) {
					entries = entries.slice().sort( function ( a, b ) {
						return cacheSortAsc
							? ( a.size_bytes || 0 ) - ( b.size_bytes || 0 )
							: ( b.size_bytes || 0 ) - ( a.size_bytes || 0 );
					} );
				} else if ( cacheSortKey === 'created' ) {
					entries = entries.slice().sort( function ( a, b ) {
						const da = a.created || '';
						const db = b.created || '';
						return cacheSortAsc ? da.localeCompare( db ) : db.localeCompare( da );
					} );
				}

				entries.forEach( function ( entry ) {
					const sizeKB = ( entry.size_bytes / 1024 ).toFixed( 1 );
					const postIdCell = entry.post_id
						? '<a href="' + entry.edit_url + '">#' + entry.post_id + '</a>'
						: '—';
					const titleCell = entry.post_id
						? '<a href="' + entry.edit_url + '">' + escHtml( entry.post_title ) + '</a>'
						: '—';
					const bitrateSuffix = entry.bitrate ? ' &middot; ' + entry.bitrate : '';
					const formatBadge = entry.orphaned
						? '<span class="piperless-cache-badge piperless-cache-badge--orphan">—</span>'
						: ( entry.has_mp3
							? '<span class="piperless-cache-badge piperless-cache-badge--ok">MP3' + bitrateSuffix + '</span>'
							: '<span class="piperless-cache-badge piperless-cache-badge--wav">WAV only</span>'
						);
					const statusBadge = entry.enabled
						? '<span class="piperless-cache-badge piperless-cache-badge--ok">Enabled</span>'
						: '<span class="piperless-cache-badge piperless-cache-badge--orphan">Orphaned</span>';

					html += '<tr data-key="' + entry.key + '">';
					html += '<td><input type="checkbox" class="piperless-cache-checkbox" data-key="' + entry.key + '"></td>';
					html += '<td>' + postIdCell + '</td>';
					html += '<td>' + titleCell + '</td>';
					html += '<td>' + sizeKB + ' KB</td>';
					html += '<td>' + ( entry.created || '—' ) + '</td>';
					html += '<td>' + formatBadge + '</td>';
					html += '<td><code>' + ( entry.model || '—' ) + '</code></td>';
					html += '<td><code>' + ( entry.post_id ? '[piperless_player post_id="' + entry.post_id + '"]' : '—' ) + '</code></td>';
					html += '<td>' + statusBadge + '</td>';
					html += '<td>';
					html += '<button type="button" class="button button-small piperless-cache-preview-btn" data-url="' + entry.proxy_url + '">&#9654; Play</button> ';
					html += '<button type="button" class="button button-small piperless-cache-delete-btn" data-key="' + entry.key + '" style="color:#b32d2e;">Delete</button>';
					html += '<audio class="piperless-cache-audio" style="display:none;"></audio>';
					html += '</td>';
					html += '</tr>';
				} );

				html += '</tbody></table>';

				// Pagination.
				if ( d.pages > 1 ) {
					html += '<div class="tablenav"><div class="tablenav-pages">';
					html += '<span class="displaying-num">' + d.total + ' items</span>';
					for ( let i = 1; i <= d.pages; i++ ) {
						if ( i === cachePage ) {
							html += '<span class="page-numbers current">' + i + '</span>';
						} else {
							html += '<a href="#" class="page-numbers piperless-cache-page" data-page="' + i + '">' + i + '</a>';
						}
					}
					html += '</div></div>';
				}
			}

			$browser.html( html );

			// Select-all checkbox.
			$browser.find( '.piperless-select-all' ).on( 'change', function () {
				$browser.find( '.piperless-cache-checkbox' ).prop( 'checked', this.checked );
			} );

			// Update sort arrows — active gets ▲/▼, inactive gets ⇅.
			$browser.find( '.piperless-sortable[data-sort="size"] .piperless-sort-arrow' )
				.text( cacheSortKey === 'size' ? ( cacheSortAsc ? ' ▲' : ' ▼' ) : ' ⇅' );
			$browser.find( '.piperless-sortable[data-sort="created"] .piperless-sort-arrow' )
				.text( cacheSortKey === 'created' ? ( cacheSortAsc ? ' ▲' : ' ▼' ) : ' ⇅' );
		} ).fail( function () {
			$browser.html( '<p>Failed to load cache entries.</p>' );
		} );
	}

	function escHtml( str ) {
		const div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	$( document ).on( 'click', '.piperless-cache-page', function ( e ) {
		e.preventDefault();
		loadCacheBrowser( parseInt( $( this ).data( 'page' ) ) );
	} );

	$( document ).on( 'click', '.piperless-sortable', function () {
		const key = $( this ).data( 'sort' );
		if ( cacheSortKey === key ) {
			cacheSortAsc = ! cacheSortAsc;
		} else {
			cacheSortKey = key;
			cacheSortAsc = true;
		}
		loadCacheBrowser( cachePage );
	} );

	$( document ).on( 'click', '.piperless-cache-preview-btn', function () {
		const $btn   = $( this );
		const $audio = $btn.closest( 'tr' ).find( '.piperless-cache-audio' )[ 0 ];
		const url    = $btn.data( 'url' );

		if ( ! $audio ) return;

		if ( $audio.src && ! $audio.paused ) {
			$audio.pause();
			$btn.text( '▶ Play' );
			return;
		}

		if ( $audio.src && $audio.paused ) {
			$audio.play();
			$btn.text( '⏸ Pause' );
			return;
		}

		$audio.src = url;
		$audio.load();
		$audio.play().then( function () {
			$btn.text( '⏸ Pause' );
		} ).catch( function () {} );
	} );

	$( document ).on( 'play pause ended', '.piperless-cache-audio', function () {
		const $audio = $( this );
		const $btn   = $audio.closest( 'tr' ).find( '.piperless-cache-preview-btn' );
		if ( $audio[ 0 ].paused ) {
			$btn.text( '▶ Play' );
		} else {
			$btn.text( '⏸ Pause' );
		}
	} );

	$( '#piperless-delete-selected' ).on( 'click', function () {
		const $checked = $( '.piperless-cache-checkbox:checked' );
		if ( $checked.length === 0 ) {
			alert( 'No entries selected.' );
			return;
		}
		if ( ! confirm( 'Delete ' + $checked.length + ' selected cache entr' + ( $checked.length === 1 ? 'y' : 'ies' ) + '?' ) ) {
			return;
		}

		const keys = $checked.map( function () { return $( this ).data( 'key' ); } ).get();
		const $btn = $( this );
		$btn.prop( 'disabled', true ).text( 'Deleting…' );

		let completed = 0;
		keys.forEach( function ( key ) {
			$.post( admin.ajaxUrl, {
				action: 'piperless_cache_delete_entry',
				nonce:  admin.nonce,
				key:    key,
			} ).always( function () {
				completed++;
				if ( completed >= keys.length ) {
					$btn.prop( 'disabled', false ).text( 'Delete Selected' );
					refreshCacheStats();
					loadCacheBrowser( cachePage );
				}
			} );
		} );
	} );

	$( document ).on( 'click', '.piperless-cache-delete-btn', function () {
		if ( ! confirm( 'Delete this cache entry?' ) ) return;

		const $btn = $( this );
		const key  = $btn.data( 'key' );

		$btn.prop( 'disabled', true ).text( '…' );

		$.post( admin.ajaxUrl, {
			action: 'piperless_cache_delete_entry',
			nonce:  admin.nonce,
			key:    key,
		} ).done( function ( resp ) {
			if ( resp.success && resp.data.deleted ) {
				$btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
			}
			refreshCacheStats();
		} ).always( function () {
			$btn.prop( 'disabled', false ).text( 'Delete' );
		} );
	} );

	// Load cache browser when the cache tab is activated.
	$( '.nav-tab' ).on( 'click', function () {
		// Small delay so the tab content is visible.
		setTimeout( function () {
			if ( $( '#piperless-tab-cache' ).is( ':visible' ) ) {
				loadCacheBrowser( 1 );
				refreshCacheStats();
			}
		}, 100 );
	} );

	// Initial load if cache tab is active.
	if ( $( '#piperless-tab-cache' ).is( ':visible' ) ) {
		setTimeout( function () {
			loadCacheBrowser( 1 );
			refreshCacheStats();
		}, 100 );
	}

	// ── Model Voice Previews ──────────────────────────────────────────────
	/**
	 * Currently active preview audio element, so only one plays at a time.
	 * @type {HTMLAudioElement|null}
	 */
	let activeModelAudio = null;

	$( '.piperless-model-preview-btn' ).on( 'click', function () {
		const $btn        = $( this );
		const $row        = $btn.closest( 'tr' );
		const modelPath   = $btn.data( 'model-path' );
		const quality     = $btn.data( 'quality' ) || 'medium';
		const $audio      = $row.find( '.piperless-model-audio' )[ 0 ];
		const $playIcon   = $btn.find( '.piperless-preview-icon-play' );
		const $pauseIcon  = $btn.find( '.piperless-preview-icon-pause' );

		if ( ! $audio ) {
			return;
		}

		// If this audio is already loaded and playing, pause it.
		if ( activeModelAudio === $audio && ! $audio.paused ) {
			$audio.pause();
			return;
		}

		// If this audio has a src and is paused, play it.
		if ( activeModelAudio === $audio && $audio.paused && $audio.src ) {
			$audio.play();
			return;
		}

		// Stop any other playing audio.
		if ( activeModelAudio && activeModelAudio !== $audio ) {
			activeModelAudio.pause();
			const $otherBtn = $( activeModelAudio ).closest( 'tr' ).find( '.piperless-model-preview-btn' );
			$otherBtn.removeClass( 'piperless-preview-loading piperless-preview-playing' );
			$otherBtn.find( '.piperless-preview-icon-play' ).show();
			$otherBtn.find( '.piperless-preview-icon-pause' ).hide();
		}

		// If already loaded, play.
		if ( $audio.src && ! $audio.src.endsWith( '#' ) ) {
			$audio.play();
			activeModelAudio = $audio;
			return;
		}

		// Generate the preview.
		$btn.addClass( 'piperless-preview-loading' );
		$btn.prop( 'disabled', true );
		$playIcon.hide();
		$pauseIcon.hide();
		$btn.find( '.piperless-preview-label' ).text( admin.i18n.generatingPreview );

		$.post( admin.ajaxUrl, {
			action: 'piperless_model_preview',
			nonce:  admin.nonce,
			model_path: modelPath,
			quality: quality,
		} )
			.done( function ( resp ) {
				if ( resp.success && resp.data.url ) {
					$audio.src = resp.data.url;
					$audio.load();
					$audio.play().then( function () {
						activeModelAudio = $audio;
					} ).catch( function () {} ).finally( function () {
						// Always restore button state after play attempt.
						$btn.removeClass( 'piperless-preview-loading' );
						$btn.prop( 'disabled', false );
					} );
				} else {
					$btn.removeClass( 'piperless-preview-loading' );
					$btn.prop( 'disabled', false );
					$playIcon.show();
					alert( resp.data.message || admin.i18n.previewFailed );
				}
			} )
			.fail( function () {
				$btn.removeClass( 'piperless-preview-loading' );
				$btn.prop( 'disabled', false );
				$playIcon.show();
				alert( admin.i18n.previewFailed );
			} );
	} );

	// Update button icons based on audio state.
	$( document ).on( 'play pause ended', '.piperless-model-audio', function () {
		const $audio = $( this );
		const $btn   = $audio.closest( 'tr' ).find( '.piperless-model-preview-btn' );
		const $playIcon  = $btn.find( '.piperless-preview-icon-play' );
		const $pauseIcon = $btn.find( '.piperless-preview-icon-pause' );

		$btn.removeClass( 'piperless-preview-loading' );
		$btn.prop( 'disabled', false );

		if ( ! $audio[ 0 ].paused ) {
			$btn.addClass( 'piperless-preview-playing' );
			$playIcon.hide();
			$pauseIcon.show();
		} else {
			$btn.removeClass( 'piperless-preview-playing' );
			$playIcon.show();
			$pauseIcon.hide();
		}
	} );

	// ── Model table sorting ──────────────────────────────────────────────
	let modelSortKey = 'voice';
	let modelSortAsc = true;

	// Initialise sort arrows on load.
	$( function () {
		$( '.piperless-model-table .piperless-sortable[data-sort="voice"] .piperless-sort-arrow' )
			.text( modelSortKey === 'voice' ? ( modelSortAsc ? ' ▲' : ' ▼' ) : ' ⇅' );
		$( '.piperless-model-table .piperless-sortable[data-sort="quality"] .piperless-sort-arrow' )
			.text( modelSortKey === 'quality' ? ( modelSortAsc ? ' ▲' : ' ▼' ) : ' ⇅' );
	} );

	// Quality tier priority map for natural ordering (low → medium → high).
	const qualityOrder = { low: 0, lite: 0, small: 0, fast: 1, medium: 2, quality: 3, high: 3 };

	function sortModelTable() {
		const $tbody = $( '.piperless-model-table tbody' );
		if ( ! $tbody.length ) return;

		const $rows = $tbody.find( 'tr' ).get();

		$rows.sort( function ( a, b ) {
			const valA = $( a ).data( modelSortKey ) || '';
			const valB = $( b ).data( modelSortKey ) || '';

			let cmp;
			if ( modelSortKey === 'quality' ) {
				const orderA = qualityOrder[ valA ] !== undefined ? qualityOrder[ valA ] : 99;
				const orderB = qualityOrder[ valB ] !== undefined ? qualityOrder[ valB ] : 99;
				cmp = orderA - orderB;
			} else {
				cmp = valA.localeCompare( valB );
			}

			return modelSortAsc ? cmp : -cmp;
		} );

		$.each( $rows, function ( i, row ) {
			$tbody.append( row );
		} );

		// Update sort arrows.
		$( '.piperless-model-table .piperless-sortable[data-sort="voice"] .piperless-sort-arrow' )
			.text( modelSortKey === 'voice' ? ( modelSortAsc ? ' ▲' : ' ▼' ) : ' ⇅' );
		$( '.piperless-model-table .piperless-sortable[data-sort="quality"] .piperless-sort-arrow' )
			.text( modelSortKey === 'quality' ? ( modelSortAsc ? ' ▲' : ' ▼' ) : ' ⇅' );
	}

	$( document ).on( 'click', '.piperless-model-table .piperless-sortable', function () {
		const key = $( this ).data( 'sort' );
		if ( modelSortKey === key ) {
			modelSortAsc = ! modelSortAsc;
		} else {
			modelSortKey = key;
			modelSortAsc = true;
		}
		sortModelTable();
	} );

	// ── Show Duration preview ────────────────────────────────────────────
	const $showMeta = $( '#player_show_meta' );
	const $previewDuration = $( '#piperless-preview-player .piperless-player__duration' );
	const $previewSeparator = $( '#piperless-preview-player .piperless-player__separator' );

	function toggleDurationPreview() {
		if ( $showMeta.is( ':checked' ) ) {
			$previewDuration.show();
			$previewSeparator.show();
		} else {
			$previewDuration.hide();
			$previewSeparator.hide();
		}
	}

	$showMeta.on( 'change', toggleDurationPreview );
	toggleDurationPreview();

	// ── Custom CSS field visibility ───────────────────────────────────────
	const $styleSelect = $( '#player_style' );
	const $customRow = $( '#player_custom_css' ).closest( 'tr' );

	function toggleCustomCSS() {
		if ( $styleSelect.val() === 'custom' ) {
			$customRow.show();
		} else {
			$customRow.hide();
		}
	}

	$styleSelect.on( 'change', toggleCustomCSS );
	toggleCustomCSS();
} )( jQuery );
