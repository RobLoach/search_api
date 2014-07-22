<?php

/**
 * @file
 * Contains SearchApiViewsQuery.
 */

namespace Drupal\search_api\Plugin\views\query;

use Drupal\Component\Utility\String;
use Drupal\search_api\Exception;
use Drupal\search_api\Index\IndexInterface;
use Drupal\search_api\Query\FilterInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;

/**
 * Views query class using a Search API index as the data source.
 *
 * @ViewsQuery(
 *   id = "search_api_query",
 *   title = @Translation("Search API Query"),
 *   help = @Translation("Query will be generated and run using the Search API.")
 * )
 */
// @todo Add "implements QueryInterface" (and necessary methods)?
class SearchApiQuery extends QueryPluginBase {

  /**
   * Number of results to display.
   *
   * @var int
   */
  protected $limit;

  /**
   * Offset of first displayed result.
   *
   * @var int
   */
  protected $offset;

  /**
   * The index this view accesses.
   *
   * @var \Drupal\search_api\Index\IndexInterface
   */
  protected $index;

  /**
   * The query that will be executed.
   *
   * @var QueryInterface
   */
  protected $query;

  /**
   * The results returned by the query, after it was executed.
   *
   * @var array
   */
  protected $search_api_results = array();

  /**
   * Array of all encountered errors.
   *
   * Each of these is fatal, meaning that a non-empty $errors property will
   * result in an empty result being returned.
   *
   * @var array
   */
  protected $errors;

  /**
   * Whether to abort the search instead of executing it.
   *
   * @var bool
   */
  protected $abort = FALSE;

  /**
   * The names of all fields whose value is required by a handler.
   *
   * The format follows the same as Search API field identifiers (parent:child).
   *
   * @var array
   */
  protected $fields;

  /**
   * The query's sub-filters representing the different Views filter groups.
   *
   * @var array
   */
  protected $filters = array();

  /**
   * The conjunction with which multiple filter groups are combined.
   *
   * @var string
   */
  public $group_operator = 'AND';

  /**
   * Create the basic query object and fill with default values.
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    try {
      $this->errors = array();
      parent::init($view, $display, $options);
      $this->fields = array();
      if (substr($view->storage->get('base_table'), 0, 17) == 'search_api_index_') {
        $id = substr($view->storage->get('base_table'), 17);
        $this->index = entity_load('search_api_index', $id);
        $this->query = $this->index->query(array(
          'parse mode' => $this->options['parse_mode'],
        ));
      }
    }
    catch (\Exception $e) {
      $this->errors[] = $e->getMessage();
    }
  }

  public function ensureTable() {

  }

  /**
   * Add a field to the query table, possibly with an alias. This will
   * automatically call ensureTable to make sure the required table
   * exists, *unless* $table is unset.
   *
   * @param $table
   *   The table this field is attached to. If NULL, it is assumed this will
   *   be a formula; otherwise, ensureTable is used to make sure the
   *   table exists.
   * @param $field
   *   The name of the field to add. This may be a real field or a formula.
   * @param $alias
   *   The alias to create. If not specified, the alias will be $table_$field
   *   unless $table is NULL. When adding formulae, it is recommended that an
   *   alias be used.
   * @param $params
   *   An array of parameters additional to the field that will control items
   *   such as aggregation functions and DISTINCT.
   *
   * @return $name
   *   The name that this field can be referred to as. Usually this is the alias.
   */
  public function addField($table, $field, $alias = '', $params = array()) {
    $this->fields[$field] = TRUE;
    return $field;
  }

  /**
   * Add a sort to the query.
   *
   * @param $selector
   *   The field to sort on. All indexed fields of the index are valid values.
   *   In addition, the special fields 'search_api_relevance' (sort by
   *   relevance) and 'search_api_id' (sort by item id) may be used.
   * @param $order
   *   The order to sort items in - either 'ASC' or 'DESC'. Defaults to 'ASC'.
   */
  public function addSelectorOrderBy($selector, $order = 'ASC') {
    $this->query->sort($selector, $order);
  }

