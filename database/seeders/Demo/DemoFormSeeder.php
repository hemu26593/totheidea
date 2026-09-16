<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Sessions\CurriculumService;
use App\Enums\QuestionType;
use App\Models\FormTemplate;
use App\Models\Program;
use App\Models\SkillArea;
use Illuminate\Database\Seeder;

/**
 * The five programme forms, built through the real form engine.
 *
 * Nothing here inserts a question row directly: templates, versions, sections,
 * questions and options all go through FormBuilderService, and each version is
 * published through FormPublishingService. A form assembled any other way
 * would look right in the database and then refuse to render, or refuse to
 * accept a submission, which is worse than no demo data at all.
 *
 * Two of the five are scored. Scoring here means what it means everywhere else
 * in this application: options carry score values, questions carry a max score
 * and a skill area, and ScoringService derives the totals. No score is written
 * as a number somebody chose.
 *
 * Forms 1 and 5 are attached to their sessions in the curriculum, so the
 * session screens show the form that belongs to them.
 */
class DemoFormSeeder extends Seeder
{
    public function run(): void
    {
        $actor = DemoTeamSeeder::actor();
        $builder = app(FormBuilderService::class);
        $publishing = app(FormPublishingService::class);

        $skillAreas = SkillArea::query()->pluck('id', 'key');

        foreach ($this->forms() as $definition) {
            if (FormTemplate::query()->where('key', $definition['key'])->exists()) {
                continue;
            }

            $template = $builder->createTemplate([
                'customer_id' => null,
                'key' => $definition['key'],
                'name' => $definition['name'],
                'description' => $definition['description'],
                'is_scored' => $definition['scored'],
            ]);

            $version = $builder->createDraftVersion($template);
            $position = 0;

            foreach ($definition['sections'] as $sectionIndex => $section) {
                $formSection = $builder->addSection(
                    $version,
                    $section['title'],
                    $sectionIndex,
                    $section['description'] ?? null,
                );

                foreach ($section['questions'] as $question) {
                    $attributes = ['is_required' => $question['required'] ?? false];

                    if (isset($question['skill_area'])) {
                        $attributes['skill_area_id'] = $skillAreas[$question['skill_area']] ?? null;
                    }

                    if (isset($question['max_score'])) {
                        $attributes['max_score'] = $question['max_score'];
                    }

                    if (isset($question['help'])) {
                        $attributes['help_text'] = $question['help'];
                    }

                    $created = $builder->addQuestion(
                        $version,
                        $formSection,
                        $question['type'],
                        $question['label'],
                        $position++,
                        $attributes,
                    );

                    foreach ($question['options'] ?? [] as $optionIndex => $option) {
                        $builder->addOption(
                            $created,
                            $option['value'],
                            $option['label'],
                            $optionIndex,
                            $option['score'] ?? null,
                        );
                    }
                }
            }

            $publishing->publish($version->fresh(), $actor);
        }

        $this->attachFormsToSessions();
    }

    /**
     * Put the intake form on session 1 and the action plan on session 6, which
     * is where a consultant would expect to find each of them.
     */
    private function attachFormsToSessions(): void
    {
        $program = Program::query()->where('code', 'BMP')->first();

        if ($program === null) {
            return;
        }

        $curriculum = app(CurriculumService::class);
        $actor = DemoTeamSeeder::actor();

        $pairs = [
            1 => 'bmp_growth_current_state',
            4 => 'bmp_leadership_people',
            5 => 'bmp_business_systems',
            6 => 'bmp_ninety_day_action_plan',
        ];

        foreach ($pairs as $sequence => $formKey) {
            $session = $program->sessionTemplates()->where('sequence', $sequence)->first();
            $form = FormTemplate::query()->where('key', $formKey)->first();

            if ($session === null || $form === null) {
                continue;
            }

            if ($session->templateForms()->where('form_template_id', $form->getKey())->exists()) {
                continue;
            }

            $curriculum->attachForm($session, $form, true, 0, $actor);
        }
    }

