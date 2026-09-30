# Changelog

All notable changes to this module are documented in this file. The format
follows Keep a Changelog and this project adheres to Semantic
Versioning.

Release checklist: set `Capabilities::VERSION` to the new heading below (a
unit test fails the build when the two disagree), then tag `vX.Y.Z`;
composer.json carries no version field.

## [1.0.0] - unreleased

First release. Four service contracts, each one behind its own ACL resource,
with the capabilities endpoint telling a client what the store offers so an
older module degrades per capability instead of failing.

### Added

- `GET /V1/dagster-bridge/capabilities`: module version, from
  `Capabilities::VERSION`, and the list of capabilities this release
  exposes.
- `GET /V1/dagster-bridge/products/index`: `entity_id`, `sku`, `type_id`,
  `attribute_set_id`, store 0 `status` and `updated_at` for every product,
  keyset paginated on `after` (an entity id, not an offset) with `limit`
  defaulting to 5000 and capped at 20000.
- `POST /V1/dagster-bridge/products/attribute-values`: store value and default
  store value per requested SKU and attribute code, at most 1000 SKUs and 50
  codes per call, unknown codes rejected with every unknown code listed.
- `POST /V1/dagster-bridge/categories/upsert`: creates the categories a path
  names, in one transaction, and answers every requested path with its id. It
  is the only writing endpoint and the only one behind the write resource.

### Fixed

Found in review before the first tag, each one verified against a live
Magento 2.4.9 store.

- `categories/upsert` rolls back on any `Throwable`, not only on an
  `Exception`, and runs its transaction through the category resource model.
- `categories/upsert` always uses the native category processor: names with
  a `/` are escaped for it instead of taking a second repository path that
  matched names case-sensitively, missed inactive categories and read the
  request store. An unknown `root` answers 400 instead of being created.
- `categories/upsert` holds the named lock `dagster_bridge_category_upsert`,
  so concurrent calls for the same new path create it once; a lock timeout
  answers 503.
- `products/attribute-values` answers 400 for codes it cannot read (a static
  code that is not a product column, such as `category_ids`, or values kept
  outside the standard EAV tables, such as `tier_price`) instead of a SQL
  error or silent nulls.
- Commerce content staging is declared unsupported; the endpoints target
  Magento Open Source.
- `products/attribute-values` finds a SKU sent in another case and answers
  under the requested spelling.
- Empty `skus` or `attribute_codes`, an empty `separator` and an unknown
  `store_id` answer 400.
- composer.json has no `version` field and real `magento/*` constraints for
  2.4.6 on, plus `magento/module-eav`; the version is
  `Capabilities::VERSION`.
- README install instructions match what resolves today.
