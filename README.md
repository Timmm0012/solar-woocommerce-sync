# solar-woocommerce-sync

A PHP CLI script that syncs products from the Solar procurement API into WooCommerce via their official APIs.

## What it does

- Fetches products from a Solar catalog (paginated, 1000 per page)
- Retrieves net/list prices per product and applies a configurable markup
- Creates new products in WooCommerce as drafts with full product data (name, description, brand, GTIN, weight, images, ETIM attributes)
- Updates existing products with the latest price
- Handles duplicate SKUs by looking up the existing WooCommerce ID
- Saves progress to `state.json` so interrupted runs can be resumed
- Caches SKU → WooCommerce ID mappings in `sku_cache.json` to avoid redundant lookups

## Requirements

- PHP 8.1+ with the `curl` extension
- A Solar procurement API account (client credentials)
- A WooCommerce store with REST API keys

## Installation

```bash
git clone https://github.com/your-org/solar-woocommerce-sync.git
cd solar-woocommerce-sync
cp .env.example .env
# fill in your credentials in .env
```

## Configuration

Copy `.env.example` to `.env` and fill in all values:

| Variable | Description |
|---|---|
| `CLIENT_ID` | Solar OAuth2 client ID |
| `CLIENT_SECRET` | Solar OAuth2 client secret |
| `ACCOUNT_ID` | Solar account ID (used for price lookups) |
| `COUNTRY_CODE` | Country code for catalog and pricing (e.g. `NL`) |
| `CATALOG_ID` | Solar catalog ID to sync |
| `WC_URL` | WooCommerce store URL (e.g. `https://myshop.nl`) |
| `WC_KEY` | WooCommerce REST API consumer key |
| `WC_SECRET` | WooCommerce REST API consumer secret |
| `MARKUP_PCT` | Markup percentage applied to the Solar price (default: `50`) |
| `PRICE_TYPE` | Price type to use: `NET` or `LIST` (default: `NET`) |
| `PRICE_FIELD` | WooCommerce price field: `regular` or `sale` (default: `regular`) |

## Usage

```bash
# Normal run (resumes from state.json if a previous run was interrupted)
php sync.php

# Start fresh (clears state, cache, and log)
php sync.php --reset

# Dump all raw fields of the first Solar product (for debugging)
php sync.php --dump
```

## Output files

| File | Description |
|---|---|
| `state.json` | Sync progress — deleted automatically on successful completion |
| `sku_cache.json` | SKU → WooCommerce product ID cache |
| `sync.log` | Full log of the last run |

## How syncing works

1. **Token** — fetches a short-lived OAuth2 token from Solar (retries up to 3 times)
2. **Products** — pages through the Solar catalog; skips products with status `40` (inactive) or missing SKU/SAP number
3. **Prices** — fetches prices in batches of 50 SAP numbers; applies `MARKUP_PCT`
4. **WooCommerce** — for each product with a price:
   - If the SKU is not in the cache → **create** (draft)
   - If the SKU is already in the cache → **update** price only
   - If a create returns a duplicate-SKU error → looks up the existing ID and updates instead
5. **State** is saved after every page so the run can be safely interrupted and resumed

If more than 3 consecutive pages fail, the script stops and can be resumed with a plain `php sync.php`.
