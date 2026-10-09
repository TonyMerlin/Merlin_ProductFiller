<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;

class TargetEligibility
{
    public const CONFIG_PATH = 'merlin_product_filler/activation/created_from';

    public function __construct(private ScopeConfigInterface $scopeConfig)
    {
    }

    public function cutoff(): ?string
    {
        $value = trim((string)$this->scopeConfig->getValue(self::CONFIG_PATH));
        return $this->validUtcDate($value) ? $value : null;
    }

    public function rejectionReason(Product $target): ?string
    {
        $cutoff = $this->cutoff();
        if ($cutoff === null) {
            return 'ProductFiller activation date is missing or invalid; run bin/magento setup:upgrade.';
        }
        $createdAt = trim((string)$target->getCreatedAt());
        if (!$this->validUtcDate($createdAt)) {
            return 'target created_at is missing or invalid';
        }
        if (strcmp($createdAt, $cutoff) < 0) {
            return 'target was created before ProductFiller activation (' . $cutoff . ' UTC)';
        }
        return null;
    }

    private function validUtcDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }
}
