# WooCommerce Spraay Payouts Plugin — Build Brief

## Overview

Build a WordPress/WooCommerce plugin called **Spraay Batch Payouts** that lets WooCommerce merchants batch-pay suppliers, vendors, affiliates, or contractors in USDC on Base through the existing Spraay Protocol gateway.

This is a **payout plugin, NOT a checkout gateway**. It does not touch the customer checkout flow. It handles the distribution of funds _after_ sales occur.

## Architecture

**Bridge pattern**: thin native PHP plugin that communicates with the existing Spraay REST gateway over HTTP. No Node.js, no npm, no separate server process. All blockchain logic stays in the Spraay gateway — the plugin handles WordPress admin UI, recipient management, batch preparation, and reconciliation.

```
WordPress/PHP (this plugin)
  → Admin dashboard, recipient/vendor records, batch preparation UI
  → REST calls to Spraay gateway

Spraay Gateway (existing, external)
  → Batch estimation and execution coordination
  → On-chain USDC settlement on Base

Merchant Wallet (browser, e.g. MetaMask)
  → Approval and signing of batch transactions
  → Noncustodial — Spraay never holds funds

WordPress Database
  → Batch history, transaction hashes, recipient records, status tracking
```

## Project Setup

- **Repository**: `plagtech/woocommerce-spraay-payouts`
- **Plugin slug**: `spraay-batch-payouts`
- **Text domain**: `spraay-batch-payouts`
- **Minimum PHP**: 7.4
- **Minimum WordPress**: 6.0
- **Minimum WooCommerce**: 7.0
- **License**: GPL v2 or later (required for WordPress.org directory)
- **Author**: Plagtech
- **Author URI**: https://plagtech.net
- **Plugin URI**: https://spraay.app

## Directory Structure

```
woocommerce-spraay-payouts/
├── spraay-batch-payouts.php          # Main plugin file (plugin header, bootstrap)
├── uninstall.php                      # Clean uninstall handler
├── readme.txt                         # WordPress.org readme (standard format)
├── LICENSE                            # GPL v2
├── includes/
│   ├── class-spraay-plugin.php        # Main plugin class (singleton, hooks registration)
│   ├── class-spraay-admin.php         # Admin menu, pages, and settings
│   ├── class-spraay-gateway-client.php # HTTP bridge to Spraay REST gateway
│   ├── class-spraay-batch.php         # Batch creation, estimation, execution logic
│   ├── class-spraay-recipient.php     # Recipient CRUD operations
│   ├── class-spraay-reconciliation.php # On-chain status tracking and confirmation
│   ├── class-spraay-csv-handler.php   # CSV import/export of recipients and batches
│   ├── class-spraay-wc-integration.php # WooCommerce order/vendor data reading
│   └── class-spraay-rest-api.php      # Plugin's own WP REST API endpoints (admin AJAX alternative)
├── admin/
│   ├── views/
│   │   ├── dashboard.php              # Main plugin dashboard page
│   │   ├── recipients.php             # Recipient management page
│   │   ├── new-batch.php              # Batch creation/review page
│   │   ├── batch-history.php          # Past batches and tx hashes
│   │   ├── settings.php               # Plugin settings (gateway URL, chain, defaults)
│   │   └── partials/                  # Reusable template parts
│   ├── css/
│   │   └── spraay-admin.css           # Admin styles
│   └── js/
│       ├── spraay-admin.js            # Admin UI logic
│       └── spraay-wallet.js           # Web3 wallet connection and signing (ethers.js or viem)
└── languages/
    └── spraay-batch-payouts.pot       # Translation template
```

## Database Tables

Create on plugin activation via `dbDelta()`. Use `$wpdb->prefix` for table names.

### `{prefix}spraay_recipients`

| Column          | Type                | Notes                                      |
|-----------------|---------------------|--------------------------------------------|
| id              | bigint(20) unsigned | Auto-increment PK                          |
| label           | varchar(255)        | Display name (e.g. "Supplier A")           |
| wallet_address  | varchar(42)         | EVM address (0x...)                        |
| email           | varchar(255)        | Optional, for merchant reference           |
| wc_vendor_id    | bigint(20) unsigned | Nullable FK to WP users (for WC vendors)   |
| default_amount  | decimal(18,6)       | Optional default payout amount             |
| currency        | varchar(10)         | Default "USDC"                             |
| notes           | text                | Merchant notes                             |
| created_at      | datetime            | UTC                                        |
| updated_at      | datetime            | UTC                                        |

