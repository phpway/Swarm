<?php
/**
 * Tests for the user config model.
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace UsersTest\Model;

use Application\Log\Writer\Mock as MockLog;
use P4\Log\Logger;
use P4Test\TestCase;
use Record\Cache\Cache;
use Users\Model\User;
use Zend\Log\Logger as ZendLogger;

class UserTest extends TestCase
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
                        'Users'       => BASE_PATH . '/module/Users/src/Users'
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
        new User($this->p4);
    }

    /**
     * Test caching
     */
    public function testCache()
    {
        // make another test user
        $user = new User($this->p4);
        $user->setId('jdoe')
             ->setEmail('jdoe@domain.com')
             ->setFullName('J Doe')
             ->save();

        $cache = new Cache($this->p4);
        $cache->setCacheDir(DATA_PATH . '/cache');
        $this->p4->setService('cache', $cache);

        // calling either exists, fetch or fetchAll should prime the cache.
        $this->assertTrue(User::exists('tester', $this->p4));
        $this->assertTrue($cache->getItem('users') !== null);

        // subsequent calls should run no commands.
        // verify this by peeking at the log
        $original = Logger::hasLogger() ? Logger::getLogger() : null;
        $logger   = new ZendLogger;
        $mock     = new MockLog;
        $logger->addWriter($mock);
        Logger::setLogger($logger);

        $this->assertTrue(User::exists('tester', $this->p4));
        $this->assertSame(0, count($mock->events));

        // test fetching
        $user = User::fetch('tester', $this->p4);
        $this->assertSame('tester', $user->getId());
        $this->assertSame($this->p4, $user->getConnection());

        // test various fetch all options
        $users = User::fetchAll(null, $this->p4);
        $this->assertSame(array('jdoe', 'tester'), $users->invoke('getId'));

        $users = User::fetchAll(array(User::FETCH_MAXIMUM => 1), $this->p4);
        $this->assertSame(array('jdoe'), $users->invoke('getId'));

        $users = User::fetchAll(array(User::FETCH_BY_NAME => 'test*'), $this->p4);
        $this->assertSame(array('tester'), $users->invoke('getId'));

        $users = User::fetchAll(array(User::FETCH_BY_NAME => array('tester', 'j*')), $this->p4);
        $this->assertSame(array('jdoe', 'tester'), $users->invoke('getId'));

        $users = User::fetchAll(array(User::FETCH_BY_NAME => array('tester', 'j*e')), $this->p4);
        $this->assertSame(array('jdoe', 'tester'), $users->invoke('getId'));

        $users = User::fetchAll(
            array(User::FETCH_BY_NAME => array('tester', 'j*'), User::FETCH_MAXIMUM => 1),
            $this->p4
        );
        $this->assertSame(array('jdoe'), $users->invoke('getId'));

        $this->assertSame(0, count($mock->events));

        // restore original logger if there is one.
        Logger::setLogger($original);
    }
}
