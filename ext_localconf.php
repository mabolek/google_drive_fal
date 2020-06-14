<?php

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers'][\Mabolek\GoogleDriveFal\Driver\GoogleDriveDriver::DRIVER_TYPE] = [
    'class' => \Mabolek\GoogleDriveFal\Driver\GoogleDriveDriver::class,
    'flexFormDS' => 'FILE:EXT:google_drive_fal/Configuration/FlexForm/GoogleDriveStorageConfigurationFlexForm.xml',
    'label' => 'Google Drive™',
    'shortName' => \Mabolek\GoogleDriveFal\Driver\GoogleDriveDriver::EXTENSION_NAME,
];
