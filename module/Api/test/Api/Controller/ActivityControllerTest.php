<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApiTest\Controller;

use Activity\Model\Activity;
use ModuleTest\TestApiController;
use P4\File\File;
use P4\Spec\Job;

class ActivityControllerTest extends TestApiController
{
    public function testActivityCreate()
    {
        $activity = array(
            'type'   => 'coffee',
            'user'   => 'A dingo',
            'action' => 'ate my',
            'target' => 'baby'
        );

        $this->post('/api/v1/activity', $activity, true);
        $actual   = json_decode($this->getResponse()->getContent(), true);
        $expected = array(
            'activity' => array(
                'id'            => 1,
                'action'        => 'ate my',
                'behalfOf'      => null,
                'change'        => null,
                'depotFile'     => null,
                'description'   => '',
                'details'       => array(),
                'followers'     => array(),
                'link'          => '',
                'preposition'   => 'for',
                'projects'      => array(),
                'streams'       => array(),
                'target'        => 'baby',
                'topic'         => '',
                'type'          => 'coffee',
                'user'          => 'A dingo'
            )
        );

        unset($actual['activity']['time']);

        $this->assertSame(200, $this->getResponse()->getStatusCode());
        $this->assertSame($expected, $actual);
    }

    public function testActivityCreateForbidden()
    {
        $activity = array(
            'type'   => 'coffee',
            'user'   => 'A dingo',
            'action' => 'ate my',
            'target' => 'baby'
        );

        $this->post('/api/v1/activity', $activity);
        $actual   = json_decode($this->getResponse()->getContent(), true);
        $expected = array('error' => 'Forbidden');

        $this->assertSame(403, $this->getResponse()->getStatusCode());
        $this->assertSame($expected, $actual);
    }

    public function testActivityList()
    {
        // grab the current activity list and confirm it is empty
        $this->get('/api/v2/activity');
        $body     = $this->getResponse()->getBody();
        $actual   = json_decode($body, true);
        $expected = array('activity' => array(), 'lastSeen' => null);

        $this->assertResponseStatusCode(200);
        $this->assertEquals($expected, $actual);
        $this->resetApplication();

        // add an event
        $file = new File;
        $file->setFilespec('//depot/foo');
        $file->setLocalContents('bar');
        $file->add();
        $file->submit('test');

        // process queue (should pull in existing jobs)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // grab the activity list and confirm it is no longer empty
        $this->get('/api/v2/activity');
        $body     = $this->getResponse()->getBody();
        $actual   = json_decode($body, true);
        $expected = array(
            'activity' => array(
                array(
                    'id'             => 1,
                    'action'         => 'committed',
                    'behalfOf'       => null,
                    'behalfOfExists' => null,
                    'change'         => 1,
                    'comments'       => array(0, 0),
                    'depotFile'      => null,
                    'description'    => "test\n",
                    'details'        => array(),
                    'followers'      => array(),
                    'link'           => array(
                        'change',
                        array('change' => 1)
                    ),
                    'preposition'    => 'into',
                    'projectList'    => array(),
                    'projects'       => array(),
                    'streams'        => array('user-admin', 'personal-admin'),
                    'target'         => 'change 1',
                    'topic'          => 'changes/1',
                    'type'           => 'change',
                    'url'            => '/changes/1',
                    'user'           => 'admin',
                    'userExists'     => 1,
                )
            ),
            'lastSeen' => 1
        );

        unset($actual['activity'][0]['date']);
        unset($actual['activity'][0]['time']);
        $this->assertResponseStatusCode(200);
        $this->assertEquals($expected, $actual);
        $this->resetApplication();
    }

    public function testActivityListFilter()
    {
        // process queue (should pull in existing jobs)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $change = $this->createPendingChange(
            'xyz123',
            'change description',
            '//depot/main/foo/test.txt',
            'admin'
        );

        $result = $this->post('/api/v2/reviews', array('change' => $change->getId()));

        $change = $this->createPendingChange(
            'abc789',
            'change description 2',
            '//depot/main/foo/test2.txt',
            'admin'
        );

        $result = $this->post('/api/v2/reviews', array('change' => $change->getId()));
        $result = $this->post(
            '/comments/add',
            array('topic' => 'reviews/2', 'body' => 'ooga booga', 'user' => 'nonadmin')
        );

        // process queue (should pull in existing jobs)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // grab the filtered list
        $result   = $this->get('/api/v2/activity?stream=review-4');
        $body     = $result->getBody();
        $actual   = json_decode($body, true);
        $expected = array(
            'activity' => array(
                array(
                    'id'             => 2,
                    'action'         => 'requested',
                    'behalfOf'       => null,
                    'behalfOfExists' => false,
                    'change'         => 4,
                    'comments'       => array(0, 0),
                    'depotFile'      => null,
                    'description'    => "change description 2\n",
                    'details'        => array(),
                    'followers'      => array(),
                    'link'           => array(
                        'review',
                        array('review' => 4)
                    ),
                    'preposition'    => 'for',
                    'projectList'    => array(),
                    'projects'       => array(),
                    'streams'        => array('review-4', 'user-nonadmin', 'personal-nonadmin', 'personal-admin'),
                    'target'         => 'review 4',
                    'topic'          => 'reviews/4',
                    'type'           => 'review',
                    'url'            => '/reviews/4/',
                    'user'           => 'nonadmin',
                    'userExists'     => true,
                )
            ),
            'lastSeen' => 2
        );

