<?php

declare(strict_types=1);

namespace App\Domain\Trackers;

use App\Enums\AuditAction;
use App\Enums\DueClassification;
use App\Enums\FundPlanSection;
use App\Enums\PlanningType;
use App\Models\Enrollment;
use App\Models\FundPlan;
use App\Models\FundPlanLine;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The monthly fund plan.
 *
 * PLANNING, NOT ACCOUNTING. Nothing here posts a ledger entry, raises an
 * invoice, takes a payment or reconciles a bank feed. A fund plan records what
 * a business intends to take in and pay out, and what happened against that
 * intention.
 *
 * The vocabulary is the client's and is used exactly as given:
 *
 *   BFP  Basic Finance Planning
 *   FBP  For-cast base planning
 *   EBP  External Base Planning
 *   SM   Strategic Management
 *   CD   Current Due      LD  Long Due      LLD  Long Long Due
 *   BIP  Business in pipeline - a LINE LABEL under sales_closing, not a column
 *
 * INVARIANT I6: due_classification is set ONLY on a fund_in line. An aging
 * classification on a production line means nothing, and a CHECK for a
 * cross-column condition is not portably expressible, so it is enforced here
 * and proved by a test.
 *
 * APPROVAL IS ITS OWN PERMISSION. Staff holds fund_plans.manage but not
 * fund_plans.approve. That separation is what makes approval mean something,
 * so this service refuses to approve without it rather than trusting the
 * caller to have checked.
 */
