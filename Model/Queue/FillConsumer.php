<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model\Queue;

use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Merlin\ProductFiller\Console\Command\ApplyCommand;
use Merlin\ProductFiller\Model\MassFillReview;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class FillConsumer
{
    public function __construct(
        private ResourceConnection $resource,
        private State $appState,
        private MassFillReview $reviews,
        private ApplyCommand $apply,
        private LoggerInterface $logger
    ) {
    }

    public function process(string $message): void
    {
        if (!preg_match('/^[1-9][0-9]*$/', $message)) {
            $this->logger->error('ProductFiller received an invalid queue item ID.');
            return;
        }
        $itemId = (int)$message;
        $connection = $this->resource->getConnection('catalog');
        $itemTable = $this->resource->getTableName('merlin_product_filler_batch_item');
        $batchTable = $this->resource->getTableName('merlin_product_filler_batch');
        $item = $connection->fetchRow($connection->select()->from(['i' => $itemTable])
            ->join(['b' => $batchTable], 'b.batch_id = i.batch_id', ['actor_id', 'actor_name'])
            ->where('i.item_id = ?', $itemId));
        if (!$item) {
            $this->logger->error('ProductFiller queue item was not found.', ['item_id' => $itemId]);
            return;
        }
        // A duplicated queue delivery must never run the same fill twice.
        $claimed = $connection->update($itemTable, [
            'status' => 'running', 'started_at' => new \Zend_Db_Expr('UTC_TIMESTAMP()'),
        ], ['item_id = ?' => $itemId, 'status = ?' => 'queued']);
        if ($claimed !== 1) {
            return;
        }

        try {
            $result = $this->appState->emulateAreaCode(Area::AREA_ADMINHTML,
                fn (): array => $this->runReviewedFill($item));
        } catch (\Throwable $exception) {
            $this->logger->error('ProductFiller background fill failed', [
                'item_id' => $itemId, 'target_id' => (int)$item['target_id'], 'exception' => $exception,
            ]);
            $result = ['status' => 'failed', 'result' => $exception->getMessage(), 'audit_id' => null];
        }
        $connection->update($itemTable, [
            'status' => $result['status'],
            'result' => mb_substr((string)$result['result'], 0, 2000),
            'audit_id' => $result['audit_id'],
            'finished_at' => new \Zend_Db_Expr('UTC_TIMESTAMP()'),
        ], ['item_id = ?' => $itemId, 'status = ?' => 'running']);
    }

    private function runReviewedFill(array $item): array
    {
        $rows = $this->reviews->review([(int)$item['target_id']]);
        $row = $rows[0] ?? null;
        if (!$row || !$row['eligible']) {
            return ['status' => 'skipped', 'result' => $row['reason'] ?? 'Product is no longer eligible.', 'audit_id' => null];
        }
        if ((int)$row['source']->getId() !== (int)$item['source_id']
            || (string)$row['target']->getSku() !== (string)$item['target_sku']
            || !hash_equals((string)$item['fingerprint'], (string)$row['fingerprint'])) {
            return ['status' => 'skipped', 'result' => 'Product or source changed since the batch was reviewed.', 'audit_id' => null];
        }
        $this->apply->setAdminActor((int)$item['actor_id'], (string)$item['actor_name']);
        $input = new ArrayInput([
            '--target-id' => (string)$item['target_id'],
            '--source-id' => (string)$item['source_id'],
            '--confirm-sku' => (string)$item['target_sku'],
        ]);
        $output = new BufferedOutput();
        $code = $this->apply->run($input, $output);
        $text = trim(strip_tags($output->fetch()));
        if ($code !== 0) {
            return ['status' => 'failed', 'result' => strtok($text, "\r\n") ?: 'Apply rejected.', 'audit_id' => null];
        }
        $auditId = preg_match('/^Audit ID:\s*([0-9]+)/m', $text, $match) ? (int)$match[1] : null;
        return ['status' => 'filled', 'result' => 'Filled and verified.', 'audit_id' => $auditId];
    }
}
