<?php

declare(strict_types=1);

namespace App\Domain\Attachments;

use App\Domain\Shared\SubjectOwnership;
use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * All write operations on documents, and the reads that carry a visibility
 * boundary.
 *
 * Ownership is resolved from the subject on every operation. A caller may not
 * assert which customer a document belongs to; the service works it out.
 */
class DocumentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SubjectOwnership $ownership,
    ) {}

    /**
     * Attach a file to a subject, recorded as an internal staff upload.
     *
     * @param  array{disk?: string, path: string, original_name: string, mime_type: string, size_bytes: int, checksum_sha256?: string|null}  $attributes
     */
    public function attachByStaff(
        Model $subject,
        array $attributes,
        User $actor,
        bool $internal = true,
    ): Document {
        // Resolves the owning customer, and refuses a subject whose ownership
        // cannot be established.
        $this->ownership->customerIdFor($subject);

        return $this->store($subject, $attributes, $internal, ActorSource::InternalUser, $actor, null);
    }

    /**
     * Attach a file that arrived through a scoped external grant.
     *
     * The grant's customer must own the subject; a grant for one business can
     * never attach a file to another's record.
     */
    public function attachByGrant(
        Model $subject,
        array $attributes,
        AccessGrant $grant,
        bool $internal = false,
    ): Document {
        $this->ownership->assertBelongsTo($subject, $grant->customer);

        return $this->store($subject, $attributes, $internal, ActorSource::ExternalGrant, null, $grant);
    }

    public function archive(Document $document, User $actor): Document
    {
        return DB::transaction(function () use ($document, $actor): Document {
            // The stored file is deliberately NOT removed: a delivered
            // document must stay retrievable.
            $document->forceFill(['archived_at' => now()])->save();

            $this->audit->log(
                AuditAction::CustomerUpdated,
                $document,
                ['archived_at' => null],
                ['archived_at' => $document->archived_at],
                $actor,
            );

            return $document->fresh();
        });
    }

    /**
     * Documents attached to a subject, for an internal reader.
     *
     * The subject must belong to the customer the caller named - this is
     * where a manipulated id is caught.
     *
     * @return Collection<int, Document>
     */
    public function forSubject(Model $subject, Customer $customer): Collection
    {
        $this->ownership->assertBelongsTo($subject, $customer);

        return Document::query()
            ->where('documentable_type', $subject->getMorphClass())
            ->where('documentable_id', $subject->getKey())
            ->get();
    }

    /**
     * Documents attached to a subject, for an EXTERNAL reader.
     *
     * Internal documents are excluded structurally. There is no argument that
     * can widen this, and no user is involved.
     *
     * @return Collection<int, Document>
     */
    public function forSubjectExternally(Model $subject, AccessGrant $grant): Collection
    {
        $this->ownership->assertBelongsTo($subject, $grant->customer);

        return Document::query()
            ->externallyVisible()
            ->where('documentable_type', $subject->getMorphClass())
            ->where('documentable_id', $subject->getKey())
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(
        Model $subject,
        array $attributes,
        bool $internal,
        ActorSource $source,
        ?User $actor,
        ?AccessGrant $grant,
    ): Document {
        return DB::transaction(function () use ($subject, $attributes, $internal, $source, $actor, $grant): Document {
            $document = new Document($attributes);
            $document->forceFill([
                'documentable_type' => $subject->getMorphClass(),
                'documentable_id' => $subject->getKey(),
                'is_internal' => $internal,
                'source' => $source,
                'created_by' => $actor?->getKey(),
                'access_grant_id' => $grant?->getKey(),
            ])->save();

            $this->audit->log(
                AuditAction::CustomerUpdated,
                $document,
                null,
                [
                    'documentable_type' => $subject->getMorphClass(),
                    'documentable_id' => $subject->getKey(),
                    'original_name' => $document->original_name,
                    'is_internal' => $internal,
                ],
                $actor,
                $source,
                $grant,
            );

            return $document->fresh();
        });
    }
}
