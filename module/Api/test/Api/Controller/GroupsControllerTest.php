<?php
/**
 * Perforce Swarm
 *
 * @copyright   2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApiTest\Controller;

use ModuleTest\TestApiController;
use Groups\Model\Group;
use Projects\Model\Project;
use Users\Model\User;

class GroupsControllerTest extends TestApiController
{
    protected $groupDescription = "In 1972 a crack commando unit was sent to prison by a military court for a crime they
        didn't commit. These men promptly escaped from a maximum security stockade to the Los Angeles underground.
        Today, still wanted by the government, they survive as soldiers of fortune. If you have a problem, if no
        one else can help, and if you can find them, maybe you can hire the A-Team.";
    protected $groupExample     = array(
        'Group'           => '',
        'MaxLockTime'     => null,
        'MaxResults'      => null,
        'MaxScanRows'     => null,
        'Owners'          => array(),
        'PasswordTimeout' => null,
        'Subgroups'       => array(),
        'Timeout'         => 43200,
        'Users'           => array(),
        'config'          => array(
            'description' => null,
            'emailFlags'  => array(),
            'name'        => null
        )
    );

    public function setUp()
    {
        parent::setUp();
        if ($this->needsSuper()) {
            $this->getApplication()->getServiceManager()->setService('p4_user', $this->superP4);
        }
    }

    public function testListGroups()
    {
        $count  = 2;
        $result = $this->get('/api/v2/groups');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);
        $this->assertSame(array('groups' => array(), 'lastSeen' => null), $actual);

        // create some groups
        $user = new User($this->p4);
        $user->setId('hmurdock')
            ->setEmail('foo@test.com')
            ->setFullName('Howlin Mad Murdock')
            ->save();

        for ($i = 0; $i < $count; $i++) {
            $result = $this->post(
                '/api/v2/groups',
                array(
                    'Group'  => 'team' . $i,
                    'Users'  => array('hmurdock'),
                    'config' => array('description' => $this->groupDescription)
                ),
                'super'
            );
        }

        // fetch the listing and remove some fields that vary by server version
        $result = $this->get('/api/v2/groups');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);
        foreach ($actual['groups'] as $key => $value) {
            unset(
                $actual['groups'][$key]['LdapConfig'],
                $actual['groups'][$key]['LdapSearchQuery'],
                $actual['groups'][$key]['LdapUserAttribute']
            );
        }

        // build expected value
        $expected                         = array('groups' => array(), 'lastSeen' => 'team1');
        $example                          = $this->groupExample;
        $example['Users']                 = array('hmurdock');
        $example['config']['description'] = $this->groupDescription;
        for ($i = 0; $i < $count; $i++) {
            $example['Group']          = 'team' . $i;
            $example['config']['name'] = 'team' . $i;
            $expected['groups'][]      = $example;
        }

        // finally, run the comparison
        $this->assertSame($expected, $actual);
    }

    public function testListGroupsKeywords()
    {
        $count  = 10;
        $result = $this->get('/api/v2/groups');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);
        $this->assertSame(array('groups' => array(), 'lastSeen' => null), $actual);

        // create some groups
        $user = new User($this->p4);
        $user->setId('hmurdock')
            ->setEmail('foo@test.com')
            ->setFullName('Howlin Mad Murdock')
            ->save();

        for ($i = 0; $i < $count; $i++) {
            $result = $this->post(
                '/api/v2/groups',
                array(
                    'Group'  => 'team' . $i,
                    'Users'  => array('hmurdock'),
                    'config' => array('description' => ($i % 2 == 0) ? $this->groupDescription : '')
                ),
                'super'
            );
        }

        // fetch the listing and remove some fields that vary by server version
        $result = $this->get('/api/v2/groups?keywords=stockade');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);

        // verify the correct number of groups are returned
        $this->assertSame(5, count($actual['groups']));

        // verify the description of the first returned group matches expected value
        $this->assertSame($this->groupDescription, $actual['groups'][0]['config']['description']);
    }

    public function testListGroupsLimitFields()
    {
        $count  = 2;
        $result = $this->get('/api/v2/groups');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);
        $this->assertSame(array('groups' => array(), 'lastSeen' => null), $actual);

        // create some groups
        $user = new User($this->p4);
        $user->setId('hmurdock')
            ->setEmail('foo@test.com')
            ->setFullName('Howlin Mad Murdock')
            ->save();

        for ($i = 0; $i < $count; $i++) {
            $this->post(
                '/api/v2/groups',
                array(
                    'Group'  => 'team' . $i,
                    'Users'  => array('hmurdock'),
                    'config' => array('description' => $this->groupDescription)
                ),
                'super'
            );
        }

        // fetch the listing and limit to one existing field and one non-existent field (test)
        $result = $this->get('/api/v2/groups?fields=Group,test');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);

        // build expected value
        $expected = array('groups' => array(), 'lastSeen' => 'team1');
        $example  = array('Group' => '');
        for ($i = 0; $i < $count; $i++) {
            $example['Group']     = 'team' . $i;
            $expected['groups'][] = $example;
        }

        // finally, run the comparison
        $this->assertSame($expected, $actual);
    }

    public function testListProjectGroups()
    {
        // skip this test on old servers where project groups are not created
        if ($this->needsSuper()) {
            $this->markTestSkipped('Project groups are not utilized, skipping.');
        }

        $project = new Project($this->p4);
        $project->setId('foo')->setMembers('foo')->save();

        Group::fromArray(array('Group' => 'group-1', 'Users' => array('foo')), $this->superP4)->save();

        // ensure that project groups are not listed in the result (API v3 and above)
        $result = $this->get('/api/v3/groups?fields=Group');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);

        $this->assertSame(1, count($actual['groups']));
        $this->assertSame('group-1', $actual['groups'][0]['Group']);

        // project grousp should be listed in v2 for backwards compatibility though
        $result = $this->get('/api/v2/groups?fields=Group');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);

        $this->assertSame(2, count($actual['groups']));
    }

    public function testFetchGroup()
    {
        $result = $this->get('/api/v2/groups/a-team');
        $this->assertResponseStatusCode(404);
        $actual = json_decode($result->getContent(), true);
        $this->assertSame(array('error' => 'Not Found'), $actual);

        // create a group
        $user = new User($this->p4);
        $user->setId('hmurdock')
            ->setEmail('foo@test.com')
            ->setFullName('Howlin Mad Murdock')
            ->save();

        $result = $this->post(
            '/api/v2/groups',
            array(
                'Group'  => 'a-team',
                'Users'  => array('hmurdock'),
                'config' => array('description' => $this->groupDescription)
            ),
            'super'
        );
        $this->assertResponseStatusCode(200);

        // test the listing
        $result = $this->get('/api/v2/groups/a-team');
        $this->assertResponseStatusCode(200);
        $actual                           = json_decode($result->getContent(), true);
        $example                          = $this->groupExample;
        $example['Group']                 = 'a-team';
        $example['Users']                 = array('hmurdock');
        $example['config']['description'] = $this->groupDescription;
        $example['config']['name']        = 'a-team';
        $expected                         = array('group' => $example);
        unset(
            $actual['group']['MaxOpenFiles'],
            $actual['group']['LdapConfig'],
            $actual['group']['LdapSearchQuery'],
            $actual['group']['LdapUserAttribute']
        );
        $this->assertSame($expected, $actual);
    }

    public function testAddGroup()
    {
        $result = $this->post(
            '/api/v2/groups',
            array(
                'Group'  => 'a-team',
                'Users'  => array('nonadmin'),
                'config' => array('name' => 'A-Team', 'description' => $this->groupDescription)
            ),
            'super'
        );
        $this->assertResponseStatusCode(200);
        $actual   = json_decode($result->getContent(), true);
        $expected = array(
            'group' => array(
                'Group'             => 'a-team',
                'MaxLockTime'       => null,
                'MaxResults'        => null,
                'MaxScanRows'       => null,
                'Owners'            => array(),
                'PasswordTimeout'   => null,
                'Subgroups'         => array(),
                'Timeout'           => 43200,
                'Users'             => array('nonadmin'),
                'config'            => array(
                    'description' => $this->groupDescription,
                    'emailFlags'  => array(),
                    'name'        => 'A-Team',
                ),
            )
        );
        unset($actual['group']['MaxOpenFiles']);
        unset($actual['group']['LdapConfig']);
        unset($actual['group']['LdapSearchQuery']);
        unset($actual['group']['LdapUserAttribute']);
        $this->assertSame($expected, $actual);

        $group  = Group::fetch('a-team', $this->p4);
        $config = $group->getConfig();
        $this->assertInstanceOf('Groups\Model\Group', $group);
        $this->assertInstanceOf('Groups\Model\Config', $config);
        $this->assertSame($this->groupDescription, $config->getDescription());
    }

    public function testAddGroupNoUsers()
    {
        $result = $this->post(
            '/api/v2/groups',
            array(
                'Group'  => 'a-team',
                'config' => array('name' => 'A-Team', 'description' => $this->groupDescription)
            ),
            'super'
        );
        $this->assertResponseStatusCode(400);
        $actual   = json_decode($result->getContent(), true);
        $expected = array(
            'error'   => 'Bad Request',
            'details' => array(
                'Users' => 'Group must have at least one owner, user or subgroup.'
            )
        );
        $this->assertSame($expected, $actual);

        try {
            Group::fetch('a-team', $this->p4);
        } catch (\Exception $e) {
        }

        $this->assertInstanceOf('P4\Spec\Exception\NotFoundException', $e);
    }

    public function testAddGroupNoName()
    {
        $result = $this->post(
            '/api/v2/groups',
            array(
                'Users'  => array('nonadmin'),
                'Group'  => 'a-team',
                'config' => array('description' => $this->groupDescription)
            ),
            'super'
        );
        $this->assertResponseStatusCode(200);
        $actual   = json_decode($result->getContent(), true);
        $expected = array(
            'group' => array(
                'Group'             => 'a-team',
                'MaxLockTime'       => null,
                'MaxResults'        => null,
                'MaxScanRows'       => null,
                'Owners'            => array(),
                'PasswordTimeout'   => null,
                'Subgroups'         => array(),
                'Timeout'           => 43200,
                'Users'             => array('nonadmin'),
                'config'            => array(
                    'description' => $this->groupDescription,
                    'emailFlags'  => array(),
                    'name'        => 'a-team',
                ),
            )
        );
        unset($actual['group']['MaxOpenFiles']);
        unset($actual['group']['LdapConfig']);
        unset($actual['group']['LdapSearchQuery']);
        unset($actual['group']['LdapUserAttribute']);
        $this->assertSame($expected, $actual);

        $group  = Group::fetch('a-team', $this->p4);
        $config = $group->getConfig();
        $this->assertInstanceOf('Groups\Model\Group', $group);
        $this->assertInstanceOf('Groups\Model\Config', $config);
        $this->assertSame($this->groupDescription, $config->getDescription());
    }

    public function testAddGroupAndName()
    {
        $result = $this->post(
            '/api/v2/groups',
            array(
                'Users'  => array('nonadmin'),
                'Group'  => 'a-team',
                'config' => array('name' => 'Axe-Team', 'description' => $this->groupDescription)
            ),
            'super'
        );
        $this->assertResponseStatusCode(200);
        $actual   = json_decode($result->getContent(), true);
        $expected = array(
            'group' => array(
                'Group'             => 'a-team',
                'MaxLockTime'       => null,
                'MaxResults'        => null,
                'MaxScanRows'       => null,
                'Owners'            => array(),
                'PasswordTimeout'   => null,
                'Subgroups'         => array(),
                'Timeout'           => 43200,
                'Users'             => array('nonadmin'),
                'config'            => array(
                    'description' => $this->groupDescription,
                    'emailFlags'  => array(),
                    'name'        => 'Axe-Team',
                ),
            )
        );
        unset($actual['group']['MaxOpenFiles']);
        unset($actual['group']['LdapConfig']);
        unset($actual['group']['LdapSearchQuery']);
        unset($actual['group']['LdapUserAttribute']);
        $this->assertSame($expected, $actual);

        $group  = Group::fetch('a-team', $this->p4);
        $config = $group->getConfig();
        $this->assertInstanceOf('Groups\Model\Group', $group);
        $this->assertInstanceOf('Groups\Model\Config', $config);
        $this->assertSame($this->groupDescription, $config->getDescription());
    }

    public function testEditGroup()
    {
        // create the initial group
        $this->post(
            '/api/v2/groups',
            array(
                'Group'  => 'a-team',
                'Owners' => array('admin'),
                'Users'  => array('nonadmin'),
                'config' => array(
                    'name'        => 'A-Team',
                    'description' => $this->groupDescription
                ),
            ),
            'super'
        );
        $this->assertResponseStatusCode(200);

        // test that we can edit the group
        $result = $this->patch(
            '/api/v2/groups/a-team',
            array(
                'Users'  => array('admin', 'nonadmin'),
                'config' => array('description' => 'I love it when a plan comes together!'),
            ),
            true
        );
        $this->assertResponseStatusCode(200);
        $actual   = json_decode($result->getContent(), true);
        $expected = array(
            'group' => array(
                'Group'             => 'a-team',
                'MaxLockTime'       => null,
                'MaxResults'        => null,
                'MaxScanRows'       => null,
                'Owners'            => array('admin'),
                'PasswordTimeout'   => null,
                'Subgroups'         => array(),
                'Timeout'           => 43200,
                'Users'             => array('admin', 'nonadmin'),
                'config'            => array(
                    'description' => 'I love it when a plan comes together!',
                    'emailFlags'  => array(),
                    'name'        => 'A-Team',
                ),
            )
        );
        unset($actual['group']['MaxOpenFiles']);
        unset($actual['group']['LdapConfig']);
        unset($actual['group']['LdapSearchQuery']);
        unset($actual['group']['LdapUserAttribute']);
        $this->assertSame($expected, $actual);

        $group  = Group::fetch('a-team', $this->p4);
        $config = $group->getConfig();
        $this->assertInstanceOf('Groups\Model\Group', $group);
        $this->assertInstanceOf('Groups\Model\Config', $config);
        $this->assertSame('I love it when a plan comes together!', $config->getDescription());
        $this->assertSame(array('admin', 'nonadmin'), $group->getUsers());
    }

    public function testEditGroupWrongFields()
    {
        // create the initial group
        $this->post(
            '/api/v2/groups',
            array(
                'Group'   => 'a-team',
                'Owners'  => array('admin'),
                'Users'   => array('nonadmin'),
                'Timeout' => 100,
                'config'  => array(
                    'name'        => 'A-Team',
                    'description' => $this->groupDescription,
                ),
            ),
            'super'
        );
        $this->assertResponseStatusCode(200);

        // test that unexpected fields are ignored.
        $result = $this->patch(
            '/api/v2/groups/a-team',
            array(
                'Group'  => 'b-team',
                'Owners' => array('nonadmin'),
                'Junk'   => 'anything',
                'config' => array(
                    'name'  => 'B-Team',
                    'junk'  => 'anything',
                ),
            ),
            true
        );
        $this->assertResponseStatusCode(200);
        $actual   = json_decode($result->getContent(), true);
        $expected = array(
            'group' => array(
                'Group'             => 'a-team',
                'MaxLockTime'       => null,
                'MaxResults'        => null,
                'MaxScanRows'       => null,
                'Owners'            => array('nonadmin'),
                'PasswordTimeout'   => null,
                'Subgroups'         => array(),
                'Timeout'           => 43200,
                'Users'             => array('nonadmin'),
                'config'            => array(
                    'description' => $this->groupDescription,
                    'emailFlags'  => array(),
                    'name'        => 'B-Team',
                ),
            )
        );
        unset($actual['group']['MaxOpenFiles']);
        unset($actual['group']['LdapConfig']);
        unset($actual['group']['LdapSearchQuery']);
        unset($actual['group']['LdapUserAttribute']);
        $this->assertSame($expected, $actual);
    }

    public function testDeleteGroup()
    {
        $result = $this->post(
            '/api/v2/groups',
            array(
                'Group'  => 'a-team',
                'Users'  => array('nonadmin'),
                'config' => array('name' => 'A-Team', 'description' => $this->groupDescription)
            ),
            'super'
        );
        $this->assertResponseStatusCode(200);
        $result = $this->delete('/api/v2/groups/a-team', 'super');
        $this->assertResponseStatusCode(200);
        $actual   = json_decode($result->getContent(), true);
        $expected = array('id' => 'a-team');
        $this->assertSame($expected, $actual);

        try {
            Group::fetch('a-team', $this->p4);
        } catch (\Exception $e) {
        }

        $this->assertInstanceOf('P4\Spec\Exception\NotFoundException', $e);
    }

    /**
     * Helper method - returns true if adding group requires super user (servers <2012.1)
     * otherwise returns false.
     *
     * @return  boolean     true if user need to be a super to add groups, false otherwise
     */
    protected function needsSuper()
    {
        return !$this->p4->isServerMinVersion('2012.1');
    }
}
