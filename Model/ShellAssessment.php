<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Catalog\Model\Product;

class ShellAssessment
{
    private array $cache = [];

    public function __construct(
        private ShellProductDetector $detector,
        private SourceCandidateFinder $finder
    ) {
    }

    public function forget(int $productId): void
    {
        unset($this->cache[$productId]);
    }

    public function assess(Product $target): array
    {
        $id = (int)$target->getId();
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        $shell = $this->detector->detect($target);
        $identity = $this->finder->identity($target);
        $reference = trim((string)$target->getData('ebay_quote_ref'));
        $candidates = $shell['is_shell'] ? $this->finder->find($target) : [];
        $best = $candidates[0] ?? null;
        if (!$shell['eligible']) {
            $action = 'Outside ProductFiller date range';
        } elseif (!$shell['is_shell']) {
            $action = 'Not a shell product';
        } elseif ($reference === '' || $identity['brand'] === '' || $identity['model_key'] === '') {
            $action = 'Needs model/reference data';
        } elseif (!$best) {
            $action = 'New model setup required';
        } elseif (!$best['good']) {
            $action = 'Manual review required';
        } else {
            $action = 'Preview fill using matched source';
        }
        return $this->cache[$id] = [
            'shell' => $shell,
            'identity' => $identity,
            'reference' => $reference,
            'candidates' => $candidates,
            'best' => $best,
            'action' => $action,
        ];
    }
}
