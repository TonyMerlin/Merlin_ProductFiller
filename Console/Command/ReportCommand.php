<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Console\Command;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Merlin\ProductFiller\Model\ShellAssessment;
use Merlin\ProductFiller\Model\TargetEligibility;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReportCommand extends Command
{
    public function __construct(
        private CollectionFactory $collectionFactory,
        private ShellAssessment $assessment,
        private AttributeSetRepositoryInterface $attributeSets,
        private TargetEligibility $eligibility,
        string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('merlin:product-filler:report')
            ->setDescription('Read-only report of Ncompass product shells and exact model source candidates')
            ->addOption('sku', null, InputOption::VALUE_REQUIRED, 'Report one target SKU')
            ->addOption('from-id', null, InputOption::VALUE_REQUIRED, 'Start at this product ID', '0')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of targets to show', '20')
            ->addOption('include-non-shells', null, InputOption::VALUE_NONE, 'Include products that fail shell detection');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        $fromId = filter_var($input->getOption('from-id'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500 || $fromId === false || $fromId < 0) {
            $output->writeln('<error>--limit must be 1â€“500 and --from-id must be a non-negative integer.</error>');
            return 1;
        }
        $sku = trim((string)($input->getOption('sku') ?? ''));
        $includeNonShells = (bool)$input->getOption('include-non-shells');
        $cutoff = $this->eligibility->cutoff();
        if ($cutoff === null) {
            $output->writeln('<error>ProductFiller activation date is missing or invalid. Run bin/magento setup:upgrade.</error>');
            return 1;
        }
        $collection = $this->collectionFactory->create();
        $collection->setStoreId(0)->addAttributeToSelect('*');
        $collection->addFieldToFilter('created_at', ['gteq' => $cutoff]);
        if ($sku !== '') {
            $collection->addAttributeToFilter('sku', ['eq' => $sku]);
        } else {
            if (!$includeNonShells) {
                $collection->addAttributeToFilter([
                    ['attribute' => 'sku', 'like' => 'NCSI%'],
                    ['attribute' => 'sku', 'regexp' => '^[0-9]{5}$'],
                ]);
                $collection->addAttributeToFilter('attribute_set_id', ['eq' => 4]);
                $collection->addAttributeToFilter('status', ['eq' => 2]);
            }
            $collection->addFieldToFilter('entity_id', ['gteq' => $fromId]);
        }
        $collection->setOrder('entity_id', 'ASC');
        $output->writeln('Target creation cutoff (UTC): ' . $cutoff);
        $shown = 0;
        foreach ($collection as $target) {
            $review = $this->assessment->assess($target);
            $shell = $review['shell'];
            if (!$shell['is_shell'] && !$includeNonShells && $sku === '') {
                continue;
            }
            $identity = $review['identity'];
            $best = $review['best'];
            $source = $best['product'] ?? null;
            $reference = $review['reference'];
            $values = [
                'Target product ID' => (int)$target->getId(),
                'Target SKU' => $target->getSku(),
                'Target name' => $target->getName(),
                'Target ebay_quote_ref' => $reference ?: '(missing)',
                'Detected brand' => $identity['brand'] ?: '(missing)',
                'Detected model' => $identity['model'] ?: '(missing)',
                'Detected model key' => $identity['model_key'] ?: '(missing)',
                'Detected product type hint' => $identity['product_type_hint'] ?: '(none)',
                'Shell detection' => $shell['is_shell'] ? 'yes' : 'no',
                'Shell reason' => $shell['reason'],
                'Source candidate product ID' => $source ? (int)$source->getId() : '(none)',
                'Source candidate SKU' => $source ? $source->getSku() : '(none)',
                'Source candidate name' => $source ? $source->getName() : '(none)',
                'Source status' => $source ? ($best['enabled'] ? 'enabled' : 'disabled') : '(none)',
                'Source attribute set' => $source ? $this->attributeSetName((int)$source->getAttributeSetId()) : '(none)',
                'Source category count' => $best['category_count'] ?? 0,
                'Source image count' => $best['image_count'] ?? 0,
                'Confidence score' => $best['confidence'] ?? 0,
                'Match reason' => $best['match_reason'] ?? '(none)',
                'Recommended action' => $review['action'],
            ];
            $output->writeln('');
            foreach ($values as $label => $value) {
                $output->writeln($label . ': ' . (string)$value);
            }
            ++$shown;
            if ($shown >= $limit) {
                break;
            }
        }
        $output->writeln('');
        if ($sku !== '' && $shown === 0) {
            $output->writeln('No eligible target found for SKU ' . $sku . '.');
        }
        $output->writeln('Reported ' . $shown . ' product(s). Read-only; no products were saved.');
        return 0;
    }

    private function attributeSetName(int $id): string
    {
        try {
            return $id . ' / ' . $this->attributeSets->get($id)->getAttributeSetName();
        } catch (\Throwable $exception) {
            return (string)$id;
        }
    }
}
