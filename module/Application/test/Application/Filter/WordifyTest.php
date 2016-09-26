<?php
/**
 * Tests for the Wordify filter
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Filter;

use \Application\Filter\Wordify;

class WordifyTest extends \PHPUnit_Framework_TestCase
{
    protected $filter = null;

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

        $this->filter = new Wordify;
    }

    public function wordProvider()
    {
        return array(
            // passthrough and spaces already present
            array('Foo Bar', 'Foo Bar'),
            array('Foobar', 'Foobar'),
            array('foobar', 'Foobar'),
            array('foo bar', 'Foo Bar'),
            array('foo bar ', 'Foo Bar'),
            array('foo  bar', 'Foo Bar'),
            array(' foo bar ', 'Foo Bar'),
            array('foo bar      ', 'Foo Bar'),
            array('FOOBAR', 'FOOBAR'),
            // camel-case only
            array('FooBar', 'Foo Bar'),
            array('ReviewSubmittedBy', 'Review Submitted By'),
            array('FOOBar', 'FOO Bar'),
            array('ServerID', 'Server ID'),
            // dashes and underscores only
            array('foo-bar', 'Foo Bar'),
            array('foo_bar', 'Foo Bar'),
            array('foo-bar-baz', 'Foo Bar Baz'),
            array('foo-bar_baz', 'Foo Bar Baz'),
            array('foo-bar-', 'Foo Bar'),
            array('__--foo--bar-_-_', 'Foo Bar'),
            // spaces, camel, dashes and underscores
            array(' fooBar-', 'Foo Bar'),
            array('FOOBar-baz', 'FOO Bar Baz'),
            array(' -Foo--Bar', 'Foo Bar'),
            array('Foo_bar BAz-', 'Foo Bar B Az'),
            // test cases from perforce's jobspec
            array('Job', 'Job'),
            array('Status', 'Status'),
            array('Type', 'Type'),
            array('Severity', 'Severity'),
            array('Release', 'Release'),
            array('OwnedBy', 'Owned By'),
            array('ReportedBy', 'Reported By'),
            array('ModifiedBy', 'Modified By'),
            array('ReportedDate', 'Reported Date'),
            array('ModifiedDate', 'Modified Date'),
            array('CommitRelease', 'Commit Release'),
            array('3rdPartyJob', '3rd Party Job'),
            array('FixVerifiedBy', 'Fix Verified By'),
            array('CallNumbers', 'Call Numbers'),
            array('P4Blog', 'P4 Blog'),
            array('UIDetails', 'UI Details'),
            array('JIRAOriginalEstimate', 'JIRA Original Estimate'),
            array('JIRAWorkLog', 'JIRA Work Log'),
            array('JIRATimeEstimate', 'JIRA Time Estimate'),
            array('JIRAIssueKey', 'JIRA Issue Key'),
            array('DTG_FIXES', 'DTG FIXES'),
            array('DTG_DTISSUE', 'DTG DTISSUE'),
            array('DTG_ERROR', 'DTG ERROR'),
            array('JIRASummary', 'JIRA Summary'),
            array('JIRAAssignee', 'JIRA Assignee'),
            // test cases from p4 -ztag info
            array('brokerAddress', 'Broker Address'),
            array('brokerVersion', 'Broker Version'),
            array('userName', 'User Name'),
            array('password', 'Password'),
            array('serverUptime', 'Server Uptime'),
            array('serverCertExpires', 'Server Cert Expires'),
            array('ServerID', 'Server ID'),
            array('serverLicense-ip', 'Server License Ip')
        );
    }

    /**
     * @dataProvider wordProvider
     */
    public function testWordify($text, $words)
    {
        $this->assertEquals($this->filter->filter($text), $words);
    }
}
