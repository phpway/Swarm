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
use Application\Permissions\Protections;
use Comments\Model\Comment;
use P4\Spec\Change;
use P4\File\File;
use Zend\Http\Request;
use Reviews\Model\Review;

class CommentsControllerTest extends TestApiController
{
    public function testListComments()
    {
        $result = $this->get('/api/v3/comments');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);
        $this->assertSame(array('comments' => array(), 'lastSeen' => null), $actual);

        // create some comments
        $comments = array(
            array(
                'id'        => 1,
                'topic'     => 'a/b',
                'user'      => 'foo',
                'body'      => 'comment 1'
            ),
            array(
                'id'        => 2,
                'topic'     => 'a/c',
                'user'      => 'bar',
                'body'      => 'abc z'
            ),
            array(
                'id'        => 3,
                'topic'     => 'a/b/123',
                'user'      => 'foo',
                'body'      => 'xyz'
            ),
            array(
                'id'        => 4,
                'topic'     => 'a/b/123',
                'user'      => 'foo',
                'body'      => 'xyz 123'
            ),
            array(
                'id'        => 5,
                'topic'     => 'a/b',
                'user'      => 'bar',
                'body'      => 'comment 2'
            ),
            array(
                'id'        => 6,
                'topic'     => 'a/c',
                'user'      => 'foo',
                'body'      => 'abc x'
            ),
            array(
                'id'        => 7,
                'topic'     => 'a/c',
                'user'      => 'foo',
                'body'      => 'abc y'
            ),
        );
        foreach ($comments as $values) {
            $model = new Comment($this->p4);
            $model->set($values)
                ->save();
        }

