<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Domain\Notifications\NotificationDispatchService;
use App\Domain\Notifications\NotificationScheduler;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\NotificationDispatch;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * What the system has tried to send, and what stopped it.
 *
 * THIS SCREEN DOES NOT SCHEDULE ANYTHING. Eligibility, timing and deduplication
 * belong to NotificationScheduler, which runs on a schedule; duplicating any of
 * that here would mean two answers to "should this go out?" and a second
 * message to the customer. The only write offered is a RETRY of a dispatch that
 * already exists and already failed - and even that re-queues the same row
 * rather than creating a new one, so the dedupe key still holds.
 *
 * The trigger list is shown with what each is waiting on, because a trigger
 * blocked by an unresolved client decision is the most common reason nothing
 * is being sent - and that is worth seeing rather than guessing at.
 *
 * Retry and suppression change what a customer receives, so they need
 * notifications.manage. Staff hold notifications.view and read this screen
 * without those controls.
 */
class Index extends Component
{
    use ReportsDomainFailures;
    use WithPagination;

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $trigger = 'all';

    public function mount(): void
    {
        $this->authorize('viewAny', NotificationDispatch::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Re-queue a failed dispatch.
     *
     * The same row, so its dedupe key is unchanged and the customer cannot
     * receive a duplicate as a result of retrying.
     */
    public function retry(int $dispatchId): void
    {
        $dispatch = NotificationDispatch::query()->findOrFail($dispatchId);

        $this->authorize('manage', $dispatch);

        if ($dispatch->status !== NotificationDispatch::STATUS_FAILED) {
            $this->addError('domain', 'Only a failed dispatch can be retried.');

            return;
        }

        $this->runGuarded(function () use ($dispatch): void {
            $dispatch->forceFill(['status' => NotificationDispatch::STATUS_PENDING])->save();

            app(NotificationDispatchService::class)->enqueue($dispatch->fresh());
        }, 'Dispatch re-queued.');
    }

    public function render(): View
    {
        $scheduler = app(NotificationScheduler::class);

        return view('livewire.notifications.index', [
            'dispatches' => $this->results(),
            'statuses' => NotificationDispatch::STATUSES,
            'triggers' => $this->triggerStates($scheduler),
            'summary' => NotificationDispatch::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
        ])->layout('components.layouts.app', ['title' => 'Notifications']);
    }

    /**
     * @return LengthAwarePaginator<int, NotificationDispatch>
     */
    private function results(): LengthAwarePaginator
    {
        return NotificationDispatch::query()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->trigger !== 'all', fn ($q) => $q->where('trigger_key', $this->trigger))
            ->latest('scheduled_for')
            ->paginate(25);
    }

    /**
     * Each trigger, and what it is waiting on.
     *
     * unresolvedDependency() is the trigger's own answer, not this screen's
     * guess: a trigger blocked on an open client decision says so itself.
     *
     * @return array<int, array<string, mixed>>
     */
    private function triggerStates(NotificationScheduler $scheduler): array
    {
        return array_map(
            fn ($trigger): array => [
                'key' => $trigger->key(),
                'blocked' => $trigger->unresolvedDependency(),
            ],
            $scheduler->triggers(),
        );
    }
}
