<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model\Queue;

use Magento\Framework\App\ResourceConnection;

class StaleJobReconciler
{
    public function __construct(private ResourceConnection $resource)
    {
    }

    public function execute(): void
    {
        $connection = $this->resource->getConnection('catalog');
        $items = $this->resource->getTableName('merlin_product_filler_batch_item');
        $batches = $this->resource->getTableName('merlin_product_filler_batch');
        $audit = $this->resource->getTableName('merlin_product_filler_audit');
        $stale = $connection->fetchAll($connection->select()->from(['i' => $items])
            ->join(['b' => $batches], 'b.batch_id = i.batch_id', ['actor_id'])
            ->where('i.status = ?', 'running')
            ->where('i.started_at < UTC_TIMESTAMP() - INTERVAL 2 HOUR')
            ->order('i.started_at ASC')->limit(50));
        foreach ($stale as $item) {
            $auditId = (int)$connection->fetchOne($connection->select()->from($audit, ['audit_id'])
                ->where('target_id = ?', (int)$item['target_id'])
                ->where('source_id = ?', (int)$item['source_id'])
                ->where('actor_id = ?', (int)$item['actor_id'])
                ->where('mode = ?', 'fill')
                ->where('created_at >= ?', $item['started_at'])
                ->order('audit_id DESC')->limit(1));
            $connection->update($items, [
                'status' => $auditId ? 'filled' : 'failed',
                'result' => $auditId
                    ? 'Fill completed; status recovered from its audit record.'
                    : 'Worker stopped before completion. Review the target and audit before queueing it again.',
                'audit_id' => $auditId ?: null,
                'finished_at' => new \Zend_Db_Expr('UTC_TIMESTAMP()'),
            ], [
                'item_id = ?' => (int)$item['item_id'],
                'status = ?' => 'running',
                'started_at < UTC_TIMESTAMP() - INTERVAL 2 HOUR',
            ]);
        }
    }
}
