<?php
/**
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_Trashbin\BackgroundJob;

use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCA\Files_Trashbin\AppInfo\Application;
use OCA\Files_Trashbin\Expiration;
use OCA\Files_Trashbin\Helper;
use OCA\Files_Trashbin\Trashbin;

class ExpireTrash extends \OC\BackgroundJob\TimedJob {

	/**
	 * @var Expiration
	 */
	private $expiration;

	/**
	 * @var IConfig
	 */
	private $config;

	/**
	 * @var IUserManager
	 */
	private $userManager;

	const USERS_PER_SESSION = 1000;

	/**
	 * @param IConfig|null $config
	 * @param IUserManager|null $userManager
	 * @param Expiration|null $expiration
	 */
	public function __construct(IConfig $config = null,
								IUserManager $userManager = null,
								Expiration $expiration = null) {
		// Run once per 30 minutes
		$this->setInterval(60 * 30);

		if (is_null($expiration) || is_null($userManager) || is_null($config)) {
			$this->fixDIForJobs();
		} else {
			$this->config = $config;
			$this->userManager = $userManager;
			$this->expiration = $expiration;
		}
	}

	protected function fixDIForJobs() {
		$application = new Application();
		$this->config = \OC::$server->getConfig();
		$this->userManager = \OC::$server->getUserManager();
		$this->expiration = $application->getContainer()->query('Expiration');
	}

	/**
	 * @param $argument
	 * @throws \Exception
	 */
	protected function run($argument) {
		$maxAge = $this->expiration->getMaxAgeAsTimestamp();
		if (!$maxAge) {
			return;
		}

		$connection = \OC::$server->getDatabaseConnection();
		$connection->beginTransaction();
		$result = $this->config->increaseAppValue('files_trashbin', 'cronjob_user_offset', self::USERS_PER_SESSION);
		if ($result !== null) {
			// use previous chunk
			$offset = $result - self::USERS_PER_SESSION;

			// check if there is at least one user at this offset
			$users = $this->userManager->search('', 1, $offset);
			if (count($users)) {
				$connection->commit();

				// fetch the whole chunk
				$users = $this->userManager->search('', self::USERS_PER_SESSION, $offset);
				foreach ($users as $user) {
					$uid = $user->getUID();
					if ($user->getLastLogin() === 0 || !$this->setupFS($uid)) {
						continue;
					}
					$dirContent = Helper::getTrashFiles('/', $uid, 'mtime');
					Trashbin::deleteExpiredFiles($dirContent, $uid);
				}

				\OC_Util::tearDownFS();

				return;
			}
		}
		// reset offset to make the next run start at the beginning
		$this->config->setAppValue('files_trashbin', 'cronjob_user_offset', 0);
		$connection->commit();

	}

	/**
	 * Act on behalf on trash item owner
	 * @param string $user
	 * @return boolean
	 */
	protected function setupFS($user) {
		\OC_Util::tearDownFS();
		if (\OC_Util::setupFS($user)) {
			// Check if this user has a trashbin directory
			$view = new \OC\Files\View('/' . $user);
			if (!$view->is_dir('/files_trashbin/files')) {
				return false;
			}
		}

		return true;
	}
}
