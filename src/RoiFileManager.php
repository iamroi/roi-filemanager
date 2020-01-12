<?php

namespace Iamroi\RoiFileManager;

//use Rakit\Validation\Rules\UploadedFile;
use Iamroi\Flysystem\Pdo\PdoPathAdapter as PdoPathAdapter;
use Iamroi\RoiFileManager\RoiImage;
use League\Flysystem\Adapter\Local as LocalAdapter;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem as Filesystem;
use League\Flysystem\Replicate\ReplicateAdapter as ReplicateAdapter;
use League\Flysystem\Config;
use League\Flysystem\Util;
use League\Event\Emitter;
use Iamroi\RoiFileManager\Providers\ListenerProvider;
use Iamroi\RoiFileManager\Helpers as FileManagerHelpers;
use Iamroi\RoiFileManager\Exception as Ex;

class RoiFileManager
{
    const PACKAGE_NAME = 'roi-filemanager';

    const DS = '/';

    public $pdo;

    public $config;

//    public $fileManagerDir = 'media';

    public $publicRoot;

//    public $fileManagerRoot;

    public $pdoPathAdapter;

    public $physicalAdapter;

//    public $localAdapter;

//    public $replicaAdapter;

//    public $filesystem;

    public $filesystemPdo;

    public $filesystemPhysical;

//    public $imageResizer;

    public $roiImage;

    public $eventEmitter;

    /**
     * FileManager constructor.
     * @param $config
     */
    public function __construct($config = null)
    {
        $config = is_array($config) ? new Config($config) : $config;
        $config = $config === null ? new Config : $config;

        $config->setFallback(new Config([
            'publicRoot' => '',
            'fileManagerDir' => 'media',
            'createThumb' => true,
            'thumbWidth' => 200,
            'thumbHeight' => 200,
//            'constraintImageWidth' => 1200,
//            'constraintImageHeight' => 628,
//            'constraintImageWidth' => 1000,
//            'constraintImageHeight' => 768,
        ]));

        $this->config = $config;

//        $databaseConfig = $this->config->get('db');
        if(!$this->config->get('host') ||
            !$this->config->get('database') ||
            !$this->config->get('username') ||
            !$this->config->get('password')) {
            throw new Ex\MissingConfiguration(
                'Database configuration missing.'
            );
        }

        $this->pdo = new \PDO('mysql:host='.$this->config->get('host').';dbname='.$this->config->get('database'), $this->config->get('username'), $this->config->get('password'));

//        $this->publicRoot = $this->config->get('publicRoot'); //storage_path('app/public');

//        $this->fileManagerDir = $config['fileManagerDir'] ? rtrim(ltrim($config['fileManagerDir'], '\\/'), '\\/') : '';
//        $this->fileManagerDir = rtrim(ltrim($this->config->get('fileManagerDir'), '\\/'), '\\/');
        $this->config->set('fileManagerDir', rtrim(ltrim($this->config->get('fileManagerDir'), '\\/'), '\\/'));

//        $this->fileManagerRoot = implode('/', array_filter([$this->config->get('publicRoot'), $this->config->get('fileManagerDir')], 'strlen'));
        $fileManagerRoot = implode('/', array_filter([$this->config->get('publicRoot'), $this->config->get('fileManagerDir')], 'strlen'));

        $this->config->set('fileManagerRoot', $fileManagerRoot);

        $this->pdoPathAdapter = new PdoPathAdapter($this->pdo, $this->config->get('fileManagerRoot'), $this->config);
//        $this->>pdoAdapter->setPathPrefix($fileDestinationPrefix);

        $this->filesystemPdo = new Filesystem($this->pdoPathAdapter); //['disable_asserts' => true]

        $this->physicalAdapter = $this->config->get('physicalAdapter', new LocalAdapter($this->config->get('fileManagerRoot')));

        $this->config->set('physicalAdapter', $this->physicalAdapter);

        $this->filesystemPhysical = new Filesystem($this->physicalAdapter); //['disable_asserts' => true]


        // todo
//        $this->localAdapter = new LocalAdapter($this->config->get('fileManagerRoot')); //$fileDestination

        // todo
//        $this->replicaAdapter = new ReplicateAdapter($this->physicalAdapter, $this->pdoPathAdapter);

//        $this->filesystem = new Filesystem($this->replicaAdapter); //['disable_asserts' => true]

//        $this->imageResizer = new RoiImage();

        $this->roiImage = new RoiImage($this->config);

        $this->eventEmitter = new Emitter;

        $this->eventEmitter->useListenerProvider(new ListenerProvider);
//        $this->search = '';
    }

