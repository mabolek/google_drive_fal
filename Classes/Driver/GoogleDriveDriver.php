<?php

namespace Mabolek\GoogleDriveFal\Driver;

use Mabolek\GoogleDriveFal\Api\Client;
use Google_Service_Exception;
use GuzzleHttp\Psr7\StreamWrapper;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\InvalidPathException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class GoogleDriveDriver extends AbstractHierarchicalFilesystemDriver
{
    const DRIVER_TYPE = 'MabolekGoogleDriveFal';

    const EXTENSION_KEY = 'google_drive_fal';

    const EXTENSION_NAME = 'Google Drive for FAL';

    const IDENTIFIER_PATTERN = '/[a-zA-Z0-9-_]+/';

    const TYPO3_TO_GOOGLE_FIELDS = [
        'size' => 'quotaBytesUsed',
        'tstamp' => 'modifiedTime',
    ];

    const GOOGLE_MIME_TYPE_TO_EXTENSIONS_AND_EXPORT_FORMATS = [
        'application/vnd.google-apps.document' => [
            'html' => 'text/html',
            'zip' => 'application/zip',
            'txt' => 'text/plain',
            'rtf' => 'application/rtf',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'epub' => 'application/epub+zip',
        ],
        'application/vnd.google-apps.spreadsheet' => [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ods' => 'application/x-vnd.oasis.opendocument.spreadsheet',
        ],
        'application/vnd.google-apps.drawing' => [
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
        ],
        'application/vnd.google-apps.presentation' => [
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
        ],
    ];

    /**
     * @var Client
     */
    protected $googleDriveClient = null;

    /**
     * @var \Google_Service_Drive
     */
    protected $googleDriveService = null;

    /**
     * Stream wrapper protocol: Will be set in the constructor
     *
     * @var string
     */
    protected $streamWrapperProtocol = '';

    /**
     * Object meta data is cached here as array or null
     * $identifier => [meta info as array]
     *
     * @var array<\Google_Service_Drive_DriveFile>
     */
    protected static $metaInfoCache = [];

    /**
     * Array of list queries
     *
     * @var array[]
     */
    protected static $listQueryCache = [];

    /**
     * @var ResourceStorage
     */
    protected $storage = null;

    /**
     * @var array
     */
    protected static $settings = null;

    /**
     * Additional fields to request from Google API
     *
     * @var array
     */
    protected $fileFields = [
        'createdTime',
        'id',
        'mimeType',
        'modifiedTime',
        'name',
        'parents',
        'size',
        'quotaBytesUsed',
        'capabilities/canAddChildren',
        'capabilities/canCopy',
        'capabilities/canDelete',
        'capabilities/canDownload',
        'capabilities/canEdit',
        'capabilities/canListChildren',
        'capabilities/canRemoveChildren',
        'capabilities/canRename',
        'capabilities/canTrash',
    ];

    /**
     * @var array
     */
    protected $temporaryPaths = [];

    public function __construct(array $configuration = [], Client $googleDriveClient = null)
    {
        parent::__construct($configuration);
        // The capabilities default of this driver. See CAPABILITY_* constants for possible values
        $this->capabilities =
            ResourceStorage::CAPABILITY_BROWSABLE
            | ResourceStorage::CAPABILITY_WRITABLE;
        $this->streamWrapperProtocol = 'googleDrive-' . substr(md5(uniqid()), 0, 7);
        $this->googleDriveClient = $googleDriveClient;
    }

    public function __destruct()
    {
        foreach ($this->temporaryPaths as $temporaryPath) {
            @unlink($temporaryPath);
        }
    }

    public function processConfiguration()
    {
    }

    public function initialize()
    {
        $this->initializeClient();
    }

    public function mergeConfigurationCapabilities($capabilities)
    {
        $this->capabilities &= $capabilities;
        return $this->capabilities;
    }

    public function getRootLevelFolder()
    {
        return $this->configuration['rootIdentifier'] ?? 'root';
    }

    public function getDefaultFolder()
    {
        return $this->getRootLevelFolder();
    }

    public function getPublicUrl($identifier)
    {
        return '';
    }

    /**
     * Create a new folder
     *
     * @param string $newFolderName The new folder name
     * @param string $parentFolderIdentifier The identifier of the parent folder
     * @param bool $recursive !!! NOT IMPLEMENTED
     * @return string The new folder ID
     */
    public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = false)
    {
        if ($parentFolderIdentifier === '') {
            $parentFolderIdentifier = $this->getDefaultFolder();
        }

        $folder = new \Google_Service_Drive_DriveFile();
        $folder->setName($newFolderName);
        $folder->setParents([$parentFolderIdentifier]);
        $folder->setMimeType('application/vnd.google-apps.folder');

        $newFolderIdentifier = $this->getGoogleDriveService()->files->create(
            $folder,
            [
                'uploadType' => 'multipart'
            ]
        );

        return $newFolderIdentifier->id;
    }

    /**
     * @param $identifier
     * @return \Google_Service_Drive_DriveFile|null
     * @throws Google_Service_Exception
     */
    protected function getObjectByIdentifier($identifier)
    {
        if ($identifier === null) {
            return null;
        }

        if ($identifier === '') {
            $identifier = $this->getRootLevelFolder();
        }

        if (isset(self::$metaInfoCache[$identifier])) {
            return self::$metaInfoCache[$identifier];
        }

        $identifierExtension = null;
        if ($this->identifierIsExportFormatRepresentation($identifier)) {
            list($identifier, $identifierExtension) = explode('.', $identifier);
        }

        $googleClient = $this->googleDriveClient->getClient();
        $service = new \Google_Service_Drive($googleClient);

        try {
            $record = $service->files->get($identifier, ['fields' => $this->getFileFields()]);
            $record['capabilities'] = (array)$record->getCapabilities();
            $record = (array)$record;
        } catch (\Google_Service_Exception $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        if (!is_array($record)) {
            return null;
        }

        if ($identifierExtension) {
            $records = $this->getExportFormatObjects($record);

            $record = null;

            foreach ($records as $exportFormatRecord) {
                $identfierWithExtension = $identifier . '.' . $identifierExtension;
                if ($exportFormatRecord['id'] === $identfierWithExtension) {
                    $record = $exportFormatRecord;
                }

                self::$metaInfoCache[$identfierWithExtension] = $exportFormatRecord;
            }
        } else {
            self::$metaInfoCache[$identifier] = $record;
        }

        return $record;
    }

    /**
     * Returns an array of virtual file system objects representing available export formats for google docs
     *
     * @param $object
     * @return array
     */
    protected function getExportFormatObjects($object)
    {
        if (!isset(self::GOOGLE_MIME_TYPE_TO_EXTENSIONS_AND_EXPORT_FORMATS[$object['mimeType']])) {
            return [$object];
        }

        $exportFormatObjects = [];

        foreach (self::GOOGLE_MIME_TYPE_TO_EXTENSIONS_AND_EXPORT_FORMATS[$object['mimeType']] as $extension => $exportFormat) {
            $exportFormatObject = $object;

            $exportFormatObject['originalMimeType'] = $object['mimeType'];
            $exportFormatObject['mimeType'] = $exportFormat;
            $exportFormatObject['id'] = $object['id'] . '.' . $extension;
            $exportFormatObject['name'] = $object['name'] . '.' . $extension;

            $exportFormatObjects[] = $exportFormatObject;
        }

        return $exportFormatObjects;
    }

    protected function objectExists($identifier, bool $isFolder = false)
    {
        $record = $this->getObjectByIdentifier($identifier);

        if ($record === null) {
            return false;
        }

        if (
            (!$isFolder && $record['mimeType'] !== 'application/vnd.google-apps.folder')
            || ($isFolder && $record['mimeType'] === 'application/vnd.google-apps.folder')
        ) {
            return true;
        }

        return false;
    }

    public function fileExists($fileIdentifier)
    {
        return $this->objectExists($fileIdentifier);
    }

    public function folderExists($folderIdentifier)
    {
        return $this->objectExists($folderIdentifier, true);
    }

    public function isFolderEmpty($folderIdentifier)
    {
        return $this->countFilesInFolder($folderIdentifier) + $this->countFoldersInFolder($folderIdentifier) === 0;
    }

    /**
     * @inheritDoc
     */
    public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = true)
    {
        $service = $this->getGoogleDriveService();

        $contents = file_get_contents($localFilePath);

        $file = new \Google_Service_Drive_DriveFile();
        $file->setName($newFileName !== ''? $newFileName : PathUtility::basename($localFilePath));
        $file->setParents([$targetFolderIdentifier]);

        $newFileIdentifier = $service->files->create(
            $file,
            [
                'data' => $contents,
                'uploadType' => 'media'
            ]
        );

        if ($newFileIdentifier && $removeOriginal) {
            unlink($localFilePath);
        }

        return $newFileIdentifier->id;
    }

    /**
     * @inheritDoc
     */
    public function createFile($fileName, $parentFolderIdentifier)
    {
        return $this->addFile('/dev/null', $parentFolderIdentifier, $fileName, false);
    }

    /**
     * @inheritDoc
     */
    public function copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $fileName)
    {
        if (!$this->fileExists($fileIdentifier)) {
            throw new FileDoesNotExistException(
                'A file with the ID "' . $fileIdentifier . '" does not exist.',
                1592043851
            );
        }

        if ($this->identifierIsExportFormatRepresentation($fileIdentifier)) {
            list($fileIdentifier, $fileIdentifierExtension) = explode('.', $fileIdentifier, 2);

            $newNameParts = explode('.', $fileName);
            $newNameExtension = array_pop($newNameParts);

            // Remove extension from file name for export formats
            if (strcasecmp($newNameExtension, $fileIdentifierExtension) === 0) {
                $fileName = implode('.', $newNameParts);
            }
        }

        $file = new \Google_Service_Drive_DriveFile();
        $file->setName($fileName);
        $file->setParents([$targetFolderIdentifier]);

        try {
            $newFile = $this->getGoogleDriveService()->files->copy(
                $fileIdentifier,
                $file,
                []
            );
        } catch (\Exception $e) {
            throw new FileOperationErrorException(
                'Could not copy file ID "' . $fileIdentifier . '" due to a n exception: '
                . $e->getMessage() . ' (' . $e->getCode() . ')',
                1592044410
            );
        }

        return $newFile->id;
    }

    /**
     * @inheritDoc
     */
    public function renameFolder($folderIdentifier, $newName)
    {
        $identifier = $this->renameObject($folderIdentifier, $newName);
        return [$identifier => $identifier];
    }

    /**
     * @inheritDoc
     */
    public function renameFile($fileIdentifier, $newName)
    {
        return $this->renameObject($fileIdentifier, $newName);
    }

    /**
     * @param string $identifier
     * @param string $newName
     * @return string
     * @throws FileDoesNotExistException
     * @throws FileOperationErrorException
     * @throws Google_Service_Exception
     */
    protected function renameObject($identifier, $newName)
    {
        if (!$this->fileExists($identifier) && !$this->folderExists($identifier)) {
            throw new FileDoesNotExistException(
                'A file with the ID "' . $identifier . '" does not exist.',
                1591900471
            );
        }

        $originalIdentifier = $identifier;
        if ($this->identifierIsExportFormatRepresentation($identifier)) {
            list($identifier, $fileIdentifierExtension) = explode('.', $identifier, 2);

            $newNameParts = explode('.', $newName);
            $newNameExtension = array_pop($newNameParts);

            // Remove extension from file name for export formats
            if (strcasecmp($newNameExtension, $fileIdentifierExtension) === 0) {
                $newName = implode('.', $newNameParts);
            }
        }

        $file = new \Google_Service_Drive_DriveFile();

        if (!$this->getObjectByIdentifier($identifier)['capabilities']['canRename']) {
            throw new FileOperationErrorException(
                'Could not rename file ID "' . $identifier . '" because you do not have rename capability.',
                1591908161
            );
        }

        $file->setName($newName);

        try {
            $this->getGoogleDriveService()->files->update(
                $identifier,
                $file,
                [
                    'fields' => 'name',
                    'uploadType' => 'multipart'
                ]
            );
        } catch (\Google_Service_Exception $e) {
            throw new FileOperationErrorException(
                'Could not rename file ID "' . $identifier . '" to "' . $newName . '" due to a Google_Service_Exception: '
                . $e->getMessage() . ' (' . $e->getCode() . ')',
                1591901620
            );
        }

        if ($this->identifierIsExportFormatRepresentation($originalIdentifier)) {
            $record = $this->getObjectByIdentifier($originalIdentifier);

            foreach (
                self::GOOGLE_MIME_TYPE_TO_EXTENSIONS_AND_EXPORT_FORMATS[$record['originalMimeType']]
                as $extension => $mimeType
            ) {
                unset(self::$metaInfoCache[$identifier . '.' . $extension]);
            }
        } else {
            unset(self::$metaInfoCache[$originalIdentifier]);
        }

        return $originalIdentifier;
    }

    /**
     * @inheritDoc
     */
    public function replaceFile($fileIdentifier, $localFilePath)
    {
        return $this->setFileContents($fileIdentifier, file_get_contents($localFilePath)) > 0;
    }

    /**
     * @inheritDoc
     */
    public function deleteFolder($folderIdentifier, $deleteRecursively = false)
    {
        return $this->deleteObject($folderIdentifier, $deleteRecursively);
    }

    /**
     * @inheritDoc
     */
    public function deleteFile($fileIdentifier)
    {
        return $this->deleteObject($fileIdentifier);
    }

    /**
     * Delete an object from Google drive
     *
     * @param string $folderIdentifier
     * @param bool $deleteRecursively
     * @return bool
     */
    protected function deleteObject($identifier, $deleteRecursively = false)
    {
        if (
            $this->folderExists($identifier)
            && !$deleteRecursively
            && ($this->countFoldersInFolder($identifier) > 0 || $this->countFilesInFolder($identifier) > 0)
        ) {
            //End early if we're trying to non-recursively delete a folder with contents
            return false;
        }

        $originalIdentifier = $identifier;
        if ($this->identifierIsExportFormatRepresentation($identifier)) {
            list($identifier, $fileIdentifierExtension) = explode('.', $identifier, 2);

            $record = $this->getObjectByIdentifier($originalIdentifier);
        }

        $deleteSuccessful = (bool)$this->getGoogleDriveService()->files->delete($identifier);

        if ($deleteSuccessful) {
            if ($this->identifierIsExportFormatRepresentation($originalIdentifier)) {
                foreach (
                    self::GOOGLE_MIME_TYPE_TO_EXTENSIONS_AND_EXPORT_FORMATS[$record['originalMimeType']]
                    as $extension => $mimeType
                ) {
                    unset(self::$metaInfoCache[$identifier . '.' . $extension]);
                }
            } elseif (
                $this->folderExists($identifier)
                && ($this->countFoldersInFolder($identifier) > 0 || $this->countFilesInFolder($identifier) > 0)
            ) {
                // Remove the entire cache if the folder is not empty so we're recursing
                self::$metaInfoCache = [];
            } else {
                unset(self::$metaInfoCache[$originalIdentifier]);
            }
        }

        return $deleteSuccessful;
    }

    /**
     * @inheritDoc
     */
    public function hash($fileIdentifier, $hashAlgorithm)
    {
        return $this->hashIdentifier($fileIdentifier);
    }

    public function moveFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName)
    {
        // TODO: Implement moveFileWithinStorage() method.
    }

    public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName)
    {
        // TODO: Implement moveFolderWithinStorage() method.
    }

    public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName)
    {
        // TODO: Implement copyFolderWithinStorage() method.
    }

    /**
     * @inheritDoc
     */
    public function getFileContents($fileIdentifier)
    {
        $file = $this->getObjectByIdentifier($fileIdentifier);

        $service = $this->getGoogleDriveService();

        if ($this->identifierIsExportFormatRepresentation($fileIdentifier)) {
            list($identifier, $identifierExtension) = explode('.', $fileIdentifier);

            if (
            !isset(self::GOOGLE_MIME_TYPE_TO_EXTENSIONS_AND_EXPORT_FORMATS
                [$file['originalMimeType']][$identifierExtension])
            ) {
                throw new FileDoesNotExistException(
                    'The export mime type for ID "' . $fileIdentifier . '" does not exist.',
                    1591211438
                );
            }

            $response = $service->files->export(
                $identifier,
                self::GOOGLE_MIME_TYPE_TO_EXTENSIONS_AND_EXPORT_FORMATS
                [$file['originalMimeType']][$identifierExtension],
                ['alt' => 'media']
            );
        } else {
            $response = $service->files->get($fileIdentifier, ['alt' => 'media']);
        }

        return $response->getBody()->getContents() ?? '';
    }

    /**
     * @inheritDoc
     */
    public function setFileContents($fileIdentifier, $contents)
    {
        if (
            !$this->getObjectByIdentifier($fileIdentifier)['capabilities']['canEdit']
            || $this->identifierIsExportFormatRepresentation($fileIdentifier)
            || $contents === ''
        ) {
            return 0;
        }

        $service = $this->getGoogleDriveService();

        $file = new \Google_Service_Drive_DriveFile();

        try {
            $service->files->update(
                $fileIdentifier,
                $file,
                [
                    'data' => $contents,
                    'uploadType' => 'media'
                ]
            );
        } catch (\Google_Service_Exception $e) {
            throw new FileOperationErrorException(
                'Could not write file ID "' . $fileIdentifier . '" due to a Google_Service_Exception: '
                . $e->getMessage() . ' (' . $e->getCode() . ')',
                1591380094
            );
        }

        return mb_strlen($contents, '8bit');
    }

    /**
     * @inheritDoc
     */
    public function fileExistsInFolder($fileName, $folderIdentifier)
    {
        return $this->getObjectByNameInFolder($fileName, $folderIdentifier) === null ? false : true;
    }

    /**
     * @inheritDoc
     */
    public function folderExistsInFolder($folderName, $folderIdentifier)
    {
        return $this->getObjectByNameInFolder($folderName, $folderIdentifier, true) === null ? false : true;
    }

    /**
     * @inheritDoc
     *
     * @throws \RuntimeException when copying the file to local filesystem fails
     */
    public function getFileForLocalProcessing($fileIdentifier, $writable = true)
    {
        $temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);

        if (file_put_contents($temporaryPath, $this->getFileContents($fileIdentifier)) === false) {
            throw new \RuntimeException('Copying file ' . $fileIdentifier . ' to temporary path failed.', 1590526556);
        }

        if (!isset($this->temporaryPaths[$temporaryPath])) {
            $this->temporaryPaths[$temporaryPath] = $temporaryPath;
        }

        return $temporaryPath;
    }

    /**
     * Returns a temporary path for a given file, including the file extension.
     *
     * @param string $fileIdentifier
     * @return string
     */
    protected function getTemporaryPathForFile($fileIdentifier)
    {
        if ($this->identifierIsExportFormatRepresentation($fileIdentifier)) {
            $extension = PathUtility::pathinfo(
                $fileIdentifier,
                PATHINFO_EXTENSION
            );
        } else {
            $extension = PathUtility::pathinfo(
                $this->getObjectByIdentifier($fileIdentifier)['name'],
                PATHINFO_EXTENSION
            );
        }

        return GeneralUtility::tempnam('fal-tempfile-', '.' . $extension);
    }

    /**
     * @inheritDoc
     */
    public function getPermissions($identifier)
    {
        $capabilities = $this->getObjectByIdentifier($identifier)['capabilities'];

        //This is a very general implementation, but TYPO3 doesn't have other permissions than R and W.
        $r = $capabilities['canDownload']
            || $capabilities['canListChildren'];

        $w = $capabilities['canAddChildren']
            || $capabilities['canCopy']
            || $capabilities['canDelete']
            || $capabilities['canEdit']
            || $capabilities['canRemoveChildren']
            || $capabilities['canRename']
            || $capabilities['canTrash'];

        if ($this->identifierIsExportFormatRepresentation($identifier)) {
            return ['r' => $r, 'w' => false];
        }

        return ['r' => $r, 'w' => $w];
    }

    /**
     * @inheritDoc
     */
    public function dumpFileContents($identifier)
    {
        echo $this->getFileContents($identifier);
        exit;
    }

    /**
     * @inheritDoc
     */
    public function isWithin($folderIdentifier, $identifier)
    {
        if ($folderIdentifier === $identifier) {
            return true;
        }

        foreach ($this->getFilesInFolder($folderIdentifier) as $file) {
            if ($file['id'] === $identifier) {
                return true;
            }
        }

        // Second foreach to avoid an extra request if previous foreach returned true
        foreach ($this->getFoldersInFolder($folderIdentifier) as $folder) {
            if ($folder['id'] === $identifier) {
                return true;
            }
        }

        return false;
    }

    public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = [])
    {
        if (!$this->objectExists($fileIdentifier)) {
            return null;
        }

        $record = $this->getObjectByIdentifier($fileIdentifier);

        $metaInfo = [
            'name' => $record['name'],
            'identifier' => $record['id'],
            'ctime' => $this->convertGoogleDateTimeStringToTimestamp($record['createdTime']),
            'mtime' => $this->convertGoogleDateTimeStringToTimestamp($record['modifiedTime']),
            'identifier_hash' => $this->hashIdentifier($record['id']),
            'folder_hash' => $this->hashIdentifier($record['parents'][0]['id'] ?? 'root'),
            'extension' => PathUtility::pathinfo($record['name'], PATHINFO_EXTENSION),
            'storage' => $this->storageUid,
            'size' => $record['quotaBytesUsed'],
            'mimetype' => $record['mimeType'],
        ];

        if (count($propertiesToExtract) > 0) {
            $metaInfo = array_intersect_key($metaInfo, array_flip($propertiesToExtract));
        }

        return $metaInfo;
    }

    /**
     * @inheritDoc
     */
    public function getFolderInfoByIdentifier($folderIdentifier)
    {
        if ($folderIdentifier === '') {
            $folderIdentifier = $this->getRootLevelFolder();
        }

        $record = $this->getObjectByIdentifier($folderIdentifier);

        $metaInfo = [
            'name' => $record['name'],
            'identifier' => $record['id'],
            'storage' => $this->storageUid,
        ];

        return $metaInfo;
    }

    /**
     * @inheritDoc
     */
    public function getFileInFolder($fileName, $folderIdentifier)
    {
        return $this->getObjectByNameInFolder($fileName, $folderIdentifier)['id'] ?? $this->generateNewId();
    }

    /**
     * @param $name
     * @param $folderIdentifier
     * @return mixed|null
     */
    protected function getObjectByNameInFolder($name, $folderIdentifier, $isFolder = false)
    {
        foreach (
            $this->getRecordsInFolder(
                $folderIdentifier,
                null,
                null,
                null,
                [],
                null,
                null,
                $isFolder
            ) as $file) {
            if (strcasecmp($name, $file['name']) === 0) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Get files or folders within a folder
     *
     * Returns a list of files or folders inside the specified path
     *
     * @param string $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param bool $recursive
     * @param array $nameFilterCallbacks callbacks for filtering the items
     * @param string $sort Property name used to sort the items.
     *                     Among them may be: '' (empty, no sorting), name,
     *                     fileext, size, tstamp and rw.
     *                     If a driver does not support the given property, it
     *                     should fall back to "name".
     * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
     * @param bool $isFolder Returns a list of folders if true. Otherwise, files.
     * @return array of FileIdentifiers
     */
    protected function getObjectsInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $nameFilterCallbacks = [],
        $sort = '',
        $sortRev = false,
        $isFolder = false
    ) {
        $records = $this->getRecordsInFolder(
            $folderIdentifier,
            $start,
            $numberOfItems,
            $recursive,
            $nameFilterCallbacks,
            $sort,
            $sortRev,
            $isFolder
        );

        if ($start !== 0 || $numberOfItems !== 0) {
            $records = array_slice($records, $start, $numberOfItems === 0 ? null : $numberOfItems);
        }

        // Sort manually if we're recursing or if the sorting field is not one of the ones supported by Google natively.
        if (
            $sort !== null
            && ($recursive || !in_array($sort, ['', 'name', 'size', 'tstamp'], true))
            && count($records) > 1
        ) {
            switch ($sort) {
                case 'fileext':
                    usort($records, function ($a, $b) {
                        return strcmp(
                            pathinfo($a['name'], PATHINFO_EXTENSION),
                            pathinfo($b['name'], PATHINFO_EXTENSION)
                        );
                    });
                    break;
                case 'rw':
                    // TODO: Implement permission-based sorting when we implement permissions
                default:
                    $sortingKey = self::TYPO3_TO_GOOGLE_FIELDS[$sort];
                    if ($sortingKey === null || !in_array($sortingKey, array_keys($records[0]))) {
                        $sortingKey = 'name';
                    }
                    $records = ArrayUtility::sortArraysByKey($records, $sortingKey);
                    break;
            }
        }

        $objects = [];

        foreach ($records as $record) {
            $objects[$record['id']] = $record['id'];
        }

        if ($sortRev && count($objects) > 1) {
            $objects = array_reverse($objects);
        }

        return $objects;
    }

    /**
     * Get file or folder records within a folder
     *
     * Returns file or folder records inside the specified path
     *
     * @param string $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param bool $recursive
     * @param array $nameFilterCallbacks callbacks for filtering the items
     * @param string $sort Property name used to sort the items.
     *                     Among them may be: '' (empty, no sorting), name,
     *                     fileext, size, tstamp and rw.
     *                     If a driver does not support the given property, it
     *                     should fall back to "name".
     * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
     * @param bool $isFolder Returns a list of folders if true. Otherwise, files.
     * @return array of file record arrays
     */
    protected function getRecordsInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $nameFilterCallbacks = [],
        $sort = '',
        $sortRev = false,
        $isFolder = false
    ) {
        if ($folderIdentifier === null && $isFolder === true) {
            $folderIdentifier = $this->getRootLevelFolder();
        } elseif ($folderIdentifier === '' || $folderIdentifier === null) {
            return [];
        }

        $parameters['fields'] = $this->getFileFields('files');
        $parameters['q'] = ' \'' . $folderIdentifier . '\' in parents and trashed = false and ';
        if (!$isFolder) {
            $parameters['q'] .= ' not ';
        }
        $parameters['q'] .= ' mimeType=\'application/vnd.google-apps.folder\' ';

        $parameters['orderBy'] = self::TYPO3_TO_GOOGLE_FIELDS[$sort] ?? 'name';

        $parameters['pageSize'] = 1000;

        $parametersHash = md5(serialize($parameters));

        if (isset(self::$listQueryCache[$parametersHash])) {
            $records = self::$listQueryCache[$parametersHash];
        } else {
            $service = $this->getGoogleDriveService();

            $records = [];
            do {
                try {
                    $fileList = $service->files->listFiles($parameters);
                    $fileRecords = $fileList->getFiles();
                } catch (Google_Service_Exception $e) {
                    if ($e->getCode() === 404) {
                        break;
                    }
                }

                foreach ($fileRecords as $fileRecord) {
                    $fileRecord['capabilities'] = (array)$fileRecord->getCapabilities();
                    $records[] = (array)$fileRecord;
                }

                $parameters['pageToken'] = $fileList->getNextPageToken();
            } while ($parameters['pageToken'] !== null);

            $newRecordsArray = [];

            foreach ($records as $record) {
                $exportFormatObjects = $this->getExportFormatObjects($record);

                foreach ($exportFormatObjects as $exportFormatObject) {
                    self::$metaInfoCache[$exportFormatObject['id']] = $exportFormatObject;
                }

                $newRecordsArray = array_merge($newRecordsArray, $exportFormatObjects);
            }

            $records = $newRecordsArray;

            self::$listQueryCache[$parametersHash] = $records;
        }

        foreach ($records as $record) {
            if (
            !$this->applyFilterMethodsToDirectoryItem(
                $nameFilterCallbacks,
                $record['name'],
                $record['id'],
                $folderIdentifier
            )
            ) {
                continue;
            }
        }

        if ($recursive && count($records) <= $start + $numberOfItems) {
            if ($isFolder) {
                $folders = $records;
            } else {
                $folders = $this->getRecordsInFolder(
                    $folderIdentifier,
                    0,
                    0,
                    true,
                    $nameFilterCallbacks,
                    '',
                    false,
                    true
                );
            }

            foreach ($folders as $folder) {
                $recordsInFolder = $this->getObjectsInFolder(
                    $folder['id'],
                    0,
                    $start + $numberOfItems - count($records),
                    true,
                    $nameFilterCallbacks
                );

                $records = array_merge($records, $recordsInFolder);

                if (count($records) > $start + $numberOfItems) {
                    break;
                }
            }
        }

        return $records;
    }

    /**
     * @inheritDoc
     */
    public function getFilesInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $filenameFilterCallbacks = [],
        $sort = '',
        $sortRev = false
    ) {
        return $this->getObjectsInFolder(
            $folderIdentifier,
            $start,
            $numberOfItems,
            $recursive,
            $filenameFilterCallbacks,
            $sort,
            $sortRev
        );
    }

    /**
     * @inheritDoc
     */
    public function getFolderInFolder($folderName, $folderIdentifier)
    {
        return $this->getObjectByNameInFolder($folderName, $folderIdentifier, true)['id']
            ?? $this->generateNewId();
    }

    /**
     * @inheritDoc
     */
    public function getFoldersInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $folderNameFilterCallbacks = [],
        $sort = '',
        $sortRev = false
    ) {
        return $this->getObjectsInFolder(
            $folderIdentifier,
            $start,
            $numberOfItems,
            $recursive,
            $folderNameFilterCallbacks,
            $sort,
            $sortRev,
            true
        );
    }

    /**
     * @inheritDoc
     */
    public function countFilesInFolder($folderIdentifier, $recursive = false, array $filenameFilterCallbacks = [])
    {
        return count($this->getFilesInFolder(
            $folderIdentifier,
            0,
            0,
            $recursive,
            $filenameFilterCallbacks
        ));
    }

    public function countFoldersInFolder($folderIdentifier, $recursive = false, array $folderNameFilterCallbacks = [])
    {
        return count($this->getFoldersInFolder(
            $folderIdentifier,
            0,
            0,
            $recursive,
            $folderNameFilterCallbacks
        ));
    }

    public function getParentFolderIdentifierOfIdentifier($identifier)
    {
        if ($identifier === $this->getRootLevelFolder()) {
            return $identifier;
        }

        $record = $this->getObjectByIdentifier($identifier);

        if ($record === null || count($record['parents']) === 0) {
            throw new ResourceDoesNotExistException();
        }

        return $record['parents'][0];
    }

    /**
     * Checks that the supplied file identifier is correct.
     *
     * @param string $theFile File identifier (aka. file path)
     * @return bool true if valid Google identifier
     * @see \TYPO3\CMS\Core\Utility\GeneralUtility::validPathStr()
     */
    protected function isPathValid($theFile)
    {
        return (bool)preg_match(self::IDENTIFIER_PATTERN, $theFile);
    }

    /**
     * @inheritDoc
     */
    protected function canonicalizeAndCheckFilePath($filePath)
    {
        return $this->canonicalizeAndCheckFileIdentifier($filePath);
    }

    /**
     * @inheritDoc
     */
    protected function canonicalizeAndCheckFileIdentifier($fileIdentifier)
    {
        if ($fileIdentifier === 'root') {
            return $this->getDefaultFolder();
        }

        return $this->canonicalizeAndCheckObjectIdentifier($fileIdentifier);
    }

    /**
     * @inheritDoc
     */
    protected function canonicalizeAndCheckFolderIdentifier($folderPath)
    {
        return $this->canonicalizeAndCheckObjectIdentifier($folderPath);
    }

    /**
     * Makes sure the identifier given as parameter is valid
     *
     * @param string $fileIdentifier The file path (including the file name!)
     * @return string
     * @throws InvalidPathException
     */
    protected function canonicalizeAndCheckObjectIdentifier($identifier)
    {
        if (!$this->isPathValid($identifier)) {
            throw new InvalidPathException('Invalid file identifier: "' . $identifier . "'", 1591534644);
        }

        return $identifier;
    }

    protected function initializeClient()
    {
        if (!$this->googleDriveClient) {
            $this->googleDriveClient = new Client();
            StreamWrapper::register($this->googleDriveClient, $this->streamWrapperProtocol);
        }

        return $this;
    }

    /**
     * Applies a set of filter methods to a file name to find out if it should be used or not. This is e.g. used by
     * directory listings.
     *
     * @param array $filterMethods The filter methods to use
     * @param string $itemName
     * @param string $itemIdentifier
     * @param string $parentIdentifier
     * @throws \RuntimeException
     * @return bool
     */
    protected function applyFilterMethodsToDirectoryItem(
        array $filterMethods,
        $itemName,
        $itemIdentifier,
        $parentIdentifier
    ) {
        foreach ($filterMethods as $filter) {
            if (is_array($filter)) {
                $result = call_user_func($filter, $itemName, $itemIdentifier, $parentIdentifier, [], $this);
                // We have to use -1 as the "don't include" return value, as call_user_func() will return FALSE
                // if calling the method succeeded and thus we can't use that as a return value.
                if ($result === -1) {
                    return false;
                }
                if ($result === false) {
                    throw new \RuntimeException('Could not apply file/folder name filter ' . $filter[0] . '::' . $filter[1]);
                }
            }
        }
        return true;
    }

    /**
     * Returns a unix timestamp from a Google DateTime string (zero if invalid)
     *
     * Example input: 2014-06-24T22:39:34.652Z
     *
     * @param $dateTimeString
     * @return int
     */
    protected function convertGoogleDateTimeStringToTimestamp($dateTimeString): int
    {
        $dateTime = $this->convertGoogleDateTimeStringToDateTime($dateTimeString);

        if ($dateTime === null) {
            return 0;
        }

        return $dateTime->getTimestamp();
    }

    /**
     * Returns a DateTime object from a Google DateTime string (null if invalid)
     *
     * Example input: 2014-06-24T22:39:34.652Z
     *
     * @param $dateTimeString
     * @return \DateTime|null
     */
    protected function convertGoogleDateTimeStringToDateTime($dateTimeString): ?\DateTime
    {
        return \DateTime::createFromFormat('Y-m-d\TH:i:s.???\Z', $dateTimeString) ?? null;
    }

    /**
     * Returns a new Google file (or folder) ID
     *
     * @return string
     */
    protected function generateNewId()
    {
        $service = $this->getGoogleDriveService();

        return $service->files->generateIds([
            'maxResults' => 1,
            'space' => 'drive'
        ])['ids'][0];
    }

    /**
     * Returns the comma separated list of file fields, with possible prefix, to be returned by Google
     *
     * @param string $prefix
     * @return string
     */
    public function getFileFields(string $prefix = ''): string
    {
        $fileFields = $this->fileFields;

        if ($prefix !== '') {
            $fileFields = preg_filter('/^/', $prefix . '/', $fileFields);
        }

        return implode(',', $fileFields);
    }

    /**
     * Returns the google drive service
     *
     * @return \Google_Service_Drive|null
     */
    protected function getGoogleDriveService(): ?\Google_Service_Drive
    {
        if ($this->googleDriveService !== null) {
            return $this->googleDriveService;
        }

        if ($this->googleDriveClient === null) {
            return null;
        }

        return new \Google_Service_Drive($this->googleDriveClient->getClient());
    }

    /**
     * Returns true if the supplied identifier isn't "real", but a google document, spreadsheet, etc. export format.
     *
     * @param $identifier
     * @return bool
     */
    protected function identifierIsExportFormatRepresentation($identifier)
    {
        return strpos($identifier, '.') !== false;
    }
}
