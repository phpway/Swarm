<?php
/**
 * Tests for the group config model.
 *
 * @copyright   2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace GroupsTest\Model;

use Application\Log\Writer\Mock as MockLog;
use Groups\Model\Config;
use Groups\Model\Group;
use P4\Log\Logger;
use P4Test\TestCase;
use Record\Cache\Cache;
use Zend\Log\Logger as ZendLogger;

class GroupTest extends TestCase
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
                        'Application' => BASE_PATH . '/module/Application/src/Application',
                        'Groups'      => BASE_PATH . '/module/Groups/src/Groups'
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
        new Group($this->p4);
    }

    /**
     * Test caching.
     */
    public function testCache()
    {
        // make test groups
        $group = new Group($this->p4);
        $group->setId('test1')->setUsers(array('user1'))->save();
        $group->setId('test2')->setUsers(array('user2'))->addSubGroup('test1')->save();

        $cache = new Cache($this->p4);
        $cache->setCacheDir(DATA_PATH . '/cache');
        $this->p4->setService('cache', $cache);

        // calling either exists, fetch or fetchAll should prime the cache.
        $this->assertTrue($cache->getItem('groups') == null);
        $this->assertTrue(Group::exists('test1', $this->p4));
        $this->assertTrue($cache->getItem('groups') !== null);

        // array reader should work as well
        $file   = $cache->getFile('groups');
        $reader = new \Record\Cache\ArrayReader($file);
        $reader->openFile();
        $this->assertTrue(count($reader) > 0);
        $reader->closeFile();

        // subsequent calls should run no commands.
        // verify this by peeking at the log
        $original = Logger::hasLogger() ? Logger::getLogger() : null;
        $logger   = new ZendLogger;
        $mock     = new MockLog;
        $logger->addWriter($mock);
        Logger::setLogger($logger);

        $this->assertTrue(Group::exists('test2', $this->p4));
        $this->assertSame(0, count($mock->events));

        // test fetching
        $group = Group::fetch('test1', $this->p4);
        $this->assertSame('test1', $group->getId());
        $this->assertSame($this->p4, $group->getConnection());

        // test various fetch all options
        $groups = Group::fetchAll(null, $this->p4);
        $this->assertSame(array('test1', 'test2'), $groups->invoke('getId'));

        $groups = Group::fetchAll(array(Group::FETCH_MAXIMUM => 1), $this->p4);
        $this->assertSame(array('test1'), $groups->invoke('getId'));

        $groups = Group::fetchAll(array(Group::FETCH_BY_USER => 'user1'), $this->p4);
        $this->assertSame(array('test1'), $groups->invoke('getId'));

        $groups = Group::fetchAll(array(Group::FETCH_BY_USER => 'user1', Group::FETCH_INDIRECT => true), $this->p4);
        $this->assertSame(array('test1', 'test2'), $groups->invoke('getId'));

        $groups = Group::fetchAll(array(Group::FETCH_BY_NAME => 'test1'), $this->p4);
        $this->assertSame(array('test1'), $groups->invoke('getId'));

        $members = Group::fetchMembers('test2', array(Group::FETCH_INDIRECT => true), $this->p4);
        $this->assertSame(array('user2', 'user1'), $members);

        $members = Group::fetchMembers('test2', array(), $this->p4);
        $this->assertSame(array('user2'), $members);

        $this->assertSame(0, count($mock->events));

        // restore original logger if there is one.
        Logger::setLogger($original);
    }

    public function testRecursiveSubGroups()
    {
        // make test groups
        $group = new Group($this->p4);
        $group->setId('test1')->setUsers(array('user1'))->addSubGroup('test2')->save();
        $group->setId('test2')->setUsers(array('user2'))->addSubGroup('test1')->save();

        // verify member fetching works without indirect (so should be safe regardless)
        $this->assertSame(
            array('user1'),
            Group::fetchMembers('test1', array(), $this->p4)
        );

        // and with indirect (so endless looping is a risk)
        $this->assertSame(
            array('user1', 'user2'),
            Group::fetchMembers('test1', array(Group::FETCH_INDIRECT => true), $this->p4)
        );
    }

    public function testFetchAllMembers()
    {
        // make test groups
        // sub-groups schema:
        //  test1
        //    test2
        //      test1
        //    test4
        //      test3
        //  test5
        $group = new Group($this->p4);
        $group->setId('test1')->setUsers(array('user1'))->setOwners(array('owner1'))
              ->setSubGroups(array('test2', 'test4'))->save();
        $group = new Group($this->p4);
        $group->setId('test2')->setUsers(array('user2A', 'user2B'))->addSubGroup('test1')->save();
        $group = new Group($this->p4);
        $group->setId('test3')->setUsers(array('user3X', 'user3Y', 'user3Z'))->save();
        $group = new Group($this->p4);
        $group->setId('test4')->setUsers(array('user40', 'user41', 'user42'))
              ->setSubGroups(array('test3'))->setOwners(array('owner41'))->save();
        $group = new Group($this->p4);
        $group->setId('test5')->setUsers(array('user51', 'user52'))->save();

        // create cache and prime it
        $cache = new Cache($this->p4);
        $cache->setCacheDir(DATA_PATH . '/cache');
        $this->p4->setService('cache', $cache);

        // calling either exists, fetch or fetchAll should prime the cache.
        $this->assertTrue(Group::exists('test1', $this->p4));

        // set mock logger to verify that sub-sequent calls to fetchAllMambers() won't run any p4 commands
        $original = Logger::hasLogger() ? Logger::getLogger() : null;
        $logger   = new ZendLogger;
        $mock     = new MockLog;
        $logger->addWriter($mock);
        Logger::setLogger($logger);

        // test 1 (should include all members from test1, test2, test3 and test4)
        $members = Group::fetchAllMembers('test1');
        sort($members);
        $expected = array(
            'user1',
            'user2A',
            'user2B',
            'user3X',
            'user3Y',
            'user3Z',
            'user40',
            'user41',
            'user42'
        );
        $this->assertSame($expected, $members);
        $this->assertSame(0, count($mock->events));

        // test 2 (should include all members from test1, test2, test3 and test4)
        $members = Group::fetchAllMembers('test2');
        sort($members);
        $expected = array(
            'user1',
            'user2A',
            'user2B',
            'user3X',
            'user3Y',
            'user3Z',
            'user40',
            'user41',
            'user42'
        );
        $this->assertSame($expected, $members);
        $this->assertSame(0, count($mock->events));

        // test 3 (should include only members from test3)
        $members = Group::fetchAllMembers('test3');
        sort($members);
        $expected = array(
            'user3X',
            'user3Y',
            'user3Z'
        );
        $this->assertSame($expected, $members);
        $this->assertSame(0, count($mock->events));

        // test 4 (should include all members from test3 and test4)
        // also verify the 'flip' flag
        $members = Group::fetchAllMembers('test4', true);
        $members = array_keys($members);
        sort($members);
        $expected = array(
            'user3X',
            'user3Y',
            'user3Z',
            'user40',
            'user41',
            'user42'
        );
        $this->assertSame($expected, $members);
        $this->assertSame(0, count($mock->events));

        // test 5 (should include only members from test5)
        $members = Group::fetchAllMembers('test5');
        sort($members);
        $expected = array(
            'user51',
            'user52'
        );
        $this->assertSame($expected, $members);
        $this->assertSame(0, count($mock->events));

        // restore original logger if there is one.
        Logger::setLogger($original);
    }

    public function testConfig()
    {
        $group = new Group($this->p4);
        $group->setId('mygroup')
              ->setUsers(array('tester'));

        // test config provisioning by the group
        $config = $group->getConfig();
        $this->assertTrue($config instanceof Config);
        $this->assertSame('mygroup', $config->getId());
        $this->assertFalse(Config::exists('mygroup', $this->p4));

        $config->set('key', 'value');
        $group->save();
        $this->assertTrue(Config::exists('mygroup', $this->p4));
        $this->assertSame('value', $config->get('key'));

        $config = Config::fetch('mygroup', $this->p4);
        $this->assertSame('mygroup', $config->getId());
        $this->assertSame('value', $config->get('key'));

        // test providing existing config
        $group = new Group($this->p4);
        $group->setId('another-group')
              ->setUsers(array('tester'));

        $config = new Config($this->p4);
        $config->setId('foo');

        $group->setConfig($config);
        $this->assertSame('another-group', $config->getId());
        $this->assertFalse(Config::exists('foo', $this->p4));
        $this->assertFalse(Config::exists('another-group', $this->p4));

        $config->set('some', 'stuff');
        $group->save();
        $this->assertFalse(Config::exists('foo', $this->p4));
        $this->assertTrue(Config::exists('another-group', $this->p4));
        $this->assertSame('stuff', $config->get('some'));

        $config = Config::fetch('another-group', $this->p4);
        $this->assertSame('another-group', $config->getId());
        $this->assertSame('stuff', $config->get('some'));
    }
}
