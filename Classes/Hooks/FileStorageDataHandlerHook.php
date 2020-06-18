<?php
declare(strict_types = 1);

namespace Mabolek\GoogleDriveFal\Hooks;

use Mabolek\GoogleDriveFal\Driver\GoogleDriveDriver;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class FileStorageDataHandlerHook
{
    /**
     * Fill path_segment/slug field with title
     *
     * @param string $status
     * @param string $table
     * @param string|int $id
     * @param array $fieldArray
     * @param DataHandler $parentObject
     */
    public function processDatamap_preProcessFieldArray(array &$fieldArray, $table, $id, DataHandler $parentObject)
    {
        if ($table === 'sys_file_storage' && $fieldArray['driver'] === GoogleDriveDriver::DRIVER_TYPE) {
            if ($fieldArray['driver'] !== '' && strpos($fieldArray['processingfolder'], ':') !== false) {
                list($fileStorageUid, $path) = explode(':', $fieldArray['processingfolder']);

                if (is_numeric($fileStorageUid)) {
                    $fileStorageUid = (int)$fileStorageUid;

                    // Handle the special zero storage (e.g. typo3temp/ and such)
                    if ($fileStorageUid === 0) {
                        $canWriteDirectory = true;

                        try {
                            GeneralUtility::mkdir_deep(GeneralUtility::getFileAbsFileName($path));
                        } catch (\RuntimeException $exception) {
                            $canWriteDirectory = false;
                        }

                        if ($canWriteDirectory) {
                            return;
                        }
                    }

                    $fileStorage = BackendUtility::getRecord(
                        'sys_file_storage',
                        $fileStorageUid
                    );

                    if (
                        is_array($fileStorage)
                        && $fileStorage['driver'] !== GoogleDriveDriver::DRIVER_TYPE
                        && $fileStorage['is_writable']
                        && $fileStorage['is_online']
                    ) {
                        return;
                    }

                    $storage = GeneralUtility::makeInstance(ResourceFactory::class)->getStorageObject($fileStorageUid);

                    if (
                        $storage
                        && ($storage->getFolder($path) || $storage->createFolder($path))
                        && $storage->isWritable($path)
                    ) {
                        return;
                    }
                }
            }

            $fieldArray['processingfolder'] = '0:typo3temp/assets/_processed_storage_' . $id;

            GeneralUtility::makeInstance(FlashMessageService::class)->getMessageQueueByIdentifier()->addMessage(
                GeneralUtility::makeInstance(FlashMessage::class,
                    'TYPO3\'s folder for processed files can\'t be on a Google Drive™. It\'s best to have it on public '
                        . ' writeable storage. It has been set to: ' . $fieldArray['processingfolder'],
                    'Processing folder changed',
                    FlashMessage::WARNING
                )
            );
        }
    }
}