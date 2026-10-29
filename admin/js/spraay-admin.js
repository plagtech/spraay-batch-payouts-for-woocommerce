/**
 * Spraay Batch Payouts admin UI.
 *
 * - Confirmation prompts for destructive links on every plugin page.
 * - The batch builder (build -> review & approve -> sign & submit) when
 *   #spraay-builder is present.
 *
 * Requests go through wp.apiFetch, which attaches the wp_rest nonce.
 *
 * @package Spraay_Batch_Payouts
 */
( function ( window, document ) {
	'use strict';

	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) {
		return s;
	};
	var sprintf = window.wp && window.wp.i18n ? window.wp.i18n.sprintf : function ( s ) {
		return s;
	};
	var DOMAIN = 'spraay-batch-payouts';

	// Confirm destructive row actions.
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( '.spraay-confirm' );
		if ( link && ! window.confirm( link.getAttribute( 'data-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	var root = document.getElementById( 'spraay-builder' );
	if ( ! root || ! window.spraayConfig || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var config = window.spraayConfig;
	var apiFetch = window.wp.apiFetch;
	var Wallet = window.SpraayWallet;
	var DECIMALS = config.token.decimals;
	var POLL_INTERVAL = 6000;
	var POLL_LIMIT = 100; // ~10 minutes.

	var state = {
		batchId: config.batchId || 0,
		rows: [],
		estimate: null,
		estimateSender: '',
		account: '',
		busy: false,
	};

	/* ------------------------------------------------------------------ */
	/* DOM helpers                                                          */
	/* ------------------------------------------------------------------ */

	function $( name ) {
		return root.querySelector( '[data-spraay="' + name + '"]' );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else if ( key === 'className' ) {
				node.className = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );
		return node;
	}

	function notify( type, message, details ) {
		var area = $( 'notices' );
		var notice = el( 'div', { className: 'notice notice-' + type + ' inline' }, [ el( 'p', { text: message } ) ] );
		if ( details && details.length ) {
			var list = el( 'ul', { className: 'spraay-notice-details' } );
			details.forEach( function ( line ) {
				list.appendChild( el( 'li', { text: line } ) );
			} );
			notice.appendChild( list );
		}
		area.textContent = '';
		area.appendChild( notice );
		area.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function clearNotices() {
		$( 'notices' ).textContent = '';
	}

	function showStep( step ) {
		root.querySelectorAll( '[data-spraay-step]' ).forEach( function ( section ) {
			section.hidden = section.getAttribute( 'data-spraay-step' ) !== step;
		} );
		root.querySelectorAll( '[data-step]' ).forEach( function ( item ) {
			item.classList.toggle( 'is-active', item.getAttribute( 'data-step' ) === step );
		} );
	}

	function setBusy( busy ) {
		state.busy = busy;
		root.classList.toggle( 'is-busy', busy );
		root.querySelectorAll( 'button' ).forEach( function ( button ) {
			if ( busy ) {
				button.dataset.wasDisabled = button.disabled ? '1' : '';
				button.disabled = true;
			} else {
				button.disabled = button.dataset.wasDisabled === '1';
			}
		} );
		if ( ! busy ) {
			updateSignButton();
		}
	}

	function shortAddress( address ) {
		return address ? address.slice( 0, 6 ) + '…' + address.slice( -4 ) : '';
	}

	function errorMessage( err ) {
		if ( ! err ) {
			return __( 'Something went wrong.', DOMAIN );
		}
		var byCode = {
			rejected: __( 'You rejected the request in your wallet. Nothing was sent.', DOMAIN ),
			no_wallet: __( 'No browser wallet detected. Install MetaMask, Coinbase Wallet or another EVM wallet and reload.', DOMAIN ),
			no_ethers: __( 'The wallet library (ethers.js) failed to load. Check your connection or content blockers and reload.', DOMAIN ),
			wrong_chain: sprintf( __( 'Switch your wallet to %s and try again.', DOMAIN ), config.chain.name ),
			insufficient_gas: __( 'Your wallet does not have enough ETH on Base to pay network gas.', DOMAIN ),
		};
		if ( err.spraayCode && byCode[ err.spraayCode ] ) {
			return byCode[ err.spraayCode ];
		}
		return err.message || __( 'Something went wrong.', DOMAIN );
	}

	/* ------------------------------------------------------------------ */
	/* Amounts                                                              */
	/* ------------------------------------------------------------------ */

	function toUnits( amount ) {
		var value = String( amount || '' ).replace( /[,\s]/g, '' );
		if ( ! /^\d+(\.\d{0,6})?$/.test( value ) ) {
			return null;
		}
		return Wallet.parseUnits( value, DECIMALS );
	}

	function formatUnits( units ) {
		var parts = Wallet.formatUnits( units, DECIMALS ).split( '.' );
		var whole = Number( parts[ 0 ] ).toLocaleString();
		var fraction = ( parts[ 1 ] || '' ).replace( /0+$/, '' );
		while ( fraction.length < 2 ) {
			fraction += '0';
		}
		return whole + '.' + fraction;
	}

	function feeUnits( units ) {
		return ( units * BigInt( config.feeBps ) + 9999n ) / 10000n;
	}

	/* ------------------------------------------------------------------ */
	/* Build step                                                           */
	/* ------------------------------------------------------------------ */

	function findRow( recipientId ) {
		for ( var i = 0; i < state.rows.length; i++ ) {
			if ( state.rows[ i ].recipient_id === recipientId ) {
				return state.rows[ i ];
			}
		}
		return null;
	}

	/**
	 * Add a row, or merge into an existing row for the same recipient.
	 *
	 * @param {Object}  row   { recipient_id, label, wallet_address, amount, wc_order_ids }.
	 * @param {boolean} merge Sum amounts/orders into an existing row.
	 * @return {boolean} Whether the row was added or merged.
	 */
	function addRow( row, merge ) {
		var existing = findRow( row.recipient_id );
		if ( existing ) {
			if ( ! merge ) {
				notify( 'info', sprintf( __( '%s is already in this batch.', DOMAIN ), existing.label ) );
				return false;
			}
			var a = toUnits( existing.amount ) || 0n;
			var b = toUnits( row.amount ) || 0n;
			existing.amount = Wallet.formatUnits( a + b, DECIMALS );
			existing.wc_order_ids = Array.from( new Set( existing.wc_order_ids.concat( row.wc_order_ids || [] ) ) );
			return true;
		}
		if ( state.rows.length >= config.maxItems ) {
			notify( 'warning', sprintf( __( 'A batch can contain at most %d recipients.', DOMAIN ), config.maxItems ) );
			return false;
		}
		state.rows.push( {
			recipient_id: row.recipient_id,
			label: row.label,
			wallet_address: row.wallet_address,
			amount: row.amount || '',
			wc_order_ids: ( row.wc_order_ids || [] ).map( Number ),
		} );
		return true;
	}

	function renderRows() {
		var body = $( 'rows' );
		body.textContent = '';

		if ( ! state.rows.length ) {
			body.appendChild( el( 'tr', { className: 'spraay-empty' }, [ el( 'td', { colspan: '5', text: __( 'No recipients added yet.', DOMAIN ) } ) ] ) );
		}

		state.rows.forEach( function ( row, index ) {
			var input = el( 'input', {
				type: 'text',
				inputmode: 'decimal',
				className: 'spraay-amount',
				value: row.amount,
				'aria-label': sprintf( __( 'Amount for %s', DOMAIN ), row.label ),
			} );
			input.addEventListener( 'input', function () {
				row.amount = input.value.trim();
				input.classList.toggle( 'is-invalid', row.amount !== '' && ! toUnits( row.amount ) );
				renderTotals();
			} );

			var remove = el( 'button', { type: 'button', className: 'button-link spraay-remove', 'aria-label': sprintf( __( 'Remove %s', DOMAIN ), row.label ), text: '×' } );
			remove.addEventListener( 'click', function () {
				state.rows.splice( index, 1 );
				renderRows();
			} );

			body.appendChild(
				el( 'tr', {}, [
					el( 'td', { text: row.label } ),
					el( 'td', {}, [ el( 'code', { className: 'spraay-address', title: row.wallet_address, text: row.wallet_address } ) ] ),
					el( 'td', { text: row.wc_order_ids.length ? row.wc_order_ids.map( function ( id ) {
						return '#' + id;
					} ).join( ', ' ) : '—' } ),
					el( 'td', { className: 'spraay-num' }, [ input ] ),
					el( 'td', {}, [ remove ] ),
				] )
			);
		} );

		renderTotals();
	}

	function subtotalUnits() {
		return state.rows.reduce( function ( sum, row ) {
			return sum + ( toUnits( row.amount ) || 0n );
		}, 0n );
	}

	function renderTotals() {
		var subtotal = subtotalUnits();
		$( 'count' ).textContent = String( state.rows.length );
		$( 'subtotal' ).textContent = formatUnits( subtotal ) + ' ' + config.token.symbol;
		if ( $( 'fee' ) ) {
			$( 'fee' ).textContent = formatUnits( feeUnits( subtotal ) ) + ' ' + config.token.symbol;
		}
	}

	function renderUnmatched( rows ) {
		var box = $( 'unmatched' );
		box.textContent = '';
		if ( ! rows.length ) {
			return;
		}
		var list = el( 'ul' );
		rows.forEach( function ( row ) {
			list.appendChild( el( 'li', { text: sprintf(
				/* translators: 1: payee label, 2: amount, 3: order count. */
				__( '%1$s — %2$s USDC from %3$d order(s)', DOMAIN ),
				row.label || __( '(unnamed)', DOMAIN ),
				row.amount,
				( row.wc_order_ids || [] ).length
			) } ) );
		} );
		box.appendChild( el( 'div', { className: 'notice notice-warning inline' }, [
			el( 'p', { text: __( 'These payees have no saved recipient with a wallet address, so they were not added. Create a recipient for each (link the vendor ID or use the same label), then import again:', DOMAIN ) } ),
			list,
		] ) );
	}

	/**
	 * Add obligation-shaped rows (WooCommerce or commission CSV).
	 *
	 * @param {Array} rows Obligations.
	 */
	function addObligations( rows ) {
		var added = 0;
		var unmatched = [];
		rows.forEach( function ( row ) {
			if ( row.recipient_id ) {
				if ( addRow( row, true ) ) {
					added++;
				}
			} else {
				unmatched.push( row );
			}
		} );
		renderRows();
		renderUnmatched( unmatched );
		notify( added ? 'success' : 'info', added
			? sprintf( __( 'Added obligations for %d recipient(s).', DOMAIN ), added )
			: __( 'No payable obligations found for that period.', DOMAIN ) );
	}

	function payloadItems() {
		return state.rows.map( function ( row ) {
			return {
				recipient_id: row.recipient_id,
				amount: String( row.amount ),
				wc_order_ids: row.wc_order_ids,
			};
		} );
	}

	function validateRows() {
		if ( ! state.rows.length ) {
			return __( 'Add at least one recipient.', DOMAIN );
		}
		for ( var i = 0; i < state.rows.length; i++ ) {
			var units = toUnits( state.rows[ i ].amount );
			if ( ! units || units <= 0n ) {
				return sprintf( __( 'Enter a positive amount (max 6 decimals) for %s.', DOMAIN ), state.rows[ i ].label );
			}
		}
		return '';
	}

	async function saveDraft() {
		var problem = validateRows();
		if ( problem ) {
			throw new Error( problem );
		}
		var batch = await apiFetch( {
			path: config.restPath + '/batches',
			method: 'POST',
			data: { batch_id: state.batchId, items: payloadItems() },
		} );
		state.batchId = batch.id;
		state.estimate = null;
		var url = new URL( window.location.href );
		url.searchParams.set( 'batch_id', String( batch.id ) );
		window.history.replaceState( null, '', url.toString() );
		$( 'history-link' ).href = config.historyUrl + '&batch_id=' + batch.id;
		return batch;
	}

	async function requestEstimate() {
		var estimate = await apiFetch( {
			path: config.restPath + '/batches/' + state.batchId + '/estimate',
			method: 'POST',
			data: { sender: state.account || '' },
		} );
		state.estimate = estimate;
		state.estimateSender = state.account || '';
		return estimate;
	}

	/* ------------------------------------------------------------------ */
	/* Review step                                                          */
	/* ------------------------------------------------------------------ */

	function renderReview( estimate ) {
		var body = $( 'review-rows' );
		body.textContent = '';
		estimate.batch.items.forEach( function ( item ) {
			body.appendChild( el( 'tr', {}, [
				el( 'td', { text: item.label } ),
				el( 'td', {}, [ el( 'code', { className: 'spraay-address', text: item.wallet_address } ) ] ),
				el( 'td', { className: 'spraay-num', text: formatUnits( toUnits( item.amount ) ) } ),
			] ) );
		} );
		var symbol = ' ' + config.token.symbol;
		$( 'review-subtotal' ).textContent = formatUnits( toUnits( estimate.subtotal ) ) + symbol;
		$( 'review-fee' ).textContent = formatUnits( toUnits( estimate.fee ) ) + symbol;
		$( 'review-total' ).textContent = formatUnits( toUnits( estimate.total ) ) + symbol;
		$( 'review-chain' ).textContent = config.chain.name + ' (' + config.chain.id + ')';
		$( 'confirm' ).checked = false;
		updateSignButton();
	}

	function updateSignButton() {
		var sign = $( 'sign' );
		if ( sign && ! state.busy ) {
			sign.disabled = ! ( state.estimate && $( 'confirm' ).checked );
		}
	}

	async function refreshWalletStatus() {
		$( 'wallet-address' ).textContent = state.account
			? sprintf( __( 'Connected: %s', DOMAIN ), shortAddress( state.account ) )
			: __( 'Not connected', DOMAIN );
		$( 'wallet-balance' ).textContent = '';
		$( 'connect' ).textContent = state.account ? __( 'Reconnect', DOMAIN ) : __( 'Connect wallet', DOMAIN );
		if ( ! state.account || ! config.token.address ) {
			return;
		}
		try {
			var balance = await Wallet.getTokenBalance( config.token.address );
			$( 'wallet-balance' ).textContent = sprintf( __( 'Balance: %s', DOMAIN ), formatUnits( balance ) + ' ' + config.token.symbol );
		} catch ( err ) {
			// Balance is informational; the sign step re-checks it.
		}
	}

	async function connectWallet() {
		state.account = await Wallet.connect();
		await Wallet.ensureChain( config.chain );
		await refreshWalletStatus();
	}

	/* ------------------------------------------------------------------ */
	/* Sign & submit                                                        */
	/* ------------------------------------------------------------------ */

	function log( message, type ) {
		$( 'log' ).appendChild( el( 'li', { className: type ? 'is-' + type : '', text: message } ) );
	}

	function txLink( hash ) {
		var p = $( 'tx' );
		p.textContent = '';
		if ( ! hash ) {
			return;
		}
		p.appendChild( document.createTextNode( __( 'Transaction: ', DOMAIN ) ) );
		p.appendChild( el( 'a', { href: config.chain.explorer + '/tx/' + hash, target: '_blank', rel: 'noopener noreferrer' }, [ el( 'code', { text: hash } ) ] ) );
	}

	/**
	 * Refuse gateway payloads that don't match what the merchant reviewed.
	 *
	 * @param {Object} estimate Estimate.
	 */
	function assertSafeEstimate( estimate ) {
		var tx = estimate.transaction;
		if ( tx ) {
			if ( ! Wallet.isAddress( tx.to ) || ! /^0x[0-9a-fA-F]*$/.test( tx.data || '' ) ) {
				throw new Error( __( 'The gateway returned an invalid transaction. Nothing was signed.', DOMAIN ) );
			}
			if ( tx.value && BigInt( tx.value ) !== 0n ) {
				throw new Error( __( 'The gateway transaction asks for ETH, which a USDC payout should not need. Nothing was signed.', DOMAIN ) );
			}
		}
		if ( estimate.approval ) {
			if ( estimate.approval.token && ! Wallet.sameAddress( estimate.approval.token, config.token.address ) ) {
				throw new Error( __( 'The gateway asked to approve an unexpected token. Nothing was signed.', DOMAIN ) );
			}
			if ( ! Wallet.isAddress( estimate.approval.spender ) ) {
				throw new Error( __( 'The gateway returned an invalid spender address. Nothing was signed.', DOMAIN ) );
			}
		}
		if ( estimate.typed_data && estimate.typed_data.domain && estimate.typed_data.domain.chainId && Number( estimate.typed_data.domain.chainId ) !== Number( config.chain.id ) ) {
			throw new Error( __( 'The gateway signature request is for a different network. Nothing was signed.', DOMAIN ) );
		}
	}

	async function signAndSubmit() {
		if ( ! $( 'confirm' ).checked ) {
			throw new Error( __( 'Tick the confirmation box first.', DOMAIN ) );
		}
		if ( ! state.account ) {
			await connectWallet();
		}
		await Wallet.ensureChain( config.chain );
		state.account = await Wallet.getAddress();

		// The transaction is built for a specific sender; re-estimate if the wallet changed.
		var reviewedTotal = state.estimate.total;
		if ( ! Wallet.sameAddress( state.estimateSender, state.account ) ) {
			await requestEstimate();
			renderReview( state.estimate );
			if ( state.estimate.total !== reviewedTotal ) {
				throw new Error( __( 'The estimate changed for this wallet. Review the new totals and confirm again.', DOMAIN ) );
			}
			$( 'confirm' ).checked = true;
		}

		var estimate = state.estimate;
		assertSafeEstimate( estimate );

		var totalUnits = toUnits( estimate.total );
		var balance = await Wallet.getTokenBalance( config.token.address );
		if ( balance < totalUnits ) {
			throw new Error( sprintf(
				__( 'Insufficient %1$s: the batch needs %2$s but the wallet holds %3$s.', DOMAIN ),
				config.token.symbol,
				formatUnits( totalUnits ),
				formatUnits( balance )
			) );
		}

		await apiFetch( {
			path: config.restPath + '/batches/' + state.batchId + '/approve',
			method: 'POST',
			data: { confirm: true },
		} );

		showStep( 'submit' );
		$( 'log' ).textContent = '';
		$( 'tx' ).textContent = '';
		log( __( 'Batch approved.', DOMAIN ) );

		var submission = { sender: state.account };

		try {
			await obtainSignature( estimate, totalUnits, submission );
		} catch ( err ) {
			// Nothing reached the chain: let the merchant fix the problem and retry.
			showStep( 'review' );
			throw err;
		}

		var result;
		try {
			result = await submitWithRetry( submission );
		} catch ( err ) {
			if ( submission.tx_hash ) {
				// Funds may already be moving. Never invite a second payment.
				log( sprintf( __( 'Your transaction %s was broadcast, but this site could not record it (%s). Do NOT sign again. Contact support with the transaction hash.', DOMAIN ), submission.tx_hash, errorMessage( err ) ), 'error' );
				throw new Error( __( 'Transaction broadcast but not recorded. Do not resubmit.', DOMAIN ) );
			}
			showStep( 'review' );
			throw err;
		}

		if ( result.warning ) {
			log( sprintf( __( 'Gateway did not acknowledge yet (%s). The transaction is tracked and will be reconciled automatically.', DOMAIN ), result.warning ), 'warning' );
		}
		txLink( result.tx_hash );
		pollStatus( result.status );
	}

	/**
	 * Get the wallet's signature or broadcast tx hash into `submission`.
	 *
	 * @param {Object} estimate   Estimate.
	 * @param {bigint} totalUnits Total incl. fee.
	 * @param {Object} submission Mutated with signature or tx_hash.
	 */
	async function obtainSignature( estimate, totalUnits, submission ) {
		if ( estimate.typed_data ) {
			log( __( 'Waiting for your signature in the wallet…', DOMAIN ) );
			submission.signature = await Wallet.signTypedData( estimate.typed_data );
			log( __( 'Signed. Sending to the Spraay gateway…', DOMAIN ) );
		} else if ( estimate.transaction ) {
			if ( estimate.approval ) {
				await Wallet.ensureAllowance( config.token.address, estimate.approval.spender, totalUnits, function ( stage ) {
					var messages = {
						approve_prompt: sprintf( __( 'Approve exactly %s in your wallet so Spraay can distribute it…', DOMAIN ), formatUnits( totalUnits ) + ' ' + config.token.symbol ),
						approve_sent: __( 'Approval sent. Waiting for confirmation…', DOMAIN ),
						approve_confirmed: __( 'Approval confirmed.', DOMAIN ),
					};
					log( messages[ stage ] );
				} );
			}
			log( __( 'Confirm the batch payment in your wallet…', DOMAIN ) );
			submission.tx_hash = await Wallet.sendTransaction( estimate.transaction );
			txLink( submission.tx_hash );
			log( __( 'Transaction broadcast. Registering with the Spraay gateway…', DOMAIN ) );
		} else {
			throw new Error( __( 'The gateway estimate contained nothing to sign. Re-run the estimate.', DOMAIN ) );
		}
	}

	/**
	 * POST /execute, retrying network failures when a tx was already broadcast
	 * (the server-side claim makes repeats safe).
	 *
	 * @param {Object} submission { sender, tx_hash?, signature? }.
	 * @return {Promise<Object>} Execute result.
	 */
	async function submitWithRetry( submission ) {
		var attempts = submission.tx_hash ? 4 : 1;
		for ( var i = 1; ; i++ ) {
			try {
				return await apiFetch( {
					path: config.restPath + '/batches/' + state.batchId + '/execute',
					method: 'POST',
					data: submission,
				} );
			} catch ( err ) {
				// An earlier attempt reached the server even though its response was lost.
				if ( i > 1 && err && err.code === 'spraay_already_submitted' ) {
					return { status: 'pending', tx_hash: submission.tx_hash || '' };
				}
				// A REST error with a code came from the server; only retry transport failures.
				if ( i >= attempts || ( err && err.code && err.code !== 'fetch_error' ) ) {
					throw err;
				}
				await new Promise( function ( resolve ) {
					window.setTimeout( resolve, 2000 * i );
				} );
			}
		}
	}

	function pollStatus( initial ) {
		var attempts = 0;

		function handle( status, hash ) {
			if ( hash ) {
				txLink( hash );
			}
			if ( status === 'confirmed' ) {
				log( __( 'Confirmed on-chain. Payouts delivered.', DOMAIN ), 'success' );
				notify( 'success', __( 'Batch confirmed.', DOMAIN ) );
				return true;
			}
			if ( status === 'failed' ) {
				log( __( 'The batch failed on-chain. No further action was taken; check the transaction for details.', DOMAIN ), 'error' );
				notify( 'error', __( 'Batch failed.', DOMAIN ) );
				return true;
			}
			return false;
		}

		if ( handle( initial ) ) {
			return;
		}
		log( __( 'Waiting for on-chain confirmation…', DOMAIN ) );

		var timer = window.setInterval( async function () {
			attempts++;
			try {
				var res = await apiFetch( { path: config.restPath + '/batches/' + state.batchId + '/status' } );
				if ( handle( res.status, res.tx_hash ) ) {
					window.clearInterval( timer );
				}
			} catch ( err ) {
				// Transient; keep polling.
			}
			if ( attempts >= POLL_LIMIT ) {
				window.clearInterval( timer );
				log( __( 'Still pending. You can leave this page — status is checked in the background and shown in Batch History.', DOMAIN ), 'warning' );
			}
		}, POLL_INTERVAL );
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Run an async action with busy state and error reporting.
	 *
	 * @param {Function} fn Action.
	 * @return {Function} Listener.
	 */
	function action( fn ) {
		return async function () {
			if ( state.busy ) {
				return;
			}
			clearNotices();
			setBusy( true );
			try {
				await fn();
			} catch ( err ) {
				notify( 'error', errorMessage( err ) );
				if ( ! $( 'log' ).closest( '[data-spraay-step]' ).hidden ) {
					log( errorMessage( err ), 'error' );
				}
			} finally {
				setBusy( false );
			}
		};
	}

	// Recipient search.
	var searchTimer = null;
	var searchInput = $( 'search' );
	var results = $( 'search-results' );

	searchInput.addEventListener( 'input', function () {
		window.clearTimeout( searchTimer );
		var term = searchInput.value.trim();
		searchTimer = window.setTimeout( async function () {
			try {
				var items = await apiFetch( { path: config.restPath + '/recipients?per_page=20&search=' + encodeURIComponent( term ) } );
				results.textContent = '';
				if ( ! items.length ) {
					results.appendChild( el( 'li', { className: 'is-empty', text: __( 'No matching recipients.', DOMAIN ) } ) );
				}
				items.forEach( function ( item ) {
					var option = el( 'li', { role: 'option', tabindex: '0' }, [
						el( 'strong', { text: item.label } ),
						' ',
						el( 'code', { text: shortAddress( item.wallet_address ) } ),
					] );
					var pick = function () {
						if ( addRow( {
							recipient_id: item.id,
							label: item.label,
							wallet_address: item.wallet_address,
							amount: item.default_amount ? String( Number( item.default_amount ) ) : '',
							wc_order_ids: [],
						}, false ) ) {
							renderRows();
						}
						results.hidden = true;
						searchInput.value = '';
						searchInput.focus();
					};
					option.addEventListener( 'click', pick );
					option.addEventListener( 'keydown', function ( e ) {
						if ( e.key === 'Enter' ) {
							pick();
						}
					} );
					results.appendChild( option );
				} );
				results.hidden = false;
			} catch ( err ) {
				notify( 'error', errorMessage( err ) );
			}
		}, 250 );
	} );
	searchInput.addEventListener( 'focus', function () {
		if ( ! searchInput.value ) {
			searchInput.dispatchEvent( new Event( 'input' ) );
		}
	} );
	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest( '.spraay-picker' ) ) {
			results.hidden = true;
		}
	} );

	async function loadObligations() {
		var source = $( 'wc-source' ).value;
		var query = 'source=' + encodeURIComponent( source ) +
			'&date_from=' + encodeURIComponent( $( 'wc-from' ).value ) +
			'&date_to=' + encodeURIComponent( $( 'wc-to' ).value );
		var rows = await apiFetch( { path: config.restPath + '/obligations?' + query } );
		addObligations( rows );
	}

	$( 'wc-load' ).addEventListener( 'click', action( loadObligations ) );

	$( 'csv-load' ).addEventListener( 'click', action( async function () {
		var file = $( 'csv-file' ).files[ 0 ];
		if ( ! file ) {
			throw new Error( __( 'Choose a CSV file first.', DOMAIN ) );
		}
		var type = $( 'csv-type' ).value;
		var form = new window.FormData();
		form.append( 'file', file );
		form.append( 'type', type );
		var res = await apiFetch( { path: config.restPath + '/import', method: 'POST', body: form } );

		if ( type === 'commissions' ) {
			addObligations( res.rows );
		} else {
			res.rows.forEach( function ( row ) {
				addRow( row, true );
			} );
			renderRows();
			notify( res.errors.length ? 'warning' : 'success', sprintf(
				__( 'Imported %1$d row(s): %2$d new recipient(s), %3$d updated.', DOMAIN ),
				res.rows.length,
				res.created,
				res.updated
			), res.errors );
		}
		$( 'csv-file' ).value = '';
	} ) );

	$( 'save' ).addEventListener( 'click', action( async function () {
		var batch = await saveDraft();
		notify( 'success', sprintf( __( 'Draft batch #%d saved.', DOMAIN ), batch.id ) );
	} ) );

	$( 'estimate' ).addEventListener( 'click', action( async function () {
		await saveDraft();
		var estimate = await requestEstimate();
		renderReview( estimate );
		showStep( 'review' );
		await refreshWalletStatus();
	} ) );

	$( 'back' ).addEventListener( 'click', function () {
		clearNotices();
		showStep( 'build' );
	} );

	$( 'confirm' ).addEventListener( 'change', updateSignButton );

	$( 'connect' ).addEventListener( 'click', action( connectWallet ) );

	$( 'sign' ).addEventListener( 'click', action( signAndSubmit ) );

	if ( Wallet ) {
		Wallet.onChange( function () {
			state.account = '';
			refreshWalletStatus();
		} );
	}

	// Initial load.
	( async function init() {
		if ( ! Wallet || ! window.ethers ) {
			notify( 'warning', errorMessage( { spraayCode: 'no_ethers' } ) );
		}
		if ( state.batchId ) {
			setBusy( true );
			try {
				var batch = await apiFetch( { path: config.restPath + '/batches/' + state.batchId } );
				batch.items.forEach( function ( item ) {
					addRow( item, false );
				} );
				$( 'history-link' ).href = config.historyUrl + '&batch_id=' + batch.id;
				if ( batch.status === 'estimated' ) {
					notify( 'info', __( 'This batch was estimated earlier. Get a fresh estimate before signing.', DOMAIN ) );
				}
			} catch ( err ) {
				notify( 'error', errorMessage( err ) );
			} finally {
				setBusy( false );
			}
		} else if ( config.autoImport && Object.keys( config.sources ).length ) {
			action( loadObligations )();
		}
		renderRows();
	}() );
}( window, document ) );
