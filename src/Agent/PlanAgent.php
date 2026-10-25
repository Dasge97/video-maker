<?php

declare(strict_types=1);

namespace App\Agent;

use App\Job\Job;
use App\Job\StepFailed;
use App\Reference\Images;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The only agent: one conversation with Claude gets the frames of the reference, its
 * transcription, the photo, the logo and what is advertised, and gives back the whole plan
 * with the submit_plan tool. What does not meet the schema or PlanChecks goes back as a tool
 * error in the same conversation, at most MAX_ROUNDS times.
 */
final class PlanAgent
{
    public const TOOL = 'submit_plan';
    public const MAX_ROUNDS = 3;
    /** Errors shown to the agent at once. */
    private const MAX_ERROR_LINES = 15;
    private const PERSON_SIDE = 1024;
    private const LOGO_SIDE = 512;
    private const SUBMIT_NOTE = "\n\n# Cómo entregas\n\nCuando tengas el plan, llama a la herramienta `".self::TOOL.'` una sola vez con el plan completo. Las respuestas en texto no se leen.';

    public function __construct(
        private readonly AnthropicClient $client,
        private readonly SchemaValidator $schema,
        private readonly string $promptsDir,
        #[Autowire('%env(int:MAX_PAID_CLIPS)%')]
        private readonly int $maxPaidClips = PlanChecks::DEFAULT_MAX_PAID_CLIPS,
    ) {
    }

    /**
     * What PlanChecks needs to know of the video.
     *
     * @return array<string, mixed>
     */
    public function checkData(Job $job): array
    {
        $data = self::data($job);

        return [
            'mode' => $data['mode'], 'durationSeconds' => $data['durationSeconds'], 'product' => $data['product'], 'note' => $data['note'],
            'brand' => $data['brand'], 'hasLogo' => $data['hasLogo'], 'frames' => (int) $job->data['reference']['frames'],
            'maxPaidClips' => $this->maxPaidClips,
        ];
    }

    /**
     * A plan for the video, or a new version of $current with the change the person asked.
     *
     * @param array<string, mixed>|null $current
     *
     * @return array{plan: array<string, mixed>, usd: float, rounds: int}
     */
    public function plan(Job $job, ?array $current = null, ?string $change = null): array
    {
        $data = self::data($job);
        $content = $this->firstMessage($job, $data);
        if (null !== $current && null !== $change) {
            $content[] = ['type' => 'text', 'text' => "Plan actual:\n".json_encode($current, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)];
            $content[] = ['type' => 'text', 'text' => \sprintf('Petición de cambio de la persona: «%s». Cambia solo lo que pide y entrega el plan entero.', $change)];
        }
        $system = [['type' => 'text', 'text' => (string) file_get_contents($this->promptsDir.'/agente.md').self::SUBMIT_NOTE, 'cacheControl' => ['type' => 'ephemeral']]];
        $toolSchema = $this->schema->schema();
        unset($toolSchema['$schema'], $toolSchema['title']);
        $tools = [['name' => self::TOOL, 'description' => 'Entrega el plan completo del anuncio. Llámala una vez, cuando el plan esté terminado.', 'inputSchema' => $toolSchema]];
        $messages = [['role' => 'user', 'content' => $content]];
        $usage = ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0];
        $checks = $this->checkData($job);

        $errors = [];
        try {
            for ($round = 0; $round <= self::MAX_ROUNDS; ++$round) {
                $response = $this->client->message($system, self::withCacheBreakpoints($messages), $tools);
                $usage['input'] += (int) $response->usage->inputTokens;
                $usage['output'] += (int) $response->usage->outputTokens;
                $usage['cacheRead'] += (int) ($response->usage->cacheReadInputTokens ?? 0);
                $usage['cacheWrite'] += (int) ($response->usage->cacheCreationInputTokens ?? 0);
                $job->log(\sprintf('Agente, vuelta %d: %s, %d fichas de entrada, %d de salida', $round + 1, $response->stopReason, $response->usage->inputTokens, $response->usage->outputTokens));
                if ('refusal' === $response->stopReason) {
                    throw new StepFailed('El agente ha rechazado la petición por sus normas de uso. Revisa el texto de qué se anuncia y la nota.');
                }
                if ('max_tokens' === $response->stopReason) {
                    throw new StepFailed('La respuesta del agente se ha cortado por larga. Reintenta.');
                }
                $messages[] = ['role' => 'assistant', 'content' => $response->content];

                $results = [];
                foreach ($response->content as $block) {
                    if ('tool_use' !== $block->type) {
                        continue;
                    }
                    if (self::TOOL !== $block->name) {
                        $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => 'Herramienta desconocida.', 'isError' => true];
                        continue;
                    }
                    $plan = json_decode(json_encode($block->input, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
                    $errors = $this->schema->errors($plan);
                    if ([] === $errors) {
                        $errors = PlanChecks::errors($plan, $checks);
                    }
                    if ([] === $errors) {
                        return ['plan' => $plan, 'usd' => $this->client->usd($usage), 'rounds' => $round + 1];
                    }
                    $job->log('Agente: plan con errores: '.implode(' | ', \array_slice($errors, 0, 5)));
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => "El plan no cumple estas comprobaciones. Corrige solo esto y vuelve a llamar a `".self::TOOL."` con el plan entero:\n".self::lines($errors), 'isError' => true];
                }
                if ([] === $results) {
                    $results[] = ['type' => 'text', 'text' => 'Llama ahora a `'.self::TOOL.'` con el plan completo.'];
                }
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } finally {
            $this->saveConversation($job, $messages, $usage);
            $job->addCost(null === $change ? 'Agente (plan)' : 'Agente (cambio)', $this->client->usd($usage));
        }

        throw new StepFailed("El agente no ha entregado un plan válido tras ".self::MAX_ROUNDS." vueltas de corrección. Errores que quedaban:\n".self::lines($errors));
    }

