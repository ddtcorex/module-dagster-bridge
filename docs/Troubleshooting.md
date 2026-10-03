# Troubleshooting

Symptoms you may hit when installing or calling the module, each with its cause and fix. Every entry is grounded in the module source, its CHANGELOG or README, or in how the dagster-magento client calls it. Error messages are shown as Magento returns them: the raw text with `%1`-style placeholders, values in `parameters`.

## Capabilities call answers 404

**Cause:** Magento does not know the route. The module is not installed, not enabled, or the config cache still holds the old route list.

**Fix:**

```bash
bin/magento module:status DDTCoreX_DagsterBridge
bin/magento module:enable DDTCoreX_DagsterBridge
bin/magento setup:upgrade
bin/magento cache:flush
```

Check the URL too: `/rest/V1/dagster-bridge/capabilities` (or with a store code, `/rest/all/V1/...`). The dagster-magento client treats a 404 as "no bridge" and continues on plain REST, so with `use_bridge="auto"` this shows up only as a warning.

## 401 on a bridge route

**Cause:** the token is missing or expired, or its role lacks the route's ACL resource. Magento answers 401 for both.

| Route | Resource needed |
| --- | --- |
| capabilities, products/index, products/attribute-values | `DDTCoreX_DagsterBridge::read` |
| categories/upsert | `DDTCoreX_DagsterBridge::write` |

`::write` does not imply `::read`. **Fix:** add the resource to the admin role or integration ([Access Control](Access-Control.md)), then request a new token. If an integration token is rejected outright, check that integration tokens are allowed as standalone bearer tokens (Stores > Configuration > Services > OAuth > Consumer Settings).

## 400 "Unknown attribute codes: %1."

**Cause:** one or more `attribute_codes` are not product attributes (typo, attribute of another entity type, attribute deleted). All unknown codes are listed in `parameters`. **Fix:** correct or drop them; nothing was read.

## 400 "Attribute codes this endpoint cannot read: %1. ..."

**Cause:** the attribute exists but its values are not where the endpoint reads: a static attribute that is not a column of `catalog_product_entity` (`category_ids`, `media_gallery`), or values in a table of their own or behind a non-scalar backend (`tier_price`). **Fix:** read those through native REST. See [Attribute Values](Attribute-Values.md).

## 400 "At least one SKU is required." / "At least one attribute code is required."

**Cause:** an empty `skus` or `attribute_codes` list. **Fix:** skip the call when there is nothing to ask. (An empty `paths` list on the upsert is not an error: it answers `[]`.)

## 400 "At most %1 SKUs ..." / "At most %1 attribute codes ..."

**Cause:** more than 1000 distinct SKUs or 50 distinct codes. **Fix:** chunk (1000 SKUs x 50 codes per call), as the dagster-magento client does.

## 400 "Store %1 does not exist."

**Cause:** `store_id` names no store. **Fix:** use a numeric store view id (`GET /V1/store/storeViews` lists them) or `0` for the default scope.

## 400 "The separator must not be empty."

**Cause:** `"separator": ""` in a category upsert. **Fix:** omit it (default `/`) or pick a character that appears in no name.

## 400 "The root category "%1" does not exist."

**Cause:** `root` is not the name of an existing tree root (a child of category id 1). The module refuses to create a new root. **Fix:** use the exact existing root name (case does not matter), for example `Default Category`.

## 400 "Category name "%1" in path "%2" ends with a backslash, ..."

**Cause:** the native processor quotes `/` as `\/`, so a trailing backslash cannot be stored. **Fix:** rename the source value.

## 400 "None of the supported path delimiters is free of the requested names."

**Cause:** across all paths of one call, every one of `,` `;` `|` `~` `^` appears somewhere. **Fix:** split the call so each batch leaves at least one of them unused.

## 400 "Category path "%1" could not be created: %2"

**Cause:** an exception inside the upsert transaction; `%2` carries Magento's original message. The whole call was rolled back. **Fix:** read `%2`, correct the data, resend. The rollback means a retry starts from a clean state.

## 503 "Another category upsert is still running after %1 seconds; retry the call later."

**Cause:** another upsert held the named lock `dagster_bridge_category_upsert` for more than 15 seconds. Nothing was done. **Fix:** retry later, or make concurrent runs send smaller upserts. The dagster-magento client retries POST only on 429, so it does not retry this: in `auto` mode it creates that call's categories through native REST instead, in `require` mode it raises. If 503s persist with no concurrent run, check the lock backend configured in `app/etc/env.php`; only the database lock provider was tested.

## Product index or attribute values come back empty

| Symptom | Cause | Fix |
| --- | --- | --- |
| `"items": []`, no `next_after` | `after` is at or above the highest `entity_id`, or the catalog is empty | start at `after=0` |
| Items for a SKU have no `store_value` and no `default_value` | the SKU matches no product, or the product has no value | check existence with the [Product Index](Product-Index.md) |
| `store_value` missing but `default_value` present | the store has no override; Magento would show the default | apply the fallback: store value if present, else default |
| `status` missing from an index item | no store 0 status row | read `status` per store through attribute values |
| A value you expect is missing in a client | null keys are dropped from JSON | read with a default, never `item["store_value"]` |

## setup:di:compile fails after installing in app/code

**Cause:** a development checkout was copied or cloned into `app/code/DDTCoreX/DagsterBridge` with its `vendor/` directory and analysis caches (`.phpstan.cache`, `.phpunit.cache`, `.phpcs-cache`). Magento scans every PHP file under `app/code`, and compilation fails on them (for example "Phar wrapper is not registered" out of PHPStan's cache), which blocks production mode. **Fix:** delete those directories from the installed copy, or install through Composer, then rerun `setup:di:compile`.

## New categories do not show on the storefront yet

**Cause:** the module creates categories through the native import processor at store 0, active and in the menu, and commits them; it does not run any indexer itself. With indexers set to "Update by Schedule", category and URL related indexes catch up only when cron runs, and full page cache may still serve old pages. **Fix:** make sure cron runs (`bin/magento indexer:status` to check), or reindex the affected indexers, and refresh the cache.

## composer install in a module checkout cannot find magento/* packages

**Cause:** Packagist does not host `magento/*`. The module's `composer.json` declares the Mage-OS mirror (`https://mirror.mage-os.org/`) for that, but only when the module is the root package. **Fix:** run `composer install` from the module's own directory (not from a parent project that lacks a Magento repository), and make sure the mirror is reachable. In a store, `magento/*` come from the store's own repository configuration (repo.magento.com or a mirror), not from the module.

## composer require cannot resolve ddtcorex/module-dagster-bridge

**Cause:** the VCS repository is not configured in the store's root `composer.json`. **Fix:**

```bash
composer config repositories.dagster-bridge vcs https://github.com/ddtcorex/module-dagster-bridge
composer require ddtcorex/module-dagster-bridge:^1.0
```

If `^1.0` does not resolve in your environment, `dev-master` installs the default branch.

## See also

- [Installation](Installation.md)
- [Endpoints](Endpoints.md)
- [Access Control](Access-Control.md)
- [Category Upsert](Category-Upsert.md)
- [Attribute Values](Attribute-Values.md)
- dagster-magento library wiki: https://github.com/ddtcorex/dagster-magento/wiki
