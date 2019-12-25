<?php

namespace Iamroi\RoiFileManager\Listeners;

use League\Event\ListenerInterface;
use League\Event\EventInterface;
use Iamroi\RoiFileManager\RoiFileManager;
use League\Flysystem\Config;
use Iamroi\RoiFileManager\RoiImage;

class ItemWasDeleted implements ListenerInterface
{
    public $config;

    public $fileInfo;

    public function isListener($listener)
    {
        return $listener === $this;
    }

    public function handle(EventInterface $event, $fileInfo = null, $config = null)
    {
        $this->config = $config;

        $this->fileInfo = $fileInfo;

//        $this->removeThumnail();
    }

//    public function removeThumbnail()
//    {
//        if(!$this->fileInfo || !isset($this->fileInfo['type'])) return;
//
//        if($this->fileInfo['file']) {
//
//        }
//    }
}
