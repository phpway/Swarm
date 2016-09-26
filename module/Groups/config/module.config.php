<?php
/**
 * Perforce Swarm
 *
 * @copyright   2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

return array(
    'groups' => array(
        'edit_name_admin_only' => false,    // if enabled only admin users can edit group name
    ),
    'router' => array(
        'routes' => array(
            'group' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/group[s]/:group[/]',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'group'
                    ),
                ),
            ),
            'add-group' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/group[s]/add[/]',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'add',
                    ),
                ),
            ),
            'edit-group' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/group[s]/edit/:group[/]',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'edit'
                    ),
                ),
            ),
            'delete-group' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/group[s]/delete/:group[/]',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'delete'
                    ),
                ),
            ),
            'group-reviews' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/group[s]/:group/reviews[/]',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'reviews'
                    ),
                ),
            ),
            'groups' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/groups[/]',
                    'defaults' => array(
                        'controller' => 'Groups\Controller\Index',
                        'action'     => 'groups'
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Groups\Controller\Index' => 'Groups\Controller\IndexController'
        ),
    ),
    'view_manager' => array(
        'template_path_stack'   => array(
            __DIR__ . '/../view',
        ),
    ),
    'view_helpers' => array(
        'invokables' => array(
            'groupToolbar' => 'Groups\View\Helper\GroupToolbar',
            'groupSidebar' => 'Groups\View\Helper\GroupSidebar',
        ),
    ),
    'input_filters' => array(
        'factories'  => array(
            'group_filter'  => function ($manager) {
                $services = $manager->getServiceLocator();
                return new \Groups\Filter\group($services->get('p4_admin'));
            },
        ),
    ),
);
