<?php

// Locale
$app['locale'] = 'en_GB';
$app['session.default_locale'] = $app['locale'];

// Cache
$app['cache.path'] = __DIR__ . '/../cache';

// Http cache
$app['http_cache.cache_dir'] = $app['cache.path'] . '/http';

// Twig cache
$app['twig.options.cache'] = $app['cache.path'] . '/twig';

// Doctrine (db)
$app['db.options'] = array(
    'driver'   => 'pdo_mysql',
    'host'     => '127.0.0.1',
    'dbname'   => 'pagoda-poller',
    'user'     => 'pagoda-poller',
    'password' => '',
);

// Swiftmailer
$app['swiftmailer.options'] = array();
