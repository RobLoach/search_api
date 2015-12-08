<?php

/**
 * @file
 * Contains SearchApiViewsHandlerFilterDate.
 */

/**
 * Views filter handler base class for handling all "normal" cases.
 */
class SearchApiViewsHandlerFilterDate extends SearchApiViewsHandlerFilter {
  
  /**
   * Add a "widget type" option.
   */
  public function option_definition() {
    return parent::option_definition() + array(
      'widget_type' => array('default' => 'default'),
    );
  }

  /**
   * If the date popup module is enabled, provide the extra option setting.
   */
  public function has_extra_options() {
    return module_exists('date_popup');
  }

  /**
   * Add extra options if we allow the date popup widget.
   */
  public function extra_options_form(&$form, &$form_state) {
    parent::extra_options_form($form, $form_state);
    if (module_exists('date_popup')) {
      $widget_options = array('default' => 'Default', 'date_popup' => 'Date popup');
      $form['widget_type'] = array(
        '#type' => 'radios',
        '#title' => t('Date selection form element'),
        '#default_value' => $this->options['widget_type'],
        '#options' => $widget_options,
      );
    }
  }

  /**
   * Provide a form for setting the filter value.
   */
  public function value_form(&$form, &$form_state) {
    parent::value_form($form, $form_state);

    // If we are using the date popup widget, overwrite the settings of the form
    // according to what date_popup expects.
    foreach (array('value', 'min', 'max') as $field) {
      if ($this->options['widget_type'] == 'date_popup' && module_exists('date_popup')) {
        $form['value'][$field]['#type'] = 'date_popup';
        $form['value'][$field]['#date_format'] = 'm/d/Y';
        unset($form['value'][$field]['#description']);
      }
      elseif (empty($form_state['exposed'])) {
        $form['value'][$field]['#description'] = t('A date in any format understood by <a href="@doc-link">PHP</a>. For example, "@date1" or "@date2".', array(
          '@doc-link' => 'http://php.net/manual/en/function.strtotime.php',
          '@date1' => format_date(REQUEST_TIME, 'custom', 'Y-m-d H:i:s'),
          '@date2' => 'now + 1 day',
        ));
      }
    }
  }

  /**
   * Validate the build group options form.
   */
  function build_group_validate($form, &$form_state) {
    // Special case to validate grouped date filters, this is because the
    // $group['value'] array contains the type of filter (date or offset)
    // and therefore the number of items the comparision has to be done
    // against 'one' instead of 'zero'.
    foreach ($form_state['values']['options']['group_info']['group_items'] as $id => $group) {
      if (empty($group['remove'])) {
        // Check if the title is defined but value wasn't defined.
        if (!empty($group['title'])) {
          if ((!is_array($group['value']) && empty($group['value'])) || (is_array($group['value']) && count(array_filter($group['value'])) == 1)) {
            form_error($form['group_info']['group_items'][$id]['value'], t('The value is required if title for this item is defined.'));
          }
        }

        // Check if the value is defined but title wasn't defined.
        if ((!is_array($group['value']) && !empty($group['value'])) || (is_array($group['value']) && count(array_filter($group['value'])) > 1)) {
          if (empty($group['title'])) {
            form_error($form['group_info']['group_items'][$id]['title'], t('The title is required if value for this item is defined.'));
          }
        }
      }
    }
  }

  function accept_exposed_input($input) {
    if (empty($this->options['exposed'])) {
      return TRUE;
    }

    // Store this because it will get overwritten.
    $type = $this->value['type'];
    $rc = parent::accept_exposed_input($input);

    // Don't filter if value(s) are empty.
    $operators = $this->operators();
    if (!empty($this->options['expose']['use_operator']) && !empty($this->options['expose']['operator_id'])) {
      $operator = $input[$this->options['expose']['operator_id']];
    }
    else {
      $operator = $this->operator;
    }

    if ($operators[$operator]['values'] == 1) {
      if ($this->value['value'] == '') {
        return FALSE;
      }
    }
    else {
      if ($this->value['min'] == '' || $this->value['max'] == '') {
        return FALSE;
      }
    }

    // restore what got overwritten by the parent.
    $this->value['type'] = $type;
    return $rc;
  }

  function op_between($field) {
    $a = intval(strtotime($this->value['min'], 0));
    $b = intval(strtotime($this->value['max'], 0));

    // This is safe because we are manually scrubbing the values.
    // It is necessary to do it this way because $a and $b are formulas when using an offset.
    $operator = strtoupper($this->operator);
    $this->query->add_where_expression($this->options['group'], "$field $operator $a AND $b");
  }

  function op_simple($field) {
    $value = intval(strtotime($this->value['value'], 0));

    // This is safe because we are manually scrubbing the value.
    // It is necessary to do it this way because $value is a formula when using an offset.
    $this->query->add_where_expression($this->options['group'], "$field $this->operator $value");
  }

  /**
   * Add this filter to the query.
   */
  public function query() {
    if ($this->operator === 'empty') {
      $this->query->condition($this->real_field, NULL, '=', $this->options['group']);
    }
    elseif ($this->operator === 'not empty') {
      $this->query->condition($this->real_field, NULL, '<>', $this->options['group']);
    }
    else {
      // Convert php relative dates to valid timestamps for searching.
      if ($this->operator != 'between') {
        $keys = array('value' => '00:00:00.000');
      }
      else {
        $keys = array('min' => '00:00:00.000', 'max' => '23:59:59.999');
      }

      $values = array();
      foreach ($keys as $key => $time) {
        if (($v = (is_numeric($this->value[$key]) ? $this->value[$key] : strtotime($this->value[$key]." $time+00"))) !== FALSE) {
          $values[] = $v;
        }
      }

      if (count($values) == 2 && $values[0] == $values[1]) {
        unset($values[1]);
        $this->operator = '=';
        drupal_set_message(t('Date range in view has the same min and max value. Resetting operator to =.'), 'warning');
      }
      if (($nvalues = count($values)) == 1) {
        $this->query->condition($this->real_field, $values[0], $this->operator, $this->options['group']);
      }
      else if ($nvalues == 2) {
        $op1 = $this->operator == 'between' ? '>=' : '<';
        $op2 = $this->operator == 'between' ? '<=' : '>';
        $this->query->condition($this->real_field, $values[0], $op1, $this->options['group']);
        $this->query->condition($this->real_field, $values[1], $op2, $this->options['group']);
      }
    }
  }

}
