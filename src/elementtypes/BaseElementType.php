<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\elementtypes;

use craft\app\components\BaseComponentType;
use craft\app\Craft;
use craft\app\dates\DateTime;
use craft\app\db\DbCommand;
use craft\app\helpers\HtmlHelper;
use craft\app\models\BaseElementModel;
use craft\app\models\ElementCriteria        as ElementCriteriaModel;
use craft\app\models\Field                  as FieldModel;
use craft\app\variables\ElementType;

/**
 * The base class for all Craft element types. Any element type must extend this class.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
abstract class BaseElementType extends BaseComponentType implements ElementTypeInterface
{
	// Properties
	// =========================================================================

	/**
	 * The type of component, e.g. "Plugin", "Widget", "FieldType", etc. Defined by the component type's base class.
	 *
	 * @var string
	 */
	protected $componentType = 'ElementType';

	/**
	 * @var
	 */
	private $_sourcesByContext;

	// Public Methods
	// =========================================================================

	// Basic info methods
	// -------------------------------------------------------------------------

	/**
	 * @inheritDoc ElementTypeInterface::hasContent()
	 *
	 * @return bool
	 */
	public function hasContent()
	{
		return false;
	}

	/**
	 * @inheritDoc ElementTypeInterface::hasTitles()
	 *
	 * @return bool
	 */
	public function hasTitles()
	{
		return false;
	}

	/**
	 * @inheritDoc ElementTypeInterface::isLocalized()
	 *
	 * @return bool
	 */
	public function isLocalized()
	{
		return false;
	}

	/**
	 * @inheritDoc ElementTypeInterface::hasStatuses()
	 *
	 * @return bool
	 */
	public function hasStatuses()
	{
		return false;
	}

	/**
	 * @inheritDoc ElementTypeInterface::getStatuses()
	 *
	 * @return array|null
	 */
	public function getStatuses()
	{
		return [
			BaseElementModel::ENABLED => Craft::t('Enabled'),
			BaseElementModel::DISABLED => Craft::t('Disabled')
		];
	}

	/**
	 * @inheritDoc ElementTypeInterface::getSources()
	 *
	 * @param null $context
	 *
	 * @return array|bool|false
	 */
	public function getSources($context = null)
	{
		return false;
	}

	/**
	 * @inheritDoc ElementTypeInterface::getSource()
	 *
	 * @param string $key
	 * @param null   $context
	 *
	 * @return array|null
	 */
	public function getSource($key, $context = null)
	{
		$contextKey = ($context ? $context : '*');

		if (!isset($this->_sourcesByContext[$contextKey]))
		{
			$this->_sourcesByContext[$contextKey] = $this->getSources($context);
		}

		return $this->_findSource($key, $this->_sourcesByContext[$contextKey]);
	}

	/**
	 * @inheritDoc ElementTypeInterface::getAvailableActions()
	 *
	 * @param string|null $source
	 *
	 * @return array|null
	 */
	public function getAvailableActions($source = null)
	{
		return [];
	}

	/**
	 * @inheritDoc ElementTypeInterface::defineSearchableAttributes()
	 *
	 * @return array
	 */
	public function defineSearchableAttributes()
	{
		return [];
	}

	// Element index methods
	// -------------------------------------------------------------------------

	/**
	 * @inheritDoc ElementTypeInterface::getIndexHtml()
	 *
	 * @param ElementCriteriaModel $criteria
	 * @param array                $disabledElementIds
	 * @param array                $viewState
	 * @param null|string          $sourceKey
	 * @param null|string          $context
	 * @param bool                 $includeContainer
	 * @param bool                 $showCheckboxes
	 *
	 * @return string
	 */
	public function getIndexHtml($criteria, $disabledElementIds, $viewState, $sourceKey, $context, $includeContainer, $showCheckboxes)
	{
		$variables = [
			'viewMode'            => $viewState['mode'],
			'context'             => $context,
			'elementType'         => new ElementType($this),
			'disabledElementIds'  => $disabledElementIds,
			'collapsedElementIds' => Craft::$app->request->getParam('collapsedElementIds'),
			'showCheckboxes'      => $showCheckboxes,
		];

		// Special case for sorting by structure
		if (isset($viewState['order']) && $viewState['order'] == 'structure')
		{
			$source = $this->getSource($sourceKey, $context);

			if (isset($source['structureId']))
			{
				$criteria->order = 'lft asc';
				$variables['structure'] = Craft::$app->structures->getStructureById($source['structureId']);

				// Are they allowed to make changes to this structure?
				if ($context == 'index' && $variables['structure'] && !empty($source['structureEditable']))
				{
					$variables['structureEditable'] = true;

					// Let StructuresController know that this user can make changes to the structure
					Craft::$app->getSession()->authorize('editStructure:'.$variables['structure']->id);
				}
			}
			else
			{
				unset($viewState['order']);
			}
		}
		else if (!empty($viewState['order']) && $viewState['order'] == 'score')
		{
			$criteria->order = 'score';
		}
		else
		{
			$sortableAttributes = $this->defineSortableAttributes();

			if ($sortableAttributes)
			{
				$order = (!empty($viewState['order']) && isset($sortableAttributes[$viewState['order']])) ? $viewState['order'] : array_shift(array_keys($sortableAttributes));
				$sort  = (!empty($viewState['sort']) && in_array($viewState['sort'], ['asc', 'desc'])) ? $viewState['sort'] : 'asc';

				// Combine them, accounting for the possibility that $order could contain multiple values,
				// and be defensive about the possibility that the first value actually has "asc" or "desc"

				// typeId             => typeId [sort]
				// typeId, title      => typeId [sort], title
				// typeId, title desc => typeId [sort], title desc
				// typeId desc        => typeId [sort]

				$criteria->order = preg_replace('/^(.*?)(?:\s+(?:asc|desc))?(,.*)?$/i', "$1 {$sort}$2", $order);
			}
		}

		switch ($viewState['mode'])
		{
			case 'table':
			{
				// Get the table columns
				$variables['attributes'] = $this->defineTableAttributes($sourceKey);

				break;
			}
		}

		$variables['elements'] = $criteria->find();

		$template = '_elements/'.$viewState['mode'].'view/'.($includeContainer ? 'container' : 'elements');
		return Craft::$app->templates->render($template, $variables);
	}

	/**
	 * @inheritDoc ElementTypeInterface::defineSortableAttributes()
	 *
	 * @retrun array
	 */
	public function defineSortableAttributes()
	{
		return $this->defineTableAttributes();
	}

	/**
	 * @inheritDoc ElementTypeInterface::defineTableAttributes()
	 *
	 * @param string|null $source
	 *
	 * @return array
	 */
	public function defineTableAttributes($source = null)
	{
		return [];
	}

	/**
	 * @inheritDoc ElementTypeInterface::getTableAttributeHtml()
	 *
	 * @param BaseElementModel $element
	 * @param string           $attribute
	 *
	 * @return mixed|string
	 */
	public function getTableAttributeHtml(BaseElementModel $element, $attribute)
	{
		switch ($attribute)
		{
			case 'uri':
			{
				$url = $element->getUrl();

				if ($url)
				{
					$value = $element->uri;

					if ($value == '__home__')
					{
						$value = '<span data-icon="home" title="'.Craft::t('Homepage').'"></span>';
					}
					else
					{
						// Add some <wbr> tags in there so it doesn't all have to be on one line
						$find = ['/'];
						$replace = ['/<wbr>'];

						$wordSeparator = Craft::$app->config->get('slugWordSeparator');

						if ($wordSeparator)
						{
							$find[] = $wordSeparator;
							$replace[] = $wordSeparator.'<wbr>';
						}

						$value = str_replace($find, $replace, $value);
					}

					return '<a href="'.$url.'" target="_blank" class="go"><span dir="ltr">'.$value.'</span></a>';
				}
				else
				{
					return '';
				}
			}

			default:
			{
				$value = $element->$attribute;

				if ($value instanceof DateTime)
				{
					return '<span title="'.$value->localeDate().' '.$value->localeTime().'">'.$value->uiTimestamp().'</span>';
				}

				return HtmlHelper::encode($value);
			}
		}
	}

	/**
	 * @inheritDoc ElementTypeInterface::defineCriteriaAttributes()
	 *
	 * @return array
	 */
	public function defineCriteriaAttributes()
	{
		return [];
	}

	// Methods for customizing the content table
	// -----------------------------------------------------------------------------

	/**
	 * @inheritDoc ElementTypeInterface::getContentTableForElementsQuery()
	 *
	 * @param ElementCriteriaModel $criteria
	 *
	 * @return false|string
	 */
	public function getContentTableForElementsQuery(ElementCriteriaModel $criteria)
	{
		return 'content';
	}

	/**
	 * @inheritDoc ElementTypeInterface::getFieldsForElementsQuery()
	 *
	 * @param ElementCriteriaModel $criteria
	 *
	 * @return FieldModel[]
	 */
	public function getFieldsForElementsQuery(ElementCriteriaModel $criteria)
	{
		$contentService = Craft::$app->content;
		$originalFieldContext = $contentService->fieldContext;
		$contentService->fieldContext = 'global';

		$fields = Craft::$app->fields->getAllFields();

		$contentService->fieldContext = $originalFieldContext;

		return $fields;
	}

	// Methods for customizing ElementCriteriaModel's for this element type...
	// -------------------------------------------------------------------------

	/**
	 * @inheritDoc ElementTypeInterface::getElementQueryStatusCondition()
	 *
	 * @param DbCommand $query
	 * @param string    $status
	 *
	 * @return false|string|void
	 */
	public function getElementQueryStatusCondition(DbCommand $query, $status)
	{
	}

	/**
	 * @inheritDoc ElementTypeInterface::modifyElementsQuery()
	 *
	 * @param DbCommand            $query
	 * @param ElementCriteriaModel $criteria
	 *
	 * @return false|null|void
	 */
	public function modifyElementsQuery(DbCommand $query, ElementCriteriaModel $criteria)
	{
	}

	// Element methods

	/**
	 * @inheritDoc ElementTypeInterface::populateElementModel()
	 *
	 * @param array $row
	 *
	 * @return BaseElementModel|void
	 */
	public function populateElementModel($row)
	{
	}

	/**
	 * @inheritDoc ElementTypeInterface::getEditorHtml()
	 *
	 * @param BaseElementModel $element
	 *
	 * @return string
	 */
	public function getEditorHtml(BaseElementModel $element)
	{
		$html = '';

		$fieldLayout = $element->getFieldLayout();

		if ($fieldLayout)
		{
			$originalNamespace = Craft::$app->templates->getNamespace();
			$namespace = Craft::$app->templates->namespaceInputName('fields', $originalNamespace);
			Craft::$app->templates->setNamespace($namespace);

			foreach ($fieldLayout->getFields() as $fieldLayoutField)
			{
				$fieldHtml = Craft::$app->templates->render('_includes/field', [
					'element'  => $element,
					'field'    => $fieldLayoutField->getField(),
					'required' => $fieldLayoutField->required
				]);

				$html .= Craft::$app->templates->namespaceInputs($fieldHtml, 'fields');
			}

			Craft::$app->templates->setNamespace($originalNamespace);
		}

		return $html;
	}

	/**
	 * @inheritDoc ElementTypeInterface::saveElement()
	 *
	 * @param BaseElementModel $element
	 * @param array            $params
	 *
	 * @return bool
	 */
	public function saveElement(BaseElementModel $element, $params)
	{
		return Craft::$app->elements->saveElement($element);
	}

	/**
	 * @inheritDoc ElementTypeInterface::routeRequestForMatchedElement()
	 *
	 * @param BaseElementModel $element
	 *
	 * @return bool|mixed
	 */
	public function routeRequestForMatchedElement(BaseElementModel $element)
	{
		return false;
	}

	/**
	 * @inheritDoc ElementTypeInterface::onAfterMoveElementInStructure()
	 *
	 * @param BaseElementModel $element
	 * @param int              $structureId
	 *
	 * @return null|void
	 */
	public function onAfterMoveElementInStructure(BaseElementModel $element, $structureId)
	{
	}

	// Private Methods
	// =========================================================================

	/**
	 * Finds a source by its key, even if it's nested.
	 *
	 * @param array  $sources
	 * @param string $key
	 *
	 * @return array|null
	 */
	private function _findSource($key, $sources)
	{
		if (isset($sources[$key]))
		{
			return $sources[$key];
		}
		else
		{
			// Look through any nested sources
			foreach ($sources as $source)
			{
				if (!empty($source['nested']) && ($nestedSource = $this->_findSource($key, $source['nested'])))
				{
					return $nestedSource;
				}
			}
		}
	}
}
