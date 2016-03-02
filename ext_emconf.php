<?php

/*********************************************************************
 * Extension Manager/Repository config file for ext "dm_developerlog".
 *********************************************************************/

$EM_CONF[$_EXTKEY] = array (
    'title' => 'Development log',
    'description' => 'An extension to capture TYPO3 devlog messages for debugging. Provides a backend module.',
    'category' => 'misc',
    'version' => '0.5.3',
    'state' => 'beta',
    'uploadfolder' => false,
    'author' => 'Patrick Schriner',
    'author_email' => 'patrick.schriner@diemedialen.de',
    'author_company' => 'DieMedialen GmbH',
    'constraints' => array (
        'depends' => array(
            'php' => '5.5.0-0.0.0',
            'typo3' => '6.2.7-7.6.99',
        ),
        'conflicts' => array(),
        'suggests' => array(),
    ),
    'createDirs' => NULL,
    'clearcacheonload' => true,
    'autoload' => array(
        'psr-4' => array('DieMedialen\\DmDeveloperlog\\' => 'Classes')
    ),
);