### `{prefix}spraay_batches`

| Column           | Type                | Notes                                      |
|------------------|---------------------|--------------------------------------------|
| id               | bigint(20) unsigned | Auto-increment PK                          |
| status           | varchar(20)         | draft / estimated / pending / confirmed / failed |
| total_amount     | decimal(18,6)       | Sum of all payouts in batch                |
| fee_amount       | decimal(18,6)       | Spraay fee (0.3%)                          |
| recipient_count  | int                 | Number of recipients in batch              |
| tx_hash          | varchar(66)         | On-chain transaction hash (0x...)          |
| chain_id         | int                 | Default 8453 (Base mainnet)                |
| gateway_batch_id | varchar(255)        | Spraay gateway's batch reference           |
| created_by       | bigint(20) unsigned | WP user ID of merchant who created it      |
| approved_by      | bigint(20) unsigned | WP user ID who approved (may differ)       |
| created_at       | datetime            | UTC                                        |
| confirmed_at     | datetime            | UTC, when on-chain confirmation received   |

### `{prefix}spraay_batch_items`

| Column          | Type                | Notes                                      |
|-----------------|---------------------|--------------------------------------------|
| id              | bigint(20) unsigned | Auto-increment PK                          |
| batch_id        | bigint(20) unsigned | FK to spraay_batches                       |
| recipient_id    | bigint(20) unsigned | FK to spraay_recipients                    |
| amount          | decimal(18,6)       | Payout amount for this recipient           |
| currency        | varchar(10)         | "USDC"                                     |
| wc_order_ids    | text                | JSON array of WooCommerce order IDs (if applicable) |
| status          | varchar(20)         | Mirrors batch status or individual override |

## Plugin Settings (wp_options)

Store under a single option key `spraay_settings`:

```php
[
    'gateway_url'     => 'https://gateway.spraay.app',  // Spraay gateway base URL
    'chain_id'        => 8453,                           // Base mainnet
    'token'           => 'USDC',                         // Default payout token
    'fee_display'     => true,                           // Show fee breakdown in UI
    'auto_import_wc'  => false,                          // Auto-import WC vendor commissions
    'api_key'         => '',                              // Spraay gateway API key (if applicable)
]
```

## Key Functional Requirements

### 1. Recipient Management
- CRUD for wallet addresses with labels
- Validate EVM addresses (0x, 42 chars, checksum)
- CSV import: columns for label, wallet_address, amount, email
- CSV export of current recipient list
- Optional linking to WooCommerce vendor/user IDs

### 2. WooCommerce Order Data Reading
- Read WooCommerce orders to surface vendor/supplier payment obligations
- Support pulling data from:
  - Order metadata (custom supplier fields)
  - WooCommerce Product Vendors commission exports (CSV format)
  - Direct DB queries for vendor commissions if Product Vendors or similar is installed
- Detect which multi-vendor plugin is active (if any) and adapt — but don't _require_ any of them
- Duplicate payment prevention: track which WC order IDs have been included in a confirmed batch

### 3. Batch Builder
- Select recipients + amounts manually, or import from WC order data / CSV
- Call Spraay gateway estimation endpoint to get fee breakdown and total
- Display review screen: recipient list, amounts, Spraay fee (0.3%), total, chain
- Require explicit merchant approval before execution
- Save batch as "draft" until approved, "estimated" after gateway estimation, "pending" during execution

### 4. Wallet Signing (Client-Side JavaScript)
- Connect to merchant's browser wallet (MetaMask, Coinbase Wallet, etc.)
- Use ethers.js v6 loaded from CDN (cdnjs.cloudflare.com) — do NOT bundle node_modules
- Present the batch transaction for merchant to sign
- Noncustodial: the plugin never has access to private keys
- Handle wallet connection states, chain switching (must be on Base 8453), insufficient balance errors

### 5. Gateway Bridge (PHP HTTP Client)
- Use `wp_remote_post()` / `wp_remote_get()` — no cURL directly, no Guzzle
- Endpoints to integrate with (these exist on the Spraay gateway):
  - `POST /api/v1/batch/estimate` — get fee estimate for a proposed batch
  - `POST /api/v1/batch/execute` — submit a signed batch for on-chain execution
  - `GET /api/v1/batch/{id}/status` — poll batch status
