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
