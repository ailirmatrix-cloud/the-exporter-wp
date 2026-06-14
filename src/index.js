/**
 * Migrate wizard — React UI.
 */
import { render, useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	TextControl,
	Spinner,
	Notice,
} from '@wordpress/components';
import './style.scss';

const ROOT = 'te-migrate-root';

function copyText( text ) {
	if ( navigator.clipboard && navigator.clipboard.writeText ) {
		return navigator.clipboard.writeText( text );
	}
	const ta = document.createElement( 'textarea' );
	ta.value = text;
	ta.setAttribute( 'readonly', '' );
	ta.style.position = 'absolute';
	ta.style.left = '-9999px';
	document.body.appendChild( ta );
	ta.select();
	document.execCommand( 'copy' );
	document.body.removeChild( ta );
	return Promise.resolve();
}

function isLocalUrl( url ) {
	return /localhost|127\.0\.0\.1/i.test( url || '' );
}

function plainErrorMessage( text ) {
	if ( ! text || typeof text !== 'string' ) {
		return text;
	}
	if ( /<p>There has been a critical error/i.test( text ) ) {
		return 'WordPress encountered a critical error. Update The Exporter on both sites to the latest version, or check your site error log.';
	}
	if ( /<[a-z][\s\S]*>/i.test( text ) ) {
		const tmp = document.createElement( 'div' );
		tmp.innerHTML = text;
		const plain = ( tmp.textContent || tmp.innerText || '' ).trim();
		return plain || 'Request failed.';
	}
	return text;
}

function formatPushCurrentFile( currentFile ) {
	if ( ! currentFile ) {
		return '';
	}
	if ( typeof currentFile === 'string' ) {
		return currentFile;
	}
	if ( typeof currentFile === 'object' && currentFile.path ) {
		return currentFile.path;
	}
	return '';
}

function formatStallLikely( diag ) {
	if ( ! diag || ! diag.likely ) {
		return '';
	}
	const map = {
		export_send_tab_not_driving: 'Export Send tab is not pushing (keep it open in the foreground).',
		export_send_tab_closed_or_never_started: 'Export Send was never started or the tab was closed.',
		export_push_failed: 'Export push reported an error.',
		export_stuck_on_current_file: 'Export reports active but no files are completing — likely stuck on the current file. Keep Send open; check export site for errors.',
		stuck_mid_chunk: 'A large file chunk started but did not complete.',
	};
	return map[ diag.likely ] || diag.likely;
}

function getConfig() {
	return window.teMigrate || window.teAdmin || {};
}

