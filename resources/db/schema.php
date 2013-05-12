<?php

$schema = new \Doctrine\DBAL\Schema\Schema();

$apps = $schema->createTable('apps');
$apps->addColumn('id', 'integer', array('unsigned' => true, 'autoincrement' => true));
$apps->addColumn('name', 'string', array('length' => 255));
$apps->setPrimaryKey(array('id'));
$apps->addUniqueIndex(array('name'));

$stats = $schema->createTable('stats');
$stats->addColumn('id', 'integer', array('unsigned' => true, 'autoincrement' => true));
$stats->addColumn('app_id', 'integer', array('unsigned' => true));
$stats->addColumn('component_name', 'string', array('length' => 255));
$stats->addColumn('component_type', 'string', array('length' => 20));
$stats->addColumn('cpu_load', 'integer', array('unsigned' => true));
$stats->addColumn('memory_load', 'integer', array('unsigned' => true));
$stats->addColumn('poll_time', 'datetime');
$stats->setPrimaryKey(array('id'));
$stats->addForeignKeyConstraint($apps, array('app_id'), array('id'));

$thresholds = $schema->createTable('thresholds');
$thresholds->addColumn('id', 'integer', array('unsigned' => true, 'autoincrement' => true));
$thresholds->addColumn('app_id', 'integer', array('unsigned' => true));
$thresholds->addColumn('name', 'string', array('length' => 255));
$thresholds->addColumn('component_type', 'string', array('length' => 255));
$thresholds->addColumn('stat_type', 'integer', array('unsigned' => true));
$thresholds->addColumn('threshold', 'integer', array('unsigned' => true));
$thresholds->addColumn('recipients', 'text');
$thresholds->setPrimaryKey(array('id'));
$thresholds->addForeignKeyConstraint($apps, array('app_id'), array('id'));

$settings = $schema->createTable('settings');
$settings->addColumn('id', 'integer', array('unsigned' => true, 'autoincrement' => true));
$settings->addColumn('name', 'string', array('length' => 255));
$settings->addColumn('value', 'text');
$settings->setPrimaryKey(array('id'));
$settings->addUniqueIndex(array('name'));

$sessions = $schema->createTable('session');
$sessions->addColumn('session_id', 'string', array('length' => 255));
$sessions->addColumn('session_value', 'text');
$sessions->addColumn('session_time', 'integer', array('length' => 11));
$sessions->setPrimaryKey(array('session_id'));

return $schema;
