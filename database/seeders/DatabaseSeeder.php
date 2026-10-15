<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What a real deployment needs before anybody signs in: the permission matrix,
 * the first administrator, and the client's own questionnaire.
 *
 * The questionnaire is reference content rather than demonstration data - it is
 * the instrument every participant is actually given - so it is seeded here and
 * not only by the demo pipeline. It runs last because publishing it is an
 * audited act that has to be attributed to the Super Admin created above, and
 * it is idempotent, so running `db:seed` again publishes nothing twice.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SuperAdminSeeder::class,
            ParticipantQuestionnaireSeeder::class,
        ]);
    }
}
