<?php

declare(strict_types=1);

namespace App\Domain\Attachments;

use App\Domain\Shared\SubjectOwnership;
use App\Enums\AuditAction;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\Note;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * All write operations on notes, and the two reads that define the visibility
 * boundary.
 *
 * The boundary is enforced HERE, in the query, not in a template. A Blade
 * "can" directive hides a button; it does not stop a note being loaded and
 * returned.
 *
 * Note the shape of the API: every write requires a User. There is no method
 * that accepts an AccessGrant, so an external actor cannot author a note -
 * that is the structural half of "notes are internal by construction".
 */
class NoteService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SubjectOwnership $ownership,
    ) {}

    /**
     * Author a note against a subject.
     *
     * $internal defaults to true. A caller wanting a customer-visible note
     * must say so explicitly, so the accident is over-restriction.
     */
    public function create(Model $subject, string $body, User $author, bool $internal = true): Note
    {
        $this->ownership->customerIdFor($subject);

        return DB::transaction(function () use ($subject, $body, $author, $internal): Note {
            $note = new Note(['body' => $body]);
            $note->forceFill([
                'notable_type' => $subject->getMorphClass(),
                'notable_id' => $subject->getKey(),
                'is_internal' => $internal,
                'author_id' => $author->getKey(),
            ])->save();

            $this->audit->log(
                AuditAction::CustomerUpdated,
                $note,
                null,
                [
                    'notable_type' => $subject->getMorphClass(),
                    'notable_id' => $subject->getKey(),
                    'is_internal' => $internal,
                ],
                $author,
            );

            return $note->fresh();
        });
    }

    public function updateBody(Note $note, string $body, User $actor): Note
    {
        return DB::transaction(function () use ($note, $body, $actor): Note {
            $before = ['body' => $note->body];

            $note->fill(['body' => $body])->save();

            $this->audit->logChanges(AuditAction::CustomerUpdated, $note, $before, ['body' => $body], $actor);

            return $note->fresh();
        });
    }

    /**
     * Change a note's visibility.
     *
     * Always audited, in both directions: making a private consultant note
     * customer-visible is exactly the change someone would later need to
     * account for.
     */
    public function setVisibility(Note $note, bool $internal, User $actor): Note
    {
        return DB::transaction(function () use ($note, $internal, $actor): Note {
            $before = ['is_internal' => $note->is_internal];

            $note->forceFill(['is_internal' => $internal])->save();

            $this->audit->log(
                AuditAction::CustomerUpdated,
                $note,
                $before,
                ['is_internal' => $internal],
                $actor,
            );

            return $note->fresh();
        });
    }

    public function archive(Note $note, User $actor): Note
    {
        return DB::transaction(function () use ($note, $actor): Note {
            $note->forceFill(['archived_at' => now()])->save();

            $this->audit->log(AuditAction::CustomerUpdated, $note, null, ['archived' => true], $actor);

            return $note->fresh();
        });
    }

    /**
     * Notes on a subject, for an internal reader.
     *
     * Internal notes are included ONLY if the actor holds notes.view_internal.
     * This is the server-side authorization boundary: a member of staff
     * without that permission does not receive the rows at all, rather than
     * receiving them and being asked not to render them.
     *
     * @return Collection<int, Note>
     */
    public function visibleToStaff(Model $subject, Customer $customer, User $actor): Collection
    {
        $this->ownership->assertBelongsTo($subject, $customer);

        return Note::query()
            ->where('notable_type', $subject->getMorphClass())
            ->where('notable_id', $subject->getKey())
            ->unless(
                $actor->can('notes.view_internal'),
                fn ($query) => $query->where('is_internal', false),
            )
            ->get();
    }

    /**
     * Notes on a subject, for an EXTERNAL reader.
     *
     * Internal notes are excluded structurally. There is no permission, no
     * argument and no actor that widens this - which is what SOW section 6 means
     * by "built in, not a setting".
     *
     * @return Collection<int, Note>
     */
    public function visibleExternally(Model $subject, AccessGrant $grant): Collection
    {
        $this->ownership->assertBelongsTo($subject, $grant->customer);

        return Note::query()
            ->externallyVisible()
            ->where('notable_type', $subject->getMorphClass())
            ->where('notable_id', $subject->getKey())
            ->get();
    }
}
