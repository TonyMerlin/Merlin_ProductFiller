<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

class StockReferencePolicy
{
    public const MAX_LENGTH = 32;

    public function problem(string $rawReference): ?string
    {
        $reference = trim($rawReference);
        if ($reference === '') {
            return 'Target ebay_quote_ref is missing.';
        }
        if ($rawReference !== $reference) {
            return 'Target ebay_quote_ref must not have surrounding whitespace.';
        }
        if (strlen($reference) > self::MAX_LENGTH) {
            return 'Target ebay_quote_ref exceeds 32 characters.';
        }
        if (!preg_match('/\A[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\z/D', $reference)) {
            return 'Target ebay_quote_ref must contain only letters, digits, and single hyphens.';
        }
        return null;
    }
}
