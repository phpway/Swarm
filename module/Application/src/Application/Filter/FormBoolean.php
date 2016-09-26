<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Application\Filter;

use Zend\Filter\AbstractFilter;

class FormBoolean extends AbstractFilter
{
    public function filter($value)
    {
        if (is_null($value)
            || $value === 0
            || $value === '0'
            || (is_string($value) && strtolower($value) === 'false')
        ) {
            return false;
        }

        return true;
    }
}
