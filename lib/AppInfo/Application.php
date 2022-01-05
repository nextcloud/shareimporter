<?php

namespace OCA\ShareImporter\AppInfo;

use OCA\ShareImporter\Hooks\UserHooks;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;

class Application extends App implements IBootstrap {
	public const APPID = 'shareimporter';

	public function __construct() {
		parent::__construct(self::APPID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerService(UserHooks::class, function (IAppContainer  $c) {
			return new UserHooks(
				$c->get('AppName'),
				$c->get('ServerContainer')->getUserManager(),
				$c->get('ServerContainer')->getLogger(),
				$c->get('ServerContainer')->getUserGlobalStoragesService(),
				$c->get('ServerContainer')->getGlobalStoragesService(),
				$c->get('ServerContainer')->getStoragesBackendService(),
				$c->get('ServerContainer')->getConfig(),
				$c->get('ServerContainer')->getHTTPClientService()
			);
		});
	}

	public function boot(IBootContext $context): void {
		/** @var UserHooks $listener */
		$listener = $context->getAppContainer()->get(UserHooks::class);
		$listener->register();
	}
}
