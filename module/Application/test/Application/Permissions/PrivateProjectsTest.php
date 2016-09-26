<?php
/**
 * Perforce Swarm
 *
 * @copyright   2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Permissions;

use Application\Permissions\PrivateProjects;
use ModuleTest\TestControllerCase;
use P4\Model\Fielded\Iterator as ModelIterator;
use Projects\Model\Project;
use Record\Key\GenericKey;
use Users\Model\User;

class PrivateProjectsTest extends TestControllerCase
{
    public function testService()
    {
        $filter = $this->getApplication()->getServiceManager()->get('projects_filter');
        $this->assertTrue($filter instanceof PrivateProjects);
    }

    public function testFilter()
    {
        $p1 = new Project($this->p4);
        $p1->set(
            array(
                'id'      => 'p1',
                'members' => array('foo'),
                'owners'  => array()
            )
        )->save();
        $p2 = new Project($this->p4);
        $p2->set(
            array(
                'id'      => 'p2',
                'members' => array('bar'),
                'owners'  => array(),
                'private' => true
            )
        )->save();
        $p3 = new Project($this->p4);
        $p3->set(
            array(
                'id'      => 'p3',
                'members' => array('bar', 'mod'),
                'owners'  => array('foo'),
                'private' => true
            )
        )->save();
        $p4 = new Project($this->p4);
        $p4->set(
            array(
                'id'      => 'p4',
                'members' => array('xyz'),
                'owners'  => array(),
                'private' => true,
                'branches' => array(
                    array(
                        'id'         => 'b1',
                        'name'       => 'branch 1',
                        'paths'      => array('//depot/...'),
                        'moderators' => array('mod')
                    )
                )
            )
        )->save();

        $p4Foo = $this->connectWithAccess('foo', array('//depot/...'));
        $p4Bar = $this->connectWithAccess('bar', array('//depot/...'));
        $p4Joe = $this->connectWithAccess('joe', array('//depot/...'));
        $p4Mod = $this->connectWithAccess('mod', array('//depot/...'));

        // prepare function to convert items into an array with item ids as keys
        // and project ids as values
        $normalizeItems = function ($items) {
            $normalized = array();
            foreach ($items as $item) {
                $projects = $item->get('projects');
                if (is_array($projects)) {
                    ksort($projects);
                }
                $normalized[$item->getId()] = $projects ? array_keys($projects) : $projects;
            }

            ksort($normalized);
            return $normalized;
        };

        // user joe should see only public projects (p1)
        $item1 = new GenericKey;
        $item1->setId('item1')->set('projects', array('p1', 'p2', 'p3'));
        $item2 = new GenericKey;
        $item2->setId('item2')->set('projects', array('p1', 'p4'));
        $item3 = new GenericKey;
        $item3->setId('item3')->set('projects', array('p3'));
        $item4 = new GenericKey;
        $item4->setId('item4');

        $items    = new ModelIterator(array($item1, $item2, $item3, $item4));
        $filter   = new PrivateProjects($this->p4, $p4Joe);
        $filtered = $filter->filter($items, 'projects');
        $this->assertSame(
            array(
                'item1' => array('p1'),
                'item2' => array('p1'),
                'item4' => null,
            ),
            $normalizeItems($filtered)
        );

        $projects = new ModelIterator(array($p1, $p2, $p3, $p4));
        $filter   = new PrivateProjects($this->p4, $p4Joe);
        $filtered = $filter->filter($projects)->invoke('getId');
        $this->assertSame(array('p1'), $filtered);

        // try as foo, it should see p1 & p3
        $item1 = new GenericKey;
        $item1->setId('item1')->set('projects', array('p1', 'p2', 'p3', 'p4'));
        $item2 = new GenericKey;
        $item2->setId('item2')->set('projects', array('p2'));
        $item3 = new GenericKey;
        $item3->setId('item3')->set('projects', array('p3', 'p4'));
        $item4 = new GenericKey;
        $item4->setId('item4');

        $items    = new ModelIterator(array($item1, $item2, $item3, $item4));
        $filter   = new PrivateProjects($this->p4, $p4Foo);
        $filtered = $filter->filter($items, 'projects');
        $this->assertSame(
            array(
                'item1' => array('p1', 'p3'),
                'item3' => array('p3'),
                'item4' => null,
            ),
            $normalizeItems($filtered)
        );

        $projects = new ModelIterator(array($p1, $p2, $p3, $p4));
        $filter   = new PrivateProjects($this->p4, $p4Foo);
        $filtered = $filter->filter($projects)->invoke('getId');
        sort($filtered);
        $this->assertSame(array('p1', 'p3'), $filtered);

        // try as bar, it should see p1, p2 & p3
        $item1 = new GenericKey;
        $item1->setId('item1')->set('projects', array('p1', 'p2', 'p3', 'p4'));
        $item2 = new GenericKey;
        $item2->setId('item2')->set('projects', array('p2'));
        $item3 = new GenericKey;
        $item3->setId('item3')->set('projects', array('p3', 'p4'));
        $item4 = new GenericKey;
        $item4->setId('item4');

        $items    = new ModelIterator(array($item1, $item2, $item3, $item4));
        $filter   = new PrivateProjects($this->p4, $p4Bar);
        $filtered = $filter->filter($items, 'projects');
        $this->assertSame(
            array(
                'item1' => array('p1', 'p2', 'p3'),
                'item2' => array('p2'),
                'item3' => array('p3'),
                'item4' => null,
            ),
            $normalizeItems($filtered)
        );

        $projects = new ModelIterator(array($p1, $p2, $p3, $p4));
        $filter   = new PrivateProjects($this->p4, $p4Bar);
        $filtered = $filter->filter($projects)->invoke('getId');
        sort($filtered);
        $this->assertSame(array('p1', 'p2', 'p3'), $filtered);

        // try as mod, it should see p1, p3 & p4
        $item1 = new GenericKey;
        $item1->setId('item1')->set('projects', array('p1', 'p2', 'p3', 'p4'));
        $item2 = new GenericKey;
        $item2->setId('item2')->set('projects', array('p2', 'p4'));
        $item3 = new GenericKey;
        $item3->setId('item3')->set('projects', array('p3'));
        $item4 = new GenericKey;
        $item4->setId('item4');

        $items    = new ModelIterator(array($item1, $item2, $item3, $item4));
        $filter   = new PrivateProjects($this->p4, $p4Mod);
        $filtered = $filter->filter($items, 'projects');
        $this->assertSame(
            array(
                'item1' => array('p1', 'p3', 'p4'),
                'item2' => array('p4'),
                'item3' => array('p3'),
                'item4' => null,
            ),
            $normalizeItems($filtered)
        );

        $projects = new ModelIterator(array($p1, $p2, $p3, $p4));
        $filter   = new PrivateProjects($this->p4, $p4Mod);
        $filtered = $filter->filter($projects)->invoke('getId');
        sort($filtered);
        $this->assertSame(array('p1', 'p3', 'p4'), $filtered);

        // try as admin, it should see all projects
        $item1 = new GenericKey;
        $item1->setId('item1')->set('projects', array('p1', 'p2', 'p3', 'p4'));
        $item2 = new GenericKey;
        $item2->setId('item2')->set('projects', array('p2', 'p4'));
        $item3 = new GenericKey;
        $item3->setId('item3')->set('projects', array('p3'));
        $item4 = new GenericKey;
        $item4->setId('item4');

        $items    = new ModelIterator(array($item1, $item2, $item3, $item4));
        $filter   = new PrivateProjects($this->p4, $this->p4);
        $filtered = $filter->filter($items, 'projects');
        $this->assertSame(
            array(
                'item1' => array('p1', 'p2', 'p3', 'p4'),
                'item2' => array('p2', 'p4'),
                'item3' => array('p3'),
                'item4' => null,
            ),
            $normalizeItems($filtered)
        );

        $projects = new ModelIterator(array($p1, $p2, $p3, $p4));
        $filter   = new PrivateProjects($this->p4, $this->p4);
        $filtered = $filter->filter($projects)->invoke('getId');
        sort($filtered);
        $this->assertSame(array('p1', 'p2', 'p3', 'p4'), $filtered);
    }

    public function testFilterList()
    {
        $p1 = new Project($this->p4);
        $p1->set(
            array(
                'id'      => 'p1',
                'members' => array('foo'),
                'owners'  => array()
            )
        )->save();
        $p2 = new Project($this->p4);
        $p2->set(
            array(
                'id'      => 'p2',
                'members' => array('bar'),
                'owners'  => array(),
                'private' => true
            )
        )->save();
        $p3 = new Project($this->p4);
        $p3->set(
            array(
                'id'      => 'p3',
                'members' => array('bar'),
                'owners'  => array('foo'),
                'private' => true
            )
        )->save();

        $p4Foo = $this->connectWithAccess('foo', array('//depot/...'));
        $p4Bar = $this->connectWithAccess('bar', array('//depot/...'));
        $p4Joe = $this->connectWithAccess('joe', array('//depot/...'));

        // test foo, should see p1 & p3
        $filter   = new PrivateProjects($this->p4, $p4Foo);
        $filtered = array_keys($filter->filterList(array('p2', 'p3', 'p1')));
        sort($filtered);
        $this->assertSame(array('p1', 'p3'), $filtered);

        // test bar, should see p1, p2 & p3
        $filter   = new PrivateProjects($this->p4, $p4Bar);
        $filtered = array_keys($filter->filterList(array('p2', 'p3', 'p1')));
        sort($filtered);
        $this->assertSame(array('p1', 'p2', 'p3'), $filtered);

        // test joe, should see only p1
        $filter   = new PrivateProjects($this->p4, $p4Joe);
        $filtered = array_keys($filter->filterList(array('p2', 'p3', 'p1')));
        sort($filtered);
        $this->assertSame(array('p1'), $filtered);
    }

    public function testCanAccess()
    {
        $p1 = new Project($this->p4);
        $p1->set(
            array(
                'id'      => 'p1',
                'members' => array('foo'),
                'owners'  => array()
            )
        )->save();
        $p2 = new Project($this->p4);
        $p2->set(
            array(
                'id'      => 'p2',
                'members' => array('bar'),
                'owners'  => array(),
                'private' => true
            )
        )->save();
        $p3 = new Project($this->p4);
        $p3->set(
            array(
                'id'      => 'p3',
                'members' => array('bar'),
                'owners'  => array('foo'),
                'private' => true
            )
        )->save();

        $p4Foo = $this->connectWithAccess('foo', array('//depot/...'));
        $p4Bar = $this->connectWithAccess('bar', array('//depot/...'));

        // foo should see p1 & p3
        $filter = new PrivateProjects($this->p4, $p4Foo);
        $this->assertTrue($filter->canAccess($p1));
        $this->assertFalse($filter->canAccess($p2));
        $this->assertTrue($filter->canAccess($p3));

        // bar should see p1, p2 & p3
        $filter = new PrivateProjects($this->p4, $p4Bar);
        $this->assertTrue($filter->canAccess($p1));
        $this->assertTrue($filter->canAccess($p2));
        $this->assertTrue($filter->canAccess($p3));
    }

    public function testFilterUsers()
    {
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'      => 'p1',
                'members' => array('foo'),
                'owners'  => array('bar'),
                'branches' => array(
                    array(
                        'id'         => 'b1',
                        'name'       => 'branch 1',
                        'paths'      => array('//depot/...'),
                        'moderators' => array('mod')
                    )
                )
            )
        )->save();

        $foo = new User($this->p4);
        $foo->setId('foo')->setFullName('Mr Foo')->setEmail('foo@test')->save();
        $joe = new User($this->p4);
        $joe->setId('joe')->setFullName('Mr Joe')->setEmail('joe@test')->save();

        $filter = new PrivateProjects($this->p4, $this->p4);
        $users  = array('a', 'b', $foo, 'c', 'bar', 'baz', 'd', 'e', 'mod', 'x', $joe);

        // test with public project, everyone should have access
        $filtered = $filter->filterUsers($users, $project);
        $this->assertSame($users, $filtered);

        // test with private project, only members/moderators/owners should have access
        $project->set('private', true)->save();
        $filtered = $filter->filterUsers($users, $project);
        $this->assertSame(array($foo, 'bar', 'mod'), $filtered);

        // test with passing only users not having access, should result in empty list
        $this->assertSame(array(), $filter->filterUsers(array('x', 'y', $joe, 'abc', 'mods'), $project));
    }
}
