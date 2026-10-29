# Spraay Gateway API Spec — For WooCommerce Plugin Gateway Client

Use this to replace the guessed request/response formats in `includes/class-spraay-gateway-client.php`.

## Base URL

`https://gateway.spraay.app`

---

## 1. Estimate a Batch

Two options — free tier or paid:

### Free Tier (no auth required)
```
POST /free/estimate-batch
```

### Paid Tier
```
POST /api/v1/batch/estimate
```

### Request Body

Full estimate (with recipients):
```json
{
  "token": "0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48",
  "sender": "0xMerchantWalletAddress",
  "recipients": [
    "0xRecipientA",
    "0xRecipientB",
    "0xRecipientC"
  ],
  "amounts": [
    "250000000",
    "400000000",
    "175000000"
  ]
}
```

Count-only estimate (for quick fee preview):
```json
{
  "recipientCount": 3
}
```

### CRITICAL: Amount Format

Batch endpoints use **RAW BASE UNITS**, not human decimals.

- USDC has 6 decimals
- 1 USDC = `"1000000"`
- 250 USDC = `"250000000"`
- 0.50 USDC = `"500000"`

These are STRING values, not numbers.

**This is the opposite of payroll/invoice endpoints which use human decimals.** Do not mix them up.

### Key Fields

- `recipients` and `amounts` are **parallel arrays** (same length, same order) — NOT an array of objects
- `token` is the ERC-20 contract address (USDC on Base: `0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913`)
- `sender` is the merchant's wallet address
- Optional `chain` param: defaults to `"base"`, also accepts `"peaq"`. Any other value returns 400 with `["base","peaq"]` in the error.

### Response

```json
{
  "estimatedFee": "2475000",
  "estimatedTotal": "827475000",
  "recipientCount": 3,
  "suggestedGasLimit": "350000",
  "supported": true,
  "supportedChains": ["base", "peaq"]
}
```

- `suggestedGasLimit` is a **decimal string** (not hex)
- `estimatedFee` and `estimatedTotal` are raw base units (strings)
- `supported` = true means the chain can actually settle (not just validate)
- The free tier `/free/estimate-batch` returns values from a floor formula (kept RPC-free); the paid tier uses live `eth_estimateGas` × 1.5

---

## 2. Execute a Batch

```
POST /api/v1/batch/execute
```

This is a **paid endpoint** (x402 payment required — the gateway charges via x402 before processing).

### Request Body

```json
{
  "token": "0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913",
  "sender": "0xMerchantWalletAddress",
  "recipients": [
    "0xRecipientA",
    "0xRecipientB",
    "0xRecipientC"
  ],
  "amounts": [
    "250000000",
    "400000000",
    "175000000"
  ]
}
```

Same parallel-arrays format as estimate. Same raw base unit strings.

Optional `chain` param: `"base"` (default) or `"peaq"`. Hard allowlist — anything else returns 400.

### Response

The gateway returns an **unsigned transaction** for the merchant's wallet to sign and broadcast — NOT a pre-broadcast result.

```json
{
  "transaction": {
    "to": "0x1646452F98E36A3c9Cfc3eDD8868221E207B5eEC",
    "data": "0x...",
    "value": "0",
    "chainId": 8453,
    "gasLimit": "0x55730"
  },
  "approvalRequired": {
    "token": "0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913",
    "spender": "0x1646452F98E36A3c9Cfc3eDD8868221E207B5eEC",
    "amount": "827475000",
    "amountFormatted": "827.475"
  },
  "batch": {
    "recipientCount": 3,
    "totalAmount": "825000000",
    "fee": "2475000",
    "totalWithFee": "827475000"
  }
}
```

### Key Details

