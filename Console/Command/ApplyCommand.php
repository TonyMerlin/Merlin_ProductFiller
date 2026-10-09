<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Console\Command;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Merlin\ProductFiller\Model\FillPlanBuilder;
use Merlin\ProductFiller\Model\ApplyAudit;
use Merlin\ProductFiller\Model\PlanFingerprint;
use Merlin\ProductFiller\Model\ShellProductDetector;
use Merlin\ProductFiller\Model\SourceCandidateFinder;
use Merlin\ProductFiller\Model\TrackingAttributeSetSupport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ApplyCommand extends Command
{
    private ?int $adminActorId = null;
    private ?string $adminActorName = null;
    private const PROTECTED_FIELDS = [
        'sku', 'ebay_quote_ref', 'price', 'special_price', 'special_from_date',
        'special_to_date', 'status', 'dataupdated', 'priceupdated', 'stockupdated',
        'image', 'small_image', 'thumbnail', 'amazon_image', 'ebay_image',
        'merlin_master_sku', 'merlin_last_master_id', 'damage_front', 'damage_back',
        'damage_left', 'damage_right', 'damage_top', 'damage_handles',
    ];

    public function __construct(
        private ProductRepositoryInterface $products,
        private CollectionFactory $collections,
        private SourceCandidateFinder $finder,
        private ShellProductDetector $detector,
        private FillPlanBuilder $plans,
        private ResourceConnection $resource,
        private State $appState,
        private ProductAction $productAction,
        private CategoryLinkManagementInterface $categoryLinks,
        private TrackingAttributeSetSupport $trackingAttributes,
        private ApplyAudit $audit,
        private PlanFingerprint $fingerprints,
        string $name = null
    ) {
        parent::__construct($name);
    }

    public function setAdminActor(int $id, string $name): void
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('An admin actor must have a positive user ID.');
        }
        $this->adminActorId = $id;
        $this->adminActorName = $name;
    }

    protected function configure(): void
    {
        $this->setName('merlin:product-filler:apply')
            ->setDescription('Fill a verified shell or repair whitelisted fields on an already-filled product')
            ->addOption('target-id', null, InputOption::VALUE_REQUIRED, 'Target product ID')
            ->addOption('source-id', null, InputOption::VALUE_REQUIRED, 'Matched source product ID')
            ->addOption('confirm-sku', null, InputOption::VALUE_REQUIRED, 'Type the exact target SKU to confirm this write')
            ->addOption('review-fingerprint', null, InputOption::VALUE_REQUIRED, 'Fingerprint printed by the reviewed preview')
            ->addOption('promotions-only', null, InputOption::VALUE_NONE, 'Update only promotion attributes on an already-filled target')
            ->addOption('oven-specs-only', null, InputOption::VALUE_NONE, 'Update only oven model specifications on an already-filled target')
            ->addOption('damage-condition-only', null, InputOption::VALUE_NONE, 'Update only damage_cond on an already-filled target')
            ->addOption('damage-fields-only', null, InputOption::VALUE_NONE, 'Update the approved damage fields on an already-filled target');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (LocalizedException $exception) {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        }
        $targetId = filter_var($input->getOption('target-id'), FILTER_VALIDATE_INT);
        $sourceId = filter_var($input->getOption('source-id'), FILTER_VALIDATE_INT);
        if (!$targetId || !$sourceId || $targetId < 1 || $sourceId < 1 || $targetId === $sourceId) {
            $output->writeln('<error>Provide distinct positive --target-id and --source-id values.</error>');
            return 1;
        }
        $expectedFingerprint = (string)$input->getOption('review-fingerprint');
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint)) {
            $output->writeln('<error>Provide the --review-fingerprint from the preview. No changes made.</error>');
            return 1;
        }
        try {
            /** @var Product $target */
            $target = $this->products->getById($targetId, false, 0, true);
            /** @var Product $source */
            $source = $this->products->getById($sourceId, false, 0, true);
        } catch (NoSuchEntityException $exception) {
            $output->writeln('<error>Target or source product was not found.</error>');
            return 1;
        }
        if ((string)$input->getOption('confirm-sku') !== (string)$target->getSku()) {
            $output->writeln('<error>--confirm-sku must exactly match the target SKU. No changes made.</error>');
            return 1;
        }
        $promotionsOnly = (bool)$input->getOption('promotions-only');
        $ovenSpecsOnly = (bool)$input->getOption('oven-specs-only');
        $damageConditionOnly = (bool)$input->getOption('damage-condition-only');
        $damageFieldsOnly = (bool)$input->getOption('damage-fields-only');
        if ((int)$promotionsOnly + (int)$ovenSpecsOnly + (int)$damageConditionOnly + (int)$damageFieldsOnly > 1) {
            $output->writeln('<error>Choose only one repair mode. No changes made.</error>');
            return 1;
        }
        $repairOnly = $promotionsOnly || $ovenSpecsOnly || $damageConditionOnly || $damageFieldsOnly;
        $shell = $this->detector->detect($target);
        if (!$shell['eligible']) {
            $output->writeln('<error>Target is outside ProductFiller scope: ' . $shell['eligibility_reason'] . '. No changes made.</error>');
            return 1;
        }
        if (!$repairOnly && !$shell['is_shell']) {
            $output->writeln('<error>Target is not a shell: ' . $shell['reason'] . '. No changes made.</error>');
            return 1;
        }
        $reference = trim((string)$target->getData('ebay_quote_ref'));
        if ($reference === '') {
            $output->writeln('<error>Target ebay_quote_ref is missing. No changes made.</error>');
            return 1;
        }
        if ($repairOnly && ($shell['is_shell']
            || !preg_match('/\(' . preg_quote($reference, '/') . '\)\s*$/i', (string)$target->getName())
            || (trim((string)$target->getData('modelno')) === '' && trim((string)$target->getData('mpn')) === ''))) {
            $output->writeln('<error>Repair mode requires an already-filled target with its own reference and model fields. No changes made.</error>');
            return 1;
        }
        $candidates = $this->finder->find($target);
        $match = null;
        foreach ($candidates as $candidate) {
            if ((int)$candidate['product']->getId() === $sourceId) {
                $match = $candidate;
                break;
            }
        }
        if (!$match || !$match['good'] || $match['confidence'] < 80) {
            $output->writeln('<error>Source is not a strong exact brand/model match. No changes made.</error>');
            return 1;
        }
        if (!$match['enabled']) {
            foreach ($candidates as $candidate) {
                if ($candidate['good'] && $candidate['enabled']) {
                    $output->writeln('<error>A good enabled source exists; select it instead. No changes made.</error>');
                    return 1;
                }
            }
        }

        if ($promotionsOnly) {
            foreach (FillPlanBuilder::PROMO_FIELDS as $field) {
                if (trim((string)$source->getData($field)) === '' || (string)$source->getData($field) === '0') {
                    $output->writeln('<error>Source ' . $field . ' is empty. No changes made.</error>');
                    return 1;
                }
            }
        }
        $plan = $this->buildPlan($target, $source, $promotionsOnly, $ovenSpecsOnly,
            $damageConditionOnly, $damageFieldsOnly);
        if ($repairOnly && !$plan['copy']) {
            $output->writeln('Selected attributes already match the source. No changes made.');
            return 0;
        }
        if (!$repairOnly && (!$plan['generated'] || trim((string)($plan['generated']['google_sku'] ?? '')) === '')) {
            $output->writeln('<error>Generated values are incomplete. No changes made.</error>');
            return 1;
        }
        if (!$repairOnly && strlen((string)$plan['generated']['ebay_desc']) > 80) {
            $output->writeln('<error>Generated eBay title exceeds 80 characters. No changes made.</error>');
            return 1;
        }
        if (!$repairOnly) {
            foreach (['url_key', 'google_sku'] as $field) {
                if ($this->valueUsedByAnotherProduct($field, (string)$plan['generated'][$field], $targetId)) {
                    $output->writeln('<error>Generated ' . $field . ' is already used by another product. No changes made.</error>');
                    return 1;
                }
            }
        }

        $mode = $promotionsOnly ? 'promotions' : ($ovenSpecsOnly ? 'oven_specs'
            : ($damageConditionOnly ? 'damage_condition' : ($damageFieldsOnly ? 'damage_fields' : 'fill')));
        $connection = $this->resource->getConnection('catalog');
        $addedTracking = [];
        $connection->beginTransaction();
        try {
            // Lock both product rows, then reload and rebuild from current data.
            // A stale review must fail before attribute-set, product, or audit writes.
            $this->lockProducts($connection, $targetId, $sourceId);
            $target = $this->products->getById($targetId, false, 0, true);
            $source = $this->products->getById($sourceId, false, 0, true);
            $plan = $this->buildPlan($target, $source, $promotionsOnly, $ovenSpecsOnly,
                $damageConditionOnly, $damageFieldsOnly);
            $before = $this->protectedSnapshot($target);
            $offerBefore = [];
            foreach ($plan['offer_store_ids'] as $storeId) {
                $storeProduct = $this->products->getById($targetId, false, $storeId, true);
                $offerBefore[$storeId] = $storeProduct->getData('allow_make_an_offer_product');
            }
            $auditBefore = $this->audit->beforeValues($target, $plan, $offerBefore);
            if (!hash_equals($expectedFingerprint, $this->fingerprints->create($target, $source, $plan))) {
                throw new \RuntimeException('Product or source changed since preview. Review the current plan again; no changes made.');
            }

            $addedTracking = $repairOnly ? [] : $this->trackingAttributes->ensure((int)$source->getAttributeSetId());
            if (isset($plan['copy']['attribute_set_id'])) {
                $connection->update(
                    $this->resource->getTableName('catalog_product_entity'),
                    ['attribute_set_id' => (int)$plan['copy']['attribute_set_id']],
                    ['entity_id = ?' => $targetId]
                );
            }
            $attributes = array_diff_key($plan['copy'], ['attribute_set_id' => true, 'category_ids' => true]);
            $attributes = array_merge($attributes, $plan['generated']);
            $this->productAction->updateAttributes([$targetId], $attributes, 0);
            if (isset($plan['copy']['category_ids'])) {
                $this->categoryLinks->assignProductToCategories($target->getSku(), $plan['copy']['category_ids']);
            }
            foreach ($plan['offer_store_ids'] as $storeId) {
                $this->productAction->updateAttributes(
                    [$targetId],
                    ['allow_make_an_offer_product' => 0],
                    $storeId
                );
            }
            /** @var Product $saved */
            $saved = $this->products->getById($targetId, false, 0, true);
            $this->assertPlannedValues($saved, $plan);
            foreach ($plan['offer_store_ids'] as $storeId) {
                $storeProduct = $this->products->getById($targetId, false, $storeId, true);
                if ((string)$storeProduct->getData('allow_make_an_offer_product') !== '0') {
                    throw new \RuntimeException('Allow Make an Offer was not set to No in store view ' . $storeId . '.');
                }
            }
            $after = $this->protectedSnapshot($saved);
            if ($after !== $before) {
                $changed = [];
                foreach ($before['fields'] as $field => $value) {
                    if ($after['fields'][$field] !== $value) {
                        $changed[] = $field;
                    }
                }
                foreach (['websites', 'stock', 'source_items', 'image_count'] as $group) {
                    if ($after[$group] !== $before[$group]) {
                        $changed[] = $group;
                    }
                }
                throw new \RuntimeException('Protected target data changed during save (' . implode(', ', $changed) . '); transaction rolled back.');
            }
            $auditId = $this->audit->record(
                $target, $source, $saved, $plan, $match, $mode,
                $auditBefore, $this->adminActorId, $this->adminActorName
            );
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            if ($addedTracking) {
                $this->trackingAttributes->refresh();
            }
            $output->writeln('<error>Apply failed: ' . $exception->getMessage() . '</error>');
            return 1;
        }

        $output->writeln(($promotionsOnly ? 'Updated promotions on target '
            : ($ovenSpecsOnly ? 'Updated oven specifications on target '
                : ($damageConditionOnly ? 'Updated damage condition on target '
                    : ($damageFieldsOnly ? 'Updated damage fields on target ' : 'Filled target '))))
            . $targetId . ' / ' . $target->getSku() . ' from source ' . $sourceId . '.');
        if ($addedTracking) {
            $output->writeln('Added Ncompass tracking attributes to destination set ' . $source->getAttributeSetId()
                . ': ' . implode(', ', $addedTracking));
        }
        $output->writeln('Copied fields: ' . implode(', ', array_keys($plan['copy'])));
        if (!$repairOnly) {
            $output->writeln('Generated fields: ' . implode(', ', array_keys($plan['generated'])));
        }
        $output->writeln('Protected target fields, stock, websites, and images were verified unchanged.');
        $output->writeln('Audit ID: ' . $auditId);
        foreach ($plan['warnings'] as $warning) {
            $output->writeln('Warning: ' . $warning);
        }
        return 0;
    }

    private function valueUsedByAnotherProduct(string $field, string $value, int $targetId): bool
    {
        $collection = $this->collections->create();
        $collection->setStoreId(0)->addAttributeToFilter($field, ['eq' => $value]);
        $collection->addFieldToFilter('entity_id', ['neq' => $targetId]);
        return (int)$collection->getSize() > 0;
    }

    private function buildPlan(
        Product $target,
        Product $source,
        bool $promotionsOnly,
        bool $ovenSpecsOnly,
        bool $damageConditionOnly,
        bool $damageFieldsOnly
    ): array {
        return $promotionsOnly
            ? $this->plans->buildPromotionsOnly($target, $source)
            : ($ovenSpecsOnly
                ? $this->plans->buildOvenSpecsOnly($target, $source)
                : ($damageConditionOnly
                    ? $this->plans->buildDamageConditionOnly($target, $source)
                    : ($damageFieldsOnly
                        ? $this->plans->buildDamageFieldsOnly($target, $source)
                        : $this->plans->build($target, $source))));
    }

    private function lockProducts(AdapterInterface $connection, int $targetId, int $sourceId): void
    {
        $ids = [$targetId, $sourceId];
        sort($ids);
        $locked = array_map('intval', $connection->fetchCol($connection->select()
            ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
            ->where('entity_id IN (?)', $ids)
            ->order('entity_id ASC')
            ->forUpdate(true)));
        if ($locked !== $ids) {
            throw new \RuntimeException('Target or source product no longer exists. No changes made.');
        }
    }

    private function protectedSnapshot(Product $product): array
    {
        $fields = [];
        foreach (self::PROTECTED_FIELDS as $field) {
            $fields[$field] = $product->getData($field);
        }
        $websites = array_map('intval', $product->getWebsiteIds());
        sort($websites);
        $connection = $this->resource->getConnection();
        $stockTable = $this->resource->getTableName('cataloginventory_stock_item');
        $stock = $connection->fetchAll(
            $connection->select()->from($stockTable)->where('product_id = ?', (int)$product->getId())->order('stock_id')
        );
        $sourceItems = [];
        $sourceTable = $this->resource->getTableName('inventory_source_item');
        if ($connection->isTableExists($sourceTable)) {
            $sourceItems = $connection->fetchAll(
                $connection->select()->from($sourceTable)->where('sku = ?', (string)$product->getSku())->order('source_code')
            );
        }
        return [
            'fields' => $fields,
            'websites' => $websites,
            'stock' => $stock,
            'source_items' => $sourceItems,
            'image_count' => $this->detector->imageCount($product),
        ];
    }

    private function assertPlannedValues(Product $saved, array $plan): void
    {
        foreach (array_merge($plan['copy'], $plan['generated']) as $field => $value) {
            if ($field === 'category_ids') {
                $expected = array_map('intval', $value);
                $actual = array_map('intval', $saved->getCategoryIds());
                sort($expected);
                sort($actual);
                if ($actual !== $expected) {
                    throw new \RuntimeException('Category assignment did not match the preview.');
                }
            } elseif ((string)$saved->getData($field) !== (string)$value) {
                throw new \RuntimeException('Saved ' . $field . ' did not match the preview.');
            }
        }
    }
}
