<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Batch;
use App\Models\CustomerContact;
use App\Models\SessionInstance;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who a notification goes to.
 *
 * There are exactly two kinds of recipient - a customer contact, or an
 * internal user. PARTICIPANTS HAVE NO ACCOUNT, so there is no third kind, and
 * no notification path can create one.
 *
 * Contact resolution follows the index the contacts table was built with: the
 * primary contact is the default recipient for a business. The fallback to the
 * oldest contact exists so a business that never designated a primary still
 * receives its reminders rather than silently receiving nothing.
 *
 * A contact with no email address yields no recipient. That is a gap in the
 * customer's record, not a suppression: there is no address to snapshot onto a
 * dispatch, so there is no send to record.
 */
class RecipientResolver
{
    public function primaryContactFor(int $customerId): ?CustomerContact
    {
        return CustomerContact::query()
            ->where('customer_id', $customerId)
            ->whereNull('archived_at')
            ->whereNotNull('email')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();
    }

    /**
     * The internal users responsible for a batch.
     *
     * Resolved from who actually conducts its sessions, which is the only
     * recorded link between a batch and a member of staff. There is no
     * Consultant role and no "owner" column, and inventing either to address a
     * reminder would be inventing an organisational rule.
     *
     * @return Collection<int, User>
     */
    public function internalUsersForBatch(Batch $batch): Collection
    {
        $userIds = SessionInstance::query()
            ->where('batch_id', $batch->getKey())
            ->whereNotNull('conducted_by')
            ->distinct()
            ->pluck('conducted_by');

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function internalUsersForSession(SessionInstance $instance): Collection
    {
        if ($instance->conducted_by === null) {
            return collect();
        }

        return User::query()
            ->whereKey($instance->conducted_by)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();
    }
}
