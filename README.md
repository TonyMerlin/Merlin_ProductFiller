# Merlin ProductFiller

## Live deployment check

After deploying the module code, inspect Magento's proposed database changes before applying them:

```bash
php bin/magento setup:upgrade --dry-run=1
```

Magento's dry run still flushes caches and cleans generated code and static files. Run it in a deployment window. If it fails while constructing a `Merlin\ProductFiller` interceptor with a constructor argument type error, the server is using generated classes or DI metadata from an older module version. From the Magento root, remove only those generated artifacts and retry the dry run:

```bash
rm -rf generated/code/Merlin/ProductFiller generated/metadata/*
php bin/magento setup:upgrade --dry-run=1
```

Check the dry-run output again before running the real upgrade. If generated files cannot be removed, resolve ownership or a process recreating them first. The module archive contains no generated files.

When updating the module's admin templates in production mode, regenerate Magento's preprocessed admin views as part of static-content deployment. An old `var/view_preprocessed/.../Merlin/ProductFiller/.../preview.phtml` can leave the review fingerprint input out of the visible form even when the module source contains it. After deployment, confirm the rendered Product Filler preview contains `review_fingerprint`; clearing `block_html` alone does not replace an old preprocessed template.

```bash
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:clean config layout block_html
php bin/magento merlin:product-filler:install-check
```

`install-check` is read-only and exits nonzero if a live first install is not ready. It checks the activation patch and a cutoff recorded within the past 24 hours, ProductFiller tables and catalog attributes, clearance category 550, database-queue routing and registration, consumer cron activity, stale-job recovery, and delayed jobs. Fix any `FAIL` result before staff begin filling products. Run `install-check --json` for a deployment script. On an **established** installation, use `install-check --allow-existing-install` to skip only the fresh-cutoff age check; do not use that option to validate a new live install.

## Target date

When installing this module on live, run `php bin/magento setup:upgrade` after deploying it, followed by `php bin/magento setup:di:compile` and the normal admin static-content deployment in production mode. The `RecordActivationDate` data patch stores the current UTC time once at `merlin_product_filler/activation/created_from`. Later setup runs leave it unchanged.

Only products whose `catalog_product_entity.created_at` is on or after that time can appear as ProductFiller targets in the admin grid and CLI report, preview, or apply commands. Completed products created before the cutoff remain eligible as matching sources. If the cutoff is missing or invalid, ProductFiller refuses target operations.

The live `install-check` verifies this cutoff; `php bin/magento merlin:product-filler:report --limit=1` also prints it. Deploying the module code to live records its own installation time.

## Apply audit

The `merlin_product_filler_audit` table records each successful fill or repair: UTC time, target and source product IDs/SKUs, mode, CLI or admin actor, match confidence and reason, every applied field's before/after value, and preview warnings. Its insert runs inside the product transaction; an audit insert failure aborts the apply. Previews, rejected applies, and no-change repairs do not create audit records. Existing historical fills are not backfilled.

Staff can inspect the newest 50 records under **Catalog > Product Filler Audit**, filter by target product ID, or follow the audit-history link on a Product Filler preview. CLI: `php bin/magento merlin:product-filler:audit --target-id=144074 --limit=20`; add `--show-changes` to print full values.

The audit schema is in `etc/db_schema.xml`. Review the live dry-run SQL as part of deployment before running the normal setup upgrade.

## Reviewed-plan check

Every fill or repair now requires the SHA-256 review fingerprint shown by its CLI preview or carried by the admin preview form. For CLI, pass the printed value to `merlin:product-filler:apply` as `--review-fingerprint=<value>` alongside the existing IDs, confirmation SKU, and matching repair-mode flag if applicable. ProductFiller locks and reloads the target and source, rebuilds the plan, and compares the fingerprint inside the write transaction before changing attributes. If either product or the proposed plan changed, the apply rolls back and staff must preview again. Background jobs pass their stored bulk-review fingerprint to the same guarded apply path.

Fingerprints created by earlier module versions cannot be used with this version. Allow existing queued jobs to finish before deploying this change, or review and queue them again afterward.

## Stock reference and source content

