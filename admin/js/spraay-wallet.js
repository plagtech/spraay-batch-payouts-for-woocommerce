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
		 * Send a transaction prepared by the gateway. Resolves with the hash as
		 * soon as it is broadcast.
		 *
		 * @param {{to:string,data:string,value?:string}} tx Transaction.
		 * @return {Promise<string>} Transaction hash.
		 */
		sendTransaction: async function ( tx ) {
			try {
				var response = await signer.sendTransaction( {
					to: ethers.getAddress( tx.to ),
					data: tx.data,
					value: tx.value ? BigInt( tx.value ) : 0n,
				} );
				return response.hash;
			} catch ( err ) {
				throw normalizeError( err );
			}
		},

		/**
		 * Sign EIP-712 typed data (gateway-relayed execution).
		 *
		 * @param {{domain:Object,types:Object,primaryType?:string,message:Object}} typed Payload.
		 * @return {Promise<string>} Signature.
		 */
		signTypedData: async function ( typed ) {
			try {
				var types = Object.assign( {}, typed.types );
				delete types.EIP712Domain; // ethers derives the domain type itself.
				return await signer.signTypedData( typed.domain, types, typed.message );
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
