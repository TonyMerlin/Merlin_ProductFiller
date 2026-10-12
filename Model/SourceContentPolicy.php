<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Catalog\Model\Product;

class SourceContentPolicy
{
    private const MAX_HTML_BYTES = 200000;
    private const HTML_TAGS = [
        'a', 'abbr', 'article', 'aside', 'b', 'blockquote', 'br', 'caption', 'code',
        'dd', 'del', 'details', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure',
        'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'i',
        'li', 'main', 'nav', 'ol', 'p', 'pre', 's', 'section', 'small', 'span',
        'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'th', 'thead',
        'tr', 'u', 'ul',
    ];
    private const HTML_ATTRIBUTES = [
        'align', 'alt', 'class', 'colspan', 'decoding', 'height', 'href', 'id',
        'loading', 'rel', 'role', 'rowspan', 'src', 'style', 'tabindex', 'target', 'title', 'valign',
        'width',
    ];
    private const STYLE_PROPERTIES = [
        'background-color', 'border', 'border-bottom', 'border-color',
        'border-left', 'border-right', 'border-top', 'box-sizing', 'clear',
        'color', 'cursor', 'display', 'float', 'font-size', 'font-style', 'font-weight',
        'height', 'line-height', 'list-style-type', 'margin', 'margin-bottom',
        'margin-left', 'margin-right', 'margin-top', 'max-width', 'min-width',
        'opacity', 'padding', 'padding-bottom', 'padding-left', 'padding-right',
        'padding-top', 'text-align', 'text-decoration', 'vertical-align',
        'white-space', 'width',
    ];

    /** Formatted source descriptions are retained unchanged only if they pass. */
    public function problems(Product $source): array
    {
        $problems = [];
        foreach (['name', 'modelno', 'mpn', 'ebay_quote_ref', 'google_sku', 'ebay_desc'] as $field) {
            $value = (string)$source->getData($field);
            if ($value !== '' && $this->unsafePlainText($value)) {
                $problems[] = 'Source ' . $field . ' contains markup or control characters.';
            }
        }
        foreach (['description', 'short_description'] as $field) {
            $value = (string)$source->getData($field);
            if (trim($value) === '') {
                continue;
            }
            $problem = $this->htmlProblem($value);
            if ($problem !== null) {
                $problems[] = 'Source ' . $field . ' ' . $problem;
            }
        }
        return $problems;
    }

    private function unsafePlainText(string $value): bool
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match('/[<>\x00-\x1F\x7F]|\{\{|\}\}/', $decoded) === 1;
    }

    private function htmlProblem(string $html, int $depth = 0): ?string
    {
        if (strlen($html) > self::MAX_HTML_BYTES) {
            return 'exceeds the 200 KB review limit.';
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\{\{|\}\}|<\s*[!?]/', $html)) {
            return 'contains control characters, declarations, or Magento template directives.';
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return 'could not be parsed safely as HTML.';
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            $tag = strtolower($element->tagName);
            if (!in_array($tag, self::HTML_TAGS, true) && !in_array($tag, ['html', 'head', 'body'], true)) {
                return 'contains an unapproved HTML element (' . $tag . ').';
            }
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                if (!in_array($name, self::HTML_ATTRIBUTES, true)
                    && !str_starts_with($name, 'data-') && !str_starts_with($name, 'aria-')
                    && !($tag === 'table' && in_array($name, ['summary', 'cellspacing', 'cellpadding'], true))) {
                    return 'contains an unapproved HTML attribute (' . $name . ').';
                }
                $value = html_entity_decode($attribute->value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($name === 'tabindex' && !in_array($value, ['0', '-1'], true)) {
                    return 'contains an unapproved tabindex value.';
                }
                if ($tag === 'table' && in_array($name, ['cellspacing', 'cellpadding'], true)
                    && !preg_match('/\A[0-9]{1,3}\z/', $value)) {
                    return 'contains an unapproved table spacing value.';
                }
                if (in_array($name, ['data-original-title', 'data-bs-title'], true)
                    && strpbrk($value, '<>') !== false) {
                    if ($depth > 0 || $this->htmlProblem($value, $depth + 1) !== null) {
                        return 'contains unapproved tooltip HTML.';
                    }
                    continue;
                }
                if ($name === 'style' && !$this->safeStyle($value)) {
                    return 'contains unapproved inline CSS.';
                }
                $compact = preg_replace('/[\x00-\x20\x7F]+/', '', $value) ?? $value;
                if (preg_match('/[<>]|(?:\A|[^a-z])(?:javascript|vbscript|data|file|blob):/i', $compact)) {
                    return 'contains an unsafe HTML attribute value.';
                }
                if (in_array($name, ['href', 'src'], true)
                    && preg_match('/\A([A-Za-z][A-Za-z0-9+.-]*):/', $compact, $scheme)
                    && !in_array(strtolower($scheme[1]), ['http', 'https', 'mailto', 'tel'], true)) {
                    return 'contains an unapproved URL scheme.';
                }
            }
        }
        return null;
    }

    private function safeStyle(string $style): bool
    {
        if (preg_match('/[{}<>\\\\]|(?:url|expression|var|attr)\s*\(|@|!important/i', $style)) {
            return false;
        }
        $found = false;
        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '') {
                continue;
            }
            if (!preg_match('/\A([a-z-]+)\s*:\s*(.+)\z/i', $declaration, $parts)
                || !in_array(strtolower($parts[1]), self::STYLE_PROPERTIES, true)
                || !preg_match('/\A[\p{L}\p{N}\s#.%(),\/-]+\z/u', trim($parts[2]))
                || (strtolower($parts[1]) === 'cursor' && strtolower(trim($parts[2])) !== 'help')) {
                return false;
            }
            $found = true;
        }
        return $found;
    }
}
