<?php

namespace App\Command;

use App\Service\AppDatabase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stock:check',
    description: 'List stockable products whose stored stock differs from stock movements.'
)]
final class StockCheckCommand extends Command
{
    public function __construct(private readonly AppDatabase $database)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $issues = $this->database->stockConsistencyIssues();

        if ($issues === []) {
            $io->success('Stock is consistent.');
            return Command::SUCCESS;
        }

        $io->warning(count($issues) . ' stock divergence(s) found.');
        $io->table(
            ['ID', 'SKU', 'Reference', 'Product', 'stock_qty', 'movements', 'difference'],
            array_map(
                static fn (array $row): array => [
                    $row['id'],
                    $row['sku'],
                    $row['ref_company'] ?? '',
                    $row['name'],
                    $row['stock_qty'],
                    $row['movement_qty'],
                    (int) $row['stock_qty'] - (int) $row['movement_qty'],
                ],
                $issues
            )
        );

        return Command::FAILURE;
    }
}
