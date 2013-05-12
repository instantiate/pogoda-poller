<?php

namespace Instantiate\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\DBALException;
use Silex\Application;

class LoadFixturesCommand extends Command
{
    protected $app;

    public function __construct($name = null, Application $app = null)
    {
        $this->app = $app;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this
            ->setDescription('Load fixtures')
            ->addOption('env', 'e', InputOption::VALUE_OPTIONAL)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Loading database fixtures...');

        $fixtures_file = __DIR__ . '/../../../resources/db/fixtures.sql';

        if (!file_exists($fixtures_file)) {
            $output->writeln('<error>Fixtures file not found: ' . $fixtures_file . '</error>');
            exit();
        }

        try {
            $this->app['db']->executeQuery(file_get_contents($fixtures_file));
            $output->writeln('<info>Fixtures loaded.</info>');
        } catch (DBALException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
        }
    }
}
