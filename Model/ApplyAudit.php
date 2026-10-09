<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\ResourceConnection;

class ApplyAudit
{
    public function __construct(private ResourceConnection $resource)
    {
    }

    public function beforeValues(Product $target, array $plan, array $offerBefore): array
    {
        $values = [];
        foreach (array_merge($plan['copy'], $plan['generated']) as $field => $value) {
            $values[$field] = $field === 'category_ids'
                ? array_map('intval', $target->getCategoryIds())
                : $target->getData($field);
        }
        foreach ($offerBefore as $storeId => $old) {
            $values['allow_make_an_offer_product@store_' . (int)$storeId] = $old;
        }
        return $values;
    }

    /**
     * Called before the product transaction commits. An audit failure must undo the fill.
     */
    public function record(
        Product $before,
        Product $source,
        Product $after,
        array $plan,
        array $match,
        string $mode,
        array $beforeValues,
        ?int $actorId = null,
        ?string $actorName = null
    ): int {
        $changes = [];
        foreach (array_merge($plan['copy'], $plan['generated']) as $field => $value) {
            $new = $field === 'category_ids'
                ? array_map('intval', $after->getCategoryIds())
                : $after->getData($field);
            $changes[$field] = ['before' => $beforeValues[$field] ?? null, 'after' => $new];
        }
        foreach ($plan['offer_store_ids'] as $storeId) {
            $field = 'allow_make_an_offer_product@store_' . (int)$storeId;
            $changes[$field] = [
                'before' => $beforeValues[$field] ?? null,
                'after' => 0,
            ];
        }
        $connection = $this->resource->getConnection('catalog');
        $connection->insert($this->resource->getTableName('merlin_product_filler_audit'), [
            'target_id' => (int)$before->getId(),
            'target_sku' => (string)$before->getSku(),
            'source_id' => (int)$source->getId(),
            'source_sku' => (string)$source->getSku(),
            'mode' => $mode,
            'actor_type' => $actorId === null ? 'cli' : 'admin',
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'confidence' => (int)$match['confidence'],
            'match_reason' => (string)$match['match_reason'],
            'changes_json' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'warnings_json' => json_encode($plan['warnings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        return (int)$connection->lastInsertId($this->resource->getTableName('merlin_product_filler_audit'));
    }
}
