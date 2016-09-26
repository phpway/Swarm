<?php
/**
 * Tests for the review model.
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ReviewsTest\Model;

use P4\File\File;
use P4\Spec\Change;
use P4\Spec\Client;
use P4\Spec\Stream;
use P4Test\TestCase;
use Projects\Model\Project;
use Reviews\Model\Review;

class ReviewTest extends TestCase
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
                        'Reviews'     => BASE_PATH . '/module/Reviews/src/Reviews',
                        'Projects'    => BASE_PATH . '/module/Projects/src/Projects',
                        'Users'       => BASE_PATH . '/module/Users/src/Users',
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
        new Review($this->p4);
    }

    /**
     * Test createFromChange() method.
     */
    public function testCreateFromChange()
    {
        // create a change
        $file = new File;
        $file->setFilespec('//depot/test.txt')
             ->open()
             ->setLocalContents('xyz123')
             ->submit('change description');

        $review = Review::createFromChange('1', $this->p4);

        // verify that expected values have been set
        $this->assertSame(null,     $review->getId());
        $this->assertSame(array(1), $review->get('changes'));
        $this->assertSame('tester', $review->get('author'));
        $this->assertTrue(strpos($review->get('description'), 'change description') === 0);
    }

    public function testUpdateFromChangeOtherOwner()
    {
        $file = new File;
        $file->setFilespec('//depot/test1')->open()->setLocalContents('abc');
        $change = new Change;
        $change->addFile($file)->setDescription('Just a starting change')->save();
        $this->p4->run('shelve', array('-c', $change->getId()));

        // create the review and verify the owner is who we expect
        $review = Review::createFromChange('1', $this->p4)->save()->updateFromChange('1')->save();
        $this->assertSame('tester', Change::fetch($review->getId())->getUser());

        // verify that expected values have been set
        $this->assertSame(2,            $review->getId());
        $this->assertSame(array(1, 3),  $review->get('changes'));
        $this->assertSame('tester',     $review->get('author'));
        $this->assertSame(1,            count($review->getVersions()));

        // swap the authorative review change owner to cause a potential issue
        Change::fetch(2)->setUser('super')->save(true);
        $this->assertSame('super', Change::fetch(2)->getUser());

        // do the update (confirming it doesn't explode)
        $file->setLocalContents('def');
        $change = new Change;
        $change->addFile($file)->setDescription('Just a contributing change')->save();
        $this->p4->run('shelve', array('-c', $change->getId()));
        $review = Review::fetch(2, $this->p4);
        $review->updateFromChange($change)->save();

        // verify the update worked and the user switched back on the authorative change
        $this->assertSame('tester', Change::fetch($review->getId())->getUser());
        $this->assertSame(2,        count($review->getVersions()));
    }

    /**
     * Test addCommit method.
     */
    public function testAddCommit()
    {
        // create a change
        $file = new File;
        $file->setFilespec('//depot/test.txt')
            ->open()
            ->setLocalContents('xyz123')
            ->submit('change description');

        // test a post commit review starting condition
        $review = Review::createFromChange('1', $this->p4);
        $review->save();
        $this->assertSame(array(1), $review->getChanges());
        $this->assertSame(array(1), $review->getCommits());

        $review->addCommit(1234);
        $this->assertSame(array(1, 1234), $review->getChanges());
        $this->assertSame(array(1, 1234), $review->getCommits());
    }

    /**
     * Test fetchAll() with no options.
     */
    public function testFetchAllEmpty()
    {
        $this->assertSame(
            array(),
            Review::fetchAll(array(), $this->p4)->toArray(),
            'expected matching result on empty fetch'
        );
    }

    /**
     * Test fetchAll() with options.
     *
     * @dataProvider fetchOptionsProvider
     */
    public function testFetchAllWithOptions($modelsData, $options, $expected)
    {
        // save models
        foreach ($modelsData as $values) {
            $model = new Review($this->p4);
            $model->set($values)->save();
        }

        // fetch all with provided options
        $models = Review::fetchAll($options, $this->p4);
        $result = array_map('intval', $models->invoke('getId'));

        // sort arrays - we compare values, not the order
        sort($result);
        sort($expected);

        $this->assertSame($expected, $result);
    }

    /**
     * Test fetchAll() approved and committed
     */
    public function testFetchAllApprovedAndCommitted()
    {
        $model = new Review($this->p4);
        $model->setId(1)->set('state', 'approved')->set('pending', 1)->save();
        $model = new Review($this->p4);
        $model->setId(2)->set('state', 'approved')->set('pending', 0)->save();
        $model = new Review($this->p4);
        $model->setId(3)->set('state', 'approved')->set('pending', 1)->save();
        $model = new Review($this->p4);
        $model->setId(4)->set('state', 'approved')->set('pending', 0)->save();

        $models = Review::fetchAll(array(Review::FETCH_BY_STATE => 'approved:isPending'), $this->p4);
        $this->assertSame(
            array(3, 1),
            $models->invoke('getId'),
            'expected matching list of pending entries'
        );

        $models = Review::fetchAll(array(Review::FETCH_BY_STATE => 'approved:notPending'), $this->p4);
        $this->assertSame(
            array(4, 2),
            $models->invoke('getId'),
            'expected matching list of not pending entries'
        );
    }

    /**
     * Test save() and fetch() methods.
     */
    public function testSaveAndFetch()
    {
        $now    = time();
        $values = array(
            'id'            => '1',
            'changes'       => array(1, 2),
            'author'        => 'foo',
            'participants'  => array('foo', 'bar'),
            'description'   => 'some description',
            'created'       => $now,
            'projects'      => array('prj1', 'prj2' => array('foo', 'bar'), 'prj3'),
            'state'         => 'approved',
            'testStatus'    => 'pass'
        );

        $model = new Review($this->p4);
        $model->set($values);
        $model->save();

        // create projects
        $project = new Project($this->p4);
        $project->set(array('id' => 'prj1',  'members' => array('foo')))->save();
        $project->set(array('id' => 'prj2',  'members' => array('foo')))->save();

        // fetch and verify
        $review           = Review::fetch('1', $this->p4);
        $expectedProjects = array(
            'prj1' => array(),
            'prj2' => array('foo', 'bar')
        );
        $this->assertSame(1,                    $review->getId());
        $this->assertSame(array(1, 2),          $review->get('changes'));
        $this->assertSame('foo',                $review->get('author'));
        $this->assertSame(array('bar'),         $review->getReviewers());
        $this->assertSame(1,                    $review->get('hasReviewer'));
        $this->assertTrue(strpos($review->get('description'), 'some description') === 0);
        $this->assertSame($now,                 $review->get('created'));
        $this->assertTrue($review->get('updated') !== null);
        $this->assertSame($expectedProjects,    $review->get('projects'));
        $this->assertSame('approved',           $review->get('state'));
        $this->assertSame('pass',               $review->get('testStatus'));
    }

    /**
     * Provider for testing fetchAll() method with options.
     */
    public function fetchOptionsProvider()
    {
        // provide data for review models we will test functionality on
        $modelsData = array(
            array(
                'id'                => 1,
                'participants'      => array('foo'),
                'state'             => 'a',
                'testStatus'        => 'x'
            ),
            array(
                'id'                => 2,
                'state'             => 'a',
                'testStatus'        => 'x'
            ),
            array(
                'id'                => 3,
                'participants'      => array('foo'),
                'state'             => 'b',
                'testStatus'        => 'x'
            ),
            array(
                'id'                => 4,
                'state'             => 'b',
                'description'       => 'foo test',
                'testStatus'        => 'z'
            ),
            array(
                'id'                => 5,
                'participants'      => array('bar'),
                'state'             => 'c',
                'testStatus'        => 'x'
            ),
            array(
                'id'                => 6,
                'state'             => 'c',
                'testStatus'        => 'y'
            ),
            array(
                'id'                => 7,
                'state'             => 'c',
                'testStatus'        => 'y'
            ),
            array(
                'id'                => 8,
                'participants'      => array('foo'),
                'state'             => 'd',
                'testStatus'        => 'y'
            ),
        );

        // create list with tests
        return array(
            // single option
            array(
                $modelsData,
                array('state' => 'a'),
                array(1, 2)
            ),
            array(
                $modelsData,
                array('state' => array('a', 'c')),
                array(1, 2, 5, 6, 7)
            ),
            array(
                $modelsData,
                array('state' => 'd'),
                array(8)
            ),
            array(
                $modelsData,
                array('hasReviewer' => '1'),
                array(1, 3, 5, 8)
            ),
            array(
                $modelsData,
                array('hasReviewer' => '0'),
                array(2, 4, 6, 7)
            ),
            array(
                $modelsData,
                array('testStatus' => 'x'),
                array(1, 2, 3, 5)
            ),
            array(
                $modelsData,
                array('testStatus' => array('y', 'z')),
                array(4, 6, 7, 8)
            ),
            // multiple options
            array(
                $modelsData,
                array('state' => 'b', 'testStatus' => array('x', 'y')),
                array(3)
            ),
            array(
                $modelsData,
                array('state' => array('b', 'c', 'd'), 'hasReviewer' => '1'),
                array(3, 5, 8)
            ),
            array(
                $modelsData,
                array('state' => array('a', 'b', 'd'), 'hasReviewer' => '1', 'testStatus' => 'x'),
                array(1, 3)
            ),
            array(
                $modelsData,
                array('participants' => 'tester'),
                array()
            ),
            array(
                $modelsData,
                array('participants' => 'foo'),
                array(1, 3, 8)
            ),
        );
    }

    public function testTokenHidden()
    {
        $now    = time();
        $values = array(
            'id'            => '1',
            'changes'       => array(1, 2),
            'author'        => 'foo',
            'description'   => 'some description',
            'created'       => $now,
            'projects'      => array('prj1', 'prj2' => array('foo', 'bar')),
            'state'         => 'approved',
            'testStatus'    => 'pass'
        );

        $model = new Review($this->p4);
        $model->set($values);
        $model->save();

        // verify token isn't in result of 'get' for all values
        $values = $model->get();
        $this->assertFalse(isset($values['token']));
        $this->assertSame('approved', $values['state']);

        // verify tokens toArray has same behaviour as get()
        $this->assertSame($model->get(), $model->toArray());

        // verify a token was actually there to be excluded
        // expected format is: FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF
        $this->assertSame(
            1,
            preg_match('/^[A-Z0-9]{8}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{12}\.v[0-9]+$/', $model->getToken()),
            $model->getToken()
        );
    }

    /**
     * Verify old review records are upgraded correctly on save.
     */
    public function testUpgrade()
    {
        // create a sample review record as it would have been written at upgrade-level 0
        // this test focuses on the fields we care about:
        //  - reviewer    (goes away)
        //  - assigned    (becomes hasReviewer)
        //  - description (becomes case-insensitive, punctuation is trimmed and words are split on ',' and '.')
        $id     = 'swarm-review-fffffffe';
        $values = array(
            'author'       => 'coder',
            'reviewer'     => 'jdoe',
            'assigned'     => 1,
            'participants' => array('coder', 'jdoe'),
            'description'  => 'THIS is, a (test.of) "description" indeXing.'
        );
        $this->p4->run(
            'index',
            array('-a', '1302', $id),
            strtoupper(bin2hex($values['author']))
        );
        $this->p4->run(
            'index',
            array('-a', '1304', $id),
            implode(' ', array_map('strtoupper', array_map('bin2hex', $values['participants'])))
        );
        $this->p4->run(
            'index',
            array('-a', '1305', $id),
            strtoupper(bin2hex($values['assigned']))
        );
        $this->p4->run(
            'index',
            array('-a', '1306', $id),
            '54484953 6973 61 28746573742E6F6629 276465736372697074696F6E27 696E646558696E672E'
        );
        $this->p4->run(
            'counter',
            array('-u', $id, json_encode($values))
        );

        // verify we can fetch it by id.
        $review = Review::fetch(1, $this->p4);
        $this->assertSame(1, $review->getId());

        // verify we can fetch it by scanning.
        $reviews = Review::fetchAll(array(), $this->p4);
        $this->assertSame(1, $reviews->count());
        $this->assertSame(1, $reviews->first()->getId());

        // verify we can search for it using keywords where case is not an issue.
        $reviews = Review::fetchAll(array(Review::FETCH_BY_KEYWORDS => 'is'), $this->p4);
        $this->assertSame(1, $reviews->count());

        // verify we CANNOT find it using keywords where case IS an issue.
        $reviews = Review::fetchAll(array(Review::FETCH_BY_KEYWORDS => 'indeXing'), $this->p4);
        $this->assertSame(0, $reviews->count());

        // verify we CANNOT find this review by keywords that have leading/trailing punctuation
        $reviews = Review::fetchAll(array(Review::FETCH_BY_KEYWORDS => 'description'), $this->p4);
        $this->assertSame(0, $reviews->count());

        // ok, let's upgrade this thing (simply fetch and save).
        $review = Review::fetch(1, $this->p4);
        $review->save();

        // fetch the upgraded record so we can verify it worked.
        $review   = Review::fetch(1, $this->p4);
        $values   = $review->get() + array('versions' => $review->get('versions'));
        $values   = array_diff_key($values, array_flip(array('created', 'updated')));
        $expected = array(
            'id'                => 1,
            'type'              => 'default',
            'changes'           => array(),
            'commits'           => array(),
            'versions'          => array(),
            'author'            => 'coder',
            'participants'      => array('coder', 'jdoe'),
            'participantsData'  => array('coder' => array(), 'jdoe' => array()),
            'hasReviewer'       => 1,
            'description'       => 'THIS is, a (test.of) "description" indeXing.',
            'projects'          => array(),
            'state'             => 'needsReview',
            'stateLabel'        => 'Needs Review',
            'testStatus'        => null,
            'testDetails'       => array(),
            'deployStatus'      => null,
            'deployDetails'     => array(),
            'pending'           => false,
            'commitStatus'      => array(),
            'groups'            => array(),
        );
        ksort($expected);
        ksort($values);
        $this->assertSame($expected, $values);

        // verify record's upgrade level is now 4
        $this->assertSame(4, $review->get('upgrade'));

        // verify the records upgrade level is its 'latest' value
        $this->assertSame(Review::UPGRADE_LEVEL, $review->get('upgrade'));

        // verify we can find this review with keywords that don't match case
        $reviews = Review::fetchAll(array(Review::FETCH_BY_KEYWORDS => 'indexing'), $this->p4);
        $this->assertSame(1, $reviews->count());

        // verify we can find this review by keywords that have leading/trailing punctuation
        $reviews = Review::fetchAll(array(Review::FETCH_BY_KEYWORDS => 'description'), $this->p4);
        $this->assertSame(1, $reviews->count());
        $reviews = Review::fetchAll(array(Review::FETCH_BY_KEYWORDS => '"description"'), $this->p4);
        $this->assertSame(1, $reviews->count());

        // verify we can find this review by keywords that are delimited by '.'
        $reviews = Review::fetchAll(array(Review::FETCH_BY_KEYWORDS => 'test.of'), $this->p4);
        $this->assertSame(1, $reviews->count());

        // verify we can find this review by has-reviewers
        $reviews = Review::fetchAll(array(Review::FETCH_BY_HAS_REVIEWER => true), $this->p4);
        $this->assertSame(1, $reviews->count());
        $reviews = Review::fetchAll(array(Review::FETCH_BY_HAS_REVIEWER => false), $this->p4);
        $this->assertSame(0, $reviews->count());

        // verify old indexes were removed
        $result = $this->p4->run('search', array('1306=' . strtoupper(bin2hex('THIS'))));
        $this->assertSame(0, count($result->getData()));
    }

    /**
     * Verify that votes in participants data get upgraded correctly on save and we get
     * them in expected format from the model.
     */
    public function testUpgradeFromLevel3()
    {
        // create a sample review record as it would have been written at upgrade-level 3
        // this test focuses on the 'participants' field
        $id     = 'swarm-review-fffffffe';
        $values = array(
            'author'        => 'coder',
            'changes'       => array(1, 3, 4),
            'commitStatus'  => array(),
            'commits'       => null,
            'created'       => 123,
            'deployDetails' => null,
            'deployStatus'  => null,
            'description'   => "testing\n\n@bar",
            'hasReviewer'   => 1,
            'participants'  => array(
                'bar'   => array(),
                'foo'   => array('vote' =>  1),
                'coder' => array(),
                'test'  => array('vote' => -1)
            ),
            'pending'      => 1,
            'projects'     => array('prj-test' => array('branch-1')),
            'state'        => 'needsReview',
            'testDetailes' => null,
            'testStatus'   => null,
            'token'        => 'EB6F1DB6-D84C-6391-4459-0FDC2A03A0B5',
            'type'         => null,
            'updated'      => 234,
            'upgrade'      => 3,
            'versions'     => array(
                array(
                    'change'  => 3,
                    'pending' => true,
                    'time'    => 123,
                    'user'    => 'coder'
                ),
                array(
                    'archiveChange' => 4,
                    'change'        => 2,
                    'pending'       => true,
                    'time'          => 234,
                    'user'          => 'coder'
                )
            )
        );

        $this->p4->run(
            'counter',
            array('-u', $id, json_encode($values))
        );

        // verify we can fetch it by id
        $review = Review::fetch(1, $this->p4);
        $this->assertSame(1, $review->getId());

        // verify we can get participants data in expected format
        $expected = array(
            'bar'   => array(),
            'coder' => array(),
            'foo'   => array(
                'vote' => array('value' =>  1, 'version' => 2, 'isStale' => false)
            ),
            'test'  => array(
                'vote' => array('value' => -1, 'version' => 2, 'isStale' => false)
            )
        );
        $this->assertSame($expected, $review->getParticipantsData());

        // verify we can get votes in expected format
        $review   = Review::fetch(1, $this->p4);
        $expected = array(
            'foo'  => array('value' =>  1, 'version' => 2, 'isStale' => false),
            'test' => array('value' => -1, 'version' => 2, 'isStale' => false)
        );
        $this->assertSame($expected, $review->getVotes());

        // verify we can get up/down votes in expected format
        $review   = Review::fetch(1, $this->p4);
        $expected = array('foo' => array('value' => 1, 'version' => 2, 'isStale' => false));
        $this->assertSame($expected, $review->getUpVotes());

        $review   = Review::fetch(1, $this->p4);
        $expected = array('test' => array('value' => -1, 'version' => 2, 'isStale' => false));
        $this->assertSame($expected, $review->getDownVotes());

        // ok, let's upgrade this thing (simply fetch and save)
        $review = Review::fetch(1, $this->p4);
        $review->save();

        // verify the records upgrade level is its 'latest' value
        $this->assertSame(Review::UPGRADE_LEVEL, $review->get('upgrade'));

        // verify we can get participants data in expected format
        $expected = array(
            'bar'   => array(),
            'coder' => array(),
            'foo'   => array(
                'vote' => array('value' =>  1, 'version' => 2, 'isStale' => false)
            ),
            'test'  => array(
                'vote' => array('value' => -1, 'version' => 2, 'isStale' => false)
            )
        );
        $this->assertSame($expected, $review->getParticipantsData());

        // verify we can get votes in expected format
        $review   = Review::fetch(1, $this->p4);
        $expected = array(
            'foo'  => array('value' =>  1, 'version' => 2, 'isStale' => false),
            'test' => array('value' => -1, 'version' => 2, 'isStale' => false)
        );
        $this->assertSame($expected, $review->getVotes());

        // verify we can get up/down votes in expected format
        $review   = Review::fetch(1, $this->p4);
        $expected = array('foo' => array('value' => 1, 'version' => 2, 'isStale' => false));
        $this->assertSame($expected, $review->getUpVotes());

        $review   = Review::fetch(1, $this->p4);
        $expected = array('test' => array('value' => -1, 'version' => 2, 'isStale' => false));
        $this->assertSame($expected, $review->getDownVotes());

        // verify that 'isStale' property was not saved in participants data
        $result   = $this->p4->run('counter', array('-u', $id))->getData();
        $rawData  = json_decode($result[0]['value'], true);
        $expected = array(
            'bar'   => array(),
            'coder' => array(),
            'foo'   => array(
                'vote' => array('value' =>  1, 'version' => 2)
            ),
            'test'  => array(
                'vote' => array('value' => -1, 'version' => 2)
            )
        );
        $this->assertSame($expected, $rawData['participants']);
    }

    public function testCommit()
    {
        $p4     = $this->p4;
        $pool   = $p4->getService('clients');
        $client = $pool->grab();
        $pool->reset();

        // open a 'test' file for add and get it into a shelved change
        $dir  = $pool->getRoot() . '/' . $client . '/depot';
        $file = $dir . '/test';
        mkdir($dir);
        file_put_contents($file, "this is a test\n");
        $p4->run('add', $file);
        $shelf = new Change($this->p4);
        $shelf->setDescription("test commit\n")->addFile($file)->save();
        $p4->run('shelve', array('-c', $shelf->getId()));

        // create a review from our change then update from change and commit
        $review = Review::createFromChange($shelf, $this->p4);
        $review->save();
        $review->updateFromChange($shelf)->save();
        $commit = $review->commit();

        // ensure the commit object reflects storage and looks right
        $commit = Change::fetch($commit->getId(), $p4);
        $this->assertSame(
            "test commit\n",
            $commit->getDescription()
        );
        $this->assertTrue(
            $commit->isSubmitted()
        );
    }

    public function testStreamCommit()
    {
        $p4   = $this->p4;
        $pool = $p4->getService('clients');

        // create a stream depot and stream //stream/main
        $depot = $this->p4->run('depot', array('-o', 'stream'))->getData(-1);
        $this->p4->run('depot', '-i', array('Type' => 'stream') + $depot);
        $stream = array('Type' => 'mainline') + $this->p4->run('stream', array('-o', '//stream/main'))->getData(-1);
        $this->p4->run('stream', '-i', $stream)->getData(-1);
        $this->p4->disconnect();    // required for 2010.2 p4d to pick up the new depot

        // get a usable client
        $client = $pool->grab(true);
        $pool->reset(true, '//stream/main');

        // open a 'test' file for add and get it into a shelved change
        $dir  = $pool->getRoot() . '/' . $client . '/depot';
        $file = $dir . '/test';
        mkdir($dir);
        file_put_contents($file, "this is a test\n");
        $p4->run('add', $file);
        $shelf = new Change($this->p4);
        $shelf->setDescription("test commit\n")->addFile($file)->save();
        $p4->run('shelve', array('-c', $shelf->getId()));

        // create a review from our change then update from change and commit
        $review = Review::createFromChange($shelf, $this->p4);
        $review->save();
        $review->updateFromChange($shelf)->save();
        $commit = $review->commit();

        // ensure the commit object reflects storage and looks right
        $commit = Change::fetch($commit->getId(), $p4);
        $this->assertSame(
            array('//stream/main/depot/test#1'),
            $commit->getFiles()
        );
        $this->assertSame(
            "test commit\n",
            $commit->getDescription()
        );
        $this->assertTrue(
            $commit->isSubmitted()
        );
    }

    public function testStreamCommitComplex()
    {
        $p4   = $this->p4;
        $pool = $p4->getService('clients');

        // create a development stream depot and stream //stream/smithers
        $depot = $this->p4->run('depot', array('-o', 'stream'))->getData(-1);
        $this->p4->run('depot', '-i', array('Type' => 'stream') + $depot);

        $stream = array('Type' => 'mainline') + $this->p4->run('stream', array('-o', '//stream/main'))->getData(-1);
        $this->p4->run('stream', '-i', $stream)->getData(-1);
        $this->p4->disconnect();    // required for 2010.2 p4d to pick up the new depot

        // paths to add for this test
        $paths = array(
            array('type' => 'share', 'view' => 'lib/...')
        );

        $mainline = Stream::fetch('//stream/main');
        $mainline->setPaths($paths)->save();

        $stream = array('Type' => 'development')
                  + $this->p4->run('stream', array('-P', '//stream/main', '-o', '//stream/smithers'))->getData(-1);
        $this->p4->run('stream', '-i', $stream)->getData(-1);
        $this->p4->disconnect();

        $development = Stream::fetch('//stream/smithers');
        $development->setPaths($paths)->save();

        $this->p4->disconnect();    // required for 2010.2 p4d to pick up the new depot

        // get a usable client
        $pool->release();
        $client = $pool->grab(false);
        $pool->reset(true, '//stream/smithers');

        // open a 'test' file for add and get it into a shelved change
        $dir  = $pool->getRoot() . '/' . $client . '/lib/mr_burns';
        $file = $dir . '/test.txt';
        mkdir($dir, 0777, true);
        file_put_contents($file, "this is a test\n");
        $p4->run('add', $file);
        $shelf = new Change($this->p4);
        $shelf->setDescription("test commit\n")->addFile($file)->save();
        $p4->run('shelve', array('-c', $shelf->getId()));

        // create a review from our change then update from change and commit
        $review = Review::createFromChange($shelf, $this->p4);
        $review->save();
        $review->updateFromChange($shelf)->save();

        $review = Review::fetch(2, $p4);
        $commit = $review->commit();

        // ensure the commit object reflects storage and looks right
        $commit = Change::fetch($commit->getId(), $p4);
        $this->assertSame(
            array('//stream/smithers/lib/mr_burns/test.txt#1'),
            $commit->getFiles()
        );
        $this->assertSame(
            "test commit\n",
            $commit->getDescription()
        );
        $this->assertTrue(
            $commit->isSubmitted()
        );
    }

    public function testStreamCommitDeletedClient()
    {
        // we lack the ability to delete clients with shelved files on older servers
        if (!$this->p4->isServerMinVersion('2014.1')) {
            $this->markTestSkipped('Inability to delete clients with shelved files; server too old.');
        }

        $p4   = $this->p4;
        $pool = $p4->getService('clients');

        // create a development stream depot and stream //stream/smithers
        $depot = $this->p4->run('depot', array('-o', 'stream'))->getData(-1);
        $this->p4->run('depot', '-i', array('Type' => 'stream') + $depot);

        $stream = array('Type' => 'mainline') + $this->p4->run('stream', array('-o', '//stream/main'))->getData(-1);
        $this->p4->run('stream', '-i', $stream)->getData(-1);
        $this->p4->disconnect();    // required for 2010.2 p4d to pick up the new depot

        // paths to add for this test
        $paths = array(
            array('type' => 'share', 'view' => 'lib/...')
        );

        $mainline = Stream::fetch('//stream/main');
        $mainline->setPaths($paths)->save();

        $stream = array('Type' => 'development')
            + $this->p4->run('stream', array('-P', '//stream/main', '-o', '//stream/smithers'))->getData(-1);
        $this->p4->run('stream', '-i', $stream)->getData(-1);
        $this->p4->disconnect();

        $development = Stream::fetch('//stream/smithers');
        $development->setPaths($paths)->save();

        $this->p4->disconnect();    // required for 2010.2 p4d to pick up the new depot

        // get a temporary client
        $pool->release();
        $client = $pool->grab(false);
        $pool->reset(true, '//stream/smithers');

        // open a 'test' file for add and get it into a shelved change
        $dir  = $pool->getRoot() . '/' . $client . '/lib/mr_burns';
        $file = $dir . '/test.txt';
        mkdir($dir, 0777, true);
        file_put_contents($file, "this is a test\n");
        $p4->run('add', $file);
        $shelf = new Change($this->p4);
        $shelf->setDescription("test commit\n")->addFile($file)->save();
        $p4->run('shelve', array('-c', $shelf->getId()));

        // create a review from our change then update from change and commit
        $review = Review::createFromChange($shelf, $this->p4);
        $review->save();
        $review->updateFromChange($shelf)->save();

        // save the client values so we can use them later
        $values = Client::fetch($client)->getRawValues();

        // delete the client used to shelve the above change, and ensure it is gone
        $pool->release();
        $this->p4->run('client', array('-df',  '-Fs', $shelf->getClient()));
        $this->assertFalse(Client::exists($shelf->getClient()));

        // use a different client to do our commit
        $client = Client::makeTemp(array('Root' => DATA_PATH . '/temp-client-of-love', 'Stream' => $values['Stream']));
        $p4->setClient($client->getId());
        $review = Review::fetch(2, $p4);
        $review->commit();
    }

    public function testStreamDelete()
    {
        $p4   = $this->p4;
        $pool = $p4->getService('clients');

        // create a development stream depot and stream //stream/smithers
        $depot = $this->p4->run('depot', array('-o', 'stream'))->getData(-1);
        $this->p4->run('depot', '-i', array('Type' => 'stream') + $depot);

        $stream = array('Type' => 'mainline') + $this->p4->run('stream', array('-o', '//stream/main'))->getData(-1);
        $this->p4->run('stream', '-i', $stream)->getData(-1);
        $this->p4->disconnect();    // required for 2010.2 p4d to pick up the new depot

        // paths to add for this test
        $paths = array(
            array('type' => 'share', 'view' => 'lib/...')
        );

        $mainline = Stream::fetch('//stream/main');
        $mainline->setPaths($paths)->save();

        $stream = array('Type' => 'development')
            + $this->p4->run('stream', array('-P', '//stream/main', '-o', '//stream/smithers'))->getData(-1);
        $this->p4->run('stream', '-i', $stream)->getData(-1);
        $this->p4->disconnect();

        $development = Stream::fetch('//stream/smithers');
        $development->setPaths($paths)->save();

        $this->p4->disconnect();    // required for 2010.2 p4d to pick up the new depot

        // get a usable client
        $pool->release();
        $client = $pool->grab(false);
        $pool->reset(true, '//stream/smithers');

        // open a 'test' file for add and get it into a shelved change
        $dir  = $pool->getRoot() . '/' . $client . '/lib/mr_burns';
        $file = $dir . '/test.txt';
        mkdir($dir, 0777, true);
        file_put_contents($file, "this is a test\n");
        $p4->run('add', $file);
        $shelf = new Change($this->p4);
        $shelf->setDescription("test commit\n")->addFile($file)->save();
        $p4->run('shelve', array('-c', $shelf->getId()));

        // create a review from our change then update from change and commit
        $review = Review::createFromChange($shelf, $this->p4);
        $review->save();
        $review->updateFromChange($shelf)->save();

        // swap to a non-streams client
        $pool->release();
        $this->p4->disconnect();
        $pool->grab(false);
        $pool->reset(true);
        $review = Review::fetch(2, $p4);
        $review->delete();

        // ensure the review has been deleted
        try {
            Review::fetch(2, $p4);
        } catch (\Exception $e) {
            $this->assertStringStartsWith('Cannot fetch entry. Id does not exist.', $e->getMessage());
        }
    }

    /**
     * Test getting versions from an old review record that has no versions
     */
    public function testGetVersionsUpgrade()
    {
        // make a couple of commits so we can pull them in as versions
        $file = new File($this->p4);
        $file->setFilespec('//depot/foo')->setLocalContents('test')->add()->submit('test');
        $file = new File($this->p4);
        $file->setFilespec('//depot/bar')->setLocalContents('test')->add()->submit('test');

        // fake out an old review record
        $change = new Change($this->p4);
        $change->setDescription('review')->save();
        $id     = 'swarm-review-fffffffc';
        $values = array(
            'author'  => 'coder',
            'commits' => array(1, 2)
        );
        $this->p4->run(
            'counter',
            array('-u', $id, json_encode($values))
        );

        $review   = Review::fetch(3, $this->p4);
        $versions = $review->getVersions();
        $this->assertSame(2, count($versions));
        foreach ($versions as $key => $version) {
            $this->assertSame($key + 1, $version['change']);
            $this->assertSame('tester',  $version['user']);
            $this->assertTrue(is_int($version['time']));
        }

        // if we set the review to 'pending', we should now see 3 versions
        $values['pending'] = 1;
        $this->p4->run(
            'counter',
            array('-u', $id, json_encode($values))
        );
        $review   = Review::fetch(3, $this->p4);
        $versions = $review->getVersions();
        $this->assertSame(3, count($versions));

        // if we fetch a fresh review and save it without fetching versions
        // versions should still be upgraded (was broken at one point)
        $review = Review::fetch(3, $this->p4);
        $review->save();
        $this->assertSame(3, count(Review::fetch(3, $this->p4)->getVersions()));
    }

    /**
     * Test getting votes from an old review records that had no versions attached to votes.
     */
    public function testGetVotesUpgrade()
    {
        // make a couple of commits so we can pull them in as versions
        $file = new File($this->p4);
        $file->setFilespec('//depot/foo')->setLocalContents('test')->add()->submit('test');
        $file = new File($this->p4);
        $file->setFilespec('//depot/bar')->setLocalContents('test')->add()->submit('test');

        // fake out an old review record
        $change = new Change($this->p4);
        $change->setDescription('review')->save();
        $id     = 'swarm-review-fffffffc';
        $values = array(
            'author'       => 'coder',
            'commits'      => array(1, 2),
            'participants' => array(
                'foo' => array('vote' =>  1),
                'bar' => array('vote' => -1)
            )
        );
        $this->p4->run(
            'counter',
            array('-u', $id, json_encode($values))
        );

        $review = Review::fetch(3, $this->p4);
        $votes  = $review->getVotes();
        $this->assertSame(2, count($votes));
        $this->assertSame(array('value' =>  1, 'version' => 2, 'isStale' => false), $votes['foo']);
        $this->assertSame(array('value' => -1, 'version' => 2, 'isStale' => false), $votes['bar']);
    }

    /**
     * Test setting a bad version
     * @expectedException \InvalidArgumentException
     */
    public function testSetIntVersion()
    {
        $review = new Review;
        $review->setVersions(array(1));
    }

    /**
     * Test setting a bad version
     * @expectedException \InvalidArgumentException
     */
    public function testSetPartialVersion()
    {
        $review = new Review;
        $review->setVersions(array(array('user' => 'joe', 'time' => 12345)));
    }

    /**
     * Test setting valid versions
     */
    public function testSetAddVersions()
    {
        $versions = array(
            array('change' => 1, 'user' => 'tester', 'time' => '123', 'pending' => true,  'difference' => 1),
            array('change' => 2, 'user' => 'joe',    'time' => '456', 'pending' => true,  'difference' => 2),
            array('change' => 3, 'user' => 'bob',    'time' => '789', 'pending' => false, 'difference' => 0),
        );

        $review = new Review;
        $review->setId(4);
        $review->setVersions($versions);
        $this->assertSame($versions, $review->getVersions());

        $version = array(
            'change' => 5, 'user' => 'jane', 'time' => '987', 'pending' => true, 'difference' => 'unknown'
        );
        $review->addVersion($version);
        $version['change']        = 4;
        $version['archiveChange'] = 5;
        $version['difference']    = 'unknown';
        $versions[]               = $version;
        $this->assertSame($versions, $review->getVersions());
    }

    public function testVersioning()
    {
        // VERSION #1
        $change = new Change($this->p4);
        $change->setDescription('v1')->save();
        $fileA = new File($this->p4);
        $fileA->setFilespec('//depot/a')->setLocalContents('a1')->add($change->getId());
        $fileB = new File($this->p4);
        $fileB->setFilespec('//depot/b')->setLocalContents('b1')->add($change->getId());
        $this->p4->run('shelve', array('-c', $change->getId()));

        $review = Review::createFromChange($change);
        $review->save();
        $review->updateFromChange($change);
        $review->save();
        $review = Review::fetch(2, $this->p4);

        // at this point we should have a review with it's own shelf and one version/archive shelf
        $versions = $review->getVersions();
        $this->assertSame(1, count($versions));
        $this->assertSame(
            array('difference', 'stream', 'change', 'user', 'time', 'pending', 'archiveChange'),
            array_keys($versions[0])
        );
        $this->assertSame(2,                      $versions[0]['change']);
        $this->assertSame(3,                      $versions[0]['archiveChange']);
        $this->assertSame($review->get('author'), $versions[0]['user']);
        $this->assertSame(true,                   $versions[0]['pending']);
        $this->assertSame(1,                      $versions[0]['difference']);

        // verify the canonical shelf and the archive shelf have the expected desc.
        $canonical = Change::fetch(2, $this->p4);
        $this->assertSame('v1', trim($canonical->getDescription()));
        $archived = Change::fetch(3, $this->p4);
        $this->assertSame('v1', trim($archived->getDescription()));

        // verify they have the expected files
        $data = $this->p4->run('files', array('@=2'))->getData();
        $this->assertSame('//depot/a', $data[0]['depotFile']);
        $this->assertSame('none',      $data[0]['rev']);
        $this->assertSame('2',         $data[0]['change']);
        $this->assertSame('add',       $data[0]['action']);
        $this->assertSame('//depot/b', $data[1]['depotFile']);
        $this->assertSame('none',      $data[1]['rev']);
        $this->assertSame('2',         $data[1]['change']);
        $this->assertSame('add',       $data[1]['action']);
        $data = $this->p4->run('files', array('@=3'))->getData();
        $this->assertSame('//depot/a', $data[0]['depotFile']);
        $this->assertSame('none',      $data[0]['rev']);
        $this->assertSame('3',         $data[0]['change']);
        $this->assertSame('add',       $data[0]['action']);
        $this->assertSame('//depot/b', $data[1]['depotFile']);
        $this->assertSame('none',      $data[1]['rev']);
        $this->assertSame('3',         $data[1]['change']);
        $this->assertSame('add',       $data[1]['action']);

        // verify the correct contents were shelved
        $this->assertSame('a1', $this->p4->run('print', array('//depot/a@=2'))->getData(1));
        $this->assertSame('b1', $this->p4->run('print', array('//depot/b@=2'))->getData(1));
        $this->assertSame('a1', $this->p4->run('print', array('//depot/a@=3'))->getData(1));
        $this->assertSame('b1', $this->p4->run('print', array('//depot/b@=3'))->getData(1));

        // VERSION #2 (commit)
        $this->p4->run('shelve', array('-d', '-c', $change->getId()));
        $fileA->setLocalContents('a2');
        $fileB->setLocalContents('b2');
        $change->submit('v2');

        // update from change should clear out the canonical shelf and add
        // a version entry, but no new shelved change because this is a commit
        $review->updateFromChange($change)->save();
        $review = Review::fetch(2, $this->p4);

        // now we should have two versions.
        $v1           = $versions[0];
        $v1['change'] = $v1['archiveChange'];
        unset($v1['archiveChange']);
        $versions = $review->getVersions();
        $this->assertSame(2,   count($versions));
        $this->assertSame($v1, $versions[0]);
        $this->assertSame($change->getId(),       $versions[1]['change']);
        $this->assertSame($review->get('author'), $versions[1]['user']);
        $this->assertSame(false,                  $versions[1]['pending']);
        $this->assertSame(false,                  $review->isPending());

        // ensure that the files were removed from our canonical shelf
        $this->assertSame(array(), $this->p4->run('files', array('@=2'))->getData());

        // VERSION #3
        $change = new Change($this->p4);
        $change->setDescription('v3')->save();
        $fileA = new File($this->p4);
        $fileA->setFilespec('//depot/a')->edit($change->getId())->setLocalContents('a3');
        $fileB = new File($this->p4);
        $fileB->setFilespec('//depot/b')->edit($change->getId())->setLocalContents('b3');
        $this->p4->run('shelve', array('-f', '-c', $change->getId()));
        $review->updateFromChange($change)->save();
        $review = Review::fetch(2, $this->p4);

        // now we should have three versions, where the third version is pending again.
        $v2       = $versions[1];
        $versions = $review->getVersions();
        $this->assertSame(3,                      count($versions));
        $this->assertSame($v1,                    $versions[0]);
        $this->assertSame($v2,                    $versions[1]);
        $this->assertSame(2,                      $versions[2]['change']);
        $this->assertSame(6,                      $versions[2]['archiveChange']);
        $this->assertSame($review->get('author'), $versions[2]['user']);
        $this->assertSame(true,                   $versions[2]['pending']);
        $this->assertSame(null,                   $versions[2]['stream']);
        $this->assertSame(true,                   $review->isPending());

        // verify the archive shelf has the expected desc.
        $archived = Change::fetch(6, $this->p4);
        $this->assertSame('v3', trim($archived->getDescription()));

        // verify they have the expected files
        $data = $this->p4->run('files', array('@=2'))->getData();
        $this->assertSame('//depot/a', $data[0]['depotFile']);
        $this->assertSame('1',         $data[0]['rev']);
        $this->assertSame('2',         $data[0]['change']);
        $this->assertSame('edit',      $data[0]['action']);
        $this->assertSame('//depot/b', $data[1]['depotFile']);
        $this->assertSame('1',         $data[1]['rev']);
        $this->assertSame('2',         $data[1]['change']);
        $this->assertSame('edit',      $data[1]['action']);
        $data = $this->p4->run('files', array('@=6'))->getData();
        $this->assertSame('//depot/a', $data[0]['depotFile']);
        $this->assertSame('1',         $data[0]['rev']);
        $this->assertSame('6',         $data[0]['change']);
        $this->assertSame('edit',      $data[0]['action']);
        $this->assertSame('//depot/b', $data[1]['depotFile']);
        $this->assertSame('1',         $data[1]['rev']);
        $this->assertSame('6',         $data[1]['change']);
        $this->assertSame('edit',      $data[1]['action']);

        // verify the correct contents were shelved
        $this->assertSame('a1', $this->p4->run('print', array('//depot/a@=3'))->getData(1));
        $this->assertSame('b1', $this->p4->run('print', array('//depot/b@=3'))->getData(1));
        $this->assertSame('a3', $this->p4->run('print', array('//depot/a@=2'))->getData(1));
        $this->assertSame('b3', $this->p4->run('print', array('//depot/b@=2'))->getData(1));
        $this->assertSame('a3', $this->p4->run('print', array('//depot/a@=6'))->getData(1));
        $this->assertSame('b3', $this->p4->run('print', array('//depot/b@=6'))->getData(1));

        // STILL VERSION #3 (new push, but no diffs)
        $change = new Change($this->p4);
        $change->setDescription('v4')->save();
        $fileA = new File($this->p4);
        $fileA->setFilespec('//depot/a')->edit($change->getId())->setLocalContents('a3');
        $fileB = new File($this->p4);
        $fileB->setFilespec('//depot/b')->edit($change->getId())->setLocalContents('b3');
        $this->p4->run('shelve', array('-f', '-c', $change->getId()));
        $review->updateFromChange($change)->save();
        $review = Review::fetch(2, $this->p4);

        // should still be three versions, we didn't change anything.
        $this->assertSame(3, count($review->getVersions()));

        // VERSION #4
        $fileA->setLocalContents('a4');
        $this->p4->run('shelve', array('-f', '-c', $change->getId()));
        $review->updateFromChange($change)->save();
        $review = Review::fetch(2, $this->p4);

        // should now be four versions
        $this->assertSame(4, count($review->getVersions()));

        // VERSION #5 (commit, but no diffs)
        $this->p4->run('shelve', array('-d', '-c', $change->getId()));
        $change->submit();
        $review->updateFromChange($change)->save();
        $review = Review::fetch(2, $this->p4);

        // should now be five versions, we didn't change anything, but we always rev for commits.
        $this->assertSame(5, count($review->getVersions()));
    }

    /**
     * Reviews that pre-date versioning will not have an archive shelf.
     * We have code to 'rescue' the files in these reviews.
     * Exercise that code.
     */
    public function testRetroactiveArchive()
    {
        // VERSION #1
        $change = new Change($this->p4);
        $change->setDescription('v1')->save();
        $fileA = new File($this->p4);
        $fileA->setFilespec('//depot/a')->setLocalContents('a1')->add($change->getId());
        $this->p4->run('shelve', array('-c', $change->getId()));

        // make a review for this change, but fake out an old record by dropping the archive shelf.
        $review = Review::createFromChange($change);
        $review->save();
        $review->updateFromChange($change);
        $versions = $review->getVersions();
        unset($versions[count($versions) - 1]['archiveChange']);
        $review->setVersions($versions);
        $review->save();

        // we should now have a review with it's own shelf, but no record of a archive shelf
        $review   = Review::fetch(2, $this->p4);
        $versions = $review->getVersions();
        $this->assertSame(1, count($versions));
        $this->assertSame(
            array('difference', 'stream', 'change', 'user', 'time', 'pending'),
            array_keys($versions[0])
        );
        $this->assertSame(2,                      $versions[0]['change']);
        $this->assertSame($review->get('author'), $versions[0]['user']);
        $this->assertSame(true,                   $versions[0]['pending']);
        $this->assertSame(1,                      $versions[0]['difference']);
        $this->assertSame(null,                   $versions[0]['stream']);

        // VERSION #2 (commit)
        $this->p4->run('shelve', array('-d', '-c', $change->getId()));
        $fileA->setLocalContents('a2');
        $change->submit('v2');

        // update from change should rescue the pending files from the canonical shelf
        $review->updateFromChange($change)->save();
        $review = Review::fetch(2, $this->p4);

        // should have two versions and the first version should now have an archive shelf
        $v1           = $versions[0];
        $v1['change'] = 5;
        $versions     = $review->getVersions();
        $this->assertSame(2,   count($versions));
        $this->assertSame($v1, $versions[0]);

        // the second version should point to the commit
        $this->assertSame($change->getId(),       $versions[1]['change']);
        $this->assertSame($review->get('author'), $versions[1]['user']);
        $this->assertSame(false,                  $versions[1]['pending']);
        $this->assertSame(1,                      $versions[1]['difference']);
        $this->assertSame(false,                  $review->isPending());

        // ensure that the files were removed from our canonical shelf
        $this->assertSame(array(), $this->p4->run('files', array('@=2'))->getData());

        // ensure that the v1 contents were saved to a new archive shelf
        $this->assertSame('a1', $this->p4->run('print', array('//depot/a@=5'))->getData(1));
    }

    public function testVersioningWithStreams()
    {
        // VERSION #1
        $p4   = $this->p4;
        $pool = $p4->getService('clients');

        // create a stream depot and stream //stream/main
        $depot = $this->p4->run('depot', array('-o', 'stream'))->getData(-1);
        $this->p4->run('depot', '-i', array('Type' => 'stream') + $depot);
        $stream = array('Type' => 'mainline') + $this->p4->run('stream', array('-o', '//stream/main'))->getData(-1);
        $this->p4->run('stream', '-i', $stream)->getData(-1);
        $this->p4->disconnect();    // required for 2010.2 p4d to pick up the new depot

        // get a usable client
        $client = $pool->grab(true);
        $pool->reset(true, '//stream/main');
        $change = new Change($this->p4);
        $change->setDescription('v1')->save();
        $fileA = new File($this->p4);
        $fileA->setFilespec('//stream/main/depot/a')->setLocalContents('a1')->add($change->getId());
        $p4->run('shelve', array('-c', $change->getId()));

        // make a review for this change, but fake out an old record by dropping the archive shelf.
        $review = Review::createFromChange($change, $this->p4);
        $review->save();
        $review->updateFromChange($change);
        $versions = $review->getVersions();
        unset($versions[count($versions) - 1]['archiveChange']);
        $review->setVersions($versions);
        $review->save();

        // we should now have a review with it's own shelf, but no record of a archive shelf
        $review   = Review::fetch(2, $p4);
        $versions = $review->getVersions();
        $this->assertSame(1, count($versions));
        $this->assertSame(
            array('difference', 'stream', 'change', 'user', 'time', 'pending'),
            array_keys($versions[0])
        );
        $this->assertSame(2,                      $versions[0]['change']);
        $this->assertSame($review->get('author'), $versions[0]['user']);
        $this->assertSame(true,                   $versions[0]['pending']);
        $this->assertSame(1,                      $versions[0]['difference']);
        $this->assertSame('//stream/main',        $versions[0]['stream']);

        // VERSION #2 (commit)
        $p4->setClient($client);
        $p4->run('shelve', array('-d', '-c', $change->getId()));
        $fileA = new File($this->p4);
        $fileA->setFilespec('//stream/main/depot/a')->setLocalContents('a2')->add($change->getId());
        $change->save()->submit('v2');

        // update from change should rescue the pending files from the canonical shelf
        $review->updateFromChange($change)->save();
        $review = Review::fetch(2, $this->p4);

        // should have two versions and the first version should now have an archive shelf
        $v1           = $versions[0];
        $v1['change'] = 5;
        $versions     = $review->getVersions();
        $this->assertSame(2,   count($versions));
        $this->assertSame($v1, $versions[0]);

        // the second version should point to the commit
        $this->assertSame($change->getId(),       $versions[1]['change']);
        $this->assertSame($review->get('author'), $versions[1]['user']);
        $this->assertSame(false,                  $versions[1]['pending']);
        $this->assertSame(1,                      $versions[1]['difference']);
        $this->assertSame(false,                  $review->isPending());

        // ensure that the files were removed from our canonical shelf
        $this->assertSame(array(), $this->p4->run('files', array('@=2'))->getData());

        // ensure that the v1 contents were saved to a new archive shelf
        $this->assertSame('a1', $this->p4->run('print', array('//stream/main/depot/a@=5'))->getData(1));
    }

    public function testSetAddVotes()
    {
        $votes = array(
            'user1' => array('value' =>  1, 'version' => 7),
            'user2' => array('value' =>  1, 'version' => 8),
            'user3' => array('value' => -1, 'version' => 9)
        );

        // prepare filter function to remove 'isStale' flag from votes
        $stripIsStale = function (array $votes) {
            $filtered = $votes;
            foreach ($filtered as &$vote) {
                unset($vote['isStale']);
            }

            return $filtered;
        };

        $review = new Review;
        $review->setId(4);
        $review->setParticipants(array_keys($votes));
        $review->setVotes($votes);
        $this->assertSame($votes, $stripIsStale($review->getVotes()));

        $review->addParticipant('user4');
        $review->addVote('user4', 1, 1);
        $votes['user4'] = array('value' => 1, 'version' => 1);
        $this->assertSame($votes, $stripIsStale($review->getVotes()));

        $review->addVote('user3', 1, 3);
        $votes['user3'] = array('value' => 1, 'version' => 3);
        $this->assertSame($votes, $stripIsStale($review->getVotes()));
    }

    public function testGetVotes()
    {
        $votes = array(
            'user1' => array('value' =>  1, 'version' => 7),
            'user2' => array('value' =>  1, 'version' => 8),
            'user3' => array('value' => -1, 'version' => 9)
        );

        $review = new Review;
        $review->setId(4);
        $review->setParticipants(array_keys($votes));
        $review->setVotes($votes);

        $this->assertSame(
            array(
                'user1' => array('value' => 1, 'version' => 7, 'isStale' => false),
                'user2' => array('value' => 1, 'version' => 8, 'isStale' => false)
            ),
            $review->getUpVotes()
        );
        $this->assertSame(
            array(
                'user3' => array('value' => -1, 'version' => 9, 'isStale' => false)
            ),
            $review->getDownVotes()
        );
    }

    public function testKTextDiff()
    {
        $p4     = $this->p4;
        $pool   = $p4->getService('clients');
        $client = $pool->grab();
        $pool->reset();

        // open 'test1' and 'test2' files for add and get them into a shelved change
        $dir   = $pool->getRoot() . '/' . $client . '/depot';
        $file1 = $dir . '/test1';
        $file2 = $dir . '/test2';
        mkdir($dir);
        file_put_contents($file1, '$Revision$ this is a test' . "\n");
        $p4->run('add', array('-t', '+k', $file1));
        file_put_contents($file2, '$Revision$ This $File$ is another test.' . "\n");
        $p4->run('add', array('-t', '+k', $file2));

        $shelf = new Change($this->p4);
        $shelf->setDescription("test commit\n")->addFile($file1)->addFile($file2)->save();
        $p4->run('shelve', array('-c', $shelf->getId()));

        // create a review from our change
        $review = Review::createFromChange($shelf, $this->p4);
        $review->save();
        $review->updateFromChange($shelf)->save();
        $review->setState(Review::STATE_APPROVED);

        // commit our change and update the review with the commit
        // the update change will have fluxed RCS keywords
        $commit = $review->commit();
        $review->updateFromChange($commit)->save();

        // ensure the review stayed approved despite the fluxed keywords
        // note for pre 2012.2 p4ds we actually expect the review will go to needs review.
        // these older servers don't make it easy to md5 the unexpanded files :(
        $expected = Review::STATE_APPROVED;
        if (!$p4->isServerMinVersion('2012.2')) {
            $expected = Review::STATE_NEEDS_REVIEW;
        }
        $this->assertSame($expected, $review->getState());
    }

    public function testUnapproveModified()
    {
        $p4     = $this->p4;
        $pool   = $p4->getService('clients');
        $client = $pool->grab();
        $pool->reset();

        // open 'test1' and 'test2' files for add and get them into a shelved change
        $dir   = $pool->getRoot() . '/' . $client . '/depot';
        $file1 = $dir . '/test1';
        $file2 = $dir . '/test2';
        mkdir($dir);
        file_put_contents($file1, '$Revision$ this is a test' . "\n");
        $p4->run('add', array('-t', '+k', $file1));
        file_put_contents($file2, '$Revision$ This $File$ is another test.' . "\n");
        $p4->run('add', array('-t', '+k', $file2));

        $shelf = new Change($this->p4);
        $shelf->setDescription("test commit\n")->addFile($file1)->addFile($file2)->save();
        $p4->run('shelve', array('-c', $shelf->getId()));

        // create a review from our change
        $review = Review::createFromChange($shelf, $this->p4);
        $review->save();
        $review->updateFromChange($shelf)->save();
        $review->setState(Review::STATE_APPROVED);
        $review->commit();

        // the review is approved - modify and shelve a file
        $modified = new Change($this->p4);
        $spec     = new File($this->p4);
        $spec->setFilespec('//depot/test1#1')->sync();
        $modified->addFile($spec);
        $spec->edit($modified->getId())->setLocalContents('Modified content.');
        $modified->setDescription("Modifying an approved review.\n")->save();
        $p4->run('shelve', array('-c', $modified->getId()));

        // update from the change, disabling the unapproval logic
        $review->updateFromChange($modified, false)->save();
        $this->assertSame(Review::STATE_APPROVED, $review->getState());
    }

    public function testVotesVersioning()
    {
        // VERSION #1
        $change = new Change($this->p4);
        $change->setDescription('v1')->save();
        $fileA = new File($this->p4);
        $fileA->setFilespec('//depot/a')->setLocalContents('a1')->add($change->getId());
        $this->p4->run('shelve', array('-c', $change->getId()));

        // make a review for this change
        $review = Review::createFromChange($change);
        $review->save();
        $review->updateFromChange($change)->save();

        // add some participants to test with
        $review->addParticipant(array('foo', 'bar', 'joe'))->save();

        // test adding vote
        $review->addVote('foo', 1)->save();
        $this->assertSame(
            array('foo' => array('value' => 1, 'version' => 1, 'isStale' => false)),
            $review->getVotes()
        );

        // VERSION #2
        $change = new Change($this->p4);
        $change->setDescription('v2')->save();
        $fileA = new File($this->p4);
        $fileA->setFilespec('//depot/a')->setLocalContents('a2')->add($change->getId());
        $this->p4->run('shelve', array('-c', $change->getId()));
        $review->updateFromChange($change)->save();

        // test adding vote
        $review->addVote('joe', -1)->save();
        $this->assertSame(
            array(
                'foo' => array('value' =>  1, 'version' => 1, 'isStale' => true),
                'joe' => array('value' => -1, 'version' => 2, 'isStale' => false)
            ),
            $review->getVotes()
        );

        #VERSION 3 (commit)
        $change = new Change($this->p4);
        $change->setDescription('v3')->save();
        $fileA->add($change->getId());
        $change->submit();
        $review->updateFromChange($change)->save();

        // test adding vote
        $review->addVote('bar', -1)->save();
        $this->assertSame(
            array(
                'bar' => array('value' => -1, 'version' => 3, 'isStale' => false),
                'foo' => array('value' =>  1, 'version' => 1, 'isStale' => true),
                'joe' => array('value' => -1, 'version' => 2, 'isStale' => false)
            ),
            $review->getVotes()
        );
    }

    public function testNormalizeVotes()
    {
        $votes = array(
            'user1' => 'invalid',
            'user2' => 0,
            'user3' => -1
        );

        $review = new Review;
        $review->setId(4);
        $review->setParticipants(array_keys($votes));
        $review->addVersion(array('change' => 1, 'user' => 'foo', 'time' => 123, 'pending' => true, 'difference' => 1));

        $votes['user4'] = 1;
        $review->setVotes($votes);
        $this->assertSame(
            array(
                'user3' => array('value' => -1, 'version' => 1, 'isStale' => false),
                'user4' => array('value' =>  1, 'version' => 1, 'isStale' => false)
            ),
            $review->getVotes()
        );

        // add a version and ensure votes become stale
        $review->addVersion(array('change' => 2, 'user' => 'foo', 'time' => 123, 'pending' => true, 'difference' => 1));
        $this->assertSame(
            array(
                'user3' => array('value' => -1, 'version' => 1, 'isStale' => true),
                'user4' => array('value' =>  1, 'version' => 1, 'isStale' => true)
            ),
            $review->getVotes()
        );

        $review->addVote('user5', 1);
        $this->assertSame(
            array(
                'user3' => array('value' => -1, 'version' => 1, 'isStale' => true),
                'user4' => array('value' =>  1, 'version' => 1, 'isStale' => true),
                'user5' => array('value' =>  1, 'version' => 2, 'isStale' => false)
            ),
            $review->getVotes()
        );

        // add a version with difference 2 and ensure user5's vote don't become stale
        $review->addVersion(array('change' => 3, 'user' => 'foo', 'time' => 123, 'pending' => true, 'difference' => 2));
        $this->assertSame(
            array(
                'user3' => array('value' => -1, 'version' => 1, 'isStale' => true),
                'user4' => array('value' =>  1, 'version' => 1, 'isStale' => true),
                'user5' => array('value' =>  1, 'version' => 2, 'isStale' => false)
            ),
            $review->getVotes()
        );
    }

    public function testCommitOnBehalfOf()
    {
        $p4     = $this->p4;
        $pool   = $p4->getService('clients');
        $client = $pool->grab();
        $pool->reset();

        // open a 'test' file for add and get it into a shelved change
        $dir  = $pool->getRoot() . '/' . $client . '/depot';
        $file = $dir . '/test';
        mkdir($dir);
        file_put_contents($file, "this is a test\n");
        $p4->run('add', $file);
        $shelf = new Change($this->p4);
        $shelf->setDescription("test commit\n")->addFile($file)->save();
        $p4->run('shelve', array('-c', $shelf->getId()));

        // create a review from our change then update from change and commit as a different user
        $review = Review::createFromChange($shelf, $this->p4);
        $review->set('author', 'yoda')->save();
        $review->save();
        $review->updateFromChange($shelf)->save();
        $commit = $review->commit(array(Review::COMMIT_CREDIT_AUTHOR => true));

        // ensure the commit object reflects storage and looks right, and the commit's user has been changed to match
        // the review author
        $commit = Change::fetch($commit->getId(), $p4);
        $this->assertSame(
            "test commit\n",
            $commit->getDescription()
        );
        $this->assertTrue(
            $commit->isSubmitted()
        );
        $this->assertSame($commit->getUser(), $review->get('author'));
    }

    public function testCommitStatusRace()
    {
        // simulate race condition where submit takes longer to return to client
        // than the commit-trigger takes to publish to swarm and be processed
        // this leaves commit-status not-null, when it should be null
        $file = new File($this->p4);
        $file->setFilespec('//depot/foo')
             ->setLocalContents('hi')
             ->add()
             ->submit('test');

        $status = array('change' => 1, 'start' => time(), 'end' => time());
        $review = new Review($this->p4);
        $review->setCommitStatus($status)
               ->save();

        $this->assertSame($status, $review->getCommitStatus());

        // now if we add data to indicate the change is processed, commit status should report null
        $review->addCommit(1)
               ->addVersion(array('change' => 1, 'user' => 'jdoe', 'time' => time(), 'pending' => false))
               ->save();

        $this->assertSame(array(), $review->getCommitStatus());

        // should also work for renumbered changes
        $change3 = new Change($this->p4);
        $change3->setDescription('test')
                ->save();
        $change4 = new Change($this->p4);
        $change4->setDescription('test')
                ->save();
        $file->edit();
        $change3->addFile($file)
                ->submit();

        $status['change'] = 3;
        $review->setCommitStatus($status)
               ->save();

        $this->assertSame($status, $review->getCommitStatus());

        // now if we add data to indicate the change is processed, commit status should report null
        $review->addChange(3)
               ->addCommit(5)
               ->addVersion(array('change' => 5, 'user' => 'jdoe', 'time' => time(), 'pending' => false))
               ->save();

        $this->assertSame(array(), $review->getCommitStatus());
    }
}
