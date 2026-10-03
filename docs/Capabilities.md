# Capabilities

`GET /V1/dagster-bridge/capabilities` tells a client which version of the module is installed and which endpoints this release offers, so the client can use each capability on its own and fall back to plain REST for the rest. An older module then degrades feature by feature instead of failing as a whole. It needs `DDTCoreX_DagsterBridge::read` and takes no parameters. Source: `Model/Capabilities.php`.

## Request and response

```bash
curl -s "https://shop.example.com/rest/V1/dagster-bridge/capabilities" \
  -H "Authorization: Bearer $TOKEN"
```

```json
{
  "version": "1.0.0",
  "capabilities": ["products.index", "products.attribute_values", "categories.upsert"]
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `version` | string | `Capabilities::VERSION` |
| `capabilities` | string[] | One identifier per finished endpoint of this release |

## Capability identifiers in 1.0.0

| Identifier | Endpoint |
| --- | --- |
| `products.index` | `GET /V1/dagster-bridge/products/index` |
| `products.attribute_values` | `POST /V1/dagster-bridge/products/attribute-values` |
| `categories.upsert` | `POST /V1/dagster-bridge/categories/upsert` |

The list is a constant of the release. It does **not** depend on the caller's ACL: a token with `::read` but without `::write` still sees `categories.upsert`, and gets 401 when it calls it.

## The version constant

`version` is not read from `composer.json` (which has no `version` field: Composer would ignore a VCS tag that disagrees with it). It is the constant `Model\Capabilities::VERSION`, kept in step by two guards:

- a unit test fails when the constant differs from the top release heading of `CHANGELOG.md`;
- the release workflow refuses a tag `vX.Y.Z` whose `X.Y.Z` differs from the constant.

So the reported version is the tag the installed code was released from.

## How a client degrades per capability

The probe is meant to run once per run, its answer cached. The dagster-magento client (`BridgeClient.capabilities()`):

| Probe outcome | Client behaviour |
| --- | --- |
| 200 | Capability set = the `capabilities` list |
| 404 (module absent), 401 (no `::read`), timeout, 500, any other failure | Warning logged, empty capability set, run continues on plain REST |
| Token cannot be obtained at all (`MagentoAuthError`) | Run aborts: a credential problem is about the store, not the optional module |

Then, per capability and per run mode (`use_bridge`):

| Mode | Capability advertised | Capability missing |
| --- | --- | --- |
| `auto` (default) | used; if a call fails (HTTP error or malformed answer) the client warns and uses the native REST path for that call | native REST path |
| `never` | ignored | native REST path |
| `require` | used; a failing call raises `MagentoImportError` | `MagentoImportError` naming the missing capability, raised before the import starts for the capabilities that importer checks (the category importers check `categories.upsert`) |

The library wiki documents exactly which importer uses which capability.

A future release that adds an endpoint adds an identifier; a client that does not know it ignores it, and a newer client against an older module simply sees fewer identifiers. Write your own clients the same way: test membership of each identifier, never compare `version` to decide what exists.

## See also

- [Endpoints](Endpoints)
- [Access Control](Access-Control)
- [Development](Development)
- [Installation](Installation)
- dagster-magento library wiki: https://github.com/ddtcorex/dagster-magento/wiki
