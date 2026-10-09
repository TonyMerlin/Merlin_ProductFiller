<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

class NameParser
{
    public function parse(string $name): array
    {
        // The trailing Ncompass stock identifier is never a model candidate.
        $clean = preg_replace('/\s+-\s+(?:NCSI\d+|\d{5})\s*$/i', '', trim($name)) ?? trim($name);
        $clean = preg_replace('/\s*\([^)]*\)\s*$/', '', $clean) ?? $clean;
        $clean = preg_replace('/^\s*graded\s+/i', '', $clean) ?? $clean;
        preg_match('/^([\p{L}][\p{L}&-]*)/u', $clean, $brandMatch);
        $brand = strtolower((string)($brandMatch[1] ?? ''));

        $model = '';
        preg_match_all('/(?<![A-Za-z0-9])([A-Za-z][A-Za-z0-9-]*\d[A-Za-z0-9-]*(?:\/[A-Za-z0-9]+)?)(?![A-Za-z0-9])/i', $clean, $matches);
        foreach ($matches[1] ?? [] as $token) {
            if (!preg_match('/^\d+(?:cm|mm|kg|l|kw)$/i', $token) && !preg_match('/^NCSI\d+$/i', $token)) {
                $model = strtoupper($token);
                break;
            }
        }

        $type = '';
        foreach (['range cooker', 'cooker', 'hob', 'microwave', 'oven', 'fridge freezer', 'fridge', 'freezer', 'dishwasher', 'washing machine', 'tumble dryer', 'extractor'] as $hint) {
            if (stripos($clean, $hint) !== false) {
                $type = $hint;
                break;
            }
        }

        return ['brand' => $brand, 'model' => $model, 'model_key' => $this->modelKey($model), 'product_type_hint' => $type];
    }

    public function modelKey(string $model): string
    {
        $model = trim($model);
        // A short slash suffix is usually a revision (EH801HVB1E/G -> eh801hvb1e).
        if (preg_match('/^(.+)\/[A-Za-z0-9]{1,2}$/', $model, $match)) {
            $model = $match[1];
        }
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $model) ?? '');
    }
}
