<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer is confirmed from the moment they exist.
     *
     * The client has confirmed there are no prospects awaiting onboarding, so
     * `prospect` describes a stage this business does not have. Leaving it in
     * place would leave rows wearing a badge that invites somebody to go
     * looking for an onboarding step that no longer exists.
     *
     * Two changes, both narrow:
     *
     *  - The column default becomes `active`, so a row written outside
     *    CustomerService (a factory, a direct create) is not born into a
     *    stage the business does not run.
     *  - Rows still sitting at `prospect` move to `active`.
     *
     * The second rewrites existing records, which is normally the thing not to
     * do - the reason it is done here is that the business has stated those
     * records are confirmed customers, so the stored value is wrong rather
     * than historical. ARCHIVED CUSTOMERS ARE DELIBERATELY EXCLUDED: archival
     * is a real end state and reviving one would be a genuine loss of history.
     * No row is deleted and no other column is touched.
     *
     * down() restores the previous default. It does not put anybody back to
     * `prospect`, because there is no record of which rows were there.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->change();
        });

        DB::table('customers')
            ->where('status', 'prospect')
            ->whereNull('archived_at')
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('status', 16)->default('prospect')->change();
        });
    }
};
