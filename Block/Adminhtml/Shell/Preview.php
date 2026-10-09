<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Block\Adminhtml\Shell;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Merlin\ProductFiller\Model\FillPlanBuilder;
use Merlin\ProductFiller\Model\PlanFingerprint;
use Merlin\ProductFiller\Model\ShellAssessment;
use Merlin\ProductFiller\Model\TargetEligibility;

class Preview extends Template
{
    private ?array $review = null;

    public function __construct(
        Context $context,
        private ProductRepositoryInterface $products,
        private ShellAssessment $assessments,
        private FillPlanBuilder $plans,
        private TargetEligibility $eligibility,
        private PlanFingerprint $fingerprints,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getReview(): array
    {
        if ($this->review !== null) {
            return $this->review;
        }
        $targetId = (int)$this->getRequest()->getParam('target_id');
        $sourceId = (int)$this->getRequest()->getParam('source_id');
        if ($targetId < 1) {
            return $this->review = ['error' => 'Choose a target product from the shell grid.'];
        }
        try {
            /** @var Product $target */
            $target = $this->products->getById($targetId, false, 0);
        } catch (NoSuchEntityException $exception) {
            return $this->review = ['error' => 'Target product was not found.'];
        }
        $dateReason = $this->eligibility->rejectionReason($target);
        if ($dateReason !== null) {
            return $this->review = ['error' => 'Target is outside ProductFiller scope: ' . $dateReason . '.'];
        }
        $assessment = $this->assessments->assess($target);
        $match = null;
        foreach ($assessment['candidates'] as $candidate) {
            if ((int)$candidate['product']->getId() === $sourceId) {
                $match = $candidate;
                break;
            }
        }
        if ($sourceId === 0) {
            $match = $assessment['best'];
        }
        $source = null;
        if ($match) {
            try {
                // Ranked candidates contain matching fields only; the plan
                // needs the selected source's complete attribute values.
                $source = $this->products->getById((int)$match['product']->getId(), false, 0, true);
            } catch (NoSuchEntityException $exception) {
                return $this->review = ['error' => 'Selected source product was not found.'];
            }
        }
        $plan = $source ? $this->plans->build($target, $source) : null;
        $canApply = $assessment['shell']['is_shell']
            && $assessment['reference'] !== ''
            && $match && $match['good'] && $match['confidence'] >= 80
            && $plan && $plan['generated'] && trim((string)($plan['generated']['google_sku'] ?? '')) !== ''
            && strlen((string)($plan['generated']['ebay_desc'] ?? '')) <= 80;
        if ($canApply && !$match['enabled']) {
            foreach ($assessment['candidates'] as $candidate) {
                if ($candidate['good'] && $candidate['enabled']) {
                    $canApply = false;
                    break;
                }
            }
        }
        return $this->review = [
            'target' => $target,
            'source' => $source,
            'assessment' => $assessment,
            'match' => $match,
            'plan' => $plan,
            'fingerprint' => $canApply ? $this->fingerprints->create($target, $source, $plan) : null,
            'can_apply' => $canApply,
            'requested_source_missing' => $sourceId > 0 && !$match,
        ];
    }

    public function canApply(): bool
    {
        return $this->_authorization->isAllowed('Merlin_ProductFiller::apply');
    }

    public function formatValue(Product $product, string $field, $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        }
        $text = trim((string)$value);
        if ($text === '') {
            return '(empty)';
        }
        $attribute = $product->getResource()->getAttribute($field);
        if ($attribute && $attribute->getFrontendInput() === 'select') {
            $label = $attribute->getSource()->getOptionText($value);
            if ($label !== false && !is_array($label) && (string)$label !== '' && (string)$label !== $text) {
                return $text . ' (' . (string)$label . ')';
            }
        }
        return $text;
    }
}
