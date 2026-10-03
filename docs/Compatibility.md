# Compatibility

Module 1.0.0 was exercised live through the dagster-magento test suite on a fresh sandbox of each Magento patch in the table below, with the module enabled. The suite runs the catalog import and the category tests once with `use_bridge="never"` and once with `"require"` (so a missing capability fails the run instead of falling back); the rest of the 15 live tests do not use the bridge. It covers the category upsert, store scoped attribute values and the product index through the catalog import, in sync and bulk mode, plus the case-insensitive category path rule. The table is generated in the library repository from the per version records under its `compat/results/`, and copied here unchanged. Beyond it, the Composer constraints admit Magento 2.4.6 and later components and PHP 8.1 to 8.5, and CI checks the lowest admitted dependencies.

## Verified matrix

This table is copied from the [dagster-magento Compatibility page](https://github.com/ddtcorex/dagster-magento/wiki/Compatibility), where it is generated from the per version records. Refresh it from there when a release changes what is verified.

| Version | Magento patch | PHP | Database | Search | Bridge | Date | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 2.4.6-p15 | 2.4.6-p15 | 8.2.26 | MariaDB 10.11.18 | elasticsearch 7.17.28 | 1.0.0 | 2026-10-03 | verified (15 of 15 passed) |
| 2.4.7-p10 | - | - | - | - | - | 2026-09-30 | not provisioned: Composer security blocking refused a dependency of 2.4.7-p10 and govard bootstrap cannot disable it |
| 2.4.8-p5 | 2.4.8-p5 | 8.4.1 | MariaDB 11.4.10 | opensearch 3.0 | 1.0.0 | 2026-10-02 | verified (15 of 15 passed) |
| 2.4.9 | 2.4.9 | 8.5.9 | MariaDB 11.8.8 | opensearch 3.0 | 1.0.0 | 2026-10-02 | verified (15 of 15 passed) |

The Result column counts the whole library live suite, not only the module. The current table always lives on the library wiki: https://github.com/ddtcorex/dagster-magento/wiki/Compatibility

## Composer constraints

From the module's `composer.json`:

| Package | Constraint | Meaning |
| --- | --- | --- |
| `php` | `~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0` | PHP 8.1 to 8.5, as the Magento release allows |
| `magento/framework` | `~103.0.6` | Magento 2.4.6 and later 2.4 lines |
| `magento/module-backend` | `~102.0.6` | |
| `magento/module-catalog` | `~104.0.6` | |
| `magento/module-catalog-import-export` | `~101.1.6` | |
| `magento/module-eav` | `~102.1.6` | |
| `magento/module-store` | `~101.1.6` | |

Development only: `magento/magento-coding-standard ^41`, `phpstan/phpstan ^1.12`, `phpunit/phpunit ^10.5 || ^11.5 || ^12.0`.

## PHP 8.1 to 8.5

CI runs the coding standard, PHPStan and the unit tests on PHP 8.1, 8.2, 8.3, 8.4 and 8.5. Which PHP a store can use is decided by its Magento release, not by the module.

## Lowest dependencies job

A separate CI job, "php 8.1, lowest dependencies", runs `composer update --prefer-lowest --prefer-stable` on PHP 8.1 and then the same three checks. It installs the lower bound of every constraint, the Magento 2.4.6 components (`magento/framework` 103.0.6), so the code is statically checked and unit tested against the oldest framework it claims. This is a compile and unit level check, not a live run.

## Honest limits

- **2.4.7 is not verified.** It could not be provisioned in the test sandbox (Composer's security blocking refused one of its dependencies), so it is neither claimed nor known to fail.
- **Rows are patches, not ranges.** A newer patch of a verified line is expected to behave the same but is unverified until the matrix is rerun.
- **Intermittent bulk test.** The library's bulk catalog test was intermittent on 2.4.6-p15 and 2.4.8-p5 (a failed record followed by passing reruns, unexplained) and never failed on 2.4.9. This concerns Magento's async bulk path used by the library, not a module endpoint.
- **Adobe Commerce content staging is unsupported.** The read queries join through the product link field, which is `row_id` with staging, and would answer one row per staged version. The module targets Magento Open Source.
- **Lock backends other than the database are untested.** The category upsert uses Magento's configured lock provider through `LockManagerInterface`; only the default database provider was exercised.
- **Production mode error masking was not exercised for every path.** The module raises its validation errors as `InputException` and the lock timeout as a web API exception with status 503, but how every failure path renders under production mode (where Magento masks unexpected errors) was not checked.
- **Search engine and database** values in the table are what the sandbox ran; the module itself does not use the search engine.

## See also

- [Home](Home)
- [Installation](Installation)
- [Development](Development)
- [Product Index](Product-Index)
- dagster-magento library wiki: https://github.com/ddtcorex/dagster-magento/wiki
