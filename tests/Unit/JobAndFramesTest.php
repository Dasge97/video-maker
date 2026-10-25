<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Job\Job;
use App\Job\JobStore;
use App\Reference\Frames;
use App\Reference\VideoTools;
use PHPUnit\Framework\TestCase;

final class JobAndFramesTest extends TestCase
{
    public function testOneFramePerShot(): void
    {
        $shots = Frames::plan(12.0, [2.0, 2.1, 4.0, 7.5, 9.0]);
        // 2.1 is a flash after 2.0: one cut. Five shots, each frame in its middle.
        self::assertCount(5, $shots);
        self::assertSame(['atSeconds' => 1.0, 'shotStart' => 0.0, 'shotEnd' => 2.0], $shots[0]);
        self::assertSame(['atSeconds' => 10.5, 'shotStart' => 9.0, 'shotEnd' => 12.0], $shots[4]);
    }

    public function testFewCutsAreSampledAtFixedIntervals(): void
    {
        $shots = Frames::plan(10.0, [5.0]);
        self::assertCount(5, $shots);
        self::assertSame(1.0, $shots[0]['atSeconds']);
        self::assertSame(2.0, $shots[0]['shotEnd']);
    }

    public function testAtMostSixteenFramesWithTheFirstAndTheLastShot(): void
    {
        $cuts = [];
        for ($i = 1; $i < 30; ++$i) {
            $cuts[] = (float) $i;
        }
        $shots = Frames::plan(30.0, $cuts);
        self::assertCount(VideoTools::MAX_FRAMES, $shots);
        self::assertSame(0.0, $shots[0]['shotStart']);
        self::assertSame(30.0, end($shots)['shotEnd']);
    }

    public function testTheCutsAreReadFromShowinfo(): void
    {
        $log = "[Parsed_showinfo_1 @ 0x1] n:   0 pts:  48048 pts_time:2.002   duration:1001\n[Parsed_showinfo_1 @ 0x1] n:   1 pts: 96096 pts_time:4.004 duration:1001\n";
        self::assertSame([2.002, 4.004], VideoTools::parseCuts($log));
    }

    public function testTheStepsOfAVideoAndARetry(): void
    {
        $dir = sys_get_temp_dir().'/vm-test-'.bin2hex(random_bytes(4));
        $store = new JobStore($dir);
        $job = $store->create(['mode' => 'voice_over']);
        self::assertSame('analysing', $job->status());
        self::assertSame('check', $job->step());

        $job->advance();
        self::assertSame('frames', $job->step());
        $job->fail('Algo ha fallado.');
        self::assertSame('failed', $job->status());
        $job->retry();
        self::assertSame(['analysing', 'frames'], [$job->status(), $job->step()]);

        $job->advance();
        $job->advance();
        $job->advance();
        self::assertSame(['proposal', null], [$job->status(), $job->step()]);

        $job->savePlan(['pieces' => []], 'agent');
        $store->save($job);
        $again = $store->get($job->id());
        self::assertSame(1, $again?->data['plan']['version']);
        self::assertSame(['pieces' => []], $again->plan());
        self::assertTrue($store->locked($job->id(), static fn (Job $j) => $j->start('generating')));
        self::assertSame('voice', $store->get($job->id())?->step());
        self::assertNull($store->get('../etc'));

        $store->locked($job->id(), static fn (Job $j) => $j->data['status'] = 'ready');
        self::assertTrue($store->delete($job->id()));
        @rmdir($dir);
    }
}
