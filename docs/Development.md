# Development

The module is developed as a standalone Composer package: `composer install` in a checkout pulls the Magento framework and modules it compiles against, and three Composer scripts run the coding standard, static analysis and the unit tests without any Magento installation. GitHub Actions runs the same gate on PHP 8.1 to 8.5 plus a lowest-dependencies job, and pushing a `vX.Y.Z` tag turns the matching CHANGELOG section into a GitHub Release. This page covers the local setup, what the tests do and do not prove, CI, the release procedure and the repository layout.

## Setup

```bash
git clone https://github.com/ddtcorex/module-dagster-bridge.git
cd module-dagster-bridge
composer install
```

`composer install` resolves the `magento/*` packages from the Mage-OS mirror declared in the module's own `composer.json`:

```json
"repositories": [
    {"type": "composer", "url": "https://mirror.mage-os.org/"}
]
```

Packagist does not host them. Composer reads `repositories` from the root package only, so this block matters when the module is the root (developing it) and is ignored when a store requires the module.

`composer.lock` is gitignored: the package is a library, and CI resolves fresh on every run.

## The three scripts

| Script | Command | What it checks |
| --- | --- | --- |
| `composer phpcs` | `phpcs` | Magento2 coding standard (`phpcs.xml.dist`), whole tree except `vendor`, `.phpstan.cache`, `.phpunit.cache` |
| `composer phpstan` | `phpstan analyse --no-progress` | PHPStan level 6 over `Api`, `Model`, `Test` (`phpstan.neon.dist`), cache in `.phpstan.cache` |
| `composer test` | `phpunit -c Test/Unit/phpunit.xml.dist` | Unit tests under `Test/Unit/Model` |

## What the unit tests cover

The tests run without a Magento installation: `Test/Unit/bootstrap.php` loads Composer's autoloader and every application service is mocked.

| Test class | Pins |
| --- | --- |
| `CategoryPathParserTest` | root prepended, caller separator, slashes kept in names, empty levels and whitespace dropped, root recognised case-insensitively |
| `ResourceModel/ProductIndexQueryTest` | `entity_id > after`, ascending order, status join on the link field and store 0, link field taken from the metadata pool |
| `ResourceModel/AttributeValuesQueryTest` | unsupported codes (static non-column, non-scalar backend, custom backend table), value table resolution, store and default rows, one query per backend type, caller values bound through adapter quoting |
| `ProductIndexTest` | limit bounds, `next_after` on full, short and empty pages |
| `AttributeValuesTest` | SKU and code caps, empty lists, unknown store, unknown and unsupported codes listed, one item per pair, store versus default value, static attributes, case-insensitive SKU |
| `CategoryUpsertTest` | native processor use, slash escaping, trailing backslash, root validation at store 0, rollback on `Exception` and `Error`, lock name, timeout bound, release on failure, 503 on lock timeout, empty separator |
| `CapabilitiesTest` | version and capability list, version equals the top CHANGELOG heading, `composer.json` has no `version`, result keys |

### What they cannot prove

- **Real SQL.** The query builders are tested by inspecting the `Select` they build. Most of those tests use a quote double that pastes values in unescaped, so they pin the query's shape, not its safety. One test binds hostile SKUs through a double that escapes like MySQL string literals. Injection safety rests on two facts the code keeps: every caller value reaches SQL through the adapter's `quoteInto` (bound `?` placeholders), and every identifier is a table or column name the module resolves itself (attribute metadata, the metadata pool, the checked product columns), never caller text.
- **The native category processor, the lock provider, the transaction, the web API layer** (routing, ACL, serialization, null dropping, HTTP status mapping). These are mocked. They are covered only by the live suite of the dagster-magento library on real Magento installs (see [Compatibility](Compatibility)).

## CI

`.github/workflows/ci.yml`, on every push and pull request (concurrent runs of the same ref are cancelled):

| Job | PHP | Install | Steps |
| --- | --- | --- | --- |
| `php 8.1` ... `php 8.5` (matrix, fail-fast off) | 8.1, 8.2, 8.3, 8.4, 8.5 | `composer install --prefer-dist` | phpcs, phpstan, phpunit |
| `php 8.1, lowest dependencies` | 8.1 | `composer update --prefer-lowest --prefer-stable` (Magento 2.4.6 components, framework 103.0.6) | phpcs, phpstan, phpunit |
| `docs` | none | none | `scripts/check-docs.sh docs`: `Home.md` exists, no broken link between pages |

## Release procedure

1. Move the `[Unreleased]` entries of `CHANGELOG.md` under a new heading `## [X.Y.Z] - YYYY-MM-DD` (Keep a Changelog format, Semantic Versioning).
2. Set `Model/Capabilities.php` `VERSION` to `X.Y.Z`. `CapabilitiesTest` fails when the constant and the top release heading disagree. Do not add a `version` field to `composer.json` (another test forbids it).
3. Commit, then tag the release commit `vX.Y.Z` and push the tag.
4. `.github/workflows/release.yml` runs on the `v*.*.*` tag. It checks the tag against `Capabilities::VERSION` (fails on mismatch), extracts the `[X.Y.Z]` section of `CHANGELOG.md` (fails when there is none), and creates the GitHub Release with that section as the body plus generated notes. Nothing is published to a registry; Composer users resolve the tag through the VCS repository.

A tag pushed before the workflow existed can be released with the workflow's manual `workflow_dispatch` trigger, input `tag`.

## Repository layout

```
Api/                        service contracts (@api)
  CapabilitiesInterface.php
  ProductIndexInterface.php       DEFAULT_LIMIT, MAX_LIMIT
  AttributeValuesInterface.php    MAX_SKUS, MAX_ATTRIBUTE_CODES
  CategoryUpsertInterface.php     DEFAULT_ROOT
  Data/                     response data interfaces
Model/                      implementations
  Capabilities.php                VERSION, capability list
  ProductIndex.php
  AttributeValues.php
  CategoryUpsert.php              LOCK_NAME, LOCK_TIMEOUT
  CategoryPathParser.php
  CategoryProcessorFactory.php    fresh native processor per call
  Data/                     data objects and their factories
  ResourceModel/            pure SQL builders (never execute)
etc/
  acl.xml  di.xml  module.xml  webapi.xml
Test/Unit/                  PHPUnit, bootstrap, phpunit.xml.dist
.github/workflows/          ci.yml, release.yml, sync-wiki.yml
docs/                       the wiki pages (flat markdown), published by sync-wiki.yml
scripts/check-docs.sh       link check run by CI and by sync-wiki.yml
CHANGELOG.md  README.md  LICENSE  composer.json  registration.php
phpcs.xml.dist  phpstan.neon.dist
```

Do not develop inside a Magento store's `app/code` with `vendor/` and caches present: Magento scans every PHP file there and `setup:di:compile` fails on them (see [Troubleshooting](Troubleshooting)).

## See also

- [Compatibility](Compatibility)
- [Capabilities](Capabilities)
- [Installation](Installation)
- [Troubleshooting](Troubleshooting)
- dagster-magento library wiki: https://github.com/ddtcorex/dagster-magento/wiki
