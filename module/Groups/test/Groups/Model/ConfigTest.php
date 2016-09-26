<?php
/**
 * Tests for the user config model.
 *
 * @copyright   2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace GroupsTest\Model;

use Groups\Model\Config;
use P4Test\TestCase;

class ConfigTest extends TestCase
{
    /**
     * Extend parent to additionally init modules we will use.
     */
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                'Zend\Loader\StandardAutoloader' => array(
                    'namespaces' => array(
                        'Groups' => BASE_PATH . '/module/Groups/src/Groups'
                    )
                )
            )
        );
    }

    /**
     * Test model creation.
     */
    public function testBasicFunction()
    {
        new Config($this->p4);
    }

    /**
     * Test basic save config
     */
    public function testSaveAndFetch()
    {
        $config = new Config($this->p4);
        $config->setId('test');
        $config->setName('name');
        $config->setDescription('foo');
        $config->setEmailFlags(array('a' => 1, 'b' => 2));
        $config->set('test', 'value');
        $config->set('some', 'stuff');
        $config->save();

        $config = Config::fetch('test', $this->p4);
        $this->assertSame(
            array(
                'id'          => 'test',
                'name'        => 'name',
                'description' => 'foo',
                'emailFlags'  => array('a' => true, 'b' => true),
                'test'        => 'value',
                'some'        => 'stuff'
            ),
            $config->get()
        );
    }
}
