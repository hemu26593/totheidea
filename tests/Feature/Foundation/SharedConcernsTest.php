<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Domain\Shared\Concerns\BelongsToCustomer;
use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The two shared traits, exercised as Eloquent model behaviour.
 *
 * The scratch table below exists only for the lifetime of a test. It is not a
 * migration and is not part of the application schema - Phase 0 deliberately
 * creates no BMP tables. It exists because model events cannot be observed
 * without something to save to.
 */
class SharedConcernsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scratch_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('source', 16)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('access_grant_id')->nullable();
            $table->timestamps();
        });
    }

    // --- HasActorTriple ---------------------------------------------------

    #[Test]
    public function a_coherent_internal_write_saves(): void
    {
        $record = ScratchActorRecord::create([
            'source' => ActorSource::InternalUser,
            'created_by' => 1,
        ]);

        $this->assertTrue($record->exists);
    }

    #[Test]
    public function a_write_claiming_two_actors_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        ScratchActorRecord::create([
            'source' => ActorSource::ExternalGrant,
            'created_by' => 1,
            'access_grant_id' => 2,
        ]);
    }

    #[Test]
    public function a_write_with_no_actor_at_all_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        ScratchActorRecord::create(['source' => ActorSource::InternalUser]);
    }

    #[Test]
    public function an_externally_written_row_is_identifiable(): void
    {
        $record = ScratchActorRecord::create([
            'source' => ActorSource::ExternalGrant,
            'access_grant_id' => 9,
        ]);

        $this->assertTrue($record->wasWrittenExternally());
    }

    // --- BelongsToCustomer ------------------------------------------------

    #[Test]
    public function a_customer_owned_record_cannot_be_created_without_an_owner(): void
    {
        $this->expectException(RuntimeException::class);

        ScratchCustomerRecord::create([]);
    }

    #[Test]
    public function customer_id_is_never_reassigned(): void
    {
        $record = ScratchCustomerRecord::create(['customer_id' => 1]);

        $this->expectException(RuntimeException::class);

        $record->update(['customer_id' => 2]);
    }

    #[Test]
    public function queries_can_be_constrained_to_one_customer(): void
    {
        ScratchCustomerRecord::create(['customer_id' => 1]);
        ScratchCustomerRecord::create(['customer_id' => 1]);
        ScratchCustomerRecord::create(['customer_id' => 2]);

        $this->assertSame(2, ScratchCustomerRecord::forCustomer(1)->count());
        $this->assertSame(1, ScratchCustomerRecord::forCustomer(2)->count());
    }
}

class ScratchActorRecord extends Model
{
    use HasActorTriple;

    protected $table = 'scratch_records';

    protected $fillable = ['customer_id', 'source', 'created_by', 'access_grant_id'];

    protected function casts(): array
    {
        return ['source' => ActorSource::class];
    }
}

class ScratchCustomerRecord extends Model
{
    use BelongsToCustomer;

    protected $table = 'scratch_records';

    protected $fillable = ['customer_id', 'source', 'created_by', 'access_grant_id'];
}
