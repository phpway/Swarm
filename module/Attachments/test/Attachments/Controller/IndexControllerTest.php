<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Attachments\test\Attachments\Controller;

use Attachments\Model\Attachment;
use ModuleTest\TestControllerCase;
use P4\File\File;
use Zend\Form\Element\DateTime;
use Zend\Json\Json;
use Zend\ServiceManager\ServiceManager;

class IndexControllerTest extends TestControllerCase
{
    public $storage;

    public function setUp()
    {
        parent::setUp();
        $services      = $this->getApplication()->getServiceManager();
        $this->storage = $services->get('depot_storage');
        $this->p4->setService('depot_storage', $this->storage);
    }

    /**
     * Test adding an attachment
     *
     * Currently this only tests that the "activity" message is properly suppressed, it does not actually test the full
     * action.
     */
    public function testAddAction()
    {
        // verify activity is empty
        $this->dispatch('/activity');
        $result   = $this->getResult();
        $body     = $this->getResponse()->getBody();
        $data     = Json::decode($body);
        $activity = $data->activity;
        $this->assertSame(0, count($activity));

        $attachment = new Attachment($this->p4);
        $attachment->set(
            array(
                'name' => 'test.jpg',
                'type' => 'image/jpg',
                'size' => 4,
            )
        );

        $tmp = tempnam(DATA_PATH, 'SWARM');
        file_put_contents($tmp, $data);

        $attachment->save($tmp);

        $id = $attachment->getId();
        $this->assertSame(1, $id);

        // verify activity is still empty
        $this->resetApplication();
        $this->processQueue();

        $this->dispatch('/activity');
        $result   = $this->getResult();
        $body     = $this->getResponse()->getBody();
        $data     = Json::decode($body);
        $activity = $data->activity;

        $this->resetApplication();
        $this->dispatch('/attachments/1');
        $response = $this->getApplication()->getMvcEvent()->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        // test that the expiration falls within a 10-second window of the expected 12 hours
        $actual   = $response->getHeaders()->get('Expires')->date()->getTimestamp();
        $expected = time() + 12 * 60 * 60;

        $this->assertGreaterThanOrEqual($expected - 5, $actual);
        $this->assertLessThanOrEqual($expected + 5, $actual);

        $this->assertSame(
            'Cache-Control: max-age=43200',
            $response->getHeaders()
                     ->get('Cache-Control')
                     ->toString()
        );

        $file = File::fetch($attachment->get('depotFile'));
        $file->delete();
        $file->submit('deleting');

        $this->resetApplication();
        $this->dispatch('/attachments/1');
        $response = $this->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    protected function processQueue()
    {
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
    }

    /**
     * Modify the configuration to inject test locations for depot storage, instead of using default ("//.swarm")
     *
     * This is necessary because in the test environment, creating "//.swarm" would be a bit messy.
     *
     * @param ServiceManager $services the service manager says you should wear more than the minimum 15 pieces of flair
     */
    protected function configureServiceManager(ServiceManager $services)
    {
        parent::configureServiceManager($services);
        $config                               = $services->get('config');
        $config['depot_storage']['base_path'] = '//depot/swarm_storage';
        $config['activity']['ignored_paths']  = array('//depot/swarm_storage');
        $services->setService('config', $config);
    }
}
