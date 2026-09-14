/**
 * Purge User Accounts - Build tab.
 *
 * Drives the stepper: the server owns the cursor, this only asks it to advance.
 * Steps run strictly one at a time; overlapping requests would double-process a
 * chunk. Progress is rendered from what the server returns, never predicted.
 */
( function () {
	'use strict';

	var config = window.hwpuaAdmin || {};

	/** Collect the ticked criteria into a filter specification. */
	function readSpec() {
		var spec = [];

		for ( var row of document.querySelectorAll( '.hwpua-filter' ) ) {
			var toggle = row.querySelector( '.hwpua-filter-toggle' );

			if ( ! toggle || ! toggle.checked ) {
				continue;
			}

			var senseInput = row.querySelector( '.hwpua-sense:checked' );
			var criterion = {
				id: toggle.value,
				sense: senseInput ? senseInput.value : 'has_not',
				args: {}
			};

			var roleBoxes = row.querySelectorAll( '.hwpua-arg-roles:checked' );
			if ( roleBoxes.length ) {
				criterion.args.roles = Array.prototype.map.call( roleBoxes, function ( box ) {
					return box.value;
				} );
			}

			var daysInput = row.querySelector( '.hwpua-arg-days' );
			if ( daysInput && daysInput.value !== '' ) {
				criterion.args.days_ago = parseInt( daysInput.value, 10 );
				criterion.args.direction = 'before';
			}

			var unknownBox = row.querySelector( '.hwpua-arg-unknown' );
			if ( unknownBox ) {
				criterion.args.include_unknown = unknownBox.checked;
			}

			var allowlistBox = row.querySelector( '.hwpua-arg-allowlist' );
			if ( allowlistBox ) {
				criterion.args.use_allowlist = allowlistBox.checked;
			}

			var guestBox = row.querySelector( '.hwpua-arg-guest' );
			if ( guestBox ) {
				criterion.args.include_guest_email = guestBox.checked;
			}

			spec.push( criterion );
		}

		return spec;
	}

	/**
	 * Tick the form to match a saved specification.
	 *
	 * Nothing is left over from whatever was ticked before: every criterion is
	 * unticked first, and an argument the specification does not mention goes
	 * back to its markup default rather than keeping the last preset's value.
	 * A half-applied preset would select users nobody asked for.
	 */
	function applySpec( spec ) {
		var unknown = [];

		for ( var row of document.querySelectorAll( '.hwpua-filter' ) ) {
			var toggle = row.querySelector( '.hwpua-filter-toggle' );

			if ( toggle ) {
				toggle.checked = false;
			}
		}

		for ( var criterion of spec || [] ) {
			var target = document.querySelector( '.hwpua-filter[data-filter-id="' + criterion.id.replace( /"/g, '' ) + '"]' );
			var box = target ? target.querySelector( '.hwpua-filter-toggle' ) : null;

			if ( ! box || box.disabled ) {
				unknown.push( criterion.id );
				continue;
			}

			box.checked = true;
			applyArguments( target, criterion );
		}

		return unknown;
	}

	/** Restore one criterion's sense and arguments. */
	function applyArguments( row, criterion ) {
		var args = criterion.args || {};

		for ( var senseInput of row.querySelectorAll( '.hwpua-sense' ) ) {
			senseInput.checked = senseInput.value === ( criterion.sense || 'has_not' );
		}

		for ( var roleBox of row.querySelectorAll( '.hwpua-arg-roles' ) ) {
			roleBox.checked = Array.isArray( args.roles ) && args.roles.indexOf( roleBox.value ) !== -1;
		}

		var daysInput = row.querySelector( '.hwpua-arg-days' );
		if ( daysInput ) {
			daysInput.value = typeof args.days_ago === 'undefined' ? daysInput.defaultValue : args.days_ago;
		}

		var toggles = {
			'.hwpua-arg-unknown': 'include_unknown',
			'.hwpua-arg-allowlist': 'use_allowlist',
			'.hwpua-arg-guest': 'include_guest_email'
		};

		for ( var selector of Object.keys( toggles ) ) {
			var control = row.querySelector( selector );

			if ( control ) {
				control.checked = typeof args[ toggles[ selector ] ] === 'undefined'
					? control.defaultChecked
					: !! args[ toggles[ selector ] ];
			}
		}
	}

	/** POST to one of our endpoints. */
	function post( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'hwpua_nonce', config.nonce );
		body.set( 'spec', JSON.stringify( readSpec() ) );

		Object.keys( extra || {} ).forEach( function ( key ) {
			body.set( key, extra[ key ] );
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function showWarnings( warnings ) {
		var panel = document.getElementById( 'hwpua-warnings' );

		if ( ! panel ) {
			return;
		}

		if ( ! warnings || ! warnings.length ) {
			panel.hidden = true;
			panel.textContent = '';
			return;
		}

		panel.textContent = '';
		warnings.forEach( function ( warning ) {
			var paragraph = document.createElement( 'p' );
			paragraph.textContent = warning;
			panel.appendChild( paragraph );
		} );
		panel.hidden = false;
	}

	function setProgress( percent, text ) {
		var wrapper = document.getElementById( 'hwpua-progress' );
		var fill = document.getElementById( 'hwpua-progress-fill' );
		var label = document.getElementById( 'hwpua-progress-text' );

		if ( wrapper ) {
			wrapper.hidden = false;
		}
		if ( fill ) {
			fill.style.width = percent + '%';
		}
		if ( label ) {
			label.textContent = text;
		}
	}

	/** Advance the run until the server says it is done. */
	function advance( runId, attempt ) {
		post( config.actions.step, { run_id: runId } ).then( function ( payload ) {
			if ( ! payload.success ) {
				setProgress( 100, ( payload.data && payload.data.message ) || config.strings.failed );
				return;
			}

			var progress = payload.data;
			setProgress(
				progress.progress_pct,
				progress.stage_label + ' — ' + Number( progress.current_count ).toLocaleString() + ' matched'
			);

			if ( progress.done ) {
				window.location.href = config.resultsUrl + '&run=' + runId;
				return;
			}

			advance( runId, 0 );
		} ).catch( function () {
			// A dropped connection is recoverable: the cursor is on the server,
			// so replaying the step re-does that chunk rather than corrupting it.
			if ( attempt < 3 ) {
				setProgress( 0, config.strings.retrying );
				window.setTimeout( function () {
					advance( runId, attempt + 1 );
				}, 1000 * ( attempt + 1 ) );
				return;
			}

			setProgress( 0, config.strings.failed );
		} );
	}

	function bind() {
		document.querySelectorAll( '.hwpua-filter-toggle' ).forEach( function ( toggle ) {
			toggle.addEventListener( 'change', function () {
				toggle.closest( '.hwpua-filter' ).classList.toggle( 'is-on', toggle.checked );
			} );
		} );

		var estimateButton = document.getElementById( 'hwpua-estimate' );
		if ( estimateButton ) {
			estimateButton.addEventListener( 'click', function () {
				var output = document.getElementById( 'hwpua-estimate-result' );
				output.textContent = config.strings.estimating;

				post( config.actions.estimate, {} ).then( function ( payload ) {
					if ( ! payload.success ) {
						output.textContent = ( payload.data && payload.data.message ) || config.strings.failed;
						return;
					}

					output.textContent = Number( payload.data.matched ).toLocaleString() +
						' of ' + Number( payload.data.total ).toLocaleString() + ' users';
					showWarnings( payload.data.warnings );
				} );
			} );
		}

		var runButton = document.getElementById( 'hwpua-run' );
		if ( runButton ) {
			runButton.addEventListener( 'click', function () {
				runButton.disabled = true;
				setProgress( 0, config.strings.building );

				post( config.actions.start, {} ).then( function ( payload ) {
					if ( ! payload.success ) {
						runButton.disabled = false;
						setProgress( 0, ( payload.data && payload.data.message ) || config.strings.failed );
						return;
					}

					showWarnings( payload.data.warnings );
					advance( payload.data.run_id, 0 );
				} );
			} );
		}
	}

	function presetMessage( text, isProblem ) {
		var target = document.getElementById( 'hwpua-preset-message' );

		if ( ! target ) {
			return;
		}

		target.textContent = text;
		target.hidden = ! text;
		target.className = isProblem ? 'hwpua-warn' : 'hwpua-muted';
	}

	/** Replace the select's options after a save, import or delete. */
	function repopulatePresets( presets, selectSlug ) {
		var select = document.getElementById( 'hwpua-preset-select' );

		config.presets = presets || {};

		if ( ! select ) {
			return;
		}

		select.textContent = '';
		select.appendChild( new Option( select.dataset.placeholder || '— choose —', '' ) );

		for ( var slug of Object.keys( config.presets ) ) {
			select.appendChild( new Option( config.presets[ slug ].label || slug, slug ) );
		}

		select.value = selectSlug || '';
	}

	function selectedPreset() {
		var select = document.getElementById( 'hwpua-preset-select' );
		var slug = select ? select.value : '';

		return slug && config.presets && config.presets[ slug ] ? config.presets[ slug ] : null;
	}

	function bindPresets() {
		var panel = document.getElementById( 'hwpua-preset-panel' );

		if ( ! panel ) {
			return;
		}

		var select = document.getElementById( 'hwpua-preset-select' );

		if ( select ) {
			select.dataset.placeholder = select.options.length ? select.options[ 0 ].text : '';
		}

		document.getElementById( 'hwpua-preset-load' ).addEventListener( 'click', function () {
			var preset = selectedPreset();

			if ( ! preset ) {
				presetMessage( config.strings.nothingTicked, true );
				return;
			}

			var unknown = applySpec( preset.filter_spec );

			presetMessage(
				unknown.length
					? preset.label + ': ' + unknown.length + ' criteria cannot be used on this site and were not ticked — ' + unknown.join( ', ' )
					: preset.label + ' loaded. Check the ticks, then run it.',
				unknown.length > 0
			);
		} );

		document.getElementById( 'hwpua-preset-save' ).addEventListener( 'click', function () {
			var nameInput = document.getElementById( 'hwpua-preset-name' );
			var label = nameInput.value.trim();

			if ( ! label ) {
				presetMessage( config.strings.nameThis, true );
				nameInput.focus();
				return;
			}

			if ( ! readSpec().length ) {
				presetMessage( config.strings.nothingTicked, true );
				return;
			}

			post( config.actions.savePreset, { label: label } ).then( function ( payload ) {
				if ( ! payload.success ) {
					presetMessage( ( payload.data && payload.data.message ) || config.strings.failed, true );
					return;
				}

				repopulatePresets( payload.data.presets, payload.data.slug );
				nameInput.value = '';
				presetMessage( payload.data.message, false );
			} );
		} );

		document.getElementById( 'hwpua-preset-delete' ).addEventListener( 'click', function () {
			var slug = select ? select.value : '';

			if ( ! slug || ! window.confirm( config.strings.confirmForget ) ) {
				return;
			}

			post( config.actions.deletePreset, { slug: slug } ).then( function ( payload ) {
				if ( ! payload.success ) {
					presetMessage( ( payload.data && payload.data.message ) || config.strings.failed, true );
					return;
				}

				repopulatePresets( payload.data.presets, '' );
				presetMessage( payload.data.message, false );
			} );
		} );

		document.getElementById( 'hwpua-preset-export' ).addEventListener( 'click', function () {
			var preset = selectedPreset();

			if ( ! preset ) {
				presetMessage( config.strings.nothingTicked, true );
				return;
			}

			// Shown rather than downloaded: the file would be one more copy of a
			// query on disk, and this is small enough to copy by hand.
			window.prompt(
				preset.label,
				JSON.stringify( {
					hwpua_preset: 1,
					slug: select.value,
					label: preset.label,
					description: preset.description || '',
					filter_spec: preset.filter_spec
				} )
			);
		} );

		document.getElementById( 'hwpua-preset-import' ).addEventListener( 'click', function () {
			var json = window.prompt( config.strings.pasteExport, '' );

			if ( ! json ) {
				return;
			}

			post( config.actions.importPreset, { json: json } ).then( function ( payload ) {
				if ( ! payload.success ) {
					var lines = ( payload.data && payload.data.lines ) || [];
					var detail = lines.filter( function ( line ) {
						return line.level === 'error';
					} ).map( function ( line ) {
						return line.text;
					} );

					presetMessage(
						( ( payload.data && payload.data.message ) || config.strings.failed ) +
							( detail.length ? ' ' + detail.join( ' ' ) : '' ),
						true
					);
					return;
				}

				repopulatePresets( payload.data.presets, payload.data.slug );
				presetMessage( payload.data.message, false );
			} );
		} );
	}

	function bindBuildTab() {
		bind();
		bindPresets();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bindBuildTab );
	} else {
		bindBuildTab();
	}
}() );

/**
 * Purge User Accounts - Results tab, action confirmation.
 *
 * The typed confirmation here is a courtesy to the operator. The server checks
 * it again, because a control that only exists in the browser is not a control.
 */
( function () {
	'use strict';

	var config = window.hwpuaAdmin || {};

	function selectedAction() {
		var picked = document.querySelector( 'input[name="hwpua-action"]:checked' );
		return picked ? picked.value : '';
	}

	function collectArgs() {
		var args = {};
		var dryRun = document.getElementById( 'hwpua-dry-run' );

		if ( dryRun && dryRun.checked ) {
			args.dry_run = true;
		}

		if ( selectedAction() === 'delete' ) {
			var reassign = document.querySelector( 'input[name="hwpua-reassign"]:checked' );
			// Sent only when the operator has actually chosen. The server
			// refuses to delete without this key present.
			if ( reassign ) {
				args.reassign_to = parseInt( reassign.value, 10 );
			}
		}

		return args;
	}

	function post( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'hwpua_nonce', config.nonce );
		body.set( 'run_id', config.runId );
		body.set( 'action_id', selectedAction() );
		body.set( 'args', JSON.stringify( collectArgs() ) );

		Object.keys( extra || {} ).forEach( function ( key ) {
			body.set( key, extra[ key ] );
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function renderPreflight( data ) {
		var panel = document.getElementById( 'hwpua-preflight' );
		panel.textContent = '';
		panel.hidden = false;

		var heading = document.createElement( 'p' );
		heading.innerHTML = '<strong></strong>';
		heading.querySelector( 'strong' ).textContent =
			data.action_label + ' — ' + Number( data.matched ).toLocaleString() + ' accounts, ' + data.estimate_text;
		panel.appendChild( heading );

		var list = document.createElement( 'ul' );
		list.className = 'hwpua-checks';

		data.checks.forEach( function ( check ) {
			var item = document.createElement( 'li' );
			item.className = 'hwpua-check hwpua-check-' + check.level;
			item.textContent = check.text;
			list.appendChild( item );
		} );

		panel.appendChild( list );

		if ( ! data.can_proceed ) {
			var blocked = document.createElement( 'p' );
			blocked.className = 'hwpua-warn';
			blocked.textContent = config.strings.blocked;
			panel.appendChild( blocked );
			return;
		}

		// The off-ramp sits at the point of maximum friction: the moment the
		// operator is thinking hardest about an irreversible action.
		if ( data.destructiveness >= 100 ) {
			var offRamp = document.createElement( 'p' );
			offRamp.className = 'hwpua-offramp';

			var offButton = document.createElement( 'button' );
			offButton.type = 'button';
			offButton.className = 'button';
			offButton.textContent = config.strings.offRamp;
			offButton.addEventListener( 'click', function () {
				var alternative = document.querySelector( 'input[name="hwpua-action"][value="block-signin"]' );
				if ( alternative ) {
					alternative.checked = true;
					alternative.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					document.getElementById( 'hwpua-check' ).click();
				}
			} );

			var note = document.createElement( 'span' );
			note.className = 'hwpua-muted';
			note.textContent = ' ' + config.strings.offRampNote;

			offRamp.appendChild( offButton );
			offRamp.appendChild( note );
			panel.appendChild( offRamp );
		}

		var confirmRow = document.createElement( 'p' );

		if ( data.needs_confirmation ) {
			var label = document.createElement( 'label' );
			label.textContent = config.strings.typeToConfirm.replace( '%s', data.confirmation ) + ' ';

			var input = document.createElement( 'input' );
			input.type = 'text';
			input.id = 'hwpua-confirm-input';
			input.className = 'regular-text';
			input.autocomplete = 'off';

			label.appendChild( input );
			confirmRow.appendChild( label );
			confirmRow.appendChild( document.createElement( 'br' ) );
		}

		var goButton = document.createElement( 'button' );
		goButton.type = 'button';
		goButton.className = data.destructiveness >= 50 ? 'button button-link-delete' : 'button button-primary';
		goButton.textContent = config.strings.confirmAction;
		goButton.addEventListener( 'click', function () {
			startJob( goButton, data );
		} );

		confirmRow.appendChild( goButton );
		panel.appendChild( confirmRow );
	}

	function startJob( goButton, preflightData ) {
		var typed = document.getElementById( 'hwpua-confirm-input' );
		goButton.disabled = true;

		post( config.actions.startJob, { confirmation: typed ? typed.value : '' } ).then( function ( payload ) {
			if ( ! payload.success ) {
				goButton.disabled = false;
				window.alert( ( payload.data && payload.data.message ) || config.strings.failed );
				return;
			}

			document.getElementById( 'hwpua-preflight' ).hidden = true;
			advanceJob( payload.data.job_id, preflightData, 0 );
		} );
	}

	function advanceJob( jobId, preflightData, attempt ) {
		post( config.actions.stepJob, { job_id: jobId } ).then( function ( payload ) {
			var wrapper = document.getElementById( 'hwpua-job-progress' );
			var fill = document.getElementById( 'hwpua-job-fill' );
			var text = document.getElementById( 'hwpua-job-text' );
			wrapper.hidden = false;

			if ( ! payload.success ) {
				text.textContent = ( payload.data && payload.data.message ) || config.strings.failed;
				return;
			}

			var progress = payload.data;
			fill.style.width = progress.progress_pct + '%';

			var summary = Number( progress.processed ).toLocaleString() + ' of ' +
				Number( progress.total ).toLocaleString() +
				' — ' + Number( progress.succeeded ).toLocaleString() + ' done';

			if ( progress.skipped ) {
				summary += ', ' + Number( progress.skipped ).toLocaleString() + ' skipped';
			}
			if ( progress.failed ) {
				summary += ', ' + Number( progress.failed ).toLocaleString() + ' failed';
			}
			if ( ! progress.done && progress.remaining_seconds ) {
				summary += ' · ' + Math.ceil( progress.remaining_seconds / 60 ) + ' min remaining';
			}
			if ( progress.dry_run ) {
				summary += ' · ' + config.strings.dryRunNote;
			}

			text.textContent = summary;

			if ( ! progress.done ) {
				advanceJob( jobId, preflightData, 0 );
			}
		} ).catch( function () {
			if ( attempt < 3 ) {
				window.setTimeout( function () {
					advanceJob( jobId, preflightData, attempt + 1 );
				}, 1000 * ( attempt + 1 ) );
				return;
			}
			document.getElementById( 'hwpua-job-text' ).textContent = config.strings.failed;
		} );
	}

	function bindActions() {
		var panel = document.getElementById( 'hwpua-action-panel' );

		if ( ! panel ) {
			return;
		}

		panel.addEventListener( 'change', function ( event ) {
			if ( event.target.name !== 'hwpua-action' ) {
				return;
			}

			var deleteArgs = document.getElementById( 'hwpua-args-delete' );
			if ( deleteArgs ) {
				deleteArgs.hidden = event.target.value !== 'delete';
			}

			document.getElementById( 'hwpua-preflight' ).hidden = true;
		} );

		document.getElementById( 'hwpua-check' ).addEventListener( 'click', function () {
			if ( ! selectedAction() ) {
				return;
			}

			post( config.actions.preflight, {} ).then( function ( payload ) {
				if ( ! payload.success ) {
					window.alert( ( payload.data && payload.data.message ) || config.strings.failed );
					return;
				}
				renderPreflight( payload.data );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bindActions );
	} else {
		bindActions();
	}
}() );
