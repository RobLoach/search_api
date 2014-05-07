<?php

/**
 * @file
 * Contains SearchApiViewsHandlerFilterDate.
 */

namespace Drupal\search_api\Plugin\views\filter;

/**
 * Views filter handler base class for handling all "normal" cases.
 *
 * @ViewsFilter("search_api_date")
 */
class SearchApiFilterDate extends SearchApiFilter {

  /**
   * Add a "widget type" option.
   */
  public function defineOptions() {
    return parent::defineOptions() + array(
      'widget_type' => array('default' => 'default'),
    );
  }

  /**
   * If the date popup module is enabled, provide the extra option setting.
   */
  public function hasExtraOptions() {
    if (\Drupal::moduleHandler()->moduleExists('date_popup')) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Add extra options if we allow the date popup widget.
   */
  public function buildExraOptionsForm(&$form, &$form_state) {
    parent::buildExtraOptionsForm($form, $form_state);
    if (\Drupal::moduleHandler()->moduleExists('date_popup')) {
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
  public function valueForm(&$form, &$form_state) {
    parent::valueForm($form, $form_state);

    // If we are using the date popup widget, overwrite the settings of the form
    // according to what date_popup expects.
    if ($this->options['widget_type'] == 'date_popup' && \Drupal::moduleHandler()->moduleExists('date_popup')) {
      $form['value']['#type'] = 'date_popup';
      $form['value']['#date_format'] = 'm/d/Y';
      unset($form['value']['#description']);
    }
    elseif (empty($form_state['exposed'])) {
      $form['value']['#description'] = t('A date in any format understood by <a href="@doc-link">PHP</a>. For example, "@date1" or "@date2".', array(
        '@doc-link' => 'http://php.net/manual/en/function.strtotime.php',
        '@date1' => format_date(REQUEST_TIME, 'custom', 'Y-m-d H:i:s'),
        '@date2' => 'now + 1 day',
      ));
    }
  }

  /**
   * Add this filter to the query.
   */
  public function query() {
    if ($this->operator === 'empty') {
      $this->query->condition($this->realField, NULL, '=', $this->options['group']);
    }
    elseif ($this->operator === 'not empty') {
      $this->query->condition($this->realField, NULL, '<>', $this->options['group']);
    }
    else {
      while (is_array($this->value)) {
        $this->value = $this->value ? reset($this->value) : NULL;
      }
      $v = is_numeric($this->value) ? $this->value : strtotime($this->value, REQUEST_TIME);
      if ($v !== FALSE) {
        $this->query->condition($this->realField, $v, $this->operator, $this->options['group']);
      }
    }
  }

}