The target `ebay_quote_ref` must be 1â€“32 ASCII letters or digits, optionally separated by single hyphens, with no surrounding whitespace. Examples: `CH-1156`, `B-72811`, and `TEST-02`. Invalid references cannot produce titles or feed values and cannot be filled or repaired. This rule applies to the target stock reference; older source products can retain legacy reference formats because their references are used only to recognize the source's old suffix in title and feed text.

Full fills trust the selected exact-matched catalog source only after staff review and source-content validation. Source names, model fields, Google SKU, eBay title, and source reference must be plain text without markup or control characters. Product descriptions and short descriptions keep their reviewed HTML unchanged. ProductFiller accepts ordinary formatting, links, approved layout attributes, simple inline presentation CSS, legacy table summary and numeric spacing, validated HTML inside tooltip titles, and `tabindex="0"` or `tabindex="-1"` for keyboard access. `cursor: help` is allowed for tooltip labels. It blocks executable or embedded elements, image tags, event handlers, unsafe URL schemes, unsafe CSS, and Magento template directives, including inside tooltip HTML. A blocked source appears in preview with the reason and cannot be applied or queued for bulk fill; staff must correct the source content and review it again. The bulk review asks staff to open the full preview or source product for formatted descriptions before confirmation. CLI previews with copied descriptions require `--full-descriptions` to print the complete source HTML and the fingerprint needed for apply.

## Bulk fill in admin

The shell grid has row checkboxes and **Review selected fills (95/100+ matches)** for staff with apply permission. Select up to 20 products, review the proposed source and generated title for each, then confirm the batch. A 95/100 candidate still has an exact normalized brand and model plus multiple exact model signals; 85/100 candidates remain excluded from bulk fill. Items without a current 95/100 or better exact match, target reference, or complete generated values are skipped. The bulk action never selects every filtered product automatically.

Source ranking considers sufficiently populated exact brand/model matches first. Among those, it selects the enabled product with the highest product ID. When no good enabled source exists, it selects the disabled product with the highest ID. Product ID takes precedence over source update time and the old completeness tie breakers; the existing exact-match and bulk confidence checks still apply.

When a target has no usable regular price (empty or zero), the ranking considers priced strong matches first: newest enabled product ID, then newest disabled product ID. The reviewed fill copies that source's regular price, special price, and special price dates. A zero or empty source special price is treated as no special price, with empty dates. If the target already has a positive regular price, it keeps all four target price fields. If neither target nor matched source has a usable regular price, the fill is blocked. The price fallback is shown in preview, included in the review fingerprint and audit, and verified after save.

At confirmation, ProductFiller compares each target, source, and proposed plan with the reviewed version, stores a background job, and returns immediately to a status page. One Magento database-queue message is published for each eligible product. The worker checks the reviewed fingerprint before calling the guarded apply command; apply then checks the same fingerprint again inside its write transaction. Tracking attributes added to a shared destination attribute set by another fill do not invalidate an otherwise unchanged review. Existing target prices, stock, website, image, approved damage-field rules, source-match, target-date, and audit protections remain in force. A missing-price fallback is the only planned price change. A failure on one product does not stop the others. Each successful product has its own audit record attributed to the admin who submitted the job.

Category assignment writes the reviewed category links through Magento's product category-link resource with default position `0`. It validates every category, uses the same transaction as the product fill, and lets the product-category indexer update category listings. ProductFiller does not save an entire category just to add one product, so category position is not a fill requirement.

Staff can leave the page and return through **Catalog > Product Filler Jobs**. The list and detail pages show queued, running, filled, skipped, and failed results. A queued job older than 15 minutes warns that Magento cron may be delayed. A running item older than two hours is reconciled by a 15-minute cron job: an existing matching apply audit marks it filled; otherwise it is marked failed for review. Requeue only after checking the target and audit.

This module uses Magento's existing `db` message queue and `consumers_runner` cron. `setup:upgrade` on live must create the two declared batch tables and register the new queue through Magento's MySQL MQ recurring setup. Confirm `php bin/magento queue:consumers:list` includes `merlin.product_filler.fill` and that Magento cron runs. If consumer cron is unavailable, a worker can be run with `php bin/magento queue:consumers:start merlin.product_filler.fill --max-messages=20`. Background processing releases the admin request; it does not shorten the underlying product save.
