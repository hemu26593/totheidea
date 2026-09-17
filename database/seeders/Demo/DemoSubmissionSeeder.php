<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Forms\SubmissionService;
use App\Domain\Scoring\ScoringService;
use App\Enums\QuestionType;
use App\Models\Customer;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Question;
use Illuminate\Database\Seeder;

/**
 * Completed and part-completed assessments, with answers that match the
 * questions and scores the application worked out for itself.
 *
 * Every answer goes through SubmissionService, so each one is bound to the
 * published version it was written against and to the enrolment that owns it.
 * Scores are produced by ScoringService from the options actually chosen -
 * there is no score written as a number somebody picked, which means the
 * figures on screen would survive somebody opening the submission and adding
 * up the answers by hand.
 *
 * The businesses answer differently on purpose. A run of identical
 * assessments would make the scored screens look broken rather than populated.
 */
class DemoSubmissionSeeder extends Seeder
{
    /**
     * How mature each business is, 1-4, per skill area. This is what makes one
     * assessment read differently from the next, and it is also what the
     * scores are derived from - so the two can never disagree.
     *
     * @var array<string, int>
     */
    private const MATURITY = [
        'BMP-APEX' => 3,
        'BMP-SHREEJI' => 2,
        'BMP-VARDHAN' => 2,
        'BMP-MERIDIAN' => 3,
        'BMP-ARVIND' => 2,
        'BMP-ZENITH' => 4,
        'BMP-PRAKASH' => 1,
        'BMP-WESTERN' => 1,
        'BMP-SANKALP' => 2,
        'BMP-GIRIRAJ' => 1,
        'BMP-NIRMAN' => 3,
        'BMP-SAMARTH' => 2,
    ];

    /**
     * Which forms each business has reached, and how far. Businesses in the
     * batches that started earlier are further along, which is what makes the
     * Forms screen look like a programme in progress.
     *
     * @var array<string, array<string, string>>
     */
    private const PROGRESS = [
        'BMP-APEX' => ['bmp_growth_current_state' => 'submitted', 'bmp_leadership_people' => 'submitted', 'bmp_business_systems' => 'submitted'],
        'BMP-VARDHAN' => ['bmp_growth_current_state' => 'submitted', 'bmp_leadership_people' => 'submitted'],
        'BMP-ARVIND' => ['bmp_growth_current_state' => 'submitted', 'bmp_business_systems' => 'submitted'],
        'BMP-ZENITH' => ['bmp_growth_current_state' => 'submitted', 'bmp_leadership_people' => 'submitted', 'bmp_business_systems' => 'submitted', 'bmp_financial_growth_planning' => 'submitted'],
        'BMP-SHREEJI' => ['bmp_growth_current_state' => 'submitted', 'bmp_leadership_people' => 'submitted'],
        'BMP-MERIDIAN' => ['bmp_growth_current_state' => 'submitted', 'bmp_business_systems' => 'draft'],
        'BMP-PRAKASH' => ['bmp_growth_current_state' => 'submitted'],
        'BMP-WESTERN' => ['bmp_growth_current_state' => 'draft'],
        'BMP-SANKALP' => ['bmp_growth_current_state' => 'submitted'],
        'BMP-NIRMAN' => ['bmp_growth_current_state' => 'submitted', 'bmp_leadership_people' => 'draft'],
        'BMP-SAMARTH' => ['bmp_growth_current_state' => 'draft'],
    ];

    public function run(): void
    {
        $actor = DemoTeamSeeder::actor();
        $submissions = app(SubmissionService::class);
        $scoring = app(ScoringService::class);

        $templates = FormTemplate::query()->get()->keyBy('key');

        foreach (self::PROGRESS as $code => $forms) {
            $customer = Customer::query()
                ->where('code', $code)
                ->with('enrollments')
                ->first();

            $enrollment = $customer?->enrollments->first();

            if ($enrollment === null) {
                continue;
            }

            foreach ($forms as $formKey => $finalStatus) {
                $template = $templates->get($formKey);
                $version = $template?->publishedVersion();

                if ($version === null) {
                    continue;
                }

                $existing = FormSubmission::query()
                    ->where('enrollment_id', $enrollment->getKey())
                    ->where('form_version_id', $version->getKey())
                    ->first();

                if ($existing !== null) {
                    continue;
                }

                $submission = $submissions->startDraft($enrollment, $version, $actor);

                $questions = $version->questions()->with('options')->orderBy('position')->get();

                // A draft is a form somebody stopped part-way through, so it
                // gets the first questions answered and the rest left blank.
                $answerable = $finalStatus === 'draft'
                    ? $questions->take((int) ceil($questions->count() / 2))
                    : $questions;

                foreach ($answerable as $question) {
                    $this->answer($submissions, $submission, $question, $customer, $code);
                }

                if ($finalStatus !== 'submitted') {
                    continue;
                }

                $submitted = $submissions->submit($submission->fresh(), $actor);

                if ($template->is_scored) {
                    $scoring->score($submitted->fresh());
                }
            }
        }
    }

