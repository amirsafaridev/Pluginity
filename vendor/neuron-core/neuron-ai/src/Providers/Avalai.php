<?php

namespace NeuronAI\Providers;

use NeuronAI\Providers\OpenAI\OpenAI;

class Avalai extends OpenAI
{
    protected string $baseUri = "https://api.avalai.ir/v1";
}

