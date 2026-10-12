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

    public function testNewestGoodEnabledSourceWinsBeforeNewerDisabledAndOlderRicherSources(): void
    {
        $target = $this->product(1, 'Bosch CMA583MS0BB/G Microwave - NCSI123456');
        $olderRich = $this->product(10, 'Graded Bosch CMA583MS0BB Microwave', [
            'status' => 1, 'merlin_model_code_key' => 'cma583ms0bb',
            'modelno' => 'CMA583MS0BB', 'mpn' => 'CMA583MS0BB',
            'test_category_count' => 6, 'test_image_count' => 10,
            'description' => 'Long description', 'short_description' => 'Short description',
        ]);
        $newerEnabled = $this->product(20, 'Graded Bosch CMA583MS0BB Microwave', [
            'status' => 1, 'modelno' => 'CMA583MS0BB', 'mpn' => 'CMA583MS0BB',
            'test_category_count' => 1, 'test_image_count' => 0,
        ]);
        $newestDisabled = $this->product(30, 'Graded Bosch CMA583MS0BB Microwave', [
            'status' => 2, 'merlin_model_code_key' => 'cma583ms0bb',
            'modelno' => 'CMA583MS0BB', 'mpn' => 'CMA583MS0BB',
        ]);

        $results = $this->finder([$olderRich, $newestDisabled, $newerEnabled])->find($target);

        self::assertSame([20, 10, 30], array_map(
            static fn (array $row): int => (int)$row['product']->getId(), $results
        ));
        self::assertSame(95, $results[0]['confidence']);
    }

    public function testNewestGoodDisabledSourceWinsWhenNoGoodEnabledSourceExists(): void
    {
        $target = $this->product(1, 'Bosch CMA583MS0BB/G Microwave - NCSI123456');
        $older = $this->product(10, 'Graded Bosch CMA583MS0BB Microwave', [
            'modelno' => 'CMA583MS0BB', 'mpn' => 'CMA583MS0BB',
            'test_category_count' => 6, 'test_image_count' => 10,
        ]);
        $newer = $this->product(20, 'Graded Bosch CMA583MS0BB Microwave', [
            'modelno' => 'CMA583MS0BB', 'mpn' => 'CMA583MS0BB',
            'test_category_count' => 1, 'test_image_count' => 0,
        ]);

        $results = $this->finder([$older, $newer])->find($target);

        self::assertSame([20, 10], array_map(
            static fn (array $row): int => (int)$row['product']->getId(), $results
        ));
    }

    public function testMissingTargetPriceSelectsNewestPricedEnabledThenPricedDisabled(): void
    {
        $target = $this->product(1, 'Bosch CMA583MS0BB/G Microwave - NCSI123456', ['price' => null]);
        $olderEnabled = $this->product(10, 'Graded Bosch CMA583MS0BB Microwave', [
            'status' => 1, 'price' => '579.000000', 'modelno' => 'CMA583MS0BB',
        ]);
        $newerEnabledWithoutPrice = $this->product(20, 'Graded Bosch CMA583MS0BB Microwave', [
            'status' => 1, 'price' => null, 'modelno' => 'CMA583MS0BB',
        ]);
        $newestDisabled = $this->product(30, 'Graded Bosch CMA583MS0BB Microwave', [
            'status' => 2, 'price' => '599.000000', 'modelno' => 'CMA583MS0BB',
        ]);

        $results = $this->finder([$newerEnabledWithoutPrice, $newestDisabled, $olderEnabled])->find($target);

        self::assertSame([10, 30, 20], array_map(
            static fn (array $row): int => (int)$row['product']->getId(), $results
        ));
        self::assertTrue($results[0]['priced']);
    }

    public function testPricedTargetStillSelectsNewestEnabledRegardlessOfSourcePrice(): void
    {
        $target = $this->product(1, 'Bosch CMA583MS0BB/G Microwave - NCSI123456', [
            'price' => '679.000000',
        ]);
        $olderPriced = $this->product(10, 'Graded Bosch CMA583MS0BB Microwave', [
            'status' => 1, 'price' => '579.000000', 'modelno' => 'CMA583MS0BB',
        ]);
        $newerUnpriced = $this->product(20, 'Graded Bosch CMA583MS0BB Microwave', [
            'status' => 1, 'price' => null, 'modelno' => 'CMA583MS0BB',
        ]);

        $results = $this->finder([$olderPriced, $newerUnpriced])->find($target);

        self::assertSame(20, (int)$results[0]['product']->getId());
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
        $detector->method('categoryCount')->willReturnCallback(
            static fn (Product $product): int => (int)($product->getData('test_category_count') ?? 1)
        );
        $detector->method('imageCount')->willReturnCallback(
            static fn (Product $product): int => (int)($product->getData('test_image_count') ?? 1)
        );
        return new SourceCandidateFinder($factory, new NameParser(), $detector);
    }
}
