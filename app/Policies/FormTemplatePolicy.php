<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormTemplate;
use App\Models\User;

class FormTemplatePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('forms.view');
    }

    public function view(User $actor, FormTemplate $template): bool
    {
        return $actor->can('forms.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('forms.create');
    }

    public function update(User $actor, FormTemplate $template): bool
    {
        return $actor->can('forms.edit');
    }

    public function archive(User $actor, FormTemplate $template): bool
    {
        return $actor->can('forms.archive');
    }

    /**
     * Archive-only: form_versions RESTRICT, and a version that has been
     * answered is evidence.
     */
    public function delete(User $actor, FormTemplate $template): bool
    {
        return false;
    }
}
