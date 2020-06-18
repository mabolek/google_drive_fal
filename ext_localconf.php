<?php

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['google_drive_fal'] =
    \Mabolek\GoogleDriveFal\Hooks\FileStorageDataHandlerHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers'][\Mabolek\GoogleDriveFal\Driver\GoogleDriveDriver::DRIVER_TYPE] = [
    'class' => \Mabolek\GoogleDriveFal\Driver\GoogleDriveDriver::class,
    'flexFormDS' => 'FILE:EXT:google_drive_fal/Configuration/FlexForm/GoogleDriveStorageConfigurationFlexForm.xml',
    'label' => 'Google Drive™',
    'shortName' => \Mabolek\GoogleDriveFal\Driver\GoogleDriveDriver::EXTENSION_NAME,
];
