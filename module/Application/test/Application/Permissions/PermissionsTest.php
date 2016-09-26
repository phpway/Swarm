<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Permissions;

use Groups\Model\Group as GroupModel;
use ModuleTest\TestControllerCase;
use Projects\Model\Project;
use P4\Spec\Group;

class PermissionsTest extends TestControllerCase
{
    /**
     * @expectedException Application\Permissions\Exception\ForbiddenException
     */
    public function testNotAdmin()
    {
        $this->getApplication()->getServiceManager()->get('permissions')->enforce('admin');
    }

    public function testAdmin()
    {
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $services->get('p4_admin'));
        $services->get('permissions')->enforce('admin');
    }

    /**
     * @expectedException Application\Permissions\Exception\ForbiddenException
     */
    public function testNotSuper()
    {
        $this->getApplication()->getServiceManager()->get('permissions')->enforce('super');
    }

    public function testSuper()
    {
        $services = $this->getApplication()->getServiceManager();
        $superP4  = $this->superP4;
        $services->setFactory(
            'p4_user',
            function () use ($superP4) {
                return $superP4;
            }
        );

        $services->get('permissions')->enforce('super');
    }

    /**
     * @expectedException Application\Permissions\Exception\UnauthorizedException
     */
    public function testNotAuthenticated()
    {
        $services = $this->getApplication()->getServiceManager();
        $services->setFactory(
            'p4_user',
            function () {
                throw new \Application\Permissions\Exception\UnauthorizedException;
            }
        );
        $services->get('permissions')->enforce('authenticated');
    }

    public function testAuthenticated()
    {
        $this->getApplication()->getServiceManager()->get('permissions')->enforce('authenticated');
    }

    /**
     * @expectedException Application\Permissions\Exception\ForbiddenException
     */
    public function testNotProjectMember()
    {
        $project = new Project;
        $project->setId('test')->setMembers(array('notme'))->save();
        $this->getApplication()->getServiceManager()->get('permissions')->enforce(array('member' => $project));
    }

    public function testProjectMember()
    {
        $services = $this->getApplication()->getServiceManager();
        $project  = new Project;
        $project->setId('test')->setMembers(array('notme', $services->get('p4_user')->getUser()))->save();
        $services->get('permissions')->enforce(array('member' => $project));
    }

    /**
     * @expectedException Application\Permissions\Exception\ForbiddenException
     */
    public function testNotGroupMember()
    {
        $group = new GroupModel($this->superP4);
        $group->setId('test')->setUsers(array('notme'))->save();
        $this->getApplication()->getServiceManager()->get('permissions')->enforce(array('member' => $group));
    }

    public function testGroupMember()
    {
        $services = $this->getApplication()->getServiceManager();
        $group    = new GroupModel($this->superP4);
        $group->setId('test')->setUsers(array('notme', $services->get('p4_user')->getUser()))->save();
        $services->get('permissions')->enforce(array('member' => $group));
    }

    /**
     * @expectedException Application\Permissions\Exception\ForbiddenException
     */
    public function testNotGroupsMember()
    {
        $group = new Group($this->superP4);
        $group->setId('foo')->setUsers(array('notme'))->save();
        $group = new Group($this->superP4);
        $group->setId('bar')->setUsers(array('notme', 'someoneelse'))->save();

        $permissions = $this->getApplication()->getServiceManager()->get('permissions');
        $permissions->enforce(array('member' => array('foo', 'bar')));
    }

    public function testGroupsMember()
    {
        $services = $this->getApplication()->getServiceManager();

        $group = new Group($this->superP4);
        $group->setId('foo')->setUsers(array('notme'))->save();
        $group = new Group($this->superP4);
        $group->setId('bar')->setUsers(array('notme', $services->get('p4_user')->getUser()))->save();

        $services->get('permissions')->enforce(array('member' => array('foo', 'bar')));
    }

    /**
     * @expectedException Application\Permissions\Exception\UnauthorizedException
     */
    public function testProjectAddAllowedNotAuthenticated()
    {
        $services = $this->getApplication()->getServiceManager();
        $services->setFactory(
            'p4_user',
            function () {
                throw new \Application\Permissions\Exception\UnauthorizedException;
            }
        );

        $services->get('permissions')->enforce('projectAddAllowed');
    }

    /**
     * @dataProvider projectAddAllowedDataProvider
     */
    public function testProjectAddAllowed(array $customConfig, array $groups, array $memberOf, $isAdmin, $isAllowed)
    {
        // adjust security config
        $services = $this->getApplication()->getServiceManager();
        $config   = $services->get('config');

        foreach ($customConfig as $configKey => $configData) {
            foreach ($configData as $key => $value) {
                $config[$configKey][$key] = $value;
            }
        }
        $services->setService('config', $config);

        // create groups for testing
        foreach (array_unique(array_merge($groups, $memberOf)) as $groupName) {
            $group = new Group($this->superP4);
            $group
                ->setId($groupName)
                ->setUsers(
                    in_array($groupName, $memberOf)
                    ? array('a', 'b', 'foo')
                    : array('a', 'b', 'x')
                )
                ->save();
        }

        // prepare user connection
        $this->getApplication()->getServiceManager()->setService(
            'p4_user',
            $this->connectWithAccess('foo', array( '//...' => $isAdmin ? 'admin' : 'write'))
        );

        // check permissions
        $this->assertSame(
            $isAllowed,
            $services->get('permissions')->is('projectAddAllowed')
        );
    }

    /**
     * @expectedException Application\Permissions\Exception\UnauthorizedException
     */
    public function testGroupAddAllowedNotAuthenticated()
    {
        $services = $this->getApplication()->getServiceManager();
        $services->setFactory(
            'p4_user',
            function () {
                throw new \Application\Permissions\Exception\UnauthorizedException;
            }
        );

        $services->get('permissions')->enforce('groupAddAllowed');
    }

    public function testGroupAddAllowedAsSuper()
    {
        $services = $this->getApplication()->getServiceManager();

        // verify it fails for regular users
        $services->setService('p4_user', $this->userP4);
        $this->assertSame(false, $services->get('permissions')->is('groupAddAllowed'));

        // verify it works for super users
        $services->setService('p4_user', $this->superP4);
        $this->assertSame(true, $services->get('permissions')->is('groupAddAllowed'));
    }

    public function testGroupAddAllowedAsAdmin()
    {
        // this test applies only for servers >=2012.1 where admins can add groups
        if (!$this->p4->isServerMinVersion('2012.1')) {
            $this->markTestSkipped('Cannot add groups as admin. Server too old.');
        }

        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $this->p4);
        $this->assertSame(true, $services->get('permissions')->is('groupAddAllowed'));
    }

    /**
     * Data provider for projectAddAllowed test.
     * Each data set contains following values:
     *  - list with application security config key-value pairs
     *  - list of groups to create for testing
     *  - list of groups tested user will be set as member of
     *  - boolean flag whether to set 'p4' service with admin (true) or regular (false) connection
     *  - boolean flag for test result, true = adding project is allowed, false otherwise
     */
    public function projectAddAllowedDataProvider()
    {
        return array(
            // test success with default config (no admin, no groups membership required)
            array(
                array(),
                array('a', 'b', 'c'),
                array(),
                false,
                true
            ),
            // lacking admin privilege
            array(
                array(
                    'projects' => array(
                        'add_admin_only'  => true,
                        'add_groups_only' => array('a')
                    )
                ),
                array('a', 'b'),
                array('a'),
                false,
                false
            ),
            // lacking group membership
            array(
                array(
                    'projects' => array(
                        'add_admin_only'  => true,
                        'add_groups_only' => array('a', 'b')
                    )
                ),
                array('a', 'b', 'c'),
                array('c'),
                true,
                false
            ),
            // lacking both admin and group membership
            array(
                array(
                    'projects' => array(
                        'add_admin_only'  => true,
                        'add_groups_only' => array('a', 'b')
                    )
                ),
                array('a', 'b', 'c'),
                array('c'),
                false,
                false
            ),
            // test success with admin required
            array(
                array(
                    'projects' => array(
                        'add_admin_only'  => true,
                        'add_groups_only' => null
                    )
                ),
                array(),
                array(),
                true,
                true
            ),
            // test success with groups membership restrictions
            array(
                array(
                    'projects' => array(
                        'add_admin_only'  => false,
                        'add_groups_only' => array('a', 'b', 'c', 'x', 'y')
                    )
                ),
                array('a', 'b', 'c', 'x', 'y', 'z'),
                array('y'),
                false,
                true
            ),
            // test success with both admin and groups membership restrictions
            array(
                array(
                    'projects' => array(
                        'add_admin_only'  => true,
                        'add_groups_only' => array('a', 'b', 'c', 'x', 'y')
                    )
                ),
                array('a', 'b', 'c', 'x', 'y', 'z'),
                array('y', 'b'),
                true,
                true
            ),
            // backwards compatibility check: ensure options from 'security' are applied when set
            array(
                array(
                    'security' => array(
                        'add_project_admin_only' => true,
                        'add_project_groups'     => array('a')
                    )
                ),
                array('a', 'b'),
                array('a'),
                false,
                false
            ),
            array(
                array(
                    'security' => array(
                        'add_project_admin_only' => true,
                        'add_project_groups'     => array('a')
                    )
                ),
                array('a', 'b'),
                array('b'),
                true,
                false
            ),
            array(
                array(
                    'projects' => array(
                        'add_admin_only'  => true,
                        'add_groups_only' => array('a')
                    ),
                    'security' => array(
                        'add_project_admin_only' => false,
                        'add_project_groups'     => array()
                    )
                ),
                array('a', 'b'),
                array('b'),
                false,
                false
            )
        );
    }

    /**
     * @expectedException Application\Permissions\Exception\ForbiddenException
     */
    public function testProjectAddAllowedNotAdmin()
    {
        $services                             = $this->getApplication()->getServiceManager();
        $config                               = $services->get('config');
        $config['projects']['add_admin_only'] = true;
        $services->setService('config', $config);

        $services->get('permissions')->enforce('projectAddAllowed');
    }

    /**
     * @expectedException Application\Permissions\Exception\ForbiddenException
     */
    public function testGroupAddAllowedNotAdmin()
    {
        $services = $this->getApplication()->getServiceManager();
        $services->get('permissions')->enforce('groupAddAllowed');
    }

    /**
     * @expectedException Application\Permissions\Exception\ForbiddenException
     */
    public function testNotProjectOwner()
    {
        $project = new Project;
        $project->setMembers(array('notme'));
        $this->getApplication()->getServiceManager()->get('permissions')->enforce(array('owner' => $project));
    }

    /**
     * @expectedException Application\Permissions\Exception\ForbiddenException
     */
    public function testNotGroupOwner()
    {
        $group = new GroupModel;
        $group->setUsers(array('notme'))->setOwners(array('nothim'));
        $this->getApplication()->getServiceManager()->get('permissions')->enforce(array('owner' => $group));
    }

    public function testProjectOwner()
    {
        $services = $this->getApplication()->getServiceManager();
        $project  = new Project;
        $project->set('owners', array('notme', $services->get('p4_user')->getUser()));
        $services->get('permissions')->enforce(array('owner' => $project));
    }

    public function testGroupOwner()
    {
        $services = $this->getApplication()->getServiceManager();
        $group    = new GroupModel;
        $group->setOwners(array('notme', $services->get('p4_user')->getUser()));
        $services->get('permissions')->enforce(array('owner' => $group));
    }

    /**
     * @expectedException Application\Permissions\Exception\ForbiddenException
     */
    public function testNotEnforceOne()
    {
        $this->getApplication()->getServiceManager()->get('permissions')->enforceOne(array('super', 'admin'));
    }

    public function testEnforceOne()
    {
        $this->getApplication()->getServiceManager()->get('permissions')->enforceOne(array('super', 'authenticated'));
    }

    /**
     * @expectedException Application\Permissions\Exception\ForbiddenException
     */
    public function testNotEnforceWithTwo()
    {
        $this->getApplication()->getServiceManager()->get('permissions')->enforce(array('super', 'authenticated'));
    }

    public function testEnforceWithTwo()
    {
        $services = $this->getApplication()->getServiceManager();
        $project  = new Project;
        $project->setId('test')->setMembers(array('notme', $services->get('p4_user')->getUser()))->save();

        $services->get('permissions')->enforce(array('authenticated', 'member' => $project));
    }

    public function testIsAndIsOne()
    {
        $permissions = $this->getApplication()->getServiceManager()->get('permissions');

        $this->assertFalse($permissions->is(array('authenticated', 'admin')), 'is should fail with with 1/2 passing');
        $this->assertTrue($permissions->isOne(array('admin', 'authenticated')), 'isOne should have passed');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testInvalidCheck()
    {
        $this->getApplication()->getServiceManager()->get('permissions')->enforce('foobar');
    }
}
