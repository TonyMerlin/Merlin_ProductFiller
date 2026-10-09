<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;

class Audit extends Template
{
    public function __construct(
        Context $context,
        private ResourceConnection $resource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function targetFilter(): int
    {
        return max(0, (int)$this->getRequest()->getParam('target_id'));
    }

    public function entries(): array
    {
        $connection = $this->resource->getConnection('catalog');
        $select = $connection->select()
            ->from($this->resource->getTableName('merlin_product_filler_audit'))
            ->order('audit_id DESC')
            ->limit(50);
        if ($this->targetFilter() > 0) {
            $select->where('target_id = ?', $this->targetFilter());
        }
        return $connection->fetchAll($select);
    }

    public function changedFields(array $entry): string
    {
        $changes = json_decode((string)$entry['changes_json'], true);
        return is_array($changes) ? implode(', ', array_keys($changes)) : '';
    }

    public function details(array $entry): string
    {
        $changes = json_decode((string)$entry['changes_json'], true);
        $warnings = json_decode((string)$entry['warnings_json'], true);
        return (string)json_encode(
            ['changes' => $changes, 'warnings' => $warnings],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
