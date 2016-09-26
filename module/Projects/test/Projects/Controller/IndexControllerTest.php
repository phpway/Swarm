<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ProjectsTest\Controller;

use Groups\Model\Group;
use ModuleTest\TestControllerCase;
use P4\Spec\User;
use Projects\Model\Project;
use Reviews\Model\Review;
use Zend\Stdlib\Parameters;

class IndexControllerTest extends TestControllerCase
{
    /**
     * Test project add action when admin permission is enforced.
     */
    public function testAddActionWithAdminRequired()
    {
        $services                                     = $this->getApplication()->getServiceManager();
        $config                                       = $services->get('config');
        $config['security']['add_project_admin_only'] = true;
        $services->setService('config', $config);

        $this->dispatch('/projects/add');

        $result = $this->getResult();
        $this->assertRoute('add-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(403);
    }

    /**
     * Test project add action when this ability is limited to specific groups.
     *
     * @dataProvider groupsDataProvider
     */
    public function testAddActionWithLimitedGroups($groups, $status, $createGroups = array())
    {
        // check with non-existent group
        $services                                 = $this->getApplication()->getServiceManager();
        $config                                   = $services->get('config');
        $config['security']['add_project_groups'] = $groups;
        $services->setService('config', $config);

        foreach ($createGroups as $group => $subGroup) {
            $newGroup = new Group($this->superP4);
            $newGroup->setId($group);

            if (strlen($subGroup)) {
                $newSubGroup = new Group($this->superP4);
                $newSubGroup->setId($subGroup)->setUsers(array('nonadmin'))->save();
                $newGroup->addSubgroup($newSubGroup)->save();
            } else {
                $newGroup->setUsers(array('nonadmin'))->save();
            }

            $newGroup->save();
        }

        $this->dispatch('/projects/add');
        $this->assertRoute('add-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode($status);
    }

    public function groupsDataProvider()
    {
        return array(
            array(array('foo'), 403),
            array(array(), 200),
            array(null, 200),
            array('', 200),
            array('foo', 403),
            array('foo', 200, array('foo' => '')),
            array(array('foo'), 200, array('foo' => '')),
            array(null, 200, array('foo' => '')),
            array('bar', 200, array('foo' => 'bar')), // member of subgroup
            array('baz', 403, array('foo' => 'bar'))  // non-member of subgroup
        );
    }

    /**
     * Test project add action with no parameters.
     */
    public function testAddActionNoParams()
    {
        $this->dispatch('/projects/add');

        $result = $this->getResult();
        $this->assertRoute('add-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $this->assertQueryContentContains('h1', 'Add Project');
        $this->assertQueryContentContains('form label', 'Name');
        $this->assertQueryContentContains('form label', 'Description');
        $this->assertQueryContentContains('form label', 'Owners');
        $this->assertQueryContentContains('form label', 'Members');
        $this->assertQueryContentContains('form label', 'Branches');
        $this->assertQueryContentContains('form label', 'Automated Tests');
        $this->assertQuery('form input[name="name"]');
        $this->assertQuery('form textarea[name="description"]');
        $this->assertQuery('form .control-group-owners input#owners');
        $this->assertQuery('form .control-group-members input#members');
    }

    /**
     * Test project add action.
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
        $this->dispatch('/projects/add');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('add-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);

        if ($messages) {
            $this->assertSame(false, $result->getVariable('isValid'));
            $responseMessages = $result->getVariable('messages');
            foreach ($messages as $message) {
                list($messageField, $messageValidator, $messageValue) = $message;
                $this->assertTrue(array_key_exists($messageField, $responseMessages));
                $this->assertSame($messageValue, $responseMessages[$messageField][$messageValidator]);
            }

            // ensure project was not created (only if name was set)
            if (isset($postData['name'])) {
                $this->assertFalse(Project::exists($postData['name'], $this->p4));
            }
        } else {
            // if no messages, check the project was saved and is dispatchable
            $name = $postData['name'];
            $this->assertSame(true, $result->getVariable('isValid'));
            $this->assertSame('/projects/' . $name, $result->getVariable('redirect'));
            $this->assertTrue(Project::exists($name, $this->p4));

            $this->resetApplication();
            $this->dispatch('/projects/' . $name);

            $this->assertRoute('project');
            $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'project');
            $this->assertResponseStatusCode(200);
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
                    'name'    => 'prj123',
                    'members' => array('foo', 'bar')
                ),
                array()
            ),
            array(
                array('foo', 'bar'),
                array(
                    'name'    => 'ドラゴン',
                    'members' => array('foo', 'bar'),
                    'branches'      => array(
                        array(
                            'name'  => '蝶',
                            'paths' => '//depot/foo/bar/baz'
                        )
                    ),
                ),
                array()
            ),

            // invalid params
            array(
                array('a'),
                array(
                    'members'     => array('a'),
                    'description' => 'test'
                ),
                array(
                    array('name', 'isEmpty', "Name is required and can't be empty.")
                ),
            ),
            array(
                array('x', 'xy', 'xxz'),
                array(
                    'name'          => 'prj789',
                    'members'       => array('x', 'xy', 'xxz'),
                    'owners'        => array('xy'),
                    'enableOwners'  => 1,
                    'description'   => 'foo desc',
                    'branches'      => array(
                        array(
                            'name'  => 'foo',
                            'paths' => '//depot/foo/bar/baz'
                        )
                    ),
                    'tests'         => array(
                        'enabled'   => true,
                        'script'    => '/x/y/z/script %a %b %c'
                    ),
                    'deploy'        => array(
                        'enabled'   => true,
                        'script'    => '/a/s/d/script %a %b %c'
                    )
                ),
                array(
                    array('tests', 'callbackValue', 'URL for tests must be provided if tests are enabled.'),
                    array('deploy', 'callbackValue', 'URL for deploy must be provided if deployment is enabled.')
                )
            ),
            array(
                array('a'),
                array(
                    'name'    => ' ',
                    'members' => array('a')
                ),
                array(
                    array('name', 'isEmpty', "Name is required and can't be empty.")
                ),
            ),
            array(
                array('a'),
                array(
                    'name'    => 'foo',
                ),
                array(
                    array('members', 'callbackValue', 'Project must have at least one member or subgroup.')
                )
            ),
            array(
                array('a'),
                array(
                    'name'    => 'foo',
                    'members' => ''
                ),
                array(
                    array('members', 'callbackValue', 'Project must have at least one member or subgroup.')
                )
            ),
            array(
                array('a'),
                array(
                    'name'    => 'foo',
                    'members' => array('madeup')
                ),
                array(
                    array('members', 'unknownIds', 'Unknown user id(s): madeup')
                )
            ),
            array(
                array('a'),
                array(
                    'name'    => 'foo',
                    'members' => array('madeup', 'notmadeupbutreallymadeup')
                ),
                array(
                    array('members', 'unknownIds', 'Unknown user id(s): madeup, notmadeupbutreallymadeup')
                )
            ),
            array(
                array('a'),
                array(
                    'name'    => 'foo',
                    'members' => array(
                        'foo' => array()
                    )
                ),
                array(
                    array('members', 'notFlat', 'Array values must not be arrays or objects.')
                )
            ),
            array(
                array('a'),
                array(
                    'name'    => 'foo',
                    'members' => array('a'),
                    'owners'  => array('madeup')
                ),
                array(
                    array('owners', 'unknownIds', 'Unknown user id(s): madeup')
                )
            ),
            array(
                array('a'),
                array(
                    'name'    => 'foo',
                    'members' => array('a'),
                    'owners'  => array(
                        'foo' => array()
                    )
                ),
                array(
                    array('owners', 'notFlat', 'Array values must not be arrays or objects.')
                )
            ),
            array(
                array('a', 'b'),
                array(
                    'name'    => 'foo',
                    'members' => array('a'),
                    'owners'  => array('b'),
                    'branches' => array(
                        array(
                            'name'  => 'Test',
                            'paths' => ' '
                        )
                    )
                ),
                array(
                    array('branches', 'callbackValue', "Error in 'Test' branch: No depot paths specified.")
                )
            ),
        );
    }

    /**
     * Test adding a project that has been previously deleted.
     */
    public function testAddActionDeletedProject()
    {
        // create deleted project
        $project = new Project($this->p4);
        $project->set(array('id' => 'foo', 'deleted' => true))->save();

        // create user to add in project members
        $user = new User;
        $user->setId('bar')->set('FullName', 'bar')->set('Email', 'test@host')->save();

        $postData = new Parameters(
            array(
                'name'        => 'foo',
                'members'     => array('bar'),
                'deleted'     => false
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/projects/add');
        $this->assertRoute('add-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $this->getResult());

        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);

        $this->assertFalse($data['isValid']);
        $this->assertSame(
            'This name is taken. Please pick a different name.',
            $data['messages']['name']['callbackValue']
        );

        // verify that project is still deleted
        $this->assertFalse(Project::exists('foo', $this->p4));

        // verify that project page can't be accessed
        $this->resetApplication();
        $this->dispatch('/projects/foo');

        $this->assertRoute('project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'project');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test the project action with an non-existant project.
     */
    public function testProjectActionNotExist()
    {
        $this->dispatch('/projects/not-exist');

        $this->assertRoute('project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'project');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test the project action with a valid project.
     */
    public function testProjectActionValid()
    {
        // add a project
        $projectData = array(
            'id'      => 'prjtest',
            'name'    => 'prjtest',
            'members' => array('foo', 'bar', 'xyz')
        );
        $project     = new Project($this->p4);
        $project->set($projectData)->save();

        $this->dispatch('/projects/prjtest');

        $this->assertRoute('project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'project');
        $this->assertResponseStatusCode(200);
        $this->assertQueryContentContains('.project-navbar .brand', 'prjtest');
    }

    /**
     * Test reviews action in json context.
     */
    public function testReviewsActionJson()
    {
        // verify result for non-existing project
        $this->getRequest()->setQuery(new Parameters(array('format' => 'json')));
        $result = $this->dispatch('/projects/not-exists/reviews');

        $result = $this->getResult();
        $this->assertRoute('project-reviews');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'reviews');
        $this->assertResponseStatusCode(404);

        // insert several projects and review records and try again
        $model = new Project($this->p4);
        $model->set(array('id' => 'foo'))->save();
        $model = new Project($this->p4);
        $model->set(array('id' => 'bar'))->save();

        $model = new Review($this->p4);
        $model->set(
            array(
                'id'            => 1,
                'author'        => 'test',
                'projects'      => array('foo', 'a', 'b')
            )
        )->save();
        $model = new Review($this->p4);
        $model->set(
            array(
                'id'            => 2,
                'author'        => 'test',
                'projects'      => array('a', 'b')
            )
        )->save();
        $model = new Review($this->p4);
        $model->set(
            array(
                'id'            => 3,
                'author'        => 'test',
                'projects'      => array('a', 'b', 'bar')
            )
        )->save();
        $model = new Review($this->p4);
        $model->set(
            array(
                'id'            => 4,
                'author'        => 'test',
                'projects'      => array('foo', 'bar', 'x')
            )
        )->save();
        $model = new Review($this->p4);
        $model->set(
            array(
                'id'            => 5,
                'author'        => 'foo',
                'projects'      => array('foo1', 'bar' => array('foo'))
            )
        )->save();

        $this->resetApplication();
        $this->getRequest()->setQuery(new Parameters(array('format' => 'json')));
        $result = $this->dispatch('/projects/foo/reviews');

        $result = $this->getResult();
        $this->assertRoute('project-reviews');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'reviews');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);

        $this->assertSame(2, count($data['reviews']));
        $this->assertSame(4, $data['reviews'][0]['id']);
        $this->assertSame(1, $data['reviews'][1]['id']);
    }

    /**
     * Test delete action with invalid method.
     */
    public function testDeleteActionInvalidMethod()
    {
        // create project
        $project = new Project($this->p4);
        $project->set(array('id' => 'foo'))->save();

        // try to delete project via get
        $this->dispatch('/projects/delete/foo');

        // check output
        $this->assertRoute('delete-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'delete');
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $this->getResult());

        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);

        $this->assertFalse($data['isValid']);
        $this->assertSame('Invalid request method. HTTP POST or HTTP DELETE required.', $data['error']);

        // ensure project was not deleted
        $this->assertTrue(Project::exists('foo', $this->p4));
    }

    /**
     * Test delete action with invalid project.
     */
    public function testDeleteActionInvalidId()
    {
        // try to delete non-existing project
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST);

        // dispatch and check output
        $this->dispatch('/projects/delete/foo');

        $this->assertRoute('delete-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'delete');
        $this->assertResponseStatusCode(404);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $this->getResult());

        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);

        $this->assertFalse($data['isValid']);
        $this->assertSame('Cannot delete project: project not found.', $data['error']);
    }

    /**
     * Test delete action with insufficient/sufficient permissions.
     */
    public function testDeleteActionPermissions()
    {
        // create project
        $project = new Project($this->p4);
        $project->set(array('id' => 'foo'))->save();

        // first try it with not enough permissions
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST);
        $this->dispatch('/projects/delete/foo');

        $this->assertRoute('delete-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'delete');
        $this->assertResponseStatusCode(403);
        $this->assertTrue(Project::exists('foo', $this->p4));

        $this->resetApplication();

        // try again as admin
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $services->get('p4_admin'));

        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST);
        $this->dispatch('/projects/delete/foo');

        $this->assertRoute('delete-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'delete');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $this->getResult());

        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);

        $this->assertTrue($data['isValid']);
        $this->assertSame('foo', $data['id']);

        // verify that project was deleted
        $this->assertFalse(Project::exists('foo', $this->p4));

        // verify that project record is still there
        $record = Project::fetchAll(
            array(
                Project::FETCH_INCLUDE_DELETED => true,
                Project::FETCH_BY_IDS          => array('foo')
            ),
            $this->p4
        );
        $this->assertSame(1,     $record->count());
        $this->assertSame('foo', $record->first()->getId());
        $this->assertSame(true,  $record->first()->get('deleted'));

        // verify that project page can't be accessed
        $this->resetApplication();
        $this->dispatch('/projects/foo');

        $this->assertRoute('project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'project');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test member cache invalidation
     */
    public function testCacheInvalidation()
    {
        // no member caching if server doesn't support admin groups.
        if (!$this->p4->isServerMinVersion('2012.1')) {
            $this->markTestSkipped('No member caching, server too old.');
        }

        $services = $this->getApplication()->getServiceManager();
        $cache    = $services->get('p4')->getService('cache');
        $queue    = $services->get('queue');

        // make a project to test with
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'        => 'p1',
                'members'   => array('x', 'y')
            )
        )->save();

        // populate cache
        $project = Project::fetch('p1', $this->p4);
        $members = $project->getMembers();
        $this->assertSame(array('x', 'y'), $members);
        $groups = $cache->getItem('groups');
        $this->assertTrue(isset($groups['swarm-project-p1']['Users']));
        $this->assertSame(array('x', 'y'), $groups['swarm-project-p1']['Users']);

        // verify cache is invalidated if groups are edited
        $group = Group::fetch('swarm-project-p1', $this->superP4);
        $group->setUsers(array('a', 'b', 'c'))->save();

        // normally this task is added by the group trigger
        $queue->addTask('group', 'swarm-project-p1');

        // process queue (this should invalidate user cache)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $this->assertNull($cache->getItem('members'));

        // populate cache again and verify result
        $project = Project::fetch('p1', $this->p4);
        $members = $project->getMembers();
        $this->assertSame(array('a', 'b', 'c'), $members);
        $groups = $cache->getItem('groups');
        $this->assertTrue(isset($groups['swarm-project-p1']['Users']));
        $this->assertSame(array('a', 'b', 'c'), $groups['swarm-project-p1']['Users']);

        // ensure editing a group invalidates cache
        $queue->addTask('group', 'other-stuff-project-p1');
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
        $this->assertSame(null, $cache->getItem('groups'));
    }

    public function testEditAction()
    {
        // create few users to test with and prepare their connections
        $p4Member = $this->connectWithAccess('foo-member', array('//...' => 'list'));
        $p4Owner  = $this->connectWithAccess('foo-owner',  array('//...' => 'list'));

        // create project to test with
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'      => 'prj',
                'members' => array('foo-member'),
                'owners'  => array()
            )
        )->save();

        // try to edit as non-member while owners are not enabled and verify it doesn't work
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Owner);
        $this->dispatch('/project/edit/prj');
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(403);
        $this->assertQueryContentContains('.error-exceptions', 'This operation is limited to project members');

