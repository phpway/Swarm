<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace DemoTest\Controller;

use ModuleTest\TestControllerCase;
use P4\Spec\Change;
use P4\File\File;
use Zend\Stdlib\Parameters;

class IndexControllerTest extends TestControllerCase
{
    /**
     * Smoke test the data generation action.
     */
    public function testGenerateAction()
    {
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $services->get('p4_admin'));

        // make some changes
        for ($i = 0; $i < 10; $i++) {
            $file = new File($this->p4);
            $file->setFilespec($this->p4->getClientRoot() . '/foo' . $i)
                 ->setLocalContents('test')
                 ->add()
                 ->submit('test');
        }

        $this->dispatch('/demo/generate');

        $result = $this->getResult();
        $this->assertRoute('demo-generate');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(array('created' => 5,  'deleted' => 0), $result->getVariable('projects'));
        $this->assertSame(array('created' => 10, 'deleted' => 0), $result->getVariable('reviews'));
        $this->assertSame(array('created' => 50, 'deleted' => 0), $result->getVariable('comments'));

        // dispatch again, this time with 'reset'
        $this->getRequest()->setQuery(new Parameters(array('reset' => true)));
        $this->dispatch('/demo/generate');

        $result = $this->getResult();
        $this->assertRoute('demo-generate');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(array('created' => 5,  'deleted' => 5),  $result->getVariable('projects'));
        $this->assertSame(array('created' => 10, 'deleted' => 10), $result->getVariable('reviews'));
        $this->assertSame(array('created' => 50, 'deleted' => 50), $result->getVariable('comments'));
        $this->assertSame(5,  \Projects\Model\Project::fetchAll(array(), $this->p4)->count());
        $this->assertSame(10, \Reviews\Model\Review::fetchAll(array(), $this->p4)->count());
        $this->assertSame(50, \Comments\Model\Comment::fetchAll(array(), $this->p4)->count());
    }

    public function testGenerateUsersGroups()
    {
        // cannot run on servers without 'p4 group -A'
        if (!$this->p4->isServerMinVersion('2012.1')) {
            $this->markTestSkipped('Cannot add groups as admin. Server too old.');
        }

        // get baseline number of users that exist due to test setup
        $baseUsers = \P4\Spec\User::fetchAll(array(), $this->p4)->count();

        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $services->get('p4_admin'));

        $params = new Parameters(
            array(
                'users'     => 10,
                'groups'    => 3,
                'projects'  => 0,
                'reviews'   => 0,
                'comments'  => 0
            )
        );
        $this->getRequest()->setQuery($params);
        $this->dispatch('/demo/generate');

        $result = $this->getResult();
        $this->assertRoute('demo-generate');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(array('created' => 10, 'deleted' => 0), $result->getVariable('users'));
        $this->assertSame(array('created' => 3,  'deleted' => 0), $result->getVariable('groups'));
        $this->assertSame(array('created' => 0,  'deleted' => 0), $result->getVariable('projects'));
        $this->assertSame(array('created' => 0,  'deleted' => 0), $result->getVariable('reviews'));
        $this->assertSame(array('created' => 0,  'deleted' => 0), $result->getVariable('comments'));

        $this->assertSame(10 + $baseUsers, \P4\Spec\User::fetchAll(array(), $this->p4)->count());
        $this->assertSame(3, \P4\Spec\Group::fetchAll(array(), $this->p4)->count());
        $this->assertSame(0, \Projects\Model\Project::fetchAll(array(), $this->p4)->count());
        $this->assertSame(0, \Reviews\Model\Review::fetchAll(array(), $this->p4)->count());
        $this->assertSame(0, \Comments\Model\Comment::fetchAll(array(), $this->p4)->count());

        // now with more 'reset'
        $params->set('reset', true);
        $this->getRequest()->setQuery($params);
        $this->dispatch('/demo/generate');

        // we expect all users (except for the current user) to be deleted
        $userCount = 10 + $baseUsers - 1;

        $result = $this->getResult();
        $this->assertRoute('demo-generate');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(array('created' => 10, 'deleted' => $userCount), $result->getVariable('users'));
        $this->assertSame(array('created' => 3,  'deleted' => 3), $result->getVariable('groups'));
        $this->assertSame(array('created' => 0,  'deleted' => 0), $result->getVariable('projects'));
        $this->assertSame(array('created' => 0,  'deleted' => 0), $result->getVariable('reviews'));
        $this->assertSame(array('created' => 0,  'deleted' => 0), $result->getVariable('comments'));

        // we expect 11 users as the user we are connected as sticks around
        $this->assertSame(10 + 1, \P4\Spec\User::fetchAll(array(), $this->p4)->count());
        $this->assertSame(3, \P4\Spec\Group::fetchAll(array(), $this->p4)->count());
        $this->assertSame(0, \Projects\Model\Project::fetchAll(array(), $this->p4)->count());
        $this->assertSame(0, \Reviews\Model\Review::fetchAll(array(), $this->p4)->count());
        $this->assertSame(0, \Comments\Model\Comment::fetchAll(array(), $this->p4)->count());
    }
}