    private function answer(
        SubmissionService $submissions,
        FormSubmission $submission,
        Question $question,
        Customer $customer,
        string $code,
    ): void {
        $type = $question->type instanceof QuestionType
            ? $question->type
            : QuestionType::from((string) $question->type);

        $options = $question->options;

        // Anything with options is answered by choosing one, which is what
        // carries the score. Picking the option whose value matches the
        // business's maturity keeps the answers and the totals consistent.
        if ($options->isNotEmpty()) {
            if ($type === QuestionType::SelectMany) {
                $chosen = $options->take(2)->all();
            } else {
                $maturity = (string) (self::MATURITY[$code] ?? 2);

                $chosen = [$options->firstWhere('value', $maturity) ?? $options->first()];
            }

            $submissions->answer($submission, $question, [], $chosen);

            return;
        }

        $value = match ($type) {
            QuestionType::Number => ['value_number' => $this->number($question, $code)],
            QuestionType::Date => ['value_date' => now()->subDays(12)->toDateString()],
            QuestionType::Boolean => ['value_boolean' => (self::MATURITY[$code] ?? 2) >= 3],
            default => ['value_text' => $this->text($question, $customer, $code)],
        };

        $submissions->answer($submission, $question, $value);
    }

    private function number(Question $question, string $code): float
    {
        $business = collect(DemoDataset::businesses())->firstWhere('code', $code);
        $label = (string) $question->label;
        $maturity = self::MATURITY[$code] ?? 2;

        return match (true) {
            str_contains($label, 'turnover'), str_contains($label, 'Annual revenue') => (float) str_replace(' crore', '', (string) ($business['turnover'] ?? '10')) * 100,
            str_contains($label, 'employees') => (float) ($business['headcount'] ?? 50),
            str_contains($label, 'Gross margin') => 22.0 + ($maturity * 3.5),
            str_contains($label, 'operating expenses') => round(((float) ($business['headcount'] ?? 50)) * 0.42, 2),
            str_contains($label, 'collection period') => (float) (110 - ($maturity * 16)),
            str_contains($label, 'beyond 90 days') => round((float) (40 - ($maturity * 8)), 2),
            str_contains($label, 'quotation turnaround') => (float) (9 - $maturity),
            str_contains($label, 'revenue target') => (float) str_replace(' crore', '', (string) ($business['turnover'] ?? '10')) * 135,
            default => (float) ($maturity * 10),
        };
    }

    private function text(Question $question, Customer $customer, string $code): string
    {
        $business = collect(DemoDataset::businesses())->firstWhere('code', $code);
        $label = (string) $question->label;

        return match (true) {
            str_contains($label, 'Company name') => (string) $customer->name,
            str_contains($label, 'business model') => sprintf(
                '%s. Owner-managed, supplying industrial customers across Gujarat on a mix of repeat schedules and project orders.',
                (string) ($business['industry'] ?? 'Manufacturing'),
            ),
            str_contains($label, 'Primary products') => (string) ($business['industry'] ?? 'Manufactured components'),
            str_contains($label, 'customer segments') => 'OEMs, EPC contractors and a small aftermarket trade.',
            str_contains($label, 'enquiries come from') => 'Repeat customers, referrals from existing buyers, and two long-standing channel partners. No paid enquiry generation.',
            str_contains($label, 'sales process') => 'Enquiry by phone or email, costed by the owner, quotation issued, then follow-up when somebody remembers.',
            str_contains($label, 'operational bottlenecks') => (string) ($business['constraint'] ?? 'Process is undocumented.'),
            str_contains($label, 'removed tomorrow') => (string) ($business['constraint'] ?? 'The owner is the bottleneck.'),
            str_contains($label, 'growth objective') => (string) ($business['objective'] ?? 'Grow turnover by a third.'),
            str_contains($label, 'leadership bottleneck') => 'Decisions wait for the owner, so nothing moves in the week he is travelling.',
            str_contains($label, 'training or capability gap') => 'Nobody other than the owner can price a non-standard job.',
            str_contains($label, 'documented, would save') => 'Enquiry-to-quotation. It is the one everybody is waiting on.',
            str_contains($label, 'additional working capital') => 'Clear the raw material backlog and stop paying premiums for urgent purchases.',
            str_contains($label, 'ninety-day target') => (string) ($business['objective'] ?? 'Establish weekly review.'),
            str_contains($label, 'know you have reached it') => 'The weekly dashboard shows it for four consecutive weeks without anybody chasing the numbers.',
            str_contains($label, 'Priority 1') => 'Document the enquiry-to-quotation process — owned by the General Manager.',
            str_contains($label, 'Priority 2') => 'Weekly sales review every Monday — owned by the Sales Head.',
            str_contains($label, 'Priority 3') => 'Receivables review each fortnight — owned by Accounts.',
            str_contains($label, 'get in the way') => 'The first busy month. The review gets skipped, and then it never comes back.',
            str_contains($label, 'support do you need') => 'A template for the weekly dashboard, and someone to sit in on the first two reviews.',
            default => 'Discussed with the management team during the session and agreed as written.',
        };
    }
}
