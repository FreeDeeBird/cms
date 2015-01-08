<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\models;

use craft\app\Craft;
use craft\app\enums\AttributeType;
use craft\app\models\AssetFolder as AssetFolderModel;
use craft\app\models\AssetSource as AssetSourceModel;

/**
 * The AssetFolder model class.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class AssetFolder extends BaseModel
{
	// Properties
	// =========================================================================

	/**
	 * @var array
	 */
	private $_children = null;

	// Public Methods
	// =========================================================================

	/**
	 * Use the folder name as the string representation.
	 *
	 * @return string
	 */
	public function __toString()
	{
		return $this->name;
	}

	/**
	 * @return AssetSourceModel|null
	 */
	public function getSource()
	{
		return Craft::$app->assetSources->getSourceById($this->sourceId);
	}

	/**
	 * Get this folder's children.
	 *
	 * @return array|null
	 */
	public function getChildren()
	{
		if (is_null($this->_children))
		{
			$this->_children = Craft::$app->assets->findFolders(['parentId' => $this->id]);
		}

		return $this->_children;
	}

	/**
	 * @return AssetFolderModel|null
	 */
	public function getParent()
	{
		if (!$this->parentId)
		{
			return null;
		}

		return Craft::$app->assets->getFolderById($this->parentId);
	}

	/**
	 * Add a child folder manually.
	 *
	 * @param AssetFolderModel $folder
	 *
	 * @return null
	 */
	public function addChild(AssetFolderModel $folder)
	{
		if (is_null($this->_children))
		{
			$this->_children = [];
		}

		$this->_children[] = $folder;
	}

	/**
	 * @inheritDoc BaseModel::setAttribute()
	 *
	 * @param string $name
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public function setAttribute($name, $value)
	{
		if ($name == 'path' && !empty($value))
		{
			$value = rtrim($value, '/').'/';
		}
		return parent::setAttribute($name, $value);
	}

	/**
	 * @inheritDoc BaseModel::getAttribute()
	 *
	 * @param string $name
	 * @param bool   $flattenValue
	 *
	 * @return mixed
	 */
	public function getAttribute($name, $flattenValue = false)
	{
		$value = parent::getAttribute($name, $flattenValue);

		if ($name == 'path' && !empty($value))
		{
			$value = rtrim($value, '/').'/';
		}

		return $value;
	}

	// Protected Methods
	// =========================================================================

	/**
	 * @inheritDoc BaseModel::defineAttributes()
	 *
	 * @return array
	 */
	protected function defineAttributes()
	{
		return [
			'id'       => AttributeType::Number,
			'parentId' => AttributeType::Number,
			'sourceId' => AttributeType::Number,
			'name'     => AttributeType::String,
			'path'     => AttributeType::String,
		];
	}
}
