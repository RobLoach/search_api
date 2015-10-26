<?php

/**
 * @file
 * Contains \Drupal\search_api\Plugin\search_api\data_type\Decimal.
 */

namespace Drupal\search_api\Plugin\search_api\data_type;

use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * Provides a decimal data type.
 *
 * @SearchApiDataType(
 *   id = "decimal",
 *   label = @Translation("Decimal"),
 *   description = @Translation("A decimal field"),
 *   default = "true"
 * )
 */
class Decimal extends DataTypePluginBase {

}
