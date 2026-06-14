/**
 * The Exporter admin UI.
 */
( function () {
	'use strict';

	if ( typeof teAdmin === 'undefined' || typeof teUI === 'undefined' ) {
		return;
	}

	const announce = ( msg ) => {
		const el = document.getElementById( 'te-live-region' );
		if ( el ) {
			el.textContent = msg;
		}
	};

	const showModal = ( message, onConfirm ) => {
		const modal = document.getElementById( 'te-confirm-modal' );
		const msgEl = document.getElementById( 'te-modal-message' );
		const input = document.getElementById( 'te-modal-confirm-input' );
		const confirmBtn = document.getElementById( 'te-modal-confirm' );
		const cancelBtn = document.getElementById( 'te-modal-cancel' );

		if ( ! modal ) return;

		msgEl.textContent = message;
		input.value = '';
		confirmBtn.disabled = true;
		modal.hidden = false;
		input.focus();

		const onInput = () => {
			confirmBtn.disabled = input.value !== teAdmin.strings.confirmWord;
		};
		input.addEventListener( 'input', onInput );

		const close = () => {
			modal.hidden = true;
			input.removeEventListener( 'input', onInput );
			confirmBtn.removeEventListener( 'click', onConfirmClick );
			cancelBtn.removeEventListener( 'click', close );
		};

		const onConfirmClick = () => {
			close();
			if ( onConfirm ) onConfirm();
		};

		confirmBtn.addEventListener( 'click', onConfirmClick );
		cancelBtn.addEventListener( 'click', close );
	};

	window.teShowModal = showModal;

	const setExportProgress = ( html ) => {
		const progress = document.getElementById( 'te-export-progress' );
		if ( progress ) {
			progress.innerHTML = html;
		}
	};

	const initExport = async () => {
		teUI.log( 'Export', 'Initializing new migration…', 'info' );
		setExportProgress( '<p class="te-text-muted">Initializing migration…</p>' );
		const result = await teUI.api( 'export/init', { method: 'POST', body: '{}', source: 'Export' } );
		if ( result.ok && result.data.migration_id ) {
			teAdmin.migrationId = result.data.migration_id;
			announce( 'Migration initialized: ' + result.data.migration_id );
			setExportProgress( '<p style="color:var(--te-success)">Migration created: <code>' + teUI.escapeHtml( result.data.migration_id ) + '</code>. Reloading…</p>' );
			window.location.reload();
			return;
		}
		setExportProgress( '<p class="te-text-warning">Initialize failed: ' + teUI.escapeHtml( result.error || result.data?.error || 'Unknown error' ) + '</p>' );
	};

	const EXPORT_TIMEOUT_MS = 45 * 60 * 1000;
	const SCAN_TIMEOUT_MS = 20 * 60 * 1000;
	const SEGMENT_TIMEOUT_MS = 10 * 60 * 1000;
	const FILE_COMPONENTS = [ 'uploads', 'themes', 'plugins', 'mu-plugins', 'wp-content-other' ];
	let exportPoller = null;
	let pushPoller = null;

	const updatePushStatus = async () => {
		const el = document.getElementById( 'te-push-status' );
		if ( ! el || ! teAdmin.migrationId ) return;
		const result = await teUI.api( 'transfer/' + teAdmin.migrationId + '/push-status', { source: 'Push' } );
		if ( ! result.ok || ! result.data ) return;
		const d = result.data;
		if ( ! d.active && ! d.done && ! d.failed ) {
			el.hidden = true;
			return;
		}
		el.hidden = false;
		if ( d.failed ) {
			el.textContent = 'Push failed. Check Jobs & Logs, then retry Send to import site.';
			el.className = 'te-status-panel te-status-panel--warning te-mt-sm';
		} else if ( d.done ) {
			el.textContent = 'All ' + d.total + ' files sent to import site.';
			el.className = 'te-status-panel te-status-panel--success te-mt-sm';
			stopPushPoller();
		} else {
			el.textContent = 'Sending… ' + d.sent + ' / ' + d.total + ' files';
			el.className = 'te-status-panel te-status-panel--info te-mt-sm';
		}
	};

	const startPushPoller = () => {
		if ( pushPoller ) return;
		updatePushStatus();
		pushPoller = setInterval( updatePushStatus, 4000 );
	};

	const stopPushPoller = () => {
		if ( pushPoller ) {
			clearInterval( pushPoller );
			pushPoller = null;
		}
	};

	const sendToImportSite = async () => {
		const pipeline = getPipelineEl( 'te-export-pipeline' );
		const btn = document.getElementById( 'te-send-to-import' );
		if ( ! teAdmin.migrationId ) {
			teUI.warn( pipeline, 'No migration', 'Start export first.' );
			return;
		}
		if ( btn ) btn.disabled = true;
		teUI.log( 'Export', 'Starting site-to-site push', 'info' );
		const result = await teUI.api( 'transfer/push', {
			method: 'POST',
			body: JSON.stringify( { migration_id: teAdmin.migrationId } ),
			source: 'Push',
		} );
		if ( btn ) btn.disabled = false;
		if ( result.ok && result.data?.success ) {
			teUI.success( pipeline, 'Push started', ( result.data.total || '?' ) + ' files queued.' );
			startPushPoller();
		} else {
			teUI.error( pipeline, 'Push failed', result.data?.error || result.error || 'unknown' );
		}
	};

	const isFileComponent = ( component ) => FILE_COMPONENTS.includes( component );

	const isMigrationLocked = ( data ) => {
		const err = ( data?.error || '' ).toLowerCase();
		return err.includes( 'migration locked' ) || err.includes( 'locked' );
	};

	const tryReleaseStaleLock = async ( lockAge ) => {
		if ( typeof lockAge !== 'number' || lockAge < 180 ) {
			return false;
		}
		teUI.log( 'Export', 'Stale lock detected (' + lockAge + 's) — releasing', 'warn' );
		const release = await teUI.api( 'lock/release', {
			method: 'POST',
			body: JSON.stringify( { migration_id: teAdmin.migrationId } ),
			source: 'Export',
			silent: true,
		} );
		return release.ok && release.data?.success;
	};

	const exportFileComponent = async ( component ) => {
		const scan = await teUI.api( 'export/scan', {
			method: 'POST',
			body: JSON.stringify( {
				migration_id: teAdmin.migrationId,
				component,
				resume: true,
			} ),
			source: 'Export',
			timeoutMs: SCAN_TIMEOUT_MS,
		} );

		if ( scan.timedOut ) {
			return {
				ok: false,
				error: component + ': scan timed out (retry Export All to resume)',
				data: { success: false, error: 'scan timed out' },
			};
		}

		if ( ! scan.ok || ! scan.data?.success ) {
			if ( isMigrationLocked( scan.data ) && await tryReleaseStaleLock( scan.data?.lock_age_seconds ) ) {
				return exportFileComponent( component );
			}
			return scan;
		}

		if ( ! scan.data.already_complete ) {
			teUI.log( 'Export', component + ' scanned (' + ( scan.data.files_total || 0 ) + ' files)', 'success' );
		}

		let done = false;
		let last = null;

		while ( ! done ) {
			const result = await teUI.api( 'export/segment', {
				method: 'POST',
				body: JSON.stringify( {
					migration_id: teAdmin.migrationId,
					component,
					resume: true,
					max_segments: 1,
				} ),
				source: 'Export',
				timeoutMs: SEGMENT_TIMEOUT_MS,
			} );

			if ( result.timedOut ) {
				return {
					ok: false,
					error: component + ': segment timed out (retry Export All to resume)',
					data: { success: false, error: 'segment timed out' },
				};
			}

			if ( ! result.ok || ! result.data?.success ) {
				if ( isMigrationLocked( result.data ) && await tryReleaseStaleLock( result.data?.lock_age_seconds ) ) {
					continue;
				}
				return result;
			}

			last = result.data;
			done = !! result.data.done;
		}

		return { ok: true, data: last || { success: true } };
	};

	const getPipelineEl = ( id ) => document.getElementById( id );

	const setExportWizardStep = ( step ) => {
		if ( window.teProgress ) {
			teProgress.renderWizardSteps(
				document.getElementById( 'te-export-wizard-steps' ),
				[ 'Start', 'Build packages', 'Seal manifest', 'Download' ],
				step
			);
		}
	};

	const startExportPoller = () => {
		if ( ! window.teProgress || ! teAdmin.migrationId ) {
			return;
		}
		exportPoller = new teProgress.ProgressPoller( teAdmin.migrationId, 'export' );
		exportPoller.onUpdate( ( snap ) => {
			teProgress.renderPipeline( getPipelineEl( 'te-export-pipeline' ), snap );
		} );
		exportPoller.start();
	};

	const stopExportPoller = () => {
		if ( exportPoller ) {
			exportPoller.stop();
			exportPoller = null;
		}
	};

	const isSftpMode = () => {
		return teAdmin.transferMode === 'sftp'
			|| document.getElementById( 'te-export-wizard' )?.dataset?.transferMode === 'sftp';
	};

	const isConnectedMode = () => {
		return teAdmin.transferMode === 'connected'
			|| teAdmin.isConnected
			|| document.getElementById( 'te-export-wizard' )?.dataset?.transferMode === 'connected';
	};

	const isServerMode = () => isSftpMode() || isConnectedMode();

	const exportAll = async () => {
		const components = teAdmin.exportComponents || [];
		const pipeline = getPipelineEl( 'te-export-pipeline' );
		const exportBtn = document.getElementById( 'te-export-all' );

		if ( ! components.length ) {
			teUI.warn( pipeline, 'No components', 'No export components configured.' );
			return;
		}
		if ( ! teAdmin.migrationId ) {
			teUI.warn( pipeline, 'No migration', 'Click Start Export first.' );
			return;
		}

		if ( exportBtn ) {
			exportBtn.disabled = true;
		}

		setExportWizardStep( 2 );

		if ( isServerMode() ) {
			teUI.log( 'Export', 'Queueing server-side export (' + teAdmin.transferMode + ' mode)', 'info' );
			startExportPoller();
			const queued = await teUI.api( 'export/queue', {
				method: 'POST',
				body: JSON.stringify( {
					migration_id: teAdmin.migrationId,
					components,
				} ),
				source: 'Export',
			} );
			if ( queued.ok && queued.data?.success ) {
				const msg = isConnectedMode()
					? 'Server is building packages. When done, click Send to import site.'
					: 'Server is building packages in the background. Use the transfer guide when complete.';
				teUI.success( pipeline, 'Export queued', msg );
				if ( exportBtn ) exportBtn.disabled = false;
				return;
			}
			teUI.warn( pipeline, 'Queue failed', queued.data?.error || queued.error || 'Falling back to browser export.' );
		}

		teUI.log( 'Export', 'Exporting ' + components.length + ' component(s)', 'info' );
		startExportPoller();

		let completed = 0;
		const failures = [];

		for ( const component of components ) {
			const result = isFileComponent( component )
				? await exportFileComponent( component )
				: await teUI.api( 'export/component', {
					method: 'POST',
					body: JSON.stringify( {
						migration_id: teAdmin.migrationId,
						component,
					} ),
					source: 'Export',
					timeoutMs: EXPORT_TIMEOUT_MS,
				} );

			if ( result.timedOut ) {
				failures.push( component + ': timed out (server may still be working — check Jobs & Logs, then retry)' );
				teUI.log( 'Export', component + ' timed out', 'error' );
				break;
			}

			if ( result.ok && result.data?.success ) {
				completed++;
				teUI.log( 'Export', component + ' exported', 'success' );
			} else {
				const err = result.data?.error || result.error || 'failed';
				failures.push( component + ': ' + err );
				teUI.log( 'Export', component + ' failed: ' + err, 'error' );
				break;
			}
		}

		stopExportPoller();

		if ( failures.length ) {
			teUI.error( pipeline, 'Export finished with errors', failures.join( ' · ' ) );
			if ( exportBtn ) exportBtn.disabled = false;
			return;
		}

		setExportWizardStep( 3 );
		teUI.loading( pipeline, 'Finalizing manifest', 'Sealing checksums and building download catalog…' );

		const finalize = await teUI.api( 'export/finalize', {
			method: 'POST',
			body: JSON.stringify( { migration_id: teAdmin.migrationId } ),
			source: 'Export',
		} );

		if ( finalize.ok && finalize.data?.success ) {
			setExportWizardStep( 4 );
			let msg = 'Download files below, then import on your other site.';
			if ( isConnectedMode() ) {
				if ( finalize.data?.auto_push?.success ) {
					msg = 'Auto-push started. Watch progress on Send to import site.';
					startPushPoller();
				} else {
					msg = 'Click Send to import site to push packages automatically.';
				}
			}
			teUI.success( pipeline, 'Export complete', msg );
			if ( window.teLoadExportPackages ) {
				await window.teLoadExportPackages();
			}
		} else {
			teUI.error( pipeline, 'Finalize failed', finalize.data?.error || finalize.error || 'unknown' );
		}

		if ( exportBtn ) {
			exportBtn.disabled = false;
		}
	};

	const exportSelected = async () => {
		const checkboxes = document.querySelectorAll( 'input[name="te_components[]"]:checked' );
		const components = Array.from( checkboxes ).map( ( cb ) => cb.value );

		if ( ! components.length ) {
			setExportProgress( '<p class="te-text-warning">Select at least one component to export.</p>' );
			teUI.log( 'Export', 'No components selected', 'warn' );
			return;
		}

		if ( ! teAdmin.migrationId ) {
			setExportProgress( '<p class="te-text-warning">No migration ID. Initialize export first.</p>' );
			return;
		}

		teUI.log( 'Export', 'Queueing export of ' + components.length + ' component(s)', 'info' );
		setExportProgress( '<p class="te-text-muted">Queueing background export jobs…</p>' );

		const queued = await teUI.api( 'export/queue', {
			method: 'POST',
			body: JSON.stringify( {
				migration_id: teAdmin.migrationId,
				components,
			} ),
			source: 'Export',
		} );

		if ( queued.ok && queued.data.success ) {
			setExportProgress(
				'<p style="color:var(--te-success)"><strong>Export queued.</strong> Components: ' + teUI.escapeHtml( ( queued.data.queued || [] ).join( ', ' ) ) + '</p>' +
				'<p class="te-text-muted">Jobs run in the background. Check Jobs &amp; Logs and Activity Log. When complete, click Finalize Manifest.</p>' +
				( queued.data.job_id ? '<p class="te-text-muted">Job ID: ' + queued.data.job_id + '</p>' : '' )
			);
			teUI.log( 'Export', 'Queued job ' + ( queued.data.job_id || '' ), 'success' );
			return;
		}

		teUI.log( 'Export', 'Queue failed, falling back to sequential export', 'warn' );
		let completed = 0;
		const failures = [];

		for ( const component of components ) {
			setExportProgress(
				'<p class="te-text-muted">Exporting <strong>' + teUI.escapeHtml( component ) + '</strong> (' + ( completed + 1 ) + ' of ' + components.length + ')…</p>' +
				'<p class="te-text-muted">Check Activity Log for server responses.</p>'
			);
			const result = await teUI.api( 'export/component', {
				method: 'POST',
				body: JSON.stringify( {
					migration_id: teAdmin.migrationId,
					component,
				} ),
				source: 'Export',
			} );
			if ( result.ok && result.data && result.data.success === true ) {
				completed++;
				teUI.log( 'Export', component + ' exported', 'success', JSON.stringify( result.data, null, 2 ) );
			} else {
				const err = result.data?.error || result.error || 'Unknown error';
				failures.push( component + ': ' + err );
				teUI.log( 'Export', component + ' failed: ' + err, 'error', JSON.stringify( result.data || {}, null, 2 ) );
			}
		}

		if ( failures.length ) {
			setExportProgress(
				'<p class="te-text-warning"><strong>Export finished with errors.</strong></p>' +
				'<ul><li>' + failures.map( ( f ) => teUI.escapeHtml( f ) ).join( '</li><li>' ) + '</li></ul>' +
				'<p class="te-text-muted">Fix the issues above, then retry failed components.</p>'
			);
		} else {
			setExportProgress(
				'<p style="color:var(--te-success)"><strong>Export complete.</strong> ' + completed + ' component(s) exported.</p>' +
				'<p class="te-text-muted">Next: click <strong>Finalize Manifest</strong>, then download packages below.</p>'
			);
		}
		announce( failures.length ? 'Export finished with errors' : 'Export components finished' );
	};

	const finalizeExport = async () => {
		if ( ! teAdmin.migrationId ) {
			setExportProgress( '<p class="te-text-warning">No migration ID.</p>' );
			return;
		}
		setExportProgress( '<p class="te-text-muted">Checking finalize readiness…</p>' );
		const gate = await teUI.api( 'export/can-finalize', {
			method: 'POST',
			body: JSON.stringify( { migration_id: teAdmin.migrationId } ),
			source: 'Export',
		} );
		if ( gate.ok && gate.data && ! gate.data.ready ) {
			const errs = ( gate.data.errors || [] ).map( ( e ) => teUI.escapeHtml( e ) ).join( '<br>' );
			setExportProgress( '<p class="te-text-warning"><strong>Cannot finalize yet:</strong><br>' + errs + '</p>' );
			teUI.log( 'Export', 'Finalize blocked', 'warn', errs );
			return;
		}

		setExportProgress( '<p class="te-text-muted">Finalizing manifest…</p>' );
		teUI.log( 'Export', 'Finalizing manifest', 'info' );
		const result = await teUI.api( 'export/finalize', {
			method: 'POST',
			body: JSON.stringify( { migration_id: teAdmin.migrationId } ),
			source: 'Export',
		} );
		if ( result.ok && result.data && result.data.success === true ) {
			setExportProgress( '<p style="color:var(--te-success)"><strong>Manifest finalized.</strong> Download packages are loading below…</p>' );
			teUI.log( 'Export', 'Manifest finalized', 'success' );
			if ( window.teLoadExportPackages ) {
				await window.teLoadExportPackages();
			}
		} else {
			const err = result.data?.error || result.error || 'Finalize failed';
			setExportProgress( '<p class="te-text-warning">Finalize failed: ' + teUI.escapeHtml( err ) + '</p>' );
		}
		announce( result.ok ? 'Manifest finalized' : 'Finalize failed' );
	};

	const runValidation = async () => {
		const idInput = document.getElementById( 'te-validate-migration-id' );
		const migrationId = idInput ? idInput.value : teAdmin.migrationId;
		const container = document.getElementById( 'te-validation-report' );
		if ( container ) {
			container.innerHTML = '<p class="te-text-muted">Running validation…</p>';
		}
		teUI.log( 'Validate', 'Full validation for ' + migrationId, 'info' );
		const result = await teUI.api( 'validation/' + migrationId, { source: 'Validate' } );
		const report = result.ok ? result.data : { passed: false, errors: [ { message: result.error } ] };
		renderValidationReport( report );
		announce( report.passed ? 'Validation passed' : 'Validation failed' );
	};

	const renderValidationReport = ( report ) => {
		const container = document.getElementById( 'te-validation-report' );
		if ( ! container ) return;

		let html = '<div class="te-glass-card"><div class="te-glass-card__body">';
		html += '<p><strong>Status:</strong> ' + ( report.passed ? '✓ Passed' : '✗ Failed' ) + '</p>';

		if ( report.errors && report.errors.length ) {
			html += '<h4>Errors</h4>';
			report.errors.forEach( ( e ) => {
				html += '<div class="te-validation-check te-validation-check--fail">' + teUI.escapeHtml( e.message || e.code ) + '</div>';
			} );
		}

		if ( report.warnings && report.warnings.length ) {
			html += '<h4>Warnings</h4>';
			report.warnings.forEach( ( w ) => {
				html += '<div class="te-validation-check te-validation-check--warn">' + teUI.escapeHtml( w.message || w.code ) + '</div>';
			} );
		}

		if ( report.checks && report.checks.length ) {
			html += '<h4>Checks</h4>';
			report.checks.forEach( ( c ) => {
				const cls = c.status === 'pass' ? 'pass' : ( c.status === 'fail' ? 'fail' : 'warn' );
				html += '<div class="te-validation-check te-validation-check--' + cls + '">' +
					teUI.escapeHtml( ( c.name || c.path || c.layer || '' ) + ': ' + c.status ) + '</div>';
			} );
		}

		html += '</div></div>';
		container.innerHTML = html;
		window.teLastValidation = report;
	};

	const formatBytes = ( bytes ) => {
		if ( ! bytes ) return '0 B';
		if ( bytes < 1024 ) return bytes + ' B';
		if ( bytes < 1048576 ) return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
		if ( bytes < 1073741824 ) return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		return ( bytes / 1073741824 ).toFixed( 2 ) + ' GB';
	};

	const loadEnvironment = async () => {
		const diskEl = document.getElementById( 'te-footer-disk' );
		const cliEl = document.getElementById( 'te-footer-cli' );
		const stats = document.getElementById( 'te-dashboard-stats' );

		const result = await teUI.api( 'environment', { source: 'System' } );
		if ( ! result.ok ) {
			if ( diskEl ) diskEl.textContent = 'Could not load environment: ' + result.error;
			if ( cliEl ) cliEl.textContent = 'REST API unavailable';
			return;
		}

		const env = result.data;
		if ( diskEl && env.disk_free ) {
			diskEl.textContent = 'Free disk: ' + formatBytes( env.disk_free );
		}
		if ( cliEl ) {
			let lockText = '';
			if ( env.lock ) {
				lockText = env.lock.stale ? ' | lock: stale' : ' | lock: active';
			}
			cliEl.textContent = 'mysqldump: ' + ( env.mysqldump ? 'yes' : 'no' ) +
				' | mysql: ' + ( env.mysql_cli ? 'yes' : 'no' ) +
				( env.scheduler ? ' | scheduler: ' + env.scheduler : '' ) + lockText;
		}
		if ( stats ) {
			const tiles = stats.querySelectorAll( '.te-stat-tile__value' );
			if ( tiles[0] ) tiles[0].textContent = env.export_path ? env.export_path.split( '/' ).pop() : '—';
			if ( tiles[1] ) tiles[1].textContent = env.disk_free ? formatBytes( env.disk_free ) : '—';
			if ( tiles[2] ) tiles[2].textContent = env.wp_cli ? 'Available' : 'N/A';
		}
	};

	const bindEvents = () => {
		document.getElementById( 'te-init-export' )?.addEventListener( 'click', initExport );
		document.getElementById( 'te-export-all' )?.addEventListener( 'click', exportAll );
		document.getElementById( 'te-export-selected' )?.addEventListener( 'click', exportSelected );
		document.getElementById( 'te-finalize-export' )?.addEventListener( 'click', finalizeExport );
		document.getElementById( 'te-run-validation' )?.addEventListener( 'click', runValidation );
		document.getElementById( 'te-download-validation' )?.addEventListener( 'click', () => {
			if ( ! window.teLastValidation ) {
				teUI.log( 'Validate', 'No report to download', 'warn' );
				teUI.warn( document.getElementById( 'te-validation-report' ), 'No report', 'Run validation first, then download the report.' );
				return;
			}
			const blob = new Blob( [ JSON.stringify( window.teLastValidation, null, 2 ) ], { type: 'application/json' } );
			const a = document.createElement( 'a' );
			a.href = URL.createObjectURL( blob );
			a.download = 'validation-report.json';
			a.click();
		} );

		// Import buttons are bound in transfer.js (.te-import-component-btn).

		document.getElementById( 'te-release-lock' )?.addEventListener( 'click', async () => {
			const id = teAdmin.migrationId || document.getElementById( 'te-import-migration-id' )?.value;
			if ( ! id ) return;
			const result = await teUI.api( 'lock/release', {
				method: 'POST',
				body: JSON.stringify( { migration_id: id } ),
				source: 'System',
			} );
			teUI.log( 'System', result.ok ? 'Migration lock released' : 'Lock release failed: ' + result.error, result.ok ? 'success' : 'error' );
			loadEnvironment();
		} );

		document.getElementById( 'te-copy-export-path' )?.addEventListener( 'click', ( e ) => {
			const path = e.target.dataset.copy;
			navigator.clipboard?.writeText( path );
			teUI.log( 'System', 'Copied path to clipboard', 'success' );
			announce( 'Path copied' );
		} );

		document.getElementById( 'te-copy-migration-id' )?.addEventListener( 'click', ( e ) => {
			const id = e.target.dataset.copy;
			navigator.clipboard?.writeText( id );
			teUI.log( 'System', 'Copied migration ID', 'success' );
			announce( 'Migration ID copied' );
		} );

		document.getElementById( 'te-send-to-import' )?.addEventListener( 'click', sendToImportSite );

		if ( isConnectedMode() && teAdmin.migrationId ) {
			startPushPoller();
		}
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		bindEvents();
		loadEnvironment();
		if ( document.getElementById( 'te-export-wizard-steps' ) && window.teProgress ) {
			setExportWizardStep( teAdmin.migrationId ? 2 : 1 );
		}
	} );
} )();
