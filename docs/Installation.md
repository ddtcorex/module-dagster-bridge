# Installation

The module installs like any Magento 2 module: through Composer from its GitHub repository, or as a plain copy under `app/code`. After either, enable it, run `setup:upgrade` and flush the cache, then confirm it answers by calling the capabilities endpoint with a bearer token. The module has no database schema and no configuration, so upgrading and removing it are short.

## Option 1: Composer (recommended)

The package `ddtcorex/module-dagster-bridge` is resolved from its VCS repository, so add the repository to the store's root `composer.json` first:

```bash
composer config repositories.dagster-bridge vcs https://github.com/ddtcorex/module-dagster-bridge
composer require ddtcorex/module-dagster-bridge:^1.0
```

The version comes from the Git tag (`v1.0.0`); `composer.json` deliberately carries no `version` field, because Composer ignores a VCS tag that disagrees with it.

To track the default branch instead of a release:

```bash
composer require ddtcorex/module-dagster-bridge:dev-master
```

An explicit `dev-master` constraint is enough on its own: Composer allows dev stability for a package the root requires by branch name, so the store's `minimum-stability` can stay `stable`.

The module's own `composer.json` declares a `repositories` block pointing at the Mage-OS mirror. That block is only for developing the module itself: Composer reads `repositories` from the root package only, so it is ignored when a store requires the module. Your store resolves `magento/*` packages from whatever repository it already uses.

## Option 2: manual copy into app/code

Place the module so that `registration.php` sits at:

```
app/code/DDTCoreX/DagsterBridge/registration.php
```

Copy only the runtime tree (`Api/`, `Model/`, `etc/`, `registration.php`, `composer.json`, plus `LICENSE`). Do not copy a development checkout's `vendor/`, `.phpstan.cache/`, `.phpunit.cache/` or `.phpcs-cache`: Magento scans every PHP file under `app/code`, and `setup:di:compile` fails on those leftovers (see [Troubleshooting](Troubleshooting)).

## Enable

```bash
bin/magento module:enable DDTCoreX_DagsterBridge
bin/magento setup:upgrade
bin/magento cache:flush
```

In production mode also run `bin/magento setup:di:compile` (and your usual deploy steps) before leaving maintenance. The module ships no frontend or admin assets.

`Magento_CatalogImportExport` must be enabled: the category upsert uses its category processor, and it is in the module's load sequence.

Check the state:

```bash
bin/magento module:status DDTCoreX_DagsterBridge
```

## Verify with the capabilities call

Get a token. With an admin user (the way the dagster-magento library authenticates):

```bash
TOKEN=$(curl -s -X POST "https://shop.example.com/rest/V1/integration/admin/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"dagster","password":"<password>"}' | tr -d '"')
```

(Admin token requests are subject to Magento's two factor authentication module when it is enabled; an integration access token is the alternative, see [Access Control](Access-Control).)

Then probe:

```bash
curl -s "https://shop.example.com/rest/V1/dagster-bridge/capabilities" \
  -H "Authorization: Bearer $TOKEN"
```

Expected answer for 1.0.0:

```json
{
  "version": "1.0.0",
  "capabilities": [
    "products.index",
    "products.attribute_values",
    "categories.upsert"
  ]
}
```

| Result | Meaning |
| --- | --- |
| 200 with the JSON above | Installed, enabled, token has `DDTCoreX_DagsterBridge::read` |
| 404 | Route unknown: module not installed, not enabled, or config cache not flushed |
| 401 | Token missing, expired, or its role lacks `DDTCoreX_DagsterBridge::read` |

## Upgrading

```bash
composer update ddtcorex/module-dagster-bridge
bin/magento setup:upgrade
bin/magento cache:flush
```

(plus `setup:di:compile` in production mode). Read the module's `CHANGELOG.md` first. After the upgrade, the capabilities call reports the new `version`, and a newer release may list more capabilities; clients that do not know a capability ignore it. For an `app/code` install, replace the directory contents and run the same commands.

## Uninstalling

```bash
bin/magento module:disable DDTCoreX_DagsterBridge
composer remove ddtcorex/module-dagster-bridge   # or delete app/code/DDTCoreX/DagsterBridge
bin/magento setup:upgrade
bin/magento cache:flush
```

The module creates no tables, columns or configuration values, so nothing is left to clean in the database. Categories it created are ordinary Magento categories and stay. After removal the capabilities probe answers 404, and the dagster-magento library falls back to plain REST in its default `use_bridge="auto"` mode (a run configured with `use_bridge="require"` raises `MagentoImportError` instead of falling back).

## See also

- [Home](Home)
- [Access Control](Access-Control)
- [Capabilities](Capabilities)
- [Troubleshooting](Troubleshooting)
- dagster-magento library wiki: https://github.com/ddtcorex/dagster-magento/wiki
