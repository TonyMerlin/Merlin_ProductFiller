<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Controller\Adminhtml\Batch;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class View extends Action
{
    public const ADMIN_RESOURCE = 'Merlin_ProductFiller::view';

    public function __construct(Context $context, private PageFactory $pages)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pages->create();
        $page->setActiveMenu('Merlin_ProductFiller::batches');
        $page->getConfig()->getTitle()->prepend(__('Product Filler Job #%1', (int)$this->getRequest()->getParam('id')));
        return $page;
    }
}
