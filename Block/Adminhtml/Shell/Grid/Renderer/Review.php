<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Block\Adminhtml\Shell\Grid\Renderer;

use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Merlin\ProductFiller\Model\ShellAssessment;

class Review extends AbstractRenderer
{
    public function __construct(
        \Magento\Backend\Block\Context $context,
        private ShellAssessment $assessment,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(DataObject $row): string
    {
        if (!$row instanceof Product) {
            return '';
        }
        $review = $this->assessment->assess($row);
        $best = $review['best'];
        switch ($this->getColumn()->getId()) {
            case 'sku':
                $targetUrl = $this->getUrl('catalog/product/edit', ['id' => (int)$row->getId()]);
                return '<a href="' . $this->escapeUrl($targetUrl) . '">'
                    . $this->escapeHtml((string)$row->getSku()) . '</a>';
            case 'identity':
                $identity = $review['identity'];
                return $this->escapeHtml(($identity['brand'] ?: '(unknown)') . ' / ' . ($identity['model'] ?: '(unknown)'));
            case 'source':
                if (!$best) {
                    return 'â€”';
                }
                $source = $best['product'];
                $sourceUrl = $this->getUrl('catalog/product/edit', ['id' => (int)$source->getId()]);
                return '<a href="' . $this->escapeUrl($sourceUrl) . '">'
                    . $this->escapeHtml((string)$source->getId() . ' / ' . (string)$source->getSku()) . '</a>'
                    . '<br/><small>' . ($best['enabled'] ? 'Enabled' : 'Disabled') . '</small>';
            case 'confidence':
                return $best ? $this->escapeHtml($best['confidence'] . '/100 â€” ' . $best['match_reason']) : 'â€”';
            case 'recommended_action':
                return $this->escapeHtml($review['action']);
            case 'review_link':
                $url = $this->getUrl('merlin_product_filler/shell/preview', [
                    'target_id' => (int)$row->getId(),
                    'source_id' => $best ? (int)$best['product']->getId() : 0,
                ]);
                return '<a href="' . $this->escapeUrl($url) . '">' . $this->escapeHtml(__('Preview')) . '</a>';
        }
        return '';
    }
}
