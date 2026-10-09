<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Console\Command;

use Merlin\ProductFiller\Model\DeploymentHealth;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCheckCommand extends Command
{
    public function __construct(private DeploymentHealth $health, string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('merlin:product-filler:install-check')
            ->setDescription('Read-only ProductFiller deployment and background-worker readiness checks')
            ->addOption('allow-existing-install', null, InputOption::VALUE_NONE,
                'Skip the 24-hour activation cutoff check for an established installation')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print machine-readable check results');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $results = $this->health->check((bool)$input->getOption('allow-existing-install'));
        $failed = count(array_filter($results, static fn (array $row): bool => $row['status'] === 'FAIL'));
        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'ready' => $failed === 0,
                'failed' => $failed,
                'checks' => $results,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($results as $row) {
                $tag = $row['status'] === 'PASS' ? 'info' : 'error';
                $output->writeln('<' . $tag . '>[' . $row['status'] . ']</' . $tag . '> '
                    . $row['check'] . ': ' . $row['detail']);
            }
            $output->writeln($failed
                ? '<error>ProductFiller is not ready: ' . $failed . ' check(s) failed. Resolve these before filling products.</error>'
                : '<info>ProductFiller installation checks passed. No products or configuration were changed.</info>');
        }
        return $failed ? 1 : 0;
    }
}
