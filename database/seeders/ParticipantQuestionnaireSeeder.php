<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\ParticipantQuestionnaire;
use App\Domain\Forms\QuestionVisibility;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The Participant Business Analysis Questionnaire, by Raajul Parekh.
 *
 * This is the client's own instrument - twelve sections and forty-nine
 * questions, in the order and the words the source document uses. It is
 * reference content, not demonstration data: it belongs in a real deployment,
 * which is why it is seeded from DatabaseSeeder alongside roles and the first
 * administrator rather than from the demo pipeline alone.
 *
 * EVERY ROW GOES THROUGH THE FORM ENGINE. Template, version, sections,
 * questions and options are all written by FormBuilderService and the version
 * is published by FormPublishingService. A form assembled by inserting rows
 * would look correct in the database and then refuse to render or refuse a
 * submission, and the audit entry for its publication would be missing.
 *
 * IDEMPOTENT, and cheaply so: a published questionnaire is left exactly as it
 * is. That is not just to avoid duplicates - a published version is immutable
 * because submissions bind to it as the record of what was asked, so rewriting
 * one is not a thing this seeder is permitted to do. Changing the instrument
 * means authoring the next version, which is what the Form templates screen is
 * for.
 *
 * WHAT IT DELIBERATELY DOES NOT DO:
 *
 *   - score anything. The instrument's last section is titled "Business
 *     Readiness Score", and its two questions are categorical with no numeric
 *     mapping defined anywhere by the client. The template is therefore not
 *     scored and no option carries a score value; inventing Stable = 1 ...
 *     Expansion Ready = 4 would be answering an open client decision.
 *   - mark as required anything the source document does not. The document
 *     marks nothing. Only Section 1 is required here, because those eight
 *     answers are what attribute the rest to a business; everything else can
 *     be left blank rather than blocking a submission.
 */
class ParticipantQuestionnaireSeeder extends Seeder
{
    public function run(): void
    {
        $existing = FormTemplate::query()
            ->whereNull('customer_id')
            ->where('key', ParticipantQuestionnaire::KEY)
            ->first();

        if ($existing !== null && $existing->publishedVersion() !== null) {
            $this->command?->info('Participant Business Analysis Questionnaire already published — skipping.');

            return;
        }

        $actor = $this->actor();

        if ($actor === null) {
            $this->command?->warn(
                'No user exists to attribute the questionnaire to. Run `php artisan db:seed` '
                .'(which creates the Super Admin) and this seeder will publish it.'
            );

            return;
        }

        $builder = app(FormBuilderService::class);

        $template = $existing ?? $builder->createTemplate([
            // Shared curriculum: one instrument, answered by every business.
            // Not a per-customer template, so there is nothing to duplicate
            // when a customer is added.
            'customer_id' => null,
            'key' => ParticipantQuestionnaire::KEY,
            'name' => 'Business Mastery Program (BMP) — Participant Business Analysis Questionnaire',
            'description' => 'By Raajul Parekh.',
            'is_scored' => false,
        ]);

        $version = $builder->createDraftVersion($template);

        $this->build($builder, $version);

        app(FormPublishingService::class)->publish($version->fresh(), $actor);

        $this->command?->info(sprintf(
            'Participant Business Analysis Questionnaire published: %d sections, %d questions.',
            $version->sections()->count(),
            $version->questions()->count(),
        ));
    }

