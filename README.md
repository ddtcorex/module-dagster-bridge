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

Later releases add `products/index`, `products/attribute-values` and
`categories/upsert`; each one appears in the capabilities answer once it is
finished.

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