- Handle gateway errors, timeouts, and retry logic gracefully
- Store gateway responses in batch record for reconciliation

### 6. Reconciliation
- After batch execution, poll gateway for on-chain confirmation
- Store tx_hash and confirmed_at when settlement is verified
- Display confirmation status in batch history with link to BaseScan
- Optional: WP-Cron scheduled check for pending batch status updates

### 7. Batch History
- Paginated list of all batches with status, amount, recipient count, date
- Click into any batch to see full recipient breakdown and tx details
- Filter by status, date range
- Export batch history as CSV

## Security Requirements (WordPress Standards)

These are mandatory for WordPress.org submission:

- **Nonces**: Every form submission and AJAX request must include and verify a nonce (`wp_nonce_field()`, `wp_verify_nonce()`)
- **Capability checks**: All admin actions require `manage_woocommerce` capability check
- **Input sanitization**: All user input through `sanitize_text_field()`, `sanitize_email()`, `absint()`, etc.
- **Output escaping**: All output through `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` — no raw `echo` of user data
- **SQL**: Use `$wpdb->prepare()` for every query with dynamic values — never interpolate
- **External service disclosure**: readme.txt must clearly state the plugin communicates with the Spraay gateway, what data is sent, and link to Spraay's privacy/terms pages
- **No direct file access**: Every PHP file starts with `if (!defined('ABSPATH')) exit;`
- **Enqueue properly**: All CSS/JS through `wp_enqueue_script()` / `wp_enqueue_style()`, only on plugin's own admin pages

## WordPress.org readme.txt Requirements

Follow the standard readme format: https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/

Must include:
- Plugin name, description, author
- "Requires at least" / "Tested up to" / "Requires PHP" / "Stable tag"
- Clear description that this is a **payout** plugin, not a checkout gateway
- FAQ section addressing: what is Spraay, is it custodial (no), what are the fees (0.3%), what chain (Base), what token (USDC)
- External service disclosure section: the plugin sends payout data (wallet addresses, amounts) to the Spraay gateway API at gateway.spraay.app for batch estimation and execution. Link to https://spraay.app/privacy and https://spraay.app/terms
- Screenshots section (placeholders OK for now)
- Changelog

## What NOT to Build

- No checkout/payment gateway — this does NOT process customer payments
- No token swaps or DEX functionality
- No custodial wallet or key storage — the plugin never holds or stores private keys
- No admin-side private key input field — signing happens exclusively in the browser wallet
- No multi-vendor plugin as a hard dependency — WooCommerce is the only required plugin
- No npm/node_modules — keep it pure PHP + CDN-loaded JS
- No Composer autoloader for the plugin itself (keep it simple; autoload classes manually or with a simple spl_autoload_register)
- No premium/freemium gating — the plugin is free; revenue comes from Spraay's 0.3% on-chain fee

## Implementation Notes

- Use WordPress coding standards: https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/
- Class naming: `Spraay_Plugin`, `Spraay_Admin`, `Spraay_Batch`, etc. (WordPress style, not PSR-4)
- Hook prefixes: `spraay_` for all actions and filters
- Option prefix: `spraay_` for all wp_options entries
- The main plugin class should use a singleton pattern with an `instance()` method
- Register activation hook for DB table creation and deactivation hook for cleanup
- Use WP_List_Table for the batch history and recipient list admin pages
- Admin menu should be a top-level menu item with the Spraay icon, nested under WooCommerce if `woocommerce` is active

## First Deliverable

Scaffold the complete plugin structure with:
1. Main plugin file with proper WordPress plugin header
2. All class files with method stubs and docblocks
3. Database table creation on activation
4. Admin menu registration and basic page rendering
5. Settings page with gateway configuration
6. Recipient management page (CRUD, CSV import)
7. Batch builder page (manual recipient selection + amount entry)
8. Gateway client class with method stubs for estimate/execute/status
9. Wallet connection JS with ethers.js CDN import
10. readme.txt and LICENSE files
11. uninstall.php for clean removal

Do NOT use placeholder values for author, URIs, or metadata. Use:
- Author: Plagtech
- Author URI: https://plagtech.net
- Plugin URI: https://spraay.app
- Gateway URL default: https://gateway.spraay.app
- License: GPL v2 or later

Create the project at: `~/Documents/woocommerce-spraay-payouts/`
