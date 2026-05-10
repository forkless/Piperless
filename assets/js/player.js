/**
 * Piperless Audio Player
 *
 * Lightweight custom HTML5 audio player with:
 *   - Play / Pause toggle
 *   - Clickable progress bar with seeking
 *   - Current time display
 *   - Duration display
 *   - Buffered progress indication
 *
 * @package Piperless
 */

( function () {
	'use strict';

	/**
	 * Format seconds as mm:ss.
	 *
	 * @param {number} secs Duration in seconds.
	 * @return {string}
	 */
	function formatTime( secs ) {
		if ( isNaN( secs ) || ! isFinite( secs ) ) {
			return '00:00';
		}
		const m = Math.floor( secs / 60 );
		const s = Math.floor( secs % 60 );
		return String( m ).padStart( 2, '0' ) + ':' + String( s ).padStart( 2, '0' );
	}

	/**
	 * Initialise all piperless players on the page.
	 */
	function initPlayers() {
		const containers = document.querySelectorAll( '.piperless-player' );

		containers.forEach( function ( container ) {
			if ( container.dataset.piperlessInitialised ) {
				return;
			}
			container.dataset.piperlessInitialised = '1';

			const audio     = container.querySelector( '.piperless-player__audio' );
			const playBtn   = container.querySelector( '.piperless-player__play' );
			const progress  = container.querySelector( '.piperless-player__progress' );
			const bar       = container.querySelector( '.piperless-player__progress-bar' );
			const buffered  = container.querySelector( '.piperless-player__progress-buffered' );
			const current   = container.querySelector( '.piperless-player__current' );
			const duration  = container.querySelector( '.piperless-player__duration' );
			const volBtn    = container.querySelector( '.piperless-player__volume-btn' );
			const volSlider = container.querySelector( '.piperless-player__volume-slider input' );

			if ( ! audio || ! playBtn ) {
				return;
			}

			/** @type {boolean} */
			let seeking = false;

			// ── Play / Pause ─────────────────────────────────────────────
			playBtn.addEventListener( 'click', function () {
				if ( audio.paused ) {
					audio.play().catch( function () {} );
				} else {
					audio.pause();
				}
			} );

			audio.addEventListener( 'play', function () {
				container.classList.add( 'piperless-player--playing' );
			} );

			audio.addEventListener( 'pause', function () {
				container.classList.remove( 'piperless-player--playing' );
			} );

			// ── Progress bar & seeking ───────────────────────────────────
			if ( progress ) {
				progress.addEventListener( 'mousedown', function ( e ) {
					seeking = true;
					seek( e );
				} );

				document.addEventListener( 'mousemove', function ( e ) {
					if ( seeking ) {
						seek( e );
					}
				} );

				document.addEventListener( 'mouseup', function () {
					seeking = false;
				} );

				// Touch support.
				progress.addEventListener( 'touchstart', function ( e ) {
					seeking = true;
					seek( e.touches[ 0 ] );
				} );

				document.addEventListener( 'touchmove', function ( e ) {
					if ( seeking ) {
						seek( e.touches[ 0 ] );
					}
				} );

				document.addEventListener( 'touchend', function () {
					seeking = false;
				} );
			}

			function seek( e ) {
				const rect    = progress.getBoundingClientRect();
				let   pct     = ( e.clientX - rect.left ) / rect.width;
				pct = Math.max( 0, Math.min( 1, pct ) );

				if ( audio.duration && isFinite( audio.duration ) ) {
					audio.currentTime = pct * audio.duration;
				}

				if ( bar ) {
					bar.style.width = ( pct * 100 ) + '%';
				}
			}

			// ── Time update ──────────────────────────────────────────────
			audio.addEventListener( 'timeupdate', function () {
				if ( seeking || ! isFinite( audio.duration ) ) {
					return;
				}

				const pct = ( audio.currentTime / audio.duration ) * 100;

				if ( bar ) {
					bar.style.width = pct + '%';
				}

				if ( current ) {
					current.textContent = formatTime( audio.currentTime );
				}
			} );

			// ── Buffer progress ──────────────────────────────────────────
			audio.addEventListener( 'progress', function () {
				if ( ! buffered || audio.buffered.length === 0 ) {
					return;
				}

				const bufEnd = audio.buffered.end( audio.buffered.length - 1 );
				if ( audio.duration && isFinite( audio.duration ) ) {
					const pct = ( bufEnd / audio.duration ) * 100;
					buffered.style.width = pct + '%';
				}
			} );

			// ── Metadata loaded ──────────────────────────────────────────
			audio.addEventListener( 'loadedmetadata', function () {
				if ( current ) {
					current.textContent = '00:00';
				}
				// Duration comes from meta attribute if available.
				if ( duration && audio.duration && isFinite( audio.duration ) ) {
					duration.textContent = formatTime( audio.duration );
				}
			} );

			// ── End ──────────────────────────────────────────────────────
			audio.addEventListener( 'ended', function () {
				container.classList.remove( 'piperless-player--playing' );
				if ( bar ) {
					bar.style.width = '0%';
				}
				if ( current ) {
					current.textContent = '00:00';
				}
			} );

			// ── Error ────────────────────────────────────────────────────
			audio.addEventListener( 'error', function () {
				container.classList.add( 'piperless-player--error' );
			} );

			// ── Volume ───────────────────────────────────────────────────
			if ( volBtn && volSlider ) {
				volBtn.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					container.classList.toggle( 'piperless-player--volume-open' );
				} );

				// Close volume on outside click.
				document.addEventListener( 'click', function ( e ) {
					if ( ! container.contains( e.target ) ) {
						container.classList.remove( 'piperless-player--volume-open' );
					}
				} );

				volSlider.style.setProperty( '--vol-pct', volSlider.value + '%' );

				volSlider.addEventListener( 'input', function () {
					audio.volume = volSlider.value / 100;
					volSlider.style.setProperty( '--vol-pct', volSlider.value + '%' );
				} );
			}
		} );
	}

	// Initialise on DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initPlayers );
	} else {
		initPlayers();
	}

	// Re-scan if content is dynamically loaded (e.g. infinite scroll).
	if ( window.MutationObserver ) {
		const observer = new MutationObserver( function ( mutations ) {
			let added = false;
			for ( let i = 0; i < mutations.length; i++ ) {
				if ( mutations[ i ].addedNodes.length ) {
					added = true;
					break;
				}
			}
			if ( added ) {
				initPlayers();
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}
} )();