    /**
     * Write the instrument into a draft version.
     *
     * Position is a single counter across the whole version, because
     * questions.position is UNIQUE per version - and because it is the order
     * the document asks them in, which is the order they must be answered in.
     */
    private function build(FormBuilderService $builder, FormVersion $version): void
    {
        $position = 0;

        /** @var array<string, Question> $created */
        $created = [];

        foreach ($this->sections() as $index => $section) {
            $formSection = $builder->addSection($version, $section['title'], $index);

            foreach ($section['questions'] as $question) {
                $created[$question['key']] = $this->addQuestion(
                    $builder,
                    $version,
                    $formSection,
                    $question,
                    $position++,
                    $created,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, Question>  $created
     */
    private function addQuestion(
        FormBuilderService $builder,
        FormVersion $version,
        FormSection $section,
        array $question,
        int $position,
        array $created,
    ): Question {
        $attributes = [
            // The question bank key: the same logical question recognised
            // across instruments. Deliberately not unique.
            'bank_key' => $question['key'],
            'is_required' => $question['required'] ?? false,
        ];

        if (isset($question['help'])) {
            $attributes['help_text'] = $question['help'];
        }

        // A conditional question names the one that reveals it. The controlling
        // question is always written first, so its id is already known - and it
        // stays known, because a published version is immutable.
        if (isset($question['reveal'])) {
            $controlling = $created[$question['reveal']['key']];

            $attributes['visible_when'] = QuestionVisibility::whenSelected(
                $controlling,
                ...$question['reveal']['values'],
            );
        }

        $model = $builder->addQuestion(
            $version,
            $section,
            $question['type'],
            $question['label'],
            $position,
            $attributes,
        );

        foreach ($question['options'] ?? [] as $optionIndex => $option) {
            // No score value, on any option. See the class note.
            $builder->addOption($model, $option['value'], $option['label'], $optionIndex);
        }

        return $model;
    }

    /**
     * The account the publication is attributed to.
     *
     * A Super Admin if there is one, because publishing is an administrative
     * act and the audit entry should say so. Any user otherwise - the entry
     * naming somebody is worth more than no entry at all.
     */
    private function actor(): ?User
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', UserRole::SuperAdmin->value))
            ->orderBy('id')
            ->first()
            ?? User::query()->orderBy('id')->first();
    }

    /**
     * The instrument: twelve sections, in the document's order and wording.
     *
     * @return list<array{title: string, questions: list<array<string, mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title' => 'Basic Business Details',
                'questions' => [
                    ['key' => 'full_name', 'type' => QuestionType::Text, 'label' => 'Full Name', 'required' => true],
                    ['key' => 'business_name', 'type' => QuestionType::Text, 'label' => 'Business Name', 'required' => true],
                    ['key' => 'business_category', 'type' => QuestionType::Text, 'label' => 'Business Category / Industry', 'required' => true],
                    ['key' => 'years_in_business', 'type' => QuestionType::SelectOne, 'label' => 'Years in Business', 'required' => true, 'options' => [
                        ['value' => 'under_1', 'label' => 'Less than 1 year'],
                        ['value' => '1_3', 'label' => '1–3 years'],
                        ['value' => '3_7', 'label' => '3–7 years'],
                        ['value' => '7_plus', 'label' => '7+'],
                    ]],
                    ['key' => 'business_location', 'type' => QuestionType::Text, 'label' => 'Business Location', 'required' => true],
                    ['key' => 'team_size', 'type' => QuestionType::SelectOne, 'label' => 'Team Size', 'required' => true, 'options' => [
                        ['value' => 'only_me', 'label' => 'Only Me'],
                        ['value' => '2_5', 'label' => '2–5'],
                        ['value' => '6_15', 'label' => '6–15'],
                        ['value' => '15_plus', 'label' => '15+'],
                    ]],
                    ['key' => 'annual_turnover', 'type' => QuestionType::SelectOne, 'label' => 'Current Annual Turnover Range', 'required' => true, 'options' => [
                        ['value' => '1_2_cr', 'label' => '₹1–2 Cr'],
                        ['value' => '2_3_cr', 'label' => '₹2–3 Cr'],
                        ['value' => '3_5_cr', 'label' => '₹3–5 Cr'],
                        ['value' => '5_7_cr', 'label' => '₹5–7 Cr'],
                        ['value' => '7_10_cr', 'label' => '₹7–10 Cr'],
                        ['value' => '10_cr_plus', 'label' => '₹10 Cr+'],
                    ]],
                    ['key' => 'main_products_services', 'type' => QuestionType::Text, 'label' => 'Main Products / Services', 'required' => true],
                ],
            ],
            [
                'title' => 'Vision & Goals',
                'questions' => [
                    ['key' => 'business_vision', 'type' => QuestionType::Textarea, 'label' => 'Business Vision (3–5 years)'],
                    // The document asks for three goals on three numbered
                    // lines. Three questions, not one box: each goal is then a
                    // value of its own that a report can read, which one
                    // free-text field would make impossible.
                    ['key' => 'goal_1', 'type' => QuestionType::Text, 'label' => 'Top 3 goals for next 12 months — 1', 'help' => 'One goal per line, in the order you would tackle them.'],
                    ['key' => 'goal_2', 'type' => QuestionType::Text, 'label' => 'Top 3 goals for next 12 months — 2'],
                    ['key' => 'goal_3', 'type' => QuestionType::Text, 'label' => 'Top 3 goals for next 12 months — 3'],
                    ['key' => 'why_join_bmp', 'type' => QuestionType::Textarea, 'label' => 'Why would you like to join BMP?'],
                ],
            ],
            [
                'title' => 'Challenges & Bottlenecks',
                'questions' => [
                    ['key' => 'current_challenges', 'type' => QuestionType::SelectMany, 'label' => 'Current Challenges (Tick)', 'options' => [
                        ['value' => 'sales', 'label' => 'Sales'],
                        ['value' => 'leads', 'label' => 'Leads'],
                        ['value' => 'marketing', 'label' => 'Marketing'],
                        ['value' => 'team', 'label' => 'Team'],
                        ['value' => 'profit', 'label' => 'Profit'],
                        ['value' => 'systems', 'label' => 'Systems'],
                        ['value' => 'time', 'label' => 'Time'],
                        ['value' => 'retention', 'label' => 'Retention'],
                        ['value' => 'operations', 'label' => 'Operations'],
                    ]],
                    ['key' => 'biggest_bottleneck', 'type' => QuestionType::Textarea, 'label' => 'Biggest bottleneck stopping growth'],
                ],
            ],
            [
                'title' => 'Sales & Customers',
                'questions' => [
                    ['key' => 'ideal_customer', 'type' => QuestionType::Textarea, 'label' => 'Who is your ideal customer?'],
                    ['key' => 'main_sales_source', 'type' => QuestionType::SelectOne, 'label' => 'Main Sales Source', 'options' => [
                        ['value' => 'referrals', 'label' => 'Referrals'],
                        ['value' => 'walk_ins', 'label' => 'Walk-ins'],
                        ['value' => 'online', 'label' => 'Online'],
                        ['value' => 'corporate', 'label' => 'Corporate'],
                        ['value' => 'dealers', 'label' => 'Dealers'],
                    ]],
                    ['key' => 'orders_per_month', 'type' => QuestionType::Number, 'label' => 'Average customers/orders per month', 'help' => 'A number. Give your best estimate for a typical month.'],
                    // Text, not a number: owners answer this as a range or
                    // with a currency symbol, and a numeric field would force
                    // them to pick one figure they do not have.
                    ['key' => 'average_order_value', 'type' => QuestionType::Text, 'label' => 'Average Order Value / Ticket Size', 'help' => 'For example ₹25,000, or a range.'],
                    ['key' => 'dedicated_sales_team', 'type' => QuestionType::Boolean, 'label' => 'Do you have a dedicated sales team?'],
                    ['key' => 'track_sales_performance', 'type' => QuestionType::Textarea, 'label' => 'How do you track sales performance?'],
                ],
            ],
            [
                'title' => 'Marketing & Lead System',
                'questions' => [
                    ['key' => 'generate_leads', 'type' => QuestionType::Textarea, 'label' => 'How do you generate leads currently?'],
                    ['key' => 'best_platform', 'type' => QuestionType::SelectMany, 'label' => 'Best Performing Platform', 'options' => [
                        ['value' => 'instagram', 'label' => 'Instagram'],
                        ['value' => 'linkedin', 'label' => 'LinkedIn'],
                        ['value' => 'google', 'label' => 'Google'],
                        ['value' => 'whatsapp', 'label' => 'WhatsApp'],
                        ['value' => 'offline_networking', 'label' => 'Offline Networking'],
                        ['value' => 'other', 'label' => 'Other'],
                    ]],
                    // The document's "Other: ______". Shown only when Other is
                    // ticked, and never required: a question nobody can see
                    // must not be able to block a submission.
                    [
                        'key' => 'best_platform_other',
                        'type' => QuestionType::Text,
                        'label' => 'Other — please specify the platform',
                        'reveal' => ['key' => 'best_platform', 'values' => ['other']],
                    ],
                    ['key' => 'structured_marketing_plan', 'type' => QuestionType::Boolean, 'label' => 'Do you have a structured marketing plan?'],
                    ['key' => 'marketing_budget', 'type' => QuestionType::SelectOne, 'label' => 'Monthly Marketing Budget', 'options' => [
                        ['value' => 'zero', 'label' => '₹0'],
                        ['value' => '10k_30k', 'label' => '₹10k–₹30k'],
                        ['value' => '30k_75k', 'label' => '₹30k–₹75k'],
                        ['value' => '75k_2l', 'label' => '₹75k–₹2L'],
                        ['value' => '2l_plus', 'label' => '₹2L+'],
                    ]],
                ],
            ],
            [
                'title' => 'Operations & Systems',
                'questions' => [
                    ['key' => 'work_execution', 'type' => QuestionType::Textarea, 'label' => 'How is work executed/delivered in your business?'],
                    // Three answers, so not a yes/no: "Partially" is a real
                    // position and the document offers it.
                    ['key' => 'has_sops', 'type' => QuestionType::SelectOne, 'label' => 'Do you have SOPs?', 'options' => [
                        ['value' => 'yes', 'label' => 'Yes'],
                        ['value' => 'no', 'label' => 'No'],
                        ['value' => 'partially', 'label' => 'Partially'],
                    ]],
                    ['key' => 'owner_dependent_activities', 'type' => QuestionType::Textarea, 'label' => 'Which activities depend completely on you?'],
                ],
            ],
            [
                'title' => 'Team & Delegation',
                'questions' => [
                    ['key' => 'team_structure', 'type' => QuestionType::Textarea, 'label' => 'Current Team Structure / Key Roles'],
                    ['key' => 'delegated_successfully', 'type' => QuestionType::Textarea, 'label' => 'Tasks you delegate successfully'],
                    ['key' => 'delegation_struggles', 'type' => QuestionType::Textarea, 'label' => 'Tasks you struggle to delegate'],
                    ['key' => 'team_meetings', 'type' => QuestionType::SelectOne, 'label' => 'Do you conduct regular team meetings?', 'options' => [
                        ['value' => 'weekly', 'label' => 'Weekly'],
                        ['value' => 'monthly', 'label' => 'Monthly'],
                        ['value' => 'rarely', 'label' => 'Rarely'],
                        ['value' => 'never', 'label' => 'Never'],
                    ]],
                ],
            ],
            [
                'title' => 'Finance & Profitability',
                'questions' => [
                    ['key' => 'track_profits_monthly', 'type' => QuestionType::Boolean, 'label' => 'Do you track profits monthly?'],
                    ['key' => 'profit_margin_range', 'type' => QuestionType::SelectOne, 'label' => 'Current Profit Margin Range', 'options' => [
                        ['value' => 'under_10', 'label' => 'Less than 10%'],
                        ['value' => '10_20', 'label' => '10–20%'],
                        ['value' => '20_35', 'label' => '20–35%'],
                        ['value' => '35_plus', 'label' => '35%+'],
                        ['value' => 'not_sure', 'label' => 'Not Sure'],
                    ]],
                    ['key' => 'recurring_expenses', 'type' => QuestionType::Textarea, 'label' => 'Major recurring business expenses'],
                ],
            ],
            [
                'title' => 'Automation Readiness',
                'questions' => [
                    ['key' => 'tools_used', 'type' => QuestionType::SelectMany, 'label' => 'Tools/Software used', 'options' => [
                        ['value' => 'crm', 'label' => 'CRM'],
                        ['value' => 'accounting', 'label' => 'Accounting'],
                        ['value' => 'erp', 'label' => 'ERP'],
                        ['value' => 'hr_system', 'label' => 'HR System'],
                        ['value' => 'excel_only', 'label' => 'Excel Only'],
                        ['value' => 'none', 'label' => 'None'],
                    ]],
                    ['key' => 'system_level', 'type' => QuestionType::SelectOne, 'label' => 'Current system level', 'options' => [
                        ['value' => 'fully_systemized', 'label' => 'Fully Systemized'],
                        ['value' => 'partially', 'label' => 'Partially'],
                        ['value' => 'unstructured', 'label' => 'Unstructured'],
                    ]],
                    ['key' => 'business_autopilot_meaning', 'type' => QuestionType::Textarea, 'label' => 'What does Business Autopilot mean to you?'],
                ],
            ],
            [
                'title' => 'Owner Lifestyle & Expectations',
                'questions' => [
                    ['key' => 'daily_working_hours', 'type' => QuestionType::SelectOne, 'label' => 'Daily working hours', 'options' => [
                        ['value' => '4_6', 'label' => '4–6'],
                        ['value' => '6_9', 'label' => '6–9'],
                        ['value' => '9_12', 'label' => '9–12'],
                        ['value' => '12_plus', 'label' => '12+'],
                    ]],
                    ['key' => 'stress_level', 'type' => QuestionType::SelectOne, 'label' => 'Stress Level', 'options' => [
                        ['value' => 'daily_overloaded', 'label' => 'Daily Overloaded'],
                        ['value' => 'weekly_stress', 'label' => 'Weekly Stress'],
                        ['value' => 'sometimes', 'label' => 'Sometimes'],
                        ['value' => 'rarely', 'label' => 'Rarely'],
                    ]],
                    ['key' => 'fifteen_days_off', 'type' => QuestionType::SelectOne, 'label' => 'If you take 15 days off, will business run smoothly?', 'options' => [
                        ['value' => 'yes', 'label' => 'Yes'],
                        ['value' => 'no', 'label' => 'No'],
                        ['value' => 'not_sure', 'label' => 'Not Sure'],
                    ]],
                ],
            ],
            [
                'title' => 'Growth Opportunities & Program Expectation',
                'questions' => [
                    ['key' => 'growth_opportunities', 'type' => QuestionType::Textarea, 'label' => 'Future growth opportunities you want to explore'],
                    ['key' => 'area_needing_improvement', 'type' => QuestionType::SelectOne, 'label' => 'Area needing maximum improvement', 'options' => [
                        ['value' => 'sales', 'label' => 'Sales'],
                        ['value' => 'marketing', 'label' => 'Marketing'],
                        ['value' => 'team', 'label' => 'Team'],
                        ['value' => 'systems', 'label' => 'Systems'],
                        ['value' => 'finance', 'label' => 'Finance'],
                        ['value' => 'scale_strategy', 'label' => 'Scale Strategy'],
                    ]],
                    ['key' => 'ready_to_implement', 'type' => QuestionType::Boolean, 'label' => 'Are you ready to implement changes immediately?'],
                    ['key' => 'successful_result', 'type' => QuestionType::Textarea, 'label' => 'What result will make BMP successful for you?'],
                    ['key' => 'support_expected', 'type' => QuestionType::Textarea, 'label' => 'Any specific support expected from Raajul Parekh?'],
                ],
            ],
            [
                // Carried verbatim as a heading. NOTHING on this form is
                // scored: these two are categorical, and the client has
                // defined no numeric mapping for either.
                'title' => 'Business Readiness Score',
                'questions' => [
                    ['key' => 'business_stage', 'type' => QuestionType::SelectOne, 'label' => 'Business Stage', 'options' => [
                        ['value' => 'stable', 'label' => 'Stable'],
                        ['value' => 'growing', 'label' => 'Growing'],
                        ['value' => 'scaling', 'label' => 'Scaling'],
                        ['value' => 'expansion_ready', 'label' => 'Expansion Ready'],
                    ]],
                    ['key' => 'self_leadership_level', 'type' => QuestionType::SelectOne, 'label' => 'Self Leadership Level', 'options' => [
                        ['value' => 'operator', 'label' => 'Operator'],
                        ['value' => 'manager', 'label' => 'Manager'],
                        ['value' => 'leader', 'label' => 'Leader'],
                        ['value' => 'visionary', 'label' => 'Visionary'],
                    ]],
                ],
            ],
        ];
    }
}
