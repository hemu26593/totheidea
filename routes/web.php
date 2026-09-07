<?php

declare(strict_types=1);

use App\Livewire\Users\Index as UsersIndex;
use App\Livewire\Users\ManageUser;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Authentication routes (login, logout, password reset) are registered by
| Fortify. Registration is NOT among them: Features::registration() is omitted
| from config/fortify.php, so no /register route exists to reach (ADR-007).
|
| Every route below is authenticated. EnsureUserIsActive is appended to the web
| middleware group in bootstrap/app.php, so it also applies here — a route
| cannot be added without it by accident. Permission middleware is the coarse
| first line only; each Livewire component re-authorizes its own actions.
|
*/

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::prefix('users')->name('users.')->group(function (): void {
        Route::get('/', UsersIndex::class)
            ->middleware('can:users.view')
            ->name('index');

        Route::get('/create', ManageUser::class)
            ->middleware('can:users.create')
            ->name('create');

        Route::get('/{user}/edit', ManageUser::class)
            ->middleware('can:users.edit')
            ->name('edit');
    });
});
