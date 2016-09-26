<?php
/**
 * Tests for the project filter.
 *
 * @copyright   2015 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ProjectsTest\Filter;

use Application\I18n\Translator;
use P4\Spec\Group;
use P4Test\TestCase;
use Projects\Filter\Project as Filter;
use Projects\Filter\Project;

class ProjectTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                'Zend\Loader\StandardAutoloader' => array(
                    'namespaces' => array(
                        'Projects'    => BASE_PATH . '/module/Projects/src/Projects',
                        'Users'       => BASE_PATH . '/module/Users/src/Users',
                        'Groups'      => BASE_PATH . '/module/Groups/src/Groups',
                        'Application' => BASE_PATH . '/module/Application/src/Application',
                        'P4'          => BASE_PATH . '/library/P4',
                        'Record'      => BASE_PATH . '/library/Record',
                    )
                )
            )
        );

        $this->p4->setService('translator', new Translator());
    }

    /**
     * @dataProvider projectProvider
     */
    public function testFilterProjects($project, $valid = true, $messages = array(), $mode = Project::MODE_ADD)
    {
        if (!$this->p4->isServerMinVersion('2012.1') && isset($project['subgroups'])) {
            $this->markTestSkipped('Cannot test subgroups. Server is too old.');
        }

        $group = new Group();
        $group->setId('foo')->setUsers(array('tester'))->save();
        $filter = new Filter($this->p4);
        $filter->setData($project);
        $filter->setMode($mode);
        $this->assertSame($valid, $filter->isValid());
        $this->assertSame($messages, $filter->getMessages());
    }

    public function projectProvider()
    {
        $data = array(
            'reject-empty-name' => array(
                array('name' => ''),
                false,
                array(
                    'name'    => array(
                        'isEmpty'       => 'Name is required and can\'t be empty.',
                        'callbackValue' => 'Name must contain at least one letter or number.',
                    ),
                    'members' => array(
                        'callbackValue' => 'Project must have at least one member or subgroup.'
                    )
                )
            ),
            'reject-bad-name' => array(
                array('name' => '-'),
                false,
                array(
                    'name'    => array(
                        'callbackValue' => 'Name must contain at least one letter or number.',
                    ),
                    'members' => array(
                        'callbackValue' => 'Project must have at least one member or subgroup.'
                    )
                )
            ),
            'accept-basic' => array(array('name' => 'My Project', 'members' => array('tester'))),
            'accept-basic-subgroup' => array(array('name' => 'My Project', 'subgroups' => array('foo'))),
            'reject-branch' => array(
                array('name' => 'test', 'members' => array('tester'), 'branches' => 'nope'),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Branches must be passed as an array.'
                    )
                )
            ),
            'reject-inner-branch' => array(
                array('name' => 'test', 'members' => array('tester'), 'branches' => array('nope')),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'All branches must be in array form.'
                    )
                )
            ),
            'reject-inner-branch-garbage' => array(
                array('name' => 'test', 'members' => array('tester'), 'branches' => array(array('name' => '--'))),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Branch name must contain at least one letter or number.'
                    )
                )
            ),
            'reject-int-branch' => array(
                array('name' => 'test', 'members' => array('tester'), 'branches' => 2),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Branches must be passed as an array.'
                    )
                )
            ),
            'reject-missing-depot-branch' => array(
                array(
                    'name'     => 'test',
                    'members'  => array('tester'),
                    'branches' => array(array('name' => 'test', 'paths' => '//'))
                ),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Error in \'test\' branch: ' .
                            'The first path component must be a valid depot name.'
                    )
                )
            ),
            'reject-null-depot-name-branch' => array(
                array(
                    'name'     => 'test',
                    'members'  => array('tester'),
                    'branches' => array(array('name' => 'test', 'paths' => '///depot/foo'))
                ),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Error in \'test\' branch: ' .
                                           'The first path component must be a valid depot name.'
                    )
                )
            ),
            'reject-wrong-depot-name-branch' => array(
                array(
                    'name'     => 'test',
                    'members'  => array('tester'),
                    'branches' => array(array('name' => 'test', 'paths' => '//../foo'))
                ),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Error in \'test\' branch: ' .
                                           'The first path component must be a valid depot name.'
                    )
                )
            ),
            'reject-relative-path-single-branch' => array(
                array(
                    'name'     => 'test',
                    'members'  => array('tester'),
                    'branches' => array(array('name' => 'test', 'paths' => '//depot/./folder'))
                ),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Error in \'test\' branch: ' .
                                           'Relative paths (., ..) are not allowed.'
                    )
                )
            ),
            'reject-relative-path-single-end-branch' => array(
                array(
                    'name'     => 'test',
                    'members'  => array('tester'),
                    'branches' => array(array('name' => 'test', 'paths' => '//depot/foo/.'))
                ),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Error in \'test\' branch: ' .
                                           'Relative paths (., ..) are not allowed.'
                    )
                )
            ),
            'reject-relative-path-double-branch' => array(
                array(
                    'name'     => 'test',
                    'members'  => array('tester'),
                    'branches' => array(array('name' => 'test', 'paths' => '//depot/foo/../folder'))
                ),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Error in \'test\' branch: ' .
                                           'Relative paths (., ..) are not allowed.'
                    )
                )
            ),
            'reject-relative-path-double-end-branch' => array(
                array(
                    'name'     => 'test',
                    'members'  => array('tester'),
                    'branches' => array(array('name' => 'test', 'paths' => '//depot/..'))
                ),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Error in \'test\' branch: ' .
                                           'Relative paths (., ..) are not allowed.'
                    )
                )
            ),
            'accept-double-dot-file-branch' => array(
                array(
                    'name'     => 'test',
                    'members'  => array('tester'),
                    'branches' => array(array('name' => 'test', 'paths' => '//depot/foo/a..b/folder '))
                )
            ),
            'reject-null-directories-branch' => array(
                array(
                    'name'     => 'test',
                    'members'  => array('tester'),
                    'branches' => array(array('name' => 'test', 'paths' => '//depot/test//Test'))
                ),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Error in \'test\' branch: ' .
                                           'The path cannot contain null directories (\'//\') or end with a \'/\'.'
                    )
                )
            ),
            'reject-null-directories-end-branch' => array(
                array(
                    'name'     => 'test',
                    'members'  => array('tester'),
                    'branches' => array(array('name' => 'test', 'paths' => '//depot/foo/'))
                ),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'Error in \'test\' branch: ' .
                                           'The path cannot contain null directories (\'//\') or end with a \'/\'.'
                    )
                )
            ),
            'accept-null-branch' => array(
                array('name' => 'test', 'members' => array('tester'), 'branches' => null)
            ),
            'accept-empty-branch' => array(
                array('name' => 'test', 'members' => array('tester'), 'branches' => '')
            ),
            'reject-int-inner-branch' => array(
                array('name' => 'test', 'members' => array('tester'), 'branches' => array(2)),
                false,
                array(
                    'branches' => array(
                        'callbackValue' => 'All branches must be in array form.'
                    )
                )
            ),
            'reject-blank-members' => array(
                array('name' => 'test', 'members' => array('tester', '')),
                false,
                array(
                    'members' => array(
                        'unknownIds' => 'Unknown user id(s): '
                    )
                )
            ),
            'reject-blank-owners' => array(
                array('name' => 'test', 'members' => array('tester'), 'owners' => array('tester', '')),
                false,
                array(
                    'owners' => array(
                        'unknownIds' => 'Unknown user id(s): '
                    )
                )
            ),
            'accept-owners' => array(
                array('name' => 'test', 'members' => array('tester'), 'owners' => array('tester'))
            ),
            'reject-owner-string' => array(
                array('name' => 'test', 'members' => array('tester'), 'owners' => 'tester'),
                false,
                array('owners' => array('notArray' => 'Invalid type given. Array required.'))
            ),
            'reject-owner-nested-array' => array(
                array('name' => 'test', 'members' => array('tester'), 'owners' => array(array('tester'))),
                false,
                array('owners' => array('notFlat' => 'Array values must not be arrays or objects.'))
            ),
            'reject-member-string' => array(
                array('name' => 'test', 'members' => 'tester'),
                false,
                array('members' => array('notArray' => 'Invalid type given. Array required.'))
            ),
            'accept-blank-description' => array(
                array('name' => 'test', 'members' => array('tester'), 'description' => '')
            ),
            'reject-bad-description' => array(
                array('name' => 'test', 'members' => array('tester'), 'description' => array()),
                false,
                array('description' => array('callbackValue' => 'Description must be a string.'))
            ),
            'reject-bad-jobview' => array(
                array('name' => 'test', 'members' => array('tester'), 'jobview' => array()),
                false,
                array('jobview' => array('callbackValue' => 'Job filter must be a string.'))
            ),
            'reject-bad-jobview-format' => array(
                array('name' => 'test', 'members' => array('tester'), 'jobview' => 'subsystem=swarm booyah'),
                false,
                array(
                    'jobview' => array(
                        'callbackValue' => 'Job filter only supports key=value conditions and the \'*\' wildcard.'
                    )
                )
            ),
            'accept-jobview' => array(
                array('name' => 'test', 'members' => array('tester'), 'jobview' => 'subsystem=swarm')
            ),
            'reject-bad-email-flags-array' => array(
                array(
                    'name'       => 'test',
                    'members'    => array('tester'),
                    'emailFlags' => array('test' => array('test'))
                ),
                false,
                array(
                    'emailFlags' => array(
                        'callbackValue' => 'Email flags must be an associative array of scalar values.'
                    )
                )
            ),
            'reject-bad-email-flags-object' => array(
                array(
                    'name'       => 'test',
                    'members'    => array('tester'),
                    'emailFlags' => array('test' => new \StdClass())
                ),
                false,
                array(
                    'emailFlags' => array(
                        'callbackValue' => 'Email flags must be an associative array of scalar values.'
                    )
                )
            ),
            'accept-email-flags-empty-array' => array(
                array(
                    'name'       => 'test',
                    'members'    => array('tester'),
                    'emailFlags' => array()
                ),
            ),
            'accept-email-flags-null' => array(
                array(
                    'name'       => 'test',
                    'members'    => array('tester'),
                    'emailFlags' => null
                ),
            ),
            'accept-email-flags-format-numberstring' => array(
                array(
                    'name'       => 'test',
                    'members'    => array('tester'),
                    'emailFlags' => array('change_email_project_users' => "1")
                )
            ),
            'accept-email-flags-format-int' => array(
                array(
                    'name'       => 'test',
                    'members'    => array('tester'),
                    'emailFlags' => array('change_email_project_users' => 1)
                )
            ),
            'accept-email-flags-format-numberzero' => array(
                array(
                    'name'       => 'test',
                    'members'    => array('tester'),
                    'emailFlags' => array('change_email_project_users' => "0")
                )
            ),
            'accept-email-flags-format-zero' => array(
                array(
                    'name'       => 'test',
                    'members'    => array('tester'),
                    'emailFlags' => array('change_email_project_users' => 0)
                )
            ),
            'reject-tests-string' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'tests'   => 'hoorah'
                ),
                false,
                array('tests' => array('callbackValue' => 'Tests must be an associative array of scalar values.'))
            ),
            'reject-tests-post-body-array' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'tests'   => array('enabled' => true, 'url' => 'http://localhost/', 'postBody' => array('test'))
                ),
                false,
                array('tests' => array('callbackValue' => 'Tests must be an associative array of scalar values.'))
            ),
            'reject-tests-post-format-array' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'tests'   => array('enabled' => true, 'url' => 'http://localhost/', 'postFormat' => array('test'))
                ),
                false,
                array('tests' => array('callbackValue' => 'Tests must be an associative array of scalar values.'))
            ),
            'accept-tests-valid-url' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'tests'   => array('enabled' => true, 'url' => 'http://localhost/')
                )
            ),
            'accept-tests-valid-enabled' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'tests'   => array('enabled' => 1, 'url' => 'http://localhost/')
                )
            ),
            'accept-deploy' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'deploy'  => array('enabled' => '1', 'url' => 'http://localhost/')
                )
            ),
            'accept-deploy2' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'deploy'  => array('enabled' => 1, 'url' => 'http://localhost/')
                )
            ),
            'accept-deploy3' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'deploy'  => array('enabled' => 0, 'url' => 'http://localhost/')
                )
            ),
            'null-deploy' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'deploy'  => null
                )
            ),
            'empty-deploy' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'deploy'  => ''
                )
            ),
            'reject-deploy-bad-format' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'deploy'  => 'derploy'
                ),
                false,
                array(
                    'deploy' => array(
                        'callbackValue' => 'Deployment settings must be an associative array of scalar values.'
                    )
                )
            ),
            'reject-deploy-array-array' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'deploy'  => array(array())
                ),
                false,
                array(
                    'deploy' => array(
                        'callbackValue' => 'Deployment settings must be an associative array of scalar values.'
                    )
                )
            ),
            'reject-deploy-enabled-empty-url' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'deploy'  => array('enabled' => true, 'url' => '')
                ),
                false,
                array(
                    'deploy' => array(
                        'callbackValue' => 'URL for deploy must be provided if deployment is enabled.'
                    )
                )
            ),
            'reject-deploy-bad-url' => array(
                array(
                    'name'    => 'test',
                    'members' => array('tester'),
                    'deploy'  => array('enabled' => true, 'url' => 5.6)
                ),
                false,
                array(
                    'deploy' => array(
                        'callbackValue' => 'URL for deploy must be a string.'
                    )
                )
            ),
        );

        return $data;
    }

    public function testFilterProjectsSubgroups()
    {
        if (!$this->p4->isServerMinVersion('2012.1')) {
            $this->markTestSkipped();
        }

        $newGroup = new Group($this->p4);
        $newGroup->setId('group1');
        $newSubGroup = new Group($this->p4);
        $newSubGroup->setId('subGroupA');
        $newSubGroup->setUsers(array('nonadmin'))->save();
        $newGroup->addSubgroup($newSubGroup)->save();

        // reject subGroupB for not existing
        $this->testFilterProjects(
            array('name' => 'test', 'subgroups' => array('subGroupA', 'subGroupB')),
            false,
            array('subgroups' => array('unknownIds' => 'Unknown group id(s): subGroupB'))
        );
        // reject group1 as an owner
        $this->testFilterProjects(
            array('name' => 'test', 'owners' => array('group1'), 'subgroups' => array('group1')),
            false,
            array('owners' => array('unknownIds' => 'Unknown user id(s): group1'))
        );
        // reject nested array
        $this->testFilterProjects(
            array('name' => 'test', 'subgroups' => array(array('group1'))),
            false,
            array(
                'subgroups' => array(
                    'notFlat'       => 'Array values must not be arrays or objects.'
                )
            )
        );
        // be happy
        $this->testFilterProjects(
            array('name' => 'test', 'subgroups' => array('group1')),
            true,
            array()
        );
        // refuse to implicitly convert string to array and instead spread unhappiness
        $this->testFilterProjects(
            array('name' => 'test', 'subgroups' => 'group1'),
            false,
            array('subgroups' => array('notArray' => 'Invalid type given. Array required.'))
        );
    }
}
