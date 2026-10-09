<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Console\Command;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Merlin\ProductFiller\Model\FillPlanBuilder;
use Merlin\ProductFiller\Model\PlanFingerprint;
use Merlin\ProductFiller\Model\ShellProductDetector;
use Merlin\ProductFiller\Model\SourceCandidateFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PreviewCommand extends Command
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private SourceCandidateFinder $finder,
        private ShellProductDetector $detector,
        private FillPlanBuilder $plans,
        private PlanFingerprint $fingerprints,
        string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('merlin:product-filler:preview')
            ->setDescription('Read-only fill or repair diff for a target and exact model source')
            ->addOption('target-id', null, InputOption::VALUE_REQUIRED, 'Target product ID')
            ->addOption('source-id', null, InputOption::VALUE_REQUIRED, 'Source product ID')
            ->addOption('promotions-only', null, InputOption::VALUE_NONE, 'Preview promotion fields on an already-filled target')
            ->addOption('oven-specs-only', null, InputOption::VALUE_NONE, 'Preview oven model specifications on an already-filled target')
            ->addOption('damage-condition-only', null, InputOption::VALUE_NONE, 'Preview damage_cond on an already-filled target')
            ->addOption('damage-fields-only', null, InputOption::VALUE_NONE, 'Preview the approved damage fields on an already-filled target');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $targetId = filter_var($input->getOption('target-id'), FILTER_VALIDATE_INT);
        $sourceId = filter_var($input->getOption('source-id'), FILTER_VALIDATE_INT);
        if (!$targetId || !$sourceId || $targetId < 1 || $sourceId < 1 || $targetId === $sourceId) {
            $output->writeln('<error>Provide distinct positive --target-id and --source-id values.</error>');
            return 1;
        }
        try {
            /** @var Product $target */
            $target = $this->products->getById($targetId, false, 0);
            /** @var Product $source */
            $source = $this->products->getById($sourceId, false, 0);
        } catch (NoSuchEntityException $exception) {
            $output->writeln('<error>Target or source product was not found.</error>');
            return 1;
        }
        $shell = $this->detector->detect($target);
        if (!$shell['eligible']) {
            $output->writeln('<error>Target is outside ProductFiller scope: ' . $shell['eligibility_reason'] . '. No preview generated.</error>');
            return 1;
        }
        $match = null;
        foreach ($this->finder->find($target) as $candidate) {
            if ((int)$candidate['product']->getId() === $sourceId) {
                $match = $candidate;
                break;
            }
        }
        $promotionsOnly = (bool)$input->getOption('promotions-only');
        $ovenSpecsOnly = (bool)$input->getOption('oven-specs-only');
        $damageConditionOnly = (bool)$input->getOption('damage-condition-only');
        $damageFieldsOnly = (bool)$input->getOption('damage-fields-only');
        if ((int)$promotionsOnly + (int)$ovenSpecsOnly + (int)$damageConditionOnly + (int)$damageFieldsOnly > 1) {
            $output->writeln('<error>Choose only one repair mode.</error>');
            return 1;
        }
        $plan = $promotionsOnly
            ? $this->plans->buildPromotionsOnly($target, $source)
            : ($ovenSpecsOnly
                ? $this->plans->buildOvenSpecsOnly($target, $source)
                : ($damageConditionOnly
                    ? $this->plans->buildDamageConditionOnly($target, $source)
                    : ($damageFieldsOnly
                        ? $this->plans->buildDamageFieldsOnly($target, $source)
                        : $this->plans->build($target, $source))));
        $output->writeln('Target: ' . $targetId . ' / ' . $target->getSku());
        $output->writeln('Source: ' . $sourceId . ' / ' . $source->getSku());
        if ($promotionsOnly) {
            $output->writeln('Mode: promotions only');
        } elseif ($ovenSpecsOnly) {
            $output->writeln('Mode: oven specifications only');
        } elseif ($damageConditionOnly) {
            $output->writeln('Mode: damage condition only');
        } elseif ($damageFieldsOnly) {
            $output->writeln('Mode: approved damage fields only');
        }
        $output->writeln('Shell: ' . ($shell['is_shell'] ? 'yes' : 'no') . ' â€” ' . $shell['reason']);
        $output->writeln('Match: ' . ($match ? $match['confidence'] . '/100 â€” ' . $match['match_reason'] : 'unverified; no exact brand/model match'));

        $this->section($output, 'FIELDS TO COPY');
        foreach ($plan['copy'] as $field => $value) {
            $before = $field === 'category_ids' ? $target->getCategoryIds() : $target->getData($field);
            $this->line($output, $field, $before, $value);
        }

        $this->section($output, 'FIELDS TO KEEP FROM TARGET');
        foreach (FillPlanBuilder::KEEP_FIELDS as $field) {
            if (in_array($field, ['stock quantity', 'stock status', 'website assignment'], true)) {
                $output->writeln('  ' . $field . ': preserved on target');
            } else {
                $output->writeln('  ' . $field . ': ' . $this->display($target->getData($field)));
            }
        }
        foreach ($plan['preserved'] as $field) {
            $output->writeln('  ' . $field . ': existing target value retained');
        }

        if ($plan['attribute_set_additions']) {
            $this->section($output, 'DESTINATION ATTRIBUTE SET UPDATE');
            $output->writeln('  Add Ncompass tracking attributes to set ' . $source->getAttributeSetId() . ': '
                . implode(', ', $plan['attribute_set_additions']));
        }

        $this->section($output, 'FIELDS TO GENERATE');
        foreach ($plan['generated'] as $field => $value) {
            $this->line($output, $field, $target->getData($field), $value);
        }

        $this->section($output, 'FIELDS EXPLICITLY SKIPPED');
        $output->writeln('  ' . implode(', ', FillPlanBuilder::SKIP_FIELDS));

        $this->section($output, 'WARNINGS');
        $warnings = $plan['warnings'];
        if (!$shell['is_shell'] && !$promotionsOnly && !$ovenSpecsOnly && !$damageConditionOnly && !$damageFieldsOnly) {
            $warnings[] = 'Target does not meet shell criteria.';
        }
        if (!$match || !$match['good'] || $match['confidence'] < 80) {
            $warnings[] = 'Source match is weak or unverified; manual review required.';
        }
        $warnings[] = 'Images and the explicitly skipped damage attributes are never copied.';
        foreach ($warnings as $warning) {
            $output->writeln('  - ' . $warning);
        }
        $output->writeln('Review fingerprint: ' . $this->fingerprints->create($target, $source, $plan));
        $output->writeln('Pass this value with --review-fingerprint when applying the reviewed plan.');
        $output->writeln('Read-only preview; no products were saved.');
        return 0;
    }

    private function section(OutputInterface $output, string $heading): void
    {
        $output->writeln('');
        $output->writeln($heading);
    }

    private function line(OutputInterface $output, string $field, $before, $after): void
    {
        $output->writeln('  ' . $field . ': ' . $this->display($before) . ' => ' . $this->display($after));
    }

    private function display($value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        $text = preg_replace('/\s+/', ' ', trim((string)$value)) ?? '';
        if (strlen($text) > 240) {
            return substr($text, 0, 240) . 'â€¦ (' . strlen($text) . ' bytes total)';
        }
        return $text === '' ? '(empty)' : $text;
    }
}
