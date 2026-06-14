( function () {
	'use strict';

	const bindTransferGuideTabs = () => {
		const guide = document.getElementById( 'te-transfer-guide' );
		if ( ! guide ) return;

		guide.querySelectorAll( '.te-transfer-guide__tab' ).forEach( ( tab ) => {
			tab.addEventListener( 'click', () => {
				const name = tab.dataset.tab;
				guide.querySelectorAll( '.te-transfer-guide__tab' ).forEach( ( t ) => {
					t.classList.toggle( 'te-transfer-guide__tab--active', t.dataset.tab === name );
				} );
				guide.querySelectorAll( '.te-transfer-guide__panel' ).forEach( ( panel ) => {
					const show = panel.dataset.panel === name;
					panel.hidden = ! show;
					panel.classList.toggle( 'te-transfer-guide__panel--active', show );
				} );
			} );
		} );
	};

	const toggleConnectedSettings = () => {
		const mode = document.getElementById( 'te_transfer_mode' );
		const box = document.getElementById( 'te-connected-settings' );
		if ( ! mode || ! box ) return;
		const show = mode.value === 'connected';
		box.classList.toggle( 'te-connected-settings--hidden', ! show );
		mode.addEventListener( 'change', () => {
			box.classList.toggle( 'te-connected-settings--hidden', mode.value !== 'connected' );
		} );
	};

	const testConnection = async () => {
		const btn = document.getElementById( 'te-test-connection' );
		const out = document.getElementById( 'te-connection-result' );
		if ( ! btn || ! out || ! window.teUI ) return;

		btn.disabled = true;
		out.textContent = 'Testing…';
		const result = await teUI.api( 'pairing/test', {
			method: 'POST',
			body: JSON.stringify( {
				remote_site_url: document.getElementById( 'te_remote_site_url' )?.value || '',
				token: document.getElementById( 'te_remote_pairing_token' )?.value || '',
			} ),
			source: 'Settings',
		} );
		btn.disabled = false;
		if ( result.ok && result.data?.success ) {
			out.textContent = 'Connected to ' + ( result.data.site_url || 'import site' );
			out.className = 'te-text-muted te-mt-sm';
		} else {
			out.textContent = result.data?.error || result.error || 'Connection failed';
			out.className = 'te-text-warning te-mt-sm';
		}
	};

	const generatePairing = async () => {
		const btn = document.getElementById( 'te-generate-pairing' );
		const out = document.getElementById( 'te-pairing-output' );
		if ( ! btn || ! out || ! window.teUI ) return;

		btn.disabled = true;
		const result = await teUI.api( 'pairing/generate', { method: 'POST', source: 'Settings' } );
		btn.disabled = false;
		if ( result.ok && result.data?.token ) {
			out.hidden = false;
			document.getElementById( 'te-pairing-code' ).textContent = result.data.token;
			document.getElementById( 'te-pairing-site-url' ).textContent = result.data.site_url || window.location.origin;
		} else {
			alert( result.data?.error || result.error || 'Could not generate pairing code' );
		}
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		bindTransferGuideTabs();
		toggleConnectedSettings();
		document.getElementById( 'te-test-connection' )?.addEventListener( 'click', testConnection );
		document.getElementById( 'te-generate-pairing' )?.addEventListener( 'click', generatePairing );
	} );

	// Export page also uses guide tabs.
	if ( document.readyState !== 'loading' ) {
		bindTransferGuideTabs();
	}
} )();
