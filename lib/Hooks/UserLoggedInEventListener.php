<?php

namespace OCA\ShareImporter\Hooks;

use Exception;
use OCA\Files_External\Lib\Backend\Backend;
use OCA\Files_External\Lib\StorageConfig;
use OCA\Files_External\Service\BackendService;
use OCA\Files_External\Service\GlobalStoragesService;
use OCA\Files_External\Service\UserGlobalStoragesService;
use OCA\ShareImporter\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\IWatcher;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUser;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<UserLoggedInEvent> */
class UserLoggedInEventListener implements IEventListener {

	public function __construct(
		private LoggerInterface $logger,
		private UserGlobalStoragesService $userGlobalStorageService,
		private GlobalStoragesService $globalStorageService,
		private BackendService $backendService,
		private IConfig $config,
		private IClientService $clientService,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserLoggedInEvent)) {
			return;
		}

		$this->mountShares($event->getUser());
	}

	/**
	 * This method is used to mount the shares of a user. For this purpose,
	 * a web service is queried and the information provided is
	 * used to determine the mounts to be mounted. The method automatically
	 * deletes unneeded shares if they no longer appear in the response
	 * of the web service.
	 */
	private function mountShares(?IUser $user): void {
		if (is_null($user) || is_null($user->getUID())) {
			return;
		}

		$exclude_user = $this->config->getSystemValue('share_importer_exclude_users', '');
		if (is_array($exclude_user) && in_array($user->getUID(), $exclude_user)) {
			return;
		}

		$userShares = $this->getUserShares($user);
		if (empty($userShares)) {
			return;
		}

		$existingUserMounts = $this->getExistingUserMounts($user);
		$existingUserMountsRemain = [];
		foreach ($userShares->shares as $userShare) {
			/** @var object $userShare */
			$foundExistingMount = false;
			foreach ($existingUserMounts as $existingUserMount) {
				if ($this->isDuplicate($userShare, $existingUserMount)) {
					$existingUserMountsRemain[] = $existingUserMount->getId();
					$foundExistingMount = true;
					break;
				}
			}
			if (!$foundExistingMount) {
				$configObj = $this->createMountConfig($user, (string)$userShare->mountpoint, (string)$userShare->host, (string)$userShare->share, (string)$userShare->domain);
				try {
					$newStorageConfig = $this->globalStorageService->addStorage($configObj);
					$this->logger->info('addStorage {config}',
						[
							'app' => Application::APPID,
							'config' => $newStorageConfig
						]
					);
				} catch (Exception $e) {
					$this->logger->error('addStorage failed: {message}',
						[
							'app' => Application::APPID,
							'message' => $e->getMessage()
						]
					);
				}
			}
		}

		$this->logger->info('remain {mounts}',
			[
				'app' => Application::APPID,
				'mounts' => $existingUserMountsRemain,
			]
		);

		foreach ($existingUserMounts as $existingUserMount) {
			if (!in_array($existingUserMount->getId(), $existingUserMountsRemain)) {
				$id = $existingUserMount->getId();
				try {
					$this->globalStorageService->removeStorage($id);
					$this->logger->info('removeStorage {id} {mount}',
						[
							'app' => Application::APPID,
							'id' => $id,
							'mount' => $existingUserMount
						]
					);
				} catch (Exception $e) {
					$this->logger->error('removeStorage failed: {message}',
						[
							'app' => Application::APPID,
							'message' => $e->getMessage(),
						]
					);
				}
			}
		}
	}

	/**
	 * This method returns all user shares as an object
	 */
	private function getUserShares(IUser $user): ?object {
		# $json = '{ "username": "testuser", "shares" : [ { "mountpoint": "T: test", "share": "test", "host": "localhost","domain":"WORKGROUP","type":"smb" } ]}';
		$json = $this->getUserSharesRaw($user);
		$obj = null;

		if (!empty($json)) {
			$obj = json_decode($json);
			if ($obj === null) {
				$error_msg = json_last_error_msg();
				$this->logger->error('can not read share importer webserver json: {message}',
					[
						'app' => Application::APPID,
						'message' => $error_msg,
					]
				);
			}
		}

		return $obj;
	}

	/**
	 * This method queries a web service and returns a json-formatted string.
	 */
	private function getUserSharesRaw(IUser $user): ?string {
		$url = $this->config->getSystemValueString('share_importer_webservice_url', '');
		$api_key = $this->config->getSystemValueString('share_importer_webservice_api_key', '');

		if (empty($url) || empty($api_key)) {
			$this->logger->error('can not connect to share importer webservice: url or api_key are not set', ['app' => Application::APPID]);
			return null;
		}

		//TODO: check config values
		$verify_cert = $this->config->getSystemValueBool('share_importer_webservice_verify_certificate', true);
		$timeout = $this->config->getSystemValueInt('share_importer_webservice_timeout', 5);
		$connect_timeout = $this->config->getSystemValueInt('share_importer_webservice_connect_timeout', 5);

		$connect_params = [
			'timeout' => $timeout,
			'connect_timeout' => $connect_timeout,
			'verify' => $verify_cert,
			'headers' => ['ApiKey' => $api_key],
		];

		$full_url = $url . '?username=' . $user->getUID();

		try {
			$client = $this->clientService->newClient();

			$rawResponse = $client->get(
				$full_url,
				$connect_params
			)->getBody();
			return (string)$rawResponse;
		} catch (Exception $e) {
			$this->logger->error('can not connect to share importer webservice: {message}',
				[
					'app' => Application::APPID,
					'message' => $e->getMessage()
				]
			);
			return null;
		}
	}

	/**
	 * This method searches which mounts already in the database for this user
	 *
	 * @param IUser $user
	 * @return StorageConfig[] all mounts of user.
	 */
	private function getExistingUserMounts(IUser $user): array {
		$existingMounts = $this->userGlobalStorageService->getAllStorages();
		$existingUserMounts = [];

		foreach ($existingMounts as $existingMount) {
			if ($existingMount->getApplicableUsers() == [$user->getUID()]) {
				$existingUserMounts[] = $existingMount;
			}
		}
		return $existingUserMounts;
	}

	/**
	 * This method compares two storage objects. If they are
	 * the same, true is returned, false otherwise.
	 */
	private function isDuplicate(object $userShare, StorageConfig $existingMount): bool {
		$backend_options = $existingMount->getBackendOptions();
		$tmp = '/' . $userShare->mountpoint;

		return $tmp === $existingMount->getMountPoint()
			&& $userShare->host === $backend_options['host']
			&& $userShare->share === $backend_options['share']
			&& $userShare->domain === $backend_options['domain'];
	}

	/**
	 * This method creates a Nextcloud MountConfig-Object
	 *
	 * @see: https://github.com/owncloud/core/blob/master/apps/files_external/lib/Command/Import.php
	 */
	private function createMountConfig(IUser $user, string $mountpoint, string $host, string $share, string $domain): StorageConfig {
		$authMech = $this->config->getSystemValue('share_importer_auth_mech', 'password::sessioncredentials');
		$mount = new StorageConfig();
		$mount->setMountPoint($mountpoint);
		// Use string instead of ::class notation otherwise backend is not found
		$mount->setBackend($this->getBackendByClass('\OCA\Files_External\Lib\Storage\SMB'));
		$authBackend = $this->backendService->getAuthMechanism($authMech);
		$mount->setAuthMechanism($authBackend);
		$backendOptions = [
			'host' => $host,
			'share' => $share,
			'root' => '',
			'domain' => $domain,
			'default_realm' => $domain,
		];
		$mount->setBackendOptions($backendOptions);
		$mount->setApplicableUsers([$user->getUID()]);
		$mount->setApplicableGroups([]);
		$mount->setMountOptions([ 'filesystem_check_changes' => IWatcher::CHECK_ONCE ]);
		return $mount;
	}

	/**
	 * This method returns a Nextcloud Backend Class
	 *
	 * @see: https://github.com/owncloud/core/blob/master/apps/files_external/lib/Command/Import.php
	 */
	private function getBackendByClass(string $className): ?Backend {
		$backends = $this->backendService->getBackends();
		foreach ($backends as $backend) {
			if ($backend->getStorageClass() === $className) {
				return $backend;
			}
		}
		return null;
	}
}
