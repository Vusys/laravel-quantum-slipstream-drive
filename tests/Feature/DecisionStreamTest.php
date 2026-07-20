<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Vusys\QuantumSlipstreamDrive\Events\QueryDecided;
use Vusys\QuantumSlipstreamDrive\Store\IdentityMapStore;
use Vusys\QuantumSlipstreamDrive\Tests\Models\User;
use Vusys\QuantumSlipstreamDrive\Tests\TestCase;

final class DecisionStreamTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    private function enableObservability(?string $channel = null, string $level = 'info'): void
    {
        config([
            'quantum-slipstream-drive.observability.enabled' => true,
            'quantum-slipstream-drive.observability.channel' => $channel,
            'quantum-slipstream-drive.observability.level' => $level,
        ]);
    }

    #[Test]
    public function no_event_dispatched_when_disabled(): void
    {
        config(['quantum-slipstream-drive.observability.enabled' => false]);
        Event::fake([QueryDecided::class]);

        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);
        User::find($user->id);
        User::find($user->id);

        Event::assertNotDispatched(QueryDecided::class);
    }

    #[Test]
    public function event_dispatches_once_per_finalised_plan_for_known_mix(): void
    {
        $this->enableObservability();
        Event::fake([QueryDecided::class]);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        // Memory hit — alice already cached by create.
        User::find($alice->id);
        // Memory hit — bob already cached.
        User::find($bob->id);
        // Absent-tracked: first find issues SQL, second hits the absent table.
        User::find(99999);
        User::find(99999);

        Event::assertDispatchedTimes(QueryDecided::class, 4);

        $dispatched = [];
        Event::assertDispatched(QueryDecided::class, function (QueryDecided $event) use (&$dispatched): bool {
            $dispatched[] = $event->explanation->type->value;

            return true;
        });

        $this->assertSame(
            ['return_model_from_memory', 'return_model_from_memory', 'execute_normally', 'return_null'],
            $dispatched,
        );
    }

    #[Test]
    public function explain_still_captures_when_streaming_enabled(): void
    {
        $this->enableObservability();
        Event::fake([QueryDecided::class]);

        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $explanations = $this->store->explain(function () use ($user): void {
            User::find($user->id);
            User::find($user->id);
        });

        $this->assertCount(2, $explanations);
        $this->assertSame('return_model_from_memory', $explanations[0]->type->value);

        Event::assertDispatchedTimes(QueryDecided::class, 2);
    }

    #[Test]
    public function log_uses_configured_channel_and_level(): void
    {
        $this->enableObservability(channel: 'identity-map', level: 'warning');

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('log')
            ->with(
                'warning',
                $this->stringContains('Plan: return_model_from_memory'),
                $this->callback(fn (array $context): bool => isset($context['context'])
                    && is_array($context['context'])
                    && $context['context']['type'] === 'return_model_from_memory'),
            );

        Log::shouldReceive('channel')
            ->with('identity-map')
            ->andReturn($logger);

        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);
    }

    #[Test]
    public function log_falls_back_to_default_driver_when_channel_is_null(): void
    {
        $this->enableObservability(channel: null, level: 'debug');

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('log')
            ->with('debug', $this->anything(), $this->anything());

        Log::shouldReceive('driver')->withNoArgs()->andReturn($logger);

        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);
    }

    #[Test]
    public function event_carries_full_explanation_object(): void
    {
        $this->enableObservability();
        Event::fake([QueryDecided::class]);

        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        Event::assertDispatched(QueryDecided::class, fn (QueryDecided $event): bool => $event->explanation->modelClass === User::class
            && $event->explanation->sqlExecuted === false
            && $event->explanation->memoryKeys === [$user->id]);
    }
}
