<?php
/**
 * Perforce Swarm
 *
 * @copyright   2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace GroupsTest\Controller;

use Groups\Model\Group;
use Groups\Model\Config as GroupConfig;
use ModuleTest\TestControllerCase;
use Projects\Model\Project;
use Users\Model\User;
use Zend\Stdlib\Parameters;

class IndexControllerTest extends TestControllerCase
{
    public function testGroupsActionUnauthenticated()
    {
        // verify that non-authenticated users don't have access
        $services = $this->getApplication()->getServiceManager();
        $services->setFactory(
            'p4_user',
            function () {
                throw new \Application\Permissions\Exception\UnauthorizedException;
            }
        );

        $this->dispatch('/groups');
        $this->assertRoute('login');
        $this->assertRouteMatch('users', 'users\controller\indexcontroller', 'login');
        $this->assertResponseStatusCode(401);
    }

    public function testGroupsActionBasicJson()
    {
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->save();

        Group::fromArray(array('Group' => 'mygroup', 'Users' => array('foo')), $this->superP4)->save();

        // test action with no query params
        $this->dispatch('/groups?format=json');

        $result = $this->getResult();
        $this->assertRoute('groups');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'groups');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $groupData = current(current(json_decode($this->getResponse()->getBody(), true)));

        $expectedFields = array(
            'Group',
            'MaxResults',
            'MaxScanRows',
            'MaxLockTime',
            'Timeout',
            'PasswordTimeout',
            'Subgroups',
            'Owners',
            'Users',
            'config',
            'name',
            'description',
            'emailFlags',
            'ownerAvatars',
            'memberCount',
            'isEmailEnabled',
            'isMember',
            'isInGroup'
        );

        $actualFields = array_diff(
            array_keys($groupData),
            array('LdapConfig', 'LdapSearchQuery', 'LdapUserAttribute', 'MaxOpenFiles')
        );
        $this->assertSame($expectedFields, array_values($actualFields));

        $this->assertSame('mygroup',    $groupData['Group']);
        $this->assertSame(array(),      $groupData['Owners']);
        $this->assertSame(array('foo'), $groupData['Users']);
        $this->assertSame(array(),      $groupData['Subgroups']);
    }

    public function testGroupsActionWithQueryParams()
    {
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->save();
        $user->setId('bar')
             ->setEmail('bar@test.com')
             ->setFullName('Mr Bar')
             ->save();

        $g1 = Group::fromArray(
            array('Group' => 'g1', 'Owners' => array('bar'), 'Users' => array('nonadmin')),
            $this->superP4
        );
        $g1->getConfig()->setEmailFlags(array('reviews' => 1));
        $g1->save();

        $g2 = Group::fromArray(
            array('Group' => 'g2', 'Owners' => array('bar'), 'Users' => array('nonadmin')),
            $this->superP4
        )->save();

        $g3 = Group::fromArray(
            array('Group' => 'g3', 'Users' => array('foo')),
            $this->superP4
        );
        $g3->getConfig()->setEmailFlags(array('reviews' => 1));
        $g3->save();

        $g4 = Group::fromArray(
            array('Group' => 'g4', 'Owners' => array('nonadmin'), 'Users' => array('foo')),
            $this->superP4
        )->save();

        $g5 = Group::fromArray(
            array('Group' => 'g5', 'Owners' => array('bar'), 'Users' => array('foo')),
            $this->superP4
        );
        $g5->getConfig()->setName('A');
        $g5->save();

        // test action with query params to pick 'id' field only
        $this->getRequest()->setQuery(
            new Parameters(
                array(
                    'format' => 'json',
                    'fields' => array('Group')
                )
            )
        );
        $this->dispatch('/groups');

        $result = $this->getResult();
        $this->assertRoute('groups');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'groups');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $groups = current(json_decode($this->getResponse()->getBody(), true));

        $this->assertSame(5,    count($groups));
        $this->assertSame(1,    count($groups[0]));
        $this->assertSame(1,    count($groups[1]));
        $this->assertSame('g1', $groups[0]['Group']);
        $this->assertSame('g2', $groups[1]['Group']);

        // verify isInGroup can't be a sub-sort
        $this->getRequest()->setQuery(
            new Parameters(
                array(
                    'format' => 'json',
                    'fields' => 'Group',
                    'sort'   => 'Group,isInGroup'
                )
            )
        );
        $this->dispatch('/groups');
        $this->assertResponseStatusCode(400);

        // test sorting by -isInGroup,-isEmailEnabled,name
        $this->getRequest()->setQuery(
            new Parameters(
                array(
                    'format' => 'json',
                    'fields' => 'Group',
                    'sort'   => '-isInGroup,-isEmailEnabled,name'
                )
            )
        );

        $this->dispatch('/groups');
        $groups = current(json_decode($this->getResponse()->getBody(), true));
        $this->assertSame(
            array('g1', 'g2', 'g4', 'g3', 'g5'),
            array_map('current', $groups)
        );

        // test limiting to max 3 and sorting by -isInGroup,-isEmailEnabled,name
        $this->getRequest()->setQuery(
            new Parameters(
                array(
                    'format' => 'json',
                    'fields' => 'Group',
                    'sort'   => '-isInGroup,-isEmailEnabled,name',
                    'max'    => 3
                )
            )
        );
        $this->dispatch('/groups');
        $groups = current(json_decode($this->getResponse()->getBody(), true));
        $this->assertSame(
            array('g1', 'g2', 'g4'),
            array_map('current', $groups)
        );

        // test limiting to max 3, skipping past g2 and sorting by -isInGroup,-isEmailEnabled,name
        $this->getRequest()->setQuery(
            new Parameters(
                array(
                    'format' => 'json',
                    'fields' => 'Group',
                    'sort'   => '-isInGroup,-isEmailEnabled,name',
                    'max'    => 3,
                    'after'  => 'g2'
                )
            )
        );
        $this->dispatch('/groups');
        $groups = current(json_decode($this->getResponse()->getBody(), true));
        $this->assertSame(
            array('g4', 'g3', 'g5'),
            array_map('current', $groups)
        );

        // test filtering for 'A' and sorting by -isInGroup,-isEmailEnabled,name
        $this->getRequest()->setQuery(
            new Parameters(
                array(
                    'format'   => 'json',
                    'fields'   => 'Group',
                    'sort'     => '-isInGroup,-isEmailEnabled,name',
                    'keywords' => 'A',
                )
            )
        );
        $this->dispatch('/groups');
        $groups = current(json_decode($this->getResponse()->getBody(), true));
        $this->assertSame(
            array('g5'),
            array_map('current', $groups)
        );
    }

    public function testGroupsActionWithPrivateProjects()
    {
        // skip this test if we are on old server where project members are not stored in groups
        if ($this->needsSuper()) {
            $this->markTestSkipped('Project doesn\'t store members in groups, skipping.');
        }

        $p4Foo = $this->connectWithAccess('foo', array('//depot/...'));
        $p4Bar = $this->connectWithAccess('bar', array('//depot/...'));

        Group::fromArray(array('Group' => 'g1', 'Users' => array('foo')), $this->superP4)->save();

        $project = new Project($this->p4);
        $project->set(
            array(
                'id'      => 'prj-public',
                'name'    => 'prj 1',
                'members' => array('a', 'bar')
            )
        )->save();

        $project->set(
            array(
                'id'      => 'prj-private-1',
                'name'    => 'prj 2',
                'members' => array('foo'),
                'private' => true
            )
        )->save();

        $project->set(
            array(
                'id'      => 'prj-private-2',
                'name'    => 'prj 3',
                'members' => array('abc'),
                'private' => true
            )
        )->save();

        // prepare function to extract group ids from the json response
        $extractGroupIds = function ($response) {
            $data   = json_decode($response, true);
            $groups = array_map(
                function (array $group) {
                    return $group['Group'];
                },
                $data['groups']
            );

            sort($groups);
            return $groups;
        };

        // test as 'foo', should see public & private-1
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $p4Foo);
        $this->dispatch('/groups?format=json');

        $result = $this->getResult();
        $this->assertRoute('groups');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'groups');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $this->assertSame(
            array(
                'g1',
                'swarm-project-prj-private-1',
                'swarm-project-prj-public'
            ),
            $extractGroupIds($this->getResponse()->getBody())
        );

        // test as 'bar', should see only public
        $this->resetApplication();
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $p4Bar);
        $this->dispatch('/groups?format=json');

        $result = $this->getResult();
        $this->assertRoute('groups');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'groups');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $this->assertSame(
            array(
                'g1',
                'swarm-project-prj-public'
            ),
            $extractGroupIds($this->getResponse()->getBody())
        );

        // test as admin, should see everything
        $this->resetApplication();
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $this->p4);
        $this->dispatch('/groups?format=json');

        $result = $this->getResult();
        $this->assertRoute('groups');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'groups');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $this->assertSame(
            array(
                'g1',
                'swarm-project-prj-private-1',
                'swarm-project-prj-private-2',
                'swarm-project-prj-public'
            ),
            $extractGroupIds($this->getResponse()->getBody())
        );
    }

    public function testAccessProjectGroup()
    {
        // skip this test if we are on old server where project members are not stored in groups
        if ($this->needsSuper()) {
            $this->markTestSkipped('Project doesn\'t store members in groups, skipping.');
        }

        // ensure that project groups cannot be accessed in Swarm
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'      => 'prj',
                'name'    => 'prj 1',
                'members' => array('a', 'bar')
            )
        )->save();

        $this->dispatch('/groups/swarm-project-prj');
        $this->assertRoute('group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'group');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test group add action when (ensure admin permission is enforced).
     */
    public function testAddActionBasic()
    {
        $this->dispatch('/groups/add');

        $result = $this->getResult();
        $this->assertRoute('add-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(403);
    }

    /**
     * Test group add action with no parameters.
     */
    public function testAddActionNoParams()
    {
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $this->needsSuper() ? $this->superP4 : $services->get('p4_admin'));

        $this->dispatch('/groups/add');

        $result = $this->getResult();
        $this->assertRoute('add-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $this->assertQueryContentContains('h1', 'Add Group');
        $this->assertQueryContentContains('form label', 'Name');
        $this->assertQueryContentContains('form label', 'Description');
        $this->assertQueryContentContains('form label', 'Owners');
        $this->assertQueryContentContains('form label', 'Members');
        $this->assertQuery('form input[name="name"]');
        $this->assertQuery('form textarea[name="description"]');
        $this->assertQuery('form .control-group-owners input#owners');
        $this->assertQuery('form .control-group-members input#members');
    }

    /**
     * Test group add action.
     *
     * @dataProvider addParamsProvider
     */
    public function testAddActionPost(array $createUsers, array $postData, array $messages)
    {
        foreach ($createUsers as $id) {
            $user = new User($this->p4);
            $user->setId($id)->set('FullName', $id)->set('Email', $id . '@test')->save();
        }

        $postData = new Parameters($postData);
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $this->needsSuper() ? $this->superP4 : $services->get('p4_admin'));

        $this->dispatch('/groups/add');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('add-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);

        if ($messages) {
            $this->assertSame(false, $result->getVariable('isValid'));
            $responseMessages = $result->getVariable('messages');
            foreach ($messages as $message) {
                list($messageField, $messageValidator, $messageValue) = $message;
                $this->assertTrue(array_key_exists($messageField, $responseMessages));
                $this->assertSame($messageValue, $responseMessages[$messageField][$messageValidator]);
            }

            // ensure group was not created (only if name was set)
            if (isset($postData['name'])) {
                $this->assertFalse(Group::exists($postData['name'], $this->p4));
            }
        } else {
            // if no messages, check the group was saved
            $name = $postData['name'];
            $this->assertSame(true, $result->getVariable('isValid'));
            $this->assertTrue(Group::exists($name, $this->p4));
        }
    }

    public function addParamsProvider()
    {
        // return values are:
        // - list of users (by ids) to create before running the test
        // - post data
        // - list of message sets expected in response (each set contains 3 values -
        //   form field name, validator name, message) or empty array if valid post is expected
        return array(
            // valid params
            array(
                array('foo', 'bar'),
                array(
                    'name'  => 'grp1',
                    'Users' => array('foo', 'bar')
                ),
                array()
            ),
            array(
                array('x', 'xy', 'xxz'),
                array(
                    'name'        => 'grp2',
                    'Users'       => array('x', 'xy', 'xxz'),
                    'Owners'      => array('xy'),
                    'description' => 'foo desc'
                ),
                array()
            ),
            array(
                array('foo', 'bar'),
                array(
                    'name'  => 'ドラゴン',
                    'Users' => array('foo', 'bar'),
                ),
                array()
            ),
            array(
                array('foo'),
                array(
                    'name'  => 'grp3',
                    'Owners' => array('foo')
                ),
                array()
            ),
            array(
                array('a'),
                array(
                    'name'  => 'foo',
                    'Users' => array('madeup')
                ),
                array()
            ),
            array(
                array(),
                array(
                    'name'      => 'grp1',
                    'Users'     => array('madeup'),
                    'Subgroups' => array('anothermadeup')
                ),
                array()
            ),

            // invalid params
            array(
                array('a'),
                array(
                    'Users'       => array('a'),
                    'description' => 'test'
                ),
                array(
                    array('name', 'isEmpty', "Name is required and can't be empty.")
                ),
            ),
            array(
                array('a'),
                array(
                    'name'  => ' ',
                    'Users' => array('a')
                ),
                array(
                    array('name', 'isEmpty', "Name is required and can't be empty.")
                ),
            ),
            array(
                array('a'),
                array(
                    'name' => 'foo',
                ),
                array(
                    array('Users', 'callbackValue', 'Group must have at least one owner, user or subgroup.')
                )
            ),
            array(
                array('a'),
                array(
                    'name'  => 'foo',
                    'Users' => ''
                ),
                array(
                    array('Users', 'callbackValue', 'Group must have at least one owner, user or subgroup.')
                )
            ),
            array(
                array('a'),
                array(
                    'name'  => 'foo',
                    'Users' => array(
                        'foo' => array()
                    )
                ),
                array(
                    array('Users', 'notFlat', 'Array values must not be arrays or objects.')
                )
            ),
            array(
                array('a'),
                array(
                    'name'   => 'foo',
                    'Users'  => array('a'),
                    'Owners' => array(
                        'foo' => array()
                    )
                ),
                array(
                    array('Owners', 'notFlat', 'Array values must not be arrays or objects.')
                )
            ),
        );
    }

    public function testEditAction()
    {
        // create few users to test with and prepare their connections
        $p4User  = $this->connectWithAccess('foo-user',   array('//...' => 'list'));
        $p4Owner = $this->connectWithAccess('foo-owner',  array('//...' => 'list'));
        $p4Admin = $this->connectWithAccess('foo-admin',  array('//...' => 'admin'));

        // create group to test with
        $group = new Group($this->superP4);
        $group->setId('grp')
              ->setOwners(array('foo-owner'))
              ->setUsers(array('foo-user'))
              ->save();

        // try to edit as non-super and non-owner, should not work
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Admin);
        $this->dispatch('/group/edit/grp');
        $this->assertRoute('edit-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(403);
        $this->assertQueryContentContains('.error-exceptions', 'This operation is limited to project or group owners');

        $this->getApplication()->getServiceManager()->setService('p4_user', $p4User);
        $this->dispatch('/group/edit/grp');
        $this->assertRoute('edit-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(403);
        $this->assertQueryContentContains('.error-exceptions', 'This operation is limited to project or group owners');

        // try to edit as group owner, should work
        $this->resetApplication();
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Owner);
        $this->dispatch('/group/edit/grp');
        $this->assertRoute('edit-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);

        // try to edit as super, should work
        $this->resetApplication();
        $this->getApplication()->getServiceManager()->setService('p4_user', $this->superP4);
        $this->dispatch('/group/edit/grp');
        $this->assertRoute('edit-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
    }

    public function testEditNameAction()
    {
        $group = new Group($this->superP4);
        $group->setId('grp')
              ->setOwners(array('tester'))
              ->save();
        $group->getConfig()->setName('foo')->save();

        $postData = new Parameters(
            array(
                'name'   => 'bar',
                'Owners' => array('tester')
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // dispatch and check output
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $this->superP4);

        $this->dispatch('/groups/edit/grp');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('edit-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
        $this->assertTrue($result->getVariable('isValid'));

        // ensure new group has not been created
        $this->assertTrue(Group::exists('grp'));
        $this->assertFalse(Group::exists('grp-changed'));

        // ensure name has been updated in config
        $config = Group::fetch('grp', $this->p4)->getConfig();
        $this->assertSame('bar', $config->getName());
    }

    public function testEditProjectGroup()
    {
        // skip this test if we are on old server where project members are not stored in groups
        if ($this->needsSuper()) {
            $this->markTestSkipped('Project doesn\'t store members in groups, skipping.');
        }

        // ensure that project groups cannot be edited in Swarm
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'      => 'prj',
                'name'    => 'prj 1',
                'members' => array('a', 'bar')
            )
        )->save();

        $this->dispatch('/groups/edit/swarm-project-prj');
        $this->assertRoute('edit-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test the group action with an non-existant group.
     */
    public function testGroupActionNotExist()
    {
        $this->dispatch('/groups/not-exist');
        $this->assertRoute('group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'group');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test the group action with a valid group.
     */
    public function testGroupActionValid()
    {
        $group = new Group($this->superP4);
        $group->getConfig()->setName('group 1');
        $group->setId('g1')
              ->setOwners(array('tester'))
              ->save();

        $this->dispatch('/groups/g1');
        $this->assertRoute('group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'group');
        $this->assertResponseStatusCode(200);
        $this->assertQueryContentContains('.group-navbar .brand', 'group 1');
    }

    /**
     * Test delete action with invalid method.
     */
    public function testDeleteActionInvalidMethod()
    {
        $group = new Group($this->superP4);
        $group->setId('foo')
              ->setOwners(array('tester'))
              ->save();

        // try to delete team via get
        $this->dispatch('/groups/delete/foo');

        // check output
        $this->assertRoute('delete-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'delete');
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $this->getResult());
        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);
        $this->assertFalse($data['isValid']);
        $this->assertSame('Invalid request method. HTTP POST or HTTP DELETE required.', $data['error']);

        // ensure group was not deleted
        $this->assertTrue(Group::exists('foo', $this->p4));
    }

    /**
     * Test delete action with invalid group.
     */
    public function testDeleteActionInvalidId()
    {
        // try to delete non-existing group
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST);

        // dispatch and check output
        $this->dispatch('/groups/delete/foo');
        $this->assertRoute('delete-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'delete');
        $this->assertResponseStatusCode(404);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $this->getResult());
        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);
        $this->assertFalse($data['isValid']);
        $this->assertSame('Cannot delete group: group not found.', $data['error']);
    }

    /**
     * Test delete action with insufficient/sufficient permissions.
     */
    public function testDeleteActionNoPermissions()
    {
        $group = new Group($this->superP4);
        $group->setId('foo')
              ->setOwners(array('bar'))
              ->save();

        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST);

        $this->dispatch('/groups/delete/foo');
        $this->assertRoute('delete-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'delete');
        $this->assertResponseStatusCode(403);
        $this->assertTrue(Group::exists('foo', $this->p4));
        $this->resetApplication();
    }

    /**
     * Test delete action as group owner.
     */
    public function testDeleteActionAsOwner()
    {
        $p4Foo = $this->connectWithAccess('foo', array('//...' => 'list'));

        $group = new Group($this->superP4);
        $group->getConfig()->setName('group 1');
        $group->setId('g1')
              ->setOwners(array('foo'))
              ->save();

        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST);

        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $p4Foo);

        $this->dispatch('/groups/delete/g1');
        $this->assertRoute('delete-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'delete');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $this->getResult());
        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);
        $this->assertTrue($data['isValid']);
        $this->assertSame('g1', $data['id']);
        $this->assertFalse(Group::exists('g1', $this->p4));
        $this->assertFalse(GroupConfig::exists('g1', $this->p4));
    }

    /**
     * Test delete action as super user.
     */
    public function testDeleteActionAsSuper()
    {
        $group = new Group($this->superP4);
        $group->getConfig()->setName('group 1');
        $group->setId('g1')
              ->setUsers(array('nonadmin'))
              ->save();

        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST);

        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $this->superP4);

        $this->dispatch('/groups/delete/g1');
        $this->assertRoute('delete-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'delete');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $this->getResult());
        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);
        $this->assertTrue($data['isValid']);
        $this->assertSame('g1', $data['id']);
        $this->assertFalse(Group::exists('g1', $this->p4));
        $this->assertFalse(GroupConfig::exists('g1', $this->p4));
    }

    public function testDeleteProjectGroup()
    {
        // skip this test if we are on old server where project members are not stored in groups
        if ($this->needsSuper()) {
            $this->markTestSkipped('Project doesn\'t store members in groups, skipping.');
        }

        // ensure that project groups cannot be deleted in Swarm
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'      => 'prj',
                'name'    => 'prj 1',
                'members' => array('a', 'bar')
            )
        )->save();

        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST);

        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $this->superP4);

        $this->dispatch('/groups/delete/swarm-project-prj');
        $this->assertRoute('delete-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'delete');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test adding a group that has been previously deleted.
     */
    public function testAddActionDeletedGroup()
    {
        // create group and delete it
        $group = new Group($this->superP4);
        $group->setId('g1')
              ->setUsers(array('nonadmin'))
              ->save();
        Group::fetch('g1', $this->superP4)->delete();

        $this->p4->getService('cache')->invalidateItem('groups');
        $this->assertFalse(Group::exists('g1', $this->p4));

        // add group 'g1'
        $postData = new Parameters(array('name' => 'g1', 'Users' => array('tester')));
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // dispatch and check output
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $this->needsSuper() ? $this->superP4 : $services->get('p4_admin'));

        $this->dispatch('/groups/add');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('add-group');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);

        $this->assertTrue(Group::exists('g1', $this->p4));
    }

    public function testReviewsActionOnProjectGroup()
    {
        // skip this test if we are on old server where project members are not stored in groups
        if ($this->needsSuper()) {
            $this->markTestSkipped('Project doesn\'t store members in groups, skipping.');
        }

        // ensure that reviews specific page for project groups cannot be accessed in Swarm
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'      => 'prj',
                'name'    => 'prj 1',
                'members' => array('a', 'bar')
            )
        )->save();

        $this->dispatch('/groups/swarm-project-prj/reviews');
        $this->assertRoute('group-reviews');
        $this->assertRouteMatch('groups', 'groups\controller\indexcontroller', 'reviews');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Helper method - returns true if adding group requires super user (servers <2012.1)
     * otherwise returns false.
     *
     * @return  boolean     true if user needs to be a super to add groups, false otherwise
     */
    protected function needsSuper()
    {
        return !$this->p4->isServerMinVersion('2012.1');
    }
}
