<?php
namespace OCA\ShareImporter\Hooks;

use OCP\ILogger;
use OCP\IUserManager;
use OCA\Files_External\Service\UserGlobalStoragesService;
use OCA\Files_External\Service\GlobalStoragesService;
use OCA\Files_External\Lib\StorageConfig;
use \OCA\Files_External\Service\BackendService;
use \OCP\IConfig;
use OCP\Http\Client\IClientService;

//based on the great work of C1-10P: https://github.com/C1-10P/shareimporter_owncloud

class UserHooks {

        private $appName;
        private $userManager;
        private $logger;
        private $userGlobalStorageService;
        private $globalStorageService;
        private $backendService;
        private $config;
        private $clientService;

        /**
        * This is the standard construct function.
        *
        * @param   object   $appName: ***
        * @param   object   $userManager: ***
        * @param   object   $logger: ***
        * @param   object   $userGlobalStorageService: ***
        * @param   object   $globalStorageService: ***
        * @param   object   $backendService: ***
        * @param   object   $config: ***
        * @param   object   $clientService: ***
        */

        public function __construct($appName,IUserManager $userManager, ILogger $logger,
                                    UserGlobalStoragesService $userGlobalStorageService,GlobalStoragesService $globalStorageService,
                                    BackendService $backendService,
                                    IConfig $config,IClientService  $clientService){
                $this->appName = $appName;
                $this->userManager = $userManager;
                $this->logger = $logger;
                $this->userGlobalStorageService =  $userGlobalStorageService;
                $this->globalStorageService =  $globalStorageService;
                $this->backendService = $backendService;
                $this->config = $config;
		$this->clientService = $clientService;

		$this->logger->debug("shareimporter UserHooks constructor");
        }

        /**
        * This method is used to bind the method mountShares() to the event postLogin.
        *
        */

        public function register() {
                $callback = function($user) {
                        $this->mountShares($user);
                };
		$this->logger->debug("shareimporter UserHooks register");
                $this->userManager->listen('\OC\User', 'postLogin', $callback);
        }

        /**
        * This method is used to mount the shares of a user. For this purpose,
        * a web service is queried and the information provided is
        * used to determine the mounts to be mounted. The method automatically
        * deletes unneeded shares if they no longer appear in the response
        * of the web service.
        *
        */

	private function mountShares($user) {
		$this->logger->debug("shareimporter mountShares");
                if(is_null($user) || is_null($user->getUID()))
                {
                    return;
                }

                $exclude_user = $this->config->getSystemValue("share_importer_exclude_users", "");
                if(is_array($exclude_user) && in_array($user->getUID(), $exclude_user))
                {
                    return;
                }

                $userShares = $this->getUserShares($user);
                if (empty($userShares))
		{
		    $this->logger->debug("no user shares for" . $user->getUID());
                    return;
                }

                $existingUserMounts = $this->getExistingUserMounts($user);
                $existingUserMountsRemain = array();
                foreach ($userShares->shares as $userShare) {
                        $foundExistingMount = false;
                        foreach($existingUserMounts as $existingUserMount) {
                                if($this->isDuplicate($userShare,$existingUserMount)){
                                        $existingUserMountsRemain[] = $existingUserMount->getId();
                                        $foundExistingMount = true;
                                        $this->logger->debug("found existing mount: " . json_encode($existingUserMount), array('app' => $this->appName));
                                        break;
                                }
                        }
                        if(!$foundExistingMount) {
                                $configObj = $this->createMountConfig($user, $userShare->mountpoint, $userShare->host, $userShare->share, $userShare->domain);
                                try {
                                    $newStorageConfig = $this->globalStorageService->addStorage($configObj);
                                    $this->logger->info("addStorage" . json_encode($newStorageConfig), array('app' => $this->appName));
                                }
                                catch (\Exception $e) {
                                    $this->logger->error("addStorage failed: " . $e->getMessage(), array('app' => $this->appName));
                                }
                        }
                }

                $this->logger->info("remain" . json_encode($existingUserMountsRemain), array('app' => $this->appName));

                foreach($existingUserMounts as $existingUserMount) {
                        if( !in_array($existingUserMount->getId(),$existingUserMountsRemain) ) {
                                $id = $existingUserMount->getId();
                                try {
                                    $this->globalStorageService->removeStorage($id);
                                    $this->logger->info("removeStorage" . $id . json_encode($existingUserMount), array('app' => $this->appName));
                                }
                                catch (\Exception $e) {
                                    $this->logger->error("removeStorage failed: " . $e->getMessage(), array('app' => $this->appName));
                                }
                        }
                }

        }

        /**
        * This method returns all user shares as an object
        *
        * @param   object   $user: Nextcloud user object
        * @return  object   all user shares as an anonymous object or null
        */

