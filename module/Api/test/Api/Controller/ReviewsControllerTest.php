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
use P4\File\File;
use P4\Spec\Change;
use P4\Spec\Job;
use Users\Model\User;
use Reviews\Model\Review;

class ReviewsControllerTest extends TestApiController
{
    public function testGetReview()
    {
        $response = $this->get('/api/v1/reviews/2');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('{"error":"Not Found"}', $response->getContent());

        $change = $this->createChange();
        $review = Review::createFromChange($change->getId(), $this->p4);
        $review->save();

        $response = $this->get('/api/v1/reviews/2');
        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'admin',
                'changes'       => array(1),
                'commits'       => array(1),
                'commitStatus'  => array(),
                'created'       => null,
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => "change description\n",
                'groups'        => array(),
                'participants'  => array(
                    'admin'     => array(),
                ),
                'pending'       => false,
                'projects'      => array(),
                'state'         => 'needsReview',
                'stateLabel'    => 'Needs Review',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
                'updated'       => null,
                'versions'      => array(),
            )
        );

        // ensure we have the same set of fields
        $this->assertSame(array_keys($actual['review']), array_keys($expected['review']));

        // discounting created/updated times, ensure we have the same data
        unset(
            $actual['review']['created'],
            $actual['review']['updated'],
            $expected['review']['created'],
            $expected['review']['updated']
        );
        $this->assertSame($actual, $expected);
    }

    public function testGetReviewLimitByFields()
    {
        $change = $this->createChange();
        $review = Review::createFromChange($change->getId(), $this->p4);
        $review->save();

        $response = $this->get('/api/v1/reviews/2?fields=id,author,description,pending,state,stateLabel');
        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'admin',
                'description'   => "change description\n",
                'pending'       => false,
                'state'         => 'needsReview',
                'stateLabel'    => 'Needs Review',
            )
        );

        $this->assertSame($actual, $expected);
    }

    public function testCreateReview()
    {
        $change   = $this->createChange();
        $response = $this->post(
            '/api/v1/reviews',
            array(
                'change'      => $change->getId(),
                'description' => 'Test Review',
                'reviewers'   => array('tester')
            )
        );

        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'admin',
                'changes'       => array(1),
                'commits'       => array(1),
                'commitStatus'  => array(),
                'created'       => null,
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => 'Test Review',
                'groups'        => array(),
                'participants'  => array(
                    'admin'     => array(),
                    'nonadmin'  => array(),
                    'tester'    => array(),
                ),
                'pending'       => false,
                'projects'      => array(),
                'state'         => 'needsReview',
                'stateLabel'    => 'Needs Review',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
                'updated'       => null,
                'versions'      => array(),
            )
        );

        // ensure we have the same set of fields
        $this->assertSame(array_keys($actual['review']), array_keys($expected['review']));

        // discounting created/updated times, ensure we have the same data
        unset(
            $actual['review']['created'],
            $actual['review']['updated'],
            $expected['review']['created'],
            $expected['review']['updated']
        );
        $this->assertSame($actual, $expected);

        // ensure record was really created
        $review = Review::fetch(2, $this->p4);
        $this->assertSame("Test Review", $review->get('description'));
    }

    public function testCreateReviewDuplicate()
    {
        $change   = $this->createChange();
        $response = $this->post(
            '/api/v1/reviews',
            array(
                'change'      => $change->getId(),
                'description' => 'Test Review'
            )
        );

        $this->assertSame(200, $response->getStatusCode());

        $response = $this->post(
            '/api/v1/reviews',
            array(
                'change'      => 1,
                'description' => 'Dupe Review',
            )
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            json_decode($response->getContent(), true),
            array('error' => 'A Review for change 1 already exists.')
        );
    }

    public function testCreateReviewJson()
    {
        $change   = $this->createChange();
        $response = $this->postJson(
            '/api/v1/reviews',
            array(
                'change'      => $change->getId(),
                'description' => 'Test Review',
                'reviewers'   => array('tester')
            )
        );

        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'admin',
                'changes'       => array(1),
                'commits'       => array(1),
                'commitStatus'  => array(),
                'created'       => null,
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => 'Test Review',
                'groups'        => array(),
                'participants'  => array(
                    'admin'     => array(),
                    'nonadmin'  => array(),
                    'tester'    => array(),
                ),
                'pending'       => false,
                'projects'      => array(),
                'state'         => 'needsReview',
                'stateLabel'    => 'Needs Review',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
                'updated'       => null,
                'versions'      => array(),
            )
        );

        // ensure we have the same set of fields
        $this->assertSame(array_keys($actual['review']), array_keys($expected['review']));

        // discounting created/updated times, ensure we have the same data
        unset(
            $actual['review']['created'],
            $actual['review']['updated'],
            $expected['review']['created'],
            $expected['review']['updated']
        );
        $this->assertSame($actual, $expected);

        // ensure review record exists
        $review = Review::fetch(2, $this->p4);
        $this->assertSame("Test Review", $review->get('description'));
    }

    public function getRequiredReviewersData()
    {
        return array(
            array(array('tester'), 200, array('tester')),
            array(array(), 200, array()),
            array(null, 200, array()),
            array(array('idonotexist'), 400, array()),
            array('wrongtype', 400, array())
        );
    }

    /**
     * @dataProvider getRequiredReviewersData
     */
    public function testCreateReviewRequiredReviewers($requiredReviews, $statusCode, $resultRequired)
    {
        $change   = $this->createChange();
        $response = $this->post(
            '/api/v1.1/reviews',
            array(
                'change'            => $change->getId(),
                'description'       => 'Test Review',
                'reviewers'         => array('tester'),
                'requiredReviewers' => $requiredReviews
            )
        );

        $this->assertSame($statusCode, $response->getStatusCode());

        // could not create the review, but was expected: pass
        if ($statusCode == 400) {
            return;
        }

        $participants = array(
            'admin'     => array(),
            'nonadmin'  => array(),
            'tester'    => array()
        );

        foreach ($resultRequired as $required) {
            $participants[$required] = array('required' => true);
        }
        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'admin',
                'changes'       => array(1),
                'commits'       => array(1),
                'commitStatus'  => array(),
                'created'       => null,
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => 'Test Review',
                'groups'        => array(),
                'participants'  => $participants,
                'pending'       => false,
                'projects'      => array(),
                'state'         => 'needsReview',
                'stateLabel'    => 'Needs Review',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
                'updated'       => null,
                'versions'      => array(),
            )
        );

        // ensure we have the same set of fields
        $this->assertSame(array_keys($actual['review']), array_keys($expected['review']));

        // discounting created/updated times, ensure we have the same data
        unset(
            $actual['review']['created'],
            $actual['review']['updated'],
            $expected['review']['created'],
            $expected['review']['updated']
        );
        $this->assertSame($actual, $expected);

        // ensure record was really created
        $review = Review::fetch(2, $this->p4);
        $this->assertSame("Test Review", $review->get('description'));
    }

    public function testCreateReviewWithMentions()
    {
        $user = new User;
        $user->set(
            array(
                'User'      => 'sample',
                'Email'     => 'sample@example.com',
                'FullName'  => 'Seamus Ample',
                'Password'  => '123',
            )
        )->save();

        $change   = $this->createChange();
        $response = $this->post(
            '/api/v1/reviews',
            array(
                'change'      => $change->getId(),
                'description' => 'Test Review @sample',
                'reviewers'   => array('tester')
            )
        );

        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'admin',
                'changes'       => array(1),
                'commits'       => array(1),
                'commitStatus'  => array(),
                'created'       => null,
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => 'Test Review @sample',
                'groups'        => array(),
                'participants'  => array(
                    'admin'     => array(),
                    'nonadmin'  => array(),
                    'tester'    => array(),
                ),
                'pending'       => false,
                'projects'      => array(),
                'state'         => 'needsReview',
                'stateLabel'    => 'Needs Review',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
                'updated'       => null,
                'versions'      => array(),
            )
        );

        // ensure we have the same set of fields
        $this->assertSame(array_keys($actual['review']), array_keys($expected['review']));

        // discounting created/updated times, ensure we have the same data
        unset(
            $actual['review']['created'],
            $actual['review']['updated'],
            $expected['review']['created'],
            $expected['review']['updated']
        );
        $this->assertSame($actual, $expected);

        // ensure record was really created
        $review = Review::fetch(2, $this->p4);
        $this->assertSame("Test Review @sample", $review->get('description'));


        // PROCESS QUEUE AND RETRY ABOVE
        $reviewId = $review->getId();
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $response = $this->dispatch('/queue/worker');

        $response = $this->get('/api/v1/reviews/' . $reviewId);
        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'admin',
                'changes'       => array(1),
                'commits'       => array(1),
                'commitStatus'  => array(),
                'created'       => null,
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => 'Test Review @sample',
                'groups'        => array(),
                'participants'  => array(
                    'admin'     => array(),
                    'nonadmin'  => array(),
                    'sample'    => array(),
                    'tester'    => array(),
                ),
                'pending'       => false,
                'projects'      => array(),
                'state'         => 'needsReview',
                'stateLabel'    => 'Needs Review',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
                'updated'       => null,
                'versions'      => array(
                    array(
                        'change'     => 1,
                        'user'       => 'admin',
                        'time'       => null,
                        'pending'    => false,
                        'difference' => 1,
                        'stream'     => null
                    )
                )
            )
        );

        // ensure we have the same set of fields
        $this->assertSame(array_keys($actual['review']), array_keys($expected['review']));

        // discounting created/updated/versioned times, ensure we have the same data
        unset(
            $actual['review']['created'],
            $actual['review']['updated'],
            $actual['review']['versions'][0]['time'],
            $expected['review']['created'],
            $expected['review']['updated'],
            $expected['review']['versions'][0]['time']
        );
        $this->assertSame($actual, $expected);
    }

    public function testAddSubmittedChange()
    {
        $change1 = $this->createChange('test123', 'change description', '//depot/main/foo/change1.txt');
        $change2 = $this->createChange('xyz789', '2nd change description', '//depot/main/foo/change2.txt');
        $review  = Review::createFromChange($change1)->save();

        $result = $this->post(
            '/api/v1/reviews/' . $review->getId() . '/changes',
            array(
                'change' => $change2->getId(),
            )
        );

        $this->assertSame(200, $result->getStatusCode());

        $actual   = json_decode($this->getResponse()->getContent(), true);
        $expected = array(
            'review' => array(
                'id'            => 3,
                'author'        => 'admin',
                'changes'       => array(1, 2),
                'commits'       => array(1, 2),
                'commitStatus'  => array(),
                'created'       => null,
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => "change description\n",
                'groups'        => array(),
                'participants'  => array(
                    'admin'     => array(),
                    'nonadmin'  => array(),
                ),
                'pending'       => false,
                'projects'      => array(),
                'state'         => 'needsReview',
                'stateLabel'    => 'Needs Review',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
                'updated'       => null,
                'versions'      => array(),
            )
        );

        // ensure we have the same set of fields
        $this->assertSame(array_keys($actual['review']), array_keys($expected['review']));

        // discounting created/updated times, ensure we have the same data
        unset(
            $actual['review']['created'],
            $actual['review']['updated'],
            $expected['review']['created'],
            $expected['review']['updated']
        );
        $this->assertSame($actual, $expected);

        $review = Review::fetch(3, $this->p4);
        $this->assertSame(array(1, 2), $review->getChanges());
        $this->assertSame(2, $review->getHeadChange());
    }

    public function testAddPendingChange()
    {
        $change1 = $this->createChange('test123', 'change description', '//depot/main/foo/change1.txt');
        $review  = Review::createFromChange($change1)->save();

        $review = Review::fetch($review->getId(), $this->p4);
        $this->assertSame(array(1), $review->getChanges());
        $this->assertFalse($review->isPending());

        $change2 = $this->createPendingChange('xyz789', '2nd change description', '//depot/main/foo/change2.txt');

        $result = $this->post(
            '/api/v1/reviews/' . $review->getId() . '/changes',
            array(
                'change' => $change2->getId(),
            )
        );

        $this->assertSame(200, $result->getStatusCode());

        $actual   = json_decode($this->getResponse()->getContent(), true);
        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'admin',
                'changes'       => array(1, 3),
                'commits'       => array(1),
                'commitStatus'  => array(),
                'created'       => null,
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => "change description\n",
                'groups'        => array(),
                'participants'  => array(
                    'admin'     => array(),
                    'nonadmin'  => array(),
                ),
                'pending'       => false,
                'projects'      => array(),
                'state'         => 'needsReview',
                'stateLabel'    => 'Needs Review',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
                'updated'       => null,
                'versions'      => array(),
            )
        );

        // ensure we have the same set of fields
        $this->assertSame(array_keys($actual['review']), array_keys($expected['review']));

        // discounting created/updated times, ensure we have the same data
        unset(
            $actual['review']['created'],
            $actual['review']['updated'],
            $expected['review']['created'],
            $expected['review']['updated']
        );
        $this->assertSame($actual, $expected);

        $review = Review::fetch($review->getId(), $this->p4);
        $this->assertSame(array(1, 3), $review->getChanges());
    }

    public function testBadAddChange()
    {
        $change1 = $this->createChange('test123', 'change description',   '//depot/main/foo/change1.txt');
        $change2 = $this->createChange('test567', 'change description 2', '//depot/main/foo/change1.txt');
        $review  = Review::createFromChange($change1)->save();

        // only post should be accepted
        $this->get('/api/v1/reviews/3/changes?change=1');

        $this->assertSame(405, $this->getResponse()->getStatusCode());
        $this->assertSame($this->getResult()->getVariables(), array('error' => 'Method Not Allowed'));

        // review id must be specified
        $this->post('/api/v1/reviews//changes', array('change' => 2));
        $this->assertSame(404, $this->getResponse()->getStatusCode());

        // review id must exist
        $this->post('/api/v1/reviews/123/changes', array('change' => 2));
        $this->assertSame(404, $this->getResponse()->getStatusCode());

        // change must exist (not a 404)
        $this->post('/api/v1/reviews/3/changes', array('change' => 123));
        $this->assertSame(400, $this->getResponse()->getStatusCode());
    }

    public function testGetReviewList()
    {
        $response = $this->get('/api/v1/reviews');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"lastSeen":null,"reviews":[],"totalCount":null}', $response->getContent());

        $change = $this->createChange();
        $review = Review::createFromChange($change->getId(), $this->p4);
        $review->save();

        $response = $this->get('/api/v1/reviews');
        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'lastSeen'   => 2,
            'reviews'    => array(
                array(
                    'id'            => 2,
                    'author'        => 'admin',
                    'changes'       => array(1),
                    'comments'      => array(0, 0),
                    'commits'       => array(1),
                    'commitStatus'  => array(),
                    'created'       => null,
                    'deployDetails' => array(),
                    'deployStatus'  => null,
                    'description'   => "change description\n",
                    'groups'        => array(),
                    'participants'  => array(
                        'admin'     => array(),
                    ),
                    'pending'       => false,
                    'projects'      => array(),
                    'state'         => 'needsReview',
                    'stateLabel'    => 'Needs Review',
                    'testDetails'   => array(),
                    'testStatus'    => null,
                    'type'          => 'default',
                    'updated'       => null
                ),
            ),
            'totalCount' => null,
        );

        // ensure we have the same set of fields
        $this->assertSame(array_keys($actual), array_keys($expected));
        $this->assertSame(array_keys($actual['reviews'][0]), array_keys($expected['reviews'][0]));

        // remove fields that contain timestamps
        unset($actual['reviews'][0]['created']);
        unset($actual['reviews'][0]['updated']);
        unset($expected['reviews'][0]['created']);
        unset($expected['reviews'][0]['updated']);

        $this->assertSame($actual, $expected);
    }

    public function testGetReviewListLimitByFields()
    {
        $change = $this->createChange();
        $review = Review::createFromChange($change->getId(), $this->p4);
        $review->save();

        $response = $this->get(
            '/api/v1/reviews'
            . '?fields[]=id&fields[]=author&fields[]=description&fields[]=pending'
            . '&fields[]=state&fields[]=stateLabel'
        );
        $this->assertSame(200, $response->getStatusCode());

        $actual   = json_decode($response->getContent(), true);
        $expected = array(
            'lastSeen'   => 2,
            'reviews'    => array(
                array(
                    'id'            => 2,
                    'author'        => 'admin',
                    'description'   => "change description\n",
                    'pending'       => false,
                    'state'         => 'needsReview',
                    'stateLabel'    => 'Needs Review',
                ),
            ),
            'totalCount' => null,
        );

        $this->assertSame($actual, $expected);
    }

    /**
     * @dataProvider getReviewListAuthorFilterItems
     */
    public function testGetReviewListAuthorFilter($query, $expected, $version)
    {
        $change1 = $this->createPendingChange(
            'lmnop12345',
            "I guess it's better to be who you are. Turns out people like you best that way, anyway.",
            '//depot/main/foo/change3.txt'
        );
        $change2 = $this->createPendingChange(
            'testTEST',
            "Uncle Sam Wants YOU!",
            '//depot/main/foo/change4.txt',
            'tester'
        );

        // start our sample reviews
        $review3 = Review::createFromChange($change1->getId())
            ->addParticipant(array('nonadmin', 'admin'))
            ->setState('needsRevision')
            ->save();
        $review4 = Review::createFromChange($change2->getId())
            ->save();

        $response = $this->get('/api/' . $version . '/reviews', $query);

        $this->assertSame(200, $response->getStatusCode());

        $actual            = json_decode($response->getContent(), true);
        $actual['reviews'] = array_map(
            function ($array) {
                return $array['id'];
            },
            $actual['reviews']
        );

        $this->assertSame($actual, $expected);
    }

    /**
     * @dataProvider getReviewListFilterItems
     */
    public function testGetReviewListFilter($query, $expected, $status = 200)
    {
        $change1 = $this->createChange(
            'abc123',
            'This is my change. There are many like it, but this one is mine.',
            '//depot/main/foo/change1.txt'
        );
        $change2 = $this->createChange(
            'xyz123',
            'I. Am. Mine.',
            '//depot/main/foo/change2.txt'
        );
        $change3 = $this->createPendingChange(
            'lmnop12345',
            "I guess it's better to be who you are. Turns out people like you best that way, anyway.",
            '//depot/main/foo/change3.txt'
        );
        $change4 = $this->createPendingChange(
            'testTEST',
            "Uncle Sam Wants YOU!",
            '//depot/main/foo/change4.txt'
        );
        $change5 = $this->createPendingChange(
            'troopers',
            "They're doing their part. Are you?",
            '//depot/main/foo/change5.txt'
        );
        $change6 = $this->createPendingChange(
            'starship',
            "Join the Mobile Infantry and save the world. Service guarantees citizenship.",
            '//depot/main/foo/change6.txt'
        );

        // start our sample reviews
        $review7 = Review::createFromChange($change2->getId())->setProjects('test')
            ->setTestDetails(array('url' => 'http://localhost/build/7'))
            ->set('testStatus', 'fail')
            ->save();
        $review8 = Review::createFromChange($change1->getId())->setProjects('test')
            ->setTestDetails(array('url' => 'http://localhost/build/8'))
            ->set('testStatus', 'pass')
            ->save();
        $review9 = Review::createFromChange($change6->getId())
            ->addParticipant(array('nonadmin', 'admin'))
            ->setState('needsRevision')
            ->save();

        $response = $this->get('/api/v1/reviews', $query);

        $this->assertSame($status, $response->getStatusCode());

        $actual            = json_decode($response->getContent(), true);
        $actual['reviews'] = array_map(
            function ($array) {
                return $array['id'];
            },
            $actual['reviews']
        );

        $this->assertSame($actual, $expected);
    }

    public function testReviewTransition()
    {
        $pending = $this->createPendingChange(
            'abc123',
            'This is my change. There are many like it, but this one is mine.',
            '//depot/main/foo/change1.txt'
        );
        $review  = Review::createFromChange($pending);
        $review->save();

        // verify the initial review data matches expected values
        $response = $this->get('/api/v1.2/reviews/' . $review->getId());
        $expected = array(
            'review'      => array(
                'id'            => 2,
                'author'        => 'nonadmin',
                'changes'       => array(
                    0 => 1,
                ),
                'commits'       => array(),
                'commitStatus'  => array(),
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => "This is my change. There are many like it, but this one is mine.\n",
                'groups'        => array(),
                'participants'  => array(
                    'nonadmin' => array(),
                ),
                'pending'       => true,
                'projects'      => array(),
                'state'         => 'needsReview',
                'stateLabel'    => 'Needs Review',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
                'versions'      => array(),
            )
        );

        $actual = json_decode($response->getContent(), true);
        unset($actual['review']['created']);
        unset($actual['review']['updated']);
        $this->assertSame($expected, $actual);

        // transition the review to 'needsRevision' and verify the expected data is returned
        $response = $this->patch(
            '/api/v2/reviews/' . $review->getId() . '/state/',
            array(
                'state'  => 'needsRevision'
            )
        );

        $expected['review']['state']      = 'needsRevision';
        $expected['review']['stateLabel'] = 'Needs Revision';

        // add expected transitions
        $expected['transitions'] = array(
            'needsReview' => 'Needs Review',
            'approved'    => 'Approve',
            'rejected'    => 'Reject',
            'archived'    => 'Archive',
        );

        $actual = json_decode($response->getContent(), true);
        unset($actual['review']['created']);
        unset($actual['review']['updated']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($expected, $actual);
    }

    /**
     * @dataProvider rejectedStates
     */
    public function testReviewTransitionStateRejected($state, $expected, $code)
    {
        $pending = $this->createPendingChange(
            'abc123',
            'This is my change. There are many like it, but this one is mine.',
            '//depot/main/foo/change1.txt'
        );
        $review  = Review::createFromChange($pending);
        $review->save();

        // transition the review to 'approved:commit' and verify the request is rejected
        $response = $this->patch(
            '/api/v2/reviews/' . $review->getId() . '/state/',
            array(
                'review' => $review->getId(),
                'state'  => $state
            )
        );

        $actual = json_decode($response->getContent(), true);

        $this->assertSame($expected, $actual);
        $this->assertSame($code, $response->getStatusCode());
    }

    public function testReviewTransitionCommitNoDescription()
    {
        $description = 'This is my change. There are many like it, but this one is mine.';
        $pending     = $this->createPendingChange('abc123', $description, '//depot/main/foo/change1.txt');

        // Queue the review and process the queue
        $this->post('/reviews/add', array('change' => $pending->getId()));
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // Approve and commit the review without a description
        $reviewId = 2;
        $response = $this->patch(
            '/api/v2/reviews/' . $reviewId . '/state/',
            array(
                'review' => $reviewId,
                'state'  => 'approved',
                'commit' => true,
            )
        );

        $this->assertSame(200, $response->getStatusCode());

        // Fetch the review and confirm the description is unchanged
        $review = Review::fetch($reviewId, $this->p4);
        $this->assertSame($description, trim($review->get('description')));

        // Grab the latest change and confirm the description matches the review
        $changes = $review->getChanges();
        $change  = Change::fetch(end($changes), $this->p4);
        $this->assertSame(array(1, 3, 4), $changes);
        $this->assertSame($description, trim($change->getDescription()));
        $this->assertFalse($change->isPending());
    }

    public function testReviewTransitionCommitOutdated()
    {
        $filespec    = '//depot/main/foo/change1.txt';
        $description = 'This is my change. There are many like it, but this one is mine.';
        $change      = $this->createChange('abc123', $description, $filespec);
        $pending     = $this->createPendingChange('def123', $description, $filespec, 'nonadmin', true);

        // queue the review and process the queue
        $this->post('/reviews/add', array('change' => $pending->getId()));
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // update the file to trigger an 'outdated' error condition
        $file = File::fetch($filespec);
        $file->edit()->setLocalContents('xyz789')->submit($description);

        // approve and commit the review
        $reviewId = 3;
        $response = $this->patch(
            '/api/v2/reviews/' . $reviewId . '/state/',
            array(
                'review' => $reviewId,
                'state'  => 'approved',
                'commit' => true,
            )
        );

        // check that we got the appropriate error message
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            array('error' => 'Out of date files must be resolved or reverted.'),
            json_decode($response->getContent(), true)
        );
    }

    public function testReviewTransitionCommit()
    {
        $pending = $this->createPendingChange(
            'abc123',
            'This is my change. There are many like it, but this one is mine.',
            '//depot/main/foo/change1.txt'
        );
        $review  = Review::createFromChange($pending);
        $review->save();
        $review->updateFromChange($pending);

        $response = $this->patch(
            '/api/v2/reviews/' . $review->getId() . '/state/',
            array(
                'review' => $review->getId(),
                'state'  => 'approved',
                'commit' => true,
                'description' => 'Committing my change.',
            )
        );

        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'nonadmin',
                'changes'       => array(
                    0 => 1,
                    1 => 3,
                    2 => 4,
                ),
                'commits'       => array(
                    0 => 4,
                ),
                'commitStatus'  => array(
                    'change'    => 4,
                    'status'    => 'Committed',
                    'committer' => 'nonadmin',
                ),
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => "This is my change. There are many like it, but this one is mine.\n",
                'groups'        => array(),
                'participants'  => array(
                    'nonadmin' => array(),
                ),
                'pending'       => true,
                'projects'      => array(),
                'state'         => 'approved',
                'stateLabel'    => 'Approved',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
            ),
            'transitions' => array(
                'needsReview'   => 'Needs Review',
                'needsRevision' => 'Needs Revision',
                'rejected'      => 'Reject',
                'archived'      => 'Archive'
            ),
            'commit' => 4,
        );
        $actual   = json_decode($response->getContent(), true);

        unset($actual['review']['commitStatus']['start']);
        unset($actual['review']['commitStatus']['end']);
        unset($actual['review']['created']);
        unset($actual['review']['updated']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($expected, $actual);

        // confirm the commit description is accurate
        $change = Change::fetch(end($expected['review']['changes']), $this->p4);
        $this->assertSame('Committing my change.', trim($change->getDescription()));
        $this->assertFalse($change->isPending());
    }

    public function testReviewTransitionCommitJobs()
    {
        $pending = $this->createPendingChange(
            'abc123',
            'This is my change. There are many like it, but this one is mine.',
            '//depot/main/foo/change1.txt'
        );
        $review  = Review::createFromChange($pending);
        $review->save();
        $review->updateFromChange($pending);

        $job = new Job;
        $job->setDescription('job xyz')
            ->setUser('foo')
            ->save();
        $this->assertSame('open', $job->getStatus());

        $response = $this->patch(
            '/api/v2/reviews/' . $review->getId() . '/state/',
            array(
                'review'      => $review->getId(),
                'state'       => 'approved',
                'commit'      => true,
                'description' => 'Committing my change.',
                'jobs'        => array($job->getId()),
                'fixStatus'   => 'closed'
            )
        );

        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'nonadmin',
                'changes'       => array(
                    0 => 1,
                    1 => 3,
                    2 => 4,
                ),
                'commits'       => array(
                    0 => 4,
                ),
                'commitStatus'  => array(
                    'change'    => 4,
                    'status'    => 'Committed',
                    'committer' => 'nonadmin',
                ),
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => "This is my change. There are many like it, but this one is mine.\n",
                'groups'        => array(),
                'participants'  => array(
                    'nonadmin' => array(),
                ),
                'pending'       => true,
                'projects'      => array(),
                'state'         => 'approved',
                'stateLabel'    => 'Approved',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
            ),
            'transitions' => array(
                'needsReview'   => 'Needs Review',
                'needsRevision' => 'Needs Revision',
                'rejected'      => 'Reject',
                'archived'      => 'Archive'
            ),
            'commit' => 4,
        );
        $actual   = json_decode($response->getContent(), true);

        unset($actual['review']['commitStatus']['start']);
        unset($actual['review']['commitStatus']['end']);
        unset($actual['review']['created']);
        unset($actual['review']['updated']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($expected, $actual);

        $change = Change::fetch($actual['review']['commits'][0], $this->p4);
        $jobs   = $change->getJobs();
        $this->assertSame(array('job000001'), $jobs);
        $this->assertSame('Committing my change.', trim($change->getDescription()));

        $job = Job::fetch('job000001');
        $this->assertSame('job xyz', trim($job->getDescription()));
        $this->assertSame('closed', $job->getStatus());
    }

    public function testReviewTransitionCommitJobsDoNotDisappear()
    {
        $pending = $this->createPendingChange(
            'abc123',
            'This is my change. There are many like it, but this one is mine.',
            '//depot/main/foo/change1.txt'
        );

        $job = new Job;
        $job->setDescription('job xyz')
            ->setUser('foo')
            ->save();
        $this->assertSame('open', $job->getStatus());

        $this->p4->run(
            'fix',
            array(
                '-c',
                $pending->getId(),
                $job->getId()
            )
        );

        $review = Review::createFromChange($pending);
        $review->save();
        $review->updateFromChange($pending);

        $review     = Review::fetch($review->getId(), $this->p4);
        $changeJobs = array();
        foreach ($review->getChanges() as $change) {
            $changeJobs += (array) Change::fetch($change)->getJobs();
        }
        $this->assertSame(array('job000001'), $changeJobs);

        $response = $this->patch(
            '/api/v2/reviews/' . $review->getId() . '/state/',
            array(
                'review'      => $review->getId(),
                'state'       => 'approved',
                'commit'      => true,
                'description' => 'Committing my change.',
            )
        );

        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'nonadmin',
                'changes'       => array(
                    0 => 1,
                    1 => 3,
                    2 => 4,
                ),
                'commits'       => array(
                    0 => 4,
                ),
                'commitStatus'  => array(
                    'change'    => 4,
                    'status'    => 'Committed',
                    'committer' => 'nonadmin',
                ),
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => "This is my change. There are many like it, but this one is mine.\n",
                'groups'        => array(),
                'participants'  => array(
                    'nonadmin' => array(),
                ),
                'pending'       => true,
                'projects'      => array(),
                'state'         => 'approved',
                'stateLabel'    => 'Approved',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
            ),
            'transitions' => array(
                'needsReview'   => 'Needs Review',
                'needsRevision' => 'Needs Revision',
                'rejected'      => 'Reject',
                'archived'      => 'Archive'
            ),
            'commit' => 4,
        );
        $actual   = json_decode($response->getContent(), true);

        unset($actual['review']['commitStatus']['start']);
        unset($actual['review']['commitStatus']['end']);
        unset($actual['review']['created']);
        unset($actual['review']['updated']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($expected, $actual);

        $change = Change::fetch($actual['review']['commits'][0], $this->p4);
        $jobs   = $change->getJobs();
        $this->assertSame(array('job000001'), $jobs);
        $this->assertSame('Committing my change.', trim($change->getDescription()));

        $job = Job::fetch('job000001');
        $this->assertSame('job xyz', trim($job->getDescription()));
        $this->assertSame('closed', $job->getStatus());
    }

    public function testReviewTransitionCommitJobsBlankedOut()
    {
        $pending = $this->createPendingChange(
            'abc123',
            'This is my change. There are many like it, but this one is mine.',
            '//depot/main/foo/change1.txt'
        );

        $job = new Job;
        $job->setDescription('job xyz')
            ->setUser('foo')
            ->save();
        $this->assertSame('open', $job->getStatus());

        $this->p4->run(
            'fix',
            array(
                '-c',
                $pending->getId(),
                $job->getId()
            )
        );

        $review = Review::createFromChange($pending);
        $review->save();
        $review->updateFromChange($pending);

        $review     = Review::fetch($review->getId(), $this->p4);
        $changeJobs = array();
        foreach ($review->getChanges() as $change) {
            $changeJobs += (array) Change::fetch($change)->getJobs();
        }
        $this->assertSame(array('job000001'), $changeJobs);

        $response = $this->patchJson(
            '/api/v2/reviews/' . $review->getId() . '/state/',
            array(
                'review'      => $review->getId(),
                'state'       => 'approved',
                'commit'      => true,
                'description' => 'Committing my change.',
                'jobs'        => array(),
            )
        );

        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'nonadmin',
                'changes'       => array(
                    0 => 1,
                    1 => 3,
                    2 => 4,
                ),
                'commits'       => array(
                    0 => 4,
                ),
                'commitStatus'  => array(
                    'change'    => 4,
                    'status'    => 'Committed',
                    'committer' => 'nonadmin',
                ),
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => "This is my change. There are many like it, but this one is mine.\n",
                'groups'        => array(),
                'participants'  => array(
                    'nonadmin' => array(),
                ),
                'pending'       => true,
                'projects'      => array(),
                'state'         => 'approved',
                'stateLabel'    => 'Approved',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
            ),
            'transitions' => array(
                'needsReview'   => 'Needs Review',
                'needsRevision' => 'Needs Revision',
                'rejected'      => 'Reject',
                'archived'      => 'Archive'
            ),
            'commit' => 4,
        );
        $actual   = json_decode($response->getContent(), true);

        unset($actual['review']['commitStatus']['start']);
        unset($actual['review']['commitStatus']['end']);
        unset($actual['review']['created']);
        unset($actual['review']['updated']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($expected, $actual);

        $change = Change::fetch($actual['review']['commits'][0], $this->p4);
        $jobs   = $change->getJobs();
        $this->assertSame(array(), $jobs);
        $this->assertSame('Committing my change.', trim($change->getDescription()));

        $job = Job::fetch('job000001');
        $this->assertSame('job xyz', trim($job->getDescription()));
        $this->assertSame('open', $job->getStatus());
    }

    public function testReviewTransitionCommitBadJob()
    {
        $pending = $this->createPendingChange(
            'abc123',
            'This is my change. There are many like it, but this one is mine.',
            '//depot/main/foo/change1.txt'
        );
        $review  = Review::createFromChange($pending);
        $review->save();
        $review->updateFromChange($pending);

        $response = $this->patch(
            '/api/v2/reviews/' . $review->getId() . '/state/',
            array(
                'review'      => $review->getId(),
                'state'       => 'approved',
                'commit'      => true,
                'description' => 'Committing my change.',
                'jobs'        => array(100),
                'fixStatus'   => 'closed'
            )
        );

        $expected = array(
            'review' => array(
                'id'            => 2,
                'author'        => 'nonadmin',
                'changes'       => array(
                    0 => 1,
                    1 => 3,
                    2 => 4,
                ),
                'commits'       => array(
                    0 => 4,
                ),
                'commitStatus'  => array(
                    'change'    => 4,
                    'status'    => 'Committed',
                    'committer' => 'nonadmin',
                ),
                'deployDetails' => array(),
                'deployStatus'  => null,
                'description'   => "This is my change. There are many like it, but this one is mine.\n",
                'groups'        => array(),
                'participants'  => array(
                    'nonadmin' => array(),
                ),
                'pending'       => true,
                'projects'      => array(),
                'state'         => 'approved',
                'stateLabel'    => 'Approved',
                'testDetails'   => array(),
                'testStatus'    => null,
                'type'          => 'default',
            ),
            'transitions' => array(
                'needsReview'   => 'Needs Review',
                'needsRevision' => 'Needs Revision',
                'rejected'      => 'Reject',
                'archived'      => 'Archive'
            ),
            'commit' => 4,
        );
        $actual   = json_decode($response->getContent(), true);

        unset($actual['review']['commitStatus']['start']);
        unset($actual['review']['commitStatus']['end']);
        unset($actual['review']['created']);
        unset($actual['review']['updated']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(array('error' => "Job '100' doesn't exist."), $actual);
    }

    public function testReviewTransitionCommitJobsBadStatus()
    {
        $pending = $this->createPendingChange(
            'abc123',
            'This is my change. There are many like it, but this one is mine.',
            '//depot/main/foo/change1.txt'
        );
        $review  = Review::createFromChange($pending);
        $review->save();
        $review->updateFromChange($pending);

        $job = new Job;
        $job->setDescription('job xyz')
            ->setUser('foo')
            ->save();
        $this->assertSame('open', $job->getStatus());

        $response = $this->patch(
            '/api/v2/reviews/' . $review->getId() . '/state/',
            array(
                'review'      => $review->getId(),
                'state'       => 'approved',
                'commit'      => true,
                'description' => 'Committing my change.',
                'jobs'        => array($job->getId()),
                'fixStatus'   => 'fixed'
            )
        );

        $actual = json_decode($response->getContent(), true);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(array('error' => 'Job fix status must be one of open/suspended/closed.'), $actual);
    }

    public function getReviewListAuthorFilterItems()
    {
        return array(
            // api version 1.2 requests include the author field, which can be an array or a string
            array(
                array('author' => 'tester'),
                array('lastSeen' => 4, 'reviews' => array(4), 'totalCount' => 1),
                'v1.2'
            ),
            array(
                array('author' => array('tester')),
                array('lastSeen' => 4, 'reviews' => array(4), 'totalCount' => 1),
                'v1.2'
            ),
            array(
                array('author' => array('nonadmin')),
                array('lastSeen' => 3, 'reviews' => array(3), 'totalCount' => 1),
                'v1.2'
            ),
            array(
                array('author' => array('nonadmin', 'tester')),
                array('lastSeen' => 3, 'reviews' => array(4,3), 'totalCount' => 2),
                'v1.2'
            ),
            // api version 1 and 1.1 requests ignore the authors field
            array(
                array('author' => array('tester')),
                array('lastSeen' => 3, 'reviews' => array(4,3), 'totalCount' => null),
                'v1.1'
            ),
            array(
                array('author' => array('nonadmin')),
                array('lastSeen' => 3, 'reviews' => array(4,3), 'totalCount' => null),
                'v1'
            ),
            array(
                array('author' => array('nonadmin', 'tester')),
                array('lastSeen' => 3, 'reviews' => array(4,3), 'totalCount' => null),
                'v1.1'
            ),
        );
    }

    public function getReviewListFilterItems()
    {
        return array(
            array(
                array('keywords' => 'anyway'),
                array('lastSeen' => null, 'reviews' => array(), 'totalCount' => 0)
            ),
            array(
                array('keywords' => 'am'),
                array('lastSeen' => 7, 'reviews' => array(7), 'totalCount' => 1)
            ),
            array(
                array('keywords' => 'mine'),
                array('lastSeen' => 7, 'reviews' => array(8,7), 'totalCount' => 2)
            ),
            array(
                array('max' => 1, 'keywords' => 'mine'),
                array('lastSeen' => 8, 'reviews' => array(8), 'totalCount' => 2)
            ),
            array(
                array('max' => 1, 'after' => 8, 'keywords' => 'mine'),
                array('lastSeen' => 7, 'reviews' => array(7), 'totalCount' => 2)
            ),
            array(
                array('change' => 3),
                array('lastSeen' => null, 'reviews' => array(), 'totalCount' => 0)
            ),
            array(
                array('change' => 2),
                array('lastSeen' => 7, 'reviews' => array(7), 'totalCount' => 1)
            ),
            array(
                array('participants' => 'nonadmin'),
                array('lastSeen' => 9, 'reviews' => array(9), 'totalCount' => 1)
            ),
            array(
                array('project' => 'test'),
                array('lastSeen' => 7, 'reviews' => array(8,7), 'totalCount' => 2)
            ),
            array(
                array('state' => 'needsRevision'),
                array('lastSeen' => 9, 'reviews' => array(9), 'totalCount' => 1)
            ),
            array(
                array('passesTests' => 'false'),
                array('lastSeen' => 7, 'reviews' => array(7), 'totalCount' => 1)
            ),
            array(
                array('passesTests' => 'true'),
                array('lastSeen' => 8, 'reviews' => array(8), 'totalCount' => 1)
            ),
            array(
                array('hasReviewers' => 1),
                array('lastSeen' => 9, 'reviews' => array(9), 'totalCount' => 1)
            ),
            array(
                array('hasReviewers' => 'true'),
                array('lastSeen' => 9, 'reviews' => array(9), 'totalCount' => 1)
            ),
            array(
                array('hasReviewers' => ''),
                array('lastSeen' => 9, 'reviews' => array(9), 'totalCount' => 1)
            ),
            array(
                array('hasReviewers' => 'false'),
                array('lastSeen' => 7, 'reviews' => array(8,7), 'totalCount' => 2)
            ),
            array(
                array('hasReviewers' => '0'),
                array('lastSeen' => 7, 'reviews' => array(8,7), 'totalCount' => 2)
            ),
        );
    }

    public function rejectedStates()
    {
        return array(
            array(
                'state'    => 'approve:commit',
                'expected' => array(
                    'error'   => 'Bad Request',
                    'details' => array('state' => "You cannot transition this review to 'approve:commit'.")
                ),
                'code'     => 400
            ),
            array(
                'state'    => 'foo',
                'expected' => array(
                    'error'   => 'Bad Request',
                    'details' => array('state' => "You cannot transition this review to 'foo'.")
                ),
                'code'     => 400
            )
        );
    }
}
