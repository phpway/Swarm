<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace FilesTest\Controller;

use Application\Permissions\Protections as ApplicationProtections;
use Files\Archiver;
use ModuleTest\TestControllerCase;
use P4\Connection\Connection;
use P4\File\File;
use P4\Spec\Change;
use P4\Spec\Client;
use P4\Spec\Protections;
use P4\Spec\User;
use Projects\Model\Project;
use Zend\Json\Json;
use Zend\Stdlib\Parameters;

class IndexControllerTest extends TestControllerCase
{
    /**
     * Test the file action.
     */
    public function testFileAction()
    {
        // add a file to the depot
        $file = new File;
        $file->setFilespec('//depot/foo/testfile1')
            ->open()
            ->setLocalContents('xyz123')
            ->submit('change test');

        $this->dispatch('/files/depot/foo/testfile1');

        $result = $this->getResult();
        $this->assertRoute('file');
        $this->assertRouteMatch('files', 'files\controller\indexcontroller', 'file');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertSame('depot/foo/testfile1', $result->getVariable('path'));
        $this->assertInstanceOf('P4\File\File', $result->getVariable('file'));
        $this->assertQueryContentContains('h1', 'testfile1');
    }

    /**
     * Test the list action with path specified.
     */
    public function testListActionWithPath()
    {
        // add few files to the depot
        $file = new File;
        $file->setFilespec('//depot/foo/test')
            ->open()
            ->setLocalContents('abc')
            ->submit('test1');
        $file = new File;
        $file->setFilespec('//depot/bar/test2')
            ->open()
            ->setLocalContents('xyz')
            ->submit('test2');
        $file = new File;
        $file->setFilespec('//depot/test3')
            ->open()
            ->setLocalContents('123')
            ->submit('test3');

        $this->dispatch('/files/depot');

        $result = $this->getResult();
        $this->assertRoute('file');
        $this->assertRouteMatch('files', 'files\controller\indexcontroller', 'file');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $this->assertSame('depot', $result->getVariable('path'));
        $dirs = $result->getVariable('dirs');
        $this->assertTrue(is_array($dirs) && count($dirs) === 2);
        $dirs = array(
            $dirs[0]['dir'],
            $dirs[1]['dir']
        );
        sort($dirs);
        $this->assertSame(array('//depot/bar', '//depot/foo'), $dirs);

        $files = $result->getVariable('files');
        $this->assertTrue(is_array($files) && count($files) === 1);
        $this->assertSame('//depot/test3', $files[0]['depotFile']);

        $this->assertQueryContentContains('h1', 'depot');
        $this->assertQueryContentContains('a[href="/files/depot/bar"]', 'bar');
        $this->assertQueryContentContains('a[href="/files/depot/foo"]', 'foo');
        $this->assertQueryContentContains('a[href="/files/depot/test3"]', 'test3');
    }

    /**
     * Test the list action with no path argument specified.
     */
    public function testListActionNoPath()
    {
        // add few files to the depot
        $file = new File;
        $file->setFilespec('//depot/foo/test')
            ->open()
            ->setLocalContents('abc')
            ->submit('test1');
        $file = new File;
        $file->setFilespec('//depot/test2')
            ->open()
            ->setLocalContents('123')
            ->submit('test2');

        $this->dispatch('/files');

        $result = $this->getResult();
        $this->assertRoute('file');
        $this->assertRouteMatch('files', 'files\controller\indexcontroller', 'file');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $this->assertSame('', $result->getVariable('path'));
        $dirs = $result->getVariable('dirs');
        $this->assertTrue(is_array($dirs) && count($dirs) === 1);
        $this->assertSame('depot', $dirs[0]['dir']);

        $files = $result->getVariable('files');
        $this->assertTrue(is_array($files) && count($files) === 0);

        $this->assertQueryContentContains('h1', '//');
        $this->assertQueryContentContains('a[href="/files/depot"]', 'depot');
    }

