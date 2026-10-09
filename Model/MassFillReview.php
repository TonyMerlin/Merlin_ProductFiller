<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class MassFillReview
{
    public const MAX_SELECTED = 20;
    public const MIN_CONFIDENCE = 95;

    public function __construct(
        private ProductRepositoryInterface $products,
        private ShellAssessment $assessments,
        private FillPlanBuilder $plans,
        private PlanFingerprint $fingerprints
    ) {
    }

    public function selectedIds($raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw) || !$raw || count($raw) > self::MAX_SELECTED) {
            throw new LocalizedException(__(
                'Select between 1 and %1 shell products at a time.',
                self::MAX_SELECTED
            ));
        }
        $ids = [];
        foreach ($raw as $value) {
            if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/', (string)$value)) {
                throw new LocalizedException(__('The selected product IDs are invalid.'));
            }
            $id = (int)$value;
            if ($id < 1 || in_array($id, $ids, true)) {
                throw new LocalizedException(__('The selected product IDs are invalid or duplicated.'));
            }
            $ids[] = $id;
        }
        return $ids;
    }

    public function review(array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            try {
                /** @var Product $target */
                $target = $this->products->getById($id, false, 0, true);
            } catch (NoSuchEntityException $exception) {
                $rows[] = ['id' => $id, 'eligible' => false, 'reason' => 'Target product no longer exists.'];
                continue;
            }
            // Queue consumers can handle multiple messages in one PHP process.
            // Reassess the force-reloaded target for every reviewed item.
            $this->assessments->forget($id);
            $assessment = $this->assessments->assess($target);
            $best = $assessment['best'];
            $row = ['id' => $id, 'target' => $target, 'source' => $best['product'] ?? null,
                'confidence' => (int)($best['confidence'] ?? 0), 'eligible' => false];
            if (!$assessment['shell']['is_shell']) {
                $row['reason'] = 'Not an eligible shell: ' . $assessment['shell']['reason'];
            } elseif ($assessment['reference'] === '') {
                $row['reason'] = 'Target ebay_quote_ref is missing.';
            } elseif (!$best || !$best['good'] || (int)$best['confidence'] < self::MIN_CONFIDENCE) {
                $row['reason'] = 'No strong 95/100 or better exact source match is available.';
            } else {
                try {
                    /** @var Product $source */
                    $source = $this->products->getById((int)$best['product']->getId(), false, 0, true);
                } catch (NoSuchEntityException $exception) {
                    $row['reason'] = 'Matched source product no longer exists.';
                    $rows[] = $row;
                    continue;
                }
                $row['source'] = $source;
                $plan = $this->plans->build($target, $source);
                if (!$plan['generated'] || trim((string)($plan['generated']['google_sku'] ?? '')) === '') {
                    $row['reason'] = 'Generated values are incomplete.';
                } elseif (strlen((string)($plan['generated']['ebay_desc'] ?? '')) > 80) {
                    $row['reason'] = 'Generated eBay title exceeds 80 characters.';
                } else {
                    $row['eligible'] = true;
                    $row['reason'] = 'Ready for review';
                    $row['plan'] = $plan;
                    $row['fingerprint'] = $this->fingerprints->create($target, $source, $plan);
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

}
