<?php namespace GO\Job\Tests;

use GO\Job;
use PHPUnit\Framework\TestCase;
use Tests\FakeRedis;

class JobTest extends TestCase
{
    public function testShouldAlwaysGenerateAnId()
    {
        $job1 = new Job('ls');
        $this->assertTrue(is_string($job1->getId()));

        $job2 = new Job(function () {
            return true;
        });
        $this->assertTrue(is_string($job2->getId()));

        $job3 = new Job(['MyClass', 'myMethod']);
        $this->assertTrue(is_string($job3->getId()));
    }

    public function testShouldGenerateIdFromSignature()
    {
        $job1 = new Job('ls');
        $this->assertEquals(md5('ls'), $job1->getId());

        $job2 = new Job('whoami');
        $this->assertNotEquals($job1->getId(), $job2->getId());

        $job3 = new Job(['MyClass', 'myMethod']);
        $this->assertNotEquals($job1->getId(), $job3->getId());
    }

    public function testShouldAllowCustomId()
    {
        $job = new Job('ls', [], 'aCustomId');

        $this->assertNotEquals(md5('ls'), $job->getId());
        $this->assertEquals('aCustomId', $job->getId());

        $job2 = new Job(['MyClass', 'myMethod'], null, 'myCustomId');
        $this->assertEquals('myCustomId', $job2->getId());
    }

    public function testShouldKnowIfDue()
    {
        $job1 = new Job('ls');
        $this->assertTrue($job1->isDue());

        $job2 = new Job('ls');
        $job2->at('* * * * *');
        $this->assertTrue($job2->isDue());

        $job3 = new Job('ls');
        $job3->at('10 * * * *');
        $this->assertTrue($job3->isDue(\DateTime::createFromFormat('i', '10')));
        $this->assertFalse($job3->isDue(\DateTime::createFromFormat('i', '12')));
    }

    public function testShouldKnowIfCanRunInBackground()
    {
        $job = new Job('ls');
        $this->assertTrue($job->canRunInBackground());

        $job2 = new Job(function () {
            return "I can't run in background";
        });
        $this->assertFalse($job2->canRunInBackground());
    }

    public function testShouldForceTheJobToRunInForeground()
    {
        $job = new Job('ls');

        $this->assertTrue($job->canRunInBackground());
        $this->assertFalse($job->inForeground()->canRunInBackground());
    }

    public function testShouldReturnCompiledJobCommand()
    {
        $job1 = new Job('ls');
        $this->assertEquals('ls', $job1->inForeground()->compile());

        $fn = function () {
            return true;
        };
        $job2 = new Job($fn);
        $this->assertEquals($fn, $job2->compile());
    }

    public function testShouldCompileWithArguments()
    {
        $job = new Job('ls', [
            '-l' => null,
            '-arg' => 'value',
        ]);

        $this->assertEquals("ls '-l' '-arg' 'value'", $job->inForeground()->compile());
    }

    public function testShouldCompileCommandInBackground()
    {
        $job1 = new Job('ls');
        $job1->at('* * * * *');

        $this->assertEquals('(ls) > /dev/null 2>&1 &', $job1->compile());
    }

    public function testShouldRunInBackground()
    {
        // This script has a 5 seconds sleep
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../async_job.php');
        $job = new Job($command);

        $startTime = microtime(true);
        $job->at('* * * * *')->run();
        $endTime = microtime(true);

        $this->assertTrue(5 > ($endTime - $startTime));

        $startTime = microtime(true);
        $job->at('* * * * *')->inForeground()->run();
        $endTime = microtime(true);

        $this->assertTrue(($endTime - $startTime) >= 5);
    }

    public function testShouldRunInForegroundIfSendsEmails()
    {
        $job = new Job('ls');
        $job->email('test@mail.com');

        $this->assertFalse($job->canRunInBackground());
    }

    public function testShouldAcceptSingleOrMultipleEmails()
    {
        $job = new Job('ls');

        $this->assertInstanceOf(Job::class, $job->email('test@mail.com'));
        $this->assertInstanceOf(Job::class, $job->email(['test@mail.com', 'other@mail.com']));
    }

    public function testShouldFailIfEmailInputIsNotStringOrArray()
    {
        $this->expectException(\InvalidArgumentException::class);

        $job = new Job('ls');

        $job->email(1);
    }

    public function testShouldAcceptEmailConfigurationAndItShouldBeChainable()
    {
        $job = new Job('ls');
        $this->assertInstanceOf(Job::class, $job->configure([
            'email' => [],
        ]));
    }

    public function testShouldFailIfEmailConfigurationIsNotArray()
    {
        $this->expectException(\InvalidArgumentException::class);

        $job = new Job('ls');
        $job->configure([
            'email' => 123,
        ]);
    }

    public function testShouldRequireRedisConfigurationForOnlyOneLocks()
    {
        $this->expectException(\InvalidArgumentException::class);

        $job = new Job(function () {
            return true;
        });

        $job->onlyOne()->run();
    }

