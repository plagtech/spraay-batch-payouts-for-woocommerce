/**
 * Spraay wallet bridge: browser-wallet connection and signing via ethers v6.
 *
 * Noncustodial by design: everything here talks to the injected EIP-1193
 * provider (MetaMask, Coinbase Wallet, ...). Private keys never touch this
 * page, WordPress or Spraay.
 *
 * @package Spraay_Batch_Payouts
 */
( function ( window ) {
	'use strict';

	var ethers = window.ethers;

	var ERC20_ABI = [
		'function balanceOf(address owner) view returns (uint256)',
		'function allowance(address owner, address spender) view returns (uint256)',
		'function approve(address spender, uint256 amount) returns (bool)',
	];

	// Spraay batch contract entry points (selectors 0xfb83b683 and 0x970aa1f2).
	var SPRAAY_ABI = [
		'function sprayToken(address token, tuple(address recipient, uint256 amount)[] recipients)',
		'function sprayETH(tuple(address recipient, uint256 amount)[] recipients) payable',
	];

	var provider = null;
	var signer = null;

	/**
	 * Error with a stable code the UI can map to a message.
	 *
	 * @param {string} code    Machine code.
	 * @param {string} message Fallback message.
	 * @param {*}      cause   Underlying error.
	 * @return {Error} Error.
	 */
	function walletError( code, message, cause ) {
		var err = new Error( message );
		err.spraayCode = code;
		err.cause = cause;
		return err;
	}

	/**
	 * Map provider/ethers errors to spraayCode values.
	 *
	 * @param {*} err Raw error.
	 * @return {Error} Normalized error.
	 */
	function normalizeError( err ) {
		if ( err && err.spraayCode ) {
			return err;
		}
		var code = err && ( err.code || ( err.info && err.info.error && err.info.error.code ) );
		if ( code === 'ACTION_REJECTED' || code === 4001 ) {
			return walletError( 'rejected', 'Request rejected in wallet.', err );
		}
		if ( code === 'INSUFFICIENT_FUNDS' ) {
			return walletError( 'insufficient_gas', 'Not enough ETH on Base to pay gas.', err );
		}
		var message = ( err && ( err.shortMessage || err.message ) ) || 'Wallet error.';
		return walletError( 'wallet', message, err );
	}

	function requireEthers() {
		if ( ! ethers || ! ethers.BrowserProvider ) {
			throw walletError( 'no_ethers', 'ethers.js failed to load.' );
		}
	}

	function requireWallet() {
		if ( ! window.ethereum ) {
			throw walletError( 'no_wallet', 'No browser wallet found.' );
		}
	}

	/**
	 * (Re)create the provider. ethers v6 providers are bound to one network,
	 * so this runs again after every chain switch.
	 */
	async function refreshProvider() {
		provider = new ethers.BrowserProvider( window.ethereum );
		signer = await provider.getSigner();
		return signer;
	}

	var SpraayWallet = {
		/**
		 * Whether an injected wallet exists.
		 *
		 * @return {boolean} True if available.
		 */
		isAvailable: function () {
			return !! window.ethereum;
		},

		/**
		 * Request account access.
		 *
		 * @return {Promise<string>} Checksummed address.
		 */
		connect: async function () {
			requireEthers();
			requireWallet();
			try {
				await window.ethereum.request( { method: 'eth_requestAccounts' } );
				await refreshProvider();
				return await signer.getAddress();
			} catch ( err ) {
				throw normalizeError( err );
			}
		},

		/**
		 * Currently connected address, or '' if not connected.
		 *
		 * @return {Promise<string>} Address.
		 */
		getAddress: async function () {
			if ( ! signer ) {
				return '';
			}
			return signer.getAddress();
		},

		/**
		 * Switch the wallet to the target chain, adding it if unknown.
		 *
		 * @param {{id:number,name:string,rpcUrl:string,explorer:string}} chain Target chain.
		 * @return {Promise<void>}
		 */
		ensureChain: async function ( chain ) {
			requireWallet();
			var hexId = '0x' + Number( chain.id ).toString( 16 );
			var current = await window.ethereum.request( { method: 'eth_chainId' } );
			if ( String( current ).toLowerCase() === hexId ) {
				if ( ! signer ) {
					await refreshProvider();
				}
				return;
			}
			try {
				await window.ethereum.request( { method: 'wallet_switchEthereumChain', params: [ { chainId: hexId } ] } );
			} catch ( err ) {
				var code = err && ( err.code || ( err.data && err.data.originalError && err.data.originalError.code ) );
				if ( code !== 4902 ) {
					throw normalizeError( err );
				}
				try {
					await window.ethereum.request( {
						method: 'wallet_addEthereumChain',
						params: [ {
							chainId: hexId,
							chainName: chain.name,
							nativeCurrency: { name: 'Ether', symbol: 'ETH', decimals: 18 },
							rpcUrls: [ chain.rpcUrl ],
							blockExplorerUrls: [ chain.explorer ],
						} ],
					} );
				} catch ( addErr ) {
					throw normalizeError( addErr );
				}
			}
			current = await window.ethereum.request( { method: 'eth_chainId' } );
			if ( String( current ).toLowerCase() !== hexId ) {
				throw walletError( 'wrong_chain', 'Wallet is not on ' + chain.name + '.' );
			}
			await refreshProvider();
		},

		/**
		 * ERC-20 balance of the connected wallet.
		 *
		 * @param {string} token Token contract address.
		 * @return {Promise<bigint>} Balance in base units.
		 */
		getTokenBalance: async function ( token ) {
			var contract = new ethers.Contract( token, ERC20_ABI, provider );
			return contract.balanceOf( await signer.getAddress() );
		},

		/**
		 * Make sure the spender may move `amount` of `token`, approving exactly
		 * that amount if needed (never an unlimited approval).
		 *
		 * @param {string}   token    Token address.
		 * @param {string}   spender  Spender (Spraay contract) address.
		 * @param {bigint}   amount   Base units.
		 * @param {Function} onStatus Progress callback.
		 * @return {Promise<void>}
		 */
		ensureAllowance: async function ( token, spender, amount, onStatus ) {
			try {
				var contract = new ethers.Contract( token, ERC20_ABI, signer );
				var owner = await signer.getAddress();
				var current = await contract.allowance( owner, spender );
				if ( current >= amount ) {
					return;
				}
				onStatus( 'approve_prompt' );
				var tx = await contract.approve( spender, amount );
				onStatus( 'approve_sent', tx.hash );
				await tx.wait( 1 );
				onStatus( 'approve_confirmed', tx.hash );
			} catch ( err ) {
				throw normalizeError( err );
			}
		},

		/**
		 * Verify the gateway's unsigned transaction before anything is signed.
		 * Fails closed: any mismatch is returned as a human-readable problem.
		 *
		 * @param {Object} prepared  { transaction: {to,data,value,chain_id,gas_limit}, approval: {token,spender,amount}|null }.
		 * @param {Object} expected  { contract, chainId, token, recipients: string[], amounts: bigint[], ceilingBps: number }
		 *                           — recipients/amounts are what the merchant confirmed in the UI.
		 * @return {string[]} Problems; empty when the transaction is safe to sign.
		 */
		verifyBatchTransaction: function ( prepared, expected ) {
			requireEthers();
			var problems = [];
			var tx = prepared.transaction || {};

			// 1. Destination is the known Spraay contract.
			if ( ! ethers.isAddress( tx.to ) || ! this.sameAddress( tx.to, expected.contract ) ) {
				problems.push( 'transaction is addressed to ' + tx.to + ', not the Spraay contract ' + expected.contract );
			}

			// 2. Network.
			if ( Number( tx.chain_id ) !== Number( expected.chainId ) ) {
				problems.push( 'transaction is for chain ' + tx.chain_id + ', expected ' + expected.chainId );
			}

			// 3. No ETH for an ERC-20 batch.
			var value;
			try {
				value = BigInt( tx.value || '0' );
			} catch ( e ) {
				value = -1n;
			}
			if ( value !== 0n ) {
				problems.push( 'transaction value is ' + tx.value + ', expected 0' );
			}

			// 4 + 5. Decode calldata and match it against the confirmed rows.
			var parsed = null;
			try {
				parsed = new ethers.Interface( SPRAAY_ABI ).parseTransaction( { data: tx.data, value: value > 0n ? value : 0n } );
			} catch ( e ) {
				parsed = null;
			}
			if ( ! parsed ) {
				problems.push( 'transaction data is not a Spraay batch call' );
			} else if ( parsed.name !== 'sprayToken' ) {
				problems.push( 'transaction calls ' + parsed.name + ', expected sprayToken for a token payout' );
			} else {
				var token = parsed.args[ 0 ];
				var rows = parsed.args[ 1 ];
				if ( ! this.sameAddress( token, expected.token ) ) {
					problems.push( 'transaction pays token ' + token + ', expected ' + expected.token );
				}
				if ( rows.length !== expected.recipients.length ) {
					problems.push( 'transaction has ' + rows.length + ' recipients, you confirmed ' + expected.recipients.length );
				} else {
					for ( var i = 0; i < rows.length; i++ ) {
						if ( ! this.sameAddress( rows[ i ][ 0 ], expected.recipients[ i ] ) ) {
							problems.push( 'recipient #' + ( i + 1 ) + ' is ' + rows[ i ][ 0 ] + ', you confirmed ' + expected.recipients[ i ] );
						}
						if ( BigInt( rows[ i ][ 1 ] ) !== expected.amounts[ i ] ) {
							problems.push( 'amount #' + ( i + 1 ) + ' is ' + rows[ i ][ 1 ] + ' base units, you confirmed ' + expected.amounts[ i ] );
						}
					}
				}
			}

			// 6 + 7. Approval: right token and spender, amount within the confirmed total + ceiling.
			var approval = prepared.approval;
			if ( approval ) {
				if ( ! this.sameAddress( approval.spender, expected.contract ) ) {
					problems.push( 'approval spender is ' + approval.spender + ', not the Spraay contract' );
				}
				if ( approval.token && ! this.sameAddress( approval.token, expected.token ) ) {
					problems.push( 'approval is for token ' + approval.token + ', expected ' + expected.token );
				}
				var subtotal = expected.amounts.reduce( function ( sum, a ) {
					return sum + a;
				}, 0n );
				var ceiling = subtotal + ( subtotal * BigInt( expected.ceilingBps ) ) / 10000n;
				var amount;
				try {
					amount = BigInt( approval.amount );
				} catch ( e ) {
					amount = -1n;
				}
				if ( amount < subtotal || amount > ceiling ) {
					problems.push( 'approval amount ' + approval.amount + ' is outside the allowed range ' + subtotal + '–' + ceiling + ' base units' );
				}
			}

			return problems;
		},

		/**
		 * Send the verified gateway transaction. Resolves with the hash as soon
		 * as it is broadcast.
		 *
		 * @param {{to:string,data:string,value?:string,chain_id:number,gas_limit?:string}} tx Transaction (gas_limit is hex).
		 * @return {Promise<string>} Transaction hash.
		 */
		sendTransaction: async function ( tx ) {
			try {
				var request = {
					to: ethers.getAddress( tx.to ),
					data: tx.data,
					value: BigInt( tx.value || '0' ),
					chainId: Number( tx.chain_id ),
				};
				if ( tx.gas_limit ) {
					request.gasLimit = BigInt( tx.gas_limit ); // Hex string, e.g. "0x55730".
				}
				var response = await signer.sendTransaction( request );
				return response.hash;
			} catch ( err ) {
				throw normalizeError( err );
			}
		},

		/**
		 * Subscribe to account/chain changes.
		 *
		 * @param {Function} callback Called with ( event, value ).
		 */
		onChange: function ( callback ) {
			if ( ! window.ethereum || ! window.ethereum.on ) {
				return;
			}
			window.ethereum.on( 'accountsChanged', function ( accounts ) {
				signer = null;
				callback( 'accounts', accounts );
			} );
			window.ethereum.on( 'chainChanged', function ( chainId ) {
				signer = null;
				callback( 'chain', chainId );
			} );
		},

		/**
		 * Decimal string to base units.
		 *
		 * @param {string} amount   Decimal amount.
		 * @param {number} decimals Token decimals.
		 * @return {bigint} Units.
		 */
		parseUnits: function ( amount, decimals ) {
			requireEthers();
			return ethers.parseUnits( String( amount ), decimals );
		},

		/**
		 * Base units to decimal string.
		 *
		 * @param {bigint} units    Units.
		 * @param {number} decimals Token decimals.
		 * @return {string} Decimal amount.
		 */
		formatUnits: function ( units, decimals ) {
			requireEthers();
			return ethers.formatUnits( units, decimals );
		},

		/**
		 * Whether a string is a valid address (checksum enforced for mixed case).
		 *
		 * @param {string} address Candidate.
		 * @return {boolean} Valid.
		 */
		isAddress: function ( address ) {
			return !! ethers && ethers.isAddress( address );
		},

		/**
		 * Case-insensitive address equality.
		 *
		 * @param {string} a Address.
		 * @param {string} b Address.
		 * @return {boolean} Equal.
		 */
		sameAddress: function ( a, b ) {
			return !! a && !! b && String( a ).toLowerCase() === String( b ).toLowerCase();
		},
	};

	window.SpraayWallet = SpraayWallet;
}( window ) );
