<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\Source\Table;

class FillPlanBuilder
{
    private const CLEARANCE_CATEGORY_ID = 550;
    private const MAKE_OFFER_ATTRIBUTE = 'allow_make_an_offer_product';
    public const PROMO_FIELDS = ['google_promo_code', 'google_promo_id', 'in_promo'];
    public const OVEN_SPEC_FIELDS = ['primary_oven_function', 'main_oven_capacity', 'oven_cleaning'];
    public const DAMAGE_FIELDS = ['damage_cond', 'damage_legs', 'damage_fkit', 'damage_wkit', 'damage_ustand'];
    private const SOURCE_OVERRIDES = [
        'description', 'short_description', 'google_promo_code', 'google_promo_id', 'in_promo',
        'primary_oven_function', 'main_oven_capacity', 'oven_cleaning',
        'damage_cond', 'damage_legs', 'damage_fkit', 'damage_wkit', 'damage_ustand',
    ];
    public const COPY_FIELDS = [
        'manufacturer', 'modelno', 'mpn', 'ean', 'condition', 'grade', 'item_type',
        'appliance_width', 'height', 'depth', 'color', 'colour', 'connection',
        'hob_type', 'hob_zones', 'hard_wired', 'energy_efficiency_rating',
        'manufacturers_warranty', 'google_product_type', 'ebay_product_type',
        'description', 'short_description', 'google_promo_code', 'google_promo_id',
        'in_promo', 'primary_oven_function', 'main_oven_capacity', 'oven_cleaning',
        'damage_cond', 'damage_legs', 'damage_fkit', 'damage_wkit', 'damage_ustand',
        'merlin_model_brand_key', 'merlin_model_code_key',
    ];
    public const KEEP_FIELDS = [
        'sku', 'ebay_quote_ref', 'price', 'special_price', 'special_from_date',
        'special_to_date', 'status', 'stock quantity', 'stock status', 'website assignment',
        'dataupdated', 'priceupdated', 'stockupdated',
    ];
    public const SKIP_FIELDS = [
        'image', 'small_image', 'thumbnail', 'amazon_image', 'ebay_image',
        'media gallery records', 'merlin_master_sku', 'merlin_last_master_id',
        'created_at', 'updated_at', 'damage_front', 'damage_back', 'damage_left',
        'damage_right', 'damage_top', 'damage_handles',
    ];

    public function __construct(
        private GeneratedValueBuilder $generatedValueBuilder,
        private TrackingAttributeSetSupport $trackingAttributes
    )
    {
    }

    public function build(Product $target, Product $source): array
    {
        $copy = [];
        $preserved = [];
        $warnings = [];
        if ((int)$target->getAttributeSetId() !== (int)$source->getAttributeSetId()) {
            $copy['attribute_set_id'] = (int)$source->getAttributeSetId();
        }
        $sourceCategories = array_map('intval', $source->getCategoryIds());
        if ($sourceCategories && array_map('intval', $target->getCategoryIds()) !== $sourceCategories) {
            $copy['category_ids'] = $sourceCategories;
        }
        foreach (self::COPY_FIELDS as $field) {
            $sourceValue = $source->getData($field);
            if ($this->emptyValue($sourceValue)) {
                if (in_array($field, ['description', 'short_description'], true) && !$this->emptyValue($target->getData($field))) {
                    $warnings[] = 'Source ' . $field . ' is empty; the existing target value will be kept.';
                }
                continue;
            }
            if (!$this->sourceOptionAvailable($source, $field, $sourceValue)) {
                $warnings[] = 'Source ' . $field . ' uses unavailable option ' . $sourceValue . '; it will not be copied.';
                continue;
            }
            $targetValue = $target->getData($field);
            if ((string)$targetValue === (string)$sourceValue) {
                continue;
            }
            if (in_array($field, self::SOURCE_OVERRIDES, true)) {
                $copy[$field] = $sourceValue;
                if (!$this->emptyValue($targetValue)) {
                    $warnings[] = 'Existing target ' . $field . ' will be replaced with the source value.';
                }
            } elseif ($this->emptyValue($targetValue)) {
                $copy[$field] = $sourceValue;
            } else {
                $preserved[] = $field;
            }
        }
        $generated = $this->generatedValueBuilder->build($target, $source);
        $offerStoreIds = [];
        $finalCategories = $copy['category_ids'] ?? array_map('intval', $target->getCategoryIds());
        if (!in_array(self::CLEARANCE_CATEGORY_ID, $finalCategories, true)) {
            // Neklo defaults this store-scoped attribute to Yes, so set No explicitly.
            $generated['values'][self::MAKE_OFFER_ATTRIBUTE] = 0;
            $offerStoreIds = array_values(array_unique(array_filter(
                array_map('intval', $target->getStoreIds()),
                static fn (int $storeId): bool => $storeId > 0
            )));
            if ((string)$target->getData(self::MAKE_OFFER_ATTRIBUTE) !== '0') {
                $warnings[] = 'Allow Make an Offer will be set to No because the final categories do not include clearance category 550.';
            }
            if ($offerStoreIds) {
                $warnings[] = 'Allow Make an Offer will also be set to No in assigned store views: '
                    . implode(', ', $offerStoreIds) . '.';
            }
        } else {
            $preserved[] = self::MAKE_OFFER_ATTRIBUTE;
            $warnings[] = 'Clearance category 550 is included; the target Allow Make an Offer setting will be kept.';
        }
        return [
            'copy' => $copy,
            'preserved' => $preserved,
            'generated' => $generated['values'],
            'offer_store_ids' => $offerStoreIds,
            'attribute_set_additions' => $this->trackingAttributes->missing((int)$source->getAttributeSetId()),
            'warnings' => array_merge($warnings, $generated['warnings']),
        ];
    }

