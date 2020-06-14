<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Google Drive for FAL',
    'description' => 'Use Google Drive as File Storage in your TYPO3 installation.',
    'category' => 'backend',
    'author' => 'Mathias Bolt Lesniak',
    'author_email' => 'mathias@pixelant.net',
    'state' => 'beta',
    'clearCacheOnLoad' => true,
    'version' => '0.1.0',
    'constraints' =>
        [
            'depends' => [
                'typo3' => '9.5.9-10.4.99',
            ],
            'conflicts' => [],
            'suggests' => [],
        ]
];
