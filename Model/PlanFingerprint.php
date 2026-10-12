<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Catalog\Model\Product;

class PlanFingerprint
{
    public function create(Product $target, Product $source, array $plan): string
    {
        $before = [];
        foreach (array_merge($plan['copy'], $plan['generated']) as $field => $value) {
            $before[$field] = $field === 'category_ids'
                ? array_map('intval', $target->getCategoryIds())
                : $target->getData($field);
        }

        return hash('sha256', json_encode([
            'version' => 4,
            'target_id' => (int)$target->getId(),
            'target_sku' => (string)$target->getSku(),
            'target_name' => (string)$target->getName(),
            'target_reference' => (string)$target->getData('ebay_quote_ref'),
            'target_status' => (string)$target->getStatus(),
            'target_price' => (string)$target->getData('price'),
            'target_special_price' => (string)$target->getData('special_price'),
            'target_special_from_date' => (string)$target->getData('special_from_date'),
            'target_special_to_date' => (string)$target->getData('special_to_date'),
            'target_attribute_set_id' => (int)$target->getAttributeSetId(),
            'target_category_ids' => array_map('intval', $target->getCategoryIds()),
            'target_manufacturer' => (string)$target->getData('manufacturer'),
            'target_modelno' => (string)$target->getData('modelno'),
            'target_mpn' => (string)$target->getData('mpn'),
            'target_model_brand_key' => (string)$target->getData('merlin_model_brand_key'),
            'target_model_code_key' => (string)$target->getData('merlin_model_code_key'),
            'target_updated_at' => (string)$target->getUpdatedAt(),
            'source_id' => (int)$source->getId(),
            'source_sku' => (string)$source->getSku(),
            'source_name' => (string)$source->getName(),
            'source_status' => (string)$source->getStatus(),
            'source_price_for_fallback' => array_key_exists('price', $plan['copy']) ? (string)$source->getData('price') : null,
            'source_special_price_for_fallback' => array_key_exists('price', $plan['copy']) ? (string)$source->getData('special_price') : null,
            'source_special_from_date_for_fallback' => array_key_exists('price', $plan['copy']) ? (string)$source->getData('special_from_date') : null,
            'source_special_to_date_for_fallback' => array_key_exists('price', $plan['copy']) ? (string)$source->getData('special_to_date') : null,
            'source_attribute_set_id' => (int)$source->getAttributeSetId(),
            'source_category_ids' => array_map('intval', $source->getCategoryIds()),
            'source_manufacturer' => (string)$source->getData('manufacturer'),
            'source_modelno' => (string)$source->getData('modelno'),
            'source_mpn' => (string)$source->getData('mpn'),
            'source_model_brand_key' => (string)$source->getData('merlin_model_brand_key'),
            'source_model_code_key' => (string)$source->getData('merlin_model_code_key'),
            'source_updated_at' => (string)$source->getUpdatedAt(),
            'before' => $before,
            'copy' => $plan['copy'],
            'content_errors' => $plan['content_errors'] ?? [],
            'price_error' => $plan['price_error'] ?? null,
            'generated' => $plan['generated'],
            'offer_store_ids' => $plan['offer_store_ids'],
            // Another fill can add these tracking attributes to the shared
            // destination set between review and apply. Their absence from
            // the plan does not change any reviewed product value.
            'preserved' => $plan['preserved'],
            'warnings' => $plan['warnings'],
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
