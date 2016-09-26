<?php
/**
 * Tests for the FormBoolean filter
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Filter;

use \Application\Filter\FormBoolean;

class FormBooleanTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                'Zend\Loader\StandardAutoloader' => array(
                    'namespaces' => array(
                        'Application' => BASE_PATH . '/module/Application/src/Application'
                    )
                )
            )
        );
    }

    public function testTruthiness()
    {
        $boolean = new FormBoolean;

        // you know that's right
        $this->assertSame(true, $boolean->filter('1'));
        $this->assertSame(true, $boolean->filter('true'));
        $this->assertSame(true, $boolean->filter(1));
        $this->assertSame(true, $boolean->filter(''));
        $this->assertSame(true, $boolean->filter('true dat'));

        // NOPE. Nope nope nope.
        $this->assertSame(false, $boolean->filter('0'));
        $this->assertSame(false, $boolean->filter(0));
        $this->assertSame(false, $boolean->filter('false'));
        $this->assertSame(false, $boolean->filter(null));
    }
}
