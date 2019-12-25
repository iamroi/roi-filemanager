<?php

namespace Iamroi\RoiFileManager\Listeners;

use League\Event\ListenerInterface;
use League\Event\EventInterface;
use Iamroi\RoiFileManager\RoiFileManager;
use League\Flysystem\Config;
use Iamroi\RoiFileManager\RoiImage;
use Iamroi\RoiFileManager\Helpers as FileManagerHelpers;

class PhysicalFileWasUploaded implements ListenerInterface
{
    public $config;

    public $filePath;

    public $fileAbsPath;

    public function isListener($listener)
    {
        return $listener === $this;
    }

    public function handle(EventInterface $event, $filePath = null, $config = null)
    {
        $this->config = $config;

        $this->filePath = $filePath;

        $this->fileAbsPath = implode('/', array_filter([$this->config->get('fileManagerRoot', ''), $filePath], 'strlen'));

        $this->createThumbnail();
    }

    function createThumbnail()
    {
//        return;

//        dd($this->file);

        $roiImage = new RoiImage($this->config);

//        $originalFilePath = implode('/', array_filter([$this->config->get('fileManagerRoot', ''), $this->fileAbsPath], 'strlen'));

        $thumbWidth = $this->config->get('thumbWidth', 200);
        $thumbHeight = $this->config->get('thumbHeight', 200);

        $pathInfo = pathinfo($this->filePath);

//        dd($pathInfo);

        $thumbName = $roiImage->getCustomSizeImageName($this->filePath, $thumbWidth, $thumbHeight);

        $thumbPath = implode('/', array_filter([$pathInfo['dirname'], $thumbName], 'strlen'));

//        dd($thumbPath);

        return $roiImage
                    ->setFile($this->fileAbsPath)
                    ->createThumbnail($thumbPath, $thumbWidth, $thumbHeight);
    }
}
