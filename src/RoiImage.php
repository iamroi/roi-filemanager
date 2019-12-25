<?php

namespace Iamroi\RoiFileManager;

//use Rakit\Validation\Rules\UploadedFile;
//use Iamroi\Flysystem\Pdo\PdoPathAdapter as PdoPathAdapter;
//use League\Flysystem\Adapter\Local as LocalAdapter;
//use League\Flysystem\FileNotFoundException;
//use League\Flysystem\Filesystem as Filesystem;
//use League\Flysystem\Replicate\ReplicateAdapter as ReplicateAdapter;
use League\Flysystem\Util;
use Iamroi\RoiFileManager\Helpers as FileManagerHelpers;
//use Iamroi\RoiFileManager\Exception as Ex;
use League\Flysystem\Filesystem as Filesystem;
use League\Flysystem\Config;
use Intervention\Image\ImageManager;
use PHPUnit\Exception;

class RoiImage
{
    public $config;

    public $filePath;

    public $fileAbsPath;

    public $fileName;

    public $mimeType;

//    public $maxWidth = 1200;
//
//    public $maxHeight = 628;

    private $imageManager;

    /**
     * FileManager constructor.
     * @param $config
     */
    public function __construct($config = null)
    {
        $config = $config === null ? new Config : $config;

        $this->config = $config;

//        $this->maxWidth = $config['maxWidth'] ?? $this->maxWidth;
//        $this->maxHeight = $config['maxHeight'] ?? $this->maxHeight;

        $this->imageManager = new ImageManager(array('driver' => $this->config->get('imageDriver', 'gd'))); //array('driver' => 'imagick')
    }


    public function setFile($absPath)
    {
        $pathInfo = pathinfo($absPath);

//        $this->fileAbsPath = implode('/', array_filter([$this->config->get('fileManagerRoot', ''), $path], 'strlen'));
        $this->fileAbsPath = $absPath; // can't append fmroot to this as it can be from other locations too like tmp folder

        $this->filePath = FileManagerHelpers::toRelativePath($absPath, $this->config->get('fileManagerRoot', '')); //$path;

//        dd($this->filePath);

        $this->fileName = $pathInfo['basename'];

        $this->mimeType = Util::guessMimeType($this->fileName, file_get_contents($absPath));

        return $this;
    }

    public function setFileName($fileName)
    {
        $this->fileName = $fileName;

        return $this;
    }

//    function resize($path, $width, $height)
//    {
//
//        $image = $this->imageManager->make($path)->resize($width, $height);
//
//        return $image;
//    }

    public function constrainImage($destination = '') //$path, $fileName = '',
    {
        $newWidth = $this->config->get('constraintImageWidth');
        $newHeight = $this->config->get('constraintImageHeight');
        if(!$newWidth && !$newHeight) {
            return false;
        }

//        if(!isset($this->file['mimetype'])) {
//            return false;
//        }

//        $mimeType = Util::guessMimeType($fileName, file_get_contents($this->fileAbsPath));

        if(!$this->isSupportedImageType() ) {
            return false;
        }

//        dd($mimeType);

        $destination = $destination == '' ? $this->fileAbsPath : $destination;

//        $absFilePath = implode('/', array_filter([$this->config->get('fileManagerRoot'), $this->file['path']], 'strlen'));

        $image = $this->imageManager->make($this->fileAbsPath);
        $image->height() > $image->width() ? $newWidth = null : $newHeight = null;

        $image->resize($newWidth, $newHeight, function ($constraint) {
            $constraint->aspectRatio();
        })->save($destination);
    }

    public function isSupportedImageType()
    {
        if(!$this->mimeType) return false;

//        $mimeType = Util::guessMimeType($fileName, file_get_contents($path));
//        $mimeType = "image/jpeg";

        $supportedImageMimeTypes = ['image/jpg','image/jpe','image/jpeg','image/png','image/bmp','image/gif', 'image/tiff', 'image/webp']; //,'image/jfif' //,'image/dib'
        if(in_array($this->mimeType, $supportedImageMimeTypes)) {
            return true;
        }

        return false;
    }