    /**
     * Test the list action with invalid path.
     */
    public function testListActionInvalidPath()
    {
        $this->dispatch('/files/depot/not-exist');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test emulating IP protections on a full dispatch cycle.
     */
    public function testEmulatingIpProtections()
    {
        // This test can have surprising results if the protections lines are not set wisely.
        // In a real case scenario, Swarm server and the user client will have in general
        // different IP address. When a command like 'p4 dirs' is issued by Swarm, Perforce
        // applies rules from the protections table according to Swarm's IP address. Then,
        // if protections emulation is enabled, the result is additionally filtered according
        // to the end-user's client IP. So for example, setting the following lines
        //
        //   list user * *        //depot/...
        //   list user * *       -//depot/a/...
        //   list user * 1.2.3.4  //depot/a/b/...
        //
        // in the protections table might not work as expected - as an example, the file
        // '//depot/a/b/foo/' will never be listed in files when dispatching to '/files/depot/a/b',
        // provided that we are emulating protections for IP 1.2.3.4, but the initial command
        // (before filtering due to emulating protections) is issued from a different IP (in
        // that case, the output will not contain any entries with filespec //depot/a/...).
        //
        // In a real case scenario, entries in the protections table for Swarm's IP should not
        // be restrictive from this reason.

        // tweak protections table
        $user        = $this->getApplication()->getServiceManager()->get('p4')->getUser();
        $protections = Protections::fetch($this->superP4);
        $protections->setProtections(
            array_merge(
                $protections->getProtections(),
                array(
                    "list user $user * //depot/...",
                    "list user $user proxy-1.2.3.4 -//depot/...",
                    "list user $user 1.2.3.4 //depot/b/...",
                    "list user $user proxy-1.2.3.4 //depot/a/...",
                    "list user $user 1.2.3.4 //depot/1",
                )
            )
        )->save();

        // add some files to the depot to test on
        $files = array(
            '//depot/1',
            '//depot/2',
            '//depot/a/3',
            '//depot/b/4',
            '//depot/c/5',
            '//depot/a/b/6',
            '//depot/a/c/7',
            '//depot/a/d/8',
            '//depot/a/b/c/9',
        );

        foreach ($files as $spec) {
            $basename = end(explode('/', $spec));
            $file     = new File;
            $file->setFilespec($spec)
                ->open()
                ->setLocalContents($basename)
                ->submit('test' . $basename);
        }

        // prepare function to get file name from the files passed to the view by the list action
        $getDepotFile = function ($file) {
            return $file['depotFile'];
        };

        // fake user's client IP
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        // Case 1: test with emulating IP protections (default)
        $this->dispatch('/files/depot');

        $result = $this->getResult();
        $this->assertRoute('file');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $this->assertSame(
            array(
                '//depot/a',
                '//depot/b',
            ),
            array_map('current', $result->getVariable('dirs'))
        );
        $this->assertSame(
            array(
                '//depot/1',
            ),
            array_map($getDepotFile, $result->getVariable('files'))
        );

        // Case 2: test without emulating IP protections
        $this->resetApplication();
        $services                                     = $this->getApplication()->getServiceManager();
        $config                                       = $services->get('config');
        $config['security']['emulate_ip_protections'] = false;
        $services->setService('config', $config);

        $this->dispatch('/files/depot');

        $result = $this->getResult();
        $this->assertRoute('file');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $this->assertSame(
            array(
                '//depot/a',
                '//depot/b',
                '//depot/c',
            ),
            array_map('current', $result->getVariable('dirs'))
        );
        $this->assertSame(
            array(
                '//depot/1',
                '//depot/2',
            ),
            array_map($getDepotFile, $result->getVariable('files'))
        );
    }

    /**
     * Test the list action with protections.
     */
    public function testListActionWithProtections()
    {
        // define protections to limit access to files
        $protections = array(
            array('perm' => 'read', 'depotFile' => '//...'),
            array('perm' => 'list', 'depotFile' => '//depot/foo/a/...', 'unmap' => true),
            array('perm' => 'list', 'depotFile' => '//depot/foo/3',     'unmap' => true)
        );

        // add some files to the depot to test on
        $files = array(
            '//depot/1',
            '//depot/2',
            '//depot/foo/3',
            '//depot/foo/4',
            '//depot/foo/a/5',
            '//depot/foo/a/6',
            '//depot/foo/b/7',
            '//depot/foo/b/8',
            '//depot/a/9',
            '//depot/a/10'
        );

        foreach ($files as $spec) {
            $basename = end(explode('/', $spec));
            $file     = new File;
            $file->setFilespec($spec)
                ->open()
                ->setLocalContents($basename)
                ->submit('test' . $basename);
        }

        // define a project
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'            => 'prj',
                'members'       => array('tester'),
                'description'   => 'test',
                'branches'      => array(
                    array(
                        'id'    => 'foo',
                        'paths' => '//depot/foo/...'
                    )
                )
            )
        )->save();

