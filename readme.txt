=== Spraay Batch Payouts ===
Contributors: plagtech
Tags: woocommerce, payouts, usdc, base, vendors
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Batch-pay suppliers, vendors, affiliates and contractors in USDC on Base from your WooCommerce admin, signed with your own wallet.

== Description ==

**Spraay Batch Payouts is a payout plugin, not a checkout gateway.** It does not change your checkout or process customer payments. It helps you distribute money *after* sales happen: paying suppliers, marketplace vendors, affiliates or contractors in USDC on the Base network — many recipients in a single on-chain transaction.

= How it works =

1. **Manage recipients** — save wallet addresses with labels, or import them from CSV. Addresses are validated, including EIP-55 checksums.
2. **Build a batch** — pick recipients and amounts by hand, import from CSV, or pull unpaid obligations from WooCommerce orders.
3. **Review** — the Spraay gateway estimates the batch. You see every recipient, amount, the 0.3% Spraay fee, the total and the network before anything is signed.
4. **Approve and sign** — you explicitly approve, then sign in your own browser wallet (MetaMask, Coinbase Wallet, etc.).
5. **Reconcile** — the plugin tracks the transaction until it is confirmed on-chain, records the transaction hash, and links to BaseScan.

= Features =

* Recipient management with CSV import/export
* Batch builder with manual entry, CSV import and WooCommerce import
* WooCommerce integration that works without any multi-vendor plugin (via order supplier fields), and also reads WooCommerce Product Vendors commissions and Dokan vendor earnings when those plugins are active
* Duplicate-payment protection: orders already in a pending or confirmed batch cannot be paid again
* Batch history with status filters, date range filters, per-batch detail and CSV export
* Background status checks via WP-Cron
* Compatible with WooCommerce High-Performance Order Storage (HPOS)

= Noncustodial =

The plugin never sees, stores or asks for private keys or seed phrases. Signing happens only in your browser wallet. Spraay never holds your funds: USDC moves directly from your wallet to your recipients on-chain.

= Supplier payouts from order metadata =

Without a multi-vendor plugin, you can store supplier payout data on each order using these order meta keys (filterable with `spraay_order_meta_keys`):

* `_spraay_supplier_wallet` — supplier wallet address
* `_spraay_supplier_amount` — amount owed in USDC for that order
* `_spraay_supplier_label` — optional supplier name

Completed orders carrying these fields appear as obligations in the batch builder.

== External services ==

This plugin connects to the **Spraay gateway** (`https://gateway.spraay.app` by default, configurable in Settings) to estimate and execute payout batches and to check their on-chain status. It is required for the plugin to work.

**What is sent and when:**

* When you request an estimate: the network (chain ID), token symbol (USDC), a batch reference generated from your site URL hash and batch number, each recipient's wallet address and payout amount, and — if connected — your wallet address.
* When you sign and submit a batch: the gateway batch ID, network, your wallet address, and the transaction hash or signature produced by your wallet.
* When checking status (on the batch screens and every five minutes via WP-Cron while a batch is pending): the gateway batch ID.
* Every request includes your site's home URL in the User-Agent header, and your gateway API key if you configured one.

Recipient labels, emails and notes are never sent.

Spraay gateway: [Terms of Service](https://spraay.app/terms) · [Privacy Policy](https://spraay.app/privacy)

**Content delivery:** the batch builder loads the ethers.js library from cdnjs (`https://cdnjs.cloudflare.com`), operated by Cloudflare, with a subresource-integrity check. cdnjs receives standard request data such as your IP address and browser user agent. [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/)

**Blockchain:** your browser wallet communicates with the Base network through the RPC provider configured in your wallet. Transactions on Base are public and permanent.

== Frequently Asked Questions ==

= Is this a payment gateway for my checkout? =

No. It does not touch checkout or customer payments. It is for paying people *from* your store's wallet after sales occur.

= What is Spraay? =

Spraay is a batch-payment protocol that sends tokens to many recipients in one on-chain transaction. This plugin connects your WooCommerce admin to the Spraay gateway, which prepares and coordinates those transactions.

= Is it custodial? =

No. Spraay never holds your funds and this plugin never has access to your private keys. You sign every batch yourself in your browser wallet, and USDC moves directly from your wallet to your recipients.

= What are the fees? =

Spraay charges a 0.3% protocol fee on each batch, shown on the review screen before you sign. You also pay the Base network gas fee (in ETH) from your wallet. The plugin itself is free.

= Which network and token? =

USDC on Base (chain ID 8453). Base Sepolia testnet is available in Settings for testing.

= What do I need in my wallet? =

Enough USDC on Base to cover the payouts plus the 0.3% fee, and a small amount of ETH on Base for gas.

= Can a payout be reversed? =

No. On-chain payments are final. Double-check wallet addresses before you sign.

= Do I need a multi-vendor plugin? =

No. WooCommerce is the only requirement. If WooCommerce Product Vendors or Dokan is active, their commission data becomes an additional import source.

= What happens to my data when I uninstall? =

Uninstalling (deleting) the plugin removes its tables, including payout history, and its settings. Deactivating keeps everything. To keep data on uninstall, add `define( 'SPRAAY_KEEP_DATA_ON_UNINSTALL', true );` to wp-config.php first.

== Screenshots ==

1. Dashboard with payout totals and recent batches.
2. Recipient management with CSV import.
3. Batch builder with WooCommerce obligations imported.
4. Review screen showing recipients, Spraay fee and total.
5. Batch history with BaseScan transaction links.

== Changelog ==

= 0.1.0 =
* Initial release: recipients, batch builder, gateway estimation and execution, wallet signing, reconciliation, batch history and CSV import/export.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
