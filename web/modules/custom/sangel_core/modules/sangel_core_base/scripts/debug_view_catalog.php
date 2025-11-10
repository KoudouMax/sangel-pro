<?php
use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require __DIR__ . '/../../../../../autoload.php';
$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals(), $autoloader, Settings::get('environment', 'prod'));
$kernel->boot();

$view = \Drupal\views\Views::getView('catalog');
$view->setDisplay('user_catalog');
$view->setArguments(['1']);
$view->execute();
print_r($view->args);

$kernel->shutdown();
