<?php
/**
 * Tests for the linkify filter
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Filter;

use \Application\Filter\Linkify;

class LinkifyTest extends \PHPUnit_Framework_TestCase
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

    public function hasUrlProvider()
    {
        return array(
            'simple-match'          => array(
                '@job061517',
                '/@job061517',
                array('job061517')
            ),
            'multi-match'           => array(
                '@job061517?  @559636. (@559643) @swarm',
                array('/@job061517', '/@559636', '/@559643', '/@swarm'),
                array('job061517', 'swarm')
            ),
            'slashy-match'          => array(
                'this @user\domain and @another\thing\here but not @\user or @user\\',
                array('/@user%5Cdomain', '/@another%5Cthing%5Chere')
            ),
            'starred'               => array(
                'this @*needsit here',
                array('/@needsit')
            ),
            'leading-slashes-path'  => array(
                '@//depot/main/swarm',
                '/@//depot/main/swarm'
            ),
            'leading-slash-path'    => array(
                '@/depot/main/swarm',
                '/@/depot/main/swarm'
            ),
            'no-leading-slash-path' => array(
                '@depot/main/swarm',
                '/@depot/main/swarm'
            ),
            'extension-hash-path'   => array(
                'E.g. @depot/main/foo.txt#22 to go to line 22',
                '/@depot/main/foo.txt#22'
            ),
            'percent-encoded-path'  => array(
                '@depot/main/swarm%40at',
                '/@depot/main/swarm%2540at'
            ),
            'trailing-bang'         => array(
                'Excited about @job061517! and @job061518!!',
                array('/@job061517', '/@job061518'),
                array('job061517', 'job061518')
            ),
            'trailing-quotes'       => array(
                '"this references @559636".',
                array('/@559636')
            ),
            'raw-change'            => array(
                'talking about change 12 and Change 12345.',
                array('/@12', '/@12345')
            ),
            'raw-changelist'            => array(
                'talking about changelist 12 and Changelist 12345.',
                array('/@12', '/@12345')
            ),
            'raw-change-mixed-validity' => array(
                "this is change 1 and change \n3 and change 4",
                array('/@1', '/@4')
            ),
            'raw-changelist-mixed-validity' => array(
                "this is changelist 1 and changelist \n3 and changelist 4",
                array('/@1', '/@4')
            ),
            'raw-change-changelist-invalid-permutations' => array(
                "change 1 and changelist 3 but not changelist4, changelists 5 nor somechangelist 6",
                array('/@1', '/@3')
            ),
            'raw-review'            => array(
                'talking about review 12 and Review 12345.',
                array('/@12', '/@12345')
            ),
            'raw-review-mixed-validity' => array(
                "this is review 1 and review \n3 and review 4",
                array('/@1', '/@4')
            ),
            'raw-job'               => array(
                'text with job123456 in it and invalid job12345 job1234567',
                array('/@job123456')
            ),
            'wordy-jobs'            => array(
                'text with job 012345 in it and job 1 and job 12',
                array('/jobs/012345', '/jobs/1', '/jobs/12')
            ),
            'link-with-get-params'  => array(
                'http://swarm.perforce.com/files/depot/main/swarm/public/css/style.css#10?v=27',
                'http://swarm.perforce.com/files/depot/main/swarm/public/css/style.css#10?v=27'
            ),
            'link-with-port'        => array(
                'http://computer.perforce.com:8080/@md=d&dw=b&jf=y@/556088?ac=10',
                'http://computer.perforce.com:8080/@md=d&dw=b&jf=y@/556088?ac=10'
            ),
            'link-inside-brackets'  => array(
                '(e.g.: http://swarm.perforce.com/users/bpendalton213),',
                'http://swarm.perforce.com/users/bpendalton213'
            ),
            'link-with-percents'    => array(
                'https://perforce.foo.com/update?code=20120612T18%3A19',
                'https://perforce.foo.com/update?code=20120612T18:19'
            ),
            'link-with-@values'     => array(
                'http://swarm.perforce.com/@job061517  http://swarm.perforce.com/@depot/main/swarm',
                array('http://swarm.perforce.com/@job061517', 'http://swarm.perforce.com/@depot/main/swarm')
            ),
            'link-with-user'        => array(
                'ftp://admin@server.host.perforce.co.uk',
                'ftp://admin@server.host.perforce.co.uk'
            ),
            'link-trailing-quote'   => array(
                '"a link! http://swarm.perforce.com"',
                array('http://swarm.perforce.com')
            ),
            'link-with-comma'       => array(
                'a link! http://swarm.perforce.com/reviews/12345/v1,3',
                array('http://swarm.perforce.com/reviews/12345/v1,3')
            ),
            'link-with-ip'          => array(
                'a link http://10.2.0.100/review/1234 is here',
                array('http://10.2.0.100/review/1234')
            ),
            'link-without-tld'          => array(
                'a link http://some-host/users/bob is here',
                array('http://some-host/users/bob')
            ),
            'multi-line-urls'       => array(
                "http://swarm.perforce.com/..//\n" .
                "http://google.com\n" .
                "http://google.ca.\n" .
                "http://google.co.uk!!\n",
                array('http://swarm.perforce.com/..//', 'http://google.com', 'http://google.ca', 'http://google.co.uk')
            ),
            'emails'                => array(
                'gnicol@perforce.com and another <gnicol+extra@perforce.com>. and more gnicol@perforce.co.uk. yay',
                array('mailto:gnicol@perforce.com', 'mailto:gnicol+extra@perforce.com', 'mailto:gnicol@perforce.co.uk')
            ),
            'email-in-brackets'     => array(
                '(email this guy gnicol@perforce.com)',
                'mailto:gnicol@perforce.com'
            )
        );
    }

    public function notUrlProvider()
    {
        return array(
            'two-ats'              => array('@depot@1234'),
            'raw-depot-path'       => array('//depot/foo.dir/file.txt'),
            'at-then-email'        => array('@slord@perforce.com'),
            'at-triangle-brackets' => array('@<test>'),
            'at-equals-Word'       => array('@=Change'),
            'domain-dot-com'       => array('google.com'),
            'domain-slash-atval'   => array('swarm.perforce.com/@1234'),
            'bad-length-jobs'      => array('too short job12345 too long job1234567'),
            'long-word'            => array('change ' . str_repeat('a', 257) . ' 123')
        );
    }

    public function testBasicFunction()
    {
        $filter = new Linkify('');
        $this->assertSame(
            'test',
            $filter->filter('test')
        );
    }

    public function testNoLinksEscaped()
    {
        $filter = new Linkify('');
        $this->assertSame(
            'I am &lt;escape&gt; &lt;worthy&gt; text!',
            $filter->filter('I am <escape> <worthy> text!')
        );
    }

    /**
     * @dataProvider hasUrlProvider
     */
    public function testWorkingUrls($text, $urls)
    {
        $urls   = (array) $urls;
        $filter = new Linkify('');
        $output = $filter->filter($text);

        foreach ($urls as $url) {
            $this->assertTrue(
                strpos($output, '<a href="' . $url . '">') !== false,
                'expected to find the url ' . '<a href="' . $url . '">' . ' in ' . $output
            );
        }

        $this->assertSame(
            count($urls),
            preg_match_all('/<a href="/', $output, $matches),
            'expected matching number of urls in output'
        );
    }

    /**
     * @dataProvider notUrlProvider
     */
    public function testNoUrls($text)
    {
        $filter = new Linkify('');
        $output = $filter->filter($text);

        $this->assertTrue(
            preg_match_all('/<a href=/', $output, $matches) === 0,
            'expected no urls would be present'
        );
    }

    public function hasCalloutProvider()
    {
        return array(
            'simple-match'          => array(
                '@user1234',
                array('user1234')
            ),
            'simple-slash'          => array(
                '@domain\user',
                array('domain\user')
            ),
            'multi-match'           => array(
                '@user-joe  @559636. (@559643) @another\user @gnicol',
                array('user-joe', 'another\user', 'gnicol')
            ),
            'trailing-bang'         => array(
                'Excited about @user_bob! @-fail and @robert!!',
                array('user_bob', 'robert')
            )
        );
    }

    public function notCalloutProvider()
    {
        return array(
            'numeric'               => array('test  @559636. (@559643) adadad @12345'),
            'leading-slashes-path'  => array('@//depot/main/swarm'),
            'leading-slash-path'    => array('@/depot/main/swarm'),
            'no-leading-slash-path' => array('@depot/main/swarm'),
            'extension-hash-path'   => array('E.g. @depot/main/foo.txt#22 to go to line 22'),
            'percent-encoded-path'  => array('@depot/main/swarm%40at'),
            'link-with-@values'     => array('http://swarm.perforce.com/@job061517  http://sw.com/@depot/'),
            'link-with-user'        => array('ftp://admin@server.host.perforce.co.uk'),
            'emails'                => array('gnicol@perforce.com <gnicol+extra@perforce.com>'),
            'email-in-brackets'     => array('(email this guy gnicol@perforce.com)'),
            'two-ats'               => array('@depot@1234'),
            'at-then-email'         => array('@slord@perforce.com'),
            'at-triangle-brackets'  => array('@<test>'),
            'at-equals-Word'        => array('@=Change')
        );
    }

    /**
     * @dataProvider hasCalloutProvider
     */
    public function testGoodCallouts($text, $callouts)
    {
        $output   = Linkify::getCallouts($text);
        $callouts = $callouts ?: array();

        $this->assertSame(
            $callouts,
            $output,
            'expected matching callouts'
        );
    }

    /**
     * @dataProvider notCalloutProvider
     */
    public function testBadCallouts($text)
    {
        $output = Linkify::getCallouts($text);

        $this->assertSame(
            array(),
            $output,
            'expected no callouts would be present'
        );
    }
}
