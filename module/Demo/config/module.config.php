<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

return array(
    'router' => array(
        'routes' => array(
            'demo-generate' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/demo/generate[/]',
                    'defaults' => array(
                        'controller' => 'Demo\Controller\Index',
                        'action'     => 'generate',
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Demo\Controller\Index' => 'Demo\Controller\IndexController'
        ),
    ),
);
