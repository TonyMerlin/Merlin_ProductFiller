<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Merlin\ProductFiller\Model\NameParser;
use Merlin\ProductFiller\Model\ShellProductDetector;
use Merlin\ProductFiller\Model\SourceCandidateFinder;
use PHPUnit\Framework\TestCase;

class SourceCandidateFinderTest extends TestCase
{
    public function testStoredKeyNeedsIndependentModelEvidence(): void
    {
        $target = $this->product(1, 'Bosch CMA583MS0BB/G Microwave - NCSI123456');
        $keyOnly = $this->product(2, 'Bosch Combination Microwave', [
            'merlin_model_code_key' => 'cma583ms0bb',
        ]);
        $matching = $this->product(3, 'Graded Bosch CMA583MS0BB Built In Microwave', [
            'merlin_model_code_key' => 'cma583ms0bb',
            'modelno' => 'CMA583MS0BB',
            'mpn' => 'CMA583MS0BB',
        ]);

        $results = $this->finder([$keyOnly, $matching])->find($target);

        self::assertCount(1, $results);
        self::assertSame(3, (int)$results[0]['product']->getId());
        self::assertSame(100, $results[0]['confidence']);
        self::assertSame('exact brand; exact model key, exact modelno, exact mpn, model in name', $results[0]['match_reason']);
    }

    public function testConflictingSourceModelFieldsAreRejected(): void
    {
        $target = $this->product(1, 'Bosch CMA583MS0BB/G Microwave - NCSI123456');
        $candidates = [
            $this->product(2, 'Bosch Combination Microwave', [
                'merlin_model_code_key' => 'cma583ms0bb', 'modelno' => 'WRONG123',
            ]),
            $this->product(3, 'Bosch CMA583MS0BB Microwave', [
                'merlin_model_code_key' => 'cma583ms0bb', 'modelno' => 'CMA583MS0BB', 'mpn' => 'WRONG123',
            ]),
            $this->product(4, 'Bosch WRONG123 Microwave', [
                'merlin_model_code_key' => 'cma583ms0bb', 'modelno' => 'CMA583MS0BB',
            ]),
        ];

        self::assertSame([], $this->finder($candidates)->find($target));
    }

    public function testConflictingTargetModelFieldsPreventMatching(): void
    {
        $target = $this->product(1, 'Bosch CMA583MS0BB/G Microwave - NCSI123456', [
            'merlin_model_code_key' => 'cma583ms0bb', 'mpn' => 'WRONG123',
        ]);
        $source = $this->product(2, 'Bosch CMA583MS0BB Microwave', [
            'merlin_model_code_key' => 'cma583ms0bb', 'modelno' => 'CMA583MS0BB',
        ]);

        self::assertSame([], $this->finder([$source])->find($target));
    }

    public function testTwoIndependentModelFieldsStillScoreNinetyFiveWithoutStoredKey(): void
    {
        $target = $this->product(1, 'Bosch CMA583MS0BB/G Microwave - NCSI123456');
        $source = $this->product(2, 'Bosch Combination Microwave', [
            'modelno' => 'CMA583MS0BB', 'mpn' => 'CMA583MS0BB',
        ]);

        $results = $this->finder([$source])->find($target);

        self::assertCount(1, $results);
        self::assertSame(95, $results[0]['confidence']);
    }

    private function product(int $id, string $name, array $fields = []): Product
    {
        /** @var Product $product */
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $product->setData(array_merge([
            'entity_id' => $id,
            'name' => $name,
            'attribute_set_id' => 138,
            'status' => 2,
        ], $fields));
        return $product;
    }

    private function finder(array $candidates): SourceCandidateFinder
    {
        $collection = $this->createMock(Collection::class);
        foreach (['setStoreId', 'addAttributeToSelect', 'addAttributeToFilter', 'addFieldToFilter'] as $method) {
            $collection->method($method)->willReturnSelf();
        }
        $collection->method('getIterator')->willReturn(new \ArrayIterator($candidates));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $detector = $this->createMock(ShellProductDetector::class);
        $detector->method('categoryCount')->willReturn(1);
        $detector->method('imageCount')->willReturn(1);
        return new SourceCandidateFinder($factory, new NameParser(), $detector);
    }
}
