<?php

namespace App\Filament\Resources\SitTemplateResource\Pages;

use App\Filament\Resources\SitTemplateResource;
use App\Models\SitTemplate;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Collection;

class ListSitTemplates extends ListRecords
{
    protected static string $resource = SitTemplateResource::class;

    protected string $view = 'filament.resources.sit-template-resource.pages.list-sit-templates';

    public string $cardSearch = '';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tạo kịch bản SIT')
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * Query SIT templates for the minimalist card grid.
     *
     * @return Collection<int, SitTemplate>
     */
    public function getCardRecordsProperty(): Collection
    {
        $q = SitTemplate::query()
            ->withCount('testRuns')
            ->orderByDesc('updated_at');

        $term = trim($this->cardSearch);
        if ($term !== '') {
            $q->where(function ($sub) use ($term): void {
                $sub->where('template_name', 'like', "%{$term}%")
                    ->orWhere('template_code', 'like', "%{$term}%")
                    ->orWhere('module_name', 'like', "%{$term}%");
            });
        }

        return $q->get();
    }

    public function deleteTemplate(int $id): void
    {
        $template = SitTemplate::find($id);
        if (! $template) {
            return;
        }

        $name = $template->template_name;
        $template->delete();

        Notification::make()
            ->title('Đã xóa kịch bản SIT')
            ->body("Đã xóa kịch bản {$name}.")
            ->success()
            ->send();
    }
}