        // fetch the listing
        $result = $this->get('/api/v3/comments?topic=a/c');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);

        // build expected value
        $expected = array(
            'comments' => array(
                0 => array(
                    'id' => 2,
                    'attachments' => array(),
                    'body' => 'abc z',
                    'context' => array(),
                    'edited' => null,
                    'flags' => array(),
                    'likes' => array(),
                    'taskState' => 'comment',
                    'time' => 1461164347,
                    'topic' => 'a/c',
                    'updated' => 1461164347,
                    'user' => 'bar'
                ),
                1 => array(
                    'id' => 6,
                    'attachments' => array(),
                    'body' => 'abc x',
                    'context' => array(),
                    'edited' => null,
                    'flags' => array(),
                    'likes' => array(),
                    'taskState' => 'comment',
                    'time' => 1461164350,
                    'topic' => 'a/c',
                    'updated' => 1461164350,
                    'user' => 'foo'
                ),
                2 => array(
                    'id' => 7,
                    'attachments' => array(),
                    'body' => 'abc y',
                    'context' => array(),
                    'edited' => null,
                    'flags' => array(),
                    'likes' => array(),
                    'taskState' => 'comment',
                    'time' => 1461164353,
                    'topic' => 'a/c',
                    'updated' => 1461164353,
                    'user' => 'foo'
                )
            ),
            'lastSeen' => 7
        );

        // ensure we have the same set of comments
        $this->assertSame(array_keys($expected['comments']), array_keys($actual['comments']));

        // ensure we have the same set of fields
        foreach ($actual['comments'] as $key => $value) {
            $this->assertSame(array_keys($expected['comments'][$key]), array_keys($actual['comments'][$key]));
        }

        // remove fields that contain timestamps
        foreach ($actual['comments'] as $key => $value) {
            unset(
                $actual['comments'][$key]['time'],
                $actual['comments'][$key]['updated'],
                $expected['comments'][$key]['time'],
                $expected['comments'][$key]['updated']
            );
        }

        // run the comparison
        $this->assertSame($actual, $expected);
    }

    public function testListCommentsPaginate()
    {
        // generate comment entries
        $model = new Comment($this->p4);
        for ($i = 0; $i < 5; $i++) {
            $model->setId(null)
                ->set('topic', 'a/b')
                ->save();
        }

        // fetch the first page of the list
        $result = $this->get('/api/v3/comments?max=2');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);

        // build expected value
        $expected = array(
            'comments' => array(
                0 => array(
                    'id' => 1,
                    'attachments' => array(),
                    'body' => '',
                    'context' => array(),
                    'edited' => null,
                    'flags' => array(),
                    'likes' => array(),
                    'taskState' => 'comment',
                    'time' => 1461164347,
                    'topic' => 'a/b',
                    'updated' => 1461164347,
                    'user' => ''
                ),
                1 => array(
                    'id' => 2,
                    'attachments' => array(),
                    'body' => '',
                    'context' => array(),
                    'edited' => null,
                    'flags' => array(),
                    'likes' => array(),
                    'taskState' => 'comment',
                    'time' => 1461164350,
                    'topic' => 'a/b',
                    'updated' => 1461164350,
                    'user' => ''
                )
            ),
            'lastSeen' => 2
        );

        // ensure we have the same set of comments
        $this->assertSame(array_keys($expected['comments']), array_keys($actual['comments']));

        // remove fields that contain timestamps
        foreach ($actual['comments'] as $key => $value) {
            unset(
                $actual['comments'][$key]['time'],
                $actual['comments'][$key]['updated'],
                $expected['comments'][$key]['time'],
                $expected['comments'][$key]['updated']
            );
        }

        // run the comparison
        $this->assertEquals($expected, $actual);

        // fetch the second page of the list
        $result = $this->get('/api/v3/comments?max=2&after=2');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);

        // build expected value
        $expected = array(
            'comments' => array(
                0 => array(
                    'id' => 3,
                    'attachments' => array(),
                    'body' => '',
                    'context' => array(),
                    'edited' => null,
                    'flags' => array(),
                    'likes' => array(),
                    'taskState' => 'comment',
                    'time' => 1461164347,
                    'topic' => 'a/b',
                    'updated' => 1461164347,
                    'user' => ''
                ),
                1 => array(
                    'id' => 4,
                    'attachments' => array(),
                    'body' => '',
                    'context' => array(),
                    'edited' => null,
                    'flags' => array(),
                    'likes' => array(),
                    'taskState' => 'comment',
                    'time' => 1461164350,
                    'topic' => 'a/b',
                    'updated' => 1461164350,
                    'user' => ''
                )
            ),
            'lastSeen' => 4
        );

        // ensure we have the same set of comments
        $this->assertSame(array_keys($expected['comments']), array_keys($actual['comments']));

        // remove fields that contain timestamps
        foreach ($actual['comments'] as $key => $value) {
            unset(
                $actual['comments'][$key]['time'],
                $actual['comments'][$key]['updated'],
                $expected['comments'][$key]['time'],
                $expected['comments'][$key]['updated']
            );
        }

        // run the comparison
        $this->assertEquals($expected, $actual);

        // fetch the last page of the list
        $result = $this->get('/api/v3/comments?max=2&after=4');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);

        // build expected value
        $expected = array(
            'comments' => array(
                0 => array(
                    'id' => 5,
                    'attachments' => array(),
                    'body' => '',
                    'context' => array(),
                    'edited' => null,
                    'flags' => array(),
                    'likes' => array(),
                    'taskState' => 'comment',
                    'time' => 1461164347,
                    'topic' => 'a/b',
                    'updated' => 1461164347,
                    'user' => ''
                )
            ),
            'lastSeen' => 5
        );

        // ensure we have the same set of comments
        $this->assertSame(array_keys($expected['comments']), array_keys($actual['comments']));

        // remove fields that contain timestamps
        foreach ($actual['comments'] as $key => $value) {
            unset(
                $actual['comments'][$key]['time'],
                $actual['comments'][$key]['updated'],
                $expected['comments'][$key]['time'],
                $expected['comments'][$key]['updated']
            );
        }

        // run the comparison
        $this->assertEquals($expected, $actual);

        // further fetching must give empty result
        $result = $this->get('/api/v3/comments?max=2&after=5');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);

        $expected = array(
            'comments' => array(),
            'lastSeen' => null
        );

        // run the comparison
        $this->assertSame($actual, $expected);
    }

    public function testListCommentsLimitFields()
    {
        $result = $this->get('/api/v3/comments');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);
        $this->assertSame(array('comments' => array(), 'lastSeen' => null), $actual);

        // create some comments
        $comments = array(
            array(
                'id'        => 1,
                'topic'     => 'a/b',
                'user'      => 'foo',
                'body'      => 'comment 1'
            ),
            array(
                'id'        => 2,
                'topic'     => 'a/c',
                'user'      => 'bar',
                'body'      => 'abc z'
            )
        );
        foreach ($comments as $values) {
            $model = new Comment($this->p4);
            $model->set($values)
                ->save();
        }

        // fetch the listing and limit to one existing field and one non-existent field (test)
        $result = $this->get('/api/v3/comments?fields=body,test');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);

        // build expected value
        $expected = array(
            'comments' => array(
                0 => array(
                    'body' => 'comment 1'
                ),
                1 => array(
                    'body' => 'abc z'
                )
            ),
            'lastSeen' => 2
        );

        // run the comparison
        $this->assertSame($expected, $actual);

        // fetch the listing again, this time using array notation
        $result = $this->get('/api/v3/comments?fields[]=body&field[]=test');
        $this->assertResponseStatusCode(200);
        $actual = json_decode($result->getContent(), true);

        // run the comparison
        $this->assertSame($expected, $actual);
    }

    public function testListCommentsWithRestrictedChanges()
    {
        // create user with limited access
        $p4Foo = $this->connectWithAccess('foo', array('//depot/foo/...'));

        // create restricted change
        $file = new File;
        $file->setFilespec('//depot/test1')->open()->setLocalContents('abc');
        $change = new Change($this->p4);
        $change->setType('restricted')->addFile($file)->submit('change description');
        $changeId = $change->getId();

        // create some comments
        $model = new Comment($this->p4);
        for ($i = 0; $i < 2; $i++) {
            $model->setId(null)
                ->set('topic', 'changes/' . $changeId)
                ->set('body', 'comment ' . ($i + 1))
                ->setContext(array('file' => '//depot/test1'))
                ->save();
        }

        // owner of the change should see comments
        $protects = new Protections;
        $protects->setProtections($this->p4->run('protects', array('-h', '*'))->getData());
        $this->getApplication()->getServiceManager()->setService('ip_protects', $protects);
        $this->getRequest()->setMethod(Request::METHOD_GET);
        $this->dispatch('/api/v3/comments');
        $result = $this->getResponse();
        $this->assertResponseStatusCode(200);

        $data = json_decode($result->getContent(), true);
        $this->assertSame(2, count($data['comments']));

        // verify access to comments for user 'foo'
        $this->resetApplication();
        $this->getApplication()->getServiceManager()->setService('p4', $p4Foo);
        $protects = new Protections;
        $protects->setProtections($p4Foo->run('protects', array('-h', '*'))->getData());
        $this->getApplication()->getServiceManager()->setService('ip_protects', $protects);
        $this->getRequest()->setMethod(Request::METHOD_GET);
        $this->dispatch('/api/v3/comments');
        $result = $this->getResponse();
        $this->assertResponseStatusCode(200);

        $data = json_decode($result->getContent(), true);
        $this->assertSame(0, count($data['comments']));
    }

    public function testCreateComment()
    {
        $change   = $this->createChange();
        $review   = Review::createFromChange($change->getId())->save();
        $response = $this->post(
            '/api/v3/comments/',
            array(
                'topic' => 'reviews/' . $review->getId(),
                'body'  => 'This is the best comment EVAR.'
            )
        );

        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'comment' => array(
                'id'          => 1,
                'attachments' => array(),
                'body'        => 'This is the best comment EVAR.',
                'context'     => array(),
                'edited'      => null,
                'flags'       => array(),
                'likes'       => array(),
                'taskState'   => 'comment',
                'topic'       => 'reviews/2',
                'user'        => 'nonadmin',
            )
        );

        unset($actual['comment']['time']);
        unset($actual['comment']['updated']);

        $this->assertSame($expected, $actual);
    }

    public function testCreateCommentCustomFlag()
    {
        $change   = $this->createChange();
        $review   = Review::createFromChange($change->getId())->save();
        $response = $this->post(
            '/api/v3/comments/',
            array(
                'topic' => 'reviews/' . $review->getId(),
                'body'  => 'This is the best comment EVAR.',
                'flags' => array('best-ever')
            )
        );

        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'comment' => array(
                'id'          => 1,
                'attachments' => array(),
                'body'        => 'This is the best comment EVAR.',
                'context'     => array(),
                'edited'      => null,
                'flags'       => array('best-ever'),
                'likes'       => array(),
                'taskState'   => 'comment',
                'topic'       => 'reviews/2',
                'user'        => 'nonadmin',
            )
        );

        unset($actual['comment']['time']);
        unset($actual['comment']['updated']);

        $this->assertSame($expected, $actual);
    }

    public function testCreateCommentWithBodyError()
    {
        $change   = $this->createChange();
        $review   = Review::createFromChange($change->getId())->save();
        $response = $this->post(
            '/api/v3/comments/',
            array(
                'topic' => 'reviews/' . $review->getId(),
            )
        );

        $this->assertSame(400, $response->getStatusCode());
        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'error'   => 'Bad Request',
            'details' => array(
                'body' => "Value is required and can't be empty"
            )
        );

        $this->assertSame($expected, $actual);
    }

    public function testCreateCommentWithTopicError()
    {
        $change   = $this->createChange();
        $review   = Review::createFromChange($change->getId())->save();
        $response = $this->post(
            '/api/v3/comments/',
            array(
                'body' => 'This is the best comment EVAR.',
            )
        );

        $this->assertSame(400, $response->getStatusCode());
        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'error'   => 'Bad Request',
            'details' => array(
                'topic' => "Value is required and can't be empty"
            )
        );

        $this->assertSame($expected, $actual);
    }

    public function testCreateCommentWithTask()
    {
        $change   = $this->createChange("My File", "One-Line Change", "//depot/main/README.txt");
        $review   = Review::createFromChange($change->getId())->save();
        $response = $this->post(
            '/api/v3/comments/',
            array(
                'topic'     => 'reviews/' . $review->getId(),
                'body'      => 'This is the best comment task EVAR.',
                'taskState' => 'open'
            )
        );

        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'comment' => array(
                'id'          => 1,
                'attachments' => array(),
                'body'        => 'This is the best comment task EVAR.',
                'context'     => array(),
                'edited'      => null,
                'flags'       => array(),
                'likes'       => array(),
                'taskState'   => 'open',
                'topic'       => 'reviews/2',
                'user'        => 'nonadmin',
            )
        );

        unset($actual['comment']['time']);
        unset($actual['comment']['updated']);

        $this->assertSame($expected, $actual);
    }

    public function testCreateCommentWithTaskError()
    {
        $change   = $this->createChange("My File", "One-Line Change", "//depot/main/README.txt");
        $review   = Review::createFromChange($change->getId())->save();
        $response = $this->post(
            '/api/v3/comments/',
            array(
                'topic'     => 'reviews/' . $review->getId(),
                'body'      => 'This is the best verified comment task EVAR.',
                'taskState' => 'verified'
            )
        );

        $this->assertSame(400, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'error'   => 'Bad Request',
            'details' => array(
                'taskState' => 'Invalid task state transition specified. Valid transitions are: comment, open'
            )
        );

        $this->assertSame($expected, $actual);
    }

    public function testArchiveComment()
    {
        $this->testCreateCommentWithTask();
        $this->assertSame(true, Review::exists(2, $this->p4));
        $response = $this->patch(
            '/api/v3/comments/1',
            array(
                'flags'     => array('closed'),
                'taskState' => 'addressed'
            )
        );

        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'comment' => array(
                'id'          => 1,
                'attachments' => array(),
                'body'        => 'This is the best comment task EVAR.',
                'context'     => array(),
                'edited'      => null,
                'flags'       => array('closed'),
                'likes'       => array(),
                'taskState'   => 'addressed',
                'topic'       => 'reviews/2',
                'user'        => 'nonadmin',
            )
        );

        unset($actual['comment']['time']);
        unset($actual['comment']['updated']);

        $this->assertSame($expected, $actual);
    }

    public function testCreateCommentJson()
    {
        $change   = $this->createChange();
        $review   = Review::createFromChange($change->getId())->save();
        $response = $this->postJson(
            '/api/v3/comments/',
            array(
                'topic' => 'reviews/' . $review->getId(),
                'body'  => 'This is the best comment EVAR.'
            )
        );

        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'comment' => array(
                'id'          => 1,
                'attachments' => array(),
                'body'        => 'This is the best comment EVAR.',
                'context'     => array(),
                'edited'      => null,
                'flags'       => array(),
                'likes'       => array(),
                'taskState'   => 'comment',
                'topic'       => 'reviews/2',
                'user'        => 'nonadmin',
            )
        );

        unset($actual['comment']['time']);
        unset($actual['comment']['updated']);

        $this->assertSame($expected, $actual);
    }

    public function testCreateCommentBadApiVersion()
    {
        $change   = $this->createChange();
        $review   = Review::createFromChange($change->getId())->save();
        $response = $this->post(
            '/api/v2/comments/',
            array(
                'topic' => 'reviews/' . $review->getId(),
                'body'  => 'This is the best comment EVAR.'
            )
        );

        $actual   = json_decode($response->getContent(), true);
        $expected = array('error' => 'Not Found');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($expected, $actual);
    }

    public function testCreateCommentIgnoreUserField()
    {
        $change   = $this->createChange();
        $review   = Review::createFromChange($change->getId())->save();
        $response = $this->post(
            '/api/v3/comments/',
            array(
                'topic' => 'reviews/' . $review->getId(),
                'body'  => 'This is the best comment EVAR.',
                'user'  => 'admin'
            )
        );

        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'comment' => array(
                'id'          => 1,
                'attachments' => array(),
                'body'        => 'This is the best comment EVAR.',
                'context'     => array(),
                'edited'      => null,
                'flags'       => array(),
                'likes'       => array(),
                'taskState'   => 'comment',
                'topic'       => 'reviews/2',
                'user'        => 'nonadmin',
            )
        );

        unset($actual['comment']['time']);
        unset($actual['comment']['updated']);

        $this->assertSame($expected, $actual);
    }

    public function testEditComment()
    {
        $change   = $this->createChange();
        $review   = Review::createFromChange($change->getId())->save();
        $response = $this->post(
            '/api/v3/comments/',
            array(
                'topic' => 'reviews/' . $review->getId(),
                'body'  => 'This is the best comment EVAR.'
            )
        );

        $this->assertSame(200, $response->getStatusCode());
        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'comment' => array(
                'id'          => 1,
                'attachments' => array(),
                'body'        => 'This is the best comment EVAR.',
                'context'     => array(),
                'edited'      => null,
                'flags'       => array(),
                'likes'       => array(),
                'taskState'   => 'comment',
                'topic'       => 'reviews/2',
                'user'        => 'nonadmin',
            )
        );

        unset($actual['comment']['time']);
        unset($actual['comment']['updated']);
        $this->assertSame($expected, $actual);

        $response = $this->patch(
            '/api/v3/comments/1',
            array(
                'body' => 'Perhaps I was a bit too bold. This was only the second-best comment EVER.'
            )
        );

        $this->assertSame(200, $response->getStatusCode());
        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'comment' => array(
                'id'          => 1,
                'attachments' => array(),
                'body'        => 'Perhaps I was a bit too bold. This was only the second-best comment EVER.',
                'context'     => array(),
                'flags'       => array(),
                'likes'       => array(),
                'taskState'   => 'comment',
                'topic'       => 'reviews/2',
                'user'        => 'nonadmin',
            )
        );

        $this->assertNotNull($actual['comment']['edited']);
        unset($actual['comment']['time']);
        unset($actual['comment']['updated']);
        unset($actual['comment']['edited']);

        $this->assertSame($expected, $actual);
    }

    /**
     * Helper functions
     */
    protected function createChange(
        $content = 'xyz123',
        $description = 'change description',
        $filespec = '//depot/main/foo/test.txt'
    ) {
        $file = new File($this->p4);
        $file->setFilespec($filespec)
            ->open()
            ->setLocalContents($content)
            ->submit($description);

        return $file->getChange();
    }
}
