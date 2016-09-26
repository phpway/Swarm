<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

return array(
    'router' => array(
        'routes' => array(
            'comments' => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/comments?(/(?P<topic>.*))?',
                    'spec'     => '/comments/%topic%',
                    'defaults' => array(
                        'controller' => 'Comments\Controller\Index',
                        'action'     => 'index',
                        'topic'      => null
                    ),
                ),
            ),
            'add-comment' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/comment[s]/add[/]',
                    'defaults' => array(
                        'controller' => 'Comments\Controller\Index',
                        'action'     => 'add'
                    ),
                ),
            ),
            'edit-comment' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/comment[s]/edit/:comment[/]',
                    'defaults' => array(
                        'controller' => 'Comments\Controller\Index',
                        'action'     => 'edit'
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Comments\Controller\Index' => 'Comments\Controller\IndexController'
        ),
    ),
    'view_manager' => array(
        'template_path_stack'   => array(
            __DIR__ . '/../view',
        ),
    ),
    'view_helpers' => array(
        'invokables' => array(
            'comments'  => 'Comments\View\Helper\Comments'
        ),
    ),
);
