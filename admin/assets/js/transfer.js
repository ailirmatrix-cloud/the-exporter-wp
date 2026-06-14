/**
 * Export downloads — folder picker, ZIP bundle, and legacy per-file fallback.
 */
( function () {
	'use strict';

	if ( typeof teAdmin === 'undefined' || typeof teUI === 'undefined' ) {
		return;
	}

	const formatBytes = ( bytes ) => {
		if ( ! bytes ) return '0 B';
		if ( bytes < 1024 ) return bytes + ' B';
		if ( bytes < 1048576 ) return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
		if ( bytes < 1073741824 ) return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		return ( bytes / 1073741824 ).toFixed( 2 ) + ' GB';
	};

	const supportsFolderPicker = typeof window.showDirectoryPicker === 'function';

	const fileDownloadLabel = ( file ) => file.download_name || file.path.split( '/' ).pop();

	const getProgressEls = () => ( {
		wrap: document.getElementById( 'te-download-progress' ),
		bar: document.getElementById( 'te-download-progress-bar' ),
		label: document.getElementById( 'te-download-progress-label' ),
	} );

	const setProgress = ( done, total, message ) => {
		const { wrap, bar, label } = getProgressEls();
		if ( ! wrap ) return;
		wrap.hidden = false;
		if ( bar ) {
			bar.max = total || 100;
			bar.value = done;
		}
		if ( label ) {
			label.textContent = message || ( done + ' / ' + total );
		}
	};

	const hideProgress = () => {
		const { wrap } = getProgressEls();
		if ( wrap ) wrap.hidden = true;
	};

	const fetchFileBlob = async ( migrationId, file ) => {
		const filename = fileDownloadLabel( file );
		const url = teAdmin.root + 'download/' + migrationId + '/' + file.hash;
		const res = await fetch( url, { headers: { 'X-WP-Nonce': teAdmin.nonce } } );
		if ( ! res.ok ) {
			throw new Error( 'Download failed for ' + filename + ' (HTTP ' + res.status + ')' );
		}
		const expectedChecksum = file.checksum || res.headers.get( 'X-TE-Checksum-Sha256' );
		const blob = await res.blob();
		if ( expectedChecksum && window.crypto?.subtle ) {
			const buf = await blob.arrayBuffer();
			const digest = await crypto.subtle.digest( 'SHA-256', buf );
			const hex = Array.from( new Uint8Array( digest ) ).map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
			if ( hex !== expectedChecksum.toLowerCase() ) {
				throw new Error( 'Checksum mismatch for ' + filename );
			}
		}
		return { blob, filename };
	};

	const saveBlobToDirectory = async ( dirHandle, filename, blob ) => {
		const fileHandle = await dirHandle.getFileHandle( filename, { create: true } );
		const writable = await fileHandle.createWritable();
		await writable.write( blob );
		await writable.close();
	};

	const collectAllSafeFiles = ( data, componentName = '' ) => {
		const files = [];
		if ( ! componentName && data.global_files?.length ) {
			data.global_files.filter( ( f ) => f.browser_safe ).forEach( ( f ) => files.push( f ) );
		}
		( data.components || [] ).forEach( ( comp ) => {
			if ( componentName && comp.name !== componentName ) {
				return;
			}
			( comp.files || [] ).filter( ( f ) => f.browser_safe ).forEach( ( f ) => files.push( f ) );
		} );
		return files;
	};

	const saveFilesToFolder = async ( migrationId, files, label ) => {
		if ( ! supportsFolderPicker ) {
			throw new Error( 'Your browser does not support “Save to folder”. Try Chrome/Edge or download the ZIP instead.' );
		}
		const dirHandle = await window.showDirectoryPicker( { mode: 'readwrite' } );
		const total = files.length;
		for ( let i = 0; i < total; i++ ) {
			const file = files[ i ];
			const name = fileDownloadLabel( file );
			setProgress( i, total, 'Saving ' + name + ' (' + ( i + 1 ) + ' / ' + total + ')' );
			const { blob, filename } = await fetchFileBlob( migrationId, file );
			await saveBlobToDirectory( dirHandle, filename, blob );
			teUI.log( 'Download', 'Saved: ' + filename, 'success' );
		}
		setProgress( total, total, 'Done — ' + total + ' files saved' );
		teUI.log( 'Download', label + ': ' + total + ' files saved to folder', 'success' );
	};

	const downloadZipBundle = async ( migrationId, component = '' ) => {
		const path = component
			? 'download/' + migrationId + '/bundle/' + component
			: 'download/' + migrationId + '/bundle';
		const url = teAdmin.root + path;
		setProgress( 0, 1, 'Building ZIP…' );
		const res = await fetch( url, { headers: { 'X-WP-Nonce': teAdmin.nonce } } );
		if ( ! res.ok ) {
			const text = await res.text();
			throw new Error( text || ( 'ZIP download failed (HTTP ' + res.status + ')' ) );
		}
		const blob = await res.blob();
		const disposition = res.headers.get( 'Content-Disposition' ) || '';
		const match = disposition.match( /filename="([^"]+)"/ );
		const filename = match ? match[1] : ( 'the-exporter-' + migrationId.slice( 0, 8 ) + '.zip' );
		const a = document.createElement( 'a' );
		a.href = URL.createObjectURL( blob );
		a.download = filename;
		a.click();
		URL.revokeObjectURL( a.href );
		setProgress( 1, 1, 'ZIP downloaded' );
		teUI.log( 'Download', 'ZIP saved: ' + filename, 'success' );
	};

	/** Legacy: triggers one browser save dialog per file. */
	const downloadFileLegacy = async ( migrationId, file ) => {
		const { blob, filename } = await fetchFileBlob( migrationId, file );
		const a = document.createElement( 'a' );
		a.href = URL.createObjectURL( blob );
		a.download = filename;
		a.click();
		URL.revokeObjectURL( a.href );
		teUI.log( 'Download', 'Saved: ' + filename, 'success' );
	};

	const downloadQueueLegacy = async ( migrationId, files, label ) => {
		const total = files.length;
		for ( let i = 0; i < total; i++ ) {
			setProgress( i, total, 'Downloading ' + ( i + 1 ) + ' / ' + total );
			await downloadFileLegacy( migrationId, files[ i ] );
		}
		setProgress( total, total, 'Done' );
		teUI.log( 'Download', 'Package complete: ' + label, 'success' );
	};

	let lastPackageData = null;

	const updateDownloadButtons = ( data ) => {
		const folderBtn = document.getElementById( 'te-save-to-folder' );
		const zipBtn = document.getElementById( 'te-download-zip' );
		const legacyBtn = document.getElementById( 'te-download-all' );
		const transfer = data.transfer || {};
		const fileCount = transfer.file_count || 0;

		if ( folderBtn ) {
			folderBtn.disabled = fileCount === 0;
			folderBtn.hidden = ! supportsFolderPicker;
		}
		if ( zipBtn ) {
			zipBtn.disabled = ! transfer.zip_available;
			if ( transfer.total_bytes > ( transfer.zip_max_bytes || 0 ) ) {
				zipBtn.title = 'Package exceeds ' + formatBytes( transfer.zip_max_bytes ) + ' — use Save to folder or SFTP';
			}
		}
		if ( legacyBtn ) {
			legacyBtn.disabled = fileCount === 0;
		}
	};

	const loadExportPackages = async () => {
		const wrap = document.getElementById( 'te-download-packages' );
		const list = document.getElementById( 'te-component-download-list' );
		const statusPanel = document.getElementById( 'te-packages-status' );
		const manifestBtn = document.getElementById( 'te-download-manifest' );
		const pathHint = document.getElementById( 'te-export-path-hint' );

		if ( ! wrap || ! list ) return;

		const migrationId = wrap.dataset.migrationId || teAdmin.migrationId;
		if ( ! migrationId ) {
			teUI.warn( statusPanel, 'No migration ID', 'Click Start Export first.' );
			list.innerHTML = '<p class="te-text-warning">Run export before downloading.</p>';
			return;
		}

		teUI.loading( statusPanel, 'Loading packages', 'Migration ID: ' + migrationId );
		const result = await teUI.api( 'packages/' + migrationId, { source: 'Packages' } );

		if ( ! result.ok ) {
			teUI.error( statusPanel, 'Could not load packages', result.error || 'Unknown error' );
			return;
		}

		const data = result.data;
		lastPackageData = data;
		updateDownloadButtons( data );

		if ( pathHint && data.transfer?.export_path ) {
			pathHint.textContent = data.transfer.export_path;
			pathHint.closest( '.te-export-path' )?.removeAttribute( 'hidden' );
		}

		if ( ! data.components?.length ) {
			teUI.warn( statusPanel, 'Nothing to download yet', 'Click Export All above, then return here.' );
			list.innerHTML = '<p class="te-text-muted">Export must finish before files appear.</p>';
			if ( manifestBtn ) manifestBtn.disabled = true;
			return;
		}

		const transfer = data.transfer || {};
		const summary = transfer.file_count + ' files · ' + formatBytes( transfer.total_bytes );
		const hint = supportsFolderPicker
			? 'Recommended: Save all to folder (one prompt, no per-file dialogs).'
			: ( transfer.zip_available ? 'Recommended: Download ZIP (one file).' : 'Use SFTP or copy from the export folder path below.' );

		teUI.success( statusPanel, 'Ready to download', summary + '. ' + hint );

		if ( manifestBtn && data.global_files?.[0] ) {
			manifestBtn.disabled = false;
			manifestBtn.onclick = async () => {
				try {
					await downloadFileLegacy( migrationId, data.global_files[0] );
				} catch ( err ) {
					teUI.log( 'Download', err.message, 'error' );
				}
			};
		}

		list.innerHTML = '';
		data.components.forEach( ( comp ) => {
			if ( ! comp.file_count ) {
				return;
			}
			const safeFiles = ( comp.files || [] ).filter( ( f ) => f.browser_safe );
			const oversized = ( comp.files || [] ).filter( ( f ) => ! f.browser_safe );
			const panel = document.createElement( 'div' );
			panel.className = 'te-component-panel te-glass-card';
			let badges = '<span class="te-badge te-badge--completed">' + comp.file_count + ' files · ' + formatBytes( comp.total_bytes ) + '</span>';
			if ( oversized.length ) {
				badges += ' <span class="te-badge te-badge--warning">SFTP required: ' + oversized.length + ' large file(s)</span>';
			}
			panel.innerHTML =
				'<div class="te-component-panel__header">' +
				'<h3 class="te-component-panel__title">' + teUI.escapeHtml( comp.label ) + '</h3>' +
				badges + '</div>' +
				'<div class="te-component-panel__body"><div class="te-actions">' +
				( supportsFolderPicker ? '<button type="button" class="te-btn te-btn--secondary te-dl-folder">Save to folder</button>' : '' ) +
				'<button type="button" class="te-btn te-btn--secondary te-dl-zip">ZIP</button>' +
				'</div>';
			if ( oversized.length ) {
				panel.innerHTML += '<ul class="te-transfer-file-list">';
				oversized.forEach( ( file ) => {
					panel.innerHTML += '<li class="te-transfer-file-list__item"><code class="te-mono">' + teUI.escapeHtml( fileDownloadLabel( file ) ) + '</code>' +
						'<span class="te-badge te-badge--warning">' + formatBytes( file.size ) + ' — SFTP</span></li>';
				} );
				panel.innerHTML += '</ul>';
			}
			panel.innerHTML += '</div>';

			panel.querySelector( '.te-dl-folder' )?.addEventListener( 'click', async ( e ) => {
				const btn = e.currentTarget;
				btn.disabled = true;
				try {
					await saveFilesToFolder( migrationId, safeFiles, comp.label );
				} catch ( err ) {
					if ( err.name !== 'AbortError' ) {
						teUI.log( 'Download', err.message, 'error' );
					}
				} finally {
					btn.disabled = false;
					hideProgress();
				}
			} );

			panel.querySelector( '.te-dl-zip' )?.addEventListener( 'click', async ( e ) => {
				const btn = e.currentTarget;
				btn.disabled = true;
				try {
					await downloadZipBundle( migrationId, comp.name );
				} catch ( err ) {
					teUI.log( 'Download', err.message, 'error' );
				} finally {
					btn.disabled = false;
					hideProgress();
				}
			} );

			list.appendChild( panel );
		} );
	};

	const saveAllToFolder = async () => {
		if ( ! lastPackageData ) return;
		const migrationId = document.getElementById( 'te-download-packages' )?.dataset.migrationId || teAdmin.migrationId;
		const btn = document.getElementById( 'te-save-to-folder' );
		if ( btn ) btn.disabled = true;
		try {
			const files = collectAllSafeFiles( lastPackageData );
			await saveFilesToFolder( migrationId, files, 'Full migration' );
		} catch ( err ) {
			if ( err.name !== 'AbortError' ) {
				teUI.log( 'Download', err.message, 'error' );
			}
		} finally {
			if ( btn ) btn.disabled = false;
			hideProgress();
		}
	};

	const downloadZipAll = async () => {
		if ( ! lastPackageData ) return;
		const migrationId = document.getElementById( 'te-download-packages' )?.dataset.migrationId || teAdmin.migrationId;
		const btn = document.getElementById( 'te-download-zip' );
		if ( btn ) btn.disabled = true;
		try {
			await downloadZipBundle( migrationId, '' );
		} catch ( err ) {
			teUI.log( 'Download', err.message, 'error' );
		} finally {
			if ( btn ) btn.disabled = false;
			hideProgress();
		}
	};

	const downloadAllLegacy = async () => {
		if ( ! lastPackageData ) return;
		const migrationId = document.getElementById( 'te-download-packages' )?.dataset.migrationId || teAdmin.migrationId;
		const btn = document.getElementById( 'te-download-all' );
		if ( btn ) {
			btn.disabled = true;
			btn.textContent = 'Downloading…';
		}
		try {
			const files = collectAllSafeFiles( lastPackageData );
			await downloadQueueLegacy( migrationId, files, 'Full migration' );
		} catch ( err ) {
			teUI.log( 'Download', err.message, 'error' );
		} finally {
			if ( btn ) {
				btn.disabled = false;
				btn.textContent = 'Download each file (legacy)';
			}
			hideProgress();
		}
	};

	window.teLoadExportPackages = loadExportPackages;

	document.addEventListener( 'DOMContentLoaded', () => {
		if ( document.getElementById( 'te-download-packages' ) ) {
			loadExportPackages();
		}
		document.getElementById( 'te-save-to-folder' )?.addEventListener( 'click', saveAllToFolder );
		document.getElementById( 'te-download-zip' )?.addEventListener( 'click', downloadZipAll );
		document.getElementById( 'te-download-all' )?.addEventListener( 'click', downloadAllLegacy );
	} );
} )();
