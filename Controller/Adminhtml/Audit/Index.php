<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Controller\Adminhtml\Audit;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Merlin_ProductFiller::view';

    public function __construct(Context $context, private PageFactory $pages)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pages->create();
        $page->setActiveMenu('Merlin_ProductFiller::audit');
        $page->getConfig()->getTitle()->prepend(__('Product Filler Audit'));
        return $page;
    }
}
