<?php

namespace App\Policies;

use App\Models\SitTemplate;
use App\Models\User;

class SitTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || SitTemplate::accessibleTo($user)->exists();
    }

    public function view(User $user, SitTemplate $template): bool
    {
        return SitTemplate::accessibleTo($user)->whereKey($template->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, SitTemplate $template): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, SitTemplate $template): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
