<?php

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;

class InMemoryChatHistory extends AbstractChatHistory
{
    public function __construct(int $contextWindow = 50000)
    {
        parent::__construct($contextWindow);
    }

    public function removeOldestMessage(): ChatHistoryInterface
    {
        return $this;
    }

    public function clear(): ChatHistoryInterface
    {
        $this->history = [];
        return $this;
    }

    protected function storeMessage(Message $message): ChatHistoryInterface
    {
        return $this;
    }
}
