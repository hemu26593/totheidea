<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one AI invocation stands (Step 3B, table 41).
 *
 * THE STAGES ARE LOAD-BEARING AND ARE NOT COLLAPSED. Succeeded means the
 * provider returned something that validated against the prompt's schema.
 * AwaitingApproval means a human has something to look at. Approved means a
 * human who is NOT the generator signed it off. Nothing published skips a
 * stage, because each stage is a different question with a different answer.
 *
 * Failed is a terminal, retained state: a failure is provenance too.
 */
enum AiGenerationStatus: string
{
    /** Recorded before the provider is called, so a crash mid-call is visible. */
    case Pending = 'pending';

    /** The provider answered and the output validated against the schema. */
    case Succeeded = 'succeeded';

    /** The provider refused, errored, or returned output that did not validate. */
    case Failed = 'failed';

    /** A draft exists and a human decision is outstanding. */
    case AwaitingApproval = 'awaiting_approval';

    case Approved = 'approved';

    case Rejected = 'rejected';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * A decision has been recorded and nothing further happens to this row.
     */
    public function isDecided(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }
}
