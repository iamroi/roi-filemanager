<?php

namespace Iamroi\FileManager;

//use Rakit\Validation\Rules\UploadedFile;
use Iamroi\Flysystem\Pdo\PdoPathAdapter as PdoPathAdapter;
use League\Flysystem\Adapter\Local as LocalAdapter;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem as Filesystem;
use League\Flysystem\Replicate\ReplicateAdapter as ReplicateAdapter;
use League\Flysystem\Util;
use Iamroi\FileManager\Helpers as FileManagerHelpers;
use Iamroi\FileManager\Exception as Ex;

class FileManager
{
    public $pdo;

    public $fileManagerDir = 'media';

    public $publicRoot;

    public $fileManagerRoot;

    public $pdoPathAdapter;

    public $localAdapter;

    public $replicaAdapter;

    public $filesystem;

    /**
     * FileManager constructor.
     * @param $config
     */
    public function __construct($config)
    {
        $this->pdo = new \PDO('mysql:host='.$config['db']['host'].';dbname='.$config['db']['database'], $config['db']['username'], $config['db']['password']);

        $this->publicRoot = $config['publicRoot'] ?? ''; //storage_path('app/public');

        $this->fileManagerDir = $config['fileManagerDir'] ? rtrim(ltrim($config['fileManagerDir'], '\\/'), '\\/') : '';

        $this->fileManagerRoot = implode('/', array_filter([$this->publicRoot, $this->fileManagerDir], 'strlen'));

        $this->pdoPathAdapter = new PdoPathAdapter($this->pdo, $this->fileManagerRoot);
//        $this->>pdoAdapter->setPathPrefix($fileDestinationPrefix);
        $this->localAdapter = new LocalAdapter($this->fileManagerRoot); //$fileDestination
//        $this->>replicaAdapter = new \League\Flysystem\Replicate\ReplicateAdapter($pdoAdapter, $localAdapter);
        $this->replicaAdapter = new ReplicateAdapter($this->localAdapter, $this->pdoPathAdapter);

        $this->filesystem = new Filesystem($this->replicaAdapter); //['disable_asserts' => true]

//        $this->search = '';
    }

    /**
     * @param string $path
     * @param string $uploadName
     * @throws Ex\IncorrectUploadName
     * @throws \Exception
     * @return mixed
     */
    public function upload($path = '', $uploadName = 'file')
    {
        if(!isset($_FILES[$uploadName])) {
            throw new Ex\IncorrectUploadName(
                'Could not find any files under name: ' . $uploadName
            );
        }

        $fileDestination = $path ?? '';  //TODO PLAY AROUND WITH THIS
        $fileDestination = FileManagerHelpers::cleanFilePath($fileDestination);

//        print_r($_FILES);
//        exit;
        $uploadedFiles = [];
        $failedFiles = [];

        $inputFiles = $this->normalizeFilesInput($uploadName);

        foreach($inputFiles['name'] as $k => $fileName) {

            $fileName = FileManagerHelpers::uniqueFileName($this->fileManagerRoot . '/' . $fileDestination, $fileName);

            $fileUri = $fileDestination . '/' . $fileName;
            $fileUri = Util::normalizePath($fileUri);

            $stream = fopen($inputFiles['tmp_name'][$k], 'r+');

            try {
                $replicaResult = $this->filesystem->writeStream($fileUri, $stream);
            } catch (\Exception $e) {
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
            if (!$replicaResult) {

                $failedFiles[] = $fileName;
                continue;
            }

//        dump($fileUri);
            $pathData = $this->pdoPathAdapter->findPathData($fileUri);
            $pathData = $this->pdoPathAdapter->normalizeMetadata($pathData);
            $uploadedFiles[] = $pathData;
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
            $replicaResult = $this->filesystem->createDir($path);
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
    public function listContents($path, $search = '', $page = 1, $limit = 3)
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

//        $pdoAdapter = new \Zdule\Flysystem\Pdo\PdoPathAdapter($this->pdo, $this->fileManagerRoot);
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
    public function destroy($path)
    {
        $deletedFiles = [];
        $failedFiles = [];

//        $fileUris = $_REQUEST['path'];  //TODO PLAY AROUND WITH THIS
//        $fileUris = $path;  //TODO PLAY AROUND WITH THIS
        $fileUris = is_array($path) ? $path : [$path];

        $pdoFilesystem = new Filesystem($this->pdoPathAdapter); //['disable_asserts' => true]
        $destinationFilesystem = new Filesystem($this->localAdapter); //['disable_asserts' => true]

        foreach ($fileUris as $k => $fileUri) {
            $fileUri = FileManagerHelpers::sanitize($fileUri);
//        dd($fileUri);
//        $fileUri = rtrim(ltrim($fileUri, '\\/'), '\\/');
            $filePath = implode('/', array_filter([$this->fileManagerRoot, $fileUri], 'strlen'));

            $pathData = $this->pdoPathAdapter->findPathData($fileUri);
            $pathData = $this->pdoPathAdapter->normalizeMetadata($pathData);

//        dd($pathData);
//            dd($filePath);
//            if(!$pathData) {
//                // hmm, file not found in the db
//                // assuming it's already deleted and proceeding
//
////                $deletedFiles[] = $pathData;
////                $failedFiles[] = $fileUri;
////                continue;
////                return responder()->error('file_not_found', 'Sorry, file or folder could not be found.')->respond(404);
//            }

//            $this->filesystem = new Filesystem($this->replicaAdapter, ['disable_asserts' => true]); //['disable_asserts' => true]

//            $this->localAdapter = new LocalAdapter($this->fileManagerRoot); //$fileDestination
//            $this->replicaAdapter = new ReplicateAdapter($this->localAdapter, $this->pdoPathAdapter);

//            $this->pdoPathAdapter = new PdoPathAdapter($this->pdo, $this->fileManagerRoot);

//            $this->filesystem = new Filesystem($this->localAdapter); //['disable_asserts' => true]

            try {

                // todo check if replica adapter can be used
                /*if (is_dir($filePath)) {
                    $this->filesystem->deleteDir($fileUri);
                } else {
                    $this->filesystem->delete($fileUri);
                }*/

                // delete pdo
//                $this->filesystem = new Filesystem($this->pdoPathAdapter); //['disable_asserts' => true]
                if($pathData) {
//                    $this->filesystem = new Filesystem($this->pdoPathAdapter); //['disable_asserts' => true]
                    if ($pathData['type'] === 'dir') {
                        $pdoFilesystem->deleteDir($fileUri);
                    } else {
                        $pdoFilesystem->delete($fileUri);
                    }
                }

                // delete local
                if(file_exists($filePath)) {
//                    $this->filesystem = new Filesystem($this->localAdapter); //['disable_asserts' => true]
                    if (is_dir($filePath)) {
                        $destinationFilesystem->deleteDir($fileUri);
                    } else {
                        $destinationFilesystem->delete($fileUri);
                    }
                }

            } catch (FileNotFoundException $e) {
                $deletedFiles[] = $pathData;
                continue;
            } catch (\Exception $e) {
//                dd($e);
                $failedFiles[] = $fileUri;
                continue;
//                throw $e;

//            dd($e->getMessage());
//                return responder()->error($e->getCode(), $e->getMessage())->respond();
            }

            $deletedFiles[] = $pathData;
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
}
