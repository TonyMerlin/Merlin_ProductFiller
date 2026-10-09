<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Controller\Adminhtml\Shell;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Preview extends Action
{
    public const ADMIN_RESOURCE = 'Merlin_ProductFiller::view';

    public function __construct(Context $context, private PageFactory $pages)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pages->create();
        $page->setActiveMenu('Merlin_ProductFiller::view');
        $page->getConfig()->getTitle()->prepend(__('Product Filler â€” Review Fill'));
        return $page;
    }
}
