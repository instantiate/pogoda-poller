<?php

namespace Instantiate\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Silex\Application;
use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;
use Doctrine\DBAL\Connection;
use PDO;

class PagodaPollCommand extends Command
{
    protected $app;
    protected $db;
    protected $settings;
    protected $apps;

    public function __construct($name = null, Application $app = null)
    {
        $this->app = $app;

        /** @var Connection $db */
        $db = $app['db'];
        $this->db = $db;

        parent::__construct($name);
    }

    protected function configure()
    {
        $this
            ->addOption('env', 'e', InputOption::VALUE_OPTIONAL)
            ->setDescription('Poll pagoda')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->updateSettings();
        if (empty($this->settings['pagoda_user']) || empty($this->settings['pagoda_password'])) {
            throw new \RuntimeException('Pagoda login data has not been set. Configure before running the poller.');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = new Client();

        while (1) {
            $start_time = microtime(true);

            // need to update once a loop as settings change externally
            $this->updateSettings();

            if ((bool) $this->settings['poller_enabled']) {

                // attempt to get app list
                $apps = array();
                try {
                    $apps = $this->getApps($client);
                } catch (\RuntimeException $e) {
                    // TODO: log failure?
                    $output->writeln('<error>Error while getting app list: ' . $e->getMessage() . '</error>');
                }

                // attempt to get app stats
                $stats = array();
                foreach ($apps as $app_id => $app) {
                    try {
                        $stats[$app_id] = $this->getAppStats($client, $app['url']);
                    } catch (\RuntimeException $e) {
                        // TODO: log failure?
                        $output->writeln(
                            '<error>Error while scraping for ' . $app['name'] . ' stats: ' . $e->getMessage() . '</error>'
                        );
                    }
                }

                // write stats to db
                $this->writeStats($stats);
            }

            // wait for any remaining time to make up poll frequency
            $poll_frequency = $this->settings['poll_frequency'] * 60;
            if (0 < $remaining_time = ($start_time + $poll_frequency) - microtime(true)) {
                if ((bool) $this->settings['poller_enabled']) {
                    $output->writeln('Poll completed in ' . round(microtime(true) - $start_time, 2) . 's');
                }
                usleep((int) ($remaining_time * 1000000));
            }
        }
    }

    /**
     * Write array of stats to the db
     *
     * @param array $stats
     */
    protected function writeStats(array $stats)
    {
        if (count($stats)) {
            $data = array();
            $types = array();
            $placeholders = array();
            foreach ($stats as $app_id => $components) {
                foreach ($components as $component_name => $component) {
                    $placeholders[] = '(?, ?, ?, ?, ?, ?)';
                    $data = array_merge($data, array(
                        $app_id,
                        $component_name,
                        $component['type'],
                        $component['cpu_load'],
                        $component['memory_load'],
                        $component['poll_time']
                    ));
                    $types = array_merge($types, array(
                        PDO::PARAM_INT,
                        PDO::PARAM_STR,
                        PDO::PARAM_STR,
                        PDO::PARAM_INT,
                        PDO::PARAM_INT,
                        'datetime'
                    ));
                }
            }

            $query = 'INSERT INTO stats'
                . ' (app_id, component_name, component_type, cpu_load, memory_load, poll_time)'
                . ' VALUES ' . implode(',', $placeholders);

            // write all in one go
            $this->db->executeUpdate($query, $data, $types);
        }
    }

    /**
     * Get app id from name
     *
     * @param string $app_name
     * @return int
     */
    protected function getAppId($app_name)
    {
        // check if app list loaded
        if (!is_array($this->apps)) {
            // load apps from db
            $this->apps = array();
            $apps = $this->db->fetchAll('SELECT * FROM apps');
            foreach ($apps as $app) {
                $this->apps[$app['name']] = (int) $app['id'];
            }
        }

        // if app doesn't exist save to db
        if (!isset($this->apps[$app_name])) {
            $this->db->insert('apps', array('name' => $app_name));
            $this->apps[$app_name] = $this->db->lastInsertId();
        }

        return $this->apps[$app_name];
    }

    /**
     * Get array of application components and stats
     *
     * @param Client $client
     * @param string $url
     * @return array
     * @throws \RuntimeException
     */
    protected function getAppStats(Client $client, $url)
    {
        $crawler = $client->request('GET', $url);
        $time = new \DateTime();
        if (!$this->isAuthenticated($client)) {
            // TODO: log authentication failure?
            // one retry in case session timed out - throws exception on failure
            $crawler = $this->doLogin($client, $url);
        }

        // scrape page for component data
        $raw_app_components = $crawler->filterXPath('//div[@data-cuid]')->extract(array(
            'data-cuid',
            'data-group',
            'data-cpu',
            'data-memory'
        ));

        if (!count($raw_app_components)) {
            throw new \RuntimeException('No application components found');
        }

        // make into nice array
        $app_components = array();
        foreach ($raw_app_components as $raw_app_component) {
            $app_components[$raw_app_component[0]] = array(
                'type' => $raw_app_component[1],
                'cpu_load' => $raw_app_component[2],
                'memory_load' => $raw_app_component[3],
                'poll_time' => $time
            );
        }

        return $app_components;
    }

    /**
     * Get array of application name and dashboard url
     *
     * @param Client $client
     * @return array
     */
    protected function getApps(Client $client)
    {
        if (!$this->isAuthenticated($client)) {
            $crawler = $this->doLogin($client);
        } else {
            $crawler = $client->request('GET', 'https://dashboard.pagodabox.com');
        }

        $raw_apps = $crawler->filter('ul.component.app li:first-child a')->extract(array('title', 'href'));

        $apps = array();
        foreach ($raw_apps as $raw_app) {
            $apps[$this->getAppId($raw_app[0])] = array(
                'name' => $raw_app[0],
                'url' => $raw_app[1]
            );
        }

        return $apps;
    }

    /**
     * Do login and return a crawler on target uri if successful
     *
     * @param Client $client
     * @param string $target_uri
     * @return Crawler
     * @throws \RuntimeException
     */
    protected function doLogin(Client $client, $target_uri = 'https://dashboard.pagodabox.com')
    {
        // get login details

        // do login
        $crawler = $client->request('GET', $target_uri);
        $form = $crawler->filter('#new_user')->form();
        $crawler = $client->submit($form, array(
            'user' => array(
                'login' => $this->settings['pagoda_user'],
                'password' => $this->settings['pagoda_password'],
                'remember_me' => 1
            )
        ));

        // check if authenticated
        if (!$this->isAuthenticated($client)) {
            $flash = $crawler->filter('#flash .alert p');
            $message = 'Pagoda login failed:' . (count($flash) ? trim(strip_tags($flash->text())) : 'Unknown reason');
            throw new \RuntimeException($message);
        }

        return $crawler;
    }

    /**
     * Check for authentication - assumes redirected to login page on authentication failure
     * TODO: Add authoritative flag to toggle GET https://dashboard.pagodabox.com and check arrived?
     *
     * @param Client $client
     * @return bool
     */
    protected function isAuthenticated(Client $client)
    {
        $request = $client->getRequest();
        return isset($request) && $request->getUri() != 'https://dashboard.pagodabox.com/account/login';
    }

    /**
     * Update settings from DB.
     */
    protected function updateSettings()
    {
        $this->settings = array();
        $raw_settings = $this->db->fetchAll('SELECT name, value FROM settings');

        foreach ($raw_settings as $raw_setting) {
            $this->settings[$raw_setting['name']] = $raw_setting['value'];
        }
    }
}
