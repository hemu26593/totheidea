<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Sessions\CurriculumService;
use App\Models\Batch;
use App\Models\Program;
use App\Models\SkillArea;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * The programme itself: skill areas, the six-session curriculum, the
 * assignments that hang off each session, and the batches running it.
 *
 * Batch dates are relative to today, not fixed. A demonstration given in six
 * months should still show a batch part-way through and a batch about to
 * start; hard dates would show a dashboard full of history and nothing due.
 */
class DemoProgramSeeder extends Seeder
{
    public function run(): void
    {
        $actor = DemoTeamSeeder::actor();
        $curriculum = app(CurriculumService::class);

        foreach ($this->skillAreas() as $position => $area) {
            SkillArea::query()->firstOrCreate(
                ['key' => $area['key']],
                ['name' => $area['name'], 'description' => $area['description'], 'position' => $position],
            );
        }

        $program = Program::query()->firstOrCreate(
            ['code' => 'BMP'],
            [
                'name' => 'Business Mastery Programme',
                'description' => 'A six-session programme for owner-managed manufacturing and engineering businesses: '
                    .'establish the current state, choose where growth comes from, and leave with a dated ninety-day plan.',
                'session_count' => count(DemoDataset::curriculum()),
                'status' => 'active',
            ],
        );

        $assignments = DemoDataset::assignments();

        foreach (DemoDataset::curriculum() as $session) {
            $template = $program->sessionTemplates()
                ->where('sequence', $session['sequence'])
                ->first();

            if ($template === null) {
                $template = $curriculum->defineSession(
                    $program,
                    $session['sequence'],
                    $session['title'],
                    $session['theme'],
                    $session['objectives'],
                    $actor,
                );
            }

            foreach ($assignments[$session['sequence']] ?? [] as $position => $assignment) {
                if ($template->assignmentTemplates()->where('title', $assignment['title'])->exists()) {
                    continue;
                }

                $curriculum->defineAssignment(
                    $template,
                    $assignment['title'],
                    $assignment['instructions'],
                    $assignment['due_days'],
                    // The ninety-day plan is the one that must arrive as a
                    // document; the rest are written into the submission.
                    $session['sequence'] === 6,
                    $position,
                    $actor,
                );
            }
        }

        foreach (DemoDataset::batches() as $batch) {
            $startsOn = CarbonImmutable::today()->modify($batch['starts']);

            Batch::query()->firstOrCreate(
                ['code' => $batch['code']],
                [
                    'program_id' => $program->getKey(),
                    'name' => $batch['name'],
                    'starts_on' => $startsOn->toDateString(),
                    // Six sessions, a fortnight apart.
                    'ends_on' => $startsOn->addWeeks(10)->toDateString(),
                    'capacity' => $batch['capacity'],
                    'status' => $batch['status'],
                ],
            );
        }
    }

    /**
     * @return list<array{key: string, name: string, description: string}>
     */
    private function skillAreas(): array
    {
        return [
            ['key' => 'strategy', 'name' => 'Strategy & Growth', 'description' => 'Where the business is going and how it will get there.'],
            ['key' => 'sales', 'name' => 'Sales & Marketing', 'description' => 'Enquiry generation, conversion and customer retention.'],
            ['key' => 'operations', 'name' => 'Operations & Delivery', 'description' => 'Production, quality, despatch and on-time performance.'],
            ['key' => 'people', 'name' => 'People & Leadership', 'description' => 'Structure, delegation, accountability and capability.'],
            ['key' => 'finance', 'name' => 'Finance & Cash', 'description' => 'Margin, cash flow, receivables and working capital.'],
            ['key' => 'systems', 'name' => 'Systems & Process', 'description' => 'Documented process, measurement and management reporting.'],
        ];
    }
}
