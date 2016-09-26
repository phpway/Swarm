<?php
/**
 * Tests for the project model.
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ProjectsTest\Model;

use Groups\Model\Group;
use P4\File\File;
use P4\Log\Logger;
use P4\Spec\Change;
use P4\Spec\Client;
use P4\Spec\Job;
use P4Test\TestCase;
use Projects\Model\Project;
use Record\Cache\Cache;
use Zend\Log\Logger as ZendLogger;
use Zend\Log\Writer\Mock as MockLog;

class ProjectTest extends TestCase
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
                        'Projects' => BASE_PATH . '/module/Projects/src/Projects',
                        'Users'    => BASE_PATH . '/module/Users/src/Users',
                    )
                )
            )
        );
    }

    public function testBasicFunction()
    {
        $model = new Project($this->p4);
    }

    public function testFetchAllEmpty()
    {
        $this->assertSame(
            array(),
            Project::fetchAll(array(), $this->p4)->toArray(),
            'expected matching result on empty fetch'
        );
    }

    public function testFetchAllByMember()
    {
        // setup cache
        $cache = new Cache($this->p4);
        $cache->setCacheDir(DATA_PATH . '/cache');
        $this->p4->setService('cache', $cache);

        // save several projects to test with
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'        => 'p1',
                'members'   => array('x', 'y')
            )
        )->save();
        $project->set(
            array(
                'id'        => 'p2',
                'members'   => array('a', 'b', 'c', 'x')
            )
        )->save();
        $project->set(
            array(
                'id'        => 'p3',
                'members'   => array('yy', 'z', 'a')
            )
        )->save();

        // verify FETCH_BY_MEMBER option functionality
        $models = Project::fetchAll(array(Project::FETCH_BY_MEMBER => 'a'), $this->p4);
        $this->assertSame(
            array('p2', 'p3'),
            $models->invoke('getId')
        );

        $models = Project::fetchAll(array(Project::FETCH_BY_MEMBER => 'y'), $this->p4);
        $this->assertSame(
            array('p1'),
            $models->invoke('getId')
        );
    }

    /**
     * Test fetchAll with options and cache.
     *
     * @dataProvider fetchAllOptionsProvider
     */
    public function testFetchAllWithOptionsWithCache($modelsData, $options, $expectedProjects, $exception)
    {
        // setup cache
        $cache = new Cache($this->p4);
        $cache->setCacheDir(DATA_PATH . '/cache');
        $this->p4->setService('cache', $cache);

        // create project records
        foreach ($modelsData as $values) {
            $model = new Project($this->p4);
            $model->set($values)
                  ->save();
        }

        // run fetchAll with provided options and compare result with expected
        // run every test twice to test the cache - at the first run the cache might be empty
        for ($i = 0; $i < 2; $i++) {
            $thrownException = null;
            try {
                $result = Project::fetchAll($options, $this->p4);
            } catch (\Exception $e) {
                $thrownException = $e;
            }

            if ($exception) {
                // check exception if it was expected
                $this->assertNotNull($thrownException);
                $this->assertSame(
                    $exception[0],
                    get_class($thrownException)
                );
                $this->assertSame(
                    $exception[1],
                    $thrownException->getMessage()
                );
            } else {
                // verify that no exception was thrown
                $this->assertTrue(is_null($thrownException));

                // compare result with expected projects (sort them before - we are comparing values, not their order)
                $returnedProjects = $result->invoke('getId');
                sort($expectedProjects);
                sort($returnedProjects);

                $this->assertSame(
                    $expectedProjects,
                    $returnedProjects
                );
            }
        }
    }

    public function fetchAllOptionsProvider()
    {
        // create few project records to test with
        $modelsData = array(
            array(
                'id'          => 'prj1',
                'name'        => 'project a',
                'members'     => array('foo', 'bar'),
            ),
            array(
                'id'          => 'prj2',
                'name'        => 'project b',
                'members'     => array('foo', 'baz', 'xyz'),
            ),
            array(
                'id'          => 'prj3',
                'name'        => 'project x',
                'members'     => array('bar', 'xyz', 'abc'),
            ),
            array(
                'id'          => 'prj4',
                'name'        => 'project y',
                'members'     => array('abc', 'bar', 'baz'),
            ),
            array(
                'id'          => 'prj5',
                'name'        => 'project c',
                'members'     => array('foo', 'xyz', 'abc'),
            ),
            array(
                'id'          => 'prj6',
                'name'        => 'project q',
                'members'     => array('foo'),
                'deleted'     => true
            ),
            array(
                'id'          => 'prj7',
                'name'        => 'project w',
                'members'     => array('abc'),
                'deleted'     => true
            )
        );

        return array(
            array(
                $modelsData,
                array(),
                array('prj1', 'prj2', 'prj3', 'prj4', 'prj5'),
                null
            ),
            array(
                $modelsData,
                array('ids' => null),
                array('prj1', 'prj2', 'prj3', 'prj4', 'prj5'),
                null
            ),
            array(
                $modelsData,
                array('ids' => array()),
                array(),
                null
            ),
            array(
                $modelsData,
                array('ids' => array('prj2', 'prj5', 'prj1')),
                array('prj1', 'prj2', 'prj5'),
                null
            ),
            array(
                $modelsData,
                array('ids' => array('prj2', 'prj5', 'prj1'), 'noCache' => true),
                array('prj1', 'prj2', 'prj5'),
                null
            ),
            array(
                $modelsData,
                array('member' => 'foo'),
                array('prj1', 'prj2', 'prj5'),
                null
            ),
            array(
                $modelsData,
                array('noCache' => true),
                array('prj1', 'prj2', 'prj3', 'prj4', 'prj5'),
                null
            ),
            array(
                $modelsData,
                array('includeDeleted' => true),
                array('prj1', 'prj2', 'prj3', 'prj4', 'prj5', 'prj6', 'prj7'),
                null
            ),
            array(
                $modelsData,
                array('member' => 'foo', 'includeDeleted' => true),
                array('prj1', 'prj2', 'prj5', 'prj6'),
                null
            ),
            array(
                $modelsData,
                array('ids' => array('prj1', 'prj6', 'prj7')),
                array('prj1'),
                null
            ),
            array(
                $modelsData,
                array('ids' => array('prj1', 'prj6', 'prj7'), 'includeDeleted' => true),
                array('prj1', 'prj6', 'prj7'),
                null
            ),
            array(
                $modelsData,
                array('ids' => array('prj-noexist'), 'includeDeleted' => true),
                array(),
                null
            ),
            array(
                $modelsData,
                array('search' => '123=ABC'),
                null,
                array(
                    'InvalidArgumentException',
                    "Following option(s) are not valid for fetching projects: search."
                )
            ),
            array(
                $modelsData,
                array('keywords' => 'foo bar baz'),
                null,
                array(
                    'InvalidArgumentException',
                    "Following option(s) are not valid for fetching projects: keywords."
                )
            ),
            array(
                $modelsData,
                array('maximum' => 3),
                null,
                array(
                    'InvalidArgumentException',
                    "Following option(s) are not valid for fetching projects: maximum."
                )
            ),
            array(
                $modelsData,
                array('totalCount' => true),
                null,
                array(
                    'InvalidArgumentException',
                    "Following option(s) are not valid for fetching projects: totalCount."
                )
            ),
            array(
                $modelsData,
                array('after' => 'prj1', 'ids' => array('prj3', 'prj5')),
                null,
                array(
                    'InvalidArgumentException',
                    "Following option(s) are not valid for fetching projects: after."
                )
            ),
            array(
                $modelsData,
                array('totalCount' => true, 'ids' => array('prj1', 'prj2'), 'after' => 'prj1'),
                null,
                array(
                    'InvalidArgumentException',
                    "Following option(s) are not valid for fetching projects: totalCount, after."
                )
            ),
            array(
                $modelsData,
                array('member' => 'foo', 'flagMember' => 'bar'),
                null,
                array(
                    'InvalidArgumentException',
                    "Following option(s) are not valid for fetching projects: flagMember."
                )
            ),
        );
    }

    public function testSaveAndFetch()
    {
        $canAdminsManageGroups = $this->p4->isServerMinVersion('2012.1');
        $expected              = array(
            'id'            => 'project1',
            'name'          => 'you get a name in death',
            'description'   => 'test1!',
            'members'       => array('bob', 'nobobs'),
            'subgroups'     => $canAdminsManageGroups ? array('baz') : array(),
            'owners'        => array('foo', 'bar'),
            'branches'      => array(
                array(
                    'id'            => 'main',
                    'name'          => 'Main',
                    'paths'         => array('//depot/foo/...'),
                    'moderators'    => array('foo', 'bar')
                )
            ),
            'jobview'       => 'status=open type=foo',
            'tests'         => array(
                'enabled'   => true,
                'url'       => 'http://test-server/build?change={change}'
            ),
            'deploy'        => array(
                'enabled'   => true,
                'url'       => 'http://test-server/deploy?change={change}'
            ),
            'emailFlags'    => array(),
            'deleted'       => false,
            'private'       => false
        );

        $values = $expected;
        if (!$canAdminsManageGroups) {
            unset($values['subgroups']);
        }
        $model = new Project($this->p4);
        $model->set($values);
        $model->save();

        $project = Project::fetch('project1', $this->p4);

        // 'tests' and 'deploy' are hidden fields, we don't expect them to be returned via get()
        unset($expected['tests'], $expected['deploy']);
        $this->assertSame(
            $expected,
            $project->get(),
            'expected matching values!'
        );

        // ensure that 'tests' and 'deploy' values can be retrieved
        $this->assertSame(
            $values['tests'],
            $project->getTests(),
            'expected tests value'
        );
        $this->assertSame(
            $values['deploy'],
            $project->getDeploy(),
            'expected tests value'
        );

        // delete project (via 'deleted' field) and verify its not present in results from fetch/exists
        $project->setDeleted(true)->save();
        $this->assertFalse(Project::exists('project1', $this->p4));

        // all done if we don't use groups
        if (!$canAdminsManageGroups) {
            return;
        }

        $group = Group::fetch('swarm-project-project1', $this->p4);
        $this->assertSame(array('bob', 'nobobs'), $group->getUsers());
        $this->assertSame(array('baz'), $group->getSubgroups());
    }

    public function testGetAffectedByChange()
    {
        $values = array(
            'id'            => 'project1',
            'name'          => 'you get a name in death',
            'description'   => 'test1!',
            'members'       => array('bob', 'nobobs'),
            'branches'      => array(
                array(
                    'id'    => 'main',
                    'name'  => 'Main',
                    'paths' => '//depot/m...'
                ),
                array(
                    'id'    => 'footxt',
                    'name'  => 'FooTxt',
                    'paths' => '//depot/main/foo.txt'
                )
            ),
            'jobview'       => 'status=open type=foo',
            'tests'         => '/path/to/script.sh %arg%'
        );
        $model  = new Project($this->p4);
        $model->set($values);
        $model->save();

        $values = array(
            'id'            => 'project2',
            'name'          => 'Robert Paulson',
            'description'   => 'test2!',
            'members'       => array('robert', 'norobs'),
            'branches'      => array(
                array(
                    'id'    => 'halfmatch',
                    'name'  => 'HalfMatch',
                    'paths' => array('//depot/main/watermelon/...', '//depot/main/f*')
                ),
                array(
                    'id'    => 'nomatch',
                    'name'  => 'NoMatch',
                    'paths' => '//depot/no-matches/...'
                )
            ),
            'jobview'       => 'status=open type=bar',
            'tests'         => '/path/to/script.sh %arg%'
        );
        $model  = new Project($this->p4);
        $model->set($values);
        $model->save();

        // test out a simple change with one file
        $file = new File($this->p4);
        $file->setFilespec('//depot/main/foo.txt')
             ->setLocalContents('')
             ->open()
             ->submit('match');

        $this->assertSame(
            array('project1' => array('main', 'footxt'), 'project2' => array('halfmatch')),
            Project::getAffectedByChange($file->getChange(), $this->p4),
            'simple change with one file'
        );


        // test out a change with two files (and a shallow common 'path')
        $change = new Change($this->p4);
        $change->setDescription('i am a change!')->save();
        $file = new File($this->p4);
        $file->setFilespec('//depot/main/file1.txt')
            ->setLocalContents('')
            ->open($change->getId());
        $file2 = new File($this->p4);
        $file2->setFilespec('//depot/main2/file2.txt')
              ->setLocalContents('')
              ->open($change->getId());
        $change->submit();

        $this->assertSame(
            array('project1' => array('main'), 'project2' => array('halfmatch')),
            Project::getAffectedByChange($change, $this->p4),
            'shallow path change with two files'
        );


        // verify our path optimization screens out clearly un-applicable branches
        // we verify this by peeking at the log to ensure p4 describe doesn't run
        $file = new File($this->p4);
        $file->setFilespec('//depot/dev/foo.txt')
            ->setLocalContents('')
            ->open()
            ->submit('no-match');
        $change = $file->getChange();

        $original = Logger::hasLogger() ? Logger::getLogger() : null;
        $logger   = new ZendLogger;
        $mock     = new MockLog;
        $logger->addWriter($mock);
        Logger::setLogger($logger);

        $this->assertSame(
            array(),
            Project::getAffectedByChange($change, $this->p4),
            "simple change that shouldn't match nutin"
        );

        foreach ($mock->events as $event) {
            $this->assertFalse(
                strpos($event['message'], 'describe'),
                'hit a describe command in log; oh oh: ' . $event['message']
            );
        }

        // restore original logger if there is one.
        Logger::setLogger($original);
    }

    public function testGetAffectedByJob()
    {
        $values = array(
            'id'            => 'project1',
            'name'          => 'you get a name in death',
            'description'   => 'test1!',
            'members'       => array('bob', 'nobobs'),
            'branches'      => array(
                'main'      => array('//depot/main/...')
            ),
            'jobview'       => 'status=oPen Job=job-open',
            'tests'         => '/path/to/script.sh %arg%'
        );
        $model  = new Project($this->p4);
        $model->set($values);
        $model->save();

        $values = array(
            'id'            => 'project2',
            'name'          => 'Robert Paulson',
            'description'   => 'test2!',
            'members'       => array('robert', 'norobs'),
            'branches'      => array(
                'dev'       => array('//depot/dev/...')
            ),
            'jobview'       => 'Status=op*',
            'tests'         => '/path/to/script.sh %arg%'
        );
        $model  = new Project($this->p4);
        $model->set($values);
        $model->save();

        $values = array(
            'id'            => 'project-bad',
            'name'          => 'Bad',
            'description'   => 'test3!',
            'members'       => array('memberino'),
            'branches'      => array(
                'dev'       => array('//depot/test/...')
            ),
            'jobview'       => 'missing=field',
            'tests'         => '/path/to/script.sh %arg%'
        );
        $model  = new Project($this->p4);
        $model->set($values);
        $model->save();

        // this project has an invalid job view so it should never show up
        $values = array(
            'id'            => 'project-invalid-view',
            'name'          => 'Invalid View',
            'description'   => 'test4!',
            'members'       => array('memberino'),
            'branches'      => array('dev' => array('//nomatch/...')),
            'jobview'       => 'foozle(woozle)',
            'tests'         => '/path/to/script.sh %arg%'
        );
        $model  = new Project($this->p4);
        $model->set($values);
        $model->save();

        // this project has a blank job view so it too should never show
        $values = array(
            'id'            => 'project-empty-view',
            'name'          => 'Empty View',
            'description'   => 'test5!',
            'members'       => array('memberino'),
            'branches'      => array('dev' => array('//nomatch/...')),
            'jobview'       => '',
            'tests'         => '/path/to/script.sh %arg%'
        );
        $model  = new Project($this->p4);
        $model->set($values);
        $model->save();

        // test out a simple job
        $job = new Job($this->p4);
        $job->setId('job-open')
            ->setDescription('test open')
            ->setStatus('open')
            ->save();
        $job = Job::fetch('job-open', $this->p4);

        $this->assertSame(
            array('project1', 'project2'),
            Project::getAffectedByJob($job, $this->p4),
            'open job'
        );


        // test out a second simple job
        $job = new Job($this->p4);
        $job->setId('job-open2')
            ->setDescription('test open2')
            ->setStatus('open')
            ->save();
        $job = Job::fetch('job-open2', $this->p4);

        $this->assertSame(
            array('project2'),
            Project::getAffectedByJob($job, $this->p4),
            'open job2'
        );
    }

    /**
     * Test member caching.
     */
    public function testMembersCache()
    {
        // no member caching if server doesn't support admin groups.
        if (!$this->p4->isServerMinVersion('2012.1')) {
            $this->markTestSkipped('No member caching, server too old.');
        }

        $cache = new Cache($this->p4);
        $cache->setCacheDir(DATA_PATH . '/cache');
        $this->p4->setService('cache', $cache);

        // make a project to test with
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'        => 'p1',
                'members'   => array('x', 'y')
            )
        )->save();

        // group cache should be empty at the moment
        $this->assertNull($cache->getItem('groups'));

        // fetching a project and calling getMembers() should prime the cache
        $project = Project::fetch('p1', $this->p4);
        $members = $project->getMembers();
        $this->assertSame(array('x', 'y'), $members);
        $groups = $cache->getItem('groups');
        $this->assertTrue(isset($groups['swarm-project-p1']['Users']));
        $this->assertSame(array('x', 'y'), $groups['swarm-project-p1']['Users']);

        // subsequent calls should run no commands (as we use cache for both groups and projects)
        // - verify this by peeking at the log
        $original = Logger::hasLogger() ? Logger::getLogger() : null;
        $logger   = new ZendLogger;
        $mock     = new MockLog;
        $logger->addWriter($mock);
        Logger::setLogger($logger);

        $project = Project::fetch('p1', $this->p4);
        $members = $project->getMembers();
        $this->assertSame(array('x', 'y'), $members);
        $this->assertSame(0, count($mock->events));

        // verify cache is NOT invalidated on non-member change.
        $project->setDescription('test')->save();
        $this->assertNotNull($cache->getItem('groups'));

        // verify cache IS invalidated on member change.
        $project->setMembers(array('x', 'y', 'z'))->save();
        $this->assertNull($cache->getItem('groups'));

        // restore original logger if there is one.
        Logger::setLogger($original);
    }

    /**
     * Test projects caching.
     */
    public function testProjectsCache()
    {
        // setup a cache for caching projects
        $cache = new Cache($this->p4);
        $cache->setCacheDir(DATA_PATH . '/cache');
        $this->p4->setService('cache', $cache);

        // make few projects to test with
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'        => 'p1',
                'members'   => array('a', 'b')
            )
        )->save();
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'        => 'p2',
                'members'   => array('a', 'x', 'y')
            )
        )->save();

        // projects cache should be empty at the moment
        $this->assertNull($cache->getItem('projects'));

        // fetch all projects and ensure cache has been populated
        Project::fetchAll(array(), $this->p4);
        $this->assertNotNull($cache->getItem('projects'));

        // verify that subsequest calls to fetchAll() run no commands
        // - verify by peeking into a log
        $original = Logger::hasLogger() ? Logger::getLogger() : null;
        $logger   = new ZendLogger;
        $mock     = new MockLog;
        $logger->addWriter($mock);
        Logger::setLogger($logger);

        Project::fetchAll(array(), $this->p4);
        $this->assertSame(0, count($mock->events));

        // calling fetch() should also use the cache
        $p = Project::fetch('p1', $this->p4);
        $this->assertSame(0, count($mock->events));

        // saving a project should invalidate a cache
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'        => 'p3',
                'members'   => array('y')
            )
        )->save();

        $this->assertNull($cache->getItem('projects'));
    }

    public function testGetClient()
    {
        $values = array(
            'id'            => 'project1',
            'name'          => 'you get a name in death',
            'description'   => 'test1!',
            'members'       => array('bob', 'nobobs'),
            'branches'      => array(
                array(
                    'id'    => 'main',
                    'name'  => 'Main',
                    'paths' => array('//depot/foo/...')
                )
            ),
            'jobview'       => 'status=open type=foo',
            'tests'         => array(
                'enabled'   => true,
                'url'       => 'http://test-server/build?change={change}'
            ),
            'deploy'        => array(
                'enabled'   => true,
                'url'       => 'http://test-server/deploy?change={change}'
            )
        );

        $model = new Project($this->p4);
        $model->set($values);
        $model->save();

        $project = Project::fetch('project1', $this->p4);
        $this->assertSame(
            "swarm-project-project1",
            $project->getClient(),
            'expected matching id!'
        );

        $client = Client::fetch('swarm-project-project1', $this->p4);
        $this->assertSame(
            array(
                array(
                    'depot' => '+//depot/foo/...',
                    'client' => '//swarm-project-project1/main/...'
                )
            ),
            $client->get('View')
        );
    }

    public function testMembers()
    {
        // create a project to test with
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'        => 'p1',
                'members'   => array('x', 'y', 'z')
            )
        )->save();

        // ensure that when we fetch the project and save it, members are not lost
        Project::fetch('p1', $this->p4)->save();
        $this->assertSame(3, count(Project::fetch('p1', $this->p4)->getMembers()));

        // ensure removing members from a project works as well
        Project::fetch('p1', $this->p4)->setMembers(null)->save();
        $this->assertSame(0, count(Project::fetch('p1', $this->p4)->getMembers()));
    }

    public function testIsMember()
    {
        // setup cache
        $cache = new Cache($this->p4);
        $cache->setCacheDir(DATA_PATH . '/cache');
        $this->p4->setService('cache', $cache);

        $project = new Project($this->p4);
        $project->set(
            array(
                'id' => 'p1'
            )
        )->save();
        $project->set(
            array(
                'id' => 'p2',
                'members' => array('foo', 'bar')
            )
        )->save();

        $p1 = Project::fetch('p1', $this->p4);
        $p2 = Project::fetch('p2', $this->p4);

        // p1 is a project with no members
        $this->assertFalse($p1->isMember('foo'));

        // p2 has members foo and bar
        $this->assertTrue($p2->isMember('foo'));
        $this->assertFalse($p2->isMember('baz'));
    }

    public function testOwners()
    {
        // create a project to test with
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'        => 'p1',
                'members'   => array('x'),
                'owners'    => array('a', 'b', 'c')
            )
        )->save();

        $project = Project::fetch('p1', $this->p4);
        $this->assertSame(array('a', 'b', 'c'), $project->getOwners());
        $this->assertSame(true, $project->hasOwners());

        // ensure that when we fetch the project and save it, owners are not lost
        Project::fetch('p1', $this->p4)->save();
        $this->assertSame(3, count(Project::fetch('p1', $this->p4)->getOwners()));

        // ensure removing owners from a project works as well
        Project::fetch('p1', $this->p4)->set('owners', null)->save();
        $this->assertSame(0, count(Project::fetch('p1', $this->p4)->getOwners()));
        $this->assertSame(false, Project::fetch('p1', $this->p4)->hasOwners());
    }
}