        unset($actual['activity'][0]['date']);
        unset($actual['activity'][0]['time']);
        $this->assertResponseStatusCode(200);
        $this->assertEquals($expected, $actual);
        $this->resetApplication();
    }

    public function testActivityListPaginate()
    {
        // generate activity entries
        $model = new Activity($this->p4);
        for ($i = 0; $i < 5; $i++) {
            $model->setId(null)
                ->set('test', "$i")
                ->save();
        }

        // grab the list
        $result   = $this->get('/api/v2/activity?max=2');
        $body     = $this->getResponse()->getBody();
        $actual   = json_decode($body, true);
        $expected = array(
            'activity' => array(
                array(
                    'id'             => 5,
                    'action'         => null,
                    'behalfOf'       => null,
                    'behalfOfExists' => null,
                    'change'         => null,
                    'comments'       => array(0, 0),
                    'depotFile'      => null,
                    'description'    => null,
                    'details'        => array(),
                    'followers'      => array(),
                    'link'           => null,
                    'preposition'    => 'for',
                    'projectList'    => array(),
                    'projects'       => array(),
                    'streams'        => null,
                    'target'         => null,
                    'test'           => 4,
                    'topic'          => null,
                    'type'           => null,
                    'url'            => null,
                    'user'           => null,
                    'userExists'     => null,
                ),
                array(
                    'id'             => 4,
                    'action'         => null,
                    'behalfOf'       => null,
                    'behalfOfExists' => null,
                    'change'         => null,
                    'comments'       => array(0, 0),
                    'depotFile'      => null,
                    'description'    => null,
                    'details'        => array(),
                    'followers'      => array(),
                    'link'           => null,
                    'preposition'    => 'for',
                    'projectList'    => array(),
                    'projects'       => array(),
                    'streams'        => null,
                    'target'         => null,
                    'test'           => 3,
                    'topic'          => null,
                    'type'           => null,
                    'url'            => null,
                    'user'           => null,
                    'userExists'     => null,
                )
            ),
            'lastSeen' => 4
        );

        unset(
            $actual['activity'][0]['date'],
            $actual['activity'][0]['time'],
            $actual['activity'][1]['date'],
            $actual['activity'][1]['time']
        );
        $this->assertResponseStatusCode(200);
        $this->assertEquals($expected, $actual);

        // grab the next page of the list
        $result   = $this->get('/api/v2/activity?max=2&after=4');
        $body     = $this->getResponse()->getBody();
        $actual   = json_decode($body, true);
        $expected = array(
            'activity' => array(
                array(
                    'id'             => 3,
                    'action'         => null,
                    'behalfOf'       => null,
                    'behalfOfExists' => null,
                    'change'         => null,
                    'comments'       => array(0, 0),
                    'depotFile'      => null,
                    'description'    => null,
                    'details'        => array(),
                    'followers'      => array(),
                    'link'           => null,
                    'preposition'    => 'for',
                    'projectList'    => array(),
                    'projects'       => array(),
                    'streams'        => null,
                    'target'         => null,
                    'test'           => 2,
                    'topic'          => null,
                    'type'           => null,
                    'url'            => null,
                    'user'           => null,
                    'userExists'     => null,
                ),
                array(
                    'id'             => 2,
                    'action'         => null,
                    'behalfOf'       => null,
                    'behalfOfExists' => null,
                    'change'         => null,
                    'comments'       => array(0, 0),
                    'depotFile'      => null,
                    'description'    => null,
                    'details'        => array(),
                    'followers'      => array(),
                    'link'           => null,
                    'preposition'    => 'for',
                    'projectList'    => array(),
                    'projects'       => array(),
                    'streams'        => null,
                    'target'         => null,
                    'test'           => 1,
                    'topic'          => null,
                    'type'           => null,
                    'url'            => null,
                    'user'           => null,
                    'userExists'     => null,
                )
            ),
            'lastSeen' => 2
        );

        unset(
            $actual['activity'][0]['date'],
            $actual['activity'][0]['time'],
            $actual['activity'][1]['date'],
            $actual['activity'][1]['time']
        );
        $this->assertResponseStatusCode(200);
        $this->assertEquals($expected, $actual);
        $this->resetApplication();
    }

    public function testActivityListLimitFields()
    {
        // grab the current activity list and confirm it is empty
        $this->get('/api/v2/activity');
        $body     = $this->getResponse()->getBody();
        $actual   = json_decode($body, true);
        $expected = array('activity' => array(), 'lastSeen' => null);

        $this->assertResponseStatusCode(200);
        $this->assertEquals($expected, $actual);
        $this->resetApplication();

        // add an event
        $file = new File;
        $file->setFilespec('//depot/foo');
        $file->setLocalContents('bar');
        $file->add();
        $file->submit('test');

        // process queue (should pull in existing jobs)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // grab the activity list and confirm it is no longer empty
        $this->get('/api/v2/activity?fields=id,description,date,user');
        $body     = $this->getResponse()->getBody();
        $actual   = json_decode($body, true);
        $expected = array(
            'activity' => array(
                array(
                    'id'             => 1,
                    'description'    => "test\n",
                    'user'           => 'admin',
                )
            ),
            'lastSeen' => 1
        );

        $this->assertResponseStatusCode(200);
        $this->assertNotEmpty($actual['activity'][0]['date']);
        unset($actual['activity'][0]['date']);

        $this->assertEquals($expected, $actual);
        $this->resetApplication();
    }
}
