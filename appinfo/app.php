<?php
namespace OCA\ShareImporter\AppInfo;

$app = new Application();
$app->getContainer()->query('UserHooks')->register();