    /**
     * @param string $filePath
     * @param string $uploadName
     * @return mixed
     * @throws Ex\IncorrectUploadName
     */
    public function upload($destination = '', $uploadName = 'file')
    {
        if(!isset($_FILES[$uploadName])) {
            throw new Ex\IncorrectUploadName(
                'Could not find any files under name: ' . $uploadName
            );
        }

        $destination = $destination ?? '';  //TODO PLAY AROUND WITH THIS
        $destination = FileManagerHelpers::cleanFilePath($destination);

//        print_r($_FILES);
//        exit;
        $uploadedFiles = [];
        $failedFiles = [];

        $inputFiles = $this->normalizeFilesInput($uploadName);

        foreach($inputFiles['name'] as $k => $fileName) {

            $absDestination = implode('/', array_filter([$this->config->get('fileManagerRoot'), $destination], 'strlen'));

            $fileName = FileManagerHelpers::uniqueFileName($absDestination, $fileName);

            $fileUri = $destination . '/' . $fileName;
            $fileUri = Util::normalizePath($fileUri);

            // constrain to max width and height if it's an image
            $this->roiImage
                ->setFile($inputFiles['tmp_name'][$k])
                ->setFileName($fileName)
                ->constrainImage();

            $stream = fopen($inputFiles['tmp_name'][$k], 'r+');

            try {
                $physicalResult = $this->filesystemPhysical->writeStream($fileUri, $stream);

                if(!$physicalResult) {
                    throw new Ex\UploadFailed(
                        'Cannot create file in physical location.'
                    );
                }

                // trigger physical location upload success
                // functions like thumbnail creation etc
                $this->eventEmitter->emit('roifm.file.physical.uploaded', $fileUri, $this->config); //, 'param value'

                $pdoResult = $this->filesystemPdo->writeStream($fileUri, $stream);

                if(!$pdoResult) {
                    throw new Ex\UploadFailed(
                        'Cannot create file in database.'
                    );
                }

//                $replicaResult = $this->filesystem->writeStream($fileUri, $stream);
            } catch (\Exception $e) {
                dd($e);
                $failedFiles[] = $fileUri;
                continue;
//                throw $e;
//            dd($e->getMessage());
//                return responder()->error($e->getCode(), $e->getMessage())->respond();
            }

            if (is_resource($stream)) {
                fclose($stream);
            }

//        dd($replicaResult);
            if (!$pdoResult) {

                $failedFiles[] = $fileName;
                continue;
            }

//        dump($fileUri);
            $pathData = $this->pdoPathAdapter->findPathData($fileUri);
            $pathData = $this->pdoPathAdapter->normalizeMetadata($pathData);

//            $pathData[]$this->createThumbnail($pathData);

            $uploadedFiles[] = $pathData;

            $this->eventEmitter->emit('roifm.file.uploaded', $pathData, $this->config); //, 'param value'
        }

        if(count($uploadedFiles) === 0) {
            return false;
        }


        return $uploadedFiles;
//        return ['uploaded' => $uploaded, 'failed' => $failed];
    }

