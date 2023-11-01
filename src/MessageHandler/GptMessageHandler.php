<?php

namespace App\MessageHandler;

use App\Message\GptMessage;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class GptMessageHandler implements MessageHandlerInterface
{
    private BaseGptMessageHandler $baseGptMessageHandler;

    public function __construct(BaseGptMessageHandler $baseGptMessageHandler)
    {
        $this->baseGptMessageHandler = $baseGptMessageHandler;
    }

    public function __invoke(GptMessage $message)
    {
        $this->baseGptMessageHandler->invoke($message);
    }
}
