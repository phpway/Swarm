<?php
/**
 * Tests for the project branch list filter.
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ProjectsTest\Filter;

use P4Test\TestCase;
use Projects\Filter\ProjectList as Filter;

class ProjectListTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                 'Zend\Loader\StandardAutoloader' => array(
                     'namespaces' => array(
                         'Projects' => BASE_PATH . '/module/Projects/src/Projects'
                     )
                 )
            )
        );
    }

    public function testBasicFunction()
    {
        $filter = new Filter($this->p4);
    }

    public function testFilterString()
    {
        $filter = new Filter($this->p4);
        $this->assertSame(
            array('test' => array()),
            $filter->filter('test')
        );
    }

    public function testFilterArray()
    {
        $filter = new Filter($this->p4);
        $this->assertSame(
            array(
                 'test'     => array(),
                 'test2'    => array('boo'),
                 'test3'    => array('main', 'dev'),
                 '123'      => array('main', 'dev')
            ),
            $filter->filter(
                array(
                     'test',
                     'test2' => 'boo',
                     'test3' => array('main', 'dev'),
                     'test3',
                     '123'   => array('main', 'dev')
                )
            )
        );
    }

    public function testMerge()
    {
        $filter = new Filter($this->p4);
        $this->assertSame(
            array(
                 'test3'    => array('main', 'dev'),
                 'test'     => array(),
                 'test2'    => array('boo')
            ),
            $filter->merge(
                'test3',
                array(
                     'test',
                     'test2' => 'boo',
                     'test3' => array('main', 'dev'),
                     'test3'
                )
            ),
            'expected to work with one string one array'
        );

        $this->assertSame(
            array(
                 'test'     => array('biz'),
                 'test2'    => array('boo', 'boz'),
                 'test3'    => array('main', 'dev', 'test')
            ),
            $filter->merge(
                array(
                     'test'  => 'biz',
                     'test2' => 'boo',
                     'test3' => array('main', 'dev'),
                     'test3'
                ),
                array(
                     'test2' => 'boz',
                     'test3' => array('main', 'test', 'dev')
                )
            ),
            'expected to work with two arrays'
        );
    }
}