        // try to edit as member and verify it works
        $this->resetApplication();
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Member);
        $this->dispatch('/project/edit/prj');
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);

        // add owner
        $project->set('owners', array('foo-owner'))->save();

        // verify that owner can edit the project
        $this->resetApplication();
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Owner);
        $this->dispatch('/project/edit/prj');
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);

        // if owners are enabled, non-owner/admin members can no longer edit the project
        $this->resetApplication();
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Member);
        $this->dispatch('/project/edit/prj');
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(403);
        $this->assertQueryContentContains('.error-exceptions', 'This operation is limited to project or group owners');

        // edit as admin when owners are enabled should also work
        $this->resetApplication();
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $services->get('p4_admin'));
        $this->dispatch('/project/edit/prj');
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
    }

    public function testEditNameAction()
    {
        // create couple of users (admin/non-admin) to test with and prepare their connections
        $p4Member = $this->connectWithAccess('foo-member', array('//...' => 'list'));
        $p4Admin  = $this->connectWithAccess('foo-admin',  array('//...' => 'admin'));

        // create project to test with
        $members = array('foo-member', 'foo-admin');
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'      => 'prj',
                'name'    => 'prj-name',
                'members' => $members,
                'owners'  => array()
            )
        )->save();

        // Case 1: try to edit project name as non-admin when editing name is not restricted (default)
        $postData = new Parameters(
            array(
                'name'    => 'prj-name-changed',
                'members' => $members
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // dispatch and check output
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Member);
        $this->dispatch('/projects/edit/prj');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
        $this->assertTrue($result->getVariable('isValid'));
        $this->assertSame('prj-name-changed', Project::fetch('prj', $this->p4)->getName());

        // Case 2: try to edit project name as non-admin when editing the 'name' is restricted (should fail)
        $this->resetApplication();
        $postData = new Parameters(
            array(
                'name'    => 'prj-name-1',
                'members' => $members
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // deny project name editing in config
        $services                                   = $this->getApplication()->getServiceManager();
        $config                                     = $services->get('config');
        $config['projects']['edit_name_admin_only'] = true;
        $services->setService('config', $config);

        // dispatch and check output
        $services->setService('p4_user', $p4Member);
        $this->dispatch('/projects/edit/prj');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
        $this->assertSame('prj-name-changed', Project::fetch('prj', $this->p4)->getName());

        // verify we got an error
        $this->assertSame(false, $result->getVariable('isValid'));
        $responseMessages = $result->getVariable('messages');
        $this->assertSame('Value is not allowed.', $responseMessages['name']['callbackValue']);

        // Case 3: try again as admin (should succeed)
        $this->resetApplication();
        $postData = new Parameters(
            array(
                'name'    => 'prj-name-2',
                'members' => $members
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // deny project name editing in config
        $services                                   = $this->getApplication()->getServiceManager();
        $config                                     = $services->get('config');
        $config['projects']['edit_name_admin_only'] = true;
        $services->setService('config', $config);

        // dispatch and check output
        $services->setService('p4_user', $p4Admin);
        $this->dispatch('/projects/edit/prj');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
        $this->assertTrue($result->getVariable('isValid'));
        $this->assertSame('prj-name-2', Project::fetch('prj', $this->p4)->getName());

        // Case 4: verify that successful editing of a project as non-admin when editing
        // the 'name' field is not allowed will not alter the project name
        $this->resetApplication();
        $postData = new Parameters(
            array(
                'members' => $members
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // deny project name editing in config
        $services                                   = $this->getApplication()->getServiceManager();
        $config                                     = $services->get('config');
        $config['projects']['edit_name_admin_only'] = true;
        $services->setService('config', $config);

        // dispatch and check output
        $services->setService('p4_user', $p4Member);
        $this->dispatch('/projects/edit/prj');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
        $this->assertTrue($result->getVariable('isValid'));
        $this->assertSame('prj-name-2', Project::fetch('prj', $this->p4)->getName());
    }

    public function testEditBranchesAction()
    {
        // create couple of users (admin/non-admin) to test with and prepare their connections
        $p4Member = $this->connectWithAccess('foo-member', array('//...' => 'list'));
        $p4Admin  = $this->connectWithAccess('foo-admin',  array('//...' => 'admin'));

        // create project to test with
        $members = array('foo-member', 'foo-admin');
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'       => 'prj',
                'name'     => 'prj-name',
                'members'  => $members,
                'owners'   => array(),
                'branches' => array(
                    array(
                        'id'    => 'branch1',
                        'name'  => 'b1',
                        'paths' => '//branch-1/...'
                    )
                )
            )
        )->save();

        // Case 1: try to edit project branches as non-admin when editing is not restricted (default)
        $postData = new Parameters(
            array(
                'name'    => 'prj-name',
                'members' => $members,
                'branches' => array(
                    array(
                        'id'    => 'branch1',
                        'name'  => 'foo',
                        'paths' => '//depot/foo/...'
                    ),
                    array(
                        'id'    => 'branch2',
                        'name'  => 'bar',
                        'paths' => '//depot/bar/...'
                    ),
                )
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // dispatch and check output
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Member);
        $this->dispatch('/projects/edit/prj');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
        $this->assertTrue($result->getVariable('isValid'));

        $branches = Project::fetch('prj', $this->p4)->getBranches('id');
        $this->assertSame(2, count($branches));
        $this->assertSame('branch1',                $branches[0]['id']);
        $this->assertSame('foo',                    $branches[0]['name']);
        $this->assertSame(array('//depot/foo/...'), $branches[0]['paths']);
        $this->assertSame('branch2',                $branches[1]['id']);
        $this->assertSame('bar',                    $branches[1]['name']);
        $this->assertSame(array('//depot/bar/...'), $branches[1]['paths']);

        // Case 2: try to edit project branches as non-admin when editing is restricted (should fail)
        $this->resetApplication();
        $postData = new Parameters(
            array(
                'name'    => 'prj-name',
                'members' => $members,
                'branches' => array(
                    array(
                        'id'    => 'branch1',
                        'name'  => 'test',
                        'paths' => '//depot/test/...'
                    ),
                )
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // deny project branches editing in config
        $services                                       = $this->getApplication()->getServiceManager();
        $config                                         = $services->get('config');
        $config['projects']['edit_branches_admin_only'] = true;
        $services->setService('config', $config);

        // dispatch and check output
        $services->setService('p4_user', $p4Member);
        $this->dispatch('/projects/edit/prj');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);

        $branches = Project::fetch('prj', $this->p4)->getBranches('id');
        $this->assertSame(2, count($branches));
        $this->assertSame('branch1',                $branches[0]['id']);
        $this->assertSame('foo',                    $branches[0]['name']);
        $this->assertSame(array('//depot/foo/...'), $branches[0]['paths']);
        $this->assertSame('branch2',                $branches[1]['id']);
        $this->assertSame('bar',                    $branches[1]['name']);
        $this->assertSame(array('//depot/bar/...'), $branches[1]['paths']);

        // verify we got an error
        $this->assertSame(false, $result->getVariable('isValid'));
        $responseMessages = $result->getVariable('messages');
        $this->assertSame('Value is not allowed.', $responseMessages['branches']['callbackValue']);

        // Case 3: try again as admin (should succeed)
        $this->resetApplication();
        $postData = new Parameters(
            array(
                'name'    => 'prj-name',
                'members' => $members,
                'branches' => array(
                    array(
                        'id'    => 'branch1',
                        'name'  => 'test',
                        'paths' => '//depot/test/...'
                    ),
                )
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // deny project branches editing in config
        $services                                       = $this->getApplication()->getServiceManager();
        $config                                         = $services->get('config');
        $config['projects']['edit_branches_admin_only'] = true;
        $services->setService('config', $config);

        // dispatch and check output
        $services->setService('p4_user', $p4Admin);
        $this->dispatch('/projects/edit/prj');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
        $this->assertTrue($result->getVariable('isValid'));

        $branches = Project::fetch('prj', $this->p4)->getBranches('id');
        $this->assertSame(1, count($branches));
        $this->assertSame('branch1',                 $branches[0]['id']);
        $this->assertSame('test',                    $branches[0]['name']);
        $this->assertSame(array('//depot/test/...'), $branches[0]['paths']);

        // Case 4: verify that successful editing of a project as non-admin when editing
        // the 'branches' field is not allowed will not alter the project branches
        $this->resetApplication();
        $postData = new Parameters(
            array(
                'name'    => 'prj-name',
                'members' => $members
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // deny project branches editing in config
        $services                                       = $this->getApplication()->getServiceManager();
        $config                                         = $services->get('config');
        $config['projects']['edit_branches_admin_only'] = true;
        $services->setService('config', $config);

        // dispatch and check output
        $services->setService('p4_user', $p4Member);
        $this->dispatch('/projects/edit/prj');
        $result = $this->getResult();

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
        $this->assertTrue($result->getVariable('isValid'));

        $branches = Project::fetch('prj', $this->p4)->getBranches('id');
        $this->assertSame(1, count($branches));
        $this->assertSame('branch1',                 $branches[0]['id']);
        $this->assertSame('test',                    $branches[0]['name']);
        $this->assertSame(array('//depot/test/...'), $branches[0]['paths']);
    }

    public function testPrivateProject()
    {
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'       => 'secret',
                'name'     => 'secret project',
                'members'  => array('foo', 'x'),
                'private'  => true
            )
        )->save();

        // try to access project as non-member, should fail
        $this->dispatch('/projects/secret');
        $this->assertRoute('project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'project');
        $this->assertResponseStatusCode(404);

        // try to edit a project as non-member, should also fail
        $this->resetApplication();
        $this->dispatch('/projects/edit/secret');
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(404);

        // try to access project as a member, should work
        $this->resetApplication();
        $p4Foo = $this->connectWithAccess('foo', array('//...' => 'read'));
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Foo);
        $this->dispatch('/projects/secret');
        $this->assertRoute('project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'project');
        $this->assertResponseStatusCode(200);

        // try to edit a project as a member, should also work
        $this->resetApplication();
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Foo);
        $this->dispatch('/projects/edit/secret');
        $this->assertRoute('edit-project');
        $this->assertRouteMatch('projects', 'projects\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
    }

    public function testProjectsAction()
    {
        // prepare connctions we will test against
        $p4Foo = $this->connectWithAccess('foo', array('//...' => 'read'));
        $p4Bar = $this->connectWithAccess('bar', array('//...' => 'read'));
        $p4Baz = $this->connectWithAccess('baz', array('//...' => 'read'));

        // create few projects to test with
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'       => 'p1',
                'name'     => 'prj 1',
                'members'  => array('foo', 'bar')
            )
        )->save();

        $project = new Project($this->p4);
        $project->set(
            array(
                'id'       => 'p2',
                'name'     => 'prj 2',
                'members'  => array('foo', 'baz'),
                'private'  => true
            )
        )->save();

        $project = new Project($this->p4);
        $project->set(
            array(
                'id'       => 'p3',
                'name'     => 'prj 3',
                'members'  => array('bar'),
                'owners'   => array('baz'),
                'private'  => true
            )
        )->save();

        // verify that foo can see only p1, p2
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Foo);
        $this->dispatch('/projects');

        $projects = array();
        foreach ($this->getResult()->getVariables() as $project) {
            $projects[] = $project['id'];
        }
        sort($projects);
        $this->assertSame(array('p1', 'p2'), $projects);

        // verify that bar can see only p1, p3
        $this->resetApplication();
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Bar);
        $this->dispatch('/projects');

        $projects = array();
        foreach ($this->getResult()->getVariables() as $project) {
            $projects[] = $project['id'];
        }
        sort($projects);
        $this->assertSame(array('p1', 'p3'), $projects);

        // verify that baz can see p1, p2, p3
        $this->resetApplication();
        $this->getApplication()->getServiceManager()->setService('p4_user', $p4Baz);
        $this->dispatch('/projects');

        $projects = array();
        foreach ($this->getResult()->getVariables() as $project) {
            $projects[] = $project['id'];
        }
        sort($projects);
        $this->assertSame(array('p1', 'p2', 'p3'), $projects);
    }
}
