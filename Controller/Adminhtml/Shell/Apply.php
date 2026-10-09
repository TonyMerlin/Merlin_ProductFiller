<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Controller\Adminhtml\Shell;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Merlin\ProductFiller\Console\Command\ApplyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class Apply extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Merlin_ProductFiller::apply';

    public function __construct(Context $context, private ApplyCommand $applyCommand)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $targetId = (int)$this->getRequest()->getParam('target_id');
        $sourceId = (int)$this->getRequest()->getParam('source_id');
        $redirect = $this->resultRedirectFactory->create();
        if ($targetId < 1 || $sourceId < 1 || (int)$this->getRequest()->getParam('reviewed') !== 1) {
            $this->messageManager->addErrorMessage(__('Review the preview and select the confirmation checkbox before applying.'));
            return $redirect->setPath('merlin_product_filler/shell/preview', ['target_id' => $targetId, 'source_id' => $sourceId]);
        }
        $input = new ArrayInput([
            '--target-id' => (string)$targetId,
            '--source-id' => (string)$sourceId,
            '--confirm-sku' => (string)$this->getRequest()->getParam('confirm_sku'),
            '--review-fingerprint' => (string)$this->getRequest()->getParam('review_fingerprint'),
        ], $this->applyCommand->getDefinition());
        $output = new BufferedOutput();
        try {
            $user = $this->_auth->getUser();
            if ($user && (int)$user->getId() > 0) {
                $this->applyCommand->setAdminActor((int)$user->getId(), (string)$user->getUserName());
            }
            $exitCode = $this->applyCommand->run($input, $output);
        } catch (\Throwable $exception) {
            $exitCode = 1;
            $output->writeln($exception->getMessage());
        }
        $message = trim(strip_tags($output->fetch()));
        if ($exitCode === 0) {
            $this->messageManager->addSuccessMessage(__('Product %1 was filled from source %2. Protected stock-unit data was verified unchanged.', $targetId, $sourceId));
            return $redirect->setPath('merlin_product_filler/shell/index');
        }
        $firstLine = strtok($message, "\r\n") ?: 'Apply failed; no product was filled.';
        $this->messageManager->addErrorMessage($firstLine);
        return $redirect->setPath('merlin_product_filler/shell/preview', ['target_id' => $targetId, 'source_id' => $sourceId]);
    }
}
