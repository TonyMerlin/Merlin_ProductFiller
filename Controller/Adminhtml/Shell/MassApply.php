<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Controller\Adminhtml\Shell;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Merlin\ProductFiller\Model\BackgroundBatch;
use Merlin\ProductFiller\Model\MassFillReview;

class MassApply extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Merlin_ProductFiller::apply';

    public function __construct(
        Context $context,
        private MassFillReview $reviews,
        private BackgroundBatch $batches
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('merlin_product_filler/shell/index');
        if ((int)$this->getRequest()->getParam('reviewed') !== 1) {
            $this->messageManager->addErrorMessage(__('Review the selected fills and tick the confirmation box.'));
            return $redirect;
        }
        try {
            $ids = $this->reviews->selectedIds($this->getRequest()->getParam('product'));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $redirect;
        }
        $expectedSources = $this->getRequest()->getParam('expected_source');
        $expectedSkus = $this->getRequest()->getParam('expected_sku');
        $expectedFingerprints = $this->getRequest()->getParam('expected_fingerprint');
        if (!is_array($expectedSources) || !is_array($expectedSkus) || !is_array($expectedFingerprints)) {
            $this->messageManager->addErrorMessage(__('The reviewed selection is incomplete. Select and review the products again.'));
            return $redirect;
        }

        // Store the reviewed plan fingerprint. The worker checks it again
        // immediately before each individual guarded apply.
        $rows = $this->reviews->review($ids);
        $user = $this->_auth->getUser();
        if (!$user || (int)$user->getId() < 1) {
            $this->messageManager->addErrorMessage(__('An admin user is required to queue a fill.'));
            return $redirect;
        }
        $ready = [];
        $skipped = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (!$row['eligible']) {
                $skipped[] = $id . ': ' . $row['reason'];
                continue;
            }
            $source = $row['source'];
            $target = $row['target'];
            if (!is_string($expectedSources[$id] ?? null)
                || $expectedSources[$id] !== (string)$source->getId()
                || !is_string($expectedSkus[$id] ?? null)
                || $expectedSkus[$id] !== (string)$target->getSku()
                || !is_string($expectedFingerprints[$id] ?? null)
                || !hash_equals($row['fingerprint'], $expectedFingerprints[$id])) {
                $skipped[] = $id . ': product or source changed since the bulk review.';
                continue;
            }
            $ready[] = [
                'target_id' => $id,
                'target_sku' => (string)$target->getSku(),
                'source_id' => (int)$source->getId(),
                'fingerprint' => $row['fingerprint'],
            ];
        }
        if ($skipped) {
            $this->messageManager->addWarningMessage(__('Skipped %1 product(s): %2',
                count($skipped), implode(' | ', $skipped)));
        }
        if (!$ready) {
            $this->messageManager->addErrorMessage(__('No reviewed products remain eligible for a background fill.'));
            return $redirect;
        }
        try {
            $batch = $this->batches->enqueue($ready, (int)$user->getId(), (string)$user->getUserName());
        } catch (\Throwable $exception) {
            $this->messageManager->addErrorMessage(__('Could not create the background fill batch: %1', $exception->getMessage()));
            return $redirect;
        }
        if ($batch['queued']) {
            $this->messageManager->addSuccessMessage(__(
                'Queued %1 product(s) in batch #%2. You can continue working while they fill in the background.',
                $batch['queued'], $batch['batch_id']
            ));
        }
        if ($batch['failed']) {
            $this->messageManager->addErrorMessage(__('%1 product(s) could not be queued. See batch #%2 for details.',
                $batch['failed'], $batch['batch_id']));
        }
        return $this->resultRedirectFactory->create()->setPath('merlin_product_filler/batch/view',
            ['id' => $batch['batch_id']]);
    }
}
