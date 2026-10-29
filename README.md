# Spraay Batch Payouts for WooCommerce

A WordPress/WooCommerce plugin that pays suppliers, vendors, affiliates and contractors in **USDC on Base** in a single on-chain transaction, through the [Spraay Protocol](https://spraay.app) gateway.

**This is a payout plugin, not a checkout gateway.** It never touches customer checkout. It distributes money *after* sales happen.

- **Noncustodial:** batches are signed in the merchant's own browser wallet (MetaMask, Coinbase Wallet, …). The plugin never sees, stores or asks for private keys.
- **Bridge architecture:** a thin PHP plugin talks to the Spraay REST gateway over HTTP. There is no Node.js server and no build step.
- **0.3% protocol fee**, added on top of the payouts and shown before signing. The plugin itself is free.

> **Status: pre-release (0.1.0).** Executing a batch needs a Spraay API key. The gateway's `/api/v1/batch/execute` endpoint is x402-paid, and API-key authentication that skips x402 is being added on the gateway side. Until it ships, execution stops with *"API key required — configure your Spraay API key in Settings"*. Recipients, batch building, estimates and history work today.

## How it works

```
Merchant (WP admin)          WordPress / PHP plugin              Spraay gateway        Base
──────────────────          ──────────────────────              ──────────────        ────
Build batch  ─────────────▶  save draft
Get estimate ─────────────▶  GET /free/estimate-batch  ───────▶  fee rate, supported
Review + approve ─────────▶  record approval
                             POST /api/v1/batch/execute ──────▶  unsigned tx + approval
Wallet verifies tx ◀────────  (tx returned to browser)
Wallet: approve USDC (exact amount), send tx ──────────────────────────────────────▶  sprayToken()
                             POST /submit (tx hash)
                             WP-Cron: eth_getTransactionReceipt ─────────────────────▶  receipt
                             mark confirmed / failed, note WC orders
```

Batch lifecycle: `draft → estimated → (approved) → pending → confirmed | failed`.

## Features

- **Recipients:** CRUD with EIP-55 checksum validation (pure-PHP Keccak-256), CSV import and export, and optional links to WooCommerce vendor IDs.
- **Batch builder:** add rows manually, from CSV, or from WooCommerce obligations. Up to 200 recipients per batch.
- **WooCommerce integration:** no multi-vendor plugin is required. It reads supplier payout fields from completed orders, and also reads WooCommerce Product Vendors unpaid commissions and Dokan vendor earnings when either plugin is active. Product Vendors commission CSV exports are supported too.
- **Duplicate-payment protection:** orders already in a pending or confirmed batch can't be paid again. This is checked when a draft is saved and again just before signing.
- **On-chain reconciliation:** WP-Cron checks pending batches every five minutes against Base RPC and records the confirmation time from the block timestamp.
- **Batch history:** status and date filters, per-batch detail with BaseScan links, and CSV export.
- **HPOS:** compatible with WooCommerce High-Performance Order Storage.

## Verify-before-sign

The wallet script refuses to sign unless every check below passes. It fails closed and shows the merchant what didn't match.

1. `transaction.to` is the Spraay batch contract `0x1646452F98E36A3c9Cfc3eDD8868221E207B5eEC`.
2. `transaction.chainId` is `8453` (Base).
3. `transaction.value` is `0`.
4. The calldata ABI-decodes as `sprayToken(address,(address,uint256)[])` (selector `0xfb83b683`) for USDC.
5. Every decoded recipient and amount matches, in order, what the merchant confirmed on the review screen.
6. The approval spender is the Spraay contract and the approval token is USDC.
7. The approval amount is between the confirmed payout total and that total plus 2%. The plugin approves exactly that amount, never an unlimited allowance.

The server repeats the address, chain, value and approval checks before it returns the transaction to the browser.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| WooCommerce | 7.0 |
| PHP | 7.4 (64-bit) |

The merchant also needs a browser wallet holding USDC on Base (enough for the payouts plus the 0.3% fee) and a little ETH on Base for gas.

## Installation

1. Copy this repository into `wp-content/plugins/spraay-batch-payouts/`, or zip it and upload it under **Plugins → Add New → Upload**.
2. Activate **Spraay Batch Payouts**. WooCommerce must be active. Activation creates three tables: `{prefix}spraay_recipients`, `{prefix}spraay_batches` and `{prefix}spraay_batch_items`.
3. Open **Spraay Payouts → Settings**, check the gateway URL (default `https://gateway.spraay.app`), and enter your Spraay API key.

## Configuration

All settings are stored in the `spraay_settings` option.

| Setting | Default | Notes |
|---|---|---|
| Gateway URL | `https://gateway.spraay.app` | Must use `https://` |
| API key | — | Sent as the `X-API-Key` header. This is not a wallet key. |
| Network | Base (8453) | The gateway settles on `base` and `peaq` |
| Token | USDC | `0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913` |
| Fee display | on | Shows the estimated fee while building |
| Auto-import WC | off | Loads obligations when a new batch is started |

### Supplier payouts from order metadata

Without a multi-vendor plugin, store payout data on each order using these meta keys:

| Meta key | Value |
|---|---|
| `_spraay_supplier_wallet` | Supplier wallet address |
| `_spraay_supplier_amount` | USDC owed for this order |
| `_spraay_supplier_label` | Optional supplier name |

Completed orders that carry these keys appear under **Import from WooCommerce** in the batch builder. You can rename the keys with the `spraay_order_meta_keys` filter.

## Gateway endpoints used

| Endpoint | When | Notes |
|---|---|---|
| `GET /free/estimate-batch?recipients=N&chain=base` | Estimate | Returns `protocolFeeBps` and `supported`. The fee is computed locally. |
| `POST /api/v1/batch/execute` | Before signing | Body: `chain`, `token`, `sender`, `recipients[]`, `amounts[]`, where amounts are **base-unit strings** (250 USDC = `"250000000"`). Returns an unsigned transaction. Never retried automatically. |

There is no gateway status endpoint: confirmation is read from Base RPC (`eth_getTransactionReceipt`). HTTP `409 duplicate_payment_detected` is shown as "batch already submitted", and HTTP `402` as "API key required".

## Hooks

**Filters:** `spraay_supported_chains`, `spraay_rpc_url`, `spraay_ethers_script_url`, `spraay_gateway_request_args`, `spraay_gateway_estimate_payload`, `spraay_gateway_estimate_response`, `spraay_gateway_execute_payload`, `spraay_gateway_execute_response`, `spraay_order_meta_keys`, `spraay_wc_obligation_sources`, `spraay_wc_obligations`, `spraay_detected_vendor_plugin`

**Actions:** `spraay_loaded`, `spraay_recipient_created`, `spraay_recipient_updated`, `spraay_recipient_deleted`, `spraay_batch_draft_saved`, `spraay_batch_approved`, `spraay_batch_submitted`, `spraay_batch_confirmed`, `spraay_batch_failed`

`spraay_rpc_url` is worth setting in production. The default `https://mainnet.base.org` is public and rate-limited.

## External services

The plugin calls the Spraay gateway, the Base RPC endpoint (server side, for reconciliation) and cdnjs (ethers.js 6.13.5, loaded with an SRI hash). [`readme.txt`](readme.txt) lists exactly what is sent to each.

> **WordPress.org note:** loading ethers.js from a CDN will likely fail directory review. Before submitting, bundle `ethers.umd.min.js` locally and point `spraay_ethers_script_url` at it.

## Project layout

```
spraay-batch-payouts.php        Plugin header, autoloader, activation hooks
uninstall.php                   Drops tables/options (define SPRAAY_KEEP_DATA_ON_UNINSTALL to keep data)
includes/
  class-spraay-plugin.php       Singleton bootstrap, schema, settings, chain config
  class-spraay-gateway-client.php  HTTP bridge to the gateway
  class-spraay-batch.php        Batch model and workflow
  class-spraay-reconciliation.php  WP-Cron + Base RPC receipt checks
  class-spraay-wc-integration.php  Order/vendor obligations, duplicate-payment guard
  class-spraay-recipient.php    Recipient CRUD
  class-spraay-csv-handler.php  CSV import/export (formula-injection safe)
  class-spraay-rest-api.php     spraay/v1 REST routes for the admin UI
  class-spraay-admin.php        Menus, settings, admin-post handlers
  class-spraay-address.php      EIP-55 / Keccak-256
  class-spraay-amount.php       Exact base-unit arithmetic (no floats)
  class-spraay-*-list-table.php WP_List_Table screens
admin/views/                    Page templates
admin/js/spraay-wallet.js       Wallet connection, verification, signing (ethers v6)
admin/js/spraay-admin.js        Batch builder UI
```

## Security

- Every admin action checks the `manage_woocommerce` capability and a nonce. REST routes use `wp_rest` cookie auth.
- All input is sanitized, all output is escaped, and all SQL goes through `$wpdb->prepare()`.
- Amounts are integer base units end to end, so there is no floating-point rounding.
- Batch submission is claimed atomically, so a double-click can't record two transactions.

## License

GPL v2 or later. See [LICENSE](LICENSE).

Built by [Plagtech](https://plagtech.net) for [Spraay](https://spraay.app).
