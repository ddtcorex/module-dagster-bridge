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

- Magento Open Source 2.4.6 or newer.
- PHP 8.1 to 8.5.

## Endpoints

| Route | Method | ACL | Purpose |
| --- | --- | --- | --- |
| `/V1/dagster-bridge/capabilities` | GET | `DDTCoreX_DagsterBridge::read` | Module version and the capabilities this release exposes |
| `/V1/dagster-bridge/products/index` | GET | `DDTCoreX_DagsterBridge::read` | `entity_id`, `sku`, `type_id`, `attribute_set_id`, store 0 `status` and `updated_at` for every product, keyset paginated with `after` and `limit` (default 5000, max 20000) |
| `/V1/dagster-bridge/products/attribute-values` | POST | `DDTCoreX_DagsterBridge::read` | Body `{skus, attribute_codes, store_id}`; one item per pair with `store_value` and `default_value` (at most 1000 SKUs and 50 codes per call) |
| `/V1/dagster-bridge/categories/upsert` | POST | `DDTCoreX_DagsterBridge::write` | Body `{paths, root, separator}`; creates the missing categories and answers every requested path with its id, in one transaction |

A null value is absent from the JSON, because Magento's serializer drops null
keys: a client reads `store_value` and `default_value` as missing rather than
null. `categories/upsert` needs the write resource and is the only endpoint of
this module that changes anything.

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

```bash
composer config repositories.dagster-bridge vcs https://github.com/ddtcorex/module-dagster-bridge
composer require ddtcorex/module-dagster-bridge:^1.0
bin/magento module:enable DDTCoreX_DagsterBridge
bin/magento setup:upgrade
bin/magento cache:flush
```

For a checkout that is not installed through Composer, place it at
`app/code/DDTCoreX/DagsterBridge` and run the same three `bin/magento`
commands.

Create an integration with the `Dagster Bridge: read catalog data` resource,
or a role that carries it, and use that integration's token for the library.

## Development

```bash
composer install
composer phpcs      # Magento2 standard
composer phpstan    # level 6 with bitexpert/phpstan-magento
composer test       # PHPUnit
```

The unit tests need no Magento installation: they cover the SQL builders and
the path parser, and mock everything that would touch the application.

## License

MIT. See [LICENSE](LICENSE).
