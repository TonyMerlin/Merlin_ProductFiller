<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Catalog\Model\Product;

class GeneratedValueBuilder
{
    public function __construct(private NameParser $parser)
    {
    }

    public function build(Product $target, Product $source): array
    {
        $reference = trim((string)$target->getData('ebay_quote_ref'));
        if ($reference === '') {
            return ['values' => [], 'warnings' => ['Target ebay_quote_ref is missing; generated values are unavailable.']];
        }
        $base = trim((string)$source->getName());
        $base = preg_replace('/^graded\s+/i', '', $base) ?? $base;
        $sourceReference = trim((string)$source->getData('ebay_quote_ref'));
        if ($sourceReference !== '') {
            $base = preg_replace('/\s*\(' . preg_quote($sourceReference, '/') . '\)\s*$/i', '', $base) ?? $base;
        }
        $base = preg_replace('/\s+-\s+(?:NCSI\d+|\d{5})\s*$/i', '', $base) ?? $base;
        // Also remove a trailing stock reference when the old source lacks ebay_quote_ref.
        $base = preg_replace('/\s*\([A-Za-z]{1,5}-\d{2,}\)\s*$/', '', $base) ?? $base;
        $base = trim($base);
        if ($base === '') {
            return ['values' => [], 'warnings' => ['Source name has no usable model title.']];
        }

        $suffix = ' (' . $reference . ')';
        $model = trim((string)($source->getData('mpn') ?: $source->getData('modelno')));
        if ($model === '') {
            $model = $this->parser->parse($base)['model'];
        }
        $sourceGoogleSku = trim((string)$source->getData('google_sku'));
        $prefix = $model;
        if ($sourceGoogleSku !== '' && $sourceReference !== '' && preg_match('/^(.+)-' . preg_quote($sourceReference, '/') . '$/i', $sourceGoogleSku, $match)) {
            $prefix = $match[1];
        }
        $prefix = trim($prefix, " \t\n\r\0\x0B-");

        $ebayBase = $base . ' A Graded';
        $sourceEbay = trim((string)$source->getData('ebay_desc'));
        if ($sourceReference !== ''
            && substr_count(strtolower($sourceEbay), strtolower($sourceReference)) === 1
            && preg_match('/^(.+?)\s*\(' . preg_quote($sourceReference, '/') . '\)\s*$/i', $sourceEbay, $match)
            && $this->parser->parse($match[1])['model_key'] === $this->parser->modelKey($model)
        ) {
            $ebayBase = trim($match[1]);
        }
        $ebay = $ebayBase . $suffix;
        $warnings = [];
        if (strlen($ebay) > 80) {
            $available = 80 - strlen($suffix);
            $shortBase = $available > 0 ? rtrim(substr($ebayBase, 0, $available)) : '';
            if (strlen($shortBase) < strlen($ebayBase)) {
                $lastSpace = strrpos($shortBase, ' ');
                if ($lastSpace !== false) {
                    $shortBase = rtrim(substr($shortBase, 0, $lastSpace));
                }
            }
            $ebay = $shortBase . $suffix;
            $warnings[] = 'eBay title was shortened to fit 80 characters; review the wording.';
            if (strlen($ebay) > 80) {
                $warnings[] = 'Target reference is too long for the 80-character eBay title limit.';
            }
        }

        $name = 'Graded ' . $base . $suffix;
        return ['values' => [
            'name' => $name,
            'url_key' => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-'),
            'meta_title' => $base . $suffix,
            'google_sku' => $prefix !== '' ? $prefix . '-' . $reference : '',
            'google_title' => $name,
            'google_description' => $base . $suffix,
            'ebay_desc' => $ebay,
        ], 'warnings' => $warnings];
    }
}
