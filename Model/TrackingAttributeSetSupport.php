<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Model;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;

class TrackingAttributeSetSupport
{
    private const CODES = ['dataupdated', 'priceupdated', 'stockupdated'];

    public function __construct(private ResourceConnection $resource, private EavConfig $eavConfig)
    {
    }

    public function missing(int $setId): array
    {
        $connection = $this->resource->getConnection('catalog');
        $attributes = $this->attributes();
        $assigned = array_map('intval', $connection->fetchCol(
            $connection->select()->from($this->resource->getTableName('eav_entity_attribute'), ['attribute_id'])
                ->where('attribute_set_id = ?', $setId)
        ));
        $missing = [];
        foreach ($attributes as $code => $attribute) {
            if (!in_array((int)$attribute['attribute_id'], $assigned, true)) {
                $missing[] = $code;
            }
        }
        return $missing;
    }

    public function ensure(int $setId): array
    {
        $missing = $this->missing($setId);
        if (!$missing) {
            return [];
        }
        $connection = $this->resource->getConnection('catalog');
        $groupId = (int)$connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('eav_attribute_group'), ['attribute_group_id'])
                ->where('attribute_set_id = ?', $setId)->where('default_id = ?', 1)
        );
        if (!$groupId) {
            throw new \RuntimeException('Destination attribute set has no default attribute group.');
        }
        $attributes = $this->attributes();
        foreach ($missing as $code) {
            if (!isset($attributes[$code])) {
                throw new \RuntimeException('Ncompass tracking attribute is missing: ' . $code);
            }
            $connection->insert($this->resource->getTableName('eav_entity_attribute'), [
                'entity_type_id' => (int)$attributes[$code]['entity_type_id'],
                'attribute_set_id' => $setId,
                'attribute_group_id' => $groupId,
                'attribute_id' => (int)$attributes[$code]['attribute_id'],
                'sort_order' => 999,
            ]);
        }
        $this->eavConfig->clear();
        return $missing;
    }

    public function refresh(): void
    {
        $this->eavConfig->clear();
    }

    private function attributes(): array
    {
        $connection = $this->resource->getConnection('catalog');
        $select = $connection->select()->from(['a' => $this->resource->getTableName('eav_attribute')],
            ['attribute_id', 'entity_type_id', 'attribute_code'])
            ->join(['t' => $this->resource->getTableName('eav_entity_type')],
                't.entity_type_id = a.entity_type_id', [])
            ->where('t.entity_type_code = ?', 'catalog_product')
            ->where('a.attribute_code IN (?)', self::CODES);
        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[$row['attribute_code']] = $row;
        }
        if (count($result) !== count(self::CODES)) {
            throw new \RuntimeException('One or more Ncompass tracking attributes do not exist.');
        }
        return $result;
    }
}
