<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $customer = Customer::factory();

        return [
            'documentable_type' => (new Customer)->getMorphClass(),
            'documentable_id' => $customer,
            'disk' => 'local',
            'path' => 'documents/'.fake()->uuid().'.pdf',
            'original_name' => 'workbook.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', fake()->uuid()),
            'is_internal' => true,
            'source' => ActorSource::InternalUser,
            'created_by' => User::factory(),
        ];
    }

    public function customerVisible(): static
    {
        return $this->state(fn (): array => ['is_internal' => false]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
