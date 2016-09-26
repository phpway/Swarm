<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace UsersTest\Controller;

use Groups\Model\Group;
use ModuleTest\TestControllerCase;
use Projects\Model\Project;
use Users\Authentication\Adapter as AuthAdapter;
use Users\Model\User;
use Zend\Http\Header\Authorization;
use Zend\ServiceManager\ServiceManager;
use Zend\Stdlib\Parameters;

class IndexControllerTest extends TestControllerCase
{
    /**
     * Test the index action.
     */
    public function testIndexAction()
    {
        $this->dispatch('/');

        $this->assertRoute('home');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
    }

    /**
     * Test the user action.
     */
    public function testUserAction()
    {
        $this->dispatch('/users/foo');

        $result = $this->getResult();
        $this->assertRoute('user');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'user');
        $this->assertResponseStatusCode(404);

        // test blank user
        $this->resetApplication();
        $this->dispatch('/users/tester');

        $result = $this->getResult();
        $this->assertRoute('user');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'user');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertSame('tester', $result->getVariable('user')->getId());
        $this->assertQuery('a[href="/activity/streams/user-tester/rss/"]');
        $this->assertQueryContentContains('.profile-info .title', 'tester');
    }

    public function testUsersActinWithPrivateProjects()
    {
        // make few projects with 'tester' as member
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'      => 'public',
                'members' => array('tester')
            )
        )->save();
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'      => 'private',
                'members' => array('tester'),
                'private' => true
            )
        )->save();
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'      => 'private-2',
                'members' => array('tester', 'foo'),
                'private' => true
            )
        )->save();

        $p4Foo = $this->connectWithAccess('foo',  array('//...'));

        // look at tester's profile as user foo and verify that only public and private-2 projects are visible
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $p4Foo);

        $this->dispatch('/users/tester');

        $result = $this->getResult();
        $this->assertRoute('user');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'user');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $projects = $result->getVariable('projects')->invoke('getId');
        sort($projects);
        $this->assertSame(array('private-2', 'public'), $projects);
    }

    public function testUsersActionUnauthenticated()
    {
        // verify that non-authenticated users don't have access
        $services = $this->getApplication()->getServiceManager();
        $services->setFactory(
            'p4_user',
            function () {
                throw new \Application\Permissions\Exception\UnauthorizedException;
            }
        );

        $this->dispatch('/users');
        $this->assertRoute('login');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'login');
        $this->assertResponseStatusCode(401);
    }

    public function testUsersActionNoQueryParams()
    {
        // although there are exitsing users at this point, create another one to test output on
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->save();

        // test action with no query params
        $this->dispatch('/users');

        $result = $this->getResult();
        $this->assertRoute('users');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'users');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $users = json_decode($this->getResponse()->getBody(), true);

        // pick data for 'foo' from output and verify fields
        $this->assertTrue(count($users) >= 1);
        $fooData = current(
            array_filter(
                $users,
                function ($data) {
                    return $data['User'] === 'foo';
                }
            )
        );

        $expectedFields = array(
            'User',
            'Type',
            'Email',
            'Update',
            'Access',
            'FullName',
            'JobView',
            'Password',
            'Reviews'
        );

        // verify that all expected fields are present (newer servers can have additional fields, e.g. AuthMethod)
        $this->assertTrue(array_intersect($expectedFields, array_keys($fooData)) === $expectedFields);

        $this->assertSame('foo', $fooData['User']);
        $this->assertSame('foo@test.com', $fooData['Email']);
        $this->assertSame('Mr Foo', $fooData['FullName']);
    }

    public function testUsersActionWithQueryParams()
    {
        // although there are exitsing users at this point, create another one to test output on
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->save();

        // test action with query params to pick 'id' and 'email' fields
        $this->getRequest()->setQuery(new Parameters(array('fields' => array('User', 'Email'))));
        $this->dispatch('/users');

        $result = $this->getResult();
        $this->assertRoute('users');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'users');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $users = json_decode($this->getResponse()->getBody(), true);

        // pick data for 'foo' from output and verify fields
        $this->assertTrue(count($users) >= 1);
        $fooData = current(
            array_filter(
                $users,
                function ($data) {
                    return $data['User'] === 'foo';
                }
            )
        );

        $expectedFields = array('User', 'Email');
        $this->assertTrue(count($fooData) === count($expectedFields));
        $this->assertTrue(count(array_diff($expectedFields, array_keys($fooData))) === 0);

        $this->assertSame('foo', $fooData['User']);
        $this->assertSame('foo@test.com', $fooData['Email']);
    }

    public function testUsersActionWithGroupParam()
    {
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->save();

        Group::fromArray(array('Group' => 'mygroup', 'Users' => array('foo')), $this->superP4)->save();

        // ensure if we pass non-existing group, we get empty set back
        $this->getRequest()->setQuery(new Parameters(array('fields' => array('User'), 'group' => 'noexist')));
        $this->dispatch('/users');

        $result = $this->getResult();
        $this->assertRoute('users');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'users');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $users = json_decode($this->getResponse()->getBody(), true);
        $this->assertSame(array(), $users);

        // try again with valid group
        $this->resetApplication();
        $this->getRequest()->setQuery(new Parameters(array('fields' => array('User'), 'group' => 'mygroup')));
        $this->dispatch('/users');

        $result = $this->getResult();
        $this->assertRoute('users');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'users');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $users = json_decode($this->getResponse()->getBody(), true);

        $this->assertSame(1,     count($users));
        $this->assertSame(1,     count($users[0]));
        $this->assertSame('foo', $users[0]['User']);
    }

    /**
     * Test the login action with no data posted.
     */
    public function testLoginNoPost()
    {
        $services = $this->getApplication()->getServiceManager();
        $services->setFactory(
            'p4_user',
            function () {
                throw new \Application\Permissions\Exception\UnauthorizedException;
            }
        );

        $this->dispatch('/login');

        $this->assertRoute('login');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'login');
        $this->assertResponseStatusCode(200);
        $this->assertQuery('div.login-dialog form input[name="user"]');
        $this->assertQuery('div.login-dialog form input[name="password"]');
    }

    /**
     * Test the login action with posted valid credentials.
     */
    public function testLoginPostValid()
    {
        // create a new user
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->setPassword('abcd1234')
             ->save();

        $postData = new Parameters(
            array(
                'user'     => 'foo',
                'password' => 'abcd1234'
            )
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        $this->dispatch('/login');

        $result = $this->getResult();
        $this->assertRoute('login');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'login');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertTrue($result->getVariable('isValid'));
    }

    /**
     * Test the login action with posted valid credentials using email for user id.
     */
    public function testLoginPostValidEmail()
    {
        // create a new user
        $user = new User($this->p4);
        $user->setId('foo')
            ->setEmail('foo@test.com')
            ->setFullName('Mr Foo')
            ->setPassword('abcd1234')
            ->save();

        $postData = new Parameters(
            array(
                'user'     => 'foo@test.com',
                'password' => 'abcd1234'
            )
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        $this->dispatch('/login');

        $result = $this->getResult();
        $this->assertRoute('login');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'login');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertTrue($result->getVariable('isValid'));
    }

    /**
     * Test the login action with posted invalid credentials.
     */
    public function testLoginPostInvalid()
    {
        // create a new user
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->setPassword('abcd1234')
             ->save();

        $tests = array(
            array(
                'user'      => '',
                'password'  => ''
            ),
            array(
                'user'      => null,
                'password'  => null
            ),
            array(
                'user'      => 'foo',
                'password'  => 'wrong'
            ),
            array(
                'user'      => 'foo',
                'password'  => ''
            ),
            array(
                'user'      => 'foo',
                'password'  => null
            ),
            array(
                'user'      => 'wrong',
                'password'  => 'abcd1234'
            ),
            array(
                'user'      => '',
                'password'  => 'abcd1234'
            ),
            array(
                'user'      => null,
                'password'  => 'abcd1234'
            ),
            array(
                'user'      => 'foo ',
                'password'  => 'abcd1234'
            ),
            array(
                'user'      => 'foo',
                'password'  => 'abcd1234 '
            ),
            array(
                'user'      => ' foo',
                'password'  => 'abcd1234'
            ),
            array(
                'user'      => 'foo',
                'password'  => ' abcd1234'
            )
        );

        // run tests
        foreach ($tests as $test) {
            $this->getRequest()
                ->setMethod(\Zend\Http\Request::METHOD_POST)
                ->setPost(new Parameters($test));

            $this->dispatch('/login');

            $result = $this->getResult();
            $this->assertRoute('login');
            $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'login');
            $this->assertResponseStatusCode(200);
            $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
            $this->assertFalse($result->getVariable('isValid'));

            $this->resetApplication();
        }
    }

    /**
     * Test the logout action.
     */
    public function testLogout()
    {
        // add a user
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->setPassword('abcd1234')
             ->save();

        // first get the session and re-open it so the auth adapter can function
        $services = $this->getApplication()->getServiceManager();
        $session  = $services->get('session');
        $session->start();

        // authenticate the foo user
        $auth    = $services->get('auth');
        $adapter = new AuthAdapter('foo', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);
        $this->assertTrue($auth->hasIdentity());

        // now that we're authed, close the session to match standard behaviour
        $session->writeClose();

        // ensure closing didn't muck anything
        $this->assertTrue($auth->hasIdentity());

        // logout via dispatching to 'logout' action
        $this->dispatch('/logout');
        $this->assertRoute('logout');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'logout');
        $this->assertResponseStatusCode(302);
        $this->assertFalse($auth->hasIdentity());
    }

    /**
     * Test the follow action.
     */
    public function testBasicFollowing()
    {
        // add a user
        $user = new User($this->p4);
        $user->setId('foo')
            ->setEmail('foo@test.com')
            ->setFullName('Mr Foo')
            ->setPassword('abcd1234')
            ->save();

        // authenticate the foo user
        $auth    = $this->getApplication()->getServiceManager()->get('auth');
        $adapter = new AuthAdapter('foo', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        // verify get of follow indicates not currently a follower
        $this->dispatch('/follow/user/tester');
        $this->assertRoute('follow');
        $this->assertResponseStatusCode(200);
        $result = json_decode($this->getResponse()->getBody(), true);
        $this->assertSame(array('isFollowing' => false), $result);

        // foo follows tester
        $this->getRequest()->setMethod(\Zend\Http\Request::METHOD_POST);
        $this->dispatch('/follow/user/tester');
        $this->assertRoute('follow');
        $this->assertResponseStatusCode(200);

        $user = User::fetch('foo', $this->p4);
        $this->assertSame(array('user' => array('tester')), $user->getConfig()->getFollows());

        // verify get of follow indicates now a follower
        $this->getRequest()->setMethod(\Zend\Http\Request::METHOD_GET);
        $this->dispatch('/follow/user/tester');
        $result = json_decode($this->getResponse()->getBody(), true);
        $this->assertSame(array('isFollowing' => true), $result);

        // add a new project
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'        => 'p1',
                'members'   => array('x', 'y')
            )
        )->save();

        // foo follows p1
        $this->getRequest()->setMethod(\Zend\Http\Request::METHOD_POST);
        $this->dispatch('/follow/project/p1');
        $this->assertRoute('follow');
        $this->assertResponseStatusCode(200);

        $user = User::fetch('foo', $this->p4);
        $this->assertSame(array('user' => array('tester'), 'project' => array('p1')), $user->getConfig()->getFollows());

        // verify indexing works
        $result = $this->p4->run('search', '1202=' . strtoupper(bin2hex('user:tester')));
        $this->assertSame('swarm-user-foo', $result->getData(0));
        $result = $this->p4->run('search', '1202=' . strtoupper(bin2hex('project:p1')));
        $this->assertSame('swarm-user-foo', $result->getData(0));
    }

    /**
     * Test follow with bad inputs
     */
    public function testBadFollowing()
    {
        $this->getRequest()->setMethod(\Zend\Http\Request::METHOD_POST);
        $this->dispatch('/follow/asdf/adsfsd');
        $result = json_decode($this->getResponse()->getBody(), true);
        $this->assertSame(false, $result['isValid']);
        $this->assertSame(array('type'), array_keys($result['messages']));

        $this->dispatch('/follow/user/alskdjfsd');
        $result = json_decode($this->getResponse()->getBody(), true);
        $this->assertSame(false, $result['isValid']);
        $this->assertSame(array('id'), array_keys($result['messages']));
    }

    /**
     * Test the follow action.
     */
    public function testUnfollowing()
    {
        // add a user
        $user = new User($this->p4);
        $user->setId('foo')
            ->setEmail('foo@test.com')
            ->setFullName('Mr Foo')
            ->setPassword('abcd1234')
            ->save();

        // authenticate the foo user
        $auth    = $this->getApplication()->getServiceManager()->get('auth');
        $adapter = new AuthAdapter('foo', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        // foo follows tester
        $this->getRequest()->setMethod(\Zend\Http\Request::METHOD_POST);
        $this->dispatch('/follow/user/tester');
        $this->assertRoute('follow');
        $this->assertResponseStatusCode(200);

        $user = User::fetch('foo', $this->p4);
        $this->assertSame(array('user' => array('tester')), $user->getConfig()->getFollows());

        // foo unfollows tester
        $this->dispatch('/unfollow/user/tester');
        $this->assertRoute('unfollow');
        $this->assertResponseStatusCode(200);
        $user = User::fetch('foo', $this->p4);
        $this->assertSame(array(), $user->getConfig()->getFollows());

        // verify index cleaned up.
        $result = $this->p4->run('search', '1201=' . strtoupper(bin2hex('user:tester')));
        $this->assertSame(array(), $result->getData());
    }

    /**
     * Test user cache invalidation
     */
    public function testCacheClearing()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $cache    = $services->get('p4_admin')->getService('cache');

        // request non-existent user
        $this->dispatch('/users/foo');
        $this->assertResponseStatusCode(404);

        // cache should be primed now
        $this->assertNotNull($cache->getItem('users'));

        // array reader should work as well
        $file   = $cache->getFile('users');
        $reader = new \Record\Cache\ArrayReader($file);
        $reader->openFile();
        $this->assertTrue(count($reader) > 0);
        $reader->closeFile();

        // create the non-existent user
        $user = new User($this->p4);
        $user->setId('foo')
            ->setEmail('foo@domain.com')
            ->setFullName('Foo Bar')
            ->save();

        // due to cache, should still be unknown
        $this->resetApplication();
        $this->dispatch('/users/foo');
        $this->assertResponseStatusCode(404);

        // simulate effect of user form-commit trigger
        $queue->addTask('user', 'foo');

        // process queue (this should invalidate user cache)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $cache = $services->get('p4_admin')->getService('cache');
        $this->assertTrue(
            $cache->getItem('users') === null,
            'expected null users cache'
        );

        // user foo should be known now.
        $this->resetApplication();
        $this->dispatch('/users/foo');
        $this->assertResponseStatusCode(200);
    }

    public function testBasicAuthenticationFailure()
    {
        $request = $this->getRequest();
        $headers = $request->getHeaders();

        $headers->addHeader(Authorization::fromString('Authorization: Basic ' . base64_encode('testuser:testpass')));
        $request->setHeaders($headers);

        $this->dispatch('/users');

        $this->assertSame(401, $this->getResponse()->getStatusCode());
    }

    public function testBasicAuthenticationFailureJson()
    {
        $request = $this->getRequest();
        $headers = $request->getHeaders();

        $headers->addHeader(Authorization::fromString('Authorization: Basic ' . base64_encode('testuser:testpass')));
        $request->setHeaders($headers);

        $result = $this->dispatch('/users?format=json');
        $result = json_decode($result, true);

        $this->assertSame(401, $this->getResponse()->getStatusCode());
        $this->assertSame(array('error' => 'Unauthorized'), $result);
    }

    public function testBasicAuthenticationSuccess()
    {
        $user = new User;
        $user->setId('testuser');
        $user->setFullName('Test User');
        $user->setEmail('testuser@example.com');
        $user->setPassword('testpass');
        $user->save();

        $request = $this->getRequest();
        $headers = $request->getHeaders();

        $headers->addHeader(Authorization::fromString('Authorization: Basic ' . base64_encode('testuser:testpass')));
        $request->setHeaders($headers);

        $result = $this->dispatch('/users?format=json');
        $result = json_decode($result, true);

        $this->assertSame(200, $this->getResponse()->getStatusCode());
        $this->assertTrue(isset($result[3]['User']));
        $this->assertSame('testuser', $result[3]['User']);
    }

    /**
     * We need to reconfigure the service manager to test basic auth
     * This is because normally the tests fake out a logged in user
     * We need to undo this fakery and restore the original factories.
     *
     * @param ServiceManager $services
     */
    public function configureServiceManager(ServiceManager $services)
    {
        parent::configureServiceManager($services);

        if (strpos($this->getName(), 'testBasicAuthentication') === 0) {
            $config = $services->get('config');

            // need to copy the test p4d server's rsh port into config
            // so that the p4_user factory can connect to it
            $config['p4']['port'] = $this->p4Params['port'];
            $services->setService('config', $config);

            // restore the original 'auth' and 'p4_user' factories
            $services->setFactory('auth', $config['service_manager']['factories']['auth']);
            $services->setFactory('p4_user', $config['service_manager']['factories']['p4_user']);
        }
    }
}
