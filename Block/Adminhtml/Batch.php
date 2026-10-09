<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Merlin\ProductFiller\Model\BackgroundBatch;

class Batch extends Template
{
    public function __construct(Context $context, private BackgroundBatch $batches, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function latest(): array
    {
        return $this->batches->latest();
    }

    public function details(): ?array
    {
        $id = (int)$this->getRequest()->getParam('id');
        return $id > 0 ? $this->batches->get($id) : null;
    }

    public function isStalled(array $item): bool
    {
        return $item['status'] === 'running' && $item['started_at']
            && strtotime((string)$item['started_at'] . ' UTC') < time() - 3600;
    }

    public function isDelayed(array $item): bool
    {
        return $item['status'] === 'queued'
            && strtotime((string)$item['created_at'] . ' UTC') < time() - 900;
    }
}
