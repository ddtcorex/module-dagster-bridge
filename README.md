# DDTCoreX_DagsterBridge

[![CI](https://github.com/ddtcorex/module-dagster-bridge/actions/workflows/ci.yml/badge.svg)](https://github.com/ddtcorex/module-dagster-bridge/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/ddtcorex/module-dagster-bridge)](https://github.com/ddtcorex/module-dagster-bridge/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Optional Magento 2 REST endpoints for
[dagster-magento](https://github.com/ddtcorex/dagster-magento). The library works
without this module, through Magento's own REST API; the module exists for the
parts where that API is expensive or wrong for an ETL run:

- **Product index.** Every product's id, SKU, type, attribute set, status and
  `updated_at`, keyset paginated.
- **Attribute values.** Store value and default value of chosen attributes for many
  SKUs in one call.
- **Category upsert.** Create the categories a path names, in one transaction.

Each endpoint is a service contract. The three read endpoints share one ACL
resource and the category upsert has its own, so an integration token can be
limited to read access. A client probes
`GET /V1/dagster-bridge/capabilities` once and falls back to plain REST for anything
the answer does not list.

## Requirements

Magento Open Source 2.4.6 or newer, PHP 8.1 to 8.5. Adobe Commerce content staging
is not supported. The Magento patches the module was exercised on are listed under
[Compatibility](https://github.com/ddtcorex/module-dagster-bridge/wiki/Compatibility).

## Install

```bash
composer config repositories.dagster-bridge vcs https://github.com/ddtcorex/module-dagster-bridge
composer require ddtcorex/module-dagster-bridge:^1.0
bin/magento module:enable DDTCoreX_DagsterBridge
bin/magento setup:upgrade
bin/magento cache:flush
```

Then check it answers:

```bash
curl -H "Authorization: Bearer $TOKEN" https://shop.example/rest/V1/dagster-bridge/capabilities
```

## Documentation

The full documentation is in the [wiki](https://github.com/ddtcorex/module-dagster-bridge/wiki):

| | |
| --- | --- |
| [Installation](https://github.com/ddtcorex/module-dagster-bridge/wiki/Installation) | Composer, manual install, upgrade |
| [Endpoints](https://github.com/ddtcorex/module-dagster-bridge/wiki/Endpoints) | every route, parameter and response |
| [Access Control](https://github.com/ddtcorex/module-dagster-bridge/wiki/Access-Control) | the read and write resources |
| [Category Upsert](https://github.com/ddtcorex/module-dagster-bridge/wiki/Category-Upsert) | path format, matching, locking |
| [Troubleshooting](https://github.com/ddtcorex/module-dagster-bridge/wiki/Troubleshooting) | symptoms and fixes |

## Contributing

Pull requests are welcome; see
[Development](https://github.com/ddtcorex/module-dagster-bridge/wiki/Development).
Release notes are in [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
