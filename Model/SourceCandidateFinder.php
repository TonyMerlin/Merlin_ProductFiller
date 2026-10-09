<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

class SourceCandidateFinder
{
    public function __construct(
        private CollectionFactory $collectionFactory,
        private NameParser $parser,
        private ShellProductDetector $detector
    ) {
    }

    public function identity(Product $product): array
    {
        $parsed = $this->parser->parse((string)$product->getName());
        $brandKey = trim((string)$product->getData('merlin_model_brand_key'));
        $manufacturer = $this->manufacturerName($product);
        $brand = $brandKey !== '' ? strtolower($brandKey) : ($manufacturer !== '' ? strtolower($manufacturer) : $parsed['brand']);
        $model = trim((string)$product->getData('modelno'));
        if ($model === '') {
            $model = trim((string)$product->getData('mpn'));
        }
        if ($model === '') {
            $model = $parsed['model'];
        }
        $modelKey = trim((string)$product->getData('merlin_model_code_key'));
        if ($modelKey === '') {
            $modelKey = $this->parser->modelKey($model);
        }
        return [
            'brand' => preg_replace('/[^a-z0-9]/', '', strtolower($brand)) ?? '',
            'model' => $model,
            'model_key' => $this->parser->modelKey($modelKey),
            'product_type_hint' => $parsed['product_type_hint'],
        ];
    }

    /**
     * Return ranked exact matches with only matching and ranking attributes loaded.
     * Load the selected product through the repository before building a fill plan.
     */
    public function find(Product $target): array
    {
        $identity = $this->identity($target);
        if ($identity['brand'] === '' || $identity['model_key'] === ''
            || $this->matchingModelEvidence($target, $identity['model_key']) === null) {
            return [];
        }
        $raw = $identity['model'];
        $base = preg_replace('/\/[A-Za-z0-9]{1,2}$/', '', $raw) ?? $raw;
        $filters = [
            ['attribute' => 'merlin_model_code_key', 'eq' => $identity['model_key']],
            ['attribute' => 'modelno', 'eq' => $raw],
            ['attribute' => 'mpn', 'eq' => $raw],
            ['attribute' => 'name', 'like' => '%' . addcslashes($base, '%_') . '%'],
        ];
        if ($base !== $raw) {
            $filters[] = ['attribute' => 'modelno', 'eq' => $base];
            $filters[] = ['attribute' => 'mpn', 'eq' => $base];
        }
        $collection = $this->collectionFactory->create();
        // OR lookup fields are sparse on older products. Inner joins here would
        // discard a product lacking any one attribute before the OR is evaluated.
        // Rank on only the fields needed here. Loading every EAV attribute for
        // every possible source makes the grid and bulk review needlessly slow.
        $collection->setStoreId(0)->addAttributeToSelect([
            'name', 'status', 'manufacturer', 'modelno', 'mpn',
            'merlin_model_brand_key', 'merlin_model_code_key',
            'description', 'short_description',
        ])->addAttributeToFilter($filters, null, 'left');
        $collection->addFieldToFilter('entity_id', ['neq' => (int)$target->getId()]);
        $results = [];
        foreach ($collection as $candidate) {
            $candidateIdentity = $this->identity($candidate);
            if ($candidateIdentity['brand'] !== $identity['brand'] || $candidateIdentity['model_key'] !== $identity['model_key']) {
                continue;
            }
            $matched = $this->matchingModelEvidence($candidate, $identity['model_key']);
            if ($matched === null) {
                continue;
            }
            $categories = $this->detector->categoryCount($candidate);
            $images = $this->detector->imageCount($candidate);
            $nonDefault = (int)$candidate->getAttributeSetId() !== 4;
            $hasModel = trim((string)$candidate->getData('modelno')) !== '' || trim((string)$candidate->getData('mpn')) !== '';
            $hasDescriptions = trim((string)$candidate->getData('description')) !== '' && trim((string)$candidate->getData('short_description')) !== '';
            $quality = (int)$nonDefault + (int)($categories > 0) + (int)($images > 0)
                + (int)($this->manufacturerName($candidate) !== '') + (int)$hasModel + (int)$hasDescriptions;
            $good = $quality >= 2 && ($nonDefault || $categories > 0 || $images > 0);
            $results[] = [
                'product' => $candidate,
                'confidence' => $good ? (in_array('exact model key', $matched, true) ? 100 : (count($matched) > 1 ? 95 : 85)) : 65,
                'match_reason' => 'exact brand; ' . implode(', ', $matched) . ($good ? '' : '; source lacks enough populated fields'),
                'good' => $good,
                'enabled' => (int)$candidate->getStatus() === 1,
                'non_default' => $nonDefault,
                'category_count' => $categories,
                'image_count' => $images,
                'manufacturer' => $this->manufacturerName($candidate) !== '',
                'model_field' => $hasModel,
                'descriptions' => $hasDescriptions,
            ];
        }
        usort($results, static function (array $a, array $b): int {
            foreach (['good', 'enabled', 'non_default', 'category_count', 'image_count', 'manufacturer', 'model_field', 'descriptions'] as $key) {
                $comparison = $b[$key] <=> $a[$key];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            $date = strcmp((string)$b['product']->getUpdatedAt(), (string)$a['product']->getUpdatedAt());
            return $date ?: ((int)$b['product']->getId() <=> (int)$a['product']->getId());
        });
        // Products in this ranking contain only the fields selected above.
        // Callers building a fill plan load their chosen source in full.
        return $results;
    }

    /**
     * A stored model key is only a lookup hint. A matching modelno, MPN, or
     * parsed name must corroborate it, and no populated model field may
     * contradict the target. Apply and bulk review both use these candidates.
     */
    private function matchingModelEvidence(Product $product, string $expectedKey): ?array
    {
        $matched = [];
        $storedKey = trim((string)$product->getData('merlin_model_code_key'));
        if ($storedKey !== '') {
            if ($this->parser->modelKey($storedKey) !== $expectedKey) {
                return null;
            }
            $matched[] = 'exact model key';
        }

        $independentMatches = 0;
        foreach (['modelno' => 'exact modelno', 'mpn' => 'exact mpn'] as $field => $reason) {
            $value = trim((string)$product->getData($field));
            if ($value === '') {
                continue;
            }
            if ($this->parser->modelKey($value) !== $expectedKey) {
                return null;
            }
            $matched[] = $reason;
            $independentMatches++;
        }

        $nameModel = $this->parser->parse((string)$product->getName())['model'];
        if ($nameModel !== '') {
            if ($this->parser->modelKey($nameModel) !== $expectedKey) {
                return null;
            }
            $matched[] = 'model in name';
            $independentMatches++;
        }

        return $independentMatches > 0 ? $matched : null;
    }

    private function manufacturerName(Product $product): string
    {
        $value = $product->getData('manufacturer');
        if ($value === null || $value === '' || $value === '0') {
            return '';
        }
        $label = $product->getAttributeText('manufacturer');
        return is_string($label) ? trim($label) : '';
    }
}
