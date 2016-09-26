<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ShortLinksTest\Controller;

use ModuleTest\TestControllerCase;
use ShortLinks\Model\ShortLink;
use Zend\Stdlib\Parameters;

class IndexControllerTest extends TestControllerCase
{
    /**
     * Test adding (shortening) a new link.
     */
    public function testAddLink()
    {
        $data = new Parameters(array('uri' => 'http://some-host/example/link'));
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($data);

        // dispatch and check output
        $this->dispatch('/l');
        $result = $this->getResult();
        $this->assertRoute('short-link');
        $this->assertRouteMatch('shortlinks', 'shortlinks\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertSame('3fc1', $result->getVariable('id'));
        $this->assertSame('http://localhost/l/3fc1', $result->getVariable('uri'));

        // bump counter...
        $this->p4->run('counter', array('-u', ShortLink::KEY_COUNT, 2147483646));

        $this->resetApplication();

        $data = new Parameters(array('uri' => '/internal/link'));
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($data);

        // dispatch and check output
        $this->dispatch('/l');
        $result = $this->getResult();
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertSame('66szik0zj', $result->getVariable('id'));
        $this->assertSame('http://localhost/l/66szik0zj', $result->getVariable('uri'));
    }

    /**
     * Test adding a link with a weird protocol.
     */
    public function testBadLink()
    {
        $data = new Parameters(array('uri' => ' javascript:some-bad-stuff'));
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($data);

        // dispatch and check output
        $this->dispatch('/l');
        $result = $this->getResult();
        $this->assertRoute('short-link');
        $this->assertRouteMatch('shortlinks', 'shortlinks\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(400);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(false, $result->getVariable('isValid'));
        $this->assertSame(
            "Cannot shorten link. URI must start with 'http(s)://' or '/'.",
            $result->getVariable('error')
        );
    }

    /**
     * Test that short-links redirect to full links.
     */
    public function testResolveLink()
    {
        $link = new ShortLink($this->p4);
        $link->set('uri', 'http://my-host/testy')->save();

        // dispatch and check response header
        $this->dispatch('/l/' . $link->getObfuscatedId());
        $header = $this->getResponse()->getHeaders()->get('location');
        $this->assertSame('Location: http://my-host/testy', $header->toString());

        // bump counter...
        $this->p4->run('counter', array('-u', ShortLink::KEY_COUNT, 2147483646));

        $this->resetApplication();

        $link = new ShortLink($this->p4);
        $link->set('uri', '/files/some/deep/path/to/a/file')->save();

        // dispatch and check response header
        $this->dispatch('/l/' . $link->getObfuscatedId());
        $header = $this->getResponse()->getHeaders()->get('location');
        $this->assertSame('Location: http://localhost/files/some/deep/path/to/a/file', $header->toString());

        // verify short-host is used if configured
        $this->resetApplication();
        $services                          = $this->getApplication()->getServiceManager();
        $config                            = $services->get('config');
        $config['short_links']['hostname'] = 'test-host';
        $services->setService('config', $config);
    }
}
