<?php

declare(strict_types=1);

namespace App\Agent;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use App\Job\StepFailed;
use App\Providers\MissingKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One call to the Messages API with the official SDK. The agent always thinks (adaptive; on
 * Claude Opus 5.5 it cannot be turned off) at high effort, and a refusal for policy reasons is
 * retried server-side on the model the API chooses («fallbacks: default»).
 *
 * The SDK retries what surely did not reach the model (429, 5xx, connection) by itself.
 */
final class AnthropicClient
{
    public const MAX_TOKENS = 32000;
    /** Dollars per million tokens: input, output, cache read, cache write (5 min, 1,25 × input). */
    private const PRICES = [
        'claude-opus-5-5' => [4.0, 20.0, 0.20, 5.0],
        'claude-sonnet-5-5' => [2.0, 10.0, 0.20, 2.5],
    ];

    private ?Client $client = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')]
        #[\SensitiveParameter]
        private readonly string $apiKey,
        #[Autowire('%env(ANTHROPIC_MODEL)%')]
        private readonly string $model,
    ) {
    }

    public function model(): string
    {
        return '' === $this->model ? 'claude-opus-5-5' : $this->model;
    }

    /**
     * @param list<array<string, mixed>> $system
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     */
    public function message(array $system, array $messages, array $tools): BetaMessage
    {
        MissingKey::check($this->apiKey, 'ANTHROPIC_API_KEY', 'Anthropic');
        try {
            return $this->client()->beta->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: $messages,
                model: $this->model(),
                fallbacks: 'default',
                outputConfig: ['effort' => 'high'],
                system: $system,
                thinking: ['type' => 'adaptive'],
                // Forcing the tool (tool_choice «tool») is refused by Claude Opus 5.5: the prompt
                // asks for submit_plan and the agent gets a reminder when it answers in text.
                toolChoice: ['type' => 'auto'],
                tools: $tools,
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new StepFailed('La clave de Anthropic de .env.local no es válida o no tiene permiso.');
        } catch (APIStatusException $e) {
            throw new StepFailed(\sprintf('Anthropic ha rechazado la petición (%d): %s', $e->status ?? 0, mb_substr(str_replace($this->apiKey, '[clave]', $e->getMessage()), 0, 300)));
        } catch (APIConnectionException) {
            throw new StepFailed('No se ha podido conectar con Anthropic. Reintenta en un rato.');
        }
    }

    /**
     * Dollars of the tokens a response reports.
     *
     * @param array{input: int, output: int, cacheRead: int, cacheWrite: int} $usage
     */
    public function usd(array $usage): float
    {
        [$in, $out, $read, $write] = self::PRICES[$this->model()] ?? self::PRICES['claude-opus-5-5'];

        return ($usage['input'] * $in + $usage['output'] * $out + $usage['cacheRead'] * $read + $usage['cacheWrite'] * $write) / 1_000_000;
    }

    private function client(): Client
    {
        // The agent thinks before it answers: one call can take minutes.
        return $this->client ??= new Client(
            apiKey: $this->apiKey,
            requestOptions: ['transporter' => new Psr18Client($this->http->withOptions(['timeout' => 600, 'max_duration' => 900]))],
        );
    }
}
