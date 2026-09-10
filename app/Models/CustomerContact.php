<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCustomer;
use Database\Factories\CustomerContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person at the customer's business.
 *
 * These are the only delivery address for eight of the nine SOW reminders,
 * which is why consent is recorded per channel.
 *
 * A contact is NOT a user: no credentials, no login, no role. They receive
 * messages and may be issued a scoped access grant, nothing more.
 *
 * Mass-assignment note: is_primary is excluded from fillable. The
 * at-most-one-primary invariant is a count across rows, so it is enforced
 * transactionally by CustomerContactService.
 */
#[Fillable(['customer_id', 'name', 'role_title', 'email', 'phone_e164', 'email_opt_in', 'whatsapp_opt_in'])]
class CustomerContact extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<CustomerContactFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'email_opt_in' => 'boolean',
            'whatsapp_opt_in' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
