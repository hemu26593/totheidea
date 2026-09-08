<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Sessions\CurriculumService;
use App\Domain\Sessions\SessionSchedulingService;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Program;
use App\Models\SessionInstance;
use App\Models\SessionTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Sessions 1-6 as data, instantiated per batch.
 */
class SessionSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private SessionSchedulingService $scheduling;

    private CurriculumService $curriculum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->scheduling = app(SessionSchedulingService::class);
        $this->curriculum = app(CurriculumService::class);
    }

    #[Test]
    public function the_curriculum_is_six_rows_not_six_classes(): void
    {
        $program = Program::factory()->create(['session_count' => 6]);
        $actor = $this->admin();

        foreach (range(1, 6) as $sequence) {
            $this->curriculum->defineSession($program, $sequence, "Session {$sequence}", actor: $actor);
        }

        $this->assertSame(6, SessionTemplate::query()->where('program_id', $program->getKey())->count());
        $this->assertTrue($this->curriculum->curriculumIsComplete($program));
    }

    #[Test]
    public function a_program_cannot_have_two_sessions_at_the_same_position(): void
    {
        $program = Program::factory()->create();
        $this->curriculum->defineSession($program, 3, 'Finance System');

        // UNIQUE (program_id, sequence): two "session 3" rows would make the
        // calendar and every reminder ambiguous.
        $this->expectException(QueryException::class);
        $this->curriculum->defineSession($program, 3, 'Something else');
    }

    #[Test]
    public function a_session_sequence_starts_at_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->curriculum->defineSession(Program::factory()->create(), 0, 'Session zero');
    }

    // --- Invariant I17 -----------------------------------------------------

    #[Test]
    public function a_batch_cannot_be_scheduled_to_sit_another_programs_session(): void
    {
        $batch = Batch::factory()->create();
        $foreign = SessionTemplate::factory()->create(); // its own program

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('belongs to program');

        $this->scheduling->schedule($batch, $foreign, now()->addWeek()->toDateString());
    }

    #[Test]
    public function scheduling_the_curriculum_instantiates_every_session_for_the_batch(): void
    {
        $program = Program::factory()->create(['session_count' => 3]);
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);

        foreach (range(1, 3) as $sequence) {
            $this->curriculum->defineSession($program, $sequence, "Session {$sequence}");
        }

        $instances = $this->scheduling->scheduleCurriculum($batch, [
            1 => now()->addWeek()->toDateString(),
            2 => now()->addWeeks(3)->toDateString(),
            3 => now()->addWeeks(5)->toDateString(),
        ], $this->admin());

        $this->assertCount(3, $instances);
        $this->assertSame(3, SessionInstance::query()->where('batch_id', $batch->getKey())->count());
    }

    #[Test]
    public function scheduling_a_curriculum_is_all_or_nothing(): void
    {
        $program = Program::factory()->create(['session_count' => 2]);
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);
        $this->curriculum->defineSession($program, 1, 'One');
        $this->curriculum->defineSession($program, 2, 'Two');

        try {
            // A missing date for session 2 must not leave session 1 scheduled.
            $this->scheduling->scheduleCurriculum($batch, [1 => now()->addWeek()->toDateString()]);
            $this->fail('Expected the missing date to be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, SessionInstance::query()->where('batch_id', $batch->getKey())->count());
    }

    // --- Cancel, never delete ---------------------------------------------

    #[Test]
    public function a_session_instance_cannot_be_deleted_by_anyone(): void
    {
        $instance = SessionInstance::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cancelled, never deleted');

        $instance->delete();
    }

    #[Test]
    public function cancelling_records_who_and_why(): void
    {
        $instance = SessionInstance::factory()->create();
        $actor = $this->admin();

        $cancelled = $this->scheduling->cancel($instance, $actor, 'Venue unavailable');

        $this->assertSame(SessionInstance::STATUS_CANCELLED, $cancelled->status);
        $this->assertTrue($cancelled->exists);

        $entry = AuditLog::query()->where('action', AuditAction::SessionCancelled)->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame($actor->getKey(), $entry->actor_id);
        $this->assertSame('Venue unavailable', $entry->new_values['reason']);
    }

    #[Test]
    public function a_cancelled_session_cannot_be_rescheduled_or_completed(): void
    {
        $instance = SessionInstance::factory()->cancelled()->create();

        $this->expectException(RuntimeException::class);
        $this->scheduling->reschedule($instance, now()->addMonth()->toDateString(), $this->admin());
    }

    // --- Both dates --------------------------------------------------------

    #[Test]
    public function a_moved_date_is_audited_because_every_reminder_reads_it(): void
    {
        $instance = SessionInstance::factory()->create(['planned_date' => '2026-10-01']);

        $this->scheduling->reschedule($instance, '2026-10-15', $this->admin(), 'Client request');

        $entry = AuditLog::query()->where('action', AuditAction::SessionRescheduled)->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame('2026-10-01', $entry->old_values['planned_date']);
        $this->assertSame('2026-10-15', $entry->new_values['planned_date']);
    }

    #[Test]
    public function the_plan_survives_the_session_actually_happening(): void
    {
        $instance = SessionInstance::factory()->create(['planned_date' => '2026-10-01']);

        $completed = $this->scheduling->complete($instance, $this->admin(), '2026-10-03');

        // Two dates, not one: the plan is not overwritten by what happened.
        $this->assertSame('2026-10-01', $completed->planned_date->toDateString());
        $this->assertSame('2026-10-03', $completed->actual_date->toDateString());
    }

    // --- [CLIENT DECISION - S5] -------------------------------------------

    #[Test]
    public function the_repeat_sitting_constraint_is_not_applied_speculatively(): void
    {
        // [CLIENT DECISION - S5]. Whether a session may be held only once per
        // batch is unanswered. Adding UNIQUE (batch_id, session_template_id)
        // later is trivial; removing it after data exists is not, so it is
        // deliberately absent until the client answers.
        $unique = collect(Schema::getIndexes('session_instances'))
            ->filter(fn (array $i): bool => $i['unique'] === true)
            ->map(fn (array $i): array => $i['columns'])
            ->values();

        $this->assertFalse(
            $unique->contains(['batch_id', 'session_template_id']),
            'S5 is unresolved: no unique key may be imposed on repeat sittings yet.'
        );
    }
}
