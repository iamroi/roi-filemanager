<?php

namespace Iamroi\RoiFileManager\Providers;

use League\Event\ListenerAcceptorInterface;
use League\Event\ListenerProviderInterface;
use Iamroi\RoiFileManager\Listeners\FileWasUploaded;
use Iamroi\RoiFileManager\Listeners\PhysicalFileWasUploaded;
use Iamroi\RoiFileManager\Listeners\ItemWasDeleted;

class ListenerProvider implements ListenerProviderInterface
{
    public function provideListeners(ListenerAcceptorInterface $acceptor)
    {
        $acceptor->addListener('roifm.file.physical.uploaded', new PhysicalFileWasUploaded);

        $acceptor->addListener('roifm.file.uploaded', new FileWasUploaded);

        $acceptor->addListener('roifm.item.deleted', new ItemWasDeleted);
    }
}

//$emitter->useListenerProvider(new MyProvider);
