<?php

// //https://github.com/phlib/flysystem-pdo/blob/master/src/PdoAdapter.php

namespace Iamroi\Flysystem\Pdo;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
use League\Flysystem\Util;
use LogicException;

class PdoPathAdapter extends AbstractAdapter
{
    /**
     * @var \PDO
     */
    protected $db;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var string
     */
    protected $pathTable;

    /**
     * @var string
     */
    protected $root;

    /**
     * @var string
     */
    protected $search;

    /**
     * @var integer
     */
    protected $page;

    /**
     * @var integer
     */
    protected $paginationLimit = 5;

    /**
     * @var integer
     */
    public $totalRowCount;

    /**
     * PdoAdapter constructor.
     * @param \PDO $db
     * @param Config $config
     */
    public function __construct(\PDO $db, $root, Config $config = null)
    {
        $this->db = $db;

        $this->db->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );

        $root = is_link($root) ? realpath($root) : $root;
        $this->ensureDirectory($root);

        if ( ! is_dir($root) || ! is_readable($root)) {
            throw new LogicException('The root path ' . $root . ' is not readable.');
        }

        if ($config === null) {
            $config = new Config;
        }

        $this->config = $config;

//        $defaultTable = 'file_manager';

        $this->config->set('table', $this->config->get('table', 'file_manager'));
        $this->config->set('temp_dir', $this->config->get('temp_dir', sys_get_temp_dir()));

//        $config->setFallback(new Config([
//            'table'            => $defaultTable,
//            'temp_dir'         => sys_get_temp_dir(),
//        ]));


//        $this->pathPrefix = '/';
        $this->root = $root;

//        $table = trim($this->config->get('table'));
//        if ($table == '') {
//            $table = $defaultTable;
//        }
        $this->pathTable  = trim($this->config->get('table'));
    }

    public function setSearch($search)
    {
        $this->search = $search;
    }

    public function resetSearch()
    {
        $this->search = '';
    }

    public function setPage($page)
    {
        $this->page = $page;
    }

    public function setPaginationLimit($paginationLimit)
    {
        $this->paginationLimit = $paginationLimit;
    }

    public function setTotalRowCount($count)
    {
        $this->totalRowCount = $count;
    }

    /**
     * @inheritdoc
     */
    public function write($path, $contents, Config $config)
    {
        $absPath = $this->getAbsolutePath($path);
        $tmpResource = null;
        if(!file_exists($this->getAbsolutePath($path))) {
            $absPath = $this->getTempFilename();
            $tmpResource = $this->getTempResource($absPath, $contents);
        }

        return $this->doWrite($path, $absPath, $contents, $tmpResource, $config);
    }

    /**
     * @inheritdoc
     */
    public function writeStream($path, $resource, Config $config)
    {
//        dd(($path));

        $absPath = $this->getAbsolutePath($path);
        $tmpResource = null;

//        dd($absPath);
        if(!file_exists($this->getAbsolutePath($path))) {
            $absPath = $this->getTempFilename();
            $tmpResource = $this->getTempResource($absPath, $resource);
        }

        return $this->doWrite($path, $absPath, '', $tmpResource, $config);
    }

    /**
     * @param string $path
     * @param string $absPath
     * @param string $contents
     * @param $tmpResource
     * @param Config $config
     * @return array|false
     */
    protected function doWrite($path, $absPath, $contents, $tmpResource, Config $config)
    {
//        dd($absPath);
//        dd(dirname($this->applyPathPrefix($path)));

        if($existingFile = $this->findPathData($path)) {
//            dd($existingFile);
//            $data['path_id'] = $existingFile['path_id'];
            return $this->normalizeMetadata($existingFile);
        }

        $data = [
//            'path'          => $this->pathPrefix ? $this->applyPathPrefix($path) : $path, //$path, //$this->applyPathPrefix($path),
            'path'          => $path, //$path, //$this->applyPathPrefix($path),
            'type'          => 'file',
            'mime_type'      => Util::guessMimeType($absPath, $contents),
            'visibility'    => $config->get('visibility', AdapterInterface::VISIBILITY_PUBLIC),
            'size'          => filesize($absPath),
        ];
        $expiry = null;
        if ($config->has('expiry')) {
            $expiry = $data['expiry'] = $config->get('expiry');
        }
        $meta = null;
        if ($config->has('meta')) {
            $meta = $data['meta'] = $config->get('meta');
        }

//        if($existingFile = $this->findPathData($path)) {
////            dd($existingFile);
//            $data['path_id'] = $existingFile['path_id'];
//            return $this->normalizeMetadata($data);
//        }

        // create the parent directory if not exists
        $dirConfig = new Config();
        $dirnameParts = explode($this->pathSeparator, $this->sanitizeDirname($path));
//        dd($dirnameParts);
        $pivotPath = '';
        foreach ($dirnameParts as $k => $dirnamePart) {
//            if($dirnamePart === '.' || $dirnamePart === '..') continue;
//            $dirnamePart = trim($dirnamePart);
            if(trim($dirnamePart) === '') continue;
            $pivotPath = $pivotPath === '' ? $dirnamePart : $pivotPath.$this->pathSeparator.$dirnamePart;
            if(!$this->has($pivotPath)) {
                $this->createDir($pivotPath, $dirConfig);
            }
        }

        $data['path_id'] = $this->insertPath(
            'file',
            $data['path'],
            $data['visibility'],
            $data['mime_type'],
            $data['size'],
            $expiry,
            $meta
        );

        if ($tmpResource) {
            $this->cleanupTemp($tmpResource, $absPath);
        }

        if ($data['path_id'] === false) {
            return false;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->normalizeMetadata($data);
    }

    /**
     * @inheritdoc
     */
    public function update($path, $contents, Config $config)
    {
        $tmpFilename = $this->getTempFilename();
        $resource = $this->getTempResource($tmpFilename, $contents);

        return $this->doUpdate($path, $tmpFilename, $contents, $resource, $config);
    }

    /**
     * @inheritdoc
     */
    public function updateStream($path, $resource, Config $config)
    {
        $tmpFilename = $this->getTempFilename();
        $resource = $this->getTempResource($tmpFilename, $resource);

        return $this->doUpdate($path, $tmpFilename, '', $resource, $config);
    }

    /**
     * @param string $path
     * @param string $tmpFilename
     * @param string $contents
     * @param resource $resource
     * @param Config $config
     * @return array|false
     */
    protected function doUpdate($path, $tmpFilename, $contents, $resource, Config $config)
    {
        $data = $this->findPathData($path);
        if (!is_array($data) || $data['type'] != 'file') {
            return false;
        }

        $searchKeys       = ['size', 'mime_type'];
        $data['size']     = filesize($tmpFilename);
        $data['mime_type'] = Util::guessMimeType($tmpFilename, $contents);
        if ($config->has('expiry')) {
            $data['expiry'] = $config->get('expiry');
            $searchKeys[] = 'expiry';
        }
        if ($config->has('meta')) {
            $data['meta'] = json_encode($config->get('meta'));
            $searchKeys[] = 'meta';
        }

        $values = array_intersect_key($data, array_flip($searchKeys));
        $setValues = implode(', ', array_map(function ($field) {
            return "{$field} = :{$field}";
        }, array_keys($values)));

        $update = "UPDATE {$this->pathTable} SET {$setValues} WHERE path_id = :path_id";
        $stmt   = $this->db->prepare($update);
        $params = array_merge($values, ['path_id' => $data['path_id']]);
        if (!$stmt->execute($params)) {
            return false;
        }

        $this->cleanupTemp($resource, $tmpFilename);

        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->normalizeMetadata($data);
    }

    /**
     * @inheritdoc
     */
    public function rename($path, $newPath)
    {
        $data = $this->findPathData($path);
        if (!is_array($data)) {
            return false;
        }

        $update = "UPDATE {$this->pathTable} SET path = :newpath WHERE path_id = :path_id";
        $stmt   = $this->db->prepare($update);

        // rename the primary node first
        if (!$stmt->execute(['newpath' => $newPath, 'path_id' => $data['path_id']])) {
            return false;
        }

        // TODO REMOVE FOR LOOP

        // rename all children when it's directory
        if ($data['type'] == 'dir') {
            $pathLength = strlen($path);
            $listing    = $this->listContents($path, true);
            foreach ($listing as $item) {
                $newItemPath = $newPath . substr($item['path'], $pathLength);
                $stmt->execute(['newpath' => $newItemPath, 'path_id' => $item['path_id']]);
            }
        }

        $data['path'] = $newPath;
        return $this->normalizeMetadata($data);
    }

    /**
     * @inheritdoc
     */
    public function copy($path, $newPath)
    {
        $data = $this->findPathData($path);
        if (!is_array($data)) {
            return false;
        }

        $newData = $data;
        $newData['path'] = $newPath;
        unset($newData['path_id']);
        unset($newData['updated_at']);

        $newData['path_id'] = $this->insertPath(
            $data['type'],
            $newData['path'],
            $data['visibility'],
            $data['mime_type'],
            $data['size'],
            isset($data['expiry']) ? $data['expiry'] : null,
            isset($data['meta']) ? $data['meta'] : null
        );

        $newData['updated_at'] = date('Y-m-d H:i:s');
        return $this->normalizeMetadata($newData);
    }

    /**
     * @inheritdoc
     */
    public function delete($path)
    {
        $data = $this->findPathData($path);
        if (!is_array($data) || $data['type'] != 'file') {
            return false;
        }

        if (!$this->deletePath($data['path_id'])) {
            return false;
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function deleteDir($dirname)
    {
        $data = $this->findPathData($dirname);
        if (!is_array($data) || $data['type'] != 'dir') {
            return false;
        }

//        $listing = $this->listContents($dirname, true);

        $delete = "DELETE FROM {$this->pathTable} WHERE dirname LIKE :dirname OR dirname = :path OR path = :path";
        $stmt   = $this->db->prepare($delete);
        return (bool)$stmt->execute(['dirname' => $dirname.'/%', 'path' => $dirname]);

        // TODO NO FOR LOOP!!!
        // delete using LIKE %
//        foreach ($listing as $item) {
//            $this->deletePath($item['path_id']);
//        }
//
//        return $this->deletePath($data['path_id']);
    }

    /**
     * @inheritdoc
     */
    public function createDir($dirname, Config $config)
    {
        $data = [
            'type'      => 'dir',
            //            'dirname'        => $this->getParentDirectoryUri($path),
            'dirname'        => $this->sanitizeDirname($dirname),
            'path'      => $dirname,
//            'path_id'   => $pathId,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $additional = null;
        if ($config->has('meta')) {
            $additional = $config->get('meta');
        }
        if ($additional !== null) {
            $data['meta'] = json_encode($additional);
        }

        // check if dir already exists
        if($existingDir = $this->findPathData($dirname)) {
//            dd($existingDir);
            $data['path_id'] = $existingDir['path_id'];

            return $this->normalizeMetadata($data);
        }

        // create the parent directory if not exists
        $dirConfig = new Config();
        $dirnameParts = explode($this->pathSeparator, $this->sanitizeDirname($dirname));
//        dd($dirnameParts);
        $pivotPath = '';
        foreach ($dirnameParts as $k => $dirnamePart) {
//            if($dirnamePart === '.' || $dirnamePart === '..') continue;
//            $dirnamePart = trim($dirnamePart);
            if(trim($dirnamePart) === '') continue;
            $pivotPath = $pivotPath === '' ? $dirnamePart : $pivotPath.$this->pathSeparator.$dirnamePart;
            if(!$this->has($pivotPath)) {
//                $this->createDir($pivotPath, $dirConfig);
                $pathId = $this->insertPath('dir', $pivotPath, null, null, null, null, $additional);
            }
        }

        $pathId = $this->insertPath('dir', $dirname, null, null, null, null, $additional);
        if ($pathId === false) {
            return false;
        }

        $data['path_id'] = $pathId;

        return $this->normalizeMetadata($data);
    }

    /**
     * @inheritdoc
     */
    public function setVisibility($path, $visibility)
    {
        $update = "UPDATE {$this->pathTable} SET visibility = :visibility WHERE path = :path";
        $data = ['visibility' => $visibility, 'path' => $path];
        $stmt = $this->db->prepare($update);
        if (!$stmt->execute($data)) {
            return false;
        }

        return $this->getMetadata($path);
    }

    /**
     * @inheritdoc
     */
    public function has($path)
    {
        $select = "SELECT 1 FROM {$this->pathTable} WHERE path = :path ORDER BY path_id DESC LIMIT 1";
        $stmt   = $this->db->prepare($select);
        if (!$stmt->execute(['path' => $path])) {
            return false;
        }

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @inheritdoc
     */
    public function read($path)
    {
        $data = $this->findPathData($path);
        if (!is_array($data)) {
            return false;
        }

//        $resource = $this->getChunkResource($data['path_id'], $data['is_compressed']);

        $metadata = $this->normalizeMetadata($data);
        $metadata['contents'] = '';
//        $metadata['contents'] = stream_get_contents($resource);

        return $metadata;
    }

    /**
     * @inheritdoc
     */
    public function readStream($path)
    {
        $data = $this->findPathData($path);
        if (!is_array($data)) {
            return false;
        }

        $metadata = $this->normalizeMetadata($data);
        $metadata['stream'] = '';
//        $metadata['stream'] = $this->getChunkResource($metadata['path_id'], (bool)$data['is_compressed']);
        return $metadata;
    }

    /**
     * @inheritdoc
     */
    public function listContents($directory = '', $recursive = false)
    {
        $params = [];
        $select = "SELECT * FROM {$this->pathTable} WHERE 1";
        $limit = "";

//        if (!empty($directory)) {
//            $select .= " WHERE path LIKE :prefix OR path = :path";
//            $params = ['prefix' => $directory . '/%', 'path' => $directory];

            $select .= " AND dirname = :dirname";
            $params = ['dirname' => $directory];
//        }

        if (!empty($this->search)) {
            $select .= " AND filename LIKE :search";
            $params = array_merge($params, ['search' => '%' . $this->search . '%']);
        }

        $pagination_statement = $this->db->prepare($select);
        $pagination_statement->execute($params);
        $row_count = $pagination_statement->rowCount();
//        dd($row_count);
        $this->setTotalRowCount($row_count);

        if (!empty($this->page) && $this->paginationLimit > 0) {
            $start = ($this->page - 1) * $this->paginationLimit;
            $limit = " LIMIT $start, $this->paginationLimit";
//            $params = array_merge($params, ['search' => '%' . $this->search . '%']);
//            $total_pages = ceil($row_count/$this->paginationLimit);
        }

        $order = " ORDER BY type asc";

//        dump($params);
//        dd($select.$limit);
//        $pagination_statement = $this->db->prepare($select);
//        $row_count = $pagination_statement->rowCount();

        $stmt = $this->db->prepare($select.$order.$limit);
        if (!$stmt->execute($params)) {
            return [];
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $rows = array_map([$this, 'normalizeMetadata'], $rows);
//        dd($rows);
        if ($recursive) {
            $rows = Util::emulateDirectories($rows);
        }
//        dd($rows);
        return $rows;
    }

    /**
     * @inheritdoc
     */
    public function getMetadata($path)
    {
        return $this->normalizeMetadata($this->findPathData($path));
    }

    /**
     * @inheritdoc
     */
    public function getSize($path)
    {
        return $this->getFileMetadataValue($path, 'size');
    }

    /**
     * @inheritdoc
     */
    public function getMimetype($path)
    {
        return $this->getFileMetadataValue($path, 'mime_type');
    }

    /**
     * @inheritdoc
     */
    public function getTimestamp($path)
    {
        return $this->getFileMetadataValue($path, 'timestamp');
    }

    /**
     * @inheritdoc
     */
    public function getVisibility($path)
    {
        return $this->getFileMetadataValue($path, 'visibility');
    }

    /**
     * @param string $path
     * @return array|false
     */
    public function findPathData($path)
    {
        $select = "SELECT * FROM {$this->pathTable} WHERE path = :path ORDER BY path_id DESC LIMIT 1";
        $stmt   = $this->db->prepare($select);
        if (!$stmt->execute(['path' => $path])) {
            return false;
        }

        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($this->hasExpired($data)) {
            return false;
        }

        return $data;
    }

    /**
     * @param array $data
     * @return array|bool
     */
    public function normalizeMetadata($data)
    {
        if (!is_array($data) || empty($data) || $this->hasExpired($data)) {
            return false;
        }

        $pathInfo = pathinfo($data['path']);

        $data['timestamp'] = strtotime($data['updated_at']);
        $data['filename'] = $pathInfo['filename'];
        $data['extension'] = isset($pathInfo['extension']) ? $pathInfo['extension'] : '';
        $data['basename'] = $pathInfo['basename'];

//        'dirname' => $pathInfo['dirname'] === '.' || $pathInfo['dirname'] === '..' ? '' : $pathInfo['dirname'],
//            'basename' => $pathInfo['basename'],
//            'extension' => $pathInfo['extension'] ?? '',
//            'filename' => $pathInfo['filename'],

//        $meta = [
//            'path_id'   => $data['path_id'],
//            'type'      => $data['type'],
//            'path'      => $data['path'],
//            'timestamp' => strtotime($data['updated_at'])
//        ];
//        if ($data['type'] == 'file') {
//            $meta['mime_type']   = $data['mime_type'];
//            $meta['size']       = $data['size'];
//            $meta['visibility'] = $data['visibility'];
//            if (isset($data['expiry'])) {
//                $meta['expiry'] = $data['expiry'];
//            }
//        }
//
        if (isset($data['meta'])) {
            $data['meta'] = json_decode($data['meta'], true);
        }

        return $data;
    }

    /**
     * @param array $data
     * @return bool
     */
    protected function hasExpired($data)
    {
        if (isset($data['expiry']) &&
            !empty($data['expiry']) &&
            strtotime($data['expiry']) !== false &&
            strtotime($data['expiry']) <= time()
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param string $path
     * @param string $property
     * @return array|false
     */
    protected function getFileMetadataValue($path, $property)
    {
        $meta = $this->getMetadata($path);
        if ($meta['type'] != 'file' || !isset($meta[$property])) {
            return false;
        }
        return [$property => $meta[$property]];
    }

    /**
     * @param string $type 'file' or 'dir'
     * @param string $path
     * @param string $visibility 'public' or 'private'
     * @param string $mimeType
     * @param int $size
     * @param bool $enableCompression
     * @param string $expiry
     * @param array $additional
     * @return bool|string
     */
    protected function insertPath(
        $type,
        $path,
        $visibility = null,
        $mimeType = null,
        $size = null,
        $expiry = null,
        $additional = null
    ) {
//        $dirname = $this->getCleanedDirname($path);
//        $dirname = $dirname === '.' || $dirname === '..' ? '' : $dirname;

        $data = [
            'type'          => $type == 'dir' ? 'dir' : 'file',
//            'dirname'        => $this->getParentDirectoryUri($path),
//            'dirname'        => $dirname,
            'dirname'        => $this->sanitizeDirname($path),
            'filename'        => basename($path),
            'path'          => $path,
            'visibility'    => $visibility,
            'mime_type'      => $mimeType,
            'size'          => $size,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

//        dump($data);
//        dump($type);
//        dd($this->config);

        // todo refactor
        if($this->config->get('createThumb') && $type == 'file') {
            $absPath = implode($this->pathSeparator, array_filter([$this->config->get('fileManagerRoot', ''), $path], 'strlen'));
            $absPathInfo = pathinfo($absPath);
            $pathInfo = pathinfo($path);
            $extension = isset($absPathInfo['extension']) ? '.' . $absPathInfo['extension'] : '';

            $thumbName = $pathInfo['filename'] . '-' . $this->config->get('thumbWidth', 200) . 'x' . $this->config->get('thumbHeight', 200) . $extension;
//            $thumbPath = implode('/', array_filter([$this->config->get('fileManagerRoot', ''), $pathInfo['dirname'], $thumbName], 'strlen'));
//            $thumbPath = $absPathInfo['filename'] . '-' . $this->config->get('thumbWidth', 200) . 'x' . $this->config->get('thumbHeight', 200) . $extension;
            $thumbPath = implode($this->pathSeparator, array_filter([$this->sanitizeDirname($path), $thumbName], 'strlen'));
            $thumbAbsPath = implode($this->pathSeparator, array_filter([$absPathInfo['dirname'], $thumbName], 'strlen'));

//            dump($pathInfo['dirname']);
//            dump($this->sanitizeDirname($path));
//            dd($thumbPath);
//            dd($pathInfo);
//            dd($thumbAbsPath);
            if(file_exists($thumbAbsPath)) {
                $data['thumb_path'] = $thumbPath;
            }
        }

        if ($expiry !== null) {
            $data['expiry'] = $expiry;
        }

        if ($additional !== null) {
            $data['meta'] = json_encode($additional);
        }

        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $values = implode(', ', array_map(function ($field) {
            return ':' . $field;
        }, $keys));


        $insert = "INSERT INTO {$this->pathTable} ({$fields}) VALUES ({$values})";
        $stmt   = $this->db->prepare($insert);

        if (!$stmt->execute($data)) {
//            echo 'dfwe';
//            print_r($data);
//            exit;

//            print_r($data);
//            print_r($this->db->errorInfo());
//            exit;
            return false;
        }

        return $this->db->lastInsertId();
    }

    /**
     * @param string|null $now Timestamp in expected format for query
     * @return int Number of expired files deleted
     */
    public function deleteExpired($now = null)
    {
        if ($now === null) {
            $now = date('Y-m-d H:i:s');
        }

        $select = "SELECT path_id FROM {$this->pathTable} WHERE expiry <= :now";
        $stmt = $this->db->prepare($select);
        $stmt->execute(['now' => $now]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $this->deletePath($row['path_id']);
        }

        return $stmt->rowCount();
    }

    /**
     * @param int $pathId
     * @return bool
     */
    protected function deletePath($pathId)
    {
        $delete = "DELETE FROM {$this->pathTable} WHERE path_id = :path_id";
        $stmt   = $this->db->prepare($delete);
        return (bool)$stmt->execute(['path_id' => (int)$pathId]);
    }

    /**
     * @return string
     */
    protected function getTempFilename()
    {
        $tempDir = $this->config->get('temp_dir');
        return tempnam($tempDir, "flysystempdo");
    }

    /**
     * @param string $tmpFilename
     * @param string|resource $content
     * @return resource
     */
    protected function getTempResource($tmpFilename, $content)
    {
        $resource = fopen($tmpFilename, 'w+b');
        if (!is_resource($content)) {
            fwrite($resource, (string)$content);
        } else {
            while (!feof($content)) {
                fwrite($resource, stream_get_contents($content, 1024), 1024);
            }
        }
        rewind($resource);
        return $resource;
    }

    /**
     * @param resource $resource
     * @param string $tmpFilename
     */
    protected function cleanupTemp($resource, $tmpFilename)
    {
        if (is_resource($resource)) {
            fclose($resource);
        }
        if (is_file($tmpFilename)) {
            unlink($tmpFilename);
        }
    }

    /**
     * Ensure the root directory exists.
     *
     * @param string $root root directory path
     *
     * @return void
     *
     * @throws Exception in case the root directory can not be created
     */
    protected function ensureDirectory($root)
    {
        if ( ! is_dir($root)) {
            $umask = umask(0);

            if ( ! @mkdir($root, 0755, true)) {
                $mkdirError = error_get_last();
            }

            umask($umask);
            clearstatcache(false, $root);

            if ( ! is_dir($root)) {
                $errorMessage = isset($mkdirError['message']) ? $mkdirError['message'] : '';
                throw new Exception(sprintf('Impossible to create the root directory "%s". %s', $root, $errorMessage));
            }
        }
    }

    /**
     * REMOVE ///////
     * @param string $path
     * @return
     */
    protected function getParentDirectoryUri($path)
    {
        $parts = explode($this->pathSeparator, $path);
        $removed = array_pop($parts);

//        dump($this->pathSeparator);
//        dd($parts);
        $parentDirectoryPath = implode($this->pathSeparator, $parts);
        return $parentDirectoryPath;
    }

    function sanitizeDirname($path)
    {
        $dirname = dirname($path);
        return $dirname === '.' || $dirname === '..' ? '' : $dirname;
    }

    function getAbsolutePath($path)
    {
        return implode($this->pathSeparator, array_filter([$this->root, $path], 'strlen'));
    }

}