function useApi() {
	const call = useCallback( async ( endpoint, options = {} ) => {
		const cfg = getConfig();
		const root = ( cfg.root || '' ).replace( /\/?$/, '/' );
		const url = root + endpoint.replace( /^\//, '' );
		const method = options.method || 'GET';
		const headers = {
			'X-WP-Nonce': cfg.nonce || '',
		};
		const fetchOptions = {
			method,
			headers,
			credentials: 'same-origin',
		};

		if ( options.body !== undefined && 'GET' !== method ) {
			headers[ 'Content-Type' ] = 'application/json';
			fetchOptions.body = JSON.stringify( options.body );
		}

		try {
			const res = await fetch( url, fetchOptions );
			const text = await res.text();
			let data = null;
			if ( text ) {
				try {
					data = JSON.parse( text );
				} catch ( parseError ) {
					data = { message: text };
				}
			}
			if ( ! res.ok ) {
				return {
					ok: false,
					status: res.status,
					error: plainErrorMessage( data?.message || data?.error || res.statusText || 'Request failed' ),
					data,
				};
			}
			return { ok: true, status: res.status, data };
		} catch ( err ) {
			return {
				ok: false,
				error: err?.message || String( err ),
				network: true,
			};
		}
	}, [] );

	return { call, cfg: getConfig() };
}

function Stepper( { steps, current } ) {
	return (
		<div className="te-migrate__stepper" role="list">
			{ steps.map( ( label, i ) => {
				const n = i + 1;
				let cls = 'te-migrate__step';
				if ( n === current ) {
					cls += ' te-migrate__step--active';
				} else if ( n < current ) {
					cls += ' te-migrate__step--done';
				}
				return (
					<div key={ label } className={ cls } role="listitem">
						{ n }. { label }
					</div>
				);
			} ) }
		</div>
	);
}

function StatusBox( { type, children } ) {
	if ( ! children ) {
		return null;
	}
	return <div className={ `te-migrate__status te-migrate__status--${ type }` }>{ children }</div>;
}

const TRANSFER_POLL_MS = 2000;
const TRANSFER_POLL_GAP_MS = 2000;
const EXPORT_POLL_MS = ( typeof window !== 'undefined' && /localhost|127\.0\.0\.1/i.test( window.location?.hostname || '' ) ) ? 800 : 2000;
const TRANSFER_NUDGE_MS = ( typeof window !== 'undefined' && /localhost|127\.0\.0\.1/i.test( window.location?.hostname || '' ) ) ? 10000 : 20000;
const TRANSFER_NUDGE_HIDDEN_MS = 10000;

function formatBytes( bytes ) {
	const n = Number( bytes ) || 0;
	if ( n < 1024 ) {
		return n + ' B';
	}
	if ( n < 1048576 ) {
		return ( n / 1024 ).toFixed( 1 ) + ' KB';
	}
	if ( n < 1073741824 ) {
		return ( n / 1048576 ).toFixed( 1 ) + ' MB';
	}
	return ( n / 1073741824 ).toFixed( 2 ) + ' GB';
}

function formatEta( seconds ) {
	const s = Math.max( 0, Math.round( Number( seconds ) || 0 ) );
	if ( s < 60 ) {
		return s + 's';
	}
	if ( s < 3600 ) {
		return Math.ceil( s / 60 ) + ' min';
	}
	return Math.ceil( s / 3600 ) + ' hr';
}

function formatTimeAgo( iso ) {
	if ( ! iso ) {
		return '';
	}
	const ts = Date.parse( iso );
	if ( Number.isNaN( ts ) ) {
		return '';
	}
	const sec = Math.max( 0, Math.floor( ( Date.now() - ts ) / 1000 ) );
	if ( sec < 5 ) {
		return window.teMigrate?.strings?.justNow || 'just now';
	}
	if ( sec < 60 ) {
		return sec + 's ago';
	}
	if ( sec < 3600 ) {
		return Math.floor( sec / 60 ) + 'm ago';
	}
	return Math.floor( sec / 3600 ) + 'h ago';
}

function useTransferRate( count ) {
	const last = useRef( { count: 0, at: 0 } );
	const [ rate, setRate ] = useState( 0 );

	useEffect( () => {
		const now = Date.now();
		const prev = last.current;
		if ( prev.at && now > prev.at ) {
			const delta = ( count || 0 ) - prev.count;
			const secs = ( now - prev.at ) / 1000;
			if ( delta > 0 && secs > 0 ) {
				setRate( delta / secs );
			}
		}
		last.current = { count: count || 0, at: now };
	}, [ count ] );

	return rate;
}

function useRollingRate( value, windowSize = 5 ) {
	const samples = useRef( [] );
	const [ rate, setRate ] = useState( 0 );

	useEffect( () => {
		const now = Date.now();
		const v = Number( value ) || 0;
		const prev = samples.current[ samples.current.length - 1 ];
		if ( prev && now > prev.at ) {
			const delta = v - prev.value;
			const secs = ( now - prev.at ) / 1000;
			if ( delta > 0 && secs > 0 ) {
				samples.current.push( { rate: delta / secs, at: now } );
			}
		}
		samples.current.push( { value: v, at: now } );
		if ( samples.current.length > windowSize * 2 ) {
			samples.current = samples.current.slice( -windowSize * 2 );
		}
		const rates = samples.current.filter( ( s ) => s.rate ).map( ( s ) => s.rate );
		if ( rates.length ) {
			const avg = rates.reduce( ( a, b ) => a + b, 0 ) / rates.length;
			setRate( avg );
		}
	}, [ value, windowSize ] );

	return rate;
}

function usePollChain( pollFn, active ) {
	const timer = useRef( null );
	const running = useRef( false );

	const stop = useCallback( () => {
		if ( timer.current ) {
			clearTimeout( timer.current );
			timer.current = null;
		}
		running.current = false;
	}, [] );

	const schedule = useCallback( () => {
		if ( ! active || running.current ) {
			return;
		}
		running.current = true;
		Promise.resolve( pollFn() ).finally( () => {
			running.current = false;
			if ( active ) {
				timer.current = setTimeout( schedule, TRANSFER_POLL_GAP_MS );
			}
		} );
	}, [ pollFn, active ] );

	useEffect( () => {
		if ( active ) {
			schedule();
		} else {
			stop();
		}
		return stop;
	}, [ active, schedule, stop ] );

	return stop;
}

function componentStatusIcon( status ) {
	if ( status === 'completed' ) {
		return '✓';
	}
	if ( status === 'running' ) {
		return '↻';
	}
	return '○';
}

function TransferStats( { rate, byteRate, etaSeconds, bytesDone, bytesTotal, filesDone, filesTotal, inflight } ) {
	const strings = window.teMigrate?.strings || {};
	if ( ! filesTotal && ! bytesTotal ) {
		return null;
	}
	const hasInflight = inflight?.bytes_total > 0 && inflight?.bytes_done < inflight?.bytes_total;
	const parts = [];
	if ( ! hasInflight && rate > 0.05 ) {
		parts.push( rate.toFixed( 1 ) + '/s ' + ( strings.filesPerSec || 'files' ) );
	}
	if ( hasInflight ) {
		parts.push( strings.receivingLarge || 'Receiving large file…' );
	} else if ( byteRate > 1024 ) {
		parts.push( formatBytes( byteRate ) + '/s' );
	}
	if ( ! hasInflight && etaSeconds > 0 && filesDone < filesTotal ) {
		parts.push( '~' + formatEta( etaSeconds ) + ' ' + ( strings.remaining || 'left' ) );
	}
	if ( bytesTotal > 0 ) {
		parts.push( formatBytes( bytesDone ) + ' / ' + formatBytes( bytesTotal ) );
	}
	if ( ! parts.length ) {
		return null;
	}
	return <div className="te-migrate__stats">{ parts.join( ' · ' ) }</div>;
}

function ComponentChecklist( { components } ) {
	if ( ! components?.length ) {
		return null;
	}
	return (
		<div className="te-migrate__component-list">
			<div className="te-migrate__component-list-title">
				{ window.teMigrate?.strings?.componentProgress || 'Component progress' }
			</div>
			{ components.map( ( c ) => {
				const total = c.chunks_total || 0;
				const done = c.chunks_done || 0;
				const pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
				let rowCls = 'te-migrate__component-row';
				if ( c.status === 'running' ) {
					rowCls += ' te-migrate__component-row--active';
				} else if ( c.status === 'completed' ) {
					rowCls += ' te-migrate__component-row--done';
				}
				return (
					<div key={ c.name } className={ rowCls }>
						<span className="te-migrate__component-icon">{ componentStatusIcon( c.status ) }</span>
						<span className="te-migrate__component-label">{ c.label || c.name }</span>
						<span className="te-migrate__component-count">{ done } / { total }</span>
						<div className="te-migrate__component-bar">
							<div className="te-migrate__component-bar-fill" style={ { width: pct + '%' } } />
						</div>
					</div>
				);
			} ) }
		</div>
	);
}

function ActivityFeed( { items, pulseKey } ) {
	if ( ! items?.length ) {
		return null;
	}
	return (
		<div className={ 'te-migrate__activity-feed' + ( pulseKey ? ' te-migrate__activity-feed--pulse' : '' ) }>
			<div className="te-migrate__activity-feed-title">
				{ window.teMigrate?.strings?.recentActivity || 'Recent activity' }
			</div>
			<ul className="te-migrate__activity-list">
				{ items.map( ( item, i ) => (
					<li key={ item.path + '-' + i } className="te-migrate__activity-item">
						<span className="te-migrate__activity-check">✓</span>
						<span className="te-migrate__activity-path">{ item.path }</span>
						<span className="te-migrate__activity-meta">
							{ formatBytes( item.size ) }{ ' ' }
							{ formatTimeAgo( item.received_at ) }
						</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

function TransferProgressPanel( { pct, action, stats, components, recent, pulseKey, stale, staleMessage } ) {
	if ( pct <= 0 && ! action && ! components?.length ) {
		return null;
	}
	return (
		<div className="te-migrate__transfer-panel">
			{ pct > 0 && (
				<div className="te-migrate__progress-bar">
					<div
						className={ 'te-migrate__progress-bar-fill' + ( pulseKey ? ' te-migrate__progress-pulse' : '' ) }
						style={ { width: pct + '%' } }
					/>
				</div>
			) }
			{ action && <StatusBox type="info">{ action }</StatusBox> }
			{ stats }
			<ComponentChecklist components={ components } />
			<ActivityFeed items={ recent } pulseKey={ pulseKey } />
			{ stale && staleMessage && (
				<Notice status="warning" isDismissible={ false } className="te-migrate__notice">
					{ staleMessage }
				</Notice>
			) }
		</div>
	);
}

function RolePicker( { onSelect, busy, error, pendingRole } ) {
	return (
		<Card className="te-glass-card te-migrate__card">
			<CardHeader>
				<h2>{ window.teMigrate?.strings?.roleTitle || 'What is this site?' }</h2>
			</CardHeader>
			<CardBody>
				<p className="te-migrate__intro">
					{ window.teMigrate?.strings?.roleIntro ||
						'Choose whether this WordPress site is the one you are copying from or copying to.' }
				</p>
				{ error && (
					<Notice status="error" isDismissible={ false } className="te-migrate__notice">
						{ error }
					</Notice>
				) }
				<div className="te-migrate__role-grid">
					<button
						type="button"
						className={ 'te-migrate__role-btn' + ( pendingRole === 'export' ? ' te-migrate__role-btn--pending' : '' ) }
						disabled={ busy }
						onClick={ () => onSelect( 'export' ) }
					>
						<strong>{ window.teMigrate?.strings?.exportSite || 'Export site' }</strong>
						<span>{ window.teMigrate?.strings?.exportHint || 'Copy this site to another server' }</span>
						{ busy && pendingRole === 'export' && <Spinner className="te-migrate__role-spinner" /> }
					</button>
					<button
						type="button"
						className={ 'te-migrate__role-btn' + ( pendingRole === 'import' ? ' te-migrate__role-btn--pending' : '' ) }
						disabled={ busy }
						onClick={ () => onSelect( 'import' ) }
					>
						<strong>{ window.teMigrate?.strings?.importSite || 'Import site' }</strong>
						<span>{ window.teMigrate?.strings?.importHint || 'Receive a migration from another site' }</span>
						{ busy && pendingRole === 'import' && <Spinner className="te-migrate__role-spinner" /> }
					</button>
				</div>
			</CardBody>
		</Card>
	);
}

function ExportConnectStep( { onConnected, api } ) {
	const [ url, setUrl ] = useState( api.cfg.remoteSiteUrl || '' );
	const [ token, setToken ] = useState( api.cfg.remotePairingToken || '' );
	const [ busy, setBusy ] = useState( false );
	const [ msg, setMsg ] = useState( '' );
	const [ ok, setOk ] = useState( false );

	const saveAndTest = async () => {
		setBusy( true );
		setMsg( '' );
		const res = await api.call( 'wizard/connect', {
			method: 'POST',
			body: { remote_site_url: url, remote_pairing_token: token },
		} );
		setBusy( false );
		if ( res.ok && res.data?.success ) {
			setOk( true );
			const peerPost = res.data.php_limits?.post_max_size || 0;
			let message = ( res.data.message || 'Connected.' ) + ( res.data.push_url && res.data.push_url !== url ? ` Server push URL: ${ res.data.push_url }` : '' );
			if ( peerPost > 0 && peerPost < 12582912 ) {
				message += ' Warning: import host PHP upload limit is very low — connected transfer may stall on large files.';
			}
			setMsg( message );
			onConnected();
		} else {
			setOk( false );
			setMsg( res.data?.error || res.error || 'Connection failed.' );
		}
	};

	const saveAndContinue = async () => {
		setBusy( true );
		setMsg( '' );
		const res = await api.call( 'wizard/connect', {
			method: 'POST',
			body: { remote_site_url: url, remote_pairing_token: token, skip_test: true },
		} );
		setBusy( false );
		if ( res.ok ) {
			setMsg( 'Settings saved. You can test the connection later from Advanced.' );
			onConnected();
		} else {
			setMsg( res.data?.error || res.error || 'Could not save settings.' );
		}
	};

	return (
		<Card className="te-glass-card te-migrate__card">
			<CardHeader>
				<h2>{ window.teMigrate?.strings?.connectTitle || 'Connect to import site' }</h2>
			</CardHeader>
			<CardBody>
				<p className="te-migrate__intro">
					{ window.teMigrate?.strings?.connectIntro ||
						'Paste the import site URL and pairing code from the import site wizard.' }
				</p>
				{ isLocalUrl( url ) && (
					<Notice status="info" isDismissible={ false }>
						{ window.teMigrate?.strings?.studioHint ||
							'Studio tip: keep both site tabs open during export and send. The server auto-uses host.docker.internal for transfers.' }
					</Notice>
				) }
				<TextControl
					label={ window.teMigrate?.strings?.importUrl || 'Import site URL' }
					value={ url }
					onChange={ setUrl }
					placeholder="http://localhost:8881"
				/>
				<TextControl
					label={ window.teMigrate?.strings?.pairingCode || 'Pairing code' }
					value={ token }
					onChange={ setToken }
					className="te-mono"
				/>
				<div className="te-migrate__actions">
					<Button variant="primary" onClick={ saveAndTest } disabled={ busy || ! url || ! token } isBusy={ busy }>
						{ window.teMigrate?.strings?.testConnect || 'Test & save connection' }
					</Button>
					<Button variant="secondary" onClick={ saveAndContinue } disabled={ busy || ! url }>
						{ window.teMigrate?.strings?.saveContinue || 'Save & continue' }
					</Button>
				</div>
				<StatusBox type={ ok ? 'success' : ( msg ? 'error' : 'info' ) }>{ msg }</StatusBox>
			</CardBody>
		</Card>
	);
}

function ExportWorkStep( { api, migrationId, setMigrationId, onDone } ) {
	const [ busy, setBusy ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ progress, setProgress ] = useState( null );
	const [ driving, setDriving ] = useState( false );
	const poller = useRef( null );
	const drivingRef = useRef( false );

	const refreshProgress = useCallback( async ( id ) => {
		const res = await api.call( 'migration/' + id + '/progress?context=export' );
		if ( ! res.ok || ! res.data ) {
			return null;
		}
		setProgress( res.data );
		return res.data;
	}, [ api ] );

	const driveOnce = useCallback( async ( id ) => {
		if ( drivingRef.current ) {
			return null;
		}
		drivingRef.current = true;
		setDriving( true );
		const res = await api.call( 'export/drive', {
			method: 'POST',
			body: { migration_id: id },
		} );
		drivingRef.current = false;
		setDriving( false );
		return res;
	}, [ api ] );

	const stopPoll = useCallback( () => {
		if ( poller.current ) {
			clearInterval( poller.current );
			poller.current = null;
		}
	}, [] );

	const handleSnap = useCallback( ( snap, driveRes ) => {
		const pct = snap?.overall_percent ?? 0;
		const action = snap?.current_action || '';
		if (
			driveRes?.ok && driveRes.data?.success &&
			( driveRes.data.finalized || driveRes.data.path || ( driveRes.data.done && ! driveRes.data.waiting_finalize ) )
		) {
			stopPoll();
			setStatus( 'Export complete.' );
			onDone();
			return;
		}
		if (
			snap &&
			( snap.phase === 'finalize' ||
				snap.job_status === 'completed' ||
				pct >= 100 )
		) {
			stopPoll();
			setStatus( 'Export complete.' );
			onDone();
			return;
		}
		if ( driveRes && ! driveRes.ok ) {
			setStatus( driveRes.error || driveRes.data?.error || 'Export engine error.' );
			return;
		}
		if ( snap ) {
			setStatus(
				`Building packages… ${ Math.round( pct ) }%` +
				( action ? ` (${ action })` : '' )
			);
		}
	}, [ onDone, stopPoll ] );

	const poll = useCallback( async ( id ) => {
		const driveRes = await driveOnce( id );
		const snap = await refreshProgress( id );
		handleSnap( snap, driveRes );
	}, [ driveOnce, refreshProgress, handleSnap ] );

	const startPoll = useCallback( ( id ) => {
		stopPoll();
		poll( id );
		poller.current = setInterval( () => poll( id ), EXPORT_POLL_MS );
	}, [ poll, stopPoll ] );

	useEffect( () => {
		if ( migrationId ) {
			startPoll( migrationId );
		}
		return () => stopPoll();
	}, [ migrationId, startPoll, stopPoll ] );

	const startExport = async () => {
		setBusy( true );
		setStatus( 'Starting export…' );
		let mid = migrationId;
		if ( ! mid ) {
			const init = await api.call( 'export/init', { method: 'POST', body: {} } );
			if ( ! init.ok || ! init.data?.migration_id ) {
				setBusy( false );
				setStatus( init.data?.error || init.error || 'Could not start migration.' );
				return;
			}
			mid = init.data.migration_id;
			setMigrationId( mid );
		}
		const components = api.cfg.exportComponents || [];
		const queued = await api.call( 'export/queue', {
			method: 'POST',
			body: { migration_id: mid, components },
		} );
		setBusy( false );
		if ( queued.ok && queued.data?.success ) {
			setStatus( 'Export running — keep this tab open…' );
			startPoll( mid );
		} else {
			setStatus( queued.data?.error || queued.error || 'Export queue failed.' );
		}
	};

	const pct = progress?.overall_percent ?? 0;

	return (
		<Card className="te-glass-card te-migrate__card">
			<CardHeader>
				<h2>{ window.teMigrate?.strings?.exportTitle || 'Export this site' }</h2>
			</CardHeader>
			<CardBody>
				{ migrationId && (
					<Notice status="info" isDismissible={ false }>
						Migration ID: <code>{ migrationId }</code>
					</Notice>
				) }
				<p className="te-migrate__intro">
					{ window.teMigrate?.strings?.exportIntro ||
						'Build checksum-verified packages on the server. Large sites run in the background.' }
				</p>
				<div className="te-migrate__actions">
					<Button variant="primary" onClick={ startExport } disabled={ busy } isBusy={ busy }>
						{ migrationId
							? ( window.teMigrate?.strings?.resumeExport || 'Resume / run export' )
							: ( window.teMigrate?.strings?.startExport || 'Start export' ) }
					</Button>
				</div>
				{ status && <StatusBox type="info">{ status }{ ( busy || driving ) && <Spinner /> }</StatusBox> }
				{ pct > 0 && (
					<div className="te-migrate__progress-bar">
						<div className="te-migrate__progress-bar-fill" style={ { width: pct + '%' } } />
					</div>
				) }
			</CardBody>
		</Card>
	);
}

function ExportTransferStep( { api, migrationId, onDone } ) {
	const [ busy, setBusy ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ pushDone, setPushDone ] = useState( false );
	const [ progress, setProgress ] = useState( null );
	const [ pulseKey, setPulseKey ] = useState( 0 );
	const [ polling, setPolling ] = useState( false );
	const [ tabHidden, setTabHidden ] = useState( false );
	const started = useRef( false );
	const lastSent = useRef( 0 );

	useEffect( () => {
		const onVisibility = () => setTabHidden( document.hidden );
		document.addEventListener( 'visibilitychange', onVisibility );
		return () => document.removeEventListener( 'visibilitychange', onVisibility );
	}, [] );

	const handleProgress = useCallback( ( d ) => {
		if ( ! d ) {
			return true;
		}
		setProgress( d );
		const sent = d.push?.sent || 0;
		if ( sent > lastSent.current ) {
			setPulseKey( ( k ) => k + 1 );
			lastSent.current = sent;
		}
		if ( d.push?.done || d.phase === 'done' ) {
			setPushDone( true );
			setStatus( d.current_action || `All ${ d.push?.total || 0 } files sent.` );
			return false;
		}
		if ( d.push?.retrying ) {
			setStatus( d.current_action || ( d.push?.last_error ? d.push.last_error + ' — retrying…' : 'Retrying…' ) );
			return true;
		}
		if ( d.push?.failed ) {
			setStatus( d.current_action || 'Push failed — worker will retry.' );
			return true;
		}
		if ( d.push?.active || d.push?.worker_active || d.push?.total > 0 ) {
			setStatus( d.current_action || `Sending… ${ sent } / ${ d.push?.total || 0 }` );
		}
		return true;
	}, [] );

	const pollPush = useCallback( async () => {
		if ( ! migrationId ) {
			return;
		}
		const res = await api.call( 'migration/' + migrationId + '/push-progress' );
		if ( res.ok && res.data ) {
			const keep = handleProgress( res.data );
			if ( ! keep ) {
				setPolling( false );
			}
		}
	}, [ api, migrationId, handleProgress ] );

	usePollChain( pollPush, polling && ! pushDone );

	useEffect( () => {
		if ( ! polling || pushDone || ! migrationId ) {
			return undefined;
		}
		const nudgeMs = tabHidden ? TRANSFER_NUDGE_HIDDEN_MS : TRANSFER_NUDGE_MS;
		const nudge = setInterval( async () => {
			await api.call( 'transfer/drive', {
				method: 'POST',
				body: { migration_id: migrationId },
			} );
		}, nudgeMs );
		return () => clearInterval( nudge );
	}, [ api, migrationId, polling, pushDone, tabHidden ] );

	const beginPush = useCallback( async () => {
		if ( ! migrationId ) {
			setStatus( 'No migration ID — complete export first.' );
			return;
		}
		setBusy( true );
		setStatus( 'Starting site-to-site transfer…' );
		const res = await api.call( 'transfer/push', {
			method: 'POST',
			body: { migration_id: migrationId },
		} );
		setBusy( false );
		if ( res.ok && res.data?.success ) {
			if ( res.data.progress ) {
				handleProgress( res.data.progress );
				if ( res.data.progress.push?.done || res.data.progress.phase === 'done' ) {
					setPushDone( true );
					return;
				}
			}
			if ( res.data.local_copy ) {
				setStatus( 'Copied via shared filesystem — no network transfer needed.' );
			}
			setPolling( true );
		} else {
			setStatus( res.data?.error || res.error || 'Push failed to start.' );
		}
	}, [ api, migrationId, handleProgress ] );

	useEffect( () => {
		if ( ! migrationId || started.current ) {
			return;
		}
		started.current = true;
		beginPush();
	}, [ migrationId, beginPush ] );

	const isAuto = api.cfg.remoteAutoPush;
	const pct = progress?.overall_percent ?? 0;
	const push = progress?.push || {};
	const hbOffset = push.heartbeat?.offset || 0;
	const bytesDone = ( push.bytes_sent || 0 ) + ( hbOffset > 0 ? hbOffset : 0 );
	const fileRate = useRollingRate( push.sent || 0 );
	const byteRate = useRollingRate( bytesDone );
	const eta = fileRate > 0 && push.total > push.sent ? ( push.total - push.sent ) / fileRate : 0;
	const cliHint = window.teMigrate?.strings?.cliDriveHint ||
		'Tip: run in terminal — studio wp the-exporter transfer worker --migration-id=' + ( migrationId || '…' );

	return (
		<Card className="te-glass-card te-migrate__card">
			<CardHeader><h2>{ window.teMigrate?.strings?.transferTitle || 'Send to import site' }</h2></CardHeader>
			<CardBody>
				<Notice status="warning" isDismissible={ false } className="te-migrate__notice">
					{ window.teMigrate?.strings?.keepTabOpen ||
						'Keep this browser tab open and in the foreground while sending. Closing or backgrounding it will pause the transfer.' }
				</Notice>
				{ tabHidden && (
					<Notice status="warning" isDismissible={ false } className="te-migrate__notice">
						{ window.teMigrate?.strings?.tabBackgrounded ||
							'This tab is in the background — transfer may be throttled. Bring it to the front.' }
					</Notice>
				) }
				{ isAuto && (
					<Notice status="info" isDismissible={ false }>
						{ window.teMigrate?.strings?.autoPushOn ||
							'Sending packages to the import site automatically. Keep this tab open.' }
					</Notice>
				) }
				{ ! isAuto && (
					<p className="te-migrate__intro">
						{ window.teMigrate?.strings?.transferIntro ||
							'Push packages to the connected import site over HTTP.' }
					</p>
				) }
				<div className="te-migrate__actions">
					{ ! isAuto && (
						<Button variant="primary" onClick={ beginPush } disabled={ busy } isBusy={ busy }>
							{ window.teMigrate?.strings?.sendNow || 'Send to import site' }
						</Button>
					) }
					<Button variant="secondary" onClick={ beginPush } disabled={ busy }>
						{ window.teMigrate?.strings?.retryPush || 'Retry send' }
					</Button>
					<Button variant="primary" onClick={ onDone } disabled={ ! pushDone }>
						{ window.teMigrate?.strings?.continue || 'Continue' }
					</Button>
				</div>
				<StatusBox type={ pushDone ? 'success' : 'info' }>{ status }{ busy && <Spinner /> }</StatusBox>
				{ polling && ! pushDone && (
					<p className="te-text-muted te-migrate__cli-hint">{ cliHint }</p>
				) }
				<TransferProgressPanel
					pct={ pct }
					action=""
					stats={
						<TransferStats
							rate={ fileRate }
							byteRate={ byteRate }
							etaSeconds={ eta }
							bytesDone={ bytesDone }
							bytesTotal={ push.bytes_total }
							filesDone={ push.sent }
							filesTotal={ push.total }
							inflight={ push.heartbeat?.current_file ? { bytes_done: hbOffset, bytes_total: push.heartbeat.current_file.size } : null }
						/>
					}
					components={ progress?.components }
					recent={ progress?.recent }
					pulseKey={ pulseKey }
				/>
			</CardBody>
		</Card>
	);
}

function ImportPairStep( { api } ) {
	const [ busy, setBusy ] = useState( false );
	const [ code, setCode ] = useState( '' );
	const [ siteUrl, setSiteUrl ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const generate = async () => {
		setBusy( true );
		setError( '' );
		const res = await api.call( 'pairing/generate', { method: 'POST', body: {} } );
		setBusy( false );
		if ( res.ok && res.data?.token ) {
			setCode( res.data.token );
			setSiteUrl( res.data.site_url || window.location.origin );
		} else {
			setError( res.data?.error || res.error || 'Could not generate pairing code.' );
		}
	};

	return (
		<Card className="te-glass-card te-migrate__card">
			<CardHeader><h2>{ window.teMigrate?.strings?.pairTitle || 'Pair with export site' }</h2></CardHeader>
			<CardBody>
				<p className="te-migrate__intro">
					{ window.teMigrate?.strings?.pairIntro ||
						'Generate a pairing code and paste it on the export site.' }
				</p>
				<div className="te-migrate__actions">
					<Button variant="primary" onClick={ generate } disabled={ busy } isBusy={ busy }>
						{ window.teMigrate?.strings?.generateCode || 'Generate pairing code' }
					</Button>
				</div>
				{ error && <StatusBox type="error">{ error }</StatusBox> }
				{ code && (
					<>
						<p>{ window.teMigrate?.strings?.copyCode || 'Copy this code to the export site:' }</p>
						<code className="te-migrate__code">{ code }</code>
						<p className="te-text-muted">
							{ window.teMigrate?.strings?.thisSiteUrl || 'This site URL:' }{ ' ' }
							<code>{ siteUrl }</code>
						</p>
					</>
				) }
			</CardBody>
		</Card>
	);
}

function ImportReceiveStep( { api, migrationId, setMigrationId, onReady } ) {
	const [ inputId, setInputId ] = useState( migrationId || '' );
	const [ status, setStatus ] = useState( '' );
	const [ progress, setProgress ] = useState( null );
	const [ pulseKey, setPulseKey ] = useState( 0 );
	const [ watching, setWatching ] = useState( false );
	const lastUploaded = useRef( 0 );

	const check = useCallback( async () => {
		const id = inputId.trim();
		if ( ! id ) {
			return false;
		}
		const res = await api.call( 'migration/' + id + '/receive-progress' );
		if ( res.ok && res.data ) {
			const d = res.data;
			setProgress( d );
			// #region agent log
			fetch( 'http://127.0.0.1:7645/ingest/2f3eba9c-d9e1-42a2-87c1-0349faa0f8ee', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Debug-Session-Id': '303160' }, body: JSON.stringify( { sessionId: '303160', location: 'index.js:ImportReceiveStep.check', message: 'receive_progress', hypothesisId: 'H1-H5', timestamp: Date.now(), data: { stale: d.stale, stall_diag: d.stall_diag, push_sent: d.push_state?.sent, push_active: d.push_state?.active, worker_last_at: d.push_state?.worker_last_at, inflight_path: d.inflight?.path } } ) } ).catch( () => {} );
			// #endregion
			const uploaded = d.upload?.uploaded || 0;
			const expected = d.upload?.expected || 0;
			if ( uploaded > lastUploaded.current ) {
				setPulseKey( ( k ) => k + 1 );
				lastUploaded.current = uploaded;
			}
			if ( d.needs_manifest || expected <= 0 ) {
				setStatus(
					window.teMigrate?.strings?.waitingSend ||
						'Waiting for export site to send files… Complete Send on the export site and keep both tabs open.'
				);
			} else if ( d.push_state?.error && ( d.stale || d.current_action?.includes( 'failed' ) ) ) {
				setStatus( d.current_action || ( 'Export push failed: ' + d.push_state.error ) );
			} else {
				setStatus( d.current_action || `Files received: ${ uploaded } / ${ expected }` );
			}
			if ( d.upload?.ready ) {
				setMigrationId( id );
				onReady();
				return false;
			}
			return true;
		}
		return true;
	}, [ api, inputId, setMigrationId, onReady ] );

	const pollReceive = useCallback( async () => {
		const keep = await check();
		if ( ! keep ) {
			setWatching( false );
		}
	}, [ check ] );

	usePollChain( pollReceive, watching );

	const startWatch = useCallback( () => {
		const id = inputId.trim();
		if ( ! id ) {
			return;
		}
		setMigrationId( id );
		lastUploaded.current = 0;
		setWatching( true );
	}, [ inputId, setMigrationId ] );

	useEffect( () => {
		if ( migrationId ) {
			setInputId( migrationId );
			setWatching( true );
		}
	}, [ migrationId ] );

	const upload = progress?.upload || {};
	const inflight = progress?.inflight;
	const bytesDone = inflight?.bytes_done ?? upload.bytes_done ?? 0;
	const fileRate = useRollingRate( upload.uploaded || 0 );
	const byteRate = useRollingRate( bytesDone );
	const eta = ( ! inflight && fileRate > 0 && upload.expected > upload.uploaded )
		? ( upload.expected - upload.uploaded ) / fileRate
		: 0;
	const postMax = progress?.php_limits?.post_max_size || 0;
	const phpLimitLow = postMax > 0 && postMax < 12582912;
	const pushState = progress?.push_state;
	const pushError = pushState?.error;
	const pushInfo = pushState && ( pushState.retrying || pushState.current_file || ( pushState.sent != null && pushState.total ) );

	return (
		<Card className="te-glass-card te-migrate__card">
			<CardHeader><h2>{ window.teMigrate?.strings?.receiveTitle || 'Receive packages' }</h2></CardHeader>
			<CardBody>
				<p className="te-migrate__intro">
					{ window.teMigrate?.strings?.receiveIntro ||
						'Enter the Migration ID from the export site and wait for files to arrive.' }
				</p>
				{ phpLimitLow && (
					<Notice status="warning" isDismissible={ false } className="te-migrate__notice">
						{ window.teMigrate?.strings?.phpLimitLow ||
							'This host PHP upload limit is very low. Connected transfer may stall on large files. Ask your host to raise post_max_size to 128M, or use SFTP transfer mode.' }
					</Notice>
				) }
				{ pushError && (
					<Notice status="error" isDismissible={ false } className="te-migrate__notice">
						{ pushState.error }
					</Notice>
				) }
				{ pushInfo && ! pushError && (
					<Notice status="info" isDismissible={ false } className="te-migrate__notice">
						{ pushState.retrying ? ( window.teMigrate?.strings?.exportRetrying || 'Export is retrying…' ) + ' ' : '' }
						{ pushState.sent != null && pushState.total ? `${ pushState.sent } / ${ pushState.total } files from export` : ( pushState.sent != null ? `${ pushState.sent } files sent from export` : '' ) }
						{ formatPushCurrentFile( pushState.current_file ) ? ` — ${ formatPushCurrentFile( pushState.current_file ) }` : '' }
					</Notice>
				) }
				{ progress?.stale && progress?.stall_diag && (
					<Notice status="warning" isDismissible={ false } className="te-migrate__notice">
						<strong>{ formatStallLikely( progress.stall_diag ) }</strong>
						{ progress.stall_diag.inflight_path ? ` Partial file: ${ progress.stall_diag.inflight_path }.` : '' }
						{ progress.stall_diag.last_received_age_s != null ? ` Last completed file: ${ progress.stall_diag.last_received_age_s }s ago.` : '' }
					</Notice>
				) }
				<TextControl
					label={ window.teMigrate?.strings?.migrationId || 'Migration ID' }
					value={ inputId }
					onChange={ setInputId }
				/>
				<div className="te-migrate__actions">
					<Button variant="primary" onClick={ startWatch } disabled={ ! inputId.trim() }>
						{ window.teMigrate?.strings?.watchFiles || 'Watch for incoming files' }
					</Button>
				</div>
				<StatusBox type={ pushError ? 'error' : ( progress?.stale ? 'warning' : 'info' ) }>{ status }</StatusBox>
				<TransferProgressPanel
					pct={ progress?.overall_percent ?? 0 }
					action=""
					stats={
						<TransferStats
							rate={ fileRate }
							byteRate={ byteRate }
							etaSeconds={ eta }
							bytesDone={ bytesDone }
							bytesTotal={ upload.bytes_total }
							filesDone={ upload.uploaded }
							filesTotal={ upload.expected }
							inflight={ inflight }
						/>
					}
					components={ progress?.components }
					recent={ progress?.recent }
					pulseKey={ pulseKey }
					stale={ progress?.stale }
					staleMessage={ progress?.stale_message }
				/>
			</CardBody>
		</Card>
	);
}

function ImportWorkStep( { api, migrationId, onDone } ) {
	const [ busy, setBusy ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ validated, setValidated ] = useState( false );

	const validate = async () => {
		if ( ! migrationId ) {
			return;
		}
		setBusy( true );
		const res = await api.call( 'import/validate', {
			method: 'POST',
			body: { migration_id: migrationId },
		} );
		setBusy( false );
		if ( res.ok ) {
			const ok = res.data?.passed === true;
			setValidated( ok );
			setStatus( ok ? 'Validation passed — ready to import.' : 'Validation found issues. Check Activity log.' );
		} else {
			setStatus( res.data?.error || res.error || 'Validation failed.' );
		}
	};

	const runImport = async () => {
		setBusy( true );
		const res = await api.call( 'import/all', {
			method: 'POST',
			body: { migration_id: migrationId, confirm: true },
		} );
		setBusy( false );
		if ( res.ok && res.data?.success ) {
			setStatus( 'Import complete.' );
			onDone();
		} else {
			setStatus( res.data?.error || res.error || 'Import failed.' );
		}
	};

	return (
		<Card className="te-glass-card te-migrate__card">
			<CardHeader><h2>{ window.teMigrate?.strings?.importTitle || 'Import migration' }</h2></CardHeader>
			<CardBody>
				{ migrationId && (
					<Notice status="info" isDismissible={ false }>
						Migration ID: <code>{ migrationId }</code>
					</Notice>
				) }
				<div className="te-migrate__actions">
					<Button variant="secondary" onClick={ validate } disabled={ busy || ! migrationId } isBusy={ busy }>
						{ window.teMigrate?.strings?.validate || 'Validate packages' }
					</Button>
					<Button variant="primary" onClick={ runImport } disabled={ busy || ! validated } isBusy={ busy }>
						{ window.teMigrate?.strings?.runImport || 'Run import' }
					</Button>
				</div>
				<StatusBox type={ validated ? 'success' : 'info' }>{ status }</StatusBox>
			</CardBody>
		</Card>
	);
}

function DoneStep( { role, migrationId } ) {
	const [ copied, setCopied ] = useState( false );

	const copyId = async () => {
		if ( ! migrationId ) {
			return;
		}
		await copyText( migrationId );
		setCopied( true );
		setTimeout( () => setCopied( false ), 2000 );
	};

	return (
		<Card className="te-glass-card te-migrate__card">
			<CardHeader><h2>{ window.teMigrate?.strings?.doneTitle || 'Migration step complete' }</h2></CardHeader>
			<CardBody>
				<Notice status="success" isDismissible={ false }>
					{ role === 'export'
						? ( window.teMigrate?.strings?.doneExport ||
							'Export sent. On the import site, enter the Migration ID and finish import.' )
						: ( window.teMigrate?.strings?.doneImport ||
							'Import finished. Review your site and clear caches if needed.' ) }
				</Notice>
				{ migrationId && (
					<p className="te-mt-md">
						<strong>Migration ID:</strong> <code>{ migrationId }</code>
					</p>
				) }
				<div className="te-migrate__actions">
					{ migrationId && (
						<Button variant="secondary" onClick={ copyId }>
							{ copied
								? ( window.teMigrate?.strings?.copied || 'Copied!' )
								: ( window.teMigrate?.strings?.copyId || 'Copy Migration ID' ) }
						</Button>
					) }
					<Button variant="secondary" href={ window.teMigrate?.activityUrl }>
						{ window.teMigrate?.strings?.viewActivity || 'View Activity log' }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

function MigrateApp() {
	const api = useApi();
	const [ role, setRole ] = useState( api.cfg.siteRole || '' );
	const [ step, setStep ] = useState( 1 );
	const [ migrationId, setMigrationId ] = useState( api.cfg.migrationId || '' );
	const [ roleBusy, setRoleBusy ] = useState( false );
	const [ roleError, setRoleError ] = useState( '' );
	const [ pendingRole, setPendingRole ] = useState( '' );

	const exportSteps = [ 'Connect', 'Export', 'Send', 'Done' ];
	const importSteps = [ 'Pair', 'Receive', 'Import', 'Done' ];
	const steps = role === 'import' ? importSteps : exportSteps;

	const pickRole = async ( r ) => {
		setRoleError( '' );
		setPendingRole( r );
		setRoleBusy( true );
		// Advance immediately so the wizard always responds to clicks.
		setRole( r );
		setStep( 1 );
		const res = await api.call( 'wizard/role', { method: 'POST', body: { role: r } } );
		setRoleBusy( false );
		setPendingRole( '' );
		if ( ! res.ok ) {
			setRoleError( res.error || res.data?.error || 'Could not save your choice. You can still continue the wizard.' );
		}
	};

	const resetRole = async () => {
		setRole( '' );
		setStep( 1 );
		setRoleError( '' );
		await api.call( 'wizard/role', { method: 'POST', body: { role: '' } } ).catch( () => {} );
	};

	const next = () => setStep( ( s ) => Math.min( s + 1, steps.length ) );

	if ( ! role ) {
		return (
			<div className="te-migrate">
				<RolePicker
					onSelect={ pickRole }
					busy={ roleBusy }
					error={ roleError }
					pendingRole={ pendingRole }
				/>
			</div>
		);
	}

	return (
		<div className="te-migrate">
			<div className="te-migrate__toolbar">
				<Stepper steps={ steps } current={ step } />
				<Button variant="link" onClick={ resetRole } className="te-migrate__change-role">
					{ window.teMigrate?.strings?.changeRole || 'Change site role' }
				</Button>
			</div>
			{ roleError && (
				<Notice status="warning" isDismissible={ false } className="te-migrate__notice">
					{ roleError }
				</Notice>
			) }
			{ role === 'export' && step === 1 && (
				<ExportConnectStep api={ api } onConnected={ next } />
			) }
			{ role === 'export' && step === 2 && (
				<ExportWorkStep
					api={ api }
					migrationId={ migrationId }
					setMigrationId={ setMigrationId }
					onDone={ next }
				/>
			) }
			{ role === 'export' && step === 3 && (
				<ExportTransferStep api={ api } migrationId={ migrationId } onDone={ next } />
			) }
			{ role === 'export' && step === 4 && (
				<DoneStep role="export" migrationId={ migrationId } />
			) }
			{ role === 'import' && step === 1 && (
				<>
					<ImportPairStep api={ api } />
					<div className="te-migrate__actions te-mt-md">
						<Button variant="primary" onClick={ next }>
							{ window.teMigrate?.strings?.continue || 'Continue' }
						</Button>
					</div>
				</>
			) }
			{ role === 'import' && step === 2 && (
				<ImportReceiveStep
					api={ api }
					migrationId={ migrationId }
					setMigrationId={ setMigrationId }
					onReady={ next }
				/>
			) }
			{ role === 'import' && step === 3 && (
				<ImportWorkStep api={ api } migrationId={ migrationId } onDone={ next } />
			) }
			{ role === 'import' && step === 4 && (
				<DoneStep role="import" migrationId={ migrationId } />
			) }
		</div>
	);
}

function boot() {
	const el = document.getElementById( ROOT );
	if ( ! el ) {
		return;
	}
	const cfg = getConfig();
	if ( ! cfg.root || ! cfg.nonce ) {
		el.innerHTML = '<div class="te-migrate__status te-migrate__status--error">Migration wizard failed to load. Refresh the page or check that build assets are present.</div>';
		return;
	}
	render( <MigrateApp />, el );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
