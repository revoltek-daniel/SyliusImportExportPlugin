<?php

declare(strict_types=1);

namespace FriendsOfSylius\SyliusImportExportPlugin\Command;

use Enqueue\Redis\RedisConnectionFactory;
use FriendsOfSylius\SyliusImportExportPlugin\Exporter\MqItemReader;
use FriendsOfSylius\SyliusImportExportPlugin\Importer\ImporterInterface;
use FriendsOfSylius\SyliusImportExportPlugin\Importer\ImporterRegistry;
use FriendsOfSylius\SyliusImportExportPlugin\Importer\ImporterResultInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ImportDataCommand extends Command
{
    /**
     * @var ImporterRegistry
     */
    private $importerRegistry;

    /**
     * @param ImporterRegistry $importerRegistry
     */
    public function __construct(ImporterRegistry $importerRegistry)
    {
        $this->importerRegistry = $importerRegistry;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setName('sylius:import')
            ->setDescription('Import a file.')
            ->setDefinition([
                new InputArgument('importer', InputArgument::OPTIONAL, 'The importer to use.'),
                new InputArgument('file', InputArgument::OPTIONAL, 'The file to import.'),
                // @TODO try to guess the format from the file to make this optional
                new InputOption('format', null, InputOption::VALUE_OPTIONAL, 'The format of the file to import'),
                new InputOption('details', null, InputOption::VALUE_NONE,
                    'If to return details about skipped/failed rows'),
            ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $importer = $input->getArgument('importer');
        $format = $input->getOption('format');

        if (empty($importer) || empty($format)) {
            $message = 'choose an importer and format';
            $this->listImporters($input, $output, $message);
        }

        $name = ImporterRegistry::buildServiceName($importer, $format);
        $file = $input->getArgument('file');

        if (!$this->importerRegistry->has($name)) {
            $message = sprintf(
                "<error>There is no '%s' importer.</error>",
                $name
            );
            $output->writeln($message);

            $message = 'choose an importer and format';
            $this->listImporters($input, $output, $message);
        }

        /** @var ImporterInterface $service */
        $service = $this->importerRegistry->get($name);

        $result = $service->import($file);

        $this->finishImport($file, $name, $output);

        $this->showResultDetails($input, $output, $result);
    }

    /**
     * @param string $file
     * @param string $name
     * @param OutputInterface $output
     */
    private function finishImport(string $file, string $name, OutputInterface $output): void
    {
        $message = sprintf(
            "<info>Imported '%s' via the %s importer</info>",
            $file,
            $name
        );
        $output->writeln($message);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param ImporterResultInterface $result
     */
    private function showResultDetails(InputInterface $input, OutputInterface $output, ImporterResultInterface $result): void
    {
        $io = new SymfonyStyle($input, $output);

        $imported = count($result->getSuccessRows());
        $skipped = count($result->getSkippedRows());
        $failed = count($result->getFailedRows());
        $countOrRows = 'count';

        if ($input->getOption('details')) {
            $imported = implode(', ', $result->getSuccessRows());
            $skipped = implode(', ', $result->getSkippedRows());
            $failed = implode(', ', $result->getFailedRows());
            $countOrRows = 'rows';
        }

        $io->listing(
            [
                sprintf('Time taken: %s ms ', $result->getDuration()),
                sprintf('Imported %s: %s', $countOrRows, $imported),
                sprintf('Skipped %s: %s', $countOrRows, $skipped),
                sprintf('Failed %s: %s', $countOrRows, $failed),
            ]
        );
    }

    /**
     * @param string $importer
     */
    private function getImporterJsonDataFromMessageQueue(string $importer, ImporterInterface $service): void
    {
        $mqItemReader = new MqItemReader(new RedisConnectionFactory(), $service);
        $mqItemReader->initQueue('sylius.export.queue.' . $importer);
        $mqItemReader->readAndImport();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $message
     */
    private function listImporters(InputInterface $input, OutputInterface $output, string $message): void
    {
        $output->writeln($message);
        $all = array_keys($this->importerRegistry->all());
        $importers = [];
        foreach ($all as $importer) {
            $importer = explode('.', $importer);
            $importers[$importer[0]][] = $importer[1];
        }

        $list = [];
        $output->writeln('<info>Available importers and formats:</info>');
        foreach ($importers as $importer => $formats) {
            $list[] = sprintf(
                '%s (formats: %s)',
                $importer,
                implode(', ', $formats)
            );
        }

        $io = new SymfonyStyle($input, $output);
        $io->listing($list);
    }
}
