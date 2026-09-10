<?php

declare(strict_types=1);

use App\Http\Controllers\External\ExternalFormController;
use App\Livewire;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes — the internal staff application
|--------------------------------------------------------------------------
|
| Authentication routes (login, logout, password reset) are registered by
| Fortify. Registration is NOT among them: Features::registration() is omitted
| from config/fortify.php, so no /register route exists to reach (ADR-007).
| There is no customer login anywhere in this file, and there is no route that
| would create one — external participation is through scoped access grants.
|
| Every route below is authenticated. EnsureUserIsActive is appended to the web
| middleware group in bootstrap/app.php, so it also applies here — a route
| cannot be added without it by accident.
|
| The `can:` middleware is the COARSE FIRST LINE ONLY. It answers "may this
| actor open this kind of screen at all"; it says nothing about which
| customer's data they are reaching. Each Livewire component re-authorizes the
| specific record on mount and re-authorizes every action it exposes, because a
| public Livewire method is an HTTP endpoint whatever the rendered page offers.
|
*/

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'active'])->group(function (): void {

    Route::get('/dashboard', Livewire\Dashboard\Index::class)->name('dashboard');

    /*
    | Customers, and the workspace that hangs off one.
    |
    | There is deliberately no DELETE route: customer removal is archival
    | (ADR-011), there is no customers.delete permission, and
    | CustomerPolicy::delete returns false.
    */
    Route::prefix('customers')->name('customers.')->group(function (): void {
        Route::get('/', Livewire\Customers\Index::class)
            ->middleware('can:customers.view')->name('index');

        Route::get('/create', Livewire\Customers\ManageCustomer::class)
            ->middleware('can:customers.create')->name('create');

        Route::get('/{customer}/edit', Livewire\Customers\ManageCustomer::class)
            ->middleware('can:customers.edit')->name('edit');

        Route::get('/{customer}', Livewire\Customers\Show::class)
            ->middleware('can:customers.view')->name('show');

        Route::get('/{customer}/enrollments', Livewire\Customers\Enrollments::class)
            ->middleware('can:customers.view')->name('enrollments');

        /*
        | The workspace tabs. Each carries the COARSE permission for its kind
        | of screen; the component then re-authorizes the specific customer on
        | mount and every action it exposes. `can:` alone would answer "may
        | this actor open a forms screen" and say nothing about WHOSE forms.
        */
        Route::get('/{customer}/forms', Livewire\Customers\Forms::class)
            ->middleware('can:forms.view')->name('forms');

        Route::get('/{customer}/sessions', Livewire\Customers\Sessions::class)
            ->middleware('can:sessions.view')->name('sessions');

        Route::get('/{customer}/assignments', Livewire\Customers\Assignments::class)
            ->middleware('can:assignments.view')->name('assignments');

        Route::get('/{customer}/attendance', Livewire\Customers\Attendance::class)
            ->middleware('can:attendance.view')->name('attendance');

        Route::get('/{customer}/day-plan', Livewire\Customers\DayPlan::class)
            ->middleware('can:day_plans.view')->name('day-plan');

        Route::get('/{customer}/time-grid', Livewire\Customers\TimeGrid::class)
            ->middleware('can:time_grid.manage')->name('time-grid');

        Route::get('/{customer}/mmd', Livewire\Customers\Mmd::class)
            ->middleware('can:mmd.view')->name('mmd');

        Route::get('/{customer}/fund-plan', Livewire\Customers\FundPlan::class)
            ->middleware('can:fund_plans.view')->name('fund-plan');

        Route::get('/{customer}/action-plan', Livewire\Customers\ActionPlan::class)
            ->middleware('can:action_items.view')->name('action-plan');

        Route::get('/{customer}/business', Livewire\Customers\BusinessSystems::class)
            ->middleware('can:positions.manage')->name('business');

        Route::get('/{customer}/documents', Livewire\Customers\Documents::class)
            ->middleware('can:documents.manage')->name('documents');

        Route::get('/{customer}/notes', Livewire\Customers\Notes::class)
            ->middleware('can:notes.manage')->name('notes');

        Route::get('/{customer}/reports', Livewire\Customers\Reports::class)
            ->middleware('can:reports.view')->name('reports');

        Route::get('/{customer}/ai', Livewire\Customers\Ai::class)
            ->middleware('can:ai.analysis.view')->name('ai');
    });

    /*
    | Programme delivery.
    */
    Route::prefix('batches')->name('batches.')->group(function (): void {
        Route::get('/', Livewire\Batches\Index::class)
            ->middleware('can:batches.view')->name('index');

        Route::get('/{batch}', Livewire\Batches\Show::class)
            ->middleware('can:batches.view')->name('show');
    });

    Route::prefix('sessions')->name('sessions.')->group(function (): void {
        Route::get('/', Livewire\Sessions\Index::class)
            ->middleware('can:sessions.view')->name('index');

        Route::get('/{session}', Livewire\Sessions\Show::class)
            ->middleware('can:sessions.view')->name('show');
    });

    Route::prefix('assignments')->name('assignments.')->group(function (): void {
        Route::get('/', Livewire\Assignments\Index::class)
            ->middleware('can:assignments.view')->name('index');

        Route::get('/{assignment}', Livewire\Assignments\Show::class)
            ->middleware('can:assignments.view')->name('show');
    });

    Route::get('/attendance', Livewire\Attendance\Index::class)
        ->middleware('can:attendance.view')->name('attendance.index');

    /*
    | A submission is reached by its own id: it already names its customer and
    | its form version, so a customer segment in the path would be a second
    | source of truth to keep in step.
    */
    Route::get('/forms/{submission}', Livewire\Forms\FormRenderer::class)
        ->middleware('can:forms.view')->name('forms.show');

    Route::get('/reports', Livewire\Reports\Index::class)
        ->middleware('can:reports.view')->name('reports.index');

    Route::get('/notifications', Livewire\Notifications\Index::class)
        ->middleware('can:notifications.view')->name('notifications.index');

    /*
    | AI. `ai.analysis.view` is the coarse gate for READING; generating and
    | approving ask for their own permissions inside the components, because
    | which one applies depends on the purpose and on who generated it.
    */
    Route::prefix('ai')->name('ai.')->group(function (): void {
        Route::get('/generations', Livewire\Ai\Generations::class)
            ->middleware('can:ai.analysis.view')->name('generations');

        Route::get('/generations/{generation}', Livewire\Ai\ShowGeneration::class)
            ->middleware('can:ai.analysis.view')->name('generations.show');

        Route::get('/prompts', Livewire\Ai\Prompts::class)
            ->middleware('can:ai.analysis.view')->name('prompts');
    });

    /*
    | Administration.
    */
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/programs', Livewire\Admin\Programs::class)
            ->middleware('can:batches.view')->name('programs');

        Route::get('/curriculum', Livewire\Admin\Curriculum::class)
            ->middleware('can:sessions.view')->name('curriculum');

        Route::get('/form-templates', Livewire\Admin\FormTemplates::class)
            ->middleware('can:forms.view')->name('form-templates');

        Route::get('/skill-areas', Livewire\Admin\SkillAreas::class)
            ->middleware('can:forms.view')->name('skill-areas');

        Route::get('/roles', Livewire\Admin\Roles::class)
            ->middleware('can:roles.manage')->name('roles');
    });

    /*
    | User administration (Step 2).
    */
    Route::prefix('users')->name('users.')->group(function (): void {
        Route::get('/', Livewire\Users\Index::class)
            ->middleware('can:users.view')->name('index');

        Route::get('/create', Livewire\Users\ManageUser::class)
            ->middleware('can:users.create')->name('create');

        Route::get('/{user}/edit', Livewire\Users\ManageUser::class)
            ->middleware('can:users.edit')->name('edit');
    });
});

