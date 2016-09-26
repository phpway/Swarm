<?php
/**
 * Tests for the activity model.
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ActivityTest\Model;

use P4Test\TestCase;
use P4\Key\Key;
use Activity\Model\Activity;
use Projects\Model\Project;

class ActivityTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                'Zend\Loader\StandardAutoloader' => array(
                    'namespaces' => array(
                        'Activity'    => BASE_PATH . '/module/Activity/src/Activity',
                        'Application' => BASE_PATH . '/module/Application/src/Application',
                        'Projects'    => BASE_PATH . '/module/Projects/src/Projects',
                        'Users'       => BASE_PATH . '/module/Users/src/Users',
                    )
                )
            )
        );
    }

    public function testBasicFunction()
    {
        $model = new Activity($this->p4);
    }

    public function testFetchAllEmpty()
    {
        $this->assertSame(
            array(),
            Activity::fetchAll(array(), $this->p4)->toArray(),
            'expected matching result on empty fetch'
        );
    }

    public function testSaveAndFetch()
    {
        $model = new Activity($this->p4);
        $model->set('test', '1');
        $model->save();
        $model = new Activity($this->p4);
        $model->set('test', '2');
        $model->save();

        $activity = Activity::fetchAll(array(), $this->p4);
        $this->assertSame(
            2,
            count($activity),
            'expected matching number of activity records'
        );

        $this->assertSame(
            2,
            reset($activity)->getId(),
            'expected matching first id'
        );
        $this->assertSame(
            1,
            next($activity)->getId(),
            'expected matching second id'
        );
    }

    public function testDelete()
    {
        $model = new Activity($this->p4);
        $model->set('test',    '1');
        $model->set('type',    'foo');
        $model->set('streams', array('one', 'two'));
        $model->save();

        $result = $this->p4->run('search', array('1001=' . strtoupper(bin2hex('foo'))));
        $this->assertSame(1, count($result->getData()));
        $result = $this->p4->run('search', array('1002=' . strtoupper(bin2hex('one'))));
        $this->assertSame(1, count($result->getData()));
        $result = $this->p4->run('search', array('1002=' . strtoupper(bin2hex('two'))));
        $this->assertSame(1, count($result->getData()));
        $result = $this->p4->run('counters', array('-u'));
        $this->assertSame(2, count($result->getData()));

        $model->delete();

        $result = $this->p4->run('search', array('1001=' . strtoupper(bin2hex('foo'))));
        $this->assertSame(0, count($result->getData()));
        $result = $this->p4->run('search', array('1002=' . strtoupper(bin2hex('one'))));
        $this->assertSame(0, count($result->getData()));
        $result = $this->p4->run('search', array('1002=' . strtoupper(bin2hex('two'))));
        $this->assertSame(0, count($result->getData()));
        $result = $this->p4->run('counters', array('-u'));
        $this->assertSame(1, count($result->getData()));
    }

    public function testFetchAllWithGaps()
    {
        // add an entry, should get id 1
        $model = new Activity($this->p4);
        $model->set('test', '1')
              ->save();

        // increment the count twice without adding anything
        $key = new Key($this->p4);
        $key->setId(Activity::KEY_COUNT);
        $key->increment();
        $key->increment();

        // add what should be entry 4
        $model = new Activity($this->p4);
        $model->set('test', '4')
              ->save();

        // add what should be entry 5
        $model = new Activity($this->p4);
        $model->set('test', '5')
              ->save();

        $result = Activity::fetchAll(array(Activity::FETCH_MAXIMUM => 2), $this->p4);
        $this->assertSame(
            2,
            count($result),
            'expected to get two results'
        );
    }

    public function testEditDeIndexes()
    {
        $streams = array('personal-al', 'user-al', 'project-a');
        $model   = new Activity($this->p4);
        $model->set('test',     '1')
              ->set('type',     'change')
              ->set('streams',  $streams)
              ->addStream('project-b')
              ->save();

        $result = Activity::fetchAll(array('streams' => 'project-b'), $this->p4);
        $this->assertSame(
            1,
            count($result),
            'Expected one result when indexed for project-b'
        );

        $model = Activity::fetch(1, $this->p4);
        $model->set('streams', $streams)
            ->save();

        $this->assertSame(
            count($streams),
            count($model->get('streams')),
            'expected one stream would have been unset'
        );

        $result = Activity::fetchAll(array('streams' => 'project-b'), $this->p4);
        $this->assertSame(
            0,
            count($result),
            'Expected no result after save without project-b'
        );

        $result = Activity::fetchAll(array('streams' => 'project-a'), $this->p4);
        $this->assertSame(
            1,
            count($result),
            'Expected to still get a hit for project-a'
        );
    }

    public function searchProvider()
    {
        return array(
            'by-user-bob'   => array(array('streams' => 'user-bob'),            array('2')),
            'by-type-c'     => array(array('type'    => 'change'),              array('3', '1')),
            'by-type-j'     => array(array('type'    => 'job'),                 array('4', '2')),
            'by-project-a'  => array(array('streams' => 'project-a'),           array('4', '3', '2', '1')),
            'by-project-b'  => array(array('streams' => 'project-b'),           array('3', '2')),
            'by-follower-a' => array(array('streams' => 'personal-al'),         array('4', '3', '2', '1')),
            'by-follower-c' => array(array('streams' => 'personal-charlie'),    array('4', '3')),
            'by-bad-user'   => array(array('streams' => 'personal-invalid'),    array()),
            'by-max'        => array(array('maximum' => '2'),                   array('4', '3')),
            'by-after'      => array(array('after'   => 3),                     array('2', '1')),
            'by-after-1'    => array(array('after'   => 1),                     array()),
            'by-max-after'  => array(array('after'   => 4, 'maximum' => '2'),   array('3', '2')),
            'by-change-0'   => array(array('change'  => 0),                     array()),
            'by-change-1'   => array(array('change'  => 1),                     array('3')),
            'by-change-1'   => array(array('change'  => 1, 'type' => 'job'),    array()),
            'by-f-and-type' => array(array('streams' => 'personal-bob', 'type' => 'change'),    array('3')),
            'by-f-and-max'  => array(array('streams' => 'personal-al',  'maximum' => '2'),      array('4', '3')),
            'by-f-off-max'  => array(array('streams' => 'personal-al', 'after' => 3),           array('2', '1')),
            'by-af-max'     => array(array('after'   => 3, 'maximum' => 1),                     array('2'))
        );
    }

    /**
     * @dataProvider searchProvider
     */
    public function testIndexAndSearch($options, $expected)
    {
        $model = new Activity($this->p4);
        $model->set('test',  '1')
              ->set('type',  'change')
              ->set('change', 2)
              ->addStream('personal-al')->addStream('user-al')
              ->addStream('project-a')
              ->save();

        $model = new Activity($this->p4);
        $model->set('test',  '2')
              ->set('type',  'job')
              ->addStream('personal-bob')->addStream('user-bob')
              ->addStream('project-a')->addStream('project-b')
              ->addStream('personal-al')->addStream('personal-bob')
              ->save();

        $model = new Activity($this->p4);
        $model->set('test',  '3')
              ->set('type',  'change')
              ->set('change', 1)
              ->addStream('personal-charlie')->addStream('user-charlie')
              ->addStream('project-a')->addStream('project-b')->addStream('project-c')
              ->addStream('personal-al')->addStream('personal-bob')->addStream('personal-charlie')
              ->save();

        $model = new Activity($this->p4);
        $model->set('test',  '4')
              ->set('type',  'job')
              ->addStream('personal-david')->addStream('user-david')
              ->addStream('project-a')->addStream('project-c')
              ->addStream('personal-al')->addStream('personal-charlie')->addStream('personal-david')
              ->save();

        $activity = Activity::fetchAll($options, $this->p4);
        $values   = $ids = array();
        foreach ($activity as $model) {
            $ids[]    = $model->getId();
            $values[] = $model->get('test');
        }

        $this->assertSame(
            (array) $expected,
            $values,
            'expected matching values and order'
        );

        // based on the expected value; figure out the anticipated IDs
        $expectedIds = array();
        foreach ($expected as $value) {
            $expectedIds[] = (int) $value;
        }

        $this->assertSame(
            $expectedIds,
            $ids,
            'expected ids to match'
        );
    }

    public function testGetSetProjects()
    {
        // create some projects to test with
        $project = new Project($this->p4);
        $project->set(array('id' => 'foo',  'members' => array('foo', 'bar'), 'deleted' => true))->save();
        $project->set(array('id' => 'biz',  'members' => array('foo', 'bar'), 'deleted' => false))->save();
        $project->set(array('id' => 'bang', 'members' => array('foo', 'bar'), 'deleted' => false))->save();

        $activity = new Activity;
        $activity->setProjects(
            array(
                 'foo' => 'bar',
                 'biz',
                 'bang' => array('one', 'two', 'three', 'three'),
                 'foo',
                 'bogus' => array('a', 'b', 'c')
            )
        );

        // ensure only existing projects are returned by getProjects()
        $this->assertSame(
            array('biz' => array(), 'bang' => array('one', 'two', 'three')),
            $activity->getProjects(),
            'expected normalized output to match'
        );
    }
}
