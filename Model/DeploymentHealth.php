<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Cron\Model\Config as CronConfig;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfig;
use Magento\Framework\MessageQueue\Topology\ConfigInterface as TopologyConfig;
use Merlin\ProductFiller\Setup\Patch\Data\RecordActivationDate;

class DeploymentHealth
{
    private const REQUIRED_ATTRIBUTES = [
        'ebay_quote_ref', 'name', 'url_key', 'meta_title', 'google_sku',
        'google_title', 'google_description', 'ebay_desc',
        'allow_make_an_offer_product', 'dataupdated', 'priceupdated', 'stockupdated',
    ];

    public function __construct(
        private ResourceConnection $resource,
        private DeploymentConfig $deployment,
        private ConsumerConfig $consumers,
        private TopologyConfig $topology,
        private CronConfig $cron,
        private TargetEligibility $eligibility
    ) {
    }

    public function check(bool $allowExistingInstall = false): array
    {
        $results = [];
        $this->guard($results, 'Activation date', fn () => $this->checkActivation($allowExistingInstall));
        $this->guard($results, 'Activation patch', fn () => $this->checkPatch());
        $this->guard($results, 'ProductFiller tables', fn () => $this->checkTables());
        $this->guard($results, 'Product attributes', fn () => $this->checkAttributes());
        $this->guard($results, 'Clearance category', fn () => $this->checkClearanceCategory());
        $this->guard($results, 'Queue configuration', fn () => $this->checkQueueConfiguration());
        $this->guard($results, 'Database queue', fn () => $this->checkQueueRegistration());
        $this->guard($results, 'Consumer cron configuration', fn () => $this->checkConsumerCron());
        $this->guard($results, 'Consumer cron activity', fn () => $this->checkCronActivity());
        $this->guard($results, 'Stale-job recovery cron', fn () => $this->checkRecoveryCron());
        $this->guard($results, 'Pending background jobs', fn () => $this->checkPendingJobs());
        return $results;
    }

    private function guard(array &$results, string $name, callable $check): void
    {
        try {
            [$ok, $detail] = $check();
            $results[] = ['status' => $ok ? 'PASS' : 'FAIL', 'check' => $name, 'detail' => $detail];
        } catch (\Throwable $exception) {
            $results[] = ['status' => 'FAIL', 'check' => $name, 'detail' => $exception->getMessage()];
        }
    }

    private function checkActivation(bool $allowExistingInstall): array
    {
        $cutoff = $this->eligibility->cutoff();
        if ($cutoff === null) {
            return [false, 'Missing or invalid UTC cutoff. Run setup:upgrade on this installation.'];
        }
        $connection = $this->resource->getConnection();
        $stored = $connection->fetchOne($connection->select()
            ->from($this->resource->getTableName('core_config_data'), ['value'])
            ->where('scope = ?', 'default')->where('scope_id = ?', 0)
            ->where('path = ?', TargetEligibility::CONFIG_PATH));
        if ((string)$stored !== $cutoff) {
            return [false, 'Effective cutoff differs from the default-scope database value; check copied config and cache.'];
        }
        $timestamp = strtotime($cutoff . ' UTC');
        if ($timestamp === false || $timestamp > time() + 300) {
            return [false, 'Cutoff is in the future; check the server clock and saved UTC value: ' . $cutoff];
        }
        if (!$allowExistingInstall && $timestamp < time() - 86400) {
            return [false, 'Cutoff ' . $cutoff . ' UTC is over 24 hours old. Check that dev configuration was not copied; use --allow-existing-install only for an established installation.'];
        }
        return [true, 'Target creation cutoff: ' . $cutoff . ' UTC.'];
    }

