<?php

// include the prod configuration
require __DIR__ . '/prod.php';

$app['db.options'] = array(
    'driver'   => 'pdo_mysql',
    'host'     => $_SERVER["DB1_HOST"],
    'port'     => $_SERVER["DB1_PORT"],
    'dbname'   => $_SERVER["DB1_NAME"],
    'user'     => $_SERVER["DB1_USER"],
    'password' => $_SERVER["DB1_PASS"],
);

$app['switmailer.transport'] = $app->share(function() use ($app) {
    return new \Swift_Transport_MailTransport(
        new \Swift_Transport_SimpleMailInvoker(),
        $app['swiftmailer.transport.eventdispatcher']
    );
});
