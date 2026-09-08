<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReportArtifactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * A report as DELIVERED: the file, its format, the filters that produced it,
 * and a checksum proving it is the one that was issued.
 *
 * IMMUTABLE. A regenerated report is a NEW artifact; the earlier one is left
 * exactly as it was handed over. The guards are on the model rather than only
 * in ReportPolicy because 'update' is not among
 * authorization.guarded_abilities - Gate::before would grant it to a Super
 * Admin and the policy would never be consulted.
 *
 * NEVER A QUERY SOURCE. Nothing in this application reads a figure back out of
 * an artifact. Reports are queries over operational data; this table records
 * only what was delivered, which is a different question and needs a different
 * answer.
 */
#[Fillable([])]
class ReportArtifact extends Model
{
    /** @use HasFactory<ReportArtifactFactory> */
    use HasFactory;

    public const FORMAT_PDF = 'pdf';

    public const FORMAT_CSV = 'csv';

    /** @var array<int, string> */
    public const FORMATS = [
        self::FORMAT_PDF,
        self::FORMAT_CSV,
    ];

    /**
     * The report vocabulary, exactly as the frozen schema names it.
     *
     * `diagnostic` is part of the vocabulary because the schema declares it;
     * its builder is not part of this phase.
     *
     * @var array<int, string>
     */
    public const REPORT_KEYS = [
        'diagnostic',
        'participant_progress',
        'batch_summary',
        'attendance_register',
        'assignment_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'A report artifact is immutable. Regenerating a report produces a new artifact, '
                .'so what was delivered before stays exactly as it was delivered.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'A report artifact cannot be deleted. It is the record of what was handed over.'
            );
        });
    }

    /**
     * The enrolment or batch this report is about. Polymorphic, so no foreign
     * key; ownership is resolved through SubjectOwnership before generation.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function isPdf(): bool
    {
        return $this->format === self::FORMAT_PDF;
    }

    public function isCsv(): bool
    {
        return $this->format === self::FORMAT_CSV;
    }
}
