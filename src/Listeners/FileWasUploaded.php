<?php

namespace Iamroi\RoiFileManager\Listeners;

use League\Event\ListenerInterface;
use League\Event\EventInterface;
use Iamroi\RoiFileManager\RoiFileManager;
use League\Flysystem\Config;
use Iamroi\RoiFileManager\RoiImage;

class FileWasUploaded implements ListenerInterface
{
    public $config;

    public $file;

    public function isListener($listener)
    {
        return $listener === $this;
    }

    public function handle(EventInterface $event, $file = null, $config = null)
    {
        $this->config = $config;

        $this->file = $file;
    }

}
