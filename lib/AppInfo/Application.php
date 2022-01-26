<?php

namespace OCA\ShareImporter\AppInfo;

use OCA\ShareImporter\Hooks\UserLoggedInEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserLoggedInEvent;

class Application extends App implements IBootstrap {
	public const APPID = 'shareimporter';

	public function __construct() {
		parent::__construct(self::APPID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(UserLoggedInEvent::class, UserLoggedInEventListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
