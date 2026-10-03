# DDTCoreX_DagsterBridge

`DDTCoreX_DagsterBridge` (Composer package `ddtcorex/module-dagster-bridge`, version 1.0.0, MIT) is an optional Magento 2 module that adds four REST endpoints for the [dagster-magento](https://github.com/ddtcorex/dagster-magento) library. The library works without it through Magento's own REST API; the module exists for the few operations where that API is expensive or wrong for an ETL run: listing every product, reading store scoped attribute values in bulk, and creating category paths atomically. Three endpoints only read and share one ACL resource, and the one that writes has its own, so an integration or admin role can be scoped to reading only.

## Why it exists

| Task | Native REST cost | Bridge replacement |
| --- | --- | --- |
| Decide which SKUs exist | Paginated `GET /V1/products` scan, a full product load per item | `GET /V1/dagster-bridge/products/index`: one SQL page of up to 20000 rows with six identity columns |
| Read store scoped values | One `GET /V1/products` per SKU chunk, and the store fallback done by hand | `POST /V1/dagster-bridge/products/attribute-values`: up to 1000 SKUs x 50 codes per call, store value and default value returned separately |
| Create missing categories | One `POST /V1/categories` per missing node, no transaction | `POST /V1/dagster-bridge/categories/upsert`: all paths in one transaction, through Magento's own import category processor |

The "what it replaces" column follows the library wiki page [Optional Bridge](https://github.com/ddtcorex/dagster-magento/wiki/Optional-Bridge). The read endpoints go straight to SQL (service contracts on top of query builders in `Model/ResourceModel/`); the upsert reuses the native `Magento\CatalogImportExport\Model\Import\Product\CategoryProcessor`, so it builds the tree exactly as a native product import does.

## What it is not

- Not required: every capability has a plain REST fallback in the library, chosen per capability after the [capabilities probe](Capabilities).
- Not a general catalog API: it reads identity fields and raw attribute values, and writes only categories. It never writes products.
- Not for Adobe Commerce content staging: the read queries join on the product link field, which is `row_id` with staging, and would return one row per staged version. Staging is declared unsupported.
- No database schema, no configuration, no admin UI, no cron, no indexer of its own. It registers two ACL resources and four routes.

## Endpoint summary

| Route | Method | ACL resource | Purpose |
| --- | --- | --- | --- |
| `/V1/dagster-bridge/capabilities` | GET | `DDTCoreX_DagsterBridge::read` | Module version and capability list |
| `/V1/dagster-bridge/products/index` | GET | `DDTCoreX_DagsterBridge::read` | `entity_id`, `sku`, `type_id`, `attribute_set_id`, store 0 `status`, `updated_at`; keyset paginated (`after`, `limit` default 5000, max 20000) |
| `/V1/dagster-bridge/products/attribute-values` | POST | `DDTCoreX_DagsterBridge::read` | `store_value` and `default_value` per SKU and attribute code (1 to 1000 SKUs, 1 to 50 codes) |
| `/V1/dagster-bridge/categories/upsert` | POST | `DDTCoreX_DagsterBridge::write` | Create missing categories of each path and return every path's id, in one transaction |

Full contracts: [Endpoints](Endpoints).

## Requirements

- Magento Open Source 2.4.6 or newer by Composer constraint (`magento/framework ~103.0.6`). The patches actually run end to end are listed in [Compatibility](Compatibility).
- PHP 8.1 to 8.5, as the Magento release allows.
- Enabled modules (from `etc/module.xml` sequence): `Magento_Backend`, `Magento_Catalog`, `Magento_CatalogImportExport`, `Magento_Eav`, `Magento_Store`.
- An admin user or integration whose role carries the module's ACL resources ([Access Control](Access-Control)).

## Quick links

- [Installation](Installation): Composer or `app/code`, enable, verify.
- [Endpoints](Endpoints): every route, parameter, limit, response and error.
- [Access Control](Access-Control): the `::read` and `::write` resources and least privilege.
- [Category Upsert](Category-Upsert): path syntax, matching, lock, transaction.
- [Product Index](Product-Index): keyset pagination and walking the catalog.
- [Attribute Values](Attribute-Values): store versus default value, refused attributes.
- [Capabilities](Capabilities): version and per capability degradation.
- [Compatibility](Compatibility): verified Magento patches and honest limits.
- [Development](Development): tests, CI, release procedure.
- [Troubleshooting](Troubleshooting): symptom, cause, fix.

## See also

- [Installation](Installation)
- [Endpoints](Endpoints)
- [Compatibility](Compatibility)
- dagster-magento library wiki: https://github.com/ddtcorex/dagster-magento/wiki