    /**
     * @return list<array{key: string, name: string, description: string, scored: bool, sections: list<array<string, mixed>>}>
     */
    private function forms(): array
    {
        return [
            $this->growthAndCurrentState(),
            $this->leadershipAndPeople(),
            $this->businessSystems(),
            $this->financialAndGrowthPlanning(),
            $this->ninetyDayActionPlan(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function growthAndCurrentState(): array
    {
        return [
            'key' => 'bmp_growth_current_state',
            'name' => 'Business Growth & Current State Assessment',
            'description' => 'The starting picture: what the business sells, what it earns, and what is actually holding it back.',
            'scored' => false,
            'sections' => [
                [
                    'title' => 'About the business',
                    'description' => 'The facts we will measure everything else against.',
                    'questions' => [
                        ['type' => QuestionType::Text, 'label' => 'Company name', 'required' => true],
                        ['type' => QuestionType::Textarea, 'label' => 'Describe your business model in your own words', 'required' => true],
                        ['type' => QuestionType::Number, 'label' => 'Current annual turnover (INR lakhs)', 'required' => true, 'help' => 'Last completed financial year.'],
                        ['type' => QuestionType::Number, 'label' => 'Total employees, including contract staff', 'required' => true],
                        ['type' => QuestionType::Textarea, 'label' => 'Primary products or services', 'required' => true],
                        ['type' => QuestionType::Textarea, 'label' => 'Main customer segments'],
                    ],
                ],
                [
                    'title' => 'How growth happens today',
                    'questions' => [
                        ['type' => QuestionType::Textarea, 'label' => 'Where do your enquiries come from today?', 'required' => true],
                        ['type' => QuestionType::Textarea, 'label' => 'Describe your sales process from enquiry to order'],
                        ['type' => QuestionType::SelectOne, 'label' => 'How often does the management team review performance?', 'required' => true, 'options' => [
                            ['value' => 'daily', 'label' => 'Daily'],
                            ['value' => 'weekly', 'label' => 'Weekly'],
                            ['value' => 'monthly', 'label' => 'Monthly'],
                            ['value' => 'rarely', 'label' => 'Rarely or only when there is a problem'],
                        ]],
                        ['type' => QuestionType::SelectMany, 'label' => 'Which areas are currently constraining growth?', 'options' => [
                            ['value' => 'sales', 'label' => 'Sales and enquiry generation'],
                            ['value' => 'capacity', 'label' => 'Production capacity'],
                            ['value' => 'people', 'label' => 'People and capability'],
                            ['value' => 'cash', 'label' => 'Cash and working capital'],
                            ['value' => 'systems', 'label' => 'Systems and process'],
                        ]],
                    ],
                ],
                [
                    'title' => 'The constraint',
                    'questions' => [
                        ['type' => QuestionType::Textarea, 'label' => 'What are the top three operational bottlenecks?', 'required' => true],
                        ['type' => QuestionType::Textarea, 'label' => 'If one constraint were removed tomorrow, which would it be?', 'required' => true],
                        ['type' => QuestionType::Textarea, 'label' => 'What is your twelve-month growth objective?', 'required' => true],
                        ['type' => QuestionType::Date, 'label' => 'Date this assessment was completed'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadershipAndPeople(): array
    {
        return [
            'key' => 'bmp_leadership_people',
            'name' => 'Leadership & People Assessment',
            'description' => 'Structure, delegation and accountability — scored across the people and strategy areas.',
            'scored' => true,
            'sections' => [
                [
                    'title' => 'Structure and delegation',
                    'questions' => [
                        ['type' => QuestionType::Scale, 'label' => 'How clearly is responsibility divided across your management team?', 'required' => true, 'skill_area' => 'people', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Scale, 'label' => 'How much of the day-to-day decision making sits with the owner?', 'required' => true, 'skill_area' => 'people', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Scale, 'label' => 'How well does accountability survive when the owner is away?', 'skill_area' => 'people', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Boolean, 'label' => 'Does a written delegation matrix exist?'],
                    ],
                ],
                [
                    'title' => 'Capability and performance',
                    'questions' => [
                        ['type' => QuestionType::Scale, 'label' => 'How consistently is individual performance reviewed?', 'skill_area' => 'people', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Scale, 'label' => 'How well does the business hire and retain the skills it needs?', 'skill_area' => 'people', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Scale, 'label' => 'How ready is the second line to take on more?', 'skill_area' => 'strategy', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Textarea, 'label' => 'What is the single biggest leadership bottleneck today?', 'required' => true],
                        ['type' => QuestionType::Textarea, 'label' => 'Which training or capability gap costs you most?'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function businessSystems(): array
    {
        return [
            'key' => 'bmp_business_systems',
            'name' => 'Business Systems & Process Assessment',
            'description' => 'What is documented, what is remembered, and what is simply hoped for — scored by area.',
            'scored' => true,
            'sections' => [
                [
                    'title' => 'Sales and enquiry systems',
                    'questions' => [
                        ['type' => QuestionType::Scale, 'label' => 'How well is enquiry handling documented and followed?', 'required' => true, 'skill_area' => 'sales', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Scale, 'label' => 'How consistent is your quotation process?', 'required' => true, 'skill_area' => 'sales', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Scale, 'label' => 'How reliable is sales follow-up?', 'skill_area' => 'sales', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        // Deliberately no free numeric question on a scored form.
                        // ScoringService adds value_number into the raw total, so a
                        // number with no max_score of its own would push the score
                        // above its own maximum. Operational numbers live on the
                        // unscored assessments, where they mean what they say.
                        ['type' => QuestionType::Textarea, 'label' => 'How long does a quotation currently take, and what holds it up?'],
                    ],
                ],
                [
                    'title' => 'Operations systems',
                    'questions' => [
                        ['type' => QuestionType::Scale, 'label' => 'How well is production tracked against plan?', 'required' => true, 'skill_area' => 'operations', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Scale, 'label' => 'How accurate is your inventory position?', 'skill_area' => 'operations', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Scale, 'label' => 'How controlled is the purchase process?', 'skill_area' => 'operations', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Scale, 'label' => 'How dependable is despatch against promised dates?', 'skill_area' => 'operations', 'max_score' => 4, 'options' => $this->maturityOptions()],
                    ],
                ],
                [
                    'title' => 'Management reporting',
                    'questions' => [
                        ['type' => QuestionType::Scale, 'label' => 'How complete is your management reporting?', 'required' => true, 'skill_area' => 'systems', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Scale, 'label' => 'How much of your reporting is produced without manual effort?', 'skill_area' => 'systems', 'max_score' => 4, 'options' => $this->maturityOptions()],
                        ['type' => QuestionType::Textarea, 'label' => 'Which process, if documented, would save you the most time?'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financialAndGrowthPlanning(): array
    {
        return [
            'key' => 'bmp_financial_growth_planning',
            'name' => 'Financial & Growth Planning Assessment',
            'description' => 'Margin, cash and the money behind the growth plan.',
            'scored' => false,
            'sections' => [
                [
                    'title' => 'Current financial position',
                    'questions' => [
                        ['type' => QuestionType::Number, 'label' => 'Annual revenue (INR lakhs)', 'required' => true],
                        ['type' => QuestionType::Number, 'label' => 'Gross margin (%)', 'required' => true],
                        ['type' => QuestionType::Number, 'label' => 'Monthly operating expenses (INR lakhs)'],
                        ['type' => QuestionType::Number, 'label' => 'Average collection period (days)', 'required' => true],
                        ['type' => QuestionType::Number, 'label' => 'Receivables outstanding beyond 90 days (INR lakhs)'],
                    ],
                ],
                [
                    'title' => 'Visibility and control',
                    'questions' => [
                        ['type' => QuestionType::SelectOne, 'label' => 'How far ahead can you see your cash position?', 'required' => true, 'options' => [
                            ['value' => 'daily', 'label' => 'Day to day only'],
                            ['value' => 'weekly', 'label' => 'About a week'],
                            ['value' => 'monthly', 'label' => 'About a month'],
                            ['value' => 'quarterly', 'label' => 'A quarter or more'],
                        ]],
                        ['type' => QuestionType::Boolean, 'label' => 'Do you know gross margin by product or job?'],
                        ['type' => QuestionType::Boolean, 'label' => 'Is working capital reviewed at a fixed interval?'],
                    ],
                ],
                [
                    'title' => 'Growth and investment',
                    'questions' => [
                        ['type' => QuestionType::Number, 'label' => 'Twelve-month revenue target (INR lakhs)', 'required' => true],
                        ['type' => QuestionType::SelectMany, 'label' => 'Where will investment go over the next year?', 'options' => [
                            ['value' => 'capacity', 'label' => 'Plant and capacity'],
                            ['value' => 'people', 'label' => 'People and training'],
                            ['value' => 'sales', 'label' => 'Sales and marketing'],
                            ['value' => 'technology', 'label' => 'Technology and automation'],
                            ['value' => 'working_capital', 'label' => 'Working capital'],
                        ]],
                        ['type' => QuestionType::Textarea, 'label' => 'What would you do first with additional working capital?'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ninetyDayActionPlan(): array
    {
        return [
            'key' => 'bmp_ninety_day_action_plan',
            'name' => '90-Day Business Growth Action Plan',
            'description' => 'The commitment the programme ends on: what will change, who owns it, and by when.',
            'scored' => false,
            'sections' => [
                [
                    'title' => 'The ninety-day target',
                    'questions' => [
                        ['type' => QuestionType::Textarea, 'label' => 'State your ninety-day target in one sentence', 'required' => true],
                        ['type' => QuestionType::Date, 'label' => 'Target date', 'required' => true],
                        ['type' => QuestionType::Textarea, 'label' => 'How will you know you have reached it?', 'required' => true, 'help' => 'Name the number and where it comes from.'],
                    ],
                ],
                [
                    'title' => 'Priorities and owners',
                    'questions' => [
                        ['type' => QuestionType::Textarea, 'label' => 'Priority 1 — what will change, and who owns it?', 'required' => true],
                        ['type' => QuestionType::Textarea, 'label' => 'Priority 2 — what will change, and who owns it?'],
                        ['type' => QuestionType::Textarea, 'label' => 'Priority 3 — what will change, and who owns it?'],
                        ['type' => QuestionType::SelectOne, 'label' => 'How often will progress be reviewed?', 'required' => true, 'options' => [
                            ['value' => 'weekly', 'label' => 'Weekly'],
                            ['value' => 'fortnightly', 'label' => 'Fortnightly'],
                            ['value' => 'monthly', 'label' => 'Monthly'],
                        ]],
                    ],
                ],
                [
                    'title' => 'What could stop it',
                    'questions' => [
                        ['type' => QuestionType::Textarea, 'label' => 'What is most likely to get in the way?', 'required' => true],
                        ['type' => QuestionType::Textarea, 'label' => 'What support do you need from the programme team?'],
                    ],
                ],
            ],
        ];
    }

    /**
     * The four-point maturity scale, scored 1 to 4.
     *
     * The labels stay descriptive on purpose. The Average / Good / Better /
     * Best mapping is an open client decision, and a demo that put a grade
     * against a number would be answering it on the client's behalf.
     *
     * @return list<array{value: string, label: string, score: float}>
     */
    private function maturityOptions(): array
    {
        return [
            ['value' => '1', 'label' => 'Not in place — handled case by case', 'score' => 1.0],
            ['value' => '2', 'label' => 'Partly in place — depends on who is doing it', 'score' => 2.0],
            ['value' => '3', 'label' => 'Mostly in place — documented and usually followed', 'score' => 3.0],
            ['value' => '4', 'label' => 'Fully in place — measured and reviewed', 'score' => 4.0],
        ];
    }
}