    public function testShouldPreventOverlappingWithRedisLock()
    {
        $redis = new FakeRedis();
        $secondRun = null;

        $secondJob = (new Job(function () {
            return true;
        }, [], 'shared-job'))->configure([
            'redis' => ['client' => $redis],
        ])->onlyOne();

        $firstJob = (new Job(function () use ($secondJob, &$secondRun) {
            $secondRun = $secondJob->run();
        }, [], 'shared-job'))->configure([
            'redis' => ['client' => $redis],
        ])->onlyOne();

        $this->assertTrue($firstJob->run());
        $this->assertFalse($secondRun);
        $this->assertFalse($firstJob->isOverlapping());
    }

    public function testShouldKnowIfRedisLockIsOverlapping()
    {
        $redis = new FakeRedis();
        $job = (new Job(function () {
            return true;
        }, [], 'redis-overlap'))->configure([
            'redis' => ['client' => $redis],
        ])->onlyOne();

        $this->assertFalse($job->isOverlapping());

        $redis->set('php-cron-scheduler:locks:redis-overlap', 'token', ['nx', 'ex' => 60]);

        $this->assertTrue($job->isOverlapping());
    }

    public function testShouldForceRedisLockedCommandsToRunInForeground()
    {
        $job = (new Job('ls'))->configure([
            'redis' => ['client' => new FakeRedis()],
        ]);

        $this->assertTrue($job->canRunInBackground());
        $this->assertFalse($job->onlyOne()->canRunInBackground());
        $this->assertEquals('ls', $job->compile());
    }

    public function testShouldNotRunMoreOftenThanCooldown()
    {
        $runs = 0;
        $job = (new Job(function () use (&$runs) {
            $runs++;
        }, [], 'cooldown-job'))->configure([
            'redis' => ['client' => new FakeRedis()],
        ])->runAtMostEvery(300);

        $this->assertTrue($job->run());
        $this->assertFalse($job->run());
        $this->assertEquals(1, $runs);
    }

    public function testWhenMethodShouldBeChainable()
    {
        $job = new Job('ls');

        $this->assertInstanceOf(Job::class, $job->when(function () {
            return true;
        }));
    }

    public function testShouldNotRunIfTruthTestFails()
    {
        $job = new Job('ls');

        $this->assertFalse($job->when(function () {
            return false;
        })->run());

        $this->assertTrue($job->when(function () {
            return true;
        })->run());
    }

    public function testShouldReturnOutputOfJobExecution()
    {
        $job1 = new Job(function () {
            echo 'hi';
        });
        $job1->run();
        $this->assertEquals('hi', $job1->getOutput());

        $job2 = new Job(function () {
            return 'hello';
        });
        $job2->run();
        $this->assertEquals('hello', $job2->getOutput());

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../test_job.php');
        $job3 = new Job($command);
        $job3->inForeground()->run();
        $this->assertEquals(['hi'], $job3->getOutput());
    }

    public function testShouldRunCallbackBeforeJobExecution()
    {
        $job = new Job(function () {
            return 'Job for testing before function';
        });

        $callbackWasExecuted = false;
        $outputWasSet = false;

        $job->before(function () use ($job, &$callbackWasExecuted, &$outputWasSet) {
            $callbackWasExecuted = true;
            $outputWasSet = ! is_null($job->getOutput());
        })->run();

        $this->assertTrue($callbackWasExecuted);
        $this->assertFalse($outputWasSet);
    }

    public function testShouldRunCallbackAfterJobExecution()
    {
        $job = new Job(function () {
            $visitors = 1000;

            return 'Daily visitors: ' . $visitors;
        });

        $jobResult = null;

        $job->then(function ($output) use (&$jobResult) {
            $jobResult = $output;
        })->run();

        $this->assertEquals($jobResult, $job->getOutput());

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../test_job.php');
        $job2 = new Job($command);

        $job2Result = null;

        $job2->then(function ($output) use (&$job2Result) {
            $job2Result = $output;
        }, true)->run();

        // Commands in background should return an empty string
        $this->assertTrue(empty($job2Result));

        $job2Result = null;
        $job2->then(function ($output) use (&$job2Result) {
            $job2Result = $output;
        })->inForeground()->run();
        $this->assertTrue(! empty($job2Result) &&
            $job2Result === $job2->getOutput());
    }

    public function testThenMethodShouldPassReturnCode()
    {
        $command_success = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../test_job.php');
        $command_fail = $command_success . ' fail';

        $run = function ($command) {
            $job = new Job($command);
            $testReturnCode = null;

            $job->then(function ($output, $returnCode) use (&$testReturnCode, &$testOutput) {
                $testReturnCode = $returnCode;
            })->run();

            return $testReturnCode;
        };

        $this->assertEquals(0, $run($command_success));
        $this->assertNotEquals(0, $run($command_fail));
    }

    public function testThenMethodShouldBeChainable()
    {
        $job = new Job('ls');

        $this->assertInstanceOf(Job::class, $job->then(function () {
            return true;
        }));
    }

    public function testShouldDefaultExecutionInForegroundIfMethodThenIsDefined()
    {
        $job = new Job('ls');

        $job->then(function () {
            return true;
        });

        $this->assertFalse($job->canRunInBackground());
    }

    public function testShouldAllowForcingTheJobToRunInBackgroundIfMethodThenIsDefined()
    {
        // This is a use case when you want to execute a callback every time your
        // job is executed, but you don't care about the output of the job

        $job = new Job('ls');

        $job->then(function () {
            return true;
        }, true);

        $this->assertTrue($job->canRunInBackground());
    }
}
