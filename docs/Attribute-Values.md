# Attribute Values

`POST /V1/dagster-bridge/products/attribute-values` reads raw attribute values for up to 1000 SKUs and 50 attribute codes in one call, for one store view, and returns the store's own value and the default (store 0) value **separately** so the caller can apply Magento's fallback itself. It writes nothing; POST is used only because the request carries two lists. It needs `DDTCoreX_DagsterBridge::read`. Source: `Model/AttributeValues.php`, `Model/ResourceModel/AttributeValuesQuery.php`.

## Request body

```json
{
  "skus": ["24-MB01", "24-mb04"],
  "attribute_codes": ["name", "price", "status", "sku"],
  "store_id": 1
}
```

| Key | Type | Default | Limits |
| --- | --- | --- | --- |
| `skus` | string[] | required | at least 1, at most 1000 (`MAX_SKUS`) |
| `attribute_codes` | string[] | required | at least 1, at most 50 (`MAX_ATTRIBUTE_CODES`) |
| `store_id` | int | `0` | must be an existing store id (0, the admin store, is valid) |

Exact duplicates are removed from both lists before the limits are checked, so 1200 entries with only 1000 distinct SKUs pass.

## Response

One item per requested SKU and code, SKU order outer, code order inner, **including pairs with no value at all**, so a caller can tell "no value" from "not asked":

```json
[
  {"sku": "24-MB01", "attribute_code": "name", "store_value": "Joust Sac de sport", "default_value": "Joust Duffle Bag"},
  {"sku": "24-MB01", "attribute_code": "price", "default_value": "34.000000"},
  {"sku": "24-MB01", "attribute_code": "status", "default_value": "1"},
  {"sku": "24-MB01", "attribute_code": "sku", "default_value": "24-MB01"},
  {"sku": "24-mb04", "attribute_code": "name", "default_value": "Strive Shoulder Pack"},
  ...
]
```

| Field | Meaning |
| --- | --- |
| `sku` | The SKU exactly as the caller sent it |
| `attribute_code` | The requested code |
| `store_value` | The value row of `store_id`; absent when that store has no value of its own. Always absent for a static attribute |
| `default_value` | The value row of store 0 (for a static attribute: the column of the product row); absent when there is none |

Values are the raw stored values cast to strings: a decimal comes back with the column's precision, a select attribute as its option id, not its label. Nulls are absent keys (Magento's serializer drops them).

### store_value versus default_value

| Request | Product has store 1 override | Product has only a default | Product has no value |
| --- | --- | --- | --- |
| `store_id: 1` | both keys | `default_value` only | neither key |
| `store_id: 0` | n/a | both keys, equal | neither key |

With `store_id: 0` the store 0 row is both the requested store's row and the default row, so `store_value` equals `default_value` whenever a value exists.

### The fallback the client applies

Magento shows a store view its own value when it has one and the default otherwise. The module does not collapse the two; the dagster-magento client does:

```python
for code, (store_value, default_value) in per_code.items():
    if store_value is None and default_value is None:
        continue                      # no value: field left unset
    value = store_value if store_value is not None else default_value
```

Only the rows of store 0 and of the requested store are read (`store_id IN (0, <store_id>)`, or just `0` when `store_id` is 0).

## Which attributes are readable

| Attribute kind | Read from | Readable |
| --- | --- | --- |
| Static, and a column of `catalog_product_entity` (e.g. `sku`) | the product row | yes, `default_value` only |
| Static, but not a column (e.g. `category_ids`, `media_gallery`) | | no, refused |
| `datetime`, `decimal`, `int`, `text`, `varchar` backend type, values in the standard `catalog_product_entity_<type>` table, scalar backend | the EAV value table | yes |
| Backend table of its own, or non-scalar backend (e.g. `tier_price`, whose values live in a price table) | | no, refused |
| Not a product attribute at all | | no, unknown |

Unknown codes are checked first and all of them are named:

```json
{"message": "Unknown attribute codes: %1.", "parameters": ["colour, sizee"]}
```

Then every unreadable code is named at once:

```json
{
  "message": "Attribute codes this endpoint cannot read: %1. A static attribute must be a column of the product table, any other one must keep scalar values in a standard EAV value table.",
  "parameters": ["category_ids, tier_price"]
}
```

Refusing is deliberate: answering null for every product would look like "no value" and silently corrupt a diff. Read such attributes through native REST.

## SKU matching

SKUs are matched case-insensitively, as the database collation compares them, and every item answers under the **spelling the caller sent**. Asking for `24-mb04` finds the product stored as `24-MB04` and answers `"sku": "24-mb04"`. To learn the stored spelling, request the `sku` attribute code: its `default_value` is the stored SKU.

A SKU that matches no product still gets its items, with neither value key. Use the [Product Index](Product-Index.md) to decide existence.

## store_id validation

`store_id` is looked up in the store repository. A store id that does not exist answers 400 instead of quietly returning defaults:

```json
{"message": "Store %1 does not exist.", "parameters": [7]}
```

The store code in the URL (`/rest/default/V1/...`) has no effect on which store is read; only `store_id` does.

## Validation order and messages

All are HTTP 400 (`InputException`):

1. `At least one SKU is required.`
2. `At least one attribute code is required.`
3. `At most %1 SKUs can be requested in one call, %2 given.`
4. `At most %1 attribute codes can be requested in one call, %2 given.`
5. `Store %1 does not exist.`
6. `Unknown attribute codes: %1.`
7. `Attribute codes this endpoint cannot read: %1. ...`

## Chunking larger requests

The dagster-magento client chunks by itself: codes in chunks of 50, SKUs in chunks of 1000, one call per pair of chunks. Note that the client retries POST only on 429, so a gateway error on this read-only POST is not retried.

## Commerce staging

Value rows are joined to the product through the metadata pool's link field (`entity_id` on Open Source). With Adobe Commerce content staging (`row_id`) a product would answer one value per staged version. Not supported.

## See also

- [Endpoints](Endpoints.md)
- [Product Index](Product-Index.md)
- [Troubleshooting](Troubleshooting.md)
- [Compatibility](Compatibility.md)
- dagster-magento library wiki: https://github.com/ddtcorex/dagster-magento/wiki
