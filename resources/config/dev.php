<?php

// include the prod configuration
require __DIR__ . '/prod.php';

// enable the debug mode
$app['debug'] = true;

// disable twig cache
$app['twig.options.cache'] = false;
