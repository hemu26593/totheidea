<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ActorSource;
use App\Enums\BehaviourDimension;
use App\Enums\BusinessFunction;
use App\Enums\DueClassification;
use App\Enums\FundPlanSection;
use App\Enums\GrantAbility;
use App\Enums\NotificationChannel;
use App\Enums\PlanningType;
use App\Enums\ProductivityStage;
use App\Enums\QuestionType;
use App\Enums\ScoringCategory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * The confirmed BMP vocabulary, turned into executable assertions.
 *
 * These are not coverage. Each is a tripwire on a business definition that
 * was expensive to obtain and would be expensive to rediscover: the client
 * had to decode this vocabulary from their own workbooks, and SOW section 9.1
 * called it "the most important page in the document".
 *
 * A contributor who adds a fourth due classification, or quietly maps
 * Average = 1 ... Best = 4, fails here immediately.
 */
class DomainVocabularyTest extends TestCase
{
    #[Test]
    public function due_classification_has_exactly_three_values(): void
    {
        $this->assertSame(['CD', 'LD', 'LLD'], DueClassification::values());
    }

    #[Test]
    public function there_is_no_fourth_due_classification(): void
    {
        // The vocabulary is complete at three. Every reference to a fourth
        // class was removed from the architecture during the Step 3A freeze.
        $this->assertCount(3, DueClassification::cases());

        foreach (['CDD', 'LLLD', 'VLD', 'XD', 'OD', 'ND'] as $invented) {
            $this->assertNull(
                DueClassification::tryFrom($invented),
                "[{$invented}] must not be a due classification."
            );
        }
    }

    #[Test]
    public function planning_type_has_exactly_the_four_confirmed_values(): void
    {
        $this->assertSame(['BFP', 'FBP', 'EBP', 'SM'], PlanningType::values());
    }

    #[Test]
    public function productivity_stages_are_the_five_confirmed_stages_in_order(): void
    {
        $this->assertSame(
            ['Planning', 'Enabling', 'Execution', 'Monitoring', 'Controlling'],
            ProductivityStage::values()
        );

        // The sequence is part of the confirmed definition (Stage 1..Stage 5),
        // not an invented score.
        $this->assertSame([1, 2, 3, 4, 5], array_map(
            static fn (ProductivityStage $stage): int => $stage->sequence(),
            ProductivityStage::cases()
        ));
    }

    #[Test]
    public function behaviour_dimensions_are_dt_cc_pa(): void
    {
        $this->assertSame(['DT', 'CC', 'PA'], BehaviourDimension::values());
    }

    #[Test]
    public function business_function_is_marketing_sales_delivery(): void
    {
        $this->assertSame(['Marketing', 'Sales', 'Delivery'], BusinessFunction::values());
    }

    #[Test]
    public function scoring_category_has_exactly_four_values(): void
    {
        $this->assertSame(['Average', 'Good', 'Better', 'Best'], ScoringCategory::values());
    }

    #[Test]
    public function scoring_category_defines_no_numeric_mapping(): void
    {
        // The client confirmed these as a dropdown with NO numeric mapping.
        // Inventing Average = 1 ... Best = 4 would make a categorical answer
        // look like a score, and would leak into submission_scores.
        $this->assertSame('string', (string) (new ReflectionEnum(ScoringCategory::class))->getBackingType());

        foreach (['score', 'weight', 'points', 'value', 'rank', 'ordinal', 'toInt', 'numericValue'] as $forbidden) {
            $this->assertFalse(
                method_exists(ScoringCategory::class, $forbidden),
                "ScoringCategory must not expose [{$forbidden}]() - that is a numeric mapping."
            );
        }

        foreach (ScoringCategory::cases() as $case) {
            $this->assertFalse(
                is_numeric($case->value),
                "ScoringCategory::{$case->name} must not carry a numeric backing value."
            );
        }
    }

    #[Test]
    public function actor_source_has_exactly_three_values(): void
    {
        $this->assertSame(
            ['internal_user', 'external_grant', 'system'],
            ActorSource::values()
        );
    }

    #[Test]
    public function grant_abilities_are_the_seven_confirmed_capabilities(): void
    {
        $this->assertSame([
            'complete_form',
            'enter_mmd',
            'enter_day_plan',
            'submit_assignment',
            'accept_terms',
            'mark_attendance',
            'view_report',
        ], GrantAbility::values());
    }

    #[Test]
    public function notification_channels_are_email_and_whatsapp(): void
    {
        $this->assertSame(['email', 'whatsapp'], NotificationChannel::values());
    }

    #[Test]
    public function fund_plan_sections_are_the_five_confirmed_sections(): void
    {
        $this->assertSame([
            'fund_in',
            'fund_out',
            'marketing_budget',
            'sales_closing',
            'production',
        ], FundPlanSection::values());
    }

    #[Test]
    public function due_classification_is_meaningful_only_on_fund_in_lines(): void
    {
        $this->assertTrue(FundPlanSection::FundIn->acceptsDueClassification());

        foreach (FundPlanSection::cases() as $section) {
            if ($section !== FundPlanSection::FundIn) {
                $this->assertFalse(
                    $section->acceptsDueClassification(),
                    "[{$section->value}] must not accept a due classification."
                );
            }
        }
    }

    #[Test]
    public function question_types_are_the_eight_confirmed_types(): void
    {
        $this->assertSame([
            'text',
            'textarea',
            'number',
            'date',
            'boolean',
            'select_one',
            'select_many',
            'scale',
        ], QuestionType::values());
    }

    #[Test]
    public function exactly_eleven_domain_enums_exist(): void
    {
        // Eleven domain enums, plus UserRole and AuditAction from Step 2.
        // A twelfth domain enum means vocabulary was reified that Step 3B
        // deliberately did not model - see the two guard tests below.
        $files = glob(dirname(__DIR__, 3).'/app/Enums/*.php');

        $this->assertCount(13, $files, 'Expected 11 domain enums plus UserRole and AuditAction.');
    }
}