    public function buildPromotionsOnly(Product $target, Product $source): array
    {
        $copy = [];
        $warnings = [];
        foreach (self::PROMO_FIELDS as $field) {
            $sourceValue = $source->getData($field);
            if ($this->emptyValue($sourceValue)) {
                $warnings[] = 'Source ' . $field . ' is empty; target value will be kept.';
                continue;
            }
            if (!$this->sourceOptionAvailable($source, $field, $sourceValue)) {
                $warnings[] = 'Source ' . $field . ' uses unavailable option ' . $sourceValue . '; it will not be copied.';
                continue;
            }
            $targetValue = $target->getData($field);
            if ((string)$targetValue === (string)$sourceValue) {
                continue;
            }
            $copy[$field] = $sourceValue;
            if (!$this->emptyValue($targetValue)) {
                $warnings[] = 'Existing target ' . $field . ' will be replaced with the source value.';
            }
        }
        return [
            'copy' => $copy,
            'preserved' => [],
            'generated' => [],
            'offer_store_ids' => [],
            'attribute_set_additions' => [],
            'warnings' => $warnings,
        ];
    }

    public function buildOvenSpecsOnly(Product $target, Product $source): array
    {
        return $this->buildSelectedFieldsOnly($target, $source, self::OVEN_SPEC_FIELDS);
    }

    public function buildDamageConditionOnly(Product $target, Product $source): array
    {
        return $this->buildSelectedFieldsOnly($target, $source, ['damage_cond']);
    }

    public function buildDamageFieldsOnly(Product $target, Product $source): array
    {
        return $this->buildSelectedFieldsOnly($target, $source, self::DAMAGE_FIELDS);
    }

    private function buildSelectedFieldsOnly(Product $target, Product $source, array $fields): array
    {
        $copy = [];
        $warnings = [];
        foreach ($fields as $field) {
            $sourceValue = $source->getData($field);
            if ($this->emptyValue($sourceValue)) {
                $warnings[] = 'Source ' . $field . ' is empty; target value will be kept.';
                continue;
            }
            if (!$this->sourceOptionAvailable($source, $field, $sourceValue)) {
                $warnings[] = 'Source ' . $field . ' uses unavailable option ' . $sourceValue . '; it will not be copied.';
                continue;
            }
            $targetValue = $target->getData($field);
            if ((string)$targetValue === (string)$sourceValue) {
                continue;
            }
            $copy[$field] = $sourceValue;
            if (!$this->emptyValue($targetValue)) {
                $warnings[] = 'Existing target ' . $field . ' will be replaced with the source value.';
            }
        }
        return [
            'copy' => $copy,
            'preserved' => [],
            'generated' => [],
            'offer_store_ids' => [],
            'attribute_set_additions' => [],
            'warnings' => $warnings,
        ];
    }

    private function sourceOptionAvailable(Product $source, string $field, $value): bool
    {
        $attribute = $source->getResource()->getAttribute($field);
        if (!$attribute || $attribute->getFrontendInput() !== 'select'
            || $attribute->getSourceModel() !== Table::class) {
            return true;
        }
        $label = $attribute->getSource()->getOptionText($value);
        return $label !== false && !is_array($label) && trim((string)$label) !== '';
    }

    private function emptyValue($value): bool
    {
        return $value === null || trim((string)$value) === '' || $value === '0' || $value === 0;
    }
}
