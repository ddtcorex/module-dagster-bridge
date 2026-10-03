# Product Index

`GET /V1/dagster-bridge/products/index` returns the identity of every product (entity id, SKU, type, attribute set, default store status and last update time) one page at a time, straight from `catalog_product_entity`, without loading product models. Pages are keyset paginated on `entity_id`: pass the `next_after` of one page as `after` of the next. It needs `DDTCoreX_DagsterBridge::read`. Source: `Model/ProductIndex.php`, `Model/ResourceModel/ProductIndexQuery.php`.

## Parameters

| Query parameter | Type | Default | Limits |
| --- | --- | --- | --- |
| `after` | int | `0` | none; products with `entity_id > after` are returned |
| `limit` | int | `5000` (`ProductIndexInterface::DEFAULT_LIMIT`) | 1 to 20000 (`ProductIndexInterface::MAX_LIMIT`) |

A `limit` outside 1 to 20000 answers 400:

```json
{"message": "The limit must be between 1 and %1.", "parameters": [20000]}
```

## Fields returned

| Field | Type | Source |
| --- | --- | --- |
| `entity_id` | int | `catalog_product_entity.entity_id` |
| `sku` | string | `catalog_product_entity.sku`, stored spelling |
| `type_id` | string | `catalog_product_entity.type_id` (`simple`, `configurable`, ...) |
| `attribute_set_id` | int | `catalog_product_entity.attribute_set_id` |
| `status` | int, optional | value of the `status` attribute at **store 0** (`1` enabled, `2` disabled); absent when the product has no store 0 status row |
| `updated_at` | string | `catalog_product_entity.updated_at`, database datetime |

Items are ordered by `entity_id` ascending.

## Status at store 0

`status` comes from a left join on `catalog_product_entity_int` restricted to the `status` attribute id (resolved from the EAV attribute repository) and `store_id = 0`. A status overridden at a store view is **not** reflected: the index reports the default scope value only. To read a store view's status, ask [Attribute Values](Attribute-Values) for the `status` code with that `store_id`.

## Paging rule

- `next_after` is set to the last item's `entity_id` when the page is full (`count(items) == limit`).
- When the page has fewer items than `limit`, `next_after` is null and therefore **absent** from the JSON. That page is the last.
- If the last page happens to be exactly full, the next call returns `"items": []` without `next_after`.

```json
{
  "items": [
    {"entity_id": 9998, "sku": "MH01-XS-Black", "type_id": "simple", "attribute_set_id": 9, "status": 1, "updated_at": "2026-09-30 10:12:44"},
    {"entity_id": 9999, "sku": "MH01", "type_id": "configurable", "attribute_set_id": 9, "status": 1, "updated_at": "2026-09-30 10:12:45"}
  ],
  "next_after": 9999
}
```

## Why keyset pagination

Offset pagination (`LIMIT n OFFSET m`, which `searchCriteria[currentPage]` uses) shifts when a product is deleted between two pages, so a walker can skip a product that was never deleted. With `WHERE entity_id > after ORDER BY entity_id LIMIT n`, a deletion between pages cannot make the caller skip another product, and each page is an index range scan on the primary key regardless of how deep the walk is.

What it does not give you: a consistent snapshot. A product created during the walk appears if its id is above the current cursor, and a product deleted after its page was read was still reported.

## Walking the whole catalog

curl and jq:

```bash
after=0
while :; do
  page=$(curl -s "https://shop.example.com/rest/V1/dagster-bridge/products/index?after=$after&limit=20000" \
    -H "Authorization: Bearer $TOKEN")
  echo "$page" | jq -c '.items[]'
  after=$(echo "$page" | jq -r '.next_after // empty')
  [ -z "$after" ] && break
done
```

Python, as the dagster-magento client does it (`BridgeClient.product_index`):

```python
after = 0
while True:
    page = resource.get("dagster-bridge/products/index", params={"after": after, "limit": 5000})
    yield from page.get("items") or []
    next_after = page.get("next_after")
    if next_after is None:
        break
    after = int(next_after)
```

The library then keys the whole index by SKU once per run and uses it to decide which SKUs exist and to answer the entity fields above without further calls.

## Open Source only: Commerce staging is not supported

The status join goes through the product link field reported by Magento's metadata pool. On Magento Open Source that is `entity_id`. On Adobe Commerce with content staging it is `row_id`, and a product has one row per staged version, so this query would return one index row per version, not one per product. Commerce content staging is **not supported**; the module targets Magento Open Source.

## See also

- [Endpoints](Endpoints)
- [Attribute Values](Attribute-Values)
- [Capabilities](Capabilities)
- [Compatibility](Compatibility)
- dagster-magento library wiki: https://github.com/ddtcorex/dagster-magento/wiki