  /**
   * Defines the options used by this query plugin.
   *
   * Adds some access options.
   */
  public function defineOptions() {
    return parent::defineOptions() + array(
      'search_api_bypass_access' => array(
        'default' => FALSE,
      ),
      'entity_access' => array(
        'default' => FALSE,
      ),
      'parse_mode' => array(
        'default' => 'terms',
      ),
    );
  }

  /**
   * Add settings for the UI.
   *
   * Adds an option for bypassing access checks.
   */
  public function buildOptionsForm(&$form, &$form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['search_api_bypass_access'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Bypass access checks'),
      '#description' => $this->t('If the underlying search index has access checks enabled, this option allows to disable them for this view.'),
      '#default_value' => $this->options['search_api_bypass_access'],
    );

    if (\Drupal::entityManager()->getDefinition($this->index->item_type)) {
      $form['entity_access'] = array(
        '#type' => 'checkbox',
        '#title' => $this->t('Additional access checks on result entities'),
        '#description' => $this->t("Execute an access check for all result entities. This prevents users from seeing inappropriate content when the index contains stale data, or doesn't provide access checks. However, result counts, paging and other things won't work correctly if results are eliminated in this way, so only use this as a last ressort (and in addition to other checks, if possible)."),
        '#default_value' => $this->options['entity_access'],
      );
    }

    $form['parse_mode'] = array(
      '#type' => 'select',
      '#title' => $this->t('Parse mode'),
      '#description' => $this->t('Choose how the search keys will be parsed.'),
      '#options' => array(),
      '#default_value' => $this->options['parse_mode'],
    );
    foreach ($this->query->parseModes() as $key => $mode) {
      $form['parse_mode']['#options'][$key] = $mode['name'];
      if (!empty($mode['description'])) {
        $states['visible'][':input[name="query[options][parse_mode]"]']['value'] = $key;
        $form["parse_mode_{$key}_description"] = array(
          '#type' => 'item',
          '#title' => $mode['name'],
          '#description' => $mode['description'],
          '#states' => $states,
        );
      }
    }
  }

