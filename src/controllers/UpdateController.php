<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\controllers;

use craft\app\Craft;
use craft\app\enums\PluginVersionUpdateStatus;
use craft\app\errors\EtException;
use craft\app\errors\Exception;
use craft\app\helpers\UpdateHelper;
use craft\app\helpers\UrlHelper;

/**
 * The UpdateController class is a controller that handles various update related tasks such as checking for available
 * updates and running manual and auto-updates.
 *
 * Note that all actions in the controller, except for [[actionPrepare]], [[actionBackupDatabase]],
 * [[actionUpdateDatabase]], [[actionCleanUp]] and [[actionRollback]] require an authenticated Craft session
 * via [[BaseController::allowAnonymous]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class UpdateController extends BaseController
{
	// Properties
	// =========================================================================

	/**
	 * If set to false, you are required to be logged in to execute any of the given controller's actions.
	 *
	 * If set to true, anonymous access is allowed for all of the given controller's actions.
	 *
	 * If the value is an array of action names, then you must be logged in for any action method except for the ones in
	 * the array list.
	 *
	 * If you have a controller that where the majority of action methods will be anonymous, but you only want require
	 * login on a few, it's best to call [[requireLogin()]] in the individual methods.
	 *
	 * @var bool
	 */
	protected $allowAnonymous = ['actionPrepare', 'actionBackupDatabase', 'actionUpdateDatabase', 'actionCleanUp', 'actionRollback'];

	// Public Methods
	// =========================================================================

	// Auto Updates
	// -------------------------------------------------------------------------

	/**
	 * Returns the available updates.
	 *
	 * @return null
	 */
	public function actionGetAvailableUpdates()
	{
		$this->requirePermission('performUpdates');

		try
		{
			$updates = Craft::$app->updates->getUpdates(true);
		}
		catch (EtException $e)
		{
			if ($e->getCode() == 10001)
			{
				$this->returnErrorJson($e->getMessage());
			}
		}

		if ($updates)
		{
			$this->returnJson($updates);
		}
		else
		{
			$this->returnErrorJson(Craft::t('Could not fetch available updates at this time.'));
		}
	}

	/**
	 * Returns the update info JSON.
	 *
	 * @return null
	 */
	public function actionGetUpdates()
	{
		$this->requirePermission('performUpdates');

		$this->requireAjaxRequest();

		$handle = Craft::$app->request->getRequiredPost('handle');

		$return = [];
		$updateInfo = Craft::$app->updates->getUpdates();

		if (!$updateInfo)
		{
			$this->returnErrorJson(Craft::t('There was a problem getting the latest update information.'));
		}

		try
		{
			switch ($handle)
			{
				case 'all':
				{
					// Craft first.
					$return[] = ['handle' => 'Craft', 'name' => 'Craft', 'version' => $updateInfo->app->latestVersion.'.'.$updateInfo->app->latestBuild, 'critical' => $updateInfo->app->criticalUpdateAvailable, 'releaseDate' => $updateInfo->app->latestDate->getTimestamp()];

					// Plugins
					if ($updateInfo->plugins !== null)
					{
						foreach ($updateInfo->plugins as $plugin)
						{
							if ($plugin->status == PluginVersionUpdateStatus::UpdateAvailable && count($plugin->releases) > 0)
							{
								$return[] = ['handle' => $plugin->class, 'name' => $plugin->displayName, 'version' => $plugin->latestVersion, 'critical' => $plugin->criticalUpdateAvailable, 'releaseDate' => $plugin->latestDate->getTimestamp()];
							}
						}
					}

					break;
				}

				case 'craft':
				{
					$return[] = ['handle' => 'Craft', 'name' => 'Craft', 'version' => $updateInfo->app->latestVersion.'.'.$updateInfo->app->latestBuild, 'critical' => $updateInfo->app->criticalUpdateAvailable, 'releaseDate' => $updateInfo->app->latestDate->getTimestamp()];
					break;
				}

				// We assume it's a plugin handle.
				default:
				{
					if (!empty($updateInfo->plugins))
					{
						if (isset($updateInfo->plugins[$handle]) && $updateInfo->plugins[$handle]->status == PluginVersionUpdateStatus::UpdateAvailable && count($updateInfo->plugins[$handle]->releases) > 0)
						{
							$return[] = ['handle' => $updateInfo->plugins[$handle]->handle, 'name' => $updateInfo->plugins[$handle]->displayName, 'version' => $updateInfo->plugins[$handle]->latestVersion, 'critical' => $updateInfo->plugins[$handle]->criticalUpdateAvailable, 'releaseDate' => $updateInfo->plugins[$handle]->latestDate->getTimestamp()];
						}
						else
						{
							$this->returnErrorJson(Craft::t("Could not find any update information for the plugin with handle “{handle}”.", ['handle' => $handle]));
						}
					}
					else
					{
						$this->returnErrorJson(Craft::t("Could not find any update information for the plugin with handle “{handle}”.", ['handle' => $handle]));
					}
				}
			}

			$this->returnJson(['success' => true, 'updateInfo' => $return]);
		}
		catch (\Exception $e)
		{
			$this->returnErrorJson($e->getMessage());
		}
	}

	/**
	 * Called during both a manual and auto-update.
	 *
	 * @return null
	 */
	public function actionPrepare()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = Craft::$app->request->getRequiredPost('data');

		$manual = false;
		if (!$this->_isManualUpdate($data))
		{
			// If it's not a manual update, make sure they have auto-update permissions.
			$this->requirePermission('performUpdates');

			if (!Craft::$app->config->get('allowAutoUpdates'))
			{
				$this->returnJson(['alive' => true, 'errorDetails' => Craft::t('Auto-updating is disabled on this system.'), 'finished' => true]);
			}
		}
		else
		{
			$manual = true;
		}

		$return = Craft::$app->updates->prepareUpdate($manual, $data['handle']);

		if (!$return['success'])
		{
			$this->returnJson(['alive' => true, 'errorDetails' => $return['message'], 'finished' => true]);
		}

		if ($manual)
		{
			$this->returnJson(['alive' => true, 'nextStatus' => Craft::t('Backing-up database…'), 'nextAction' => 'update/backupDatabase', 'data' => $data]);
		}
		else
		{
			$data['md5'] = $return['md5'];
			$this->returnJson(['alive' => true, 'nextStatus' => Craft::t('Downloading update…'), 'nextAction' => 'update/processDownload', 'data' => $data]);
		}

	}

	/**
	 * Called during an auto-update.
	 *
	 * @return null
	 */
	public function actionProcessDownload()
	{
		// This method should never be called in a manual update.
		$this->requirePermission('performUpdates');

		$this->requirePostRequest();
		$this->requireAjaxRequest();

		if (!Craft::$app->config->get('allowAutoUpdates'))
		{
			$this->returnJson(['alive' => true, 'errorDetails' => Craft::t('Auto-updating is disabled on this system.'), 'finished' => true]);
		}

		$data = Craft::$app->request->getRequiredPost('data');

		$return = Craft::$app->updates->processUpdateDownload($data['md5']);
		if (!$return['success'])
		{
			$this->returnJson(['alive' => true, 'errorDetails' => $return['message'], 'finished' => true]);
		}

		unset($return['success']);

		$this->returnJson(['alive' => true, 'nextStatus' => Craft::t('Backing-up files…'), 'nextAction' => 'update/backupFiles', 'data' => $return]);
	}

	/**
	 * Called during an auto-update.
	 *
	 * @return null
	 */
	public function actionBackupFiles()
	{
		// This method should never be called in a manual update.
		$this->requirePermission('performUpdates');

		$this->requirePostRequest();
		$this->requireAjaxRequest();

		if (!Craft::$app->config->get('allowAutoUpdates'))
		{
			$this->returnJson(['alive' => true, 'errorDetails' => Craft::t('Auto-updating is disabled on this system.'), 'finished' => true]);
		}

		$data = Craft::$app->request->getRequiredPost('data');

		$return = Craft::$app->updates->backupFiles($data['uid']);
		if (!$return['success'])
		{
			$this->returnJson(['alive' => true, 'errorDetails' => $return['message'], 'finished' => true]);
		}

		$this->returnJson(['alive' => true, 'nextStatus' => Craft::t('Updating files…'), 'nextAction' => 'update/updateFiles', 'data' => $data]);
	}

	/**
	 * Called during an auto-update.
	 *
	 * @return null
	 */
	public function actionUpdateFiles()
	{
		// This method should never be called in a manual update.
		$this->requirePermission('performUpdates');

		$this->requirePostRequest();
		$this->requireAjaxRequest();

		if (!Craft::$app->config->get('allowAutoUpdates'))
		{
			$this->returnJson(['alive' => true, 'errorDetails' => Craft::t('Auto-updating is disabled on this system.'), 'finished' => true]);
		}

		$data = Craft::$app->request->getRequiredPost('data');

		$return = Craft::$app->updates->updateFiles($data['uid']);
		if (!$return['success'])
		{
			$this->returnJson(['alive' => true, 'errorDetails' => $return['message'], 'nextStatus' => Craft::t('An error was encountered. Rolling back…'), 'nextAction' => 'update/rollback']);
		}

		$this->returnJson(['alive' => true, 'nextStatus' => Craft::t('Backing-up database…'), 'nextAction' => 'update/backupDatabase', 'data' => $data]);
	}

	/**
	 * Called during both a manual and auto-update.
	 *
	 * @return null
	 */
	public function actionBackupDatabase()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = Craft::$app->request->getRequiredPost('data');

		$handle = $this->_getFixedHandle($data);

		if (Craft::$app->config->get('backupDbOnUpdate'))
		{
			$plugin = Craft::$app->plugins->getPlugin($handle);

			// If this a plugin, make sure it actually has new migrations before backing up the database.
			if ($handle == 'craft' || ($plugin && Craft::$app->migrations->getNewMigrations($plugin)))
			{
				$return = Craft::$app->updates->backupDatabase();

				if (!$return['success'])
				{
					$this->returnJson(['alive' => true, 'errorDetails' => $return['message'], 'nextStatus' => Craft::t('An error was encountered. Rolling back…'), 'nextAction' => 'update/rollback']);
				}

				if (isset($return['dbBackupPath']))
				{
					$data['dbBackupPath'] = $return['dbBackupPath'];
				}
			}
		}

		$this->returnJson(['alive' => true, 'nextStatus' => Craft::t('Updating database…'), 'nextAction' => 'update/updateDatabase', 'data' => $data]);
	}

	/**
	 * Called during both a manual and auto-update.
	 *
	 * @return null
	 */
	public function actionUpdateDatabase()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = Craft::$app->request->getRequiredPost('data');

		$handle = $this->_getFixedHandle($data);

		if (isset($data['dbBackupPath']))
		{
			$return = Craft::$app->updates->updateDatabase($handle);
		}
		else
		{
			$return = Craft::$app->updates->updateDatabase($handle);
		}

		if (!$return['success'])
		{
			$this->returnJson(['alive' => true, 'errorDetails' => $return['message'], 'nextStatus' => Craft::t('An error was encountered. Rolling back…'), 'nextAction' => 'update/rollback']);
		}

		$this->returnJson(['alive' => true, 'nextStatus' => Craft::t('Cleaning up…'), 'nextAction' => 'update/cleanUp', 'data' => $data]);
	}

	/**
	 * Performs maintenance and clean up tasks after an update.
	 *
	 * Called during both a manual and auto-update.
	 *
	 * @return null
	 */
	public function actionCleanUp()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = Craft::$app->request->getRequiredPost('data');

		if ($this->_isManualUpdate($data))
		{
			$uid = false;
		}
		else
		{
			$uid = $data['uid'];
		}

		$handle = $this->_getFixedHandle($data);

		$oldVersion = false;

		// Grab the old version from the manifest data before we nuke it.
		$manifestData = UpdateHelper::getManifestData(UpdateHelper::getUnzipFolderFromUID($uid));

		if ($manifestData)
		{
			$oldVersion = UpdateHelper::getLocalVersionFromManifest($manifestData);
		}

		Craft::$app->updates->updateCleanUp($uid, $handle);

		if ($oldVersion && version_compare($oldVersion, Craft::$app->getVersion(), '<'))
		{
			$returnUrl = UrlHelper::getUrl('whats-new');
		}
		else
		{
			$returnUrl = Craft::$app->config->get('postCpLoginRedirect');
		}

		$this->returnJson(['alive' => true, 'finished' => true, 'returnUrl' => $returnUrl]);
	}

	/**
	 * Can be called during both a manual and auto-update.
	 *
	 * @throws Exception
	 * @return null
	 */
	public function actionRollback()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = Craft::$app->request->getRequiredPost('data');

		if ($this->_isManualUpdate($data))
		{
			$uid = false;
		}
		else
		{
			$uid = $data['uid'];
		}

		if (isset($data['dbBackupPath']))
		{
			$return = Craft::$app->updates->rollbackUpdate($uid, $data['dbBackupPath']);
		}
		else
		{
			$return = Craft::$app->updates->rollbackUpdate($uid);
		}

		if (!$return['success'])
		{
			// Let the JS handle the exception response.
			throw new Exception($return['message']);
		}

		$this->returnJson(['alive' => true, 'finished' => true, 'rollBack' => true]);
	}

	// Private Methods
	// =========================================================================

	/**
	 * @param $data
	 *
	 * @return bool
	 */
	private function _isManualUpdate($data)
	{
		if (isset($data['manualUpdate']) && $data['manualUpdate'] == 1)
		{
			return true;
		}

		return false;
	}

	/**
	 * @param $data
	 *
	 * @return string
	 */
	private function _getFixedHandle($data)
	{
		if (!isset($data['handle']))
		{
			return 'craft';
		}
		else
		{
			return $data['handle'];
		}
	}
}
