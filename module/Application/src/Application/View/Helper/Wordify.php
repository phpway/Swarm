<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Application\View\Helper;

use Application\Filter\Wordify as Filter;
use Zend\View\Helper\AbstractHelper;

class Wordify extends AbstractHelper
{
    /**
     * Splits a string into words on dash, underscore or capital letters (camel-case), and capitalizes
     * each word.
     *
     * @param  string $value the string to wordify
     * @return string the wordified string
     */
    public function __invoke($value)
    {
        $filter = new Filter;
        return $filter->filter($value);
    }
}
