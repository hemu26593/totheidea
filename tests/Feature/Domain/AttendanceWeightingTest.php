<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Sessions\AttendanceCalculator;
use App\Domain\Sessions\Contracts\AttendanceWeighting;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The 90% completion rule.
 *
 * [CLIENT DECISION - ATTENDANCE WEIGHTING]
 *
 * The shape of both calculations is settled and implemented in
 * AttendanceCalculator. Their INPUTS are not: whether `late` counts toward the
 * numerator, and whether `excused` stays in the denominator, are unanswered.
 * The two are materially different - removing an excused session RAISES the
 * percentage, counting it as absent LOWERS it - and the figure gates a warning
 * sent to the participant and the consultant.
 *
 * So the tests that would pin the numbers are WRITTEN AND SKIPPED. They are
 * written now because writing them later, against an implementation, invites
 * tests that agree with whatever the code happens to do. Skipped because
 * asserting a number today would enshrine an invented rule.
 *
 * The three tests that are NOT skipped assert the deliberate absence: no
 * implementation, no binding, no default.
 */
#[Group('client-decision')]
class AttendanceWeightingTest extends TestCase
{
    use RefreshDatabase;

    private const DECISION = '[CLIENT DECISION - ATTENDANCE WEIGHTING] '
        .'Unresolved: how `late` and `excused` affect the numerator and the denominator '
        .'of the 90% rule. No default is assumed.';

    // --- What IS asserted today -------------------------------------------

    #[Test]
    public function the_weighting_contract_has_no_implementation(): void
    {
        $implementations = array_filter(
            get_declared_classes(),
            fn (string $class): bool => is_subclass_of($class, AttendanceWeighting::class),
        );

        $this->assertSame(
            [],
            array_values($implementations),
            'An AttendanceWeighting implementation would decide a contractual completion figure '
            .'that the client has not decided.'
        );
    }

    #[Test]
    public function the_weighting_contract_is_not_bound_in_the_container(): void
    {
        // Resolving it must fail loudly rather than fall back to a plausible
        // default - a wrong 90% figure is worse than a missing one.
        $this->expectException(BindingResolutionException::class);

        app(AttendanceWeighting::class);
    }

    #[Test]
    public function the_calculator_cannot_be_constructed_without_the_clients_answer(): void
    {
        $this->expectException(BindingResolutionException::class);

        app(AttendanceCalculator::class);
    }

    #[Test]
    public function the_threshold_itself_is_settled(): void
    {
        // 90% is from the SOW. It is the WEIGHTING that is open, not the bar.
        $this->assertSame(0.90, AttendanceCalculator::COMPLETION_THRESHOLD);
    }

    // --- Written and skipped ----------------------------------------------

    #[Test]
    public function a_late_arrival_counts_toward_the_numerator_as_the_client_decides(): void
    {
        $this->markTestSkipped(self::DECISION);
    }

    #[Test]
    public function an_excused_absence_is_either_removed_from_the_total_or_counted_against_it(): void
    {
        $this->markTestSkipped(self::DECISION);
    }

    #[Test]
    public function the_current_percentage_is_attended_over_sessions_held_so_far(): void
    {
        $this->markTestSkipped(self::DECISION);
    }

    #[Test]
    public function the_warning_fires_when_the_best_possible_final_figure_drops_below_ninety(): void
    {
        // Trigger 8. Strictly earlier than the current percentage crossing the
        // line, which is the whole point of a reachability warning.
        $this->markTestSkipped(self::DECISION);
    }

    #[Test]
    public function the_boundary_cases_of_the_ninety_percent_rule_behave_as_specified(): void
    {
        // Exactly 90%, one session short, and a participant with no marks yet.
        $this->markTestSkipped(self::DECISION);
    }
}