class FundPlanService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function createMonth(
        Enrollment $enrollment,
        int $year,
        int $month,
        ?float $budgetTotal = null,
        ?User $actor = null,
    ): FundPlan {
        $this->assertMonthIsValid($month);

        if ($budgetTotal !== null && $budgetTotal < 0) {
            throw new InvalidArgumentException('A month budget cannot be negative.');
        }

        return DB::transaction(function () use ($enrollment, $year, $month, $budgetTotal, $actor): FundPlan {
            $plan = new FundPlan;
            $plan->forceFill([
                'enrollment_id' => $enrollment->getKey(),
                'year' => $year,
                'month' => $month,
                'status' => FundPlan::STATUS_DRAFT,
                'budget_total' => $budgetTotal,
                'created_by' => $actor?->getKey(),
            ])->save();

            return $plan->fresh();
        });
    }

    /**
     * Add a line to a month's plan.
     *
     * Three independent dimensions: where it sits, how it was planned, how
     * aged the receivable is.
     */
    public function addLine(
        FundPlan $plan,
        FundPlanSection $section,
        ?PlanningType $planningType = null,
        ?DueClassification $dueClassification = null,
        ?string $label = null,
        ?int $weekNumber = null,
        ?float $plannedAmount = null,
        ?float $actualAmount = null,
        int $position = 0,
    ): FundPlanLine {
        $this->assertPlanIsOpen($plan);
        $this->assertDueClassificationIsAllowed($section, $dueClassification);
        $this->assertWeekIsValid($weekNumber);
        $this->assertAmountsAreNotNegative($plannedAmount, $actualAmount);

        return DB::transaction(function () use (
            $plan, $section, $planningType, $dueClassification, $label,
            $weekNumber, $plannedAmount, $actualAmount, $position
        ): FundPlanLine {
            $line = new FundPlanLine;
            $line->forceFill([
                'fund_plan_id' => $plan->getKey(),
                'section' => $section,
                'planning_type' => $planningType,
                'due_classification' => $dueClassification,
                'label' => $label,
                // NULL is a month-level line, 1-5 a weekly one. That is how
                // weekly money in and out is held without a third table.
                'week_number' => $weekNumber,
                'planned_amount' => $plannedAmount,
                'actual_amount' => $actualAmount,
                'position' => $position,
            ])->save();

            return $line->fresh();
        });
    }

    public function updateLine(FundPlanLine $line, array $attributes): FundPlanLine
    {
        $plan = $line->fundPlan()->first();

        if ($plan !== null) {
            $this->assertPlanIsOpen($plan);
        }

        $allowed = array_intersect_key($attributes, array_flip([
            'planning_type', 'due_classification', 'label',
            'week_number', 'planned_amount', 'actual_amount', 'position',
        ]));

        $section = $line->section;
        $due = array_key_exists('due_classification', $allowed)
            ? $allowed['due_classification']
            : $line->due_classification;

        $this->assertDueClassificationIsAllowed(
            $section,
            $due instanceof DueClassification ? $due : ($due === null ? null : DueClassification::from((string) $due)),
        );
        $this->assertWeekIsValid($allowed['week_number'] ?? $line->week_number);
        $this->assertAmountsAreNotNegative(
            $allowed['planned_amount'] ?? null,
            $allowed['actual_amount'] ?? null,
        );

        return DB::transaction(function () use ($line, $allowed): FundPlanLine {
            $line->forceFill($allowed)->save();

            return $line->fresh();
        });
    }

    /**
     * Approve a month.
     *
     * Requires fund_plans.approve. Staff does not hold it, and this refusal is
     * in the service rather than only in the policy so that no caller - a job,
     * a console command, a future controller - can approve by forgetting to
     * authorize.
     */
    public function approve(FundPlan $plan, User $actor): FundPlan
    {
        if (! $actor->can('fund_plans.approve')) {
            throw new RuntimeException(sprintf(
                'User %d cannot approve a fund plan: fund_plans.approve is required, and approval '
                .'is deliberately separate from fund_plans.manage.',
                $actor->getKey(),
            ));
        }

        if ($plan->isApproved()) {
            throw new RuntimeException("Fund plan {$plan->getKey()} is already approved.");
        }

        return DB::transaction(function () use ($plan, $actor): FundPlan {
            $plan->forceFill([
                'status' => FundPlan::STATUS_APPROVED,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $fresh = $plan->fresh();

            $this->audit->log(
                AuditAction::FundPlanApproved,
                $fresh,
                ['status' => FundPlan::STATUS_DRAFT],
                [
                    'status' => FundPlan::STATUS_APPROVED,
                    'year' => $fresh->year,
                    'month' => $fresh->month,
                ],
                $actor,
            );

            return $fresh;
        });
    }

    /**
     * @return array<string, array<int, FundPlanLine>>
     */
    public function bySection(FundPlan $plan): array
    {
        $grouped = [];

        foreach (FundPlanSection::cases() as $section) {
            $grouped[$section->value] = $plan->lines()
                ->where('section', $section->value)
                ->get()
                ->all();
        }

        return $grouped;
    }

    /**
     * "All Long Due across months" - the query the classification dimension
     * exists to make possible.
     *
     * @return Collection<int, FundPlanLine>
     */
    public function linesByDueClassification(Enrollment $enrollment, DueClassification $classification)
    {
        return FundPlanLine::query()
            ->whereIn('fund_plan_id', FundPlan::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->select('id'))
            ->where('due_classification', $classification->value)
            ->orderBy('fund_plan_id')
            ->orderBy('position')
            ->get();
    }

    /**
     * INVARIANT I6.
     */
    private function assertDueClassificationIsAllowed(
        FundPlanSection $section,
        ?DueClassification $classification,
    ): void {
        if ($classification === null) {
            return;
        }

        if (! $section->acceptsDueClassification()) {
            throw new InvalidArgumentException(sprintf(
                'A due classification (%s) is meaningful only on a %s line; this line sits in %s. '
                .'Aging describes a receivable, not a cost or a production figure.',
                $classification->value,
                FundPlanSection::FundIn->value,
                $section->value,
            ));
        }
    }

    /**
     * An approved month is a record of what was agreed. Changing its lines
     * afterwards would make the approval meaningless.
     */
    private function assertPlanIsOpen(FundPlan $plan): void
    {
        if ($plan->isApproved()) {
            throw new RuntimeException(sprintf(
                'Fund plan %d is approved; its lines are the record of what was approved and do '
                .'not change.',
                $plan->getKey(),
            ));
        }
    }

    private function assertMonthIsValid(int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Month must be between 1 and 12; got [{$month}].");
        }
    }

    private function assertWeekIsValid(?int $week): void
    {
        if ($week !== null && ($week < 1 || $week > 5)) {
            throw new InvalidArgumentException(
                "A week number is 1-5, or NULL for a month-level line; got [{$week}]."
            );
        }
    }

    private function assertAmountsAreNotNegative(?float $planned, ?float $actual): void
    {
        foreach (['planned_amount' => $planned, 'actual_amount' => $actual] as $name => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException("{$name} cannot be negative.");
            }
        }
    }
}
