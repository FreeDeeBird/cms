<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\widgets;

use craft\app\Craft;

/**
 * Class Updates widget.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Updates extends BaseWidget
{
	// Properties
	// =========================================================================

	/**
	 * Whether users should be able to select more than one of this widget type.
	 *
	 * @var bool
	 */
	protected $multi = false;

	// Public Methods
	// =========================================================================

	/**
	 * @inheritDoc ComponentTypeInterface::getName()
	 *
	 * @return string
	 */
	public function getName()
	{
		return Craft::t('Updates');
	}

	/**
	 * @inheritDoc ComponentTypeInterface::isSelectable()
	 *
	 * @return bool
	 */
	public function isSelectable()
	{
		// Gotta have update permission to get this widget
		if (parent::isSelectable() && Craft::$app->getUser()->checkPermission('performUpdates'))
		{
			return true;
		}

		return false;
	}

	/**
	 * @inheritDoc WidgetInterface::getBodyHtml()
	 *
	 * @return string|false
	 */
	public function getBodyHtml()
	{
		// Make sure the user actually has permission to perform updates
		if (!Craft::$app->getUser()->checkPermission('performUpdates'))
		{
			return false;
		}

		$cached = Craft::$app->updates->isUpdateInfoCached();

		if (!$cached || !Craft::$app->updates->getTotalAvailableUpdates())
		{
			Craft::$app->templates->includeJsResource('js/UpdatesWidget.js');
			Craft::$app->templates->includeJs('new Craft.UpdatesWidget('.$this->model->id.', '.($cached ? 'true' : 'false').');');

			Craft::$app->templates->includeTranslations(
				'One update available!',
				'{total} updates available!',
				'Go to Updates',
				'Congrats! You’re up-to-date.',
				'Check again'
			);
		}

		if ($cached)
		{
			return Craft::$app->templates->render('_components/widgets/Updates/body', [
				'total' => Craft::$app->updates->getTotalAvailableUpdates()
			]);
		}
		else
		{
			return '<p class="centeralign">'.Craft::t('Checking for updates…').'</p>';
		}
	}
}
