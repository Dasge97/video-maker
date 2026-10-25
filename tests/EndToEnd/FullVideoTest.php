<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Command\WorkerCommand;
use App\Job\Job;
use App\Job\JobStore;
use App\Render\Ffmpeg;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

/**
 * A whole video with every provider imitated: from «analysing» to «ready», through the worker,
 * and the MP4 lasts what the voice says (±0,5 s). The plan of the agent is the one of the real
 * run kept in tests/fixtures/talking_person. Needs ffmpeg in the PATH.
 */
final class FullVideoTest extends KernelTestCase
{
    private string $media;
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->media = sys_get_temp_dir().'/vm-e2e-'.bin2hex(random_bytes(4));
        mkdir($this->media);
        $run = static function (array $command): void {
            $p = new Process($command);
            $p->setTimeout(120);
            $p->mustRun();
        };
        // A reference of 20 s with sound, a clip of 9:16, a voice and a music.
        $run(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=360x640:rate=24:duration=20', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=20', '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-y', $this->media.'/reference.mp4']);
        $run(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=720x1280:rate=24:duration=5', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-y', $this->media.'/clip.mp4']);
        $run(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=220:duration=20', '-c:a', 'libmp3lame', '-y', $this->media.'/tone.mp3']);
        $gd = imagecreatetruecolor(864, 1536);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 60, 140, 200));
        imagejpeg($gd, $this->media.'/image.jpg');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->media.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->media);
        parent::tearDown();
    }

    public function testFromTheUploadToTheVideo(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $container->set('http_client', new MockHttpClient($this->provider(...)));
        /** @var JobStore $store */
        $store = $container->get(JobStore::class);
        /** @var WorkerCommand $worker */
        $worker = $container->get(WorkerCommand::class);

        $fixture = json_decode((string) file_get_contents(\dirname(__DIR__).'/fixtures/talking_person/job.json'), true);
        $job = $store->create(['referenceUrl' => null, 'referenceFile' => 'input/reference-upload.mp4', 'personFile' => 'input/person-upload.jpg', 'logoFile' => 'input/logo-upload.png'] + $fixture['input']);
        copy($this->media.'/reference.mp4', $job->path('input/reference-upload.mp4'));
        copy(\dirname(__DIR__).'/fixtures/talking_person/input/person.jpg', $job->path('input/person-upload.jpg'));
        copy(\dirname(__DIR__).'/fixtures/talking_person/input/logo.png', $job->path('input/logo-upload.png'));
        $store->save($job);

        $job = $this->work($store, $worker, $job->id());
        self::assertSame('proposal', $job->status(), (string) json_encode($job->data['error']));
        self::assertNotNull($job->plan());
        self::assertGreaterThan(0, $job->data['estimate']['usd']);

        $store->locked($job->id(), static fn (Job $j) => $j->start('generating'));
        for ($round = 0; $round < 10; ++$round) {
            // The worker would wait 10 s between two questions to fal.
            $store->locked($job->id(), static function (Job $j): void { $j->data['clipsPolledAt'] = 0; });
            $job = $this->work($store, $worker, $job->id());
            if ('generating' !== $job->status()) {
                break;
            }
        }
        self::assertSame('ready', $job->status(), (string) json_encode($job->data['error']));
        $timeline = json_decode((string) file_get_contents($job->path('audio/timeline.json')), true);
        $seconds = (new Ffmpeg())->probe($job->path('out/video.mp4'))['duration'];
        self::assertEqualsWithDelta($timeline['totalSeconds'], $seconds, 0.5);
        self::assertFileExists($job->path('out/poster.jpg'));
        // Each clip was sent once, and the costs written.
        $submits = array_filter($this->calls, static fn (string $c) => str_starts_with($c, 'POST https://queue.fal.run/'));
        self::assertCount(\count($job->data['outputs']['clips']), $submits);
        self::assertGreaterThan(0, $job->totalCost());

        // Generating again without changes pays nothing: every file is on disk.
        $before = \count($this->calls);
        $store->locked($job->id(), static fn (Job $j) => $j->start('generating'));
        $job = $this->work($store, $worker, $job->id());
        self::assertSame('ready', $job->status());
        self::assertSame([], array_values(array_filter(\array_slice($this->calls, $before), static fn (string $c) => str_starts_with($c, 'POST'))));

        $store->delete($job->id());
    }

    private function work(JobStore $store, WorkerCommand $worker, string $id): Job
    {
        $store->locked($id, static fn (Job $j) => $worker->work($j));

        return $store->get($id) ?? throw new \RuntimeException('Sin vídeo');
    }

    /**
     * The providers, imitated by URL.
     *
     * @param array<string, mixed> $options
     */
    private function provider(string $method, string $url, array $options): MockResponse
    {
        $this->calls[] = $method.' '.$url;
        $json = static fn (array $data) => new MockResponse((string) json_encode($data), ['response_headers' => ['content-type' => 'application/json']]);
        if (str_contains($url, 'api.anthropic.com')) {
            $plan = json_decode((string) file_get_contents(\dirname(__DIR__).'/fixtures/talking_person/plan.json'), true);

            return $json([
                'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'submit_plan', 'input' => $plan]],
                'stop_reason' => 'tool_use', 'stop_sequence' => null, 'container' => null, 'context_management' => null, 'diagnostics' => null, 'stop_details' => null,
                'usage' => ['input_tokens' => 100, 'output_tokens' => 100, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            ]);
        }
        if (str_contains($url, 'api.elevenlabs.io/v1/text-to-speech')) {
            $text = (string) json_decode((string) $options['body'], true)['text'];
            $characters = mb_str_split($text);
            $starts = array_map(static fn (int $i) => round($i * 0.06, 3), array_keys($characters));

            return $json([
                'audio_base64' => base64_encode((string) file_get_contents($this->media.'/tone.mp3')),
                'alignment' => ['characters' => $characters, 'character_start_times_seconds' => $starts, 'character_end_times_seconds' => array_map(static fn (float $s) => round($s + 0.06, 3), $starts)],
            ]);
        }
        if (str_contains($url, 'api.elevenlabs.io/v1/music')) {
            return new MockResponse((string) file_get_contents($this->media.'/tone.mp3'));
        }
        if (str_contains($url, 'api.openai.com/v1/images')) {
            return $json(['data' => [['b64_json' => base64_encode((string) file_get_contents($this->media.'/image.jpg'))]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 1000]]);
        }
        if ('POST' === $method && str_starts_with($url, 'https://queue.fal.run/')) {
            $id = 'req-'.\count($this->calls);

            return $json(['request_id' => $id, 'status_url' => 'https://queue.fal.run/fal-ai/x/requests/'.$id.'/status', 'response_url' => 'https://queue.fal.run/fal-ai/x/requests/'.$id, 'cancel_url' => 'https://queue.fal.run/fal-ai/x/requests/'.$id.'/cancel']);
        }
        if (str_ends_with($url, '/status')) {
            return $json(['status' => 'COMPLETED']);
        }
        if (str_starts_with($url, 'https://queue.fal.run/')) {
            return $json(['video' => ['url' => 'https://fal.media/files/clip.mp4', 'content_type' => 'video/mp4']]);
        }
        if (str_starts_with($url, 'https://fal.media/')) {
            return new MockResponse((string) file_get_contents($this->media.'/clip.mp4'));
        }

        return new MockResponse('', ['http_code' => 404]);
    }
}
