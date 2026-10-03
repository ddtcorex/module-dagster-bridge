# Endpoints

This page is the reference for the four REST routes the module declares in `etc/webapi.xml`. Each is a Magento service contract (`Api/*Interface.php`, data objects in `Api/Data/`), so it follows Magento's normal REST conventions: a bearer token in the `Authorization` header, camelCase PHP parameters exposed as snake_case JSON keys, `null` values dropped from the response, and validation errors answered as HTTP 400 with a `message` and `parameters` body. Every parameter, default, limit and message below is taken from the module source.

Base URL in the examples: `https://shop.example.com/rest/V1/...` (any store code prefix such as `/rest/all/V1/` or `/rest/default/V1/` reaches the same routes). `$TOKEN` is an admin or integration access token.

## Common rules

### Null handling

Magento's REST serializer drops keys whose value is `null`. A field documented as nullable is therefore **absent** from the JSON, not `null`. Clients must read optional fields with a default, for example `item.get("store_value")` in Python. This affects `next_after` (last page), `status` (no store 0 status row), `store_value` and `default_value`.

### Error responses

| Status | Raised as | When |
| --- | --- | --- |
| 400 | `Magento\Framework\Exception\InputException` | Every validation failure listed per endpoint below, and any failure inside the category upsert transaction |
| 401 | Magento web API authorization | Missing or invalid token, or the token's role lacks the route's ACL resource |
| 404 | Magento web API routing | Route unknown: module not installed or not enabled |
| 503 | `Magento\Framework\Webapi\Exception` with HTTP code 503 | `categories/upsert` could not obtain its lock within 15 seconds |
| 500 | Any other error | Not raised by the module on purpose; a PHP `Error` inside the upsert is rolled back and rethrown unchanged |

A 400 body carries Magento's raw message with numbered placeholders and the values in `parameters`:

```json
{
  "message": "Unknown attribute codes: %1.",
  "parameters": ["colour, sizee"]
}
```

## GET /V1/dagster-bridge/capabilities

| | |
| --- | --- |
| Service | `CapabilitiesInterface::get()` |
| ACL | `DDTCoreX_DagsterBridge::read` |
| Parameters | none |

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

`version` is the constant `Model\Capabilities::VERSION`. `capabilities` lists one identifier per finished endpoint; the probe itself is not listed. No error other than authorization and routing. Details: [Capabilities](Capabilities).

## GET /V1/dagster-bridge/products/index

| | |
| --- | --- |
| Service | `ProductIndexInterface::getPage(int $after = 0, int $limit = 5000)` |
| ACL | `DDTCoreX_DagsterBridge::read` |

Query parameters:

| Name | Type | Default | Limits | Meaning |
| --- | --- | --- | --- | --- |
| `after` | int | `0` | none | Return products whose `entity_id` is greater than this value |
| `limit` | int | `5000` (`DEFAULT_LIMIT`) | 1 to 20000 (`MAX_LIMIT`) | Page size |

```bash
curl -s "https://shop.example.com/rest/V1/dagster-bridge/products/index?after=0&limit=2" \
  -H "Authorization: Bearer $TOKEN"
```

```json
{
  "items": [
    {
      "entity_id": 1,
      "sku": "24-MB01",
      "type_id": "simple",
      "attribute_set_id": 15,
      "status": 1,
      "updated_at": "2026-09-30 10:12:44"
    },
    {
      "entity_id": 2,
      "sku": "24-MB04",
      "type_id": "simple",
      "attribute_set_id": 15,
      "updated_at": "2026-09-30 10:12:45"
    }
  ],
  "next_after": 2
}
```

The second item has no store 0 status row, so `status` is absent. `next_after` is present only when the page is full (`count(items) == limit`) and equals the last item's `entity_id`; on the last page it is absent.

Errors:

| Status | Message (raw) | Parameters |
| --- | --- | --- |
| 400 | `The limit must be between 1 and %1.` | `[20000]` |

Details: [Product Index](Product-Index).

## POST /V1/dagster-bridge/products/attribute-values

| | |
| --- | --- |
| Service | `AttributeValuesInterface::get(string[] $skus, string[] $attributeCodes, int $storeId = 0)` |
| ACL | `DDTCoreX_DagsterBridge::read` |
| Writes | nothing; POST only because the request carries two lists |

Body:

| Key | Type | Required | Limits | Meaning |
| --- | --- | --- | --- | --- |
| `skus` | string[] | yes | 1 to 1000 after removing exact duplicates (`MAX_SKUS`) | Products to read |
| `attribute_codes` | string[] | yes | 1 to 50 after removing exact duplicates (`MAX_ATTRIBUTE_CODES`) | Product attribute codes |
| `store_id` | int | no, default `0` | must be an existing store id | Store view whose value is wanted |