- `transaction.to` is always the Spraay batch contract: `0x1646452F98E36A3c9Cfc3eDD8868221E207B5eEC` (Base)
- `transaction.chainId` will be `8453` (Base) or `3338` (peaq)
- `transaction.value` is `"0"` for ERC-20 batches (non-zero only for ETH batches via sprayETH)
- `transaction.gasLimit` is a **hex string**
- `approvalRequired` is present when the merchant needs to approve USDC spending first
  - `spender` = the Spraay contract address
  - `amount` = total including fee, in raw base units
  - The wallet JS should check if existing allowance covers this; if not, prompt an ERC-20 `approve()` first
  - Approve for EXACTLY the batch total (never unlimited)
- The contract function being called is `sprayToken(address token, Recipient[])` where Recipient is a struct `(address, uint256)`

### Duplicate Payment Guard

If the same sender submits the same batch params twice in quick succession, the gateway returns:

```
HTTP 409
{
  "error": "duplicate_payment_detected"
}
```

The dedupe key includes the route + sender + payment-relevant body fields. This is intentional — handle it gracefully in the plugin (show "batch already submitted" rather than an error).

---

## 3. Batch Status / Reconciliation

**There is no dedicated `/api/v1/batch/{id}/status` polling endpoint on the gateway.**

Reconciliation happens client-side via on-chain verification:

1. After the merchant signs and broadcasts the transaction, the wallet JS gets back a `tx_hash`
2. The plugin stores that `tx_hash` in the `spraay_batches` table
3. To verify confirmation, query the Base RPC directly:
   - `eth_getTransactionReceipt` with the tx_hash
   - Check `status` = `"0x1"` (success)
   - Check the contract logs for the fee event
4. Link to BaseScan for human verification: `https://basescan.org/tx/{tx_hash}`
5. For peaq: use Subscan `https://peaq.subscan.io/tx/{tx_hash}`

The plugin's WP-Cron reconciliation job should poll the RPC for pending batches, not the Spraay gateway.

---

## 4. Supporting Endpoints (Read-Only)

### Validate addresses / check chain support
```
GET /free/validate-batch
```
Validates that addresses are well-formed. Note: `valid: true` does NOT mean the chain can settle — only `estimate.supported` is authoritative for that.

### Token prices
```
GET /free/prices?token=USDC
```
Returns current token price. Useful for showing USD-equivalent values in the admin UI.

### Chain status
```
GET /free/chain-status
```
Returns status of supported chains.

### Token list
```
GET /api/v1/tokens
```
Returns supported tokens per chain.

### Wallet balances
```
GET /api/v1/balances?address=0x...
```
Returns token balances for an address. Useful for checking if the merchant has enough USDC before building a batch.

---

## 5. On-Chain Contract Reference

- **Base batch contract**: `0x1646452F98E36A3c9Cfc3eDD8868221E207B5eEC`
- **peaq batch contract**: same canonical address from peaq deploy
- **Fee**: 30 bps (0.3%) added ON TOP of the batch total — `msg.value` or allowance must cover `total + fee`
- **MAX_RECIPIENTS**: 200 per batch
- **Contract functions the plugin should expect in calldata**:
  - `sprayToken(address token, (address,uint256)[] recipients)` — ERC-20 batch
  - `sprayETH((address,uint256)[] recipients)` — native ETH batch (payable)
  - `sprayEqual(address token, address[] recipients, uint256 amountPerRecipient)` — equal-split (not currently used by gateway but exists on contract)
- **USDC on Base**: `0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913`

## 6. Security: Verify Before Signing

The wallet JS MUST verify the unsigned transaction before prompting the merchant to sign:

1. `transaction.to` must equal the known Spraay contract address
2. `transaction.chainId` must be 8453 (Base) or 3338 (peaq)
3. `transaction.value` must be `"0"` for ERC-20 batches
4. ABI-decode `transaction.data` and confirm the function selector is `sprayToken` or `sprayETH`
5. Match decoded recipients and amounts against what the merchant confirmed in the UI
6. `approvalRequired.spender` must equal the known contract address
7. Derive the expected allowance locally (confirmed total + 0.3% fee) and compare to `approvalRequired.amount` — reject if it exceeds confirmed total + 2% (generous ceiling for rounding)

Fail closed: if any check fails, refuse to sign and show the merchant what didn't match.