    /**
     * @param $path
     * @return array|bool|false
     * @throws \Exception
     */
    public function createDir($path)
    {
//        $newDirUris = is_array($path) ? $path : [$path];
        $path = trim($path);

        $path = FileManagerHelpers::cleanFilePath($path);
        //        $path = rtrim(ltrim($path, '\\/'), '\\/');
        //        $path = Util::normalizePath($path);
        //        $folderDestination = implode('/', array_filter([$this->fileManagerDir, $path], 'strlen'));

//        dd($path);

        // TODO need to check and create parent directories


        try {
//            $replicaResult = $this->filesystem->createDir($path);
            $physicalResult = $this->filesystemPhysical->createDir($path);

            if(!$physicalResult) {
                throw new Ex\UploadFailed(
                    'Cannot create directory in physical location.'
                );
            }

            $pdoResult = $this->filesystemPdo->createDir($path);

            if(!$pdoResult) {
                throw new Ex\UploadFailed(
                    'Cannot create directory in database.'
                );
            }

        } catch (\Exception $e) {
//                $failedDirs[] = $path;
            throw $e;
            //            dd($e->getMessage());
//                return responder()->error($e->getCode(), $e->getMessage())->respond();
        }

        $pathData = $this->pdoPathAdapter->findPathData($path);
        $pathData = $this->pdoPathAdapter->normalizeMetadata($pathData);

        return $pathData;
    }

    /**
     * @param $paths
     * @return array|bool
     */
    public function createDirectories($paths)
    {
        $newDirUris = is_array($paths) ? $paths : [$paths];

        $createdDirs = [];
        $failedDirs = [];
        foreach ($newDirUris as $k => $newDirUri) {
            $newDirUri = trim($newDirUri);

            try {
                $pathData = $this->createDir($newDirUri);
//                dd($pathData);
                if(!$pathData) {
                    $failedDirs[] = $newDirUri;
                    continue;
//                return responder()->error('new_folder_failed', "Sorry, folder wasn't created.")->respond();
                }

            } catch (\Exception $e) {
                $failedDirs[] = $newDirUri;
                continue;
//                throw $e;
                //            dd($e->getMessage());
            }

            $createdDirs[] = $pathData;
        }

        if(count($createdDirs) === 0) {
            return false;
        }

        return $createdDirs;
    }

    /**
     * @param $path
     * @param string $search
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \Exception
     */
    public function list($path, $search = '', $page = 1, $limit = 3)
    {
        $path = $path ?? '';
        $path = FileManagerHelpers::cleanFilePath($path);
//        $path = rtrim(ltrim($path, '\\/'), '\\/'); //.'/'.$this->fileManagerDir;
//        $path = Util::normalizePath($path);
//        $path = implode('/', array_filter([$this->fileManagerDir, $path], 'strlen'));
//        dd($path);

        $this->pdoPathAdapter->setSearch($search);

        $this->pdoPathAdapter->setPage($page);

        $this->pdoPathAdapter->setPaginationLimit($limit);

//        $pdoAdapter = new \MailTinker\Flysystem\Pdo\PdoPathAdapter($this->pdo, $this->config->get('fileManagerRoot'));
        $filesystem = new \League\Flysystem\Filesystem($this->pdoPathAdapter);

        try {
            $mediaList = $filesystem->listContents($path);
        } catch (\Exception $e) {
            throw $e;
//            dd($e->getMessage());
//            return false;
        }

        return [
            'files' => $mediaList,
            'pagination' => [
                "total" => $this->pdoPathAdapter->totalRowCount,
                "count" => $this->pdoPathAdapter->totalRowCount,
                "perPage" => $limit,
                "currentPage" => $page,
                "totalPages" => ceil($this->pdoPathAdapter->totalRowCount/$limit),
                "links" => []
            ]
        ];
    }

