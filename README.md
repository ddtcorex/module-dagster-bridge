# DDTCoreX_DagsterBridge

Optional Magento 2 REST endpoints for
[dagster-magento](https://github.com/ddtcorex/dagster-magento).

The library works without this module, through Magento's own REST API. This
module exists for the parts where that API is expensive or wrong for an ETL
run: reading the product index and store-scoped attribute values in bulk, and
upserting category paths. Its endpoints are read mostly, go straight to SQL
where Magento offers no other way, and are declared as service contracts with
their own ACL resources so an integration token can be scoped to `read`.

A client probes `GET /V1/dagster-bridge/capabilities` once per run and falls
back to plain REST for every capability the answer does not list, so an older
module degrades per feature instead of failing.

## Requirements

- Magento Open Source 2.4.6 or newer. The read endpoints are Open Source
  only: Adobe Commerce with content staging keeps one product row per staged
  version (`row_id`), and the index and attribute value queries would answer
  one row per version. Commerce content staging is not supported.
- PHP 8.1 to 8.5, as the Magento release allows. The Composer constraints
  admit the Magento 2.4 components from 2.4.6 on (`magento/framework`
  `~103.0.6`).

## Endpoints

| Route | Method | ACL | Purpose |
| --- | --- | --- | --- |
| `/V1/dagster-bridge/capabilities` | GET | `DDTCoreX_DagsterBridge::read` | Module version and the capabilities this release exposes |
| `/V1/dagster-bridge/products/index` | GET | `DDTCoreX_DagsterBridge::read` | `entity_id`, `sku`, `type_id`, `attribute_set_id`, store 0 `status` and `updated_at` for every product, keyset paginated with `after` and `limit` (default 5000, max 20000) |
| `/V1/dagster-bridge/products/attribute-values` | POST | `DDTCoreX_DagsterBridge::read` | Body `{skus, attribute_codes, store_id}`; one item per pair with `store_value` and `default_value` (1 to 1000 SKUs and 1 to 50 codes per call, `store_id` must name an existing store) |
| `/V1/dagster-bridge/categories/upsert` | POST | `DDTCoreX_DagsterBridge::write` | Body `{paths, root, separator}`; creates the missing categories and answers every requested path with its id, in one transaction (`separator` must not be empty) |

`products/attribute-values` reads a static attribute from its column of
`catalog_product_entity` and any other attribute from its standard EAV value
table (`catalog_product_entity_{int,decimal,varchar,text,datetime}`). A code
that fits neither, such as `category_ids` and `media_gallery` (static but no
column) or `tier_price` (its values live in a price table of their own),
answers 400 naming every such code, as an unknown code does.
SKUs match case-insensitively, as the database collation does, and every
item answers under the SKU spelling the caller sent; ask for the `sku` code to
read the stored spelling.

A null value is absent from the JSON, because Magento's serializer drops null
keys: a client reads `store_value` and `default_value` as missing rather than
null. `categories/upsert` needs the write resource and is the only endpoint of
this module that changes anything.

### Category upsert semantics

Every path goes through Magento's own CatalogImportExport category processor,
so the tree is built exactly as a native product import builds it:

- Names match case-insensitively: `men/shirts` reuses an existing
  `Men/Shirts`.
- An existing category is reused whether it is active or not, so an inactive
  sibling is never duplicated.
- New categories are created active, in the menu, and written at the admin
  store (store 0), whatever store code the request URL carries.
- The `root` must already be a tree root (a child of the invisible root,
  matched case-insensitively); an unknown root answers 400 instead of being
  created.
- A `/` inside a name is allowed: pick another `separator` (for example `|`)
  and the module escapes the slash as the processor's own `\/` quoting. A
  name that ends with a backslash is rejected with 400.
- Calls are serialised by the named lock `dagster_bridge_category_upsert`
  (Magento's configured lock provider), so two concurrent calls that create
  the same new path create it once and answer the same ids. A call that
  cannot get the lock within 15 seconds answers 503 and can be retried.

## Access control

The capabilities probe, the product index and the attribute values sit behind
`DDTCoreX_DagsterBridge::read`; the category upsert sits behind
`DDTCoreX_DagsterBridge::write`. Both resources are children of
`Magento_Backend::admin`, so a role can grant exactly one of them.

Create a Magento integration (System > Extensions > Integrations) with the read
resource, or with a role that carries it, for a run that only reads. Add the
write resource when the run also creates categories, and give the integration
nothing else. The library never needs more than these two resources, and an
admin token carries both already.

## Install

Once 1.0.0 is tagged (no release exists yet, so this constraint does not
resolve today):

```bash
composer config repositories.dagster-bridge vcs https://github.com/ddtcorex/module-dagster-bridge
composer require ddtcorex/module-dagster-bridge:^1.0
bin/magento module:enable DDTCoreX_DagsterBridge
bin/magento setup:upgrade
bin/magento cache:flush
```

Until then, from the default branch:

```bash
composer config repositories.dagster-bridge vcs https://github.com/ddtcorex/module-dagster-bridge
composer require ddtcorex/module-dagster-bridge:dev-master
```

An explicit `dev-master` constraint is enough on its own: Composer allows the
dev stability for a package the root requires by branch name, so the store's
`minimum-stability` can stay `stable` (checked with a dry-run `composer
require` against a 2.4.9 project). Then run the same three `bin/magento`
commands.

For a checkout that is not installed through Composer, place it at
`app/code/DDTCoreX/DagsterBridge` and run the same three `bin/magento`
commands.

## Development

```bash
composer install
composer phpcs      # Magento2 standard
composer phpstan    # level 6
composer test       # PHPUnit
```

`composer install` resolves the `magento/*` packages from the Mage-OS mirror
declared in this module's `repositories` block, because Packagist does not
host them. Composer reads `repositories` from the root package only, so the
block matters for developing this module and is ignored when a store requires
it. CI also runs the whole gate with `--prefer-lowest` on PHP 8.1, which
installs the Magento 2.4.6 components (`magento/framework` 103.0.6).

The unit tests need no Magento installation: they cover the SQL builders and
the path parser, and mock everything that would touch the application.

## License

MIT. See [LICENSE](LICENSE).
