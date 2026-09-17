<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\AccessGrant;
use App\Models\ActionItem;
use App\Models\AiPromptVersion;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DayPlanItem;
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
use App\Models\NotificationDispatch;
use App\Models\Position;
use App\Models\Question;
use App\Models\ReportArtifact;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use App\Models\SubmissionScore;
use App\Models\TimeGridEntry;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Seeder;

/**
 * Builds the whole demonstration dataset, in dependency order.
 *
 * Each step is its own seeder so that none of them grows into a thousand-line
 * file, and so a single section can be re-read without scrolling past the rest.
 * They find what they need by querying for the deterministic codes in
 * DemoDataset rather than by having state threaded through them, which keeps
 * each one runnable and readable on its own.
 *
 * Every write goes through the domain service that owns the rule - enrolment
 * through EnrollmentService, publication through FormPublishingService,
 * attendance through AttendanceService, and so on. Nothing here reaches past a
 * service to write a row the application itself would not write, so the demo
 * data is data the application could have produced, and the audit log it
 * leaves behind is real rather than staged.
 */
class DemoSeeder extends Seeder
{
    /** @var list<class-string<Seeder>> */
    private const STEPS = [
        DemoTeamSeeder::class,
        DemoProgramSeeder::class,
        DemoFormSeeder::class,
        DemoCustomerSeeder::class,
        DemoSessionSeeder::class,
        DemoAssignmentSeeder::class,
        DemoSubmissionSeeder::class,
        DemoTrackerSeeder::class,
        DemoBusinessSeeder::class,
        DemoContentSeeder::class,
    ];

    public function run(): void
    {
        $this->seed();
    }

    /**
     * @return array<string, int>
     */
    public function seed(?OutputStyle $output = null): array
    {
        foreach (self::STEPS as $step) {
            $output?->writeln('  <fg=gray>'.class_basename($step).'</>');

            $this->callWith($step, []);
        }

        return $this->counts();
    }

    /**
     * What the run actually produced, counted from the database rather than
     * tallied while writing - a number that came from anywhere else would not
     * be evidence of anything.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'Customers' => Customer::query()->count(),
            'Primary contacts' => CustomerContact::query()->where('is_primary', true)->count(),
            'Batches' => Batch::query()->count(),
            'Enrolments' => Enrollment::query()->count(),
            'Forms' => FormTemplate::query()->count(),
            'Published form versions' => FormVersion::query()->whereNotNull('published_at')->count(),
            'Form questions' => Question::query()->count(),
            'Form submissions' => FormSubmission::query()->count(),
            'Submission scores' => SubmissionScore::query()->count(),
            'Sessions scheduled' => SessionInstance::query()->count(),
            'Attendance records' => SessionAttendance::query()->count(),
            'Assignments released' => AssignmentInstance::query()->count(),
            'Assignment submissions' => AssignmentSubmission::query()->count(),
            'Day plan items' => DayPlanItem::query()->count(),
            'Time grid entries' => TimeGridEntry::query()->count(),
            'MMD entries' => MmdEntry::query()->count(),
            'MMD targets' => MmdTarget::query()->count(),
            'Fund plans' => FundPlan::query()->count(),
            'Fund plan lines' => FundPlanLine::query()->count(),
            'Action items' => ActionItem::query()->count(),
            'Org chart positions' => Position::query()->count(),
            'HR policies' => HrPolicy::query()->count(),
            'Notes' => Note::query()->count(),
            'Documents' => Document::query()->count(),
            'Notification dispatches' => NotificationDispatch::query()->count(),
            'External form links' => AccessGrant::query()->count(),
            'AI prompt versions' => AiPromptVersion::query()->count(),
            'Report artifacts' => ReportArtifact::query()->count(),
        ];
    }
}