    public function hasThumb()
    {
//        if (!$this->isImage()) {
//            return false;
//        }
//        if (!$this->lfm->thumb()->exists()) {
//            return false;
//        }
//        return true;
    }

    public function createThumbnail($thumbPath, $width = 200, $height = 200)
    {
        if(!$this->filePath) return false;

        if (!$this->canCreateThumb()) {
            return false;
        }

//        $image->height() > $image->width() ? $newWidth = null : $newHeight = null;
//        $image = $this->imageManager->make($this->fileAbsPath);

        // disable asserts - overwrite if previous thumb exists for some reason
        $filesystemPhysical = new Filesystem($this->config->get('physicalAdapter'), ['disable_asserts' => true]);

//        dump($this->filePath);
//        dd($filesystemPhysical->get($this->filePath));

//        dd($thumbPath);
//        $thumbnail = $this->imageManager->make($this->fileAbsPath)
        $thumbnail = $this->imageManager
                            ->make($filesystemPhysical->readStream($this->filePath))
                            ->resize($width, $height, function ($constraint) {
                                $constraint->aspectRatio();
                            });

        try {
            $filesystemPhysical->write($thumbPath, $thumbnail->stream());
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    public function canCreateThumb()
    {
        if (!$this->config->get('createThumb', true)) {
            return false;
        }

        if(!$this->isSupportedImageType() ) {
            return false;
        }

        if (in_array($this->mimeType, ['image/gif', 'image/svg+xml'])) {
            return false;
        }

        return true;
    }

    public static function getCustomSizeImageName($fileName, $thumbWidth, $thumbHeight)
    {
        $pathInfo = pathinfo($fileName);

        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';

        $thumbName = $pathInfo['filename'] . '-' . $thumbWidth . 'x' . $thumbHeight . $extension;
//        $thumbPath = implode('/', array_filter([$pathInfo['dirname'], $thumbName], 'strlen'));

        return $thumbName;
    }

    // todo remove
//    public static function setConfig($config)
//    {
//        return self::$config = $config;
//    }

    // todo remove
//    function normalizeSize($path, $destination = '')
//    {
//        $destination = $destination == '' ? $path : $destination;
//
//        $ratio = 1.0;
////        $image = request('img');
////        $originalImage = Image::make($this->lfm->setName($image)->path('absolute'));
//        $originalImage = $this->imageManager->make($path);
//        $originalWidth = $originalImage->width();
//        $originalHeight = $originalImage->height();
//        $scaled = false;
//
//        $newWidth = $originalWidth;
//        $newHeight = $originalHeight;
//        if ($originalWidth > $this->maxWidth) {
//            $ratio = $this->maxWidth / $originalWidth;
//            $newWidth = $originalWidth * $ratio;
//            $newHeight = $originalHeight * $ratio;
//            $scaled = true;
//        } /*else {
//            $newWidth = $originalWidth;
//            $newHeight = $originalHeight;
//        }*/
//
//        if ($newHeight > $this->maxHeight) {
//            $ratio = $this->maxHeight / $originalHeight;
//            $newWidth = $originalWidth * $ratio;
//            $newHeight = $originalHeight * $ratio;
//            $scaled = true;
//        }
//
//
////        Image::make($image_path)->resize(request('dataWidth'), request('dataHeight'))->save();
//
////        $this->resize($path, $newWidth, null);
//
//        $image = $this->imageManager->make($path);
//        $image->height() > $image->width() ? $newWidth = null : $newHeight = null;
//
//        $image->resize($newWidth, $newHeight, function ($constraint) {
//            $constraint->aspectRatio();
//        })->save($destination);
//
////        print_r($newImage);
////        exit;
//    }

}
