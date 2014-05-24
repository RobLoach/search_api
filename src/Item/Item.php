<?php

/**
 * @file
 * Contains \Drupal\search_api\Item\Item.
 */

namespace Drupal\search_api\Item;

use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\search_api\Exception\SearchApiException;
use Drupal\search_api\Utility\Utility;
use Drupal\search_api\Index\IndexInterface;
use Drupal\search_api\Datasource\DatasourceInterface;

/**
 * Provides a default implementation for a search item.
 */
class Item implements ItemInterface, \IteratorAggregate {

  /**
   * The search index with which this item is associated.
   *
   * @var \Drupal\search_api\Index\IndexInterface
   */
  protected $index;

  /**
   * The complex data item this Search API item is based on.
   *
   * @var \Drupal\Core\TypedData\ComplexDataInterface
   */
  protected $originalObject;

  /**
   * The datasource of this item.
   *
   * @var \Drupal\search_api\Datasource\DatasourceInterface
   */
  protected $datasource;

  /**
   * The extracted fields of this item.
   *
   * @var \Drupal\search_api\Item\FieldInterface[]
   */
  protected $fields = array();

  /**
   * Whether the fields were already extracted for this item.
   *
   * @var bool
   */
  protected $fieldsExtracted = FALSE;

  /**
   * The HTML text with highlighted text-parts that match the query.
   *
   * @var string
   */
  protected $excerpt;

  /**
   * The score this item had as a result in a corresponding search query.
   *
   * @var float
   */
  protected $score = 1.0;

  /**
   * The boost of this item at indexing time.
   *
   * @var float
   */
  protected $boost = 1.0;

  /**
   * Extra data set on this item.
   *
   * @var array
   */
  protected $extraData = array();

  /**
   * Constructs a new search item.
   *
   * @param \Drupal\search_api\Index\IndexInterface $index
   *   The item's search index.
   * @param string $id
   *   The ID of this item.
   * @param \Drupal\search_api\Datasource\DatasourceInterface|null $datasource
   *   (optional) The datasource of this item. If not set, it will be determined
   *   from the ID and loaded from the index.
   */
  public function __construct(IndexInterface $index, $id, DatasourceInterface $datasource = NULL) {
    $this->index = $index;
    $this->id = $id;
    $this->datasource = $datasource;
  }

  /**
   * {@inheritdoc}
   */
  public function getDatasource() {
    if (!isset($this->datasource)) {
      list($datasource_id) = Utility::splitCombinedId($this->id);
      $this->datasource = $this->index->getDatasource($datasource_id);
    }
    return $this->datasource;
  }

  /**
   * {@inheritdoc}
   */
  public function getIndex() {
    return $this->index;
  }

  /**
   * {@inheritdoc}
   */
  public function getId() {
    return $this->id;
  }

  /**
   * {@inheritdoc}
   */
  public function getOriginalObject($load = TRUE) {
    if (!isset($this->originalObject) && $load) {
      $this->originalObject = $this->index->loadItem($this->id);
    }
    return $this->originalObject;
  }

  /**
   * {@inheritdoc}
   */
  public function setOriginalObject(ComplexDataInterface $original_object) {
    $this->originalObject = $original_object;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getField($field_id, $extract = FALSE) {
    $fields = $this->getFields($extract);
    return isset($fields[$field_id]) ? $fields[$field_id] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getFields($extract = FALSE) {
    if ($extract && !$this->fieldsExtracted) {
      foreach (array(NULL, $this->getDatasource()->getPluginId()) as $datasource_id) {
        $fields_by_property_path = array();
        foreach ($this->index->getFieldsByDatasource($datasource_id) as $field_id => $field) {
          // Don't overwrite fields that were previously set.
          if (empty($this->fields[$field_id])) {
            $this->fields[$field_id] = clone $field;
            $fields_by_property_path[$field->getPropertyPath()] = $this->fields[$field_id];
          }
        }
        if ($datasource_id && $fields_by_property_path) {
          try {
            Utility::extractFields($this->getOriginalObject(), $fields_by_property_path);
          }
          catch (SearchApiException $e) {
            // If we couldn't load the object, just log an error and fail
            // silently to set the values.
            watchdog_exception('search_api', $e);
          }
        }
      }
      $this->fieldsExtracted = TRUE;
    }
    return $this->fields;
  }

  /**
   * {@inheritdoc}
   */
  public function setField($field_id, FieldInterface $field = NULL) {
    if ($field) {
      $this->fields[$field_id] = $field;
    }
    else {
      unset($this->fields[$field_id]);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setFields(array $fields) {
    $this->fields = $fields;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getScore() {
    return $this->score;
  }

  /**
   * {@inheritdoc}
   */
  public function setScore($score) {
    $this->score = $score;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getBoost() {
    return $this->boost;
  }

  /**
   * {@inheritdoc}
   */
  public function setBoost($boost) {
    $this->boost = $boost;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getExcerpt() {
    return $this->excerpt;
  }

  /**
   * {@inheritdoc}
   */
  public function setExcerpt($excerpt) {
    $this->excerpt = $excerpt;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hasExtraData($key) {
    return array_key_exists($key, $this->extraData);
  }

  /**
   * {@inheritdoc}
   */
  public function getExtraData($key, $default = NULL) {
    return isset($this->extraData[$key]) ? $this->extraData[$key] : $default;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllExtraData() {
    return $this->extraData;
  }

  /**
   * {@inheritdoc}
   */
  public function setExtraData($key, $data = NULL) {
    if (isset($data)) {
      $this->extraData[$key] = $data;
    }
    else {
      unset($this->extraData[$key]);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator() {
    return new \ArrayIterator($this->getFields(TRUE));
  }

  /**
   * Implements the magic __clone() method to implement a deep clone.
   */
  public function __clone() {
    // The fields definitely need to be cloned. For the extra data its hard (or,
    // rather, impossible) to tell, but we opt for cloning objects there, too,
    // to be on the (hopefully) safer side. (Ideas for later: introduce an
    // interface that tells us to not clone the data object; or check whether
    // its an entity; or introduce some other system to override this default.)
    foreach ($this->fields as $field_id => $field) {
      $this->fields[$field_id] = clone $field;
    }
    foreach ($this->extraData as $key => $data) {
      if (is_object($data)) {
        $this->extraData[$key] = clone $data;
      }
    }
  }

}
