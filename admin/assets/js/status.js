/**
 * Shared status, activity log, and API helpers for The Exporter admin UI.
 */
( function () {
	'use strict';

	const STORAGE_KEY = 'te_activity_log_v1';
	const MAX_ENTRIES = 100;
	const entries = [];
	let filter = 'all';

	const escapeHtml = ( value ) => {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	};

	const formatTime = () => {
		return new Date().toLocaleTimeString();
	};

	const levelLabel = ( level ) => {
		const map = { success: 'OK', error: 'Error', warn: 'Warn', info: 'Info' };
		return map[ level ] || 'Info';
	};

	const formatEntryText = ( entry ) => {
		let text = entry.time + '\t' + entry.source + '\t' + entry.level + '\t' + entry.message;
		if ( entry.detail ) {
			text += '\n' + entry.detail;
		}
		return text;
	};

	const persistEntries = () => {
		try {
			sessionStorage.setItem( STORAGE_KEY, JSON.stringify( entries.slice( 0, MAX_ENTRIES ) ) );
		} catch ( e ) {
			// sessionStorage may be unavailable.
		}
	};

	const restoreEntries = () => {
		try {
			const raw = sessionStorage.getItem( STORAGE_KEY );
			if ( ! raw ) {
				return;
			}
			const saved = JSON.parse( raw );
			if ( Array.isArray( saved ) ) {
				saved.forEach( ( entry ) => entries.push( entry ) );
				if ( entries.length > MAX_ENTRIES ) {
					entries.length = MAX_ENTRIES;
				}
			}
		} catch ( e ) {
			// ignore corrupt storage.
		}
	};

	const announce = ( message ) => {
		const live = document.getElementById( 'te-live-region' );
		if ( live ) {
			live.textContent = message;
		}
	};

	const copyText = async ( text, toast ) => {
		try {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				await navigator.clipboard.writeText( text );
			} else {
				const ta = document.createElement( 'textarea' );
				ta.value = text;
				ta.setAttribute( 'readonly', '' );
				ta.style.position = 'absolute';
				ta.style.left = '-9999px';
				document.body.appendChild( ta );
				ta.select();
				document.execCommand( 'copy' );
				document.body.removeChild( ta );
			}
			if ( toast ) {
				announce( toast );
			}
			return true;
		} catch ( e ) {
			announce( 'Copy failed' );
			return false;
		}
	};

	const filteredEntries = () => {
		return entries.filter( ( entry ) => {
			if ( 'errors' === filter ) {
				return entry.level === 'error';
			}
			if ( 'warnings' === filter ) {
				return entry.level === 'warn';
			}
			if ( 'export' === filter ) {
				return entry.source === 'Export';
			}
			return true;
		} );
	};

	const renderLog = () => {
		const panel = document.getElementById( 'te-activity-log' );
		if ( ! panel ) {
			return;
		}

		const visible = filteredEntries();
		if ( ! visible.length ) {
			panel.innerHTML = '<p class="te-activity-log__empty">' + escapeHtml( 'No activity yet. Export actions appear here in real time.' ) + '</p>';
			return;
		}

		panel.innerHTML = visible.map( ( entry, idx ) => {
			const entryId = 'te-log-entry-' + idx;
			let html = '<article class="te-activity-entry te-activity-entry--' + escapeHtml( entry.level ) + '" data-entry-index="' + idx + '">';
			html += '<div class="te-activity-entry__rail" aria-hidden="true"></div>';
			html += '<div class="te-activity-entry__body">';
			html += '<div class="te-activity-entry__head">';
			html += '<span class="te-activity-entry__time">' + escapeHtml( entry.time ) + '</span>';
			html += '<span class="te-activity-entry__source">' + escapeHtml( entry.source ) + '</span>';
			html += '<span class="te-activity-entry__pill te-activity-entry__pill--' + escapeHtml( entry.level ) + '">' + escapeHtml( levelLabel( entry.level ) ) + '</span>';
			html += '<button type="button" class="te-activity-entry__copy" data-copy-entry="' + idx + '" aria-label="Copy entry">Copy</button>';
			html += '</div>';
			html += '<p class="te-activity-entry__message">' + escapeHtml( entry.message ) + '</p>';
			if ( entry.detail ) {
				html += '<details class="te-activity-entry__details"><summary>Details</summary>';
				html += '<pre class="te-activity-entry__detail te-mono" id="' + entryId + '-detail">' + escapeHtml( entry.detail ) + '</pre>';
				html += '<button type="button" class="te-activity-entry__copy-detail" data-copy-entry="' + idx + '" aria-label="Copy details">Copy details</button>';
				html += '</details>';
			}
			html += '</div></article>';
			return html;
		} ).join( '' );

		panel.querySelectorAll( '[data-copy-entry]' ).forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				const i = parseInt( btn.getAttribute( 'data-copy-entry' ), 10 );
				const entry = visible[ i ];
				if ( ! entry ) {
					return;
				}
				const detailOnly = btn.classList.contains( 'te-activity-entry__copy-detail' );
				const text = detailOnly ? entry.detail : formatEntryText( entry );
				copyText( text, detailOnly ? 'Copied error details' : 'Copied entry' );
			} );
		} );
	};

	const log = ( source, message, level = 'info', detail = '' ) => {
		const entry = {
			time: formatTime(),
			source,
			message,
			level,
			detail: detail || '',
		};
		entries.unshift( entry );
		if ( entries.length > MAX_ENTRIES ) {
			entries.length = MAX_ENTRIES;
		}
		persistEntries();
		renderLog();
		announce( source + ': ' + message );
		return entry;
	};

	const clearLog = () => {
		entries.length = 0;
		try {
			sessionStorage.removeItem( STORAGE_KEY );
		} catch ( e ) {
			// ignore.
		}
		renderLog();
		announce( 'Activity log cleared' );
	};

	const copyAll = () => {
		const visible = filteredEntries();
		const text = visible.map( formatEntryText ).join( '\n\n' );
		copyText( text, 'Copied ' + visible.length + ' lines' );
	};

	const copyErrors = () => {
		const errs = entries.filter( ( e ) => e.level === 'error' );
		if ( ! errs.length ) {
			announce( 'No errors to copy' );
			return;
		}
		const text = errs.map( formatEntryText ).join( '\n\n' );
		copyText( text, 'Copied ' + errs.length + ' error(s)' );
	};

	const copyLast = () => {
		if ( ! entries.length ) {
			announce( 'No entries to copy' );
			return;
		}
		copyText( formatEntryText( entries[ 0 ] ), 'Copied last entry' );
	};

	const bindToolbar = () => {
		document.getElementById( 'te-activity-filter' )?.addEventListener( 'change', ( ev ) => {
			filter = ev.target.value;
			renderLog();
		} );
		document.getElementById( 'te-activity-copy-all' )?.addEventListener( 'click', copyAll );
		document.getElementById( 'te-activity-copy-errors' )?.addEventListener( 'click', copyErrors );
		document.getElementById( 'te-activity-copy-last' )?.addEventListener( 'click', copyLast );
		document.getElementById( 'te-activity-clear' )?.addEventListener( 'click', () => {
			if ( window.confirm( 'Clear the activity log?' ) ) {
				clearLog();
			}
		} );
	};

	const setPanel = ( el, type, title, body, extraHtml = '' ) => {
		if ( ! el ) {
			return;
		}
		el.hidden = false;
		el.className = 'te-status-panel te-status-panel--' + type;
		el.innerHTML =
			'<div class="te-status-panel__icon" aria-hidden="true"></div>' +
			'<div class="te-status-panel__content">' +
			'<p class="te-status-panel__title">' + escapeHtml( title ) + '</p>' +
			'<p class="te-status-panel__body">' + body + '</p>' +
			extraHtml +
			'</div>';
	};

	const loading = ( el, title, body = '' ) => {
		setPanel( el, 'loading', title, body || 'Please wait…' );
	};

	const success = ( el, title, body = '', extraHtml = '' ) => {
		setPanel( el, 'success', title, body, extraHtml );
	};

	const error = ( el, title, body = '', extraHtml = '' ) => {
		setPanel( el, 'error', title, body, extraHtml );
	};

	const warn = ( el, title, body = '', extraHtml = '' ) => {
		setPanel( el, 'warn', title, body, extraHtml );
	};

	const info = ( el, title, body = '', extraHtml = '' ) => {
		setPanel( el, 'info', title, body, extraHtml );
	};

	const api = async ( endpoint, options = {} ) => {
		if ( typeof teAdmin === 'undefined' ) {
			throw new Error( 'Admin configuration not loaded. Refresh the page.' );
		}

		const url = teAdmin.root + endpoint.replace( /^\//, '' );
		const headers = { 'X-WP-Nonce': teAdmin.nonce };
		if ( ! ( options.body instanceof FormData ) ) {
			headers['Content-Type'] = 'application/json';
		}

		const source = options.source || 'API';
		if ( ! options.silent ) {
			log( source, 'Request: ' + endpoint, 'info' );
		}

		const timeoutMs = options.timeoutMs || 0;
		const controller = timeoutMs > 0 ? new AbortController() : null;
		let timer = null;
		if ( controller && timeoutMs > 0 ) {
			timer = setTimeout( () => controller.abort(), timeoutMs );
		}

		const fetchOptions = { headers, ...options };
		if ( controller ) {
			fetchOptions.signal = controller.signal;
		}
		delete fetchOptions.timeoutMs;
		delete fetchOptions.silent;
		delete fetchOptions.source;

		let res;
		try {
			res = await fetch( url, fetchOptions );
		} catch ( networkError ) {
			if ( timer ) clearTimeout( timer );
			const msg = networkError.name === 'AbortError' ? 'Request timed out — server may still be working. Check Jobs & Logs.' : networkError.message;
			if ( ! options.silent ) {
				log( source, 'Network error: ' + msg, 'error' );
			}
			return { ok: false, status: 0, error: msg, network: true, timedOut: networkError.name === 'AbortError' };
		}
		if ( timer ) clearTimeout( timer );

		const contentType = res.headers.get( 'content-type' ) || '';
		if ( contentType.includes( 'application/json' ) ) {
			const data = await res.json();
			if ( ! res.ok ) {
				const msg = data.message || data.error || data.code || ( 'HTTP ' + res.status );
				log( source, 'Failed: ' + msg, 'error', JSON.stringify( data, null, 2 ) );
				return { ok: false, status: res.status, data, error: msg };
			}
			const businessFailed = ( source === 'Validate' && data.passed === false )
				|| ( source === 'Upload' && data.success === false )
				|| ( source === 'Import' && data.success === false )
				|| ( data.success === false );
			if ( businessFailed ) {
				const msg = data.error
					|| ( data.errors && data.errors[0] && ( data.errors[0].message || data.errors[0].code ) )
					|| endpoint + ' returned failure';
				if ( ! options.silent ) {
					log( source, msg, 'error', JSON.stringify( data, null, 2 ) );
				}
			} else if ( ! options.silent ) {
				log( source, 'Success: ' + endpoint, 'success' );
			}
			return { ok: true, status: res.status, data };
		}

		if ( ! res.ok ) {
			const msg = 'HTTP ' + res.status;
			log( source, 'Failed: ' + msg, 'error' );
			return { ok: false, status: res.status, response: res, error: msg };
		}

		log( source, 'Success: ' + endpoint, 'success' );
		return { ok: true, status: res.status, response: res };
	};

	restoreEntries();
	document.addEventListener( 'DOMContentLoaded', () => {
		bindToolbar();
		renderLog();
	} );

	window.teUI = {
		log,
		renderLog,
		clearLog,
		copyAll,
		copyErrors,
		copyLast,
		formatEntryText,
		loading,
		success,
		error,
		warn,
		info,
		api,
		escapeHtml,
	};
} )();
