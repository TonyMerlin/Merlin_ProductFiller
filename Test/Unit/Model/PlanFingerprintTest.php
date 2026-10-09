<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Merlin\ProductFiller\Model\PlanFingerprint;
use PHPUnit\Framework\TestCase;

class PlanFingerprintTest extends TestCase
{
    public function testReviewedPlanChangesWhenSourceOrProposedValueChanges(): void
    {
        $fingerprints = new PlanFingerprint();
        $target = $this->product(1, 'NCSI123456', 'Bosch CMA583MS0BB Microwave', [
            'attribute_set_id' => 4, 'category_ids' => [], 'modelno' => null,
        ]);
        $source = $this->product(2, 'NCSI654321', 'Graded Bosch CMA583MS0BB Microwave', [
            'attribute_set_id' => 138, 'category_ids' => [594], 'modelno' => 'CMA583MS0BB',
        ]);
        $plan = $this->plan();
        $reviewed = $fingerprints->create($target, $source, $plan);

        self::assertSame($reviewed, $fingerprints->create($target, $source, $plan));
        $source->setData('name', 'Graded Bosch WRONG123 Microwave');
        self::assertNotSame($reviewed, $fingerprints->create($target, $source, $plan));
        $source->setData('name', 'Graded Bosch CMA583MS0BB Microwave');
        $plan['copy']['modelno'] = 'WRONG123';
        self::assertNotSame($reviewed, $fingerprints->create($target, $source, $plan));
    }

    public function testReviewedPlanChangesWhenTargetOrAttributeSetPlanChanges(): void
    {
        $fingerprints = new PlanFingerprint();
        $target = $this->product(1, 'NCSI123456', 'Bosch CMA583MS0BB Microwave', [
            'attribute_set_id' => 4, 'category_ids' => [], 'modelno' => null,
        ]);
        $source = $this->product(2, 'NCSI654321', 'Graded Bosch CMA583MS0BB Microwave', [
            'attribute_set_id' => 138, 'category_ids' => [594], 'modelno' => 'CMA583MS0BB',
        ]);
        $plan = $this->plan();
        $reviewed = $fingerprints->create($target, $source, $plan);

        $target->setData('modelno', 'CMA583MS0BB');
        self::assertNotSame($reviewed, $fingerprints->create($target, $source, $plan));
        $target->setData('modelno', null);
        $plan['attribute_set_additions'][] = 'stockupdated';
        self::assertNotSame($reviewed, $fingerprints->create($target, $source, $plan));
    }

    private function product(int $id, string $sku, string $name, array $fields): Product
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSku'])
            ->getMock();
        $product->method('getSku')->willReturn($sku);
        $product->setData(array_merge([
            'entity_id' => $id,
            'sku' => $sku,
            'name' => $name,
            'status' => 2,
            'ebay_quote_ref' => 'TEST-01',
            'updated_at' => '2026-10-09 12:00:00',
        ], $fields));
        return $product;
    }

    private function plan(): array
    {
        return [
            'copy' => ['attribute_set_id' => 138, 'category_ids' => [594], 'modelno' => 'CMA583MS0BB'],
            'generated' => ['name' => 'Graded Bosch CMA583MS0BB Microwave (TEST-01)'],
            'offer_store_ids' => [1],
            'attribute_set_additions' => [],
            'preserved' => [],
            'warnings' => [],
        ];
    }
}
