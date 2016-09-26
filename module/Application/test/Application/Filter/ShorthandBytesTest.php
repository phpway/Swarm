<?php
/**
 * Tests for the ShorthandBytes filter
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Filter;

use \Application\Filter\ShorthandBytes;

class ShorthandBytesTest extends \PHPUnit_Framework_TestCase
{
    protected $filter = null;

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

        $this->filter = new ShorthandBytes;
    }

    public function shorthandProvider()
    {
        // form is shorthand, bytes, optimal shorthand
        return array(
            array('1gB',    1,              '1'),
            array('1G m',   1024*1024,      '1M'),
            array('1G',     1024*1024*1024, '1G'),
            array('1MB',    1,              '1'),
            array('1.2M m', 1024*1024,      '1M'),
            array('1mb',    1,              '1'),
            array('1M',     1024*1024,      '1M'),
            array('1KB',    1,              '1'),
            array('10K',    1024*10,        '10K'),
            array('1024K',  1024*1024,      '1M'),
            array('1025K',  1025*1024,      '1025K'),
            array('1025F',  1025,           '1025'),
            array('1024KS', 1024,           '1K'),
            array('-1',     -1,             '-1'),
        );
    }

    /**
     * @dataProvider shorthandProvider
     */
    public function testToBytes($shorthand, $bytes, $optimalShorthand = null)
    {
        $this->assertSame(
            $bytes,
            ShorthandBytes::toBytes($shorthand)
        );

        $this->assertSame(
            $bytes,
            ShorthandBytes::toBytes($bytes)
        );

        if ($optimalShorthand !== null) {
            $this->assertSame(
                $bytes,
                ShorthandBytes::toBytes($optimalShorthand)
            );
        }
    }

    /**
     * @dataProvider shorthandProvider
     */
    public function testToShorthand($shorthand, $bytes, $optimalShorthand = null)
    {
        $optimalShorthand = $optimalShorthand !== null ? $optimalShorthand : $shorthand;

        $this->assertSame(
            $optimalShorthand,
            ShorthandBytes::toShorthand($shorthand)
        );
        $this->assertSame(
            $optimalShorthand,
            $this->filter->filter($shorthand)
        );

        $this->assertSame(
            $optimalShorthand,
            ShorthandBytes::toShorthand($bytes)
        );
        $this->assertSame(
            $optimalShorthand,
            $this->filter->filter($bytes)
        );

        $this->assertSame(
            $optimalShorthand,
            ShorthandBytes::toShorthand($optimalShorthand)
        );
        $this->assertSame(
            $optimalShorthand,
            $this->filter->filter($optimalShorthand)
        );
    }
}
