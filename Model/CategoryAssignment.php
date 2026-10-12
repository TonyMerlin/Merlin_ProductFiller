<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Catalog\Model\Indexer\Product\Category as ProductCategoryIndexer;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CategoryLink;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Indexer\IndexerRegistry;

class CategoryAssignment
{
    public function __construct(
        private ResourceConnection $resource,
        private CategoryLink $links,
        private IndexerRegistry $indexers
    ) {
    }

    public function assign(Product $target, array $categoryIds, AdapterInterface $transaction): void
    {
        // Magento's resource writes catalog_category_product directly. It must
        // share the apply transaction so a later verification failure rolls it back.
        if ($transaction !== $this->resource->getConnection()) {
            throw new \RuntimeException('Category links do not share the ProductFiller transaction. No changes made.');
        }
        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        if (!$ids || count($ids) !== count($categoryIds) || min($ids) < 1) {
            throw new \RuntimeException('The reviewed category IDs are invalid. No changes made.');
        }
        $existing = array_map('intval', $transaction->fetchCol($transaction->select()
            ->from($this->resource->getTableName('catalog_category_entity'), ['entity_id'])
            ->where('entity_id IN (?)', $ids)));
        sort($existing);
        $expected = $ids;
        sort($expected);
        if ($existing !== $expected) {
            throw new \RuntimeException('A reviewed category no longer exists. No changes made.');
        }

        // This uses the same product link resource as Magento product saves,
        // without saving the whole category or changing other product positions.
        $links = array_map(static fn (int $id): array => ['category_id' => $id, 'position' => 0], $ids);
        $this->links->saveCategoryLinks($target, $links);
        $indexer = $this->indexers->get(ProductCategoryIndexer::INDEXER_ID);
        if (!$indexer->isScheduled()) {
            $indexer->reindexRow((int)$target->getId());
        }
    }
}
