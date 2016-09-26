<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

return array(
    'short_links' => array(
        'hostname' => null, // a dedicated host for short links - defaults to standard host
    ),
    'router' => array(
        'routes' => array(
            'short-link' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/l[/:link][/]',
                    'defaults' => array(
                        'controller' => 'ShortLinks\Controller\Index',
                        'action'     => 'index',
                        'link'       => null
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'ShortLinks\Controller\Index' => 'ShortLinks\Controller\IndexController'
        ),
    ),
);
