<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\ResourceConnection;

class ShellProductDetector
{
    public function __construct(
        private ResourceConnection $resource,
        private TargetEligibility $eligibility
    )
    {
    }

    public function categoryCount(Product $product): int
    {
        return (int)$this->resource->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->resource->getTableName('catalog_category_product') . ' WHERE product_id = ?',
            [(int)$product->getId()]
        );
    }

    public function imageCount(Product $product): int
    {
        return (int)$this->resource->getConnection()->fetchOne(
            'SELECT COUNT(DISTINCT value_id) FROM ' . $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity') . ' WHERE entity_id = ?',
            [(int)$product->getId()]
        );
    }

    public function detect(Product $product): array
    {
        $reasons = [];
        if (!preg_match('/^(?:NCSI\d+|\d{5})$/i', (string)$product->getSku())) {
            $reasons[] = 'SKU is not NCSI plus digits or five digits';
        }
        if ((int)$product->getAttributeSetId() !== 4) {
            $reasons[] = 'attribute set is not Default (4)';
        }
        if ((int)$product->getStatus() !== 2) {
            $reasons[] = 'product is not disabled';
        }
        $categories = $this->categoryCount($product);
        $images = $this->imageCount($product);
        if ($categories !== 0) {
            $reasons[] = 'has ' . $categories . ' categories';
        }
        if ($images !== 0) {
            $reasons[] = 'has ' . $images . ' images';
        }
        $dateReason = $this->eligibility->rejectionReason($product);
        if ($dateReason !== null) {
            $reasons[] = $dateReason;
        }
        $reference = trim((string)$product->getData('ebay_quote_ref'));
        return [
            'is_shell' => $reasons === [],
            'eligible' => $dateReason === null,
            'eligibility_reason' => $dateReason,
            'reason' => $reasons ? implode('; ', $reasons) : ($reference === '' ? 'shell shape; ebay_quote_ref missing' : 'shell shape; ebay_quote_ref present'),
            'category_count' => $categories,
            'image_count' => $images,
        ];
    }
}