/*
|--------------------------------------------------------------------------
| External participant access — the only unauthenticated surface
|--------------------------------------------------------------------------
|
| A participating business has no account. It has a link, and the token in that
| link is a scoped, expiring capability for one form version on one enrolment.
| These two routes are the whole of that surface.
|
| THEY ARE OUTSIDE THE auth GROUP ON PURPOSE, and that is the only thing they
| are outside. There is no second guard, no customer session and no cookie here
| that means anything: the token is presented again on every request and
| AccessGrantRedeemer re-proves expiry, revocation, remaining uses, ability and
| scope from the database each time.
|
| No id is accepted from the request. Which customer, which enrolment, which
| form version and which submission are all resolved from the grant, so there is
| nothing in a URL or a payload for a participant to substitute.
|
| Throttling here is about VOLUME, which is a request-rate concern and belongs
| at the route. Token GUESSING is bounded separately inside the redeemer, which
| counts refusals per caller. The two limits do different jobs and neither
| replaces the other.
|
*/
Route::prefix('external/forms')
    ->name('external.forms.')
    ->middleware('throttle:60,1')
    ->group(function (): void {
        Route::get('/{token}', [ExternalFormController::class, 'show'])->name('show');

        // CSRF applies: the route is in the web group, so the POST carries a
        // session token as well as the capability token.
        Route::post('/{token}', [ExternalFormController::class, 'store'])->name('store');
    });
