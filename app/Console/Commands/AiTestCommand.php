<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

class AiTestCommand extends Command
{
    protected $signature = 'ai:test {prompt=Reply with a short one-sentence greeting.}';

    protected $description = 'Send a test prompt to Ollama via Prism to prove the Laravel -> Prism -> tunnel -> Ollama chain works';

    public function handle(): int
    {
        $model = config('prism.providers.ollama.model');
        $url = config('prism.providers.ollama.url');
        $prompt = $this->argument('prompt');

        $this->info("Sending prompt to Ollama at {$url} using model {$model}...");

        $response = Prism::text()
            ->using(Provider::Ollama, $model)
            ->withPrompt($prompt)
            ->withClientOptions(['timeout' => 60])
            ->asText();

        $this->newLine();
        $this->line($response->text);

        return self::SUCCESS;
    }
}