        // prepare function to get file name from the files passed to the view by the list action
        $getDepotFile = function ($file) {
            return $file['depotFile'];
        };

        // test browsing files with no protections
        $this->dispatch('/files/depot/foo');

        $result = $this->getResult();
        $this->assertRoute('file');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $this->assertSame(
            array(
                '//depot/foo/a',
                '//depot/foo/b'
            ),
            array_map('current', $result->getVariable('dirs'))
        );
        $this->assertSame(
            array(
                '//depot/foo/3',
                '//depot/foo/4'
            ),
            array_map($getDepotFile, $result->getVariable('files'))
        );

        // test browsing project files with no protections
        $this->resetApplication();
        $this->dispatch('/projects/prj/files/foo');

        $result = $this->getResult();
        $this->assertRoute('project-browse');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $this->assertSame(
            array('a', 'b'),
            array_map('current', $result->getVariable('dirs'))
        );
        $this->assertSame(
            array(
                '//depot/foo/3',
                '//depot/foo/4'
            ),
            array_map($getDepotFile, $result->getVariable('files'))
        );

        // test browsing files with protections
        $this->resetApplication();
        $this->getApplication()->getServiceManager()->get('ip_protects')
            ->setEnabled(true)
            ->setProtections($protections);

        $this->dispatch('/files/depot/foo');

        $result = $this->getResult();
        $this->assertRoute('file');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $this->assertSame(
            array('//depot/foo/b'),
            array_map('current', $result->getVariable('dirs'))
        );
        $this->assertSame(
            array('//depot/foo/4'),
            array_map($getDepotFile, $result->getVariable('files'))
        );

        // test browsing project files with protections
        $this->resetApplication();
        $this->getApplication()->getServiceManager()->get('ip_protects')
            ->setEnabled(true)
            ->setProtections($protections);

        $this->dispatch('/projects/prj/files/foo');

