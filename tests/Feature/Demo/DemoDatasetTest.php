<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Domain\Notifications\RecipientResolver;
use App\Enums\UserRole;
use App\Models\AccessGrant;
use App\Models\ActionItem;
use App\Models\Answer;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Document;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\FundPlan;
use App\Models\FundPlanLine;
use App\Models\HrPolicy;
use App\Models\MmdEntry;
use App\Models\MmdTarget;
use App\Models\Note;
use App\Models\Position;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use App\Models\SubmissionScore;
use App\Models\TimeGridEntry;
use App\Models\User;
use Database\Seeders\Demo\DemoDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The demonstration dataset: that it refuses to run where it must not, that it
 * produces a coherent programme, and that every row it writes points at
 * something real.
 *
 * The consistency tests here are the ones worth having. A demo that looks
 * populated but holds an attendance record against a session nobody attended,
 * or a submission bound to an unpublished version, fails in front of the
 * client rather than in CI - so each relationship is asserted rather than
 * assumed.
 */
class DemoDatasetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();

        // The demo attributes every write to a real user, exactly as the
        // application does. In a local environment that is the Super Admin
        // created by SuperAdminSeeder.
        $this->superAdmin(['email' => (string) config('authorization.initial_super_admin.email')]);

        Mail::fake();
    }

    /*
    |--------------------------------------------------------------------------
    | A. It cannot run where it must not
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_refuses_to_run_in_production(): void
    {
        $customer = Customer::factory()->create(['code' => 'REAL-DATA']);

        app()->detectEnvironment(fn (): string => 'production');

        $this->artisan('demo:reset', ['--force' => true])
            ->expectsOutputToContain('runs only in local, development or testing')
            ->assertExitCode(1);

        $this->assertNotNull(
            Customer::query()->where('code', 'REAL-DATA')->first(),
            'A refused run must not have deleted anything.',
        );
    }

    #[Test]
    public function it_refuses_every_environment_it_was_not_told_about(): void
    {
        // An allowlist, not "if production": an environment nobody thought of
        // is refused rather than wiped.
        foreach (['staging', 'uat', 'demo-prod'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);

            $this->artisan('demo:reset', ['--force' => true])->assertExitCode(1);
        }
    }

    #[Test]
    public function it_runs_in_a_permitted_environment(): void
    {
        $this->artisan('demo:reset', ['--force' => true])->assertExitCode(0);

        $this->assertGreaterThan(0, Customer::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | B. What it produces
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_active_businesses_each_with_a_primary_contact_in_a_batch(): void
    {
        $this->seedDemo();

        $customers = Customer::query()->get();

        $this->assertGreaterThanOrEqual(8, $customers->count());
        $this->assertLessThanOrEqual(12, $customers->count());

        foreach ($customers as $customer) {
            $this->assertSame('active', $customer->status, "{$customer->code} should be active.");
            $this->assertStringStartsWith(DemoDataset::CODE_PREFIX, (string) $customer->code);

            $contact = $customer->primaryContact();

            $this->assertNotNull($contact, "{$customer->code} has no primary contact.");
            $this->assertNotNull($contact->email);
            $this->assertStringEndsWith('.example.test', (string) $contact->email);

            $this->assertSame(
                1,
                $customer->enrollments()->where('status', 'enrolled')->count(),
                "{$customer->code} should be active in exactly one batch.",
            );
        }
    }

    #[Test]
    public function the_recipient_resolver_finds_every_demo_contact(): void
    {
        // The form-link workflow is the thing most likely to be demonstrated,
        // and it goes through RecipientResolver.
        $this->seedDemo();

        foreach (Customer::query()->get() as $customer) {
            $this->assertNotNull(
                app(RecipientResolver::class)->primaryContactFor((int) $customer->getKey()),
                "No form link could be sent to {$customer->code}.",
            );
        }
    }

    #[Test]
    public function it_creates_published_forms_with_questions(): void
    {
        $this->seedDemo();

        $this->assertSame(5, FormTemplate::query()->count());

        foreach (FormTemplate::query()->get() as $template) {
            $published = $template->publishedVersion();

            $this->assertNotNull($published, "{$template->key} has no published version.");
            $this->assertGreaterThan(0, $published->questions()->count());
        }

        $this->assertGreaterThan(0, FormTemplate::query()->where('is_scored', true)->count());
    }

    #[Test]
    public function it_creates_a_spread_of_work_rather_than_one_uniform_state(): void
    {
        // A demo where every record is in the same state shows nothing about
        // the screens that exist to tell states apart.
        $this->seedDemo();

        $this->assertGreaterThan(1, FormSubmission::query()->distinct()->count('status'));
        $this->assertGreaterThan(1, AssignmentSubmission::query()->distinct()->count('status'));
        $this->assertGreaterThan(1, SessionAttendance::query()->distinct()->count('status'));
        $this->assertGreaterThan(1, ActionItem::query()->distinct()->count('status'));
        $this->assertGreaterThan(1, SessionInstance::query()->distinct()->count('status'));

        // And attendance is not a clean sweep.
        $this->assertGreaterThan(
            0,
            SessionAttendance::query()->where('status', '!=', SessionAttendance::STATUS_PRESENT)->count(),
        );
    }

    #[Test]
    public function every_dashboard_metric_has_something_behind_it(): void
    {
        $this->seedDemo();

        foreach ([
            'customers' => Customer::query()->count(),
            'batches' => Batch::query()->count(),
            'enrolments' => Enrollment::query()->count(),
            'sessions' => SessionInstance::query()->count(),
            'attendance' => SessionAttendance::query()->count(),
            'assignments' => AssignmentInstance::query()->count(),
            'form submissions' => FormSubmission::query()->count(),
            'mmd entries' => MmdEntry::query()->count(),
            'mmd targets' => MmdTarget::query()->count(),
            'fund plans' => FundPlan::query()->count(),
            'fund plan lines' => FundPlanLine::query()->count(),
            'action items' => ActionItem::query()->count(),
            'time grid entries' => TimeGridEntry::query()->count(),
            'positions' => Position::query()->count(),
            'hr policies' => HrPolicy::query()->count(),
            'notes' => Note::query()->count(),
            'documents' => Document::query()->count(),
        ] as $section => $count) {
            $this->assertGreaterThan(0, $count, "The demo left [{$section}] empty.");
        }

        // Sessions genuinely behind and genuinely ahead, so the dashboard has
        // both a history and something upcoming.
        $this->assertGreaterThan(0, SessionInstance::query()->where('status', SessionInstance::STATUS_COMPLETED)->count());
        $this->assertGreaterThan(0, SessionInstance::query()->where('status', SessionInstance::STATUS_SCHEDULED)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | C. Every row points at something real
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_submission_binds_to_a_published_version_and_a_real_enrolment(): void
    {
        $this->seedDemo();

        $publishedVersionIds = FormVersion::query()->whereNotNull('published_at')->pluck('id')->all();
        $enrollmentIds = Enrollment::query()->pluck('id')->all();

        foreach (FormSubmission::query()->get() as $submission) {
            $this->assertContains((int) $submission->form_version_id, $publishedVersionIds);
            $this->assertContains((int) $submission->enrollment_id, $enrollmentIds);
        }

        // And every answer belongs to a question on that same version.
        foreach (Answer::query()->with(['question', 'formSubmission'])->get() as $answer) {
            $this->assertSame(
                (int) $answer->formSubmission->form_version_id,
                (int) $answer->question->form_version_id,
                'An answer was recorded against a question from a different version.',
            );
        }
    }

    #[Test]
    public function every_score_was_derived_rather_than_asserted(): void
    {
        $this->seedDemo();

        $this->assertGreaterThan(0, SubmissionScore::query()->count());

        foreach (SubmissionScore::query()->get() as $score) {
            $this->assertGreaterThan(0, (float) $score->max_score);
            $this->assertLessThanOrEqual((float) $score->max_score, (float) $score->raw_score);
            $this->assertGreaterThanOrEqual(0.0, (float) $score->raw_score);

            // The percentage is the two figures it claims to summarise.
            $this->assertEqualsWithDelta(
                round((float) $score->raw_score / (float) $score->max_score * 100, 2),
                (float) $score->percentage,
                0.01,
            );
        }
    }

    #[Test]
    public function attendance_only_exists_for_sessions_that_were_actually_held(): void
    {
        $this->seedDemo();

        $enrollmentIds = Enrollment::query()->pluck('id')->all();

        foreach (SessionAttendance::query()->with('sessionInstance')->get() as $attendance) {
            $this->assertContains((int) $attendance->enrollment_id, $enrollmentIds);

            $this->assertSame(
                SessionInstance::STATUS_COMPLETED,
                $attendance->sessionInstance?->status,
                'Attendance was marked against a session that was never held.',
            );
        }
    }

    #[Test]
    public function every_session_belongs_to_a_batch_running_its_own_programme(): void
    {
        $this->seedDemo();

        foreach (SessionInstance::query()->with(['batch', 'sessionTemplate'])->get() as $instance) {
            $this->assertNotNull($instance->batch);
            $this->assertNotNull($instance->sessionTemplate);
            $this->assertSame(
                (int) $instance->batch->program_id,
                (int) $instance->sessionTemplate->program_id,
                'A session was scheduled from another programme\'s curriculum.',
            );
        }
    }

    #[Test]
    public function assignment_submissions_belong_to_participants_of_the_releasing_batch(): void
    {
        $this->seedDemo();

        foreach (AssignmentSubmission::query()->with(['assignmentInstance.sessionInstance', 'enrollment'])->get() as $submission) {
            $batchOfInstance = (int) $submission->assignmentInstance?->sessionInstance?->batch_id;

            $this->assertSame(
                $batchOfInstance,
                (int) $submission->enrollment?->batch_id,
                'An assignment was submitted by a participant from another batch.',
            );
        }
    }

    #[Test]
    public function tracker_records_all_hang_off_a_real_enrolment(): void
    {
        $this->seedDemo();

        $enrollmentIds = Enrollment::query()->pluck('id')->all();

        foreach ([MmdTarget::class, TimeGridEntry::class, FundPlan::class, ActionItem::class] as $model) {
            $this->assertGreaterThan(0, $model::query()->count());

            foreach ($model::query()->get() as $record) {
                $this->assertContains((int) $record->enrollment_id, $enrollmentIds, $model.' is orphaned.');
            }
        }

        // MMD carries both, and they must agree with each other.
        foreach (MmdEntry::query()->with('enrollment')->get() as $entry) {
            $this->assertSame(
                (int) $entry->customer_id,
                (int) $entry->enrollment?->customer_id,
                'An MMD entry names one customer and an enrolment belonging to another.',
            );
        }
    }

    #[Test]
    public function mmd_uses_only_the_metrics_the_application_defines(): void
    {
        $this->seedDemo();

        foreach (MmdTarget::query()->pluck('metric')->unique() as $metric) {
            $this->assertContains((string) $metric, MmdEntry::METRICS);
        }
    }

    #[Test]
    public function every_document_points_at_a_file_that_is_really_there(): void
    {
        // DocumentService records metadata and never writes a file, and the
        // Documents screen 404s when the file is missing. Seeding rows without
        // files would give a list where nothing opens.
        $this->seedDemo();

        $this->assertGreaterThan(0, Document::query()->count());

        foreach (Document::query()->get() as $document) {
            $this->assertTrue(
                Storage::disk($document->disk)->exists($document->path),
                "The file behind [{$document->original_name}] does not exist.",
            );

            $this->assertSame(
                (int) Storage::disk($document->disk)->size($document->path),
                (int) $document->size_bytes,
                'The recorded size is not the file\'s size.',
            );
        }
    }

    #[Test]
    public function seeded_form_links_keep_every_external_access_guarantee(): void
    {
        $this->seedDemo();

        $grants = AccessGrant::query()->get();

        $this->assertGreaterThan(0, $grants->count());

        foreach ($grants as $grant) {
            // Hashed, single-use, fourteen days — unchanged by the demo.
            $this->assertSame(64, strlen((string) $grant->token_hash));
            $this->assertTrue((bool) $grant->single_use);
            $this->assertSame(1, (int) $grant->max_uses);
            $this->assertSame(
                (int) config('access.link_expiry_days'),
                (int) $grant->created_at->startOfSecond()->diffInDays($grant->expires_at->startOfSecond()),
            );

            // Scoped to the enrolment's own customer, never another's.
            $this->assertSame(
                (int) $grant->customer_id,
                (int) $grant->enrollment?->customer_id,
                'A grant was issued across customers.',
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | D. It can be run again
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function running_it_twice_produces_the_same_dataset_not_two_of_everything(): void
    {
        $this->seedDemo();

        $first = $this->census();

        $this->seedDemo();

        $this->assertSame($first, $this->census(), 'A second run changed the dataset.');
    }

    #[Test]
    public function it_leaves_the_super_admin_and_the_permission_matrix_alone(): void
    {
        $email = (string) config('authorization.initial_super_admin.email');
        $before = User::query()->where('email', $email)->first();

        $this->assertNotNull($before, 'The Super Admin should exist before the demo runs.');

        $this->seedDemo();

        $after = User::query()->where('email', $email)->first();

        $this->assertNotNull($after, 'The demo reset removed the Super Admin.');
        $this->assertSame($before->password, $after->password, 'The Super Admin credential changed.');
        $this->assertTrue($after->hasRole(UserRole::SuperAdmin->value));
    }

    #[Test]
    public function it_clears_what_was_there_before_rather_than_adding_to_it(): void
    {
        $stale = Customer::factory()->create(['code' => 'STALE-RUN']);

        $this->seedDemo();

        $this->assertNull(
            Customer::query()->where('code', 'STALE-RUN')->first(),
            'The reset should have cleared the previous dataset.',
        );
        $this->assertGreaterThan(0, Customer::query()->count());
        $this->assertTrue($stale->exists);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function seedDemo(): void
    {
        $this->artisan('demo:reset', ['--force' => true])->assertExitCode(0);
    }

    /**
     * @return array<string, int>
     */
    private function census(): array
    {
        return [
            'customers' => Customer::query()->count(),
            'contacts' => CustomerContact::query()->count(),
            'enrolments' => Enrollment::query()->count(),
            'forms' => FormTemplate::query()->count(),
            'submissions' => FormSubmission::query()->count(),
            'answers' => Answer::query()->count(),
            'sessions' => SessionInstance::query()->count(),
            'attendance' => SessionAttendance::query()->count(),
            'assignments' => AssignmentInstance::query()->count(),
            'assignment_submissions' => AssignmentSubmission::query()->count(),
            'mmd' => MmdEntry::query()->count(),
            'targets' => MmdTarget::query()->count(),
            'fund_plan_lines' => FundPlanLine::query()->count(),
            'action_items' => ActionItem::query()->count(),
            'positions' => Position::query()->count(),
            'documents' => Document::query()->count(),
            'notes' => Note::query()->count(),
        ];
    }
}
