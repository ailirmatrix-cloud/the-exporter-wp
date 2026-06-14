/**
 * Simple import wizard: manifest → all files → validate → import all.
 */
( function () {
	'use strict';

	if ( typeof teAdmin === 'undefined' || typeof teUI === 'undefined' ) {
		return;
	}

	const UPLOAD_CONCURRENCY = 2;
	const IMPORT_POLL_MS = 2000;

	let expectedFiles = [];
	let lastStatus = null;
	let lastValidated = false;
	let uploading = false;
	let importPoller = null;
	let uploadPollTimer = null;

	const getMigrationId = () => document.getElementById( 'te-import-migration-id' )?.value?.trim() || '';

	const getImportBase = () => document.getElementById( 'te-import-wizard' )?.dataset?.importBase || '';

	const formatBytes = ( bytes ) => {
		if ( ! bytes ) {
			return '0 B';
		}
		if ( bytes < 1024 ) {
			return bytes + ' B';
		}
		if ( bytes < 1048576 ) {
			return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
		}
		if ( bytes < 1073741824 ) {
			return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		}
		return ( bytes / 1073741824 ).toFixed( 2 ) + ' GB';
	};

	const blockReasonLabel = ( reason, maxBytes ) => {
		switch ( reason ) {
			case 'checksum':
				return 'checksum mismatch';
			case 'browser_limit':
				return 'exceeds ' + formatBytes( maxBytes ) + ' browser limit — use SFTP or copy to folder';
			case 'missing':
				return 'not on server yet';
			default:
				return '';
		}
	};

	const setImportWizardStep = ( step ) => {
		if ( window.teProgress ) {
			teProgress.renderWizardSteps(
				document.getElementById( 'te-import-wizard-steps' ),
				[ 'Connect', 'Transfer', 'Verify', 'Apply', 'Done' ],
				step
			);
		}
	};

	const startImportPoller = ( migrationId ) => {
		if ( ! window.teProgress || ! migrationId ) return;
		stopImportPoller();
		importPoller = new teProgress.ProgressPoller( migrationId, 'import' );
		importPoller.onUpdate( ( snap ) => {
			teProgress.renderPipeline( document.getElementById( 'te-import-pipeline' ), snap );
		} );
		importPoller.start();
	};

	const stopImportPoller = () => {
		if ( importPoller ) {
			importPoller.stop();
			importPoller = null;
		}
	};

	const startUploadPoll = () => {
		stopUploadPoll();
		uploadPollTimer = setInterval( () => refreshStatus( true ), IMPORT_POLL_MS );
	};

	const stopUploadPoll = () => {
		if ( uploadPollTimer ) {
			clearInterval( uploadPollTimer );
			uploadPollTimer = null;
		}
	};

	const isManifestFile = ( file ) => {
		return file && ( file.name === 'manifest.json' || file.name.endsWith( '/manifest.json' ) );
	};

	const setProgress = ( text, isError = false ) => {
		const el = document.getElementById( 'te-import-upload-progress' );
		if ( el ) {
			el.textContent = text || '';
			el.className = 'te-text-muted te-mt-md' + ( isError ? ' te-text-warning' : '' );
		}
	};

	const renderMissingPanel = ( status ) => {
		const panel = document.getElementById( 'te-import-missing-panel' );
		if ( ! panel ) {
			return;
		}
		const pending = ( status?.files || [] ).filter( ( file ) => ! file.uploaded );
		if ( ! pending.length ) {
			panel.hidden = true;
			panel.innerHTML = '';
			return;
		}
		const maxBytes = status?.browser_transfer_max_bytes || teAdmin?.browserTransferMaxBytes || 67108864;
		let html = '<p class="te-import-missing-panel__title">Missing files (' + pending.length + ')</p>';
		pending.forEach( ( file ) => {
			const reason = blockReasonLabel( file.block_reason, maxBytes );
			html += '<div class="te-import-missing-panel__item">';
			html += '<code class="te-mono">' + teUI.escapeHtml( file.download_name ) + '</code>';
			html += ' <span class="te-text-muted">(' + formatBytes( file.size ) + ( reason ? ' — ' + teUI.escapeHtml( reason ) : '' ) + ')</span>';
			html += '</div>';
		} );
		panel.innerHTML = html;
		panel.hidden = false;
	};

	const renderSftpHints = ( status ) => {
		const el = document.getElementById( 'te-import-sftp-hints' );
		if ( ! el ) {
			return;
		}
		const migrationId = getMigrationId();
		const sftpOnly = ( status?.files || [] ).filter( ( file ) => ! file.uploaded && file.block_reason === 'browser_limit' );
		if ( ! sftpOnly.length || ! migrationId ) {
			el.hidden = true;
			el.innerHTML = '';
			return;
		}
		const base = getImportBase();
		let html = '<p class="te-import-sftp-hints__title">Copy these large files via SFTP or filesystem</p>';
		sftpOnly.forEach( ( file ) => {
			const folderPath = base ? base + '/migration-' + migrationId + '/' + file.path : file.path;
			html += '<code class="te-mono te-import-sftp-hints__path">' + teUI.escapeHtml( folderPath ) + '</code>';
		} );
		el.innerHTML = html;
		el.hidden = false;
	};

	const buildMissingSummary = ( status ) => {
		const pending = ( status?.files || [] ).filter( ( file ) => ! file.uploaded );
		if ( ! pending.length ) {
			return '';
		}
		const maxBytes = status?.browser_transfer_max_bytes || teAdmin?.browserTransferMaxBytes || 67108864;
		const parts = pending.slice( 0, 3 ).map( ( file ) => {
			let part = file.download_name + ' (' + formatBytes( file.size ) + ')';
			if ( file.block_reason === 'browser_limit' ) {
				part += ' — exceeds ' + formatBytes( maxBytes ) + ' browser limit';
			}
			return part;
		} );
		let text = pending.length + ' file' + ( pending.length === 1 ? '' : 's' ) + ' still needed: ' + parts.join( ', ' );
		if ( pending.length > 3 ) {
			text += '…';
		}
		return text;
	};

	const renderFileList = ( status ) => {
		const list = document.getElementById( 'te-import-file-list' );
		if ( ! list ) {
			return;
		}
		if ( ! status?.files?.length ) {
			list.innerHTML = '';
			renderMissingPanel( null );
			renderSftpHints( null );
			return;
		}
		const maxBytes = status?.browser_transfer_max_bytes || teAdmin?.browserTransferMaxBytes || 67108864;
		let html = '';
		status.files.forEach( ( file ) => {
			const done = !! file.uploaded;
			const cls = done ? ' te-expected-files__item--done' : ' te-expected-files__item--missing';
			html += '<li class="te-expected-files__item' + cls + '">';
			html += '<code class="te-mono">' + teUI.escapeHtml( file.download_name ) + '</code>';
			if ( ! done ) {
				const reason = blockReasonLabel( file.block_reason, maxBytes );
				if ( reason ) {
					html += ' <span class="te-text-muted">(' + formatBytes( file.size ) + ' — ' + teUI.escapeHtml( reason ) + ')</span>';
				}
			}
			html += '</li>';
		} );
		list.innerHTML = html;
		renderMissingPanel( status );
		renderSftpHints( status );
	};

	const updateButtons = ( status ) => {
		const validateBtn = document.getElementById( 'te-import-validate' );
		const importBtn = document.getElementById( 'te-import-all' );
		const queueBtn = document.getElementById( 'te-import-queue' );
		const uploadManifestBtn = document.getElementById( 'te-upload-manifest' );
		const retryMissingBtn = document.getElementById( 'te-retry-missing' );
		const ready = !! status?.ready_to_validate;
		const hasId = !! getMigrationId();
		const hasManifestFile = !! document.getElementById( 'te-manifest-file' )?.files?.[0];
		const browserMissing = ( status?.files || [] ).filter( ( file ) => ! file.uploaded && file.browser_uploadable ).length;

		if ( uploadManifestBtn ) {
			uploadManifestBtn.disabled = ! hasId || ! hasManifestFile || uploading;
		}
		if ( retryMissingBtn ) {
			retryMissingBtn.disabled = ! hasId || uploading || status?.needs_manifest || browserMissing === 0;
		}
		if ( validateBtn ) {
			validateBtn.disabled = ! ready;
		}
		if ( importBtn ) {
			importBtn.disabled = ! ready || ! lastValidated;
		}
		if ( queueBtn ) {
			queueBtn.disabled = ! ready || ! lastValidated;
		}
	};

	const refreshStatus = async ( silent = false ) => {
		const migrationId = getMigrationId();
		const statusPanel = document.getElementById( 'te-import-status' );
		const packageWrap = document.getElementById( 'te-package-upload-wrap' );
		const ringEl = document.getElementById( 'te-import-upload-ring' );

		if ( ! migrationId ) {
			if ( statusPanel ) {
				statusPanel.hidden = true;
			}
			if ( packageWrap ) {
				packageWrap.hidden = true;
			}
			setProgress( 'Enter the Migration ID first, then choose your files.' );
			updateButtons( null );
			setImportWizardStep( 1 );
			return null;
		}

		setImportWizardStep( 2 );

		const result = await teUI.api( 'import/' + migrationId + '/upload-status', {
			source: 'Import',
			silent,
		} );
		const status = result.ok ? result.data : null;

		if ( window.teProgress && status && ringEl ) {
			const pct = status.expected > 0 ? Math.round( ( status.uploaded / status.expected ) * 100 ) : 0;
			ringEl.hidden = false;
			ringEl.innerHTML = teProgress.ringSvg( pct );
			teProgress.renderPipeline( document.getElementById( 'te-import-pipeline' ), {
				overall_percent: pct,
				current_action: 'Uploaded ' + ( status.uploaded || 0 ) + ' / ' + ( status.expected || 0 ) + ' files',
				components: [ {
					name: 'transfer',
					status: status.ready_to_validate ? 'completed' : 'running',
					chunks_done: status.uploaded || 0,
					chunks_total: status.expected || 1,
				} ],
			} );
		}

		if ( ! status || status.needs_manifest ) {
			if ( packageWrap ) {
				packageWrap.hidden = true;
			}
			const manifestFile = document.getElementById( 'te-manifest-file' )?.files?.[0];
			const hint = manifestFile
				? 'manifest.json selected — click “Upload manifest” to send it to the server.'
				: 'Choose manifest.json below, then click Upload manifest.';
			teUI.info( statusPanel, 'Step 1: Connect', hint );
			setProgress( manifestFile ? 'Ready to upload manifest.json.' : 'Waiting for manifest.json…' );
			expectedFiles = [];
			renderFileList( null );
			updateButtons( status );
			return status;
		}

		if ( packageWrap ) {
			packageWrap.hidden = false;
		}
		renderFileList( status );

		if ( status.ready_to_validate ) {
			teUI.success( statusPanel, 'All files uploaded', status.uploaded + ' / ' + status.expected + ' files ready. Click Validate migration.' );
			setProgress( 'All files uploaded (' + status.uploaded + '/' + status.expected + ').' );
		} else {
			const summary = buildMissingSummary( status );
			teUI.info( statusPanel, 'Step 3: Upload package files', status.uploaded + ' / ' + status.expected + ' files on the server.' );
			setProgress( summary || ( 'Uploaded ' + status.uploaded + ' / ' + status.expected + '. Choose the remaining files below.' ) );
		}

		lastStatus = status;
		expectedFiles = status.files || [];
		updateButtons( status );
		return status;
	};

	const uploadFile = async ( migrationId, component, file, relativePath, checksum ) => {
		const fd = new FormData();
		fd.append( 'file', file );
		fd.append( 'relative_path', relativePath || '' );
		if ( checksum ) {
			fd.append( 'checksum', checksum );
		}
		return teUI.api( 'upload/' + migrationId + '/' + component, {
			method: 'POST',
			body: fd,
			source: 'Upload',
		} );
	};

	const matchExpectedFile = ( file ) => {
		const name = file.name;
		const exact = expectedFiles.find( ( f ) => f.download_name === name );
		if ( exact ) {
			return exact;
		}
		const partial = expectedFiles.filter( ( f ) => {
			return f.download_name === name
				|| f.download_name.endsWith( name )
				|| name.endsWith( f.download_name );
		} );
		return partial.length === 1 ? partial[0] : null;
	};

	const uploadManifest = async ( file ) => {
		const migrationId = getMigrationId();
		const statusPanel = document.getElementById( 'te-import-status' );
		if ( ! migrationId ) {
			teUI.warn( statusPanel, 'Migration ID required', 'Paste the ID from your export site before choosing files.' );
			setProgress( 'Enter Migration ID first, then choose manifest.json again.', true );
			return false;
		}
		if ( ! file ) {
			return false;
		}
		setProgress( 'Uploading manifest.json…' );
		const result = await uploadFile( migrationId, 'manifest', file, 'manifest.json', '' );
		if ( result.ok && result.data?.success ) {
			teUI.log( 'Upload', 'Manifest uploaded', 'success' );
			lastValidated = false;
			await refreshStatus();
			return true;
		}
		const err = result.data?.error || result.error || 'Upload failed';
		teUI.error( statusPanel, 'Manifest upload failed', err );
		setProgress( err, true );
		teUI.log( 'Upload', 'Manifest failed: ' + err, 'error', JSON.stringify( result.data || {}, null, 2 ) );
		return false;
	};

	const uploadPackageFiles = async ( fileList, options = {} ) => {
		const migrationId = getMigrationId();
		const statusPanel = document.getElementById( 'te-import-status' );
		const retryMissingOnly = !! options.retryMissingOnly;
		if ( ! migrationId ) {
			teUI.warn( statusPanel, 'Migration ID required', 'Paste the ID before choosing package files.' );
			setProgress( 'Enter Migration ID first, then choose package files again.', true );
			return;
		}
		if ( ! fileList?.length || uploading ) {
			return;
		}

		uploading = true;
		startUploadPoll();
		const files = Array.from( fileList );
		const manifestInBatch = retryMissingOnly ? null : files.find( isManifestFile );
		const packageOnly = files.filter( ( f ) => ! isManifestFile( f ) );

		if ( manifestInBatch ) {
			const manifestOk = await uploadManifest( manifestInBatch );
			if ( ! manifestOk ) {
				uploading = false;
				stopUploadPoll();
				return;
			}
		}

		let status = lastStatus || await refreshStatus();
		if ( ! status || status.needs_manifest ) {
			setProgress( 'manifest.json must upload successfully before package files.', true );
			uploading = false;
			stopUploadPoll();
			return;
		}

		if ( ! packageOnly.length ) {
			uploading = false;
			stopUploadPoll();
			return;
		}

		const unmatched = [];
		const tooLarge = [];
		const queue = [];

		packageOnly.forEach( ( file ) => {
			const match = matchExpectedFile( file );
			if ( ! match ) {
				unmatched.push( file.name );
				return;
			}
			if ( match.uploaded ) {
				return;
			}
			if ( retryMissingOnly && ! match.browser_uploadable ) {
				tooLarge.push( match.download_name );
				return;
			}
			if ( ! match.browser_uploadable ) {
				tooLarge.push( match.download_name );
				return;
			}
			queue.push( file );
		} );

		const skipNotes = [];
		if ( unmatched.length ) {
			skipNotes.push( unmatched.length + ' unrecognized (wrong filename)' );
		}
		if ( tooLarge.length ) {
			skipNotes.push( tooLarge.length + ' too large for browser — use SFTP' );
		}

		if ( ! queue.length ) {
			uploading = false;
			stopUploadPoll();
			const stillMissing = ( status.files || [] ).filter( ( file ) => ! file.uploaded ).length;
			let msg = skipNotes.length ? 'Nothing queued: ' + skipNotes.join( '; ' ) + '.' : 'No pending uploads in selection.';
			if ( stillMissing ) {
				msg += ' ' + stillMissing + ' file' + ( stillMissing === 1 ? '' : 's' ) + ' still missing on server.';
			}
			setProgress( msg, true );
			if ( stillMissing ) {
				teUI.warn( statusPanel, 'Upload incomplete', msg );
			}
			return;
		}

		if ( skipNotes.length ) {
			setProgress( 'Uploading ' + queue.length + ' file(s). Skipped: ' + skipNotes.join( '; ' ) + '.' );
		}

		let uploaded = 0;
		let failed = 0;
		const errors = [];

		const worker = async ( file ) => {
			const match = matchExpectedFile( file );
			if ( ! match || match.uploaded ) {
				return;
			}
			setProgress( 'Uploading ' + match.download_name + '…' );
			let attempt = 0;
			let lastError = 'Upload failed after retries';
			while ( attempt < 3 ) {
				attempt++;
				const result = await uploadFile( migrationId, match.component, file, match.path, match.checksum || '' );
				if ( result.ok && result.data?.success ) {
					uploaded++;
					teUI.log( 'Upload', 'OK: ' + match.download_name, 'success' );
					await refreshStatus( true );
					return;
				}
				lastError = result.data?.error || result.error || lastError;
				await new Promise( ( r ) => setTimeout( r, 1000 * attempt ) );
			}
			failed++;
			errors.push( file.name + ': ' + lastError );
			teUI.log( 'Upload', file.name + ': ' + lastError, 'error' );
		};

		let idx = 0;
		const runners = Array.from( { length: Math.min( UPLOAD_CONCURRENCY, queue.length ) }, async () => {
			while ( idx < queue.length ) {
				const file = queue[ idx++ ];
				await worker( file );
			}
		} );
		await Promise.all( runners );

		uploading = false;
		stopUploadPoll();
		lastValidated = false;
		status = await refreshStatus();

		if ( failed && ! uploaded ) {
			setProgress( 'Upload failed: ' + errors.join( '; ' ), true );
			teUI.error( statusPanel, 'Upload failed', errors.join( ' ' ) );
		} else if ( failed ) {
			setProgress( uploaded + ' uploaded, ' + failed + ' failed. ' + errors.join( '; ' ), true );
		} else if ( skipNotes.length && uploaded ) {
			setProgress( uploaded + ' uploaded. Skipped: ' + skipNotes.join( '; ' ) + '.' );
		}
	};

	const retryPendingUploads = async () => {
		if ( ! getMigrationId() || uploading ) {
			return;
		}
		const manifestInput = document.getElementById( 'te-manifest-file' );
		const packageInput = document.getElementById( 'te-package-files' );
		let status = await refreshStatus();

		if ( status?.needs_manifest && manifestInput?.files?.[0] ) {
			await uploadManifest( manifestInput.files[0] );
			status = await refreshStatus();
		}

		if ( ! status?.needs_manifest && packageInput?.files?.length ) {
			await uploadPackageFiles( packageInput.files );
		}
	};

	const renderReport = ( report ) => {
		const el = document.getElementById( 'te-import-report' );
		if ( ! el ) {
			return;
		}
		let html = '<div class="te-validation-mini">';
		html += '<p><strong>' + ( report.passed || report.success ? '✓ Passed' : '✗ Failed' ) + '</strong></p>';
		( report.errors || [] ).forEach( ( e ) => {
			html += '<div class="te-validation-check te-validation-check--fail">' + teUI.escapeHtml( e.message || e.code || e.error || '' ) + '</div>';
		} );
		( report.warnings || [] ).forEach( ( w ) => {
			html += '<div class="te-validation-check te-validation-check--warn">' + teUI.escapeHtml( w.message || w.code || '' ) + '</div>';
		} );
		html += '</div>';
		el.innerHTML = html;
	};

	const validateMigration = async () => {
		const migrationId = getMigrationId();
		const status = await refreshStatus();
		if ( ! status?.ready_to_validate ) {
			teUI.error( document.getElementById( 'te-import-status' ), 'Not ready', 'Upload manifest.json and all package files first.' );
			return;
		}
		const reportEl = document.getElementById( 'te-import-report' );
		if ( reportEl ) {
			reportEl.innerHTML = '<p class="te-text-muted">Validating…</p>';
		}
		const result = await teUI.api( 'import/validate', {
			method: 'POST',
			body: JSON.stringify( { migration_id: migrationId } ),
			source: 'Validate',
		} );
		const report = result.ok ? result.data : { passed: false, errors: [ { message: result.error } ] };
		lastValidated = !! report.passed;
		renderReport( report );
		setImportWizardStep( report.passed ? 4 : 3 );
		await refreshStatus();
		if ( report.passed ) {
			teUI.success( document.getElementById( 'te-import-status' ), 'Validation passed', 'Click Import all to finish the migration.' );
		} else {
			const msg = ( report.errors || [] ).map( ( e ) => e.message ).filter( Boolean ).join( ' ' ) || 'Validation failed';
			teUI.error( document.getElementById( 'te-import-status' ), 'Validation failed', msg );
			teUI.log( 'Validate', msg, 'error', JSON.stringify( report, null, 2 ) );
		}
	};

	const isSftpMode = () => teAdmin?.transferMode === 'sftp';
	const isConnectedMode = () => teAdmin?.transferMode === 'connected' || teAdmin?.isConnected;
	const isFolderOrConnected = () => isSftpMode() || isConnectedMode();

	let incomingPoller = null;

	const updateIncomingTransfer = async () => {
		const migrationId = getMigrationId();
		const el = document.getElementById( 'te-incoming-transfer-progress' );
		if ( ! el || ! isConnectedMode() || ! migrationId ) return;

		const result = await teUI.api( 'import/' + migrationId + '/sftp-status', { source: 'Incoming' } );
		if ( ! result.ok || ! result.data ) return;

		const uploaded = result.data.uploaded || 0;
		const expected = result.data.expected || 0;
		if ( expected > 0 ) {
			el.textContent = 'Files received: ' + uploaded + ' / ' + expected + ( result.data.ready_to_validate ? ' — ready to validate' : '' );
			if ( result.data.ready_to_validate ) {
				await refreshStatus( true );
			}
		} else {
			el.textContent = 'Waiting for files from export site…';
		}
	};

	const startIncomingPoller = () => {
		if ( ! isConnectedMode() || incomingPoller ) return;
		updateIncomingTransfer();
		incomingPoller = setInterval( updateIncomingTransfer, 5000 );
	};

	const stopIncomingPoller = () => {
		if ( incomingPoller ) {
			clearInterval( incomingPoller );
			incomingPoller = null;
		}
	};

	const scanImportFolder = async () => {
		const migrationId = getMigrationId();
		if ( ! migrationId ) {
			teUI.warn( document.getElementById( 'te-import-status' ), 'Migration ID required', 'Paste the export Migration ID first.' );
			return;
		}
		setProgress( 'Scanning import folder…' );
		const result = await teUI.api( 'import/' + migrationId + '/sftp-status', { source: 'SFTP scan' } );
		if ( result.ok ) {
			await refreshStatus( true );
			if ( result.data?.disk_estimate ) {
				const est = result.data.disk_estimate;
				teUI.info( document.getElementById( 'te-import-status' ), 'Folder scanned', 'Peak disk needed: ~' + formatBytes( est.peak_bytes ) );
			}
		} else {
			setProgress( result.error || 'Scan failed', true );
		}
	};

	const importViaQueue = async () => {
		const migrationId = getMigrationId();
		const run = async () => {
			setImportWizardStep( 4 );
			setProgress( 'Queuing background import…' );
			startImportPoller( migrationId );
			const result = await teUI.api( 'import/queue', {
				method: 'POST',
				body: JSON.stringify( { migration_id: migrationId, confirm: true } ),
				source: 'Import',
			} );
			if ( result.ok && result.data?.success ) {
				teUI.success( document.getElementById( 'te-import-status' ), 'Import queued', 'Import runs in the background. Check Jobs & Logs for progress.' );
				setProgress( 'Background import running — poll Jobs & Logs.' );
			} else {
				stopImportPoller();
				teUI.error( document.getElementById( 'te-import-status' ), 'Queue failed', result.data?.error || result.error || 'failed' );
			}
		};
		if ( window.teShowModal ) {
			window.teShowModal( 'Queue background import? Type CONFIRM.', run );
		} else {
			await run();
		}
	};

	const importAll = async () => {
		const migrationId = getMigrationId();
		const IMPORT_TIMEOUT_MS = 45 * 60 * 1000;
		const run = async () => {
			setImportWizardStep( 4 );
			setProgress( 'Importing…' );
			startImportPoller( migrationId );

			const result = await teUI.api( 'import/all', {
				method: 'POST',
				body: JSON.stringify( { migration_id: migrationId, confirm: true } ),
				source: 'Import',
				timeoutMs: IMPORT_TIMEOUT_MS,
			} );

			stopImportPoller();
			const payload = result.ok ? result.data : { success: false, error: result.error || result.timedOut ? 'Import timed out — check Jobs & Logs' : 'failed' };
			renderReport( payload );

			if ( payload.success ) {
				setImportWizardStep( 5 );
				teUI.success( document.getElementById( 'te-import-status' ), 'Import complete', 'Migration finished successfully.' );
				setProgress( 'Done.' );
			} else {
				teUI.error( document.getElementById( 'te-import-status' ), 'Import failed', payload.error || 'See report below.' );
			}
		};
		if ( window.teShowModal ) {
			window.teShowModal( 'Import everything into this site? Type CONFIRM.', run );
		} else {
			await run();
		}
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		if ( ! document.getElementById( 'te-import-migration-id' ) ) {
			return;
		}

		document.getElementById( 'te-manifest-file' )?.addEventListener( 'change', ( e ) => {
			const file = e.target.files?.[0];
			updateButtons( null );
			if ( file && getMigrationId() ) {
				uploadManifest( file );
			} else if ( file ) {
				teUI.info( document.getElementById( 'te-import-status' ), 'Migration ID required', 'Paste the Migration ID, then click Upload manifest.' );
			}
		} );

		document.getElementById( 'te-upload-manifest' )?.addEventListener( 'click', () => {
			const file = document.getElementById( 'te-manifest-file' )?.files?.[0];
			if ( file ) {
				uploadManifest( file );
			} else {
				teUI.warn( document.getElementById( 'te-import-status' ), 'No file selected', 'Choose manifest.json first.' );
			}
		} );

		document.getElementById( 'te-package-files' )?.addEventListener( 'change', ( e ) => {
			const files = e.target.files;
			if ( files?.length ) {
				uploadPackageFiles( files );
			}
		} );

		document.getElementById( 'te-retry-missing' )?.addEventListener( 'click', () => {
			document.getElementById( 'te-retry-missing-files' )?.click();
		} );

		document.getElementById( 'te-retry-missing-files' )?.addEventListener( 'change', ( e ) => {
			const files = e.target.files;
			if ( files?.length ) {
				uploadPackageFiles( files, { retryMissingOnly: true } );
			}
			e.target.value = '';
		} );

		document.getElementById( 'te-import-validate' )?.addEventListener( 'click', validateMigration );
		document.getElementById( 'te-import-all' )?.addEventListener( 'click', importAll );
		document.getElementById( 'te-import-queue' )?.addEventListener( 'click', importViaQueue );
		document.getElementById( 'te-scan-import-folder' )?.addEventListener( 'click', scanImportFolder );

		if ( isFolderOrConnected() ) {
			const manifestWrap = document.getElementById( 'te-manifest-file' )?.closest( '.te-mt-md' );
			const packageWrap = document.getElementById( 'te-package-upload-wrap' );
			if ( manifestWrap ) manifestWrap.hidden = true;
			if ( packageWrap ) packageWrap.hidden = true;
		}

		if ( isConnectedMode() ) {
			startIncomingPoller();
		}

		const idInput = document.getElementById( 'te-import-migration-id' );
		const onIdChange = () => retryPendingUploads();
		idInput?.addEventListener( 'change', onIdChange );
		idInput?.addEventListener( 'blur', onIdChange );
		idInput?.addEventListener( 'input', () => {
			if ( getMigrationId().length >= 36 ) {
				onIdChange();
			}
		} );

		refreshStatus();
	} );
} )();