        $result = $this->getResult();
        $this->assertRoute('project-browse');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $this->assertSame(
            array('b'),
            array_map('current', array_values($result->getVariable('dirs')))
        );
        $this->assertSame(
            array('//depot/foo/4'),
            array_map($getDepotFile, $result->getVariable('files'))
        );
    }

    public function testArchiveAction()
    {
        // skip if the zip/unzip commands are not available
        exec('which zip',   $output, $zipResult);
        exec('which unzip', $output, $unzipResult);
        if ($zipResult !== 0 || $unzipResult !== 0) {
            $this->markTestSkipped('Cannot test archiving, zip and/or unzip command is not present');
        }

        // check in few files to test with
        $file = new File;
        $file->setFilespec('//depot/foo/a')
            ->open()
            ->setLocalContents('abc')
            ->submit('test1');
        $file = new File;
        $file->setFilespec('//depot/foo/b')
            ->open()
            ->setLocalContents('this is b')
            ->submit('test2');
        $file = new File;
        $file->setFilespec('//depot/bar/a')
            ->open()
            ->setLocalContents('bar')
            ->submit('test3');

        $output = $this->dispatch('/archive/depot/foo.zip');
        $result = $this->getResult();
        $this->assertRoute('archive');
        $this->assertRouteMatch('files', 'files\controller\indexcontroller', 'archive');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Application\Response\CallbackResponse', $result);

        // if available, unzip output and verify the content, otherwise mark test as incomplete
        if (!class_exists('\\ZipArchive')) {
            $this->markTestIncomplete('Cannot verify zip output.');
        }

        $zipTmpFile = tempnam(DATA_PATH, 'zip-');
        file_put_contents($zipTmpFile, $output);
        $zip = new \ZipArchive;
        $zip->open($zipTmpFile);

        // ensure the archive contains 3 items ('foo/', 'foo/a', 'foo/b')
        $this->assertSame(3, $zip->numFiles);

        $details0 = $zip->statIndex(0);
        $details1 = $zip->statIndex(1);
        $details2 = $zip->statIndex(2);

        $files = array($details0['name'], $details1['name'], $details2['name']);
        sort($files);
        $this->assertSame(array('foo/', 'foo/a', 'foo/b'), $files);
    }

    /**
     * @dataProvider archiveTestsProvider
     */
    public function testArchiveActionWithProtections(
        array $protectionLines,
        array $projectData,
        array $depotFiles,
        array $tests
    ) {
        // skip if the zip/unzip commands are not available
        exec('which zip',   $output, $zipResult);
        exec('which unzip', $output, $unzipResult);
        if ($zipResult !== 0 || $unzipResult !== 0) {
            $this->markTestSkipped('Cannot test archiving, zip and/or unzip command is not present');
        }

        // check in given files
        foreach ($depotFiles as $index => $filespec) {
            // we set file size to 2^($index)
            // this would allow us later to determine which files are in the archive based on total number
            // of bytes (as any number can be decomposed to a sum of powers of 2 in exactly one way)
            $contents = str_pad('', pow(2, $index), '1');
            $file     = new File;
            $file->setFilespec($filespec)->add()->setLocalContents($contents)->submit('test-' . $index);
        }

        // create a project if set
        if (isset($projectData['id'])) {
            $project = new Project;
            $project->set($projectData)->save();
        }

        // set the protections for testing - we automatically add lines for basic access and the super user
        $protections = Protections::fetch($this->superP4);
        $protections->setProtections(
            array_merge(
                array(
                    'write user * * //...',
                    'super user tester * //...'
                ),
                preg_replace('/\s{2,}/', ' ', $protectionLines)
            )
        )->save();

        // run tests, each test contains list of tests in the form:
        // [<user>, <ip>, <archive-path>]            => <expected-list-of-filtered-files> or
        // [<user>, <ip>, <project>, <archive-path>] => <expected-list-of-filtered-files>
        foreach ($tests as $testConfig => $expectedFiles) {
            $user = $ip = $project = $path = null;

            $config = array_map('trim', explode(',', $testConfig));

            if (count($config) === 3) {
                list($user, $ip, $path) = $config;
            } elseif (count($config) === 4) {
                list($user, $ip, $project, $path) = $config;
            } else {
                $this->fail("Invalid test key format - 3 or 4 items separated by comma are expected.");
            }

            // reset application and clear archives cache
            $this->resetApplication();
            foreach (glob(DATA_PATH . '/cache/archives/*') as $file) {
                is_file($file) && unlink($file);
            }

            // create user connection with services
            if (!User::exists($user, $this->superP4)) {
                $newUser = new User;
                $newUser->setId($user)
                    ->setFullName($user . ' - testing')
                    ->setEmail('test@test')
                    ->save();
            }

            $services = $this->getApplication()->getServiceManager();
            $p4Params = $this->getP4Params();
            $factory  = new \Application\Connection\ConnectionFactory(
                array(
                    'port'   => $p4Params['port'],
                    'user'   => $user
                )
            );

            $p4 = $factory->createService($services);

            // configure 'p4' and 'ip_protects' services
            $protections = $p4->run('protects', array('-h', $ip))->getData();
            $ipProtects  = new ApplicationProtections;
            $ipProtects->setProtections($protections);
            $services->setService('ip_protects', $ipProtects);
            $services->setService('p4', $p4);

            $url    = ($project ? '/projects/' . $project : '') . '/archives/' . trim($path, '/') . '.zip';
            $output = $this->dispatch($url);

            // prepare expected total uncompressed size of files expected to be present in the archive
            // expected files are given by indexes and we set each file's size based on its index as 2^index
            $size = array_sum(array_map('pow', array_fill(0, count($expectedFiles), 2), $expectedFiles));

            // check files info output
            $archiver  = $services->get('archiver');
            $path      = $p4->getClient() ? $p4->getClient() . '/' . $path : $path;
            $filesInfo = $archiver->getFilesInfo('//' . $path . '/...', $p4);

            $this->assertSame(count($expectedFiles), $filesInfo['count'], "Test: $testConfig");
            $this->assertSame($size, $filesInfo['size'], "Test: $testConfig");

            // unzip archive and verify the content if ZipArchive is available
            if (!class_exists('\\ZipArchive')) {
                $this->markTestIncomplete('Cannot verify zip output.');
            }

            $zipTmpFile = tempnam(DATA_PATH, 'zip-');
            file_put_contents($zipTmpFile, $output);
            $zip = new \ZipArchive;
            $zip->open($zipTmpFile);

            // calculate number of files in archive and their total uncompressed size
            $filesCount = $filesSize = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                // skip entries with zero size as zip reports all paths including directories
                $details = $zip->statIndex($i);
                if ($details['size'] > 0) {
                    $filesCount++;
                    $filesSize += $details['size'];
                }
            }

            $this->assertSame(count($expectedFiles), $filesCount, "Test: $testConfig");
            $this->assertSame($size, $filesSize, "Test: $testConfig");
        }
    }

    public function archiveTestsProvider()
    {
        return array(
            'minimal-access' => array(
                // protections
                array(
                    'list user * * -//...',
                    'read user * *  //...'
                ),
                // project data
                array(),
                // depot files
                array(
                    1  => '//depot/1',
                    2  => '//depot/2',
                    3  => '//depot/foo/1',
                    4  => '//depot/foo/2',
                    5  => '//depot/foo/bar/1',
                    6  => '//depot/foo/bar/2',
                    7  => '//depot/foo/bar/x/1',
                    8  => '//depot/foo/bar/y/2',
                ),
                // tests
                array(
                    'joe, 0.0.0.0, depot'           => array(1, 2, 3, 4, 5, 6, 7, 8),
                    'foo, 0.0.0.0, depot/foo'       => array(3, 4, 5, 6, 7, 8),
                    'bar, 0.0.0.0, depot/foo/bar'   => array(5, 6, 7, 8),
                    'joe, 0.0.0.0, depot/foo/bar/x' => array(7),
                    'joe, 0.0.0.0, depot/foo/bar/y' => array(8),
                )
            ),
            'limited-access' => array(
                // protections
                array(
                    'list  user foo 1.2.3.4 -//...',
                    'write user foo 1.2.3.4  //depot/foo/bar/...',
                    'list  user joe *       -//...',
                    'read  user joe *        //depot/...'
                ),
                // project data
                array(
                    'id'            => 'prj',
                    'name'          => 'prj',
                    'members'       => array('joe'),
                    'branches'      => array(
                        array(
                            'id'    => 'main',
                            'name'  => 'Main',
                            'paths' => '//depot/foo/...'
                        ),
                    ),
                ),
                // depot files
                array(
                    1 => '//depot/1',
                    2 => '//depot/foo/1',
                    3 => '//depot/foo/2',
                    4 => '//depot/foo/bar/1',
                    5 => '//depot/foo/bar/2',
                    6 => '//depot/foo/bar/x/1',
                    7 => '//depot/foo/bar/y/2',
                    8 => '//depot/foo/baz/1',
                    9 => '//depot/foo/baz/2',
                ),
                // tests
                array(
                    'foo, 0.0.0.0, depot'           => array(1, 2, 3, 4, 5, 6, 7, 8, 9),
                    'foo, 0.0.0.0, depot/foo'       => array(2, 3, 4, 5, 6, 7, 8, 9),
                    'foo, 0.0.0.0, depot/foo/bar/x' => array(6),
                    'foo, 1.2.3.4, depot'           => array(4, 5, 6, 7),
                    'foo, 1.2.3.4, depot/foo'       => array(4, 5, 6, 7),
                    'foo, 1.2.3.4, depot/foo/bar/x' => array(6),
                    'joe, 0.0.0.0, prj, main'       => array(2, 3, 4, 5, 6, 7, 8, 9),
                    'joe, 1.2.3.4, prj, main'       => array(2, 3, 4, 5, 6, 7, 8, 9),
                )
            ),
            'view-overlay' => array(
                // protections
                array(
                    'list user * * -//...',
                    'read user * *  //depot/x/...'
                ),
                // project data
                array(
                    'id'            => 'prj',
                    'name'          => 'prj',
                    'members'       => array('joe'),
                    'branches'      => array(
                        array(
                            'id'    => 'main',
                            'name'  => 'Main',
                            'paths' => array(
                                '//depot/foo/...',
                                '//depot/x/foo/...',
                                '//depot/x/bar/...',
                            )
                        ),
                    ),
                ),
                // depot files
                array(
                    1  => '//depot/1',
                    2  => '//depot/foo/1',
                    3  => '//depot/foo/bar/1',
                    4  => '//depot/x/foo/1',
                    5  => '//depot/x/foo/2',
                    6  => '//depot/x/joe/1',
                    7  => '//depot/x/joe/2',
                    8  => '//depot/x/bar/3',
                    9  => '//depot/x/bar/4',
                    10 => '//depot/y/1/2',
                ),
                // tests
                array(
                    'foo, 0.0.0.0, depot'     => array(4, 5, 6, 7, 8, 9),
                    'bar, 0.0.0.0, depot/x'   => array(4, 5, 6, 7, 8, 9),
                    'joe, 0.0.0.0, prj, main' => array(4, 5, 8, 9),
                )
            )
        );
    }

    public function testArchiveStatusNonExistant()
    {
        $output = $this->dispatch('/archive-status/123');
        $result = $this->getResult();

        $this->assertRoute('archive-status');
        $this->assertRouteMatch('files', 'files\controller\indexcontroller', 'archive');
        $this->assertResponseStatusCode(404);
    }

    public function testArchiveStatusValid()
    {
        // skip if the zip/unzip commands are not available
        exec('which zip',   $output, $zipResult);
        exec('which unzip', $output, $unzipResult);
        if ($zipResult !== 0 || $unzipResult !== 0) {
            $this->markTestSkipped('Cannot test archiving, zip and/or unzip command is not present');
        }

        // check in few files to test with
        $file = new File;
        $file->setFilespec('//depot/foo/a')
            ->open()
            ->setLocalContents('abc')
            ->submit('test1');
        $file = new File;
        $file->setFilespec('//depot/foo/b')
            ->open()
            ->setLocalContents('this is b')
            ->submit('test2');
        $file = new File;
        $file->setFilespec('//depot/bar/a')
            ->open()
            ->setLocalContents('bar')
            ->submit('test3');

        // compress files, it will create archive and status file
        $this->dispatch('/archive/depot.zip');

        $archiver = new Archiver;
        $info     = $archiver->getFilesInfo('//depot/...', $this->p4);

        $this->resetApplication();
        $this->dispatch('/archive-status/' . $info['digest']);

        $this->assertRoute('archive-status');
        $this->assertRouteMatch('files', 'files\controller\indexcontroller', 'archive');
        $this->assertResponseStatusCode(200);

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $this->getResult());
        $data = Json::decode($this->getResponse()->getBody(), Json::TYPE_ARRAY);
        $this->assertTrue($data['success']);
    }

    public function testDiffSymlink()
    {
        $p4     = $this->p4;
        $target = BASE_PATH . '/public/index.php';

        // this test uses specific path in the filesystem for the symlink,
        // skip if the path doesn't exist (this should not happen)
        if (!file_exists($target)) {
            $this->markTestSkipped();
        }

        // add file to depot that is a symbolic link
        $file = new File($p4);
        $file->setFilespec('//depot/foo');
        $file->createLocalPath();
        symlink($target, $file->getLocalFilename());
        $file->add()->submit('test');

        $this->dispatch('/diff?right=' . urlencode('//depot/foo'));
        $this->assertSame(200, $this->getResponse()->getStatusCode());
        $this->assertContains('Symlink file added.', $this->getResponse()->getContent());
    }

    public function testArchivePendingFile()
    {
        // skip if the zip/unzip commands are not available
        exec('which zip',   $output, $zipResult);
        exec('which unzip', $output, $unzipResult);
        if ($zipResult !== 0 || $unzipResult !== 0) {
            $this->markTestSkipped('Cannot test archiving, zip and/or unzip command is not present');
        }

        $filespec = '//depot/main/foo/test.txt';
        $edit     = false;
        $change   = new Change($this->p4);
        $change->setDescription('change description')->save();

        $file = new File($this->p4);
        $file->setFilespec($filespec);
        $file->setLocalContents('xyz123');

        if ($edit) {
            $file->edit($change->getId());
        } else {
            $file->add($change->getId());
        }

        $this->p4->run('shelve', array('-c', $change->getId(), '//...'));
        $this->p4->run('revert', array('//...'));

        $change->setUser('nonadmin')->save();

        // compress files, it will create archive and status file
        $result = $this->dispatch('/archive/' . ltrim($filespec, '/') . '.zip?v=%40%3D1');
        $this->assertResponseStatusCode(200);
        $this->assertSame('', $this->getResponse()->getContent());

        $archiver = new Archiver;
        $info     = $archiver->getFilesInfo($filespec . '@=1', $this->p4);

        $this->resetApplication();
        $this->dispatch('/archive-status/' . $info['digest']);

        $this->assertRoute('archive-status');
        $this->assertRouteMatch('files', 'files\controller\indexcontroller', 'archive');
        $this->assertResponseStatusCode(200);

        $this->assertInstanceOf('Zend\View\Model\JsonModel', $this->getResult());
        $data = Json::decode($this->getResponse()->getBody(), Json::TYPE_ARRAY);
        $this->assertTrue($data['success']);
    }

    public function testArchiveWithSymbolicLinks()
    {
        $services = $this->getApplication()->getServiceManager();
        $p4       = $this->p4;
        $target   = BASE_PATH . '/public/index.php';

        // this test uses specific path in the filesystem for the symlink,
        // skip if the path doesn't exist (this should not happen)
        if (!file_exists($target)) {
            $this->markTestSkipped();
        }

        // we will use zip/unzip to extract files from the archive, skip if they are not available
        exec('which zip',   $output, $zipResult);
        exec('which unzip', $output, $unzipResult);
        if ($zipResult !== 0 || $unzipResult !== 0) {
            $this->markTestSkipped('Cannot verify zip with symlinks, zip and/or unzip command is not present');
        }

        // add file to depot that is a symbolic link
        $file = new File($p4);
        $file->setFilespec('//depot/foo');
        $file->createLocalPath();
        symlink($target, $file->getLocalFilename());
        $file->add()->submit('test');

        $output = $this->dispatch('/archive/depot/foo.zip');

        // unzip output and verify the extracted file came out as a link
        $zipTmpFile = tempnam(DATA_PATH, 'zip-');
        file_put_contents($zipTmpFile, $output);

        $extractDir = DATA_PATH . '/archive';
        mkdir($extractDir);
        exec("unzip $zipTmpFile -d $extractDir");

        $this->assertTrue(file_exists($extractDir . '/foo'));
        $this->assertTrue(is_link($extractDir . '/foo'), 'Expected extracted file is a symlink.');
    }

    /**
     * Test the case when viewing file that doesn't exist.
     */
    public function testNonExistantFile()
    {
        $this->dispatch('/files/depot/notexist');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test file download.
     */
    public function testDownloadFile()
    {
        // add a file to the depot
        $file = new File;
        $file->setFilespec('//depot/foo/testfile1')
            ->open()
            ->setLocalContents('xyz123')
            ->submit('test');

        $request = $this->getRequest();
        $request->setQuery(new Parameters(array('download' => true)));

        $output   = $this->dispatch('/files/depot/foo/testfile1');
        $response = $this->getApplication()->getMvcEvent()->getResult();

        $this->assertSame('xyz123', $output);
        $this->assertInstanceOf('Application\Response\CallbackResponse', $response);
    }

    /**
     * Test file view.
     */
    public function testViewFile()
    {
        // prepare file content
        $fileContent = file_get_contents(__FILE__);

        // add a file to the depot
        $file = new File;
        $file->setFilespec('//depot/foo/testfile1')
            ->open()
            ->setLocalContents($fileContent)
            ->submit('test');

        $request = $this->getRequest();
        $request->setQuery(new Parameters(array('view' => true)));

        $output = $this->dispatch('/files/depot/foo/testfile1');
        $this->assertSame($fileContent, $output);
    }

    /**
     * Test getting only a select range of a file
     */
    public function testViewLineRange()
    {
        // prepare file content
        $fileContent = array("Line 1\n","Line 2\r\n","Line 3\n","Line 4\r","Line 5 ");

        // add a file to the depot
        $file = new File;
        $file->setFilespec('//depot/foo/testfile1')
            ->open()
            ->setLocalContents(implode($fileContent))
            ->submit('test');

        // Test grabbing just one line from the start of the file
        $request = $this->getRequest();
        $request->setQuery(new Parameters(array('view' => true, 'lines' => '1-1')));

        $output = $this->dispatch('/files/depot/foo/testfile1');
        $this->assertSame($fileContent[0], $output);

        // Test grabbing the first two lines, and the last line, skipping the rest
        $this->resetApplication();
        $request = $this->getRequest();
        $request->setQuery(
            new Parameters(array('view' => true, 'lines' => array('1-2', array('start' => 5, 'end' => 5))))
        );

        $output   = $this->dispatch('/files/depot/foo/testfile1');
        $expected = array($fileContent[0], $fileContent[1], $fileContent[4]);
        $this->assertSame(implode($expected), $output);

        // Test grabbing lines past the end of the content and using just start and end syntax
        $this->resetApplication();
        $request = $this->getRequest();
        $request->setQuery(new Parameters(array('view' => true, 'lines' => array('start' => 3, 'end' => 6))));

        $output = $this->dispatch('/files/depot/foo/testfile1');
        $this->assertSame(implode(array_slice($fileContent, 2)), $output);

        // Test grabbing overlapping lines
        $this->resetApplication();
        $request = $this->getRequest();
        $request->setQuery(new Parameters(array('view' => true, 'lines' => array('1-3', '2-5'))));

        $output = $this->dispatch('/files/depot/foo/testfile1');
        $this->assertSame(implode($fileContent), $output);
    }
}
