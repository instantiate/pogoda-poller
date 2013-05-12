<?php

namespace Instantiate\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Silex\Application;
use Symfony\Component\DomCrawler\Crawler;
use Doctrine\DBAL\Connection;
use PDO;

class ThresholdCheckCommand extends Command
{
    protected $app;
    protected $db;
    protected $settings;
    protected $triggered_thresholds = array();

    const STAT_TYPE_CPU = 1;
    const STAT_TYPE_MEMORY = 2;

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
            ->setDescription('Monitor configured thresholds and trigger actions')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        while (1) {
            $thresholds = $this->getThresholds();
            $stats = $this->getLatestStats();

            foreach ($stats as $stat) {
                // get thresholds for stat and check if any exceeded
                $stat_thresholds = $this->getStatThresholds($stat, $thresholds);
                foreach ($stat_thresholds as $stat_threshold) {
                    $this->checkThreshold($stat, $stat_threshold);
                }
            }

            // send mails
            $this->app['swiftmailer.spool']->flushQueue($this->app['swiftmailer.transport']);
        }
    }

    /**
     * Get thresholds and clean triggered thresholds array
     *
     * @return array
     */
    protected function getThresholds()
    {
        $thresholds = array();
        $threshold_ids = array();
        $raw_thresholds = $this->db->fetchAll('SELECT * FROM thresholds');
        foreach ($raw_thresholds as $raw_threshold) {
            $thresholds[$raw_threshold['app_id']][] = array(
                'id' => $raw_threshold['id'],
                'name' => $raw_threshold['name'],
                'component_type' => $raw_threshold['component_type'],
                'stat_type' => (int) $raw_threshold['stat_type'],
                'threshold' => (int) $raw_threshold['threshold'],
                'recipients' => $raw_threshold['recipients']
            );
            $threshold_ids[] = $raw_threshold['id'];
        }

        // remove any triggered thresholds that no longer exist
        $threshold_ids = array_flip($threshold_ids);
        array_intersect_key($this->triggered_thresholds, $threshold_ids);

        return $thresholds;
    }

    /**
     * Get latest stats for all apps
     *
     * @return array
     */
    protected function getLatestStats()
    {
        // sub-select in join to maintain performance
        return $this->db->fetchAll(
            'SELECT a.id, a.name, s.component_name, s.component_type, s.cpu_load, s.memory_load
            FROM stats s JOIN apps a ON s.app_id = a.id JOIN (
                SELECT max(id) as id FROM stats GROUP BY app_id, component_type, component_name
            ) sid ON s.id = sid.id'
        );
    }

    /**
     * Get any thresholds for current stat
     *
     * @param array $stat
     * @param array $thresholds
     * @return array
     */
    protected function getStatThresholds(array $stat, array $thresholds)
    {
        $stat_thresholds = array();
        if (isset($thresholds[$stat['id']])) {
            foreach ($thresholds[$stat['id']] as $threshold) {
                if ($threshold['component_type'] == $stat['component_type']) {
                    $stat_thresholds[] = $threshold;
                }
            }
        }

        return $stat_thresholds;
    }

    /**
     * Check threshold and send mail if exceeded
     *
     * @param array $stat
     * @param array $threshold
     */
    protected function checkThreshold(array $stat, array $threshold)
    {
        $type = array(
            self::STAT_TYPE_CPU => 'cpu_load',
            self::STAT_TYPE_MEMORY => 'memory_load',
        );

        // check if stat above threshold
        if ($stat[$type[$threshold['stat_type']]] >= $threshold['threshold']) {
            // check if threshold already triggered
            if (!isset($this->triggered_thresholds[$threshold['id']])) {
                $this->triggered_thresholds[$threshold['id']] = true;
                $this->sendEmail($stat, $threshold);
            }
        } else {
            // below threshold so clear previously triggered;
            if (isset($this->triggered_thresholds[$threshold['id']])) {
                unset($this->triggered_thresholds[$threshold['id']]);
            }
        }
    }

    protected function sendEmail(array $stat, array $threshold)
    {
        $this->updateSettings();

        $type = array(
            self::STAT_TYPE_CPU => 'CPU',
            self::STAT_TYPE_MEMORY => 'Memory',
        );

        $subject = sprintf(
            '%s load threshold exceeded: %s',
            $stat['name'],
            $threshold['name']
        );

        $message = \Swift_Message::newInstance()
            ->setSubject($subject)
            ->setFrom($this->settings['email_sender'])
            ->setTo(explode(',', $threshold['recipients']))
            ->setBody(
                $this->app['twig']->render('email.html.twig', array(
                    'subject' => $subject,
                    'url' => 'https://dashboard.pagodabox.com/apps/' . $stat['name'],
                    'stat' => $stat,
                    'threshold' => $threshold,
                    'threshold_type' => $type[$threshold['stat_type']]
                )), 'text/html'
            );

        // spool mail
        $this->app['mailer']->send($message);
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
