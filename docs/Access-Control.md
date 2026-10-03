# Access Control

The module declares two ACL resources in `etc/acl.xml`, both direct children of `Magento_Backend::admin`: `DDTCoreX_DagsterBridge::read` guards the three read endpoints and `DDTCoreX_DagsterBridge::write` guards the category upsert. Because they are siblings, not parent and child, a role or integration can be granted exactly one of them. This page explains what each resource opens, how to give a token only what it needs, and what the dagster-magento library expects.

## Resources

| Resource id | Title in the admin tree | Routes |
| --- | --- | --- |
| `DDTCoreX_DagsterBridge::read` | Dagster Bridge: read catalog data | `GET /V1/dagster-bridge/capabilities`, `GET /V1/dagster-bridge/products/index`, `POST /V1/dagster-bridge/products/attribute-values` |
| `DDTCoreX_DagsterBridge::write` | Dagster Bridge: upsert categories | `POST /V1/dagster-bridge/categories/upsert` |

Notes:

- `::write` does **not** include `::read`. A token with only `::write` can upsert categories but cannot call the capabilities probe, so a client that probes first (as the library does) needs both.
- `products/attribute-values` is a POST but sits behind `::read`: it writes nothing.
- A token without the route's resource gets HTTP 401 from Magento's web API authorization, the same status as a missing or expired token.

## Two ways to get a scoped token

### Admin user with a dedicated role

1. System > Permissions > User Roles > Add New Role, for example `Dagster bridge`.
2. Role Resources: Custom. Tick `Dagster Bridge: read catalog data`, and `Dagster Bridge: upsert categories` only if the run creates categories.
3. System > Permissions > All Users > Add New User, assign that role.
4. Request a token with `POST /V1/integration/admin/token`. The token carries the user's role ACL, nothing more.

This is how the dagster-magento library authenticates: `MagentoResource` takes a `username` and `password` and fetches an admin token from `integration/admin/token`. When Magento's two factor authentication module is active, admin tokens need a 2FA step; the library's sandbox disables the two factor authentication modules (`Magento_TwoFactorAuth` and `Magento_AdminAdobeImsTwoFactorAuth`).

### Integration

1. System > Extensions > Integrations > Add New Integration.
2. API tab: Resource Access Custom, tick the same resource(s).
3. Save, Activate, copy the Access Token.

Since Magento 2.4.4 an integration access token is accepted as a standalone bearer token only when Stores > Configuration > Services > OAuth > Consumer Settings > "Allow OAuth Access Tokens to be used as standalone Bearer tokens" is enabled. Use an integration token for curl, monitoring or a custom client; the library as shipped logs in as an admin user (above).

## Least privilege recipe

| Run type | Grant |
| --- | --- |
| Probe only (monitoring, smoke test) | `::read` |
| Snapshot, diff, export (reads products) | `::read` |
| Import that creates categories through the bridge | `::read` + `::write` |

Then add only the native Magento resources the library's non-bridge paths need for the importers you actually run (products, categories, attributes, inventory, and so on). The bridge never needs more than its two resources; everything else a token carries is for the native REST paths.

Do not grant `Magento_Backend::all` (full admin) to an automation user just for the bridge: a full admin token carries both bridge resources already, but also everything else.

## What the token cannot do through the module

- `::read` gives raw access to identity fields of every product and to any product attribute value that lives in the product table or a standard EAV value table, for any store. Treat it like catalog read access, not like public storefront access.
- `::write` can only create categories under an **existing** root. It never renames, moves, deactivates or deletes a category, and cannot create a new tree root: an unknown `root` is rejected with 400.

## What the library needs

| Library setting | Resources used |
| --- | --- |
| `use_bridge="never"` | none of the bridge resources |
| `use_bridge="auto"` (default) | `::read` for the probe, `::write` for the category upsert. If the role lacks `::read`, the probe answers 401 even after the library refreshes its token once; the library logs a warning and continues on plain REST. Only a failure to obtain the token at all (`MagentoAuthError`) aborts the run |
| `use_bridge="auto"`, `::read` without `::write` | The probe still lists `categories.upsert` (the list is fixed per release, it does not reflect the caller's ACL). The upsert call answers 401, and the library warns and creates the categories through native REST instead |
| `use_bridge="require"` | `::read`, plus `::write` for importers that create categories; a missing capability raises `MagentoImportError` |

## See also

- [Endpoints](Endpoints)
- [Category Upsert](Category-Upsert)
- [Installation](Installation)
- [Troubleshooting](Troubleshooting)
- dagster-magento library wiki: https://github.com/ddtcorex/dagster-magento/wiki
