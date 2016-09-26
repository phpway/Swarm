<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Controller;

use ModuleTest\TestControllerCase;
use Zend\Stdlib\Parameters;

class CsrfTest extends TestControllerCase
{
    public function testComment()
    {
        $postData = new Parameters(
            array(
                'topic'     => 'a',
                'body'      => 'q w e r t y u i o p',
                'user' => 'nonadmin'
            )
        );

        // no csrf token
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);
        $this->dispatch('/comments/add', false);
        $this->assertRoute('add-comment');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(403);

        // bad csrf token in post
        $this->resetApplication();
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData)
             ->getPost()->set('_csrf', 'foo');
        $this->dispatch('/comments/add', false);
        $this->assertRoute('add-comment');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(403);

        // good csrf token in get (fails)
        $this->resetApplication();
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData)
             ->getQuery()->set('_csrf', $this->application->getServiceManager()->get('csrf')->getToken());
        $this->dispatch('/comments/add', false);
        $this->assertRoute('add-comment');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(403);

        // good csrf token in post
        $this->resetApplication();
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setQuery(new Parameters)
             ->setPost($postData)
             ->getPost()->set('_csrf', $this->application->getServiceManager()->get('csrf')->getToken());
        $this->dispatch('/comments/add', false);
        $result = $this->getResult();
        $this->assertRoute('add-comment');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(true, $result->getVariable('isValid'));
    }
}
