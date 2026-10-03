# Category Upsert

`POST /V1/dagster-bridge/categories/upsert` takes a list of category paths, creates every missing category along them, and answers each requested path with the id of its deepest category. It is the only endpoint of the module that writes, it needs `DDTCoreX_DagsterBridge::write`, and it runs every call in one database transaction under a named lock. Paths are handed to Magento's own `CatalogImportExport` category processor, so the tree is built exactly as a native product import would build it. This page describes the path syntax, how names are matched, what gets created and where, and how failures and concurrency behave. Source: `Model/CategoryUpsert.php`, `Model/CategoryPathParser.php`.

## Request

```json
{
  "paths": ["Men/Tops/T-Shirts", "Women/Bags"],
  "root": "Default Category",
  "separator": "/"
}
```

| Key | Type | Default | Rule |
| --- | --- | --- | --- |
| `paths` | string[] | required | Any number; an empty list answers `[]` with no write (an empty `separator` is still rejected first) |
| `root` | string | `Default Category` | Must name an existing tree root |
| `separator` | string | `/` | Not empty; may be more than one character |

## Path format

Each path is split by `CategoryPathParser::parse()`:

1. The path is split on every occurrence of `separator` (plain `explode`, no regular expression).
2. Each level is trimmed of surrounding whitespace; empty levels are dropped. `Men//Tops/`, ` Men / Tops ` and `Men/Tops` are the same path.
3. If the first remaining level is not the root name (compared case-insensitively with `mb_strtolower`, root trimmed), the root is prepended. `Men/Tops` and `Default Category/Men/Tops` therefore describe the same branch.
4. A path that is empty after step 2 becomes just the root, and answers the root's id.

### The separator and escaping

There is **no escape for the separator itself**: every occurrence splits. Choose a separator that appears in no name.

A `/` inside a name is allowed when the separator is something else. Magento's processor uses `/` as its own level delimiter, so the module escapes a `/` inside a name as `\/`, the processor's own quoting:

```json
{"paths": ["Men|Bags / Luggage"], "separator": "|"}
```

creates a category literally named `Bags / Luggage` under `Men`.

Consequence of that quoting: a name that **ends with a backslash** cannot be stored, and the call is rejected with 400 `Category name "%1" in path "%2" ends with a backslash, which the native processor cannot store.`

Internally all paths of one call are joined into a single processor call with a path delimiter picked from `,` `;` `|` `~` `^`, the first one that appears in none of the paths. If all five appear somewhere in the call, the request is rejected with 400 `None of the supported path delimiters is free of the requested names.`; split such a call into smaller ones.

The dagster-magento client picks the wire separator itself: `/` when no path contains `|`, `>`, `^` or `~`, otherwise the first of those that appears in no path, and re-joins the levels with it.

## Root

`root` must be the name of an existing child of the invisible root category (id 1), that is, a tree root such as `Default Category`. The check reads the categories at the admin store (store 0) and compares names case-insensitively. An unknown root is rejected **before** anything is created:

```json
{"message": "The root category \"%1\" does not exist.", "parameters": ["Catalog Root"]}
```

The native processor would otherwise silently create a new tree; the module refuses that. Use `root` to target a second store root: `{"paths": ["Sale"], "root": "Outlet Root"}`.

## Name matching

Matching is the native processor's:

- **Case-insensitive**: `men/shirts` reuses an existing `Men/Shirts`. New nodes keep the spelling the caller sent.
- **Active or not**: an existing inactive category is reused, never duplicated by an active sibling.
- With two existing siblings whose names differ only by case, the processor decides which one a path resolves to and logs nothing. Avoid such siblings if the choice matters.

## What is created, and where

- Only missing levels are created; existing ones are reused.
- New categories are created active and included in the menu, as a native import creates them.
- They are written at the admin store (store 0), whatever store code the request URL carries (`/rest/default/V1/...` and `/rest/all/V1/...` behave the same).
- Nothing existing is renamed, moved, re-activated or deleted.

The module does not trigger any indexer itself. The categories are saved through the category resource model, and the after-commit callbacks each saved category registers run when the transaction commits, as for any category save. Indexers configured "Update by Schedule" catch up when cron runs.

## Idempotence

Calling the same request twice creates nothing the second time and answers the same ids. Within one call, a path created by an earlier path of the same call is reused (`["Men", "Men/Tops"]` creates `Men` once).

## Concurrency: the named lock

Every call that has paths takes the lock `dagster_bridge_category_upsert` through Magento's `LockManagerInterface` (the lock provider configured in `app/etc/env.php`, the database by default) and waits up to **15 seconds** (`CategoryUpsert::LOCK_TIMEOUT`). The category processor, which loads the whole tree in its constructor, is built only after the lock is held, so it sees what the previous holder committed. Two concurrent calls that create the same new path therefore create it once and answer the same id.

If the lock is not obtained within 15 seconds, nothing is done and the call answers:

```
HTTP/1.1 503 Service Unavailable
{"message": "Another category upsert is still running after 15 seconds; retry the call later."}
```

The lock is released in a `finally` block on success and on failure. Note that the dagster-magento client retries POST requests only on 429, so it does not retry a 503 by itself: in `use_bridge="auto"` it falls back to native REST creation for that call, in `"require"` it raises.

## Transaction and rollback

The whole call runs inside one transaction opened on the category resource model. On any `Throwable` (an `Error` as much as an `Exception`) the transaction is rolled back, so a failing call leaves the tree exactly as it was and no commit callback survives:

| Failure inside the transaction | Answer |
| --- | --- |
| `InputException` (processor failure, id count mismatch, no free path delimiter) | rethrown, HTTP 400 |
| any other `Exception` | wrapped: 400 `Category path "%1" could not be created: %2`, where `%1` lists every requested path joined by `", "` and `%2` is the original message |
| a PHP `Error` | rethrown unchanged (HTTP 500) |

Validation that needs no database write (empty separator, unknown root, trailing backslash) happens before the lock and the transaction.

## Response mapping

```json
[
  {"path": "Men/Tops/T-Shirts", "id": 41},
  {"path": "Women/Bags", "id": 57}
]
```

- One item per requested path, in request order.
- `path` is the string exactly as sent (not trimmed, not normalized, root not prepended), so a client can map answers back by index or by string.
- `id` is the id of the deepest category of that path (the root's id for a path that names only the root).

## Limits

| Limit | Value |
| --- | --- |
| Paths per call | no cap in the module; one transaction and one lock hold per call, so keep calls reasonable |
| Separator | non-empty string, no escape |
| Names | must not end with `\`; must not contain the separator |
| Path delimiters | the call must leave at least one of `,` `;` `\|` `~` `^` unused |
| Lock wait | 15 s, then 503 |

## See also

- [Endpoints](Endpoints.md)
- [Access Control](Access-Control.md)
- [Troubleshooting](Troubleshooting.md)
- [Capabilities](Capabilities.md)
- dagster-magento library wiki: https://github.com/ddtcorex/dagster-magento/wiki