  /**
   * Builds the necessary info to execute the query.
   */
  public function build(ViewExecutable $view) {
    $this->view = $view;

    // Setup the nested filter structure for this query.
    if (!empty($this->where)) {
      // If the different groups are combined with the OR operator, we have to
      // add a new OR filter to the query to which the filters for the groups
      // will be added.
      if ($this->group_operator === 'OR') {
        $base = $this->query->createFilter('OR');
        $this->query->filter($base);
      }
      else {
        $base = $this->query;
      }
      // Add a nested filter for each filter group, with its set conjunction.
      foreach ($this->where as $group_id => $group) {
        if (!empty($group['conditions']) || !empty($group['filters'])) {
          $group += array('type' => 'AND');
          // For filters without a group, we want to always add them directly to
          // the query.
          $filter = ($group_id === '') ? $this->query : $this->query->createFilter($group['type']);
          if (!empty($group['conditions'])) {
            foreach ($group['conditions'] as $condition) {
              list($field, $value, $operator) = $condition;
              $filter->condition($field, $value, $operator);
            }
          }
          if (!empty($group['filters'])) {
            foreach ($group['filters'] as $nested_filter) {
              $filter->filter($nested_filter);
            }
          }
          // If no group was given, the filters were already set on the query.
          if ($group_id !== '') {
            $base->filter($filter);
          }
        }
      }
    }

    // Initialize the pager and let it modify the query to add limits.
    $view->initPager();
    $view->pager->query();

    // Set the search ID, if it was not already set.
    if ($this->query->getOption('search id') == get_class($this->query)) {
      $this->query->setOption('search id', 'search_api_views:' . $view->storage->id() . ':' . $view->current_display);
    }

    // Add the "search_api_bypass_access" option to the query, if desired.
    if (!empty($this->options['search_api_bypass_access'])) {
      $this->query->setOption('search_api_bypass_access', TRUE);
    }

    // If the View and the Panel conspire to provide an overridden path then
    // pass that through as the base path.
    if (!empty($this->view->override_path) && strpos(current_path(), $this->view->override_path) !== 0) {
      $this->query->setOption('search_api_base_path', $this->view->override_path);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ViewExecutable $view) {
    \Drupal::moduleHandler()->invokeAll('views_query_alter', array($view, $this));
    \Drupal::moduleHandler()->alter('search_api_views_query', $view, $this->query);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(ViewExecutable $view) {
    if ($this->errors || $this->abort) {
      if (error_displayable()) {
        foreach ($this->errors as $msg) {
          drupal_set_message(String::checkPlain($msg), 'error');
        }
      }
      $view->result = array();
      $view->total_rows = 0;
      $view->execute_time = 0;
      return;
    }

    // Calculate the "skip result count" option, if it wasn't already set to
    // FALSE.
    $skip_result_count = $this->query->getOption('skip result count', TRUE);
    if ($skip_result_count) {
      $skip_result_count = !$view->pager->useCountQuery() && empty($view->get_total_rows);
      $this->query->setOption('skip result count', $skip_result_count);
    }

    try {
      // Trigger pager preExecute().
      $view->pager->preExecute($this->query);

      // Views passes sometimes NULL and sometimes the integer 0 for "All" in a
      // pager. If set to 0 items, a string "0" is passed. Therefore, we unset
      // the limit if an empty value OTHER than a string "0" was passed.
      if (!$this->limit && $this->limit !== '0') {
        $this->limit = NULL;
      }
      // Set the range. (We always set this, as there might even be an offset if
      // all items are shown.)
      $this->query->range($this->offset, $this->limit);

      $start = microtime(TRUE);

      // Execute the search.
      $results = $this->query->execute();
      $this->search_api_results = $results;

      // Store the results.
      if (!$skip_result_count) {
        $view->pager->total_items = $view->total_rows = $results->getResultCount();
        if (!empty($this->pager->options['offset'])) {
          $this->pager->total_items -= $this->pager->options['offset'];
        }
        $view->pager->updatePageInfo();
      }
      $view->result = array();
      if ($results->getResultItems()) {
        $this->addResults($results->getResultItems(), $view);
      }
      $view->execute_time = microtime(TRUE) - $start;

      // Trigger pager postExecute().
      $view->pager->postExecute($view->result);
    }
    catch (\Exception $e) {
      $this->errors[] = $e->getMessage();
      // Recursion to get the same error behaviour as above.
      $this->execute($view);
    }
  }

  /**
   * Aborts this search query.
   *
   * Used by handlers to flag a fatal error which shouldn't be displayed but
   * still lead to the view returning empty and the search not being executed.
   *
   * @param string|null $msg
   *   Optionally, a translated, unescaped error message to display.
   */
  public function abort($msg = NULL) {
    if ($msg) {
      $this->errors[] = $msg;
    }
    $this->abort = TRUE;
  }

  /**
   * Adds Search API result items to a view's result set.
   *
   * @param \Drupal\search_api\Item\ItemInterface[] $results
   *   The search results.
   * @param \Drupal\views\ViewExecutable $view
   *   The executed view.
   */
  protected function addResults(array $results, ViewExecutable $view) {
    /** @var \Drupal\views\ResultRow[] $rows */
    $rows = array();
    $missing = array();

    // First off, we try to gather as much field values as possible without
    // loading any items.
    foreach ($results as $item_id => $result) {
      $datasource_id = $result->getDatasourceId();

      /*if (!empty($this->options['entity_access'])) {
        $entity = entity_load($this->index->item_type, $id);
        if (!$entity[$id]->access('view')) {
          continue;
        }
      }*/

      $values['search_api_id'] = $item_id;
      $values['search_api_datasource'] = $datasource_id;

      // Include the loaded item for this result row, if present, or the item
      // ID.
      $values['_item'] = $result->getOriginalObject(FALSE);
      if (!$values['_item']) {
        $values['_item'] = $item_id;
      }

      $values['search_api_relevance'] = $result->getScore();
      $values['search_api_excerpt'] = $result->getExcerpt() ?: '';

      // Gather any fields from the search results.
      foreach ($result->getFields(FALSE) as $field_id => $field) {
        if ($field->getValues()) {
          $values[$field_id] = $field->getValues();
        }
      }

      // Check whether we need to extract any properties from the result item.
      $missing_fields = array_diff_key($this->fields, $values);
      if ($missing_fields) {
        $missing[$item_id] = $missing_fields;
        if (!is_object($values['_item'])) {
          $item_ids[] = $item_id;
        }
      }

      // Save the row values for adding them to the Views result afterwards.
      $rows[$item_id] = new ResultRow($values);
    }

    // Load items of those rows which haven't got all field values, yet.
    if (!empty($item_ids)) {
      foreach ($this->index->loadItemsMultiple($item_ids) as $item_id => $object) {
        $results[$item_id]->setOriginalObject($object);
        $rows[$item_id]->_item = $object;
      }
    }

    foreach ($missing as $item_id => $missing_fields) {
      foreach ($missing_fields as $field_id) {
        $field = $results[$item_id]->getField($field_id);
        if ($field) {
          $rows[$item_id]->$field_id = $field->getValues();
        }
      }
    }

    // Finally, add all rows to the Views result set.
    $view->result = array_values($rows);
  }

  /**
   * Returns the according entity objects for the given query results.
   *
   * This is necessary to support generic entity handlers and plugins with this
   * query backend.
   *
   * If the current query isn't based on an entity type, the method will return
   * an empty array.
   */
  public function getResultEntities($results, $relationship = NULL, $field = NULL) {
    list($type, $wrappers) = $this->get_result_wrappers($results, $relationship, $field);
    $return = array();
    foreach ($wrappers as $i => $wrapper) {
      try {
        // Get the entity ID beforehand for possible watchdog messages.
        $id = $wrapper->value(array('identifier' => TRUE));

        // Only add results that exist.
        if ($entity = $wrapper->value()) {
          $return[$i] = $entity;
        }
        else {
          watchdog('search_api_views', 'The search index returned a reference to an entity with ID @id, which does not exist in the database. Your index may be out of sync and should be rebuilt.', array('@id' => $id), WATCHDOG_ERROR);
        }
      }
      catch (EntityMetadataWrapperException $e) {
        watchdog_exception('search_api_views', $e, "%type while trying to load search result entity with ID @id: !message in %function (line %line of %file).", array('@id' => $id), WATCHDOG_ERROR);
      }
    }
    return array($type, $return);
  }

  /**
   * Returns the according metadata wrappers for the given query results.
   *
   * This is necessary to support generic entity handlers and plugins with this
   * query backend.
   */
  public function get_result_wrappers($results, $relationship = NULL, $field = NULL) {
    $entity_type = $this->index->getEntityType();
    $wrappers = array();
    $load_entities = array();
    foreach ($results as $row_index => $row) {
      if ($entity_type && isset($row->entity)) {
        // If this entity isn't load, register it for pre-loading.
        if (!is_object($row->entity)) {
          $load_entities[$row->entity] = $row_index;
        }

        $wrappers[$row_index] = $this->index->entityWrapper($row->entity);
      }
    }

    // If the results are entities, we pre-load them to make use of a multiple
    // load. (Otherwise, each result would be loaded individually.)
    if (!empty($load_entities)) {
      $entities = entity_load($entity_type, array_keys($load_entities));
      foreach ($entities as $entity_id => $entity) {
        $wrappers[$load_entities[$entity_id]] = $this->index->entityWrapper($entity);
      }
    }

    // Apply the relationship, if necessary.
    $type = $entity_type ? $entity_type : $this->index->item_type;
    $selector_suffix = '';
    if ($field && ($pos = strrpos($field, ':'))) {
      $selector_suffix = substr($field, 0, $pos);
    }
    if ($selector_suffix || ($relationship && !empty($this->view->relationship[$relationship]))) {
      // Use EntityFieldHandlerHelper to compute the correct data selector for
      // the relationship.
      $handler = (object) array(
        'view' => $this->view,
        'relationship' => $relationship,
        'real_field' => '',
      );
      $selector = EntityFieldHandlerHelper::construct_property_selector($handler);
      $selector .= ($selector ? ':' : '') . $selector_suffix;
      list($type, $wrappers) = EntityFieldHandlerHelper::extract_property_multiple($wrappers, $selector);
    }

    return array($type, $wrappers);
  }

  /**
   * API function for accessing the raw Search API query object.
   *
   * @return SearchApiQueryInterface
   *   The search query object used internally by this handler.
   */
  public function getSearchApiQuery() {
    return $this->query;
  }

  /**
   * API function for accessing the raw Search API results.
   *
   * @return array
   *   An associative array containing the search results, as specified by
   *   SearchApiQueryInterface::execute().
   */
  public function getSearchApiResults() {
    return $this->search_api_results;
  }

  //
  // Query interface methods (proxy to $this->query)
  //

  public function createFilter($conjunction = 'AND', $tags = array()) {
    if (!$this->errors) {
      return $this->query->createFilter($conjunction, $tags);
    }
  }

  public function keys($keys = NULL) {
    if (!$this->errors) {
      $this->query->keys($keys);
    }
    return $this;
  }

  public function fields(array $fields) {
    if (!$this->errors) {
      $this->query->fields($fields);
    }
    return $this;
  }

  /**
   * Adds a nested filter to the search query object.
   *
   * If $group is given, the filter is added to the relevant filter group
   * instead.
   */
  public function filter(FilterInterface $filter, $group = NULL) {
    if (!$this->errors) {
      $this->where[$group]['filters'][] = $filter;
    }
    return $this;
  }

  /**
   * Set a condition on the search query object.
   *
   * If $group is given, the condition is added to the relevant filter group
   * instead.
   */
  public function condition($field, $value, $operator = '=', $group = NULL) {
    if (!$this->errors) {
      $this->where[$group]['conditions'][] = array($field, $value, $operator);
    }
    return $this;
  }

  public function sort($field, $order = 'ASC') {
    if (!$this->errors) {
      $this->query->sort($field, $order);
    }
    return $this;
  }

  public function range($offset = NULL, $limit = NULL) {
    if (!$this->errors) {
      $this->query->range($offset, $limit);
    }
    return $this;
  }

  public function getIndex() {
    return $this->index;
  }

  public function &getKeys() {
    if (!$this->errors) {
      return $this->query->getKeys();
    }
    $ret = NULL;
    return $ret;
  }

  public function getOriginalKeys() {
    if (!$this->errors) {
      return $this->query->getOriginalKeys();
    }
  }

  public function &getFields() {
    if (!$this->errors) {
      return $this->query->getFields();
    }
    $ret = NULL;
    return $ret;
  }

  public function getFilter() {
    if (!$this->errors) {
      return $this->query->getFilter();
    }
  }

  public function &getSort() {
    if (!$this->errors) {
      return $this->query->getSort();
    }
    $ret = NULL;
    return $ret;
  }

  public function getOption($name) {
    if (!$this->errors) {
      return $this->query->getOption($name);
    }
  }

  public function setOption($name, $value) {
    if (!$this->errors) {
      return $this->query->setOption($name, $value);
    }
  }

  public function &getOptions() {
    if (!$this->errors) {
      return $this->query->getOptions();
    }
    $ret = NULL;
    return $ret;
  }

}
