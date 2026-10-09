<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

class BackgroundBatch
{
    public const TOPIC = 'merlin.product_filler.fill';

    public function __construct(
        private ResourceConnection $resource,
        private PublisherInterface $publisher,
        private LoggerInterface $logger
    ) {
    }

    public function enqueue(array $items, int $actorId, string $actorName): array
    {
        if (!$items || $actorId < 1) {
            throw new \InvalidArgumentException('A background batch requires reviewed products and an admin actor.');
        }
        $queueConnection = $this->resource->getConnection();
        $queueId = (int)$queueConnection->fetchOne($queueConnection->select()
            ->from($this->resource->getTableName('queue'), ['id'])
            ->where('name = ?', self::TOPIC));
        if (!$queueId) {
            throw new \RuntimeException('ProductFiller background queue is not registered. Run Magento setup:upgrade before queuing fills.');
        }
        $connection = $this->resource->getConnection('catalog');
        $atomicQueue = $connection === $queueConnection;
        $batchTable = $this->resource->getTableName('merlin_product_filler_batch');
        $itemTable = $this->resource->getTableName('merlin_product_filler_batch_item');
        $itemIds = [];
        $connection->beginTransaction();
        try {
            $connection->insert($batchTable, ['actor_id' => $actorId, 'actor_name' => $actorName]);
            $batchId = (int)$connection->lastInsertId($batchTable);
            foreach ($items as $item) {
                $connection->insert($itemTable, [
                    'batch_id' => $batchId,
                    'target_id' => (int)$item['target_id'],
                    'target_sku' => (string)$item['target_sku'],
                    'source_id' => (int)$item['source_id'],
                    'fingerprint' => (string)$item['fingerprint'],
                    'status' => 'queued',
                ]);
                $itemIds[] = (int)$connection->lastInsertId($itemTable);
            }
            if ($atomicQueue) {
                foreach ($itemIds as $itemId) {
                    $this->publisher->publish(self::TOPIC, (string)$itemId);
                }
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        if ($atomicQueue) {
            return ['batch_id' => $batchId, 'queued' => count($itemIds), 'failed' => 0];
        }

        // A split catalog DB cannot share the queue transaction. Report each
        // individual publish failure instead of leaving it marked as queued.
        $published = 0;
        foreach ($itemIds as $itemId) {
            try {
                $this->publisher->publish(self::TOPIC, (string)$itemId);
                ++$published;
            } catch (\Throwable $exception) {
                $connection->update($itemTable, [
                    'status' => 'failed',
                    'result' => 'Could not publish this item to the background queue.',
                    'finished_at' => new \Zend_Db_Expr('UTC_TIMESTAMP()'),
                ], ['item_id = ?' => $itemId, 'status = ?' => 'queued']);
                $this->logger->error('ProductFiller queue publish failed', [
                    'batch_id' => $batchId, 'item_id' => $itemId, 'exception' => $exception,
                ]);
            }
        }
        return ['batch_id' => $batchId, 'queued' => $published, 'failed' => count($itemIds) - $published];
    }

    public function latest(int $limit = 50): array
    {
        $connection = $this->resource->getConnection('catalog');
        $batchTable = $this->resource->getTableName('merlin_product_filler_batch');
        $itemTable = $this->resource->getTableName('merlin_product_filler_batch_item');
        $select = $connection->select()->from(['b' => $batchTable], ['batch_id', 'created_at', 'actor_name'])
            ->joinLeft(['i' => $itemTable], 'i.batch_id = b.batch_id', [
                'total' => 'COUNT(i.item_id)',
                'queued' => "SUM(i.status = 'queued')",
                'running' => "SUM(i.status = 'running')",
                'filled' => "SUM(i.status = 'filled')",
                'skipped' => "SUM(i.status = 'skipped')",
                'failed' => "SUM(i.status = 'failed')",
            ])->group('b.batch_id')->order('b.batch_id DESC')->limit(max(1, min($limit, 100)));
        return $connection->fetchAll($select);
    }

    public function get(int $batchId): ?array
    {
        $connection = $this->resource->getConnection('catalog');
        $batch = $connection->fetchRow($connection->select()
            ->from($this->resource->getTableName('merlin_product_filler_batch'))
            ->where('batch_id = ?', $batchId));
        if (!$batch) {
            return null;
        }
        $items = $connection->fetchAll($connection->select()
            ->from($this->resource->getTableName('merlin_product_filler_batch_item'))
            ->where('batch_id = ?', $batchId)->order('item_id ASC'));
        return ['batch' => $batch, 'items' => $items];
    }
}