```bash
curl -s -X POST "https://shop.example.com/rest/V1/dagster-bridge/products/attribute-values" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"skus":["24-MB01","NO-SUCH-SKU"],"attribute_codes":["name","sku"],"store_id":1}'
```

```json
[
  {"sku": "24-MB01", "attribute_code": "name", "store_value": "Joust Sac de sport", "default_value": "Joust Duffle Bag"},
  {"sku": "24-MB01", "attribute_code": "sku", "default_value": "24-MB01"},
  {"sku": "NO-SUCH-SKU", "attribute_code": "name"},
  {"sku": "NO-SUCH-SKU", "attribute_code": "sku"}
]
```

One item per requested SKU and code, SKU order outer and code order inner, including pairs with no value at all. Values are the raw stored values as strings. A static attribute (`sku` here) only ever carries `default_value`.

Errors (all 400, checked in this order):

| Message (raw) | Cause |
| --- | --- |
| `At least one SKU is required.` | `skus` empty |
| `At least one attribute code is required.` | `attribute_codes` empty |
| `At most %1 SKUs can be requested in one call, %2 given.` | more than 1000 distinct SKUs |
| `At most %1 attribute codes can be requested in one call, %2 given.` | more than 50 distinct codes |
| `Store %1 does not exist.` | `store_id` names no store |
| `Unknown attribute codes: %1.` | one or more codes are not product attributes; all unknown codes listed, comma separated |
| `Attribute codes this endpoint cannot read: %1. A static attribute must be a column of the product table, any other one must keep scalar values in a standard EAV value table.` | e.g. `category_ids`, `media_gallery`, `tier_price`; all such codes listed |

Details: [Attribute Values](Attribute-Values).

## POST /V1/dagster-bridge/categories/upsert

| | |
| --- | --- |
| Service | `CategoryUpsertInterface::upsert(string[] $paths, string $root = 'Default Category', string $separator = '/')` |
| ACL | `DDTCoreX_DagsterBridge::write` |
| Writes | yes, the only writing endpoint; one transaction per call |

Body:

| Key | Type | Required | Default | Meaning |
| --- | --- | --- | --- | --- |
| `paths` | string[] | yes | | Category paths; the root is prepended when a path does not start with it |
| `root` | string | no | `Default Category` | Name of an existing tree root (a child of the invisible root id 1), matched case-insensitively |
| `separator` | string | no | `/` | Level separator inside `paths`; must not be empty and must not appear inside any name |

```bash
curl -s -X POST "https://shop.example.com/rest/V1/dagster-bridge/categories/upsert" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"paths":["Men|Tops|T-Shirts","Men|Bags / Luggage"],"root":"Default Category","separator":"|"}'
```

```json
[
  {"path": "Men|Tops|T-Shirts", "id": 41},
  {"path": "Men|Bags / Luggage", "id": 42}
]
```

Every requested path comes back, in request order, spelled exactly as sent, with the id of its deepest category. An empty `paths` list answers `[]` without touching anything.

Errors:

| Status | Message (raw) | Cause |
| --- | --- | --- |
| 400 | `The separator must not be empty.` | `separator` is `""` |
| 400 | `The root category "%1" does not exist.` | `root` is not a tree root |
| 400 | `Category name "%1" in path "%2" ends with a backslash, which the native processor cannot store.` | a level ends with `\` |
| 400 | `None of the supported path delimiters is free of the requested names.` | the paths together contain all of `,` `;` `\|` `~` `^` |
| 400 | `Category path "%1" could not be created by the native processor.` | the native processor reported a failed category; rolled back |
| 400 | `The native processor answered %1 ids for %2 paths.` | id count mismatch; rolled back |
| 400 | `Category path "%1" could not be created: %2` | any other exception during the transaction; `%1` lists all requested paths; rolled back |
| 503 | `Another category upsert is still running after 15 seconds; retry the call later.` | lock `dagster_bridge_category_upsert` not obtained within 15 s; nothing done |

Details: [Category Upsert](Category-Upsert).

## See also

- [Access Control](Access-Control)
- [Product Index](Product-Index)
- [Attribute Values](Attribute-Values)
- [Category Upsert](Category-Upsert)
- [Capabilities](Capabilities)
- [Troubleshooting](Troubleshooting)
- dagster-magento library wiki: https://github.com/ddtcorex/dagster-magento/wiki