        private function getUserShares($user) {
		# $json = '{ "username": "testuser", "shares" : [ { "mountpoint": "T: test", "share": "test", "host": "localhost","domain":"WORKGROUP","type":"smb" } ]}';
		$json = $this->getUserSharesRaw($user);
                $obj = null;

                if (! empty($json)) {
                    $obj = json_decode($json);
                    if ($obj === null) {
                        $error_msg = json_last_error_msg();
                        $this->logger->error("can not read share importer webserver json: " . $error_msg, array('app' => $this->appName));
                    }
                }

                return $obj;
        }

        /**
        * This method queries a web service and returns a json-formatted string.
        *
        * @param   object   $user: Nextcloud user object
        * @return  string|false: shares of an user as json-formatted string, or false
        */

        private function getUserSharesRaw($user) {
                $url = $this->config->getSystemValue("share_importer_webservice_url", "");
                $api_key = $this->config->getSystemValue("share_importer_webservice_api_key", "");

                if (empty($url) || empty ($api_key)) {
                    $this->logger->error("can not connect to share importer webservice: url or api_key are not set", array('app' => $this->appName));
                    return false;
                }

                //TODO: check config values
                $verify_cert = $this->config->getSystemValue("share_importer_webservice_verify_certificate", true);
                $timeout = $this->config->getSystemValue("share_importer_webservice_timeout", 5);
                $connect_timeout = $this->config->getSystemValue("share_importer_webservice_connect_timeout", 5);

                $connect_params =   [
                                'timeout' => $timeout,
                                'connect_timeout' => $connect_timeout,
                                'verify' => $verify_cert
                        ];

                $full_url = $url . "?api_key=" . $api_key . "&user_name=" . $user->getUID();

                try {
                        $client = $this->clientService->newClient();

                        $raw_response = $client->get(
                        $full_url,
                        $connect_params
                        )->getBody();
                        $this->logger->debug("getUserSharesRaw - webservice response:" . $raw_response, array('app' => $this->appName));
                        return $raw_response;

                } catch (\Exception $e) {
                       $this->logger->error("can not connect to share importer webservice: " . $e->getMessage(), array('app' => $this->appName));
                       return false;
                }
        }

        /**
        * This method searches which mounts already in the database for this user
        *
        * @param   object   $user: Nextcloud user object
        * @return  object: all mounts of user.
        *
        */

        private function getExistingUserMounts($user){
                $existingMounts = $this->userGlobalStorageService->getAllStorages();
                $existingUserMounts = array();

                foreach ($existingMounts as $existingMount) {
                        if ( $existingMount->getApplicableUsers() == [$user->getUID()])      {
                                $existingUserMounts[] = $existingMount;
                        }
                }
                return $existingUserMounts;
        }

        /**
        * This method compares two storage objects. If they are
        * the same, true is returned, false otherwise.
        *
        * @param   object   $userShare: ***
        * @param   object   $existingMount: ***
        * @return  boolean
        */

        private function isDuplicate($userShare,$existingMount) {
                $backend_options = $existingMount->getBackendOptions();
                $this->logger->debug(json_encode($backend_options).json_encode($userShare).json_encode($existingMount), array('app' => $this->appName));
                $tmp = "/" . $userShare->mountpoint;

                if($tmp === $existingMount->getMountPoint() &&
                   $userShare->host === $backend_options["host"] &&
                   $userShare->share === $backend_options["share"] &&
                   $userShare->domain === $backend_options["domain"]) {
                                return true;
                }
                return false;
        }

        /**
        * This method creates a Nextcloud MountConfig-Object
        *
        * @see: https://github.com/owncloud/core/blob/master/apps/files_external/lib/Command/Import.php
        * @param   object   $user: ***
        * @param   object   $mountpoint: ***
        * @param   object   $host: ***
        * @param   object   $share: ***
        * @param   object   $domain: ***
        * @return  object
        */

        private function createMountConfig($user, $mountpoint, $host, $share, $domain) {
		$mount = new StorageConfig();
                $mount->setMountPoint($mountpoint);
                $mount->setBackend($this->getBackendByClass("\OCA\Files_External\Lib\Storage\SMB"));
                $authBackend = $this->backendService->getAuthMechanism("password::sessioncredentials");
                $mount->setAuthMechanism($authBackend);
                $backendOptions = array();
                $backendOptions["host"] = $host;
                $backendOptions["share"] = $share;
                $backendOptions["root"] = "";
                $backendOptions["domain"] = $domain;
                $mount->setBackendOptions($backendOptions);
                $mount->setApplicableUsers([$user->getUID()]);
                $mount->setApplicableGroups([]);
                return $mount;
        }

        /**
        * This method returns a Nextcloud Backend Class
        *
        * @see: https://github.com/owncloud/core/blob/master/apps/files_external/lib/Command/Import.php
        *
        * @param   string   $className: ***
        * @return  object
        */

        private function getBackendByClass($className) {
                $backends = $this->backendService->getBackends();
                foreach ($backends as $backend) {
                        if ($backend->getStorageClass() === $className) {
                                return $backend;
                        }
                }
        }
}
