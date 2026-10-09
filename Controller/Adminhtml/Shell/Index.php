<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Controller\Adminhtml\Shell;

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
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(__('Product Filler â€” Shell Products'));
        return $page;
    }
}