    /**
     * @param $path string|array
     * @return array|bool
     */
    public function delete($path)
    {
        $deletedFiles = [];
        $failedFiles = [];

//        $filePaths = $_REQUEST['path'];  //TODO PLAY AROUND WITH THIS
//        $fileUris = $path;  //TODO PLAY AROUND WITH THIS
        $filePaths = is_array($path) ? $path : [$path];

//        $pdoFilesystem = new Filesystem($this->pdoPathAdapter); //['disable_asserts' => true]
//        $destinationFilesystem = new Filesystem($this->localAdapter); //['disable_asserts' => true]

        foreach ($filePaths as $k => $filePath) {
            $filePath = FileManagerHelpers::sanitize($filePath);
//        dd($filePath);
//        $filePath = rtrim(ltrim($filePath, '\\/'), '\\/');
            $fileAbsPath = implode('/', array_filter([$this->config->get('fileManagerRoot'), $filePath], 'strlen'));

            $fileInfo = $this->pdoPathAdapter->findPathData($filePath);
            $fileInfo = $this->pdoPathAdapter->normalizeMetadata($fileInfo);

//        dd($fileInfo);
//            dd($filePath);
//            if(!$fileInfo) {
//                // hmm, file not found in the db
//                // assuming it's already deleted and proceeding
//
////                $deletedFiles[] = $fileInfo;
////                $failedFiles[] = $filePath;
////                continue;
////                return responder()->error('file_not_found', 'Sorry, file or folder could not be found.')->respond(404);
//            }

//            $this->filesystem = new Filesystem($this->replicaAdapter, ['disable_asserts' => true]); //['disable_asserts' => true]

//            $this->localAdapter = new LocalAdapter($this->config->get('fileManagerRoot')); //$fileDestination
//            $this->replicaAdapter = new ReplicateAdapter($this->localAdapter, $this->pdoPathAdapter);

//            $this->pdoPathAdapter = new PdoPathAdapter($this->pdo, $this->config->get('fileManagerRoot'));

//            $this->filesystem = new Filesystem($this->localAdapter); //['disable_asserts' => true]

            try {

                // todo check if replica adapter can be used
                /*if (is_dir($filePath)) {
                    $this->filesystem->deleteDir($filePath);
                } else {
                    $this->filesystem->delete($filePath);
                }*/

                // deleting in pdo if it wasn't already
//                $this->filesystem = new Filesystem($this->pdoPathAdapter); //['disable_asserts' => true]
                if($fileInfo) {
//                    $this->filesystem = new Filesystem($this->pdoPathAdapter); //['disable_asserts' => true]
                    if ($fileInfo['type'] === 'dir') {
                        $this->filesystemPdo->deleteDir($filePath);

                    } else {
                        $this->filesystemPdo->delete($filePath);
                    }
                }

//                dd($filePath);

                // it could be possible for this item is orphaned in physical i.e no entry in pdo
                // so deleting physical without relying on pdo pathdata
                $physicalFileMeta = $this->filesystemPhysical->getMetadata($filePath);
//                dd($physicalFileMeta);

                if ($physicalFileMeta['type'] == 'dir') {
                    $this->filesystemPhysical->deleteDir($filePath);
                } else {
                    $this->filesystemPhysical->delete($filePath);
                }

                // delete thumb in physical
                // todo what if the item was already deleted in pdo i.e empty $fileInfo? we got an orphaned thumb file here!!
                if ($fileInfo && $fileInfo['thumb_path'] !== '') {
                    $this->filesystemPhysical->delete($fileInfo['thumb_path']);
                }

                // trigger physical location delete success
                // functions: remove thumbnails, remove other versions of the file etc
                $this->eventEmitter->emit('roifm.item.deleted', $fileInfo, $this->config);

            } catch (FileNotFoundException $e) {
                $deletedFiles[] = $fileInfo;
                continue;
            } catch (\Exception $e) {
//                dd($e);
                $failedFiles[] = $filePath;
                continue;
//                throw $e;

//            dd($e->getMessage());
//                return responder()->error($e->getCode(), $e->getMessage())->respond();
            }

            $deletedFiles[] = $fileInfo;
        }

        if(count($deletedFiles) === 0) {
            return false;
        }

        return $deletedFiles;
    }

    /**
     * @param $uploadName
     * @return array|mixed
     */
    private function normalizeFilesInput($uploadName)
    {
        if(FileManagerHelpers::isMultipleFileUpload($uploadName)) {
            return $_FILES[$uploadName];
        }

        $inputFiles = [];
        foreach ($_FILES[$uploadName] as $fieldName => $fieldValue) {
            $inputFiles[$fieldName] = [$fieldValue];
        }

        return $inputFiles;
    }

    /**
     * Get directory seperator of current operating system.
     *
     * @return string
     */
    public function ds()
    {
        $ds = RoiFileManager::DS;
        if ($this->isRunningOnWindows()) {
            $ds = '\\';
        }
        return $ds;
    }

    /**
     * Check current operating system is Windows or not.
     *
     * @return bool
     */
    public function isRunningOnWindows()
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

}
