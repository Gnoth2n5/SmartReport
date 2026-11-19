<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CustomViewResource;
use App\Models\User;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'SmartReport';

    protected string $view = 'filament.pages.dashboard';

    public function mount(): void
    {
        if (User::current()?->isSuperAdmin()) {
            $this->redirect(CustomViewResource::getUrl());
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! (User::current()?->isSuperAdmin() ?? false);
    }
}