    private function checkPatch(): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('patch_list');
        if (!$connection->isTableExists($table)) {
            return [false, 'Magento patch_list table is missing.'];
        }
        $installed = (bool)$connection->fetchOne($connection->select()->from($table, ['patch_id'])
            ->where('patch_name = ?', RecordActivationDate::class));
        return $installed
            ? [true, 'RecordActivationDate data patch ran.']
            : [false, 'RecordActivationDate data patch has not run; run setup:upgrade.'];
    }

    private function checkTables(): array
    {
        $connection = $this->resource->getConnection('catalog');
        $required = [
            'merlin_product_filler_audit' => ['audit_id', 'target_id', 'source_id', 'changes_json'],
            'merlin_product_filler_batch' => ['batch_id', 'actor_id', 'actor_name', 'created_at'],
            'merlin_product_filler_batch_item' => [
                'item_id', 'batch_id', 'target_id', 'source_id', 'fingerprint',
                'status', 'result', 'audit_id', 'started_at', 'finished_at',
            ],
        ];
        $missing = [];
        foreach ($required as $name => $columns) {
            $table = $this->resource->getTableName($name);
            if (!$connection->isTableExists($table)) {
                $missing[] = $name;
                continue;
            }
            $actual = array_keys($connection->describeTable($table));
            foreach (array_diff($columns, $actual) as $column) {
                $missing[] = $name . '.' . $column;
            }
        }
        return $missing
            ? [false, 'Missing tables/columns: ' . implode(', ', $missing) . '. Run setup:upgrade.']
            : [true, 'Audit, batch, and batch-item tables contain required columns.'];
    }

    private function checkAttributes(): array
    {
        $connection = $this->resource->getConnection('catalog');
        $codes = array_values(array_unique(array_merge(self::REQUIRED_ATTRIBUTES, FillPlanBuilder::COPY_FIELDS)));
        $select = $connection->select()->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_code'])
            ->join(['t' => $this->resource->getTableName('eav_entity_type')],
                't.entity_type_id = a.entity_type_id', [])
            ->where('t.entity_type_code = ?', 'catalog_product')
            ->where('a.attribute_code IN (?)', $codes);
        $missing = array_diff($codes, $connection->fetchCol($select));
        $defaultSet = (bool)$connection->fetchOne($connection->select()
            ->from($this->resource->getTableName('eav_attribute_set'), ['attribute_set_id'])
            ->where('attribute_set_id = ?', 4));
        if (!$defaultSet) {
            $missing[] = 'Default attribute set (ID 4)';
        }
        return $missing
            ? [false, 'Missing product attributes or set: ' . implode(', ', $missing) . '. Install catalog attributes before filling.']
            : [true, count($codes) . ' required product attributes and Default set 4 exist.'];
    }

    private function checkClearanceCategory(): array
    {
        $connection = $this->resource->getConnection('catalog');
        $exists = (bool)$connection->fetchOne($connection->select()
            ->from($this->resource->getTableName('catalog_category_entity'), ['entity_id'])
            ->where('entity_id = ?', 550));
        return $exists
            ? [true, 'Clearance category 550 exists for the Make an Offer rule.']
            : [false, 'Clearance category 550 is missing; verify the live category ID before fills.'];
    }

    private function checkQueueConfiguration(): array
    {
        $consumer = $this->consumers->getConsumer(BackgroundBatch::TOPIC);
        if ($consumer->getQueue() !== BackgroundBatch::TOPIC || $consumer->getConnection() !== 'db') {
            return [false, 'Consumer is not bound to the ProductFiller database queue.'];
        }
        foreach ($this->topology->getQueues() as $queue) {
            if ($queue->getName() === BackgroundBatch::TOPIC && $queue->getConnection() === 'db') {
                return [true, 'ProductFiller consumer and DB queue topology are configured.'];
            }
        }
        return [false, 'ProductFiller database queue topology is missing.'];
    }

    private function checkQueueRegistration(): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('queue');
        if (!$connection->isTableExists($table)) {
            return [false, 'Magento database queue table is missing; run setup:upgrade.'];
        }
        $registered = (bool)$connection->fetchOne($connection->select()->from($table, ['id'])
            ->where('name = ?', BackgroundBatch::TOPIC));
        return $registered
            ? [true, 'Queue is registered in the database.']
            : [false, 'Consumer is configured but its DB queue is not registered. Run setup:upgrade.'];
    }

    private function checkConsumerCron(): array
    {
        if (!(bool)$this->deployment->get('cron_consumers_runner/cron_run', true)) {
            return [false, 'cron_consumers_runner/cron_run is disabled in app/etc/env.php.'];
        }
        $allowed = $this->deployment->get('cron_consumers_runner/consumers', []);
        if (is_array($allowed) && $allowed && !in_array(BackgroundBatch::TOPIC, $allowed, true)) {
            return [false, 'ProductFiller consumer is absent from cron_consumers_runner/consumers allowlist.'];
        }
        $jobs = $this->cron->getJobs();
        return isset($jobs['consumers']['consumers_runner'])
            ? [true, 'Magento consumer runner is enabled and scheduled.']
            : [false, 'Magento consumers_runner cron job is not scheduled.'];
    }

    private function checkCronActivity(): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('cron_schedule');
        if (!$connection->isTableExists($table)) {
            return [false, 'Magento cron_schedule table is missing.'];
        }
        $last = $connection->fetchOne($connection->select()->from($table, ['MAX(executed_at)'])
            ->where('job_code = ?', 'consumers_runner')->where('status = ?', 'success'));
        if (!$last || strtotime((string)$last . ' UTC') < time() - 1200) {
            return [false, 'No successful consumers_runner execution in the last 20 minutes. Check Magento cron. Last: ' . ($last ?: 'never')];
        }
        return [true, 'Last successful consumers_runner execution: ' . $last . ' UTC.'];
    }

    private function checkRecoveryCron(): array
    {
        $jobs = $this->cron->getJobs();
        return isset($jobs['default']['merlin_product_filler_reconcile_stale_jobs'])
            ? [true, 'Stale-job recovery cron is scheduled.']
            : [false, 'ProductFiller stale-job recovery cron is not scheduled.'];
    }

    private function checkPendingJobs(): array
    {
        $connection = $this->resource->getConnection('catalog');
        $table = $this->resource->getTableName('merlin_product_filler_batch_item');
        if (!$connection->isTableExists($table)) {
            return [false, 'Batch-item table is missing; job status cannot be checked.'];
        }
        $oldQueued = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($table)
            . " WHERE status = 'queued' AND created_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE");
        $oldRunning = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($table)
            . " WHERE status = 'running' AND started_at < UTC_TIMESTAMP() - INTERVAL 2 HOUR");
        return $oldQueued || $oldRunning
            ? [false, $oldQueued . ' queued item(s) over 15 minutes old; ' . $oldRunning . ' running item(s) over 2 hours old. Check cron and Product Filler Jobs.']
            : [true, 'No delayed queued or stale running ProductFiller items.'];
    }
}
