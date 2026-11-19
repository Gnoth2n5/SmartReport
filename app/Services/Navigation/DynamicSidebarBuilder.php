<?php

namespace App\Services\Navigation;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SqlExportPage;
use App\Filament\Resources\SitTemplateResource;
use App\Models\CustomView;
use App\Models\MenuGroup;
use App\Models\RolePermission;
use App\Models\SitTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DynamicSidebarBuilder
{
    /**
     * Build dynamic navigation for the admin panel.
     */
    public function build(NavigationBuilder $builder): NavigationBuilder
    {
        // 1. Safe guard against unmigrated database or CLI runner
        try {
            if (! Schema::hasTable('menu_groups') || ! Schema::hasTable('custom_views')) {
                return $builder;
            }
        } catch (\Throwable) {
            return $builder;
        }

        $user = User::current();

        // 2. Add Dashboard item
        if (Dashboard::shouldRegisterNavigation()) {
            $builder->items(Dashboard::getNavigationItems());
        }

        if (! $user) {
            return $builder;
        }

        // 3. Add Dynamic Report Menu Groups & Custom Views from database
        $reportGroups = $this->buildReportGroups($user);
        $builder->groups($reportGroups);

        if (! $user->hasAnyRole(['admin', 'super_admin']) && SitTemplateResource::canAccess()) {
            $builder->items([
                NavigationItem::make('Kịch bản SIT')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->url(SitTemplateResource::getUrl())
                    ->isActiveWhen(fn (): bool => request()->is('admin/sit-templates*', 'admin/sit-tests/*')),
            ]);
        }

        // 4. Add System Management Group for Admins and Super Admins
        if ($user->hasAnyRole(['admin', 'super_admin'])) {
            $adminGroup = $this->buildAdminGroup();
            if ($adminGroup) {
                $builder->group($adminGroup);
            }
        }

        return $builder;
    }

    /**
     * Build report navigation groups and their custom views.
     *
     * @return array<NavigationGroup>
     */
    public function buildReportGroups(User $user): array
    {
        $groups = MenuGroup::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $allViews = CustomView::query()
            ->select(['id', 'view_code', 'view_name', 'menu_group_code'])
            ->where('is_active', true)
            ->get();

        $userPermissions = RolePermission::query()
            ->whereIn('role_code', $user->roles ?? [])
            ->get();

        $navigationGroups = [];

        foreach ($groups as $group) {
            $groupViews = $allViews->where('menu_group_code', $group->group_code);
            $items = [];

            foreach ($groupViews as $view) {
                if (! $this->canUserAccessView($user, $view, $userPermissions)) {
                    continue;
                }

                $items[] = NavigationItem::make($view->view_name)
                    ->url(url("/admin/reports/{$view->view_code}"))
                    ->isActiveWhen(fn (): bool => request()->is("admin/reports/{$view->view_code}*"));
            }

            if (! empty($items)) {
                $navigationGroups[] = NavigationGroup::make($group->title)
                    ->icon($group->icon ?: 'heroicon-o-folder')
                    ->collapsible()
                    ->items($items);
            }
        }

        return $navigationGroups;
    }

    /**
     * Build system administration group dynamically from panel resources and pages.
     */
    public function buildAdminGroup(): ?NavigationGroup
    {
        $panel = Filament::getCurrentOrDefaultPanel();
        $adminItems = [];

        foreach ($panel->getResources() as $resource) {
            if ($resource::shouldRegisterNavigation() && $resource::canAccess()) {
                $adminItems = array_merge($adminItems, $resource::getNavigationItems());
            }
        }

        foreach ($panel->getPages() as $page) {
            if ($page === Dashboard::class || ($page === SqlExportPage::class && User::current()?->isSuperAdmin()) || ! $page::shouldRegisterNavigation()) {
                continue;
            }

            if ($page::canAccess()) {
                $adminItems = array_merge($adminItems, $page::getNavigationItems());
            }
        }

        if (empty($adminItems)) {
            return null;
        }

        usort(
            $adminItems,
            fn (NavigationItem $a, NavigationItem $b): int => ($a->getSort() ?? 0) <=> ($b->getSort() ?? 0)
        );

        return NavigationGroup::make('Quản Trị Hệ Thống')
            ->collapsible()
            ->items($adminItems);
    }

    /**
     * Check if user has permission to access a custom view.
     *
     * @param  Collection<int, RolePermission>  $permissions
     */
    public function canUserAccessView(User $user, CustomView $view, Collection $permissions): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($permission->allowsView($view->view_code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has permission to access a SIT template.
     *
     * @param  Collection<int, RolePermission>  $permissions
     */
    public function canUserAccessSitTemplate(User $user, SitTemplate $template, Collection $permissions): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($permission->allowsSitTemplate($template->template_code)) {
                return true;
            }
        }

        return false;
    }
}
