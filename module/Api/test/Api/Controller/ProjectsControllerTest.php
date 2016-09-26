<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApiTest\Controller;

use ModuleTest\TestApiController;
use P4\Spec\User;
use Projects\Model\Project;
use Zend\Http\Request;
use Zend\Json\Json;
use Zend\Stdlib\Parameters;

class ProjectsControllerTest extends TestApiController
{
    public function testProjectList()
    {
        // prepare some users for our project
        $user = new User;
        $user->setId('foo')->set('FullName', 'foo')->set('Email', 'test@host')->save();
        $user = new User;
        $user->setId('bar')->set('FullName', 'bar')->set('Email', 'test@host')->save();

        // grab the current projects list and confirm it is empty
        $this->dispatch('/api/v1/projects');
        $body     = $this->getResponse()->getBody();
        $actual   = Json::decode($body, true);
        $expected = array('projects' => array());

        $this->assertResponseStatusCode(200);
        $this->assertEquals($expected, $actual);
        $this->resetApplication();

        // create a project using the regular route (rather than posting to API, not yet implemented)
        $postData = new Parameters(
            array(
                'description' => 'My Project',
                'name'        => 'prj123',
                'members'     => array('bar', 'foo')
            )
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        $this->dispatch('/projects/add');
        $this->resetApplication();

        // fetch the projects list again (this time it shouldn't be empty)
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_GET);

        $this->dispatch('/api/v1/projects');

        $body     = $this->getResponse()->getBody();
        $actual   = Json::decode($body, true);
        $expected = array(
            'projects' => array(
                array(
                    'id'            => 'prj123',
                    'branches'      => array(),
                    'deleted'       => false,
                    'description'   => 'My Project',
                    'emailFlags'    => array(),
                    'followers'     => array(),
                    'jobview'       => null,
                    'members'       => array('bar', 'foo'),
                    'name'          => 'prj123',
                    'owners'        => array(),
                    'subgroups'     => array(),
                )
            )
        );

        $this->assertResponseStatusCode(200);
        $this->assertSame($expected, $actual);
    }

