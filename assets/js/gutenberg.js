/**
 * Piperless Gutenberg Sidebar Plugin
 *
 * Registers a PluginSidebar panel in the post editor with:
 *   - Generate / Regenerate audio button
 *   - Per-post voice, language, quality overrides
 *   - Audio status display with preview player
 *
 * @package Piperless
 */

( function ( wp ) {
	const { __ } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginSidebar } = wp.editPost;
	const {
		PanelBody,
		PanelRow,
		Button,
		SelectControl,
		TextControl,
		Notice,
		Spinner,
	} = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const { useState, useEffect, createElement } = wp.element;
	const apiFetch = wp.apiFetch;

	/**
	 * Format seconds to mm:ss.
	 */
	function formatDuration( secs ) {
		if ( ! secs || ! isFinite( secs ) ) return '';
		const m = Math.floor( secs / 60 );
		const s = Math.floor( secs % 60 );
		return String( m ).padStart( 2, '0' ) + ':' + String( s ).padStart( 2, '0' );
	}

	/**
	 * Main sidebar component.
	 */
	function PiperlessSidebar() {
		const postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		} );

		const postMeta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		} );

		const { editPost } = useDispatch( 'core/editor' );
		const { createNotice } = useDispatch( 'core/notices' );

		const [ generating, setGenerating ]     = useState( false );
		const [ audioUrl, setAudioUrl ]         = useState( null );
		const [ audioFormat, setAudioFormat ]   = useState( 'mp3' );
		const [ audioDuration, setAudioDuration ] = useState( null );
		const [ models, setModels ]             = useState( [] );
		const [ voices, setVoices ]             = useState( [] );
		const [ voiceAliases, setVoiceAliases ] = useState( {} );
		const [ languagesList, setLanguages ]   = useState( [] );
		const [ qualitiesList, setQualities ]   = useState( [] );
		const [ error, setError ]               = useState( null );

		// Load initial audio status and models on mount.
		useEffect( function () {
			if ( ! postId ) return;

			// Load audio status.
			apiFetch( { path: '/piperless/v1/status/' + postId } )
				.then( function ( data ) {
					if ( data.has_audio ) {
						setAudioUrl( data.url );
						setAudioFormat( data.format || 'mp3' );
						setAudioDuration( data.duration );
					}
				} )
				.catch( function () {} );

			// Load available models.
			apiFetch( { path: '/piperless/v1/models' } )
				.then( function ( data ) {
					setModels( data.models || [] );
					setVoices( data.voices || [] );
					setVoiceAliases( data.voice_aliases || {} );
					setLanguages( data.languages || [] );
					setQualities( data.qualities || [] );
				} )
				.catch( function () {} );
		}, [ postId ] );

		// Handle generate click.
		function handleGenerate() {
			if ( audioUrl && ! confirm( piperlessEditor.i18n.confirmRegen ) ) {
				return;
			}

			setGenerating( true );
			setError( null );

			apiFetch( {
				path: '/piperless/v1/generate',
				method: 'POST',
				data: {
					post_id: postId,
					voice: postMeta._piperless_voice || '',
					language: postMeta._piperless_language || '',
					quality: postMeta._piperless_quality || '',
				},
			} )
				.then( function ( data ) {
					setAudioUrl( data.url );
					setAudioFormat( data.format || 'mp3' );
					setAudioDuration( data.duration );
					setGenerating( false );
					createNotice( 'success', piperlessEditor.i18n.success, {
						type: 'snackbar',
					} );
				} )
				.catch( function ( err ) {
					setError( err.message || piperlessEditor.i18n.error );
					setGenerating( false );
				} );
		}

		// Handle remove.
		function handleRemove() {
			apiFetch( {
				path: '/piperless/v1/audio/' + postId,
				method: 'DELETE',
			} )
				.then( function () {
					setAudioUrl( null );
					setAudioDuration( null );
				} );
		}

		// Build voice options — use alias as label when available.
		const voiceOptions = voices.map( function ( v ) {
			var alias = voiceAliases[ v ] || '';
			return { value: v, label: alias || v };
		} );

		const langOptions = languagesList.map( function ( l ) {
			return { value: l, label: l };
		} );

		const qualityOptions = qualitiesList.map( function ( q ) {
			return { value: q, label: q.charAt( 0 ).toUpperCase() + q.slice( 1 ) };
		} );

		if ( qualityOptions.length === 0 ) {
			qualityOptions.push(
				{ value: 'low', label: 'Low' },
				{ value: 'medium', label: 'Medium' },
				{ value: 'high', label: 'High' }
			);
		}

		function updateMeta( key, value ) {
			editPost( { meta: { [ key ]: value } } );
		}

		return createElement(
			PluginSidebar,
			{
				name: 'piperless-sidebar',
				title: piperlessEditor.i18n.title,
				icon: 'controls-volumeon',
			},
			// ── Generation Controls ─────────────────────────────────────
			createElement(
				PanelBody,
				{ title: __( 'Generation', 'piperless' ), initialOpen: true },
				createElement( PanelRow, null,
					createElement( Button, {
						variant: 'primary',
						onClick: handleGenerate,
						disabled: generating,
						style: { width: '100%', justifyContent: 'center' },
					},
						generating
							? createElement( 'span', null,
								createElement( Spinner, null ),
								' ' + piperlessEditor.i18n.generating
							)
							: ( audioUrl
								? piperlessEditor.i18n.regenerate
								: piperlessEditor.i18n.generate
							)
					)
				),
				createElement( PanelRow, null,
					createElement( TextControl, {
						label: piperlessEditor.i18n.titleLabel,
						placeholder: piperlessEditor.i18n.titlePlaceholder,
						value: postMeta._piperless_title || '',
						onChange: function ( val ) { updateMeta( '_piperless_title', val ); },
					} )
				),
				audioUrl && createElement( PanelRow, null,
					createElement( Button, {
						variant: 'link',
						isDestructive: true,
						onClick: handleRemove,
					}, piperlessEditor.i18n.remove )
				),
				error && createElement( PanelRow, null,
					createElement( Notice, { status: 'error', isDismissible: false },
						error
					)
				)
			),
			// ── Voice / Language / Quality ──────────────────────────────
			createElement(
				PanelBody,
				{ title: __( 'Voice Settings', 'piperless' ), initialOpen: false },
				voices.length > 0 && createElement( SelectControl, {
					label: piperlessEditor.i18n.voiceLabel,
					value: postMeta._piperless_voice || '',
					options: [ { value: '', label: '— Default —' } ].concat( voiceOptions ),
					onChange: function ( val ) { updateMeta( '_piperless_voice', val ); },
				} ),
				languagesList.length > 0 && createElement( SelectControl, {
					label: piperlessEditor.i18n.languageLabel,
					value: postMeta._piperless_language || '',
					options: [ { value: '', label: '— Default —' } ].concat( langOptions ),
					onChange: function ( val ) { updateMeta( '_piperless_language', val ); },
				} ),
				createElement( SelectControl, {
					label: piperlessEditor.i18n.qualityLabel,
					value: postMeta._piperless_quality || '',
					options: [ { value: '', label: '— Default —' } ].concat( qualityOptions ),
					onChange: function ( val ) { updateMeta( '_piperless_quality', val ); },
				} ),
				createElement( TextControl, {
					label: piperlessEditor.i18n.sentenceSilenceLabel || 'Sentence Silence',
					help: piperlessEditor.i18n.sentenceSilenceHelp || 'Seconds of silence after sentences. Leave empty for default.',
					placeholder: '0.2',
					value: postMeta._piperless_sentence_silence || '',
					onChange: function ( val ) { updateMeta( '_piperless_sentence_silence', val ); },
				} ),
				createElement( TextControl, {
					label: piperlessEditor.i18n.lengthScaleLabel || 'Length Scale',
					help: piperlessEditor.i18n.lengthScaleHelp || 'Word spacing override. 1.0 = normal. Leave empty for quality preset.',
					placeholder: '1.0',
					value: postMeta._piperless_length_scale || '',
					onChange: function ( val ) { updateMeta( '_piperless_length_scale', val ); },
				} ),
			),
			// ── Display Settings ────────────────────────────────────────
			createElement(
				PanelBody,
				{ title: __( 'Display Settings', 'piperless' ), initialOpen: false },
				createElement( SelectControl, {
					label: piperlessEditor.i18n.styleLabel || 'Player Style',
					value: postMeta._piperless_style || '',
					options: [
						{ value: '', label: '— Default —' },
						{ value: 'classic', label: 'Classic' },
						{ value: 'minimal', label: 'Minimal' },
						{ value: 'dark', label: 'Modern Dark' },
						{ value: 'newsviews', label: 'NewsViews' },
						{ value: 'newsviews-classic', label: 'NewsViews Classic' },
					],
					onChange: function ( val ) { updateMeta( '_piperless_style', val ); },
				} ),
				createElement( SelectControl, {
					label: piperlessEditor.i18n.placementLabel || 'Placement',
					value: postMeta._piperless_placement || '',
					options: [
						{ value: '', label: '— Default —' },
						{ value: 'before', label: 'Before content' },
						{ value: 'after', label: 'After content' },
						{ value: 'both', label: 'Above & below' },
						{ value: 'manual', label: 'Manual (shortcode only)' },
					],
					onChange: function ( val ) { updateMeta( '_piperless_placement', val ); },
				} )
			),
			// ── Audio Preview ───────────────────────────────────────────
			createElement(
				PanelBody,
				{ title: __( 'Preview', 'piperless' ), initialOpen: true },
				audioUrl
					? createElement( 'div', null,
						createElement( 'audio', {
							controls: true,
							style: { width: '100%', marginBottom: '8px' },
						},
							createElement( 'source', {
								src: audioUrl,
								type: audioFormat === 'opus' ? 'audio/ogg' : 'audio/mpeg',
							} ),
							piperlessEditor.i18n.noAudio
						),
						audioDuration && createElement( 'p', {
							style: { color: '#757575', fontSize: '12px' },
						}, piperlessEditor.i18n.duration + ' ' + formatDuration( audioDuration ) )
					)
					: createElement( 'p', {
						style: { color: '#757575', fontStyle: 'italic' },
					}, piperlessEditor.i18n.noAudio )
			)
		);
	}

	registerPlugin( 'piperless-sidebar', {
		render: PiperlessSidebar,
	} );
} )( window.wp );
