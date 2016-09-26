<?php
/**
 * Tests for the StringToId filter
 *
 * @copyright   2015 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Filter;

use \Application\Filter\StringToId;

class StringToIdTest extends \PHPUnit_Framework_TestCase
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

    public function testMbStringToId()
    {
        if (!function_exists('mb_strtolower')) {
            $this->markTestSkipped('mbstring extension not installed, skipping.');
        }

        $filter = new StringToId();
        $this->assertSame('abcü123', $filter->filter('abcÜ123'));
    }

    public function testNoMbStringToId()
    {
        if (function_exists('mb_strtolower')) {
            $this->markTestSkipped('mbstring extension installed, skipping.');
        }

        $filter = new StringToId();
        $this->assertSame('abc-123', $filter->filter('abcÜ123'));
    }

    /**
     * @dataProvider testStrings
     */
    public function testStringToId($actual, $expected)
    {
        $filter = new StringToId();
        $this->assertSame($expected, $filter->filter($actual));
    }

    public function testStrings()
    {
        return array(
            array('', ''),
            array('abc123', 'abc123'),
            array('ABC123', 'abc123'),
            array('abc~!@#$%^&*(){}[]123', 'abc-123'),
            array('abc123~!@#$%^&*(){}[]', 'abc123'),
            array('ドラ���ゴン', 'ドラ-ゴン'),
            array('ドラゴン12~!@#$%^&*(){}[]', 'ドラゴン12')
        );
    }
}
