<?php

namespace App\Services\Navigation;

use App\Models\CustomView;
use App\Models\MenuGroup;
use App\Models\RolePermission;
use App\Models\SitTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
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
        $builder->items(Dashboard::getNavigationItems());

        if (! $user) {
            return $builder;
        }

        // 3. Add Dynamic Report Menu Groups & Custom Views from database
        $reportGroups = $this->buildReportGroups($user);
        $builder->groups($reportGroups);

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
            ->where('is_active', true)
            ->get();

        $allSitTemplates = Schema::hasTable('sit_templates')
            ? SitTemplate::query()->where('is_active', true)->get()
            : collect();

        $userPermissions = RolePermission::query()
            ->whereIn('role_code', $user->roles ?? [])
            ->get();

        $navigationGroups = [];

        foreach ($groups as $group) {
            if (! $this->canUserAccessGroup($user, $group, $userPermissions)) {
                continue;
            }

            $groupViews = $allViews->where('menu_group_code', $group->group_code);
            $groupSitTemplates = $allSitTemplates->where('menu_group_code', $group->group_code);
            $items = [];

            foreach ($groupViews as $view) {
                if (! $this->canUserAccessView($user, $view, $userPermissions)) {
                    continue;
                }

                $items[] = NavigationItem::make($view->view_name)
                    ->url(url("/admin/reports/{$view->view_code}"))
                    ->isActiveWhen(fn (): bool => request()->is("admin/reports/{$view->view_code}*"));
            }

            foreach ($groupSitTemplates as $sitTemplate) {
                if (! $this->canUserAccessSitTemplate($user, $sitTemplate, $userPermissions)) {
                    continue;
                }

                $items[] = NavigationItem::make($sitTemplate->template_name)
                    ->icon('heroicon-o-clipboard-document-check')
                    ->url(url("/admin/sit-tests/{$sitTemplate->template_code}"))
                    ->isActiveWhen(fn (): bool => request()->is("admin/sit-tests/{$sitTemplate->template_code}*"));
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
            if ($resource::canAccess()) {
                $adminItems = array_merge($adminItems, $resource::getNavigationItems());
            }
        }

        foreach ($panel->getPages() as $page) {
            if ($page === Dashboard::class || ! $page::shouldRegisterNavigation()) {
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
     * Check if user has permission to access a menu group.
     *
     * @param  Collection<int, RolePermission>  $permissions
     */
    public function canUserAccessGroup(User $user, MenuGroup $group, Collection $permissions): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! empty($group->roles) && $user->hasAnyRole($group->roles)) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($permission->allowsGroup($group->group_code)) {
                return true;
            }
        }

        return false;
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

        if (! empty($view->roles) && $user->hasAnyRole($view->roles)) {
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

        if (! empty($template->roles) && $user->hasAnyRole($template->roles)) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($permission->allowsView($template->template_code)) {
                return true;
            }
        }

        return false;
    }
}
