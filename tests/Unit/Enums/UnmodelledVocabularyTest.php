<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Vocabulary that is CONFIRMED but deliberately NOT modelled (Step 3B).
 *
 * Three confirmed terms have no enum, table, column, field or score, and that
 * is a decision rather than an omission:
 *
 *   T / Th / S   Tuesday / Thursday / Saturday. Weekday columns on the paper
 *                MMD sheet, not measures. The storage grain stays one row per
 *                day; T/Th/S is a DERIVED weekly presentation over those rows.
 *
 *   G / C / M    Three Day Plan columns after Tasks. The count is settled at
 *                three; the individual labels are an open client decision
 *                (S1) and are carried verbatim, never expanded.
 *
 *   1 - 3 - 5    1 year / 3 years / 5 years. The definition is closed; its
 *                platform usage is undefined by the requirements and is
 *                therefore deferred (L1) rather than guessed.
 *
 * This test guards against a well-meaning contributor "completing" the model
 * by reifying any of them.
 */
class UnmodelledVocabularyTest extends TestCase
{
    private function appSource(): string
    {
        return dirname(__DIR__, 3).'/app';
    }

    /**
     * @return array<int, string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->appSource(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    #[Test]
    public function g_c_m_are_never_expanded_into_individual_labels(): void
    {
        // The client supplied a GROUP-level meaning only. Mapping each letter
        // to its own word is an open decision (S1). Guessing it would name a
        // Day Plan column wrongly and then have to be migrated.
        $forbidden = [
            "'Growth'",
            "'Customer Management'",
            'case Growth',
            'case Management',
            'GrowthColumn',
            'DayPlanDimension',
        ];

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "G / C / M must stay unexpanded; found [{$needle}] in ".basename($file)
                );
            }
        }
    }

    #[Test]
    public function the_one_three_five_growth_model_is_not_reified(): void
    {
        // Definition closed at 1 year / 3 years / 5 years. Usage deferred.
        // No table, column, field or score is invented for it.
        $this->assertFileDoesNotExist($this->appSource().'/Enums/GrowthHorizon.php');
        $this->assertFileDoesNotExist($this->appSource().'/Enums/GrowthModel.php');

        foreach ($this->sourceFiles() as $file) {
            $this->assertStringNotContainsString(
                'OneThreeFive',
                (string) file_get_contents($file),
                'The 1-3-5 growth model must remain unmodelled until its usage is defined (L1).'
            );
        }
    }

    #[Test]
    public function weekdays_are_not_stored_as_mmd_columns(): void
    {
        // T / Th / S is a derived weekly view over daily rows. An enum here
        // would invite a weekday column on mmd_entries, which would change the
        // storage grain the SOW actually specifies ("daily entry").
        $this->assertFileDoesNotExist($this->appSource().'/Enums/MmdWeekday.php');
        $this->assertFileDoesNotExist($this->appSource().'/Enums/ReviewWeekday.php');
    }
}
