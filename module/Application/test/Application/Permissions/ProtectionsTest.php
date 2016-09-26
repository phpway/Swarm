<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Permissions;

use Application\Permissions\Protections as ApplicationProtections;
use P4\Connection\Connection;
use P4\Spec\User;
use P4\Spec\Protections;
use P4Test\TestCase;

class ProtectionsTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                 'Zend\Loader\StandardAutoloader' => array(
                     'namespaces' => array(
                         'Application' => BASE_PATH . '/module/Application/src/Application'
                     )
                 )
            )
        );
    }

    /**
     * @dataProvider filterPathsProvider
     */
    public function testFilterPaths(array $protectionLines, array $paths, array $tests, $caseInsensitiveOnly = false)
    {
        // first, run tests with the original server's case sensitivity settings, skip tests if they
        // are intended to run on case insensitive server, but we are connected to case sensitive server
        if (!$caseInsensitiveOnly || !$this->p4->isCaseSensitive()) {
            $this->runTests($protectionLines, $paths, $tests, $this->p4->isCaseSensitive());
        }

        // all test should always pass as case insensitive, verify
        $this->runTests($protectionLines, $paths, $tests, false);
    }

    /**
     * Helper method to run the tests specified by given data.
     */
    protected function runTests(array $protectionLines, array $paths, array $tests, $isCaseSensitive)
    {
        $protections = Protections::fetch($this->p4);

        // set the protections for testing - we automatically add lines for basic access and the super user
        $protections->setProtections(
            array_merge(
                array(
                    'list user * * //...',
                    'super user tester * //...'
                ),
                preg_replace('/\s{2,}/', ' ', $protectionLines)
            )
        )->save();

        // prepare connections list to re-use
        $connections = array('tester' => $this->p4);

        // run tests, each test contains list of tests in the form:
        // [<user>, <mode>, <ip>] => <expected-list-of-filtered-paths>
        foreach ($tests as $config => $expected) {
            list($user, $mode, $ip) = array_map('trim', explode(',', $config));

            // create user/connection if we don't have it
            if (!isset($connections[$user])) {
                if (!User::exists($user, $this->p4)) {
                    $newUser = new User($this->p4);
                    $newUser->setId($user)
                        ->setFullName($user . ' - testing')
                        ->setEmail('test@test')
                        ->save();
                }

                $p4Params           = $this->getP4Params();
                $connections[$user] = Connection::factory(
                    $p4Params['port'],
                    $user,
                    'client-' . $user . '-test-files',
                    '',
                    null,
                    null
                );
            }

            $p4          = $connections[$user];
            $protections = $p4->run('protects', array('-h', $ip))->getData();
            $ipProtects  = new ApplicationProtections;
            $ipProtects->setProtections($protections, $isCaseSensitive);

            // filter paths and compare results
            $filtered = $ipProtects->filterPaths($paths, $mode);

            // expand $expected to a list of files (its given by files' keys expected to remain after filtering)
            $expected = $expected
                ? array_intersect_key($paths, array_combine($expected, $expected))
                : array();

            // sort filtered and expected lists to compare the values, not the order
            sort($expected);
            sort($filtered);

            $this->assertSame($filtered, $expected, "[User: $user, Mode: $mode, IP: $ip]");
        }
    }

    /**
     * Data provider for testing users' access to files with given protections.
     * Each data set contains 4 pieces:
     *  - list of protections (as written in Perforce protections table)
     *  - list of paths to test access to
     *  - list with access test, each contains 'user, mode, ip-address' as key
     *    and a list of referencesto paths with allowed access as value
     *  - optional boolean flag to restrict running the test on case insensitive
     *    server only (if true) or run it always (false, by default)
     */
    public function filterPathsProvider()
    {
        return array(
            'default' => array(
                array(),
                array(
                    1 => '//depot/test',
                    2 => '//bar/test',
                    3 => '//foo/test'
                ),
                array(
                    'foo, read,  1.2.3.4' => array(),
                    'foo, open,  1.2.3.4' => array(),
                    'foo, write, 1.2.3.4' => array(),
                    'foo, admin, 1.2.3.4' => array(),
                    'foo, super, 1.2.3.4' => array()
                )
            ),
            'basic-write' => array(
                array(
                    'write user foo * //foo/...'
                ),
                array(
                    1 => '//depot/test',
                    2 => '//bar/test',
                    3 => '//foo/test'
                ),
                array(
                    'foo, read,  1.2.3.4' => array(3),
                    'foo, open,  1.2.3.4' => array(3),
                    'foo, write, 1.2.3.4' => array(3),
                    'foo, admin, 1.2.3.4' => array(),
                    'foo, super, 1.2.3.4' => array()
                )
            ),
            'basic-read-ip' => array(
                array(
                    'read  user foo *       //foo/all/...',
                    'write user foo 1.2.3.4 //foo/1234/...',
                ),
                array(
                    1 => '//depot/test',
                    2 => '//foo/test',
                    3 => '//foo/all/test',
                    4 => '//foo/1234/test'
                ),
                array(
                    'foo, read,  0.0.0.0' => array(3),
                    'foo, open,  0.0.0.0' => array(),
                    'foo, read,  1.2.3.4' => array(3, 4),
                    'foo, write, 1.2.3.4' => array(4),
                    'foo, admin, 1.2.3.4' => array(),
                )
            ),
            'basic-allow-deny-ip' => array(
                array(
                    'write user *   *        //...',
                    'list  user foo 1.2.3.4 -//foo/...'
                ),
                array(
                    1 => '//depot/test',
                    2 => '//bar/test',
                    3 => '//foo/test'
                ),
                array(
                    'foo, read,  1.2.3.0' => array(1, 2, 3),
                    'foo, write, 0.0.0.0' => array(1, 2, 3),
                    'foo, read,  1.2.3.4' => array(1, 2),
                    'foo, write, 1.2.3.4' => array(1, 2),
                    'foo, admin, 1.2.3.4' => array(),
                )
            ),
            'basic-deny-allow-ip' => array(
                array(
                    'list  user foo *       -//foo/...',
                    'write user bar *        //...',
                    'write user foo 6.7.8.9  //foo/...'
                ),
                array(
                    1 => '//depot/test',
                    2 => '//bar/test',
                    3 => '//foo/test'
                ),
                array(
                    'foo, read,  0.0.0.0' => array(),
                    'foo, write, 0.0.0.0' => array(),
                    'foo, admin, 0.0.0.0' => array(),
                    'foo, read,  6.7.8.9' => array(3),
                    'foo, write, 6.7.8.9' => array(3),
                    'foo, admin, 6.7.8.9' => array(),
                    'bar, read,  0.0.0.0' => array(1, 2, 3),
                    'bar, write, 0.0.0.0' => array(1, 2, 3),
                    'bar, admin, 0.0.0.0' => array(),
                )
            ),
            'one-file-access' => array(
                array(
                    'list user foo 1.2.3.4 -//...',
                    'read user foo 1.2.3.4  //depot/a/b/foo'
                ),
                array(
                    1  => 'depot/',
                    2  => '//depot',
                    3  => '//depot/',
                    4  => '//depot/a',
                    5  => '//depot/a/',
                    6  => '//depot/a/b',
                    7  => '//depot/a/b/',
                    8  => '//depot/a/b/c',
                    9  => '//depot/a/b/foo',
                    10 => '//depot/a/b/foo/',
                    11 => '//depot/a/b/foo/x'
                ),
                array(
                    'foo, list,  0.0.0.0' => range(2, 11),
                    'foo, list,  1.2.3.4' => array(3, 5, 7, 9),
                    'foo, read,  0.0.0.0' => array(),
                    'foo, read,  1.2.3.4' => array(9),
                )
            ),
            'dirs-deep' => array(
                array(
                    'list user foo 1.2.3.4 -//...',
                    'list user foo 1.2.3.4  //depot/a/b/c/...'
                ),
                array(
                    1  => 'depot/',
                    2  => '//depot',
                    3  => '//depot/',
                    4  => '//depot/a',
                    5  => '//depot/a/',
                    6  => '//depot/b/',
                    7  => '//depot/a/b',
                    8  => '//depot/a/b/',
                    9  => '//depot/a/b/foo',
                    10 => '//depot/a/b/c',
                    11 => '//depot/a/b/c/',
                    12 => '//depot/a/b/c/foo',
                    13 => '//depot/a/b/c/d/e/f/g/'
                ),
                array(
                    'foo, list,  0.0.0.0' => range(2, 13),
                    'foo, list,  1.2.3.4' => array(3, 5, 8, 11, 12, 13),
                    'foo, read,  0.0.0.0' => array(),
                    'foo, read,  1.2.3.4' => array(),
                )
            ),
            'dirs-deep-2' => array(
                array(
                    'list user foo 1.2.3.4 -//...',
                    'list user foo 1.2.3.4  //depot/a/...',
                    'list user foo 1.2.3.4 -//depot/a/x/...'
                ),
                array(
                    1  => 'depot/',
                    2  => '//depot',
                    3  => '//depot/',
                    4  => '//depot/a',
                    5  => '//depot/a/',
                    6  => '//depot/b',
                    7  => '//depot/b/',
                    8  => '//depot/x',
                    9  => '//depot/x/',
                    10 => '//depot/a/x',
                    11 => '//depot/a/x/',
                    12 => '//depot/a/x/foo',
                    13 => '//depot/a/b/foo',
                    14 => '//depot/a/b/c/d/foo',
                    15 => '//depot/a/b/c/d/foo/',
                    16 => '//depot/a/x/c/d/foo/'
                ),
                array(
                    'foo, list,  0.0.0.0' => range(2, 16),
                    'foo, list,  1.2.3.4' => array(3, 5, 10, 13, 14, 15),
                    'foo, read,  0.0.0.0' => array(),
                    'foo, read,  1.2.3.4' => array(),
                )
            ),
            'dirs-overwrite' => array(
                array(
                    'open  user foo *         //...',
                    'list  user foo 1.2.3.4  -//depot/a/...',
                    'list  user foo 1.2.3.4   //depot/a/...'
                ),
                array(
                    1 => 'depot/',
                    2 => '//depot',
                    3 => '//depot/',
                    4 => '//depot/test/',
                    5 => '//depot/a/test/'
                ),
                array(
                    'foo, list,  0.0.0.0' => array(2, 3, 4, 5),
                    'foo, list,  1.2.3.4' => array(2, 3, 4, 5),
                    'foo, read,  0.0.0.0' => array(2, 3, 4, 5),
                    'foo, read,  1.2.3.4' => array(2, 3, 4),
                )
            ),
            'dirs-test' => array(
                array(
                    'list  user foo *        -//...',
                    'list  user foo 1.2.3.4   //depot/a/...',
                    'list  user foo 1.2.3.4  -//depot/a/b/...',
                    'list  user foo 1.2.3.4   //depot/a/b/x/y/z/...',
                    'list  user foo 1.2.3.4   //depot/c/...'
                ),
                array(
                    1  => 'depot/',
                    2  => '//depot',
                    3  => '//depot/',
                    4  => '//depot/test/',
                    5  => '//depot/a/',
                    6  => '//depot/a/b/',
                    7  => '//depot/a/b/c/',
                    8  => '//depot/a/b/x/',
                    9  => '//depot/a/b/x/a/',
                    10 => '//depot/a/b/x/y/',
                    11 => '//depot/a/b/x/y/a/',
                    12 => '//depot/a/b/x/y/z/',
                    13 => '//depot/a/b/x/y/z/a/',
                    14 => '//depot/a/d/',
                    15 => '//depot/b/',
                    16 => '//depot/b/a',
                    17 => '//depot/c/',
                    18 => '//depot/c/a/',
                    19 => '//depot/a/foo',
                    20 => '//depot/a/b/foo',
                    21 => '//depot/a/b/x/y/z'
                ),
                array(
                    'foo, list,  0.0.0.0' => array(),
                    'foo, list,  1.2.3.4' => array(3, 5, 6, 8, 10, 12, 13, 14, 17, 18, 19),
                    'foo, read,  0.0.0.0' => array(),
                    'foo, read,  1.2.3.4' => array(),
                )
            ),
            'wildcards-star' => array(
                array(
                    'list user foo 1.2.3.4 -//...',
                    'read user foo 1.2.3.4  //foo/*/a*/...'
                ),
                array(
                    1  => 'depot/',
                    2  => '//depot',
                    3  => '//depot/',
                    4  => '//foo',
                    5  => '//foo/',
                    6  => '//foo/a/',
                    7  => '//foo/a/a/',
                    8  => '//foo/x/a',
                    9  => '//foo/x/b',
                    10 => '//foo/x/b/y',
                    11 => '//foo/x/a/bar'
                ),
                array(
                    'foo, list,   0.0.0.0' => range(2, 11),
                    'foo, list,   1.2.3.4' => array(5, 6, 7, 11),
                    'foo, read,   0.0.0.0' => array(),
                    'foo, read,   1.2.3.4' => array(7, 11),
                    'foo, write,  0.0.0.0' => array(),
                    'foo, write,  1.2.3.4' => array(),
                )
            ),
            'wildcards-3dot' => array(
                array(
                    'list user foo 1.2.3.4 -//...',
                    'read user foo 1.2.3.4  //foo/.../x/...'
                ),
                array(
                    1  => 'depot/',
                    2  => '//depot',
                    3  => '//depot/',
                    3  => '//depot/a/x/foo',
                    4  => '//foo',
                    5  => '//foo/',
                    6  => '//foo/a/',
                    7  => '//foo/a/a/',
                    8  => '//foo/a/a',
                    9  => '//foo/x/b',
                    10 => '//foo/x/b/y',
                    11 => '//foo/a/x/x',
                    12 => '//foo/a/b/c/x/foo',
                    13 => '//foo/a/b/c/d/foo',
                    14 => '//foo/a/b/c/d/foo/'
                ),
                array(
                    'foo, list,  0.0.0.0' => range(2, 14),
                    'foo, list,  1.2.3.4' => array(5, 6, 7, 11, 12, 14),
                    'foo, read,  0.0.0.0' => array(),
                    'foo, read,  1.2.3.4' => array(11, 12),
                    'foo, write, 0.0.0.0' => array(),
                    'foo, write, 1.2.3.4' => array(),
                )
            ),
            'paths-with-spaces' => array(
                array(
                    'list user foo *        -//...',
                    'open user foo 1.2.3.4  "//depot/foo bar/..."',
                ),
                array(
                    1 => 'depot/',
                    2 => '//depot',
                    3 => '//depot/',
                    4 => '//depot/foo/bar',
                    5 => '//depot/bar/test',
                    6 => '//depot/foo-bar/test',
                    7 => '//depot/foo bar/test'
                ),
                array(
                    'foo, list,  0.0.0.0' => array(),
                    'foo, list,  1.2.3.4' => array(3, 7),
                    'foo, read,  0.0.0.0' => array(),
                    'foo, read,  1.2.3.4' => array(7),
                    'foo, open,  0.0.0.0' => array(),
                    'foo, open,  1.2.3.4' => array(7),
                    'foo, write, 0.0.0.0' => array(),
                    'foo, write, 1.2.3.4' => array(),
                )
            ),
            'complex' => array(
                array(
                    'read   user foo *        //foo/...',
                    'read   user bar *        //bar/...',
                    'read   user all *        //...',
                    'write  user foo 1.2.3.4  //foo/...',
                    'write  user bar 1.2.3.4  //bar/...',
                    'write  user *   1.1.1.1  //...',
                    '=open  user foo 1.2.3.4 -//foo/...',
                    '=write user all *       -//foo/...',
                    '=write user all *       -//bar/...',
                    'list   user *   4.4.4.4 -//secret-3333/...',
                    'open   user foo 4.4.4.4  //secret-3333/...',
                ),
                array(
                    1 => '//depot/test',
                    2 => '//bar/test',
                    3 => '//foo/test',
                    4 => '//secret/test',
                    5 => '//secret-3333/test',
                ),
                array(
                    'foo, read,  0.0.0.0' => array(3),
                    'foo, open,  0.0.0.0' => array(),
                    'foo, write, 0.0.0.0' => array(),
                    'foo, admin, 0.0.0.0' => array(),
                    'foo, read,  1.1.1.1' => array(1,2,3,4,5),
                    'foo, open,  1.1.1.1' => array(1,2,3,4,5),
                    'foo, write, 1.1.1.1' => array(1,2,3,4,5),
                    'foo, admin, 1.1.1.1' => array(),
                    'foo, read,  1.2.3.4' => array(3),
                    'foo, open,  1.2.3.4' => array(),
                    'foo, write, 1.2.3.4' => array(3),
                    'foo, admin, 1.2.3.4' => array(),
                    'foo, read,  4.4.4.4' => array(3, 5),
                    'foo, open,  4.4.4.4' => array(5),
                    'foo, write, 4.4.4.4' => array(),
                    'foo, admin, 4.4.4.4' => array(),
                )
            ),
            // following tests will be run on case insensitive server only
            'case-insensitive' => array(
                array(
                    'read user foo * //depot/FOO/...',
                    'read user bar * //Bar/...',
                ),
                array(
                    1 => '//depot/test',
                    2 => '//bar/test',
                    3 => '//depot/foo/test',
                    4 => '//depot/Foo/test',
                    5 => '//depot/Foo',
                ),
                array(
                    'foo, read,  0.0.0.0' => array(3, 4),
                    'foo, write, 0.0.0.0' => array(),
                    'bar, read,  0.0.0.0' => array(2),
                    'bar, write, 0.0.0.0' => array(),
                ),
                true
            ),
        );
    }
}
