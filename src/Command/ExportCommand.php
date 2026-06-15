<?php declare(strict_types=1);

namespace Waiter24\Export\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Waiter24\Export\Service\MenuExporter;

/**
 * Manual catalog push: `bin/console waiter24:export`.
 */
class ExportCommand extends Command
{
    protected static $defaultName = 'waiter24:export';

    public function __construct(private readonly MenuExporter $exporter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('waiter24:export')
            ->setDescription('Export the catalog to Waiter24 now.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->exporter->run();
            $stats  = $result['stats'] ?? [];
            $io->success(sprintf(
                'Export complete: %d created, %d updated.',
                $stats['created'] ?? 0,
                $stats['updated'] ?? 0,
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
