<?php

namespace App\MessageHandler;

use App\Message\GptMessageSync;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class GptMessageHandlerSync implements MessageHandlerInterface
{

    private BaseGptMessageHandler $baseGptMessageHandler;

    public function __construct(BaseGptMessageHandler $baseGptMessageHandler)
    {
        $this->baseGptMessageHandler = $baseGptMessageHandler;
    }

    public function __invoke(GptMessageSync $message)
    {
        $this->baseGptMessageHandler->invoke($message);
    }
}
