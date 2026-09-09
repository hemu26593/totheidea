<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Attachments\NoteService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use App\Models\Note;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Consultant notes on a business.
 *
 * INTERNAL BY DEFAULT, AND GATED BY A PERMISSION. is_internal defaults to
 * true; NoteService::visibleToStaff filters by notes.view_internal, so an
 * actor without it never receives an internal note - it is excluded from the
 * query, not hidden by a template condition.
 *
 * AN AUTHOR IS ALWAYS A USER. notes.author_id is NOT NULL and there is no
 * actor triple on this table: an external access grant cannot author a note,
 * by construction rather than by convention. A participant never becomes a
 * note's author.
 */
class Notes extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    public bool $writing = false;

    public string $body = '';

    public bool $internal = true;

    public ?int $editingId = null;

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);
    }

    public function startNote(): void
    {
        $this->authorize('create', Note::class);

        $this->reset(['body', 'editingId']);
        $this->internal = true;
        $this->writing = true;
    }

    public function startEditing(int $noteId): void
    {
        $note = $this->noteInWorkspace($noteId);

        $this->authorize('update', $note);

        $this->editingId = $noteId;
        $this->body = (string) $note->body;
        $this->internal = (bool) $note->is_internal;
        $this->writing = true;
    }

    public function save(NoteService $notes): void
    {
        $this->validate(['body' => ['required', 'string', 'max:20000']]);

        if ($this->editingId !== null) {
            $note = $this->noteInWorkspace($this->editingId);

            $this->authorize('update', $note);

            $saved = $this->runGuarded(
                fn () => $notes->updateBody($note, $this->body, auth()->user()),
                'Note updated.',
            );
        } else {
            $this->authorize('create', Note::class);

            $customer = $this->customer();

            $saved = $this->runGuarded(
                fn () => $notes->create($customer, $this->body, auth()->user(), $this->internal),
                'Note added.',
            );
        }

        if ($saved) {
            $this->writing = false;
            $this->editingId = null;
        }
    }

    public function toggleVisibility(int $noteId, NoteService $notes): void
    {
        $note = $this->noteInWorkspace($noteId);

        $this->authorize('setVisibility', $note);

        $this->runGuarded(
            fn () => $notes->setVisibility($note, ! $note->is_internal, auth()->user()),
            'Visibility updated.',
        );
    }

    public function archive(int $noteId, NoteService $notes): void
    {
        $note = $this->noteInWorkspace($noteId);

        $this->authorize('archive', $note);

        $this->runGuarded(
            fn () => $notes->archive($note, auth()->user()),
            'Note archived.',
        );
    }

    public function render(): View
    {
        $customer = $this->customer();

        return view('livewire.customers.notes', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            // Filtered by the service, which excludes internal notes from an
            // actor without notes.view_internal at the query level.
            'notes' => app(NoteService::class)->visibleToStaff($customer, $customer, auth()->user()),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Notes']);
    }

    private function noteInWorkspace(int $noteId): Note
    {
        $note = Note::query()->findOrFail($noteId);

        if ($note->notable_type !== (new Customer)->getMorphClass()) {
            abort(404);
        }

        $this->assertOwnedByWorkspace((int) $note->notable_id);

        return $note;
    }
}
