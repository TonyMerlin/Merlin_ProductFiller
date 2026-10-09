<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Controller\Adminhtml\Shell;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Merlin\ProductFiller\Model\MassFillReview;

class MassPreview extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Merlin_ProductFiller::apply';

    public function __construct(
        Context $context,
        private PageFactory $pages,
        private MassFillReview $reviews
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $this->reviews->selectedIds($this->getRequest()->getParam('product'));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $this->resultRedirectFactory->create()->setPath('merlin_product_filler/shell/index');
        }
        $page = $this->pages->create();
        $page->setActiveMenu('Merlin_ProductFiller::view');
        $page->getConfig()->getTitle()->prepend(__('Product Filler â€” Review Selected Fills'));
        return $page;
    }
}
