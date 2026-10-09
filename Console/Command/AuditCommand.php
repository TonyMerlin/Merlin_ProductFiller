<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AuditCommand extends Command
{
    public function __construct(private ResourceConnection $resource, string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('merlin:product-filler:audit')
            ->setDescription('Read-only history of successful ProductFiller applies')
            ->addOption('target-id', null, InputOption::VALUE_REQUIRED, 'Filter by target product ID')
            ->addOption('source-id', null, InputOption::VALUE_REQUIRED, 'Filter by source product ID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum records to show (1-100)', '20')
            ->addOption('show-changes', null, InputOption::VALUE_NONE, 'Show recorded before and after field values');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $output->writeln('<error>--limit must be 1-100.</error>');
            return 1;
        }
        $filters = [];
        foreach (['target-id' => 'target_id', 'source-id' => 'source_id'] as $option => $field) {
            $raw = $input->getOption($option);
            if ($raw === null) {
                continue;
            }
            $id = filter_var($raw, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                $output->writeln('<error>--' . $option . ' must be a positive product ID.</error>');
                return 1;
            }
            $filters[$field] = $id;
        }
        $connection = $this->resource->getConnection('catalog');
        $table = $this->resource->getTableName('merlin_product_filler_audit');
        $select = $connection->select()->from($table)->order('audit_id DESC')->limit($limit);
        foreach ($filters as $field => $id) {
            $select->where($field . ' = ?', $id);
        }
        $rows = $connection->fetchAll($select);
        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '#%d %s UTC | %s | target %d / %s | source %d / %s | %s%s | %d/100',
                $row['audit_id'], $row['created_at'], $row['mode'], $row['target_id'], $row['target_sku'],
                $row['source_id'], $row['source_sku'], $row['actor_type'],
                $row['actor_name'] ? ' ' . $row['actor_name'] : '', $row['confidence']
            ));
            $changes = json_decode((string)$row['changes_json'], true) ?: [];
            $output->writeln('  Match: ' . $row['match_reason']);
            $output->writeln('  Changed: ' . implode(', ', array_keys($changes)));
            if ($input->getOption('show-changes')) {
                foreach ($changes as $field => $values) {
                    $output->writeln('  ' . $field . ': '
                        . json_encode($values['before'] ?? null, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                        . ' => '
                        . json_encode($values['after'] ?? null, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                }
                $warnings = json_decode((string)$row['warnings_json'], true) ?: [];
                foreach ($warnings as $warning) {
                    $output->writeln('  Warning: ' . $warning);
                }
            }
        }
        $output->writeln('Found ' . count($rows) . ' audit record(s). Read-only.');
        return 0;
    }
}
