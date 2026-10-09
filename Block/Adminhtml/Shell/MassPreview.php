<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Block\Adminhtml\Shell;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Merlin\ProductFiller\Model\MassFillReview;

class MassPreview extends Template
{
    private ?array $rows = null;

    public function __construct(
        Context $context,
        private MassFillReview $reviews,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function rows(): array
    {
        if ($this->rows === null) {
            $ids = $this->reviews->selectedIds($this->getRequest()->getParam('product'));
            $this->rows = $this->reviews->review($ids);
        }
        return $this->rows;
    }
}