    public function testProjectsListAdvanced()
    {
        // prepare some users for our project
        $user = new User;
        $user->setId('foo')->set('FullName', 'foo')->set('Email', 'test@host')->save();
        $user = new User;
        $user->setId('bar')->set('FullName', 'bar')->set('Email', 'test@host')->save();

        // create project to test with
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'          => 'prj123',
                'name'        => 'Project 123',
                'description' => 'My Project',
                'emailFlags'  => array(),
                'members'     => array('bar', 'foo'),
                'owners'      => array('foo'),
                'branches'    => array(
                    array(
                        'id'         => 'test',
                        'name'       => 'Test',
                        'paths'      => array('//depot/prj123/test/...'),
                        'moderators' => array('alice', 'bob'),
                    )
                )
            )
        )->save();

        // fetch the projects list again (this time it shouldn't be empty)
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_GET);

        $this->dispatch('/api/v1/projects');

        $body     = $this->getResponse()->getBody();
        $actual   = Json::decode($body, true);
        $expected = array(
            'projects' => array(
                array(
                    'id'            => 'prj123',
                    'branches'      => array(
                        array(
                            'id'         => 'test',
                            'name'       => 'Test',
                            'paths'      => array('//depot/prj123/test/...'),
                            'moderators' => array('alice', 'bob'),
                        )
                    ),
                    'deleted'       => false,
                    'description'   => 'My Project',
                    'emailFlags'    => array(),
                    'followers'     => array(),
                    'jobview'       => null,
                    'members'       => array('bar', 'foo'),
                    'name'          => 'Project 123',
                    'owners'        => array('foo'),
                    'subgroups'     => array(),
                )
            )
        );

        $this->assertResponseStatusCode(200);
        $this->assertSame($expected, $actual);
    }

    public function testProjectLimitFields()
    {
        // create a project using the regular route (rather than posting to API, not yet implemented)
        $postData = new Parameters(
            array(
                'description' => 'My Project',
                'name'        => 'prj123',
                'members'     => array('admin')
            )
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        $this->dispatch('/projects/add');
        $this->resetApplication();

        // fetch the projects list again (this time it shouldn't be empty)
        $this->get('/api/v1/projects?fields=id,description,name');
        $body     = $this->getResponse()->getBody();
        $actual   = Json::decode($body, true);
        $expected = array(
            'projects' => array(
                array(
                    'id'          => 'prj123',
                    'description' => 'My Project',
                    'name'        => 'prj123',
                )
            )
        );

        $this->assertResponseStatusCode(200);
        $this->assertSame($expected, $actual);

        // fetch the project individually
        $this->get('/api/v2/projects/prj123?fields=id,description,name');
        $body     = $this->getResponse()->getBody();
        $actual   = Json::decode($body, true);
        $expected = array(
            'project' => array(
                'id'          => 'prj123',
                'description' => 'My Project',
                'name'        => 'prj123',
            )
        );

        $this->assertResponseStatusCode(200);
        $this->assertSame($expected, $actual);
    }

    /**
     * @param $admin
     * @dataProvider enableAdminProvider
     */
    public function testGetProject($admin)
    {
        // prepare some users for our project
        $user = new User;
        $user->setId('foo')->set('FullName', 'foo')->set('Email', 'test@host')->save();
        $user = new User;
        $user->setId('bar')->set('FullName', 'bar')->set('Email', 'test@host')->save();

        // create a project using the API
        $postData = new Parameters(
            array(
                'description' => 'My Project',
                'name'        => 'prj123',
                'members'     => array('bar', 'foo')
            )
        );

        $result = $this->post('/api/v2/projects/', $postData);
        $actual = json_decode($result->getBody(), true);
        $this->assertSame('prj123', $actual['project']['id']);
        $this->assertResponseStatusCode(200);

        $this->get('/api/v2/projects/prj123', null, $admin);

        $body     = $this->getResponse()->getBody();
        $actual   = Json::decode($body, true);
        $expected = array(
            'project' => array(
                'id'          => 'prj123',
                'branches'    => array(),
                'deleted'     => false,
                'description' => 'My Project',
                'emailFlags'  => array(),
                'jobview'     => null,
                'members'     => array('bar', 'foo'),
                'name'        => 'prj123',
                'owners'      => array(),
                'subgroups'   => array(),
            )
        );

        $this->assertResponseStatusCode(200);

        if ($admin) {
            $this->assertSame(array('enabled' => false, 'url' => null), $actual['project']['deploy']);
            $this->assertSame(array('enabled' => false, 'url' => null), $actual['project']['tests']);
            unset($actual['project']['deploy'], $actual['project']['tests']);
        }

        $this->assertSame($expected, $actual);
    }

    /**
     * @dataProvider projectProvider
     */
    public function testCreateProject($data, $expected, $status = 200)
    {
        $result = $this->post('/api/v2/projects/', $data);
        $actual = json_decode($result->getBody(), true);
        $this->assertSame($expected, $actual);
        $this->assertResponseStatusCode($status);
    }

    public function projectProvider()
    {
        /*
         * Each provided test should have a name as its key (this will make debugging easier).
         *
         * data:     the payload to sent to the project creation endpoint
         * expected: the project fields/values to verify edit success
         * status:   optional HTTP status code for success/failure (defaults to 200)
         */
        return array(
            'simple_project' => array(
                'data'     => array('name' => 'Ecto One', 'members' => array('nonadmin')),
                'expected' => array(
                    'project' => array(
                        'id'          => 'ecto-one',
                        'branches'    => array(),
                        'deleted'     => false,
                        'deploy'      => array(
                            'enabled'    => false,
                            'url'        => null,
                        ),
                        'description' => null,
                        'emailFlags'  => array(),
                        'jobview'     => null,
                        'members'     => array(
                            0 => 'nonadmin',
                        ),
                        'name'        => 'Ecto One',
                        'owners'      => array(),
                        'subgroups'   => array(),
                        'tests'       => array(
                            'enabled'    => false,
                            'url'        => null,
                        ),
                    ),
                ),
            ),
            'error-members-missing' => array(
                'data'     => array('name' => 'Ecto One'),
                'expected' => array(
                    'error'   => 'Bad Request',
                    'details' => array('members' => 'Project must have at least one member or subgroup.')
                ),
                'status'   => 400
            ),
            'error-bad-members' => array(
                'data'     => array('name' => 'Ecto One', 'members' => 'nonadmin'),
                'expected' => array(
                    'error'   => 'Bad Request',
                    'details' => array('members' => 'Invalid type given. Array required.')
                ),
                'status'   => 400
            ),
            'error-empty-members-and-subgroups' => array(
                'data'     => array('name' => 'Ecto One', 'members' => '', 'subgroups' => ''),
                'expected' => array(
                    'error'   => 'Bad Request',
                    'details' => array('members' => 'Project must have at least one member or subgroup.')
                ),
                'status'   => 400
            ),
            'error-bad-branches' => array(
                'data'     => array('name' => 'Ecto One', 'members' => array('nonadmin'), 'branches' => 'dfgsdfg'),
                'expected' => array(
                    'error'   => 'Bad Request',
                    'details' => array('branches' => 'Branches must be passed as an array.')
                ),
                'status'   => 400
            ),
            'error-bad-branch' => array(
                'data'     => array('name' => 'Ecto One', 'members' => array('nonadmin'), 'branches' => array('bad')),
                'expected' => array(
                    'error'   => 'Bad Request',
                    'details' => array('branches' => 'All branches must be in array form.')
                ),
                'status'   => 400
            ),
            'the-whole-world' => array(
                'data'     => array(
                    'name'        => 'Ecto One',
                    'members'     => array('nonadmin'),
                    'description' => 'We got one!',
                    'deploy' => array(
                        'enabled' => true,
                        'url' => 'http://localhost/?change={change}'
                    ),
                    'branches' => array(
                        array(
                            'name'       => 'Egon',
                            'paths'      => array('//depot/egon/main/...'),
                            'moderators' => array('admin')
                        )
                    ),
                    'emailFlags' => array('review_email_project_members' => true, 'change_email_project_users' => true),
                    'owners' => array('admin'),
                    'tests' => array('enabled' => 'hells yeah', 'url' => 'http://localhost/?pass={pass}&fail={fail}'),
                    'jobview' => 'subsystem=swarm type=sir',
                ),
                'expected' => array(
                    'project' => array(
                        'id'          => 'ecto-one',
                        'branches'    => array(
                            array(
                                'name'       => 'Egon',
                                'paths'      => array(
                                    '//depot/egon/main/...'
                                ),
                                'moderators' => array('admin'),
                                'id'         => 'egon'
                            )
                        ),
                        'deleted'     => false,
                        'deploy'      => array(
                            'enabled' => true,
                            'url'     => 'http://localhost/?change={change}'
                        ),
                        'description' => 'We got one!',
                        'emailFlags'  => array(
                            'change_email_project_users'   => true,
                            'review_email_project_members' => true,
                        ),
                        'jobview'     => 'subsystem=swarm type=sir',
                        'members'     => array(
                            0 => 'nonadmin',
                        ),
                        'name'        => 'Ecto One',
                        'owners'      => array('admin'),
                        'subgroups'   => array(),
                        'tests'       => array(
                            'enabled'    => true,
                            'url'        => 'http://localhost/?pass={pass}&fail={fail}',
                            'postBody'   => null,
                            'postFormat' => 'URL',
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * @dataProvider projectEditProvider
     */
    public function testEditProject($initialData, $data, $expected, $status = 200)
    {
        $result = $this->post('/api/v2/projects/', $initialData);
        $actual = json_decode($result->getBody(), true);

        $result = $this->patch('/api/v2/projects/' . $actual['project']['id'], $data);
        $actual = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('project', $actual);
        $actual = array_intersect_key($actual['project'], $expected);
        $this->assertSame($expected, $actual);
        $this->assertResponseStatusCode($status);
    }

    public function testEditNonExistentProject()
    {
        $result = $this->patch('/api/v2/projects/ecto-one', array('description' => 'Who ya gonna call?'));
        $this->assertResponseStatusCode(404);
        $actual = json_decode($result->getBody(), true);
        $this->assertSame(array('error' => 'Not Found'), $actual);
    }

    public function projectEditProvider()
    {
        // Our basic starting point for project edit tests
        $wholeWorld = array(
            'name'        => 'Ecto One',
            'members'     => array('nonadmin'),
            'description' => 'We got one!',
            'deploy'      => array(
                'enabled' => true,
                'url'     => 'http://localhost/?change={change}'
            ),
            'branches'    => array(
                array(
                    'name'       => 'Egon',
                    'paths'      => array('//depot/egon/main/...'),
                    'moderators' => array('admin')
                )
            ),
            'emailFlags'  => array('review_email_project_members' => true, 'change_email_project_users' => true),
            'owners'      => array('admin', 'nonadmin'),
            'tests'       => array('enabled' => 'hells yeah', 'url' => 'http://localhost/?pass={pass}&fail={fail}'),
            'jobview'     => 'subsystem=swarm type=sir'
        );

        /*
         * Each provided test should have a unique name as its key (this will make debugging easier).
         *
         * First argument:  the initial project to create before running the test.
         *
         * Second argument: the project fields/values to edit
         *
         * Third argument:  the project fields/values to verify edit success
         *                  keys provided in this array are compared directly to the 'project' array of the successful
         *                  response, using array_intersect_key() to knock out unwanted fields that might clutter the
         *                  result.
         *
         * Fourth argument: optional HTTP status code for success/failure (defaults to 200)
         */
        return array(
            'change-description' => array(
                $wholeWorld,
                array('description' => 'He slimed me!'),
                array('description' => 'He slimed me!'),
            ),
            'change-name-id-unchanged' => array(
                $wholeWorld,
                array('name' => 'Ecto Two'),
                array('id' => 'ecto-one', 'name' => 'Ecto Two'),
            ),
            'add-branch' => array(
                $wholeWorld,
                array(
                    'branches' => array(
                        array(
                            'name'       => 'Egon',
                            'paths'      => array('//depot/egon/main/...'),
                            'moderators' => array('admin')
                        ),
                        array(
                            'name'       => 'Ecto Two',
                            'paths'      => array('//depot/ecto/main/...'),
                        )
                    )
                ),
                array(
                    'branches' => array(
                        array(
                            'name'       => 'Egon',
                            'paths'      => array('//depot/egon/main/...'),
                            'moderators' => array('admin'),
                            'id'         => 'egon'
                        ),
                        array(
                            'name'       => 'Ecto Two',
                            'paths'      => array('//depot/ecto/main/...'),
                            'id'         => 'ecto-two',
                            'moderators' => array()
                        )
                    ),
                    'members'  => array('nonadmin'),
                    'owners'   => array('admin', 'nonadmin'),
                ),
            ),
        );
    }

    public function testDeleteProject()
    {
        // create the project
        $result = $this->post('/api/v2/projects/', array('name' => 'prj123', 'members' => array('nonadmin')));
        $this->assertResponseStatusCode(200);

        // fetch the project
        $this->get('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(200);

        // delete the project
        $result = $this->delete('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(200);
        $this->assertSame(array('id' => 'prj123'), json_decode($result->getContent(), true));

        // fetch the deleted project
        $this->get('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(404);
    }

    public function testDeleteProjectForbidden()
    {
        // create the project
        $result = $this->post(
            '/api/v2/projects/',
            array('name' => 'prj123', 'members' => array('admin'), 'owners' => array('admin'))
        );
        $this->assertResponseStatusCode(200);

        // fetch the project
        $this->get('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(200);

        // delete the project
        $result = $this->delete('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(403);
        $this->assertSame(array('error' => 'Forbidden'), json_decode($result->getContent(), true));

        // fetch the project
        $this->get('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(200);
    }

    public function testDeleteProjectForbiddenForMembers()
    {
        // create the project
        $result = $this->post(
            '/api/v2/projects/',
            array('name' => 'prj123', 'members' => array('nonadmin'), 'owners' => array('admin'))
        );
        $this->assertResponseStatusCode(200);

        // fetch the project
        $this->get('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(200);

        // delete the project
        $result = $this->delete('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(403);
        $this->assertSame(array('error' => 'Forbidden'), json_decode($result->getContent(), true));

        // fetch the project
        $this->get('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(200);
    }

    public function testUndeleteProject()
    {
        // create the project
        $result = $this->post('/api/v2/projects/', array('name' => 'prj123', 'members' => array('nonadmin')));
        $this->assertResponseStatusCode(200);

        // fetch the project
        $this->get('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(200);

        // delete the project
        $result = $this->delete('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(200);
        $this->assertSame(array('id' => 'prj123'), json_decode($result->getContent(), true));

        // cannot fetch the deleted project
        $this->get('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(404);

        // cannot undelete the project
        $result = $this->patch('/api/v2/projects/prj123', array('deleted' => false));
        $this->assertResponseStatusCode(404);
        $this->assertSame(array('error' => 'Not Found'), json_decode($result->getContent(), true));
    }

    public function testDeletedProjectIdCannotBeReused()
    {
        // create the project
        $result = $this->post('/api/v2/projects/', array('name' => 'prj123', 'members' => array('nonadmin')));
        $this->assertResponseStatusCode(200);

        // delete the project
        $result = $this->delete('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(200);
        $this->assertSame(array('id' => 'prj123'), json_decode($result->getContent(), true));

        // recreate the project and confirm request is rejected with 400 Bad Request
        $result = $this->post('/api/v2/projects/', array('name' => 'prj123', 'members' => array('nonadmin')));
        $this->assertResponseStatusCode(400);
        $this->assertSame(
            array(
                'error'   => 'Bad Request',
                'details' => array('name' => 'This name is taken. Please pick a different name.')
            ),
            json_decode($result->getContent(), true)
        );

        // fetch the deleted project and confirm we get a 404 Not Found
        $this->get('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(404);
    }

    public function testDeleteNonExistentProject()
    {
        // delete a non-existent project
        $result = $this->delete('/api/v2/projects/prj123');
        $this->assertResponseStatusCode(404);
        $this->assertSame(
            array('error' => 'Cannot delete project: project not found.'),
            json_decode($result->getContent(), true)
        );
    }

    public function enableAdminProvider()
    {
        return array(
            'admin_enabled'  => array(true),
            'admin_disabled' => array(false)
        );
    }
}
