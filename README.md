# Merlin_ProductFiller

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

Review the dry-run output before proceeding with the normal deployment steps:

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

The `install-check` verifies this cutoff; `php bin/magento merlin:product-filler:report --limit=1` also prints it so it will record its own installation time.

## Apply audit

The `merlin_product_filler_audit` table records each successful fill or repair: UTC time, target and source product IDs/SKUs, mode, CLI or admin actor, match confidence and reason, every applied field's before/after value, and preview warnings. Its insert runs inside the product transaction; an audit insert failure aborts the apply. Previews, rejected applies, and no-change repairs do not create audit records. Existing historical fills are not backfilled.

Staff can inspect the newest 50 records under **Catalog > Product Filler Audit**, filter by target product ID, or follow the audit-history link on a Product Filler preview. CLI: `php bin/magento merlin:product-filler:audit --target-id=144074 --limit=20`; add `--show-changes` to print full values.

The audit schema is in `etc/db_schema.xml`. Review the live dry-run SQL as part of deployment before running the normal setup upgrade.

## Reviewed-plan check

Every fill or repair now requires the SHA-256 review fingerprint shown by its CLI preview or carried by the admin preview form. For CLI, pass the printed value to `merlin:product-filler:apply` as `--review-fingerprint=<value>` alongside the existing IDs, confirmation SKU, and matching repair-mode flag if applicable. ProductFiller locks and reloads the target and source, rebuilds the plan, and compares the fingerprint inside the write transaction before changing attributes. If either product or the proposed plan changed, the apply rolls back and staff must preview again. Background jobs pass their stored bulk-review fingerprint to the same guarded apply path.

Fingerprints created by earlier module versions cannot be used with this version. Allow existing queued jobs to finish before deploying this change, or review and queue them again afterward.

## Bulk fill in admin

The shell grid has row checkboxes and **Review selected fills (95/100+ matches)** for staff with apply permission. Select up to 20 products, review the proposed source and generated title for each, then confirm the batch. A 95/100 candidate still has an exact normalized brand and model plus multiple exact model signals; 85/100 candidates remain excluded from bulk fill. Items without a current 95/100 or better exact match, target reference, or complete generated values are skipped. The bulk action never selects every filtered product automatically.

At confirmation, ProductFiller compares each target, source, and proposed plan with the reviewed version, stores a background job, and returns immediately to a status page. One Magento database-queue message is published for each eligible product. The worker checks the reviewed fingerprint before calling the guarded apply command; apply then checks the same fingerprint again inside its write transaction. Price, stock, website, image, approved damage-field rules, source-match, target-date, and audit protections remain in force. A failure on one product does not stop the others. Each successful product has its own audit record attributed to the admin who submitted the job.

Staff can leave the page and return through **Catalog > Product Filler Jobs**. The list and detail pages show queued, running, filled, skipped, and failed results. A queued job older than 15 minutes warns that Magento cron may be delayed. A running item older than two hours is reconciled by a 15-minute cron job: an existing matching apply audit marks it filled; otherwise it is marked failed for review. Requeue only after checking the target and audit.

This module uses Magento's existing `db` message queue and `consumers_runner` cron. `setup:upgrade` and will create the two declared batch tables and register the new queue through Magento's MySQL MQ recurring setup. Confirm `php bin/magento queue:consumers:list` includes `merlin.product_filler.fill` and that Magento cron runs. If consumer cron is unavailable, a worker can be run with `php bin/magento queue:consumers:start merlin.product_filler.fill --max-messages=20`. Background processing releases the admin request; it does not shorten the underlying product save.