    /**
     * The data of the video as the agent reads them (docs/04-agente.md).
     *
     * @return array<string, mixed>
     */
    public static function data(Job $job): array
    {
        $input = $job->input();
        $transcript = [];
        $file = $job->path('reference/transcript.json');
        if (is_file($file)) {
            $result = json_decode((string) file_get_contents($file), true);
            if (true === ($result['available'] ?? false)) {
                $transcript = array_map(static fn (array $s) => ['start' => round((float) $s['start'], 1), 'end' => round((float) $s['end'], 1), 'text' => trim((string) $s['text'])], $result['segments']);
            }
        }

        return [
            'mode' => $input['mode'],
            'durationSeconds' => (int) $input['durationSeconds'],
            'product' => (string) $input['product'],
            'note' => (string) ($input['note'] ?? ''),
            'brand' => [
                'name' => (string) ($input['brand']['name'] ?? ''),
                'colors' => (string) ($input['brand']['colors'] ?? ''),
                'forbiddenWords' => array_values($input['brand']['forbiddenWords'] ?? []),
            ],
            'hasLogo' => true === ($input['hasLogo'] ?? false),
            'reference' => [
                'durationSeconds' => (float) $job->data['reference']['durationSeconds'],
                'transcript' => $transcript,
            ],
        ];
    }

    /**
     * The frames, each after the line that says which one it is; the photo; the logo; the data.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function firstMessage(Job $job, array $data): array
    {
        $blocks = [];
        $frames = json_decode((string) file_get_contents($job->path('reference/frames.json')), true, 512, \JSON_THROW_ON_ERROR);
        foreach ($frames as $frame) {
            $blocks[] = ['type' => 'text', 'text' => \sprintf('Fotograma %d · segundo %s · plano de %s a %s s', $frame['index'], self::n($frame['atSeconds']), self::n($frame['shotStart']), self::n($frame['shotEnd']))];
            $blocks[] = self::image(base64_encode((string) file_get_contents($job->path('reference/'.$frame['path']))));
        }
        $blocks[] = ['type' => 'text', 'text' => 'Foto de la persona'];
        $blocks[] = self::image(Images::jpegBase64($job->path('input/person.jpg'), self::PERSON_SIDE));
        if ($data['hasLogo']) {
            $blocks[] = ['type' => 'text', 'text' => 'Logo de la marca'];
            $blocks[] = self::image(Images::jpegBase64($job->path('input/logo.png'), self::LOGO_SIDE));
        }
        $blocks[] = ['type' => 'text', 'text' => "Datos del vídeo:\n".json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)];

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private static function image(string $base64): array
    {
        return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => $base64]];
    }

    /**
     * The conversation with a breakpoint on the first message (the images and the data, paid
     * once) and one on the last answer of ours, so each correction reads the rest from the cache.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    private static function withCacheBreakpoints(array $messages): array
    {
        $first = \count($messages[0]['content']) - 1;
        $messages[0]['content'][$first]['cacheControl'] = ['type' => 'ephemeral'];
        $last = \count($messages) - 1;
        if ($last > 0 && 'user' === $messages[$last]['role']) {
            $block = \count($messages[$last]['content']) - 1;
            $messages[$last]['content'][$block]['cacheControl'] = ['type' => 'ephemeral'];
        }

        return $messages;
    }

    /**
     * agent/conversation-NNN.json: what was sent and received, without the images.
     *
     * @param list<array<string, mixed>>                                      $messages
     * @param array{input: int, output: int, cacheRead: int, cacheWrite: int} $usage
     */
    private function saveConversation(Job $job, array $messages, array $usage): void
    {
        $plain = json_decode(json_encode($messages, \JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]', true);
        array_walk_recursive($plain, static function (mixed &$value, string|int $key): void {
            if ('data' === $key && \is_string($value) && \strlen($value) > 200) {
                $value = '[imagen]';
            }
        });
        $n = \count(glob($job->path('agent/conversation-*.json')) ?: []) + 1;
        file_put_contents($job->path(\sprintf('agent/conversation-%03d.json', $n)), json_encode(['model' => $this->client->model(), 'usage' => $usage, 'messages' => $plain], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, string> $errors
     */
    private static function lines(array $errors): string
    {
        $lines = [];
        foreach (\array_slice($errors, 0, self::MAX_ERROR_LINES, true) as $pointer => $message) {
            $lines[] = '- '.$pointer.': '.$message;
        }

        return implode("\n", $lines);
    }

    private static function n(float|int $value): string
    {
        return str_replace('.', ',', (string) round((float) $value, 1));
    }
}
