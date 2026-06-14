/**
 * Live migration progress — poller + pipeline renderer.
 */
( function () {
	'use strict';

	const POLL_MS = 2000;
	const STALE_MS = 30000;

	const escapeHtml = ( value ) => String( value )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );

	const statusClass = ( status ) => {
		if ( status === 'completed' ) return 'te-pipeline-node--done';
		if ( status === 'running' ) return 'te-pipeline-node--active';
		if ( status === 'failed' ) return 'te-pipeline-node--error';
		return 'te-pipeline-node--pending';
	};

	const ringSvg = ( percent ) => {
		const p = Math.max( 0, Math.min( 100, percent ) );
		const dash = ( p / 100 ) * 283;
		return '<svg class="te-progress-ring" viewBox="0 0 100 100" aria-hidden="true">' +
			'<circle class="te-progress-ring__bg" cx="50" cy="50" r="45"/>' +
			'<circle class="te-progress-ring__fill" cx="50" cy="50" r="45" style="stroke-dasharray:' + dash + ' 283"/>' +
			'<text x="50" y="54" class="te-progress-ring__text">' + Math.round( p ) + '%</text></svg>';
	};

	const renderPipeline = ( container, snapshot ) => {
		if ( ! container || ! snapshot ) {
			return;
		}

		const components = snapshot.components || [];
		const percent = snapshot.overall_percent || 0;
		const action = snapshot.current_action || '';
		const stale = snapshot._stale;

		let nodes = '';
		components.forEach( ( c, i ) => {
			let sub = '';
			if ( c.chunks_total > 0 && c.status === 'running' ) {
				sub = '<div class="te-pipeline-node__bar"><span style="width:' + Math.min( 100, Math.round( ( c.chunks_done / Math.max( 1, c.chunks_total ) ) * 100 ) ) + '%"></span></div>';
				if ( c.files_total ) {
					sub += '<div class="te-pipeline-node__meta">' + escapeHtml( ( c.files_packed || 0 ) + ' / ' + c.files_total + ' files' ) + '</div>';
				}
			} else if ( c.files_scanned && c.status === 'running' ) {
				sub = '<div class="te-pipeline-node__meta">' + escapeHtml( String( c.files_scanned ) ) + ' files</div>';
			} else if ( c.chunks_total > 0 && c.status === 'completed' ) {
				sub = '<div class="te-pipeline-node__meta">' + escapeHtml( c.chunks_total + ' segments' ) + '</div>';
			}
			nodes += '<div class="te-pipeline-node ' + statusClass( c.status ) + '" data-component="' + escapeHtml( c.name ) + '">' +
				'<div class="te-pipeline-node__hex"><span class="te-pipeline-node__label">' + escapeHtml( c.name ) + '</span></div>' +
				sub +
				( i < components.length - 1 ? '<div class="te-pipeline-connector' + ( c.status === 'running' ? ' te-pipeline-connector--flow' : '' ) + '"></div>' : '' ) +
				'</div>';
		} );

		container.innerHTML =
			'<div class="te-pipeline-dashboard' + ( stale ? ' te-pipeline-dashboard--breathing' : '' ) + '">' +
			'<div class="te-pipeline-ring-wrap">' + ringSvg( percent ) + '</div>' +
			'<div class="te-pipeline-stats">' +
			'<p class="te-pipeline-action">' + escapeHtml( action ) + '</p>' +
			( stale ? '<p class="te-pipeline-heartbeat">Packing large themes can take 30–60 minutes. Progress updates every few seconds — keep this tab open.</p>' : '' ) +
			( snapshot.warnings?.length ? '<p class="te-pipeline-warn">' + escapeHtml( snapshot.warnings[0] ) + '</p>' : '' ) +
			'</div>' +
			'<div class="te-pipeline-track">' + nodes + '</div>' +
			'</div>';
	};

	const renderWizardSteps = ( container, steps, current ) => {
		if ( ! container ) return;
		let html = '<div class="te-wizard te-wizard--pipeline">';
		steps.forEach( ( label, i ) => {
			const num = i + 1;
			let cls = '';
			if ( num === current ) cls = ' te-wizard__step--active';
			else if ( num < current ) cls = ' te-wizard__step--done';
			html += '<div class="te-wizard__step' + cls + '"><span class="te-wizard__num">' + num + '</span><span class="te-wizard__label">' + escapeHtml( label ) + '</span></div>';
		} );
		html += '</div>';
		container.innerHTML = html;
	};

	class ProgressPoller {
		constructor( migrationId, context ) {
			this.migrationId = migrationId;
			this.context = context || 'auto';
			this.timer = null;
			this.lastHeartbeat = Date.now();
			this.lastSnapshot = null;
			this.listeners = [];
		}

		onUpdate( fn ) {
			this.listeners.push( fn );
			return () => {
				this.listeners = this.listeners.filter( ( f ) => f !== fn );
			};
		}

		async tick() {
			if ( ! this.migrationId || typeof teUI === 'undefined' ) return;
			const result = await teUI.api(
				'migration/' + this.migrationId + '/progress?context=' + encodeURIComponent( this.context ),
				{ source: 'Progress', silent: true }
			);
			if ( ! result.ok ) return;
			const snap = result.data;
			const hb = snap.heartbeat_at ? new Date( snap.heartbeat_at.replace( ' ', 'T' ) + 'Z' ).getTime() : Date.now();
			if ( ! isNaN( hb ) ) {
				this.lastHeartbeat = hb;
			}
			snap._stale = ( Date.now() - this.lastHeartbeat ) > STALE_MS;
			this.lastSnapshot = snap;
			this.listeners.forEach( ( fn ) => fn( snap ) );
		}

		start() {
			this.stop();
			this.tick();
			this.timer = setInterval( () => this.tick(), POLL_MS );
		}

		stop() {
			if ( this.timer ) {
				clearInterval( this.timer );
				this.timer = null;
			}
		}
	}

	window.teProgress = {
		POLL_MS,
		STALE_MS,
		ProgressPoller,
		renderPipeline,
		renderWizardSteps,
		ringSvg,
	};
} )();
