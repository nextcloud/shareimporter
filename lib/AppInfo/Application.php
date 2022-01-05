<?php

namespace OCA\ShareImporter\AppInfo;

use OCA\ShareImporter\Hooks\UserHooks;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APPID = 'shareimporter';

	public function __construct() {
		parent::__construct(self::APPID);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		/** @var UserHooks $handler */
		$handler = $context->getAppContainer()->get(UserHooks::class);
		$handler->register();
	}
}
