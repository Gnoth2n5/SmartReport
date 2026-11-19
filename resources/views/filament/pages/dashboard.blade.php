<x-filament-panels::page>
    <x-filament::section heading="SmartReport">
        <p>Chọn báo cáo hoặc kịch bản SIT từ menu để bắt đầu.</p>
        @if (\App\Filament\Pages\SqlExportPage::canAccess())
            <x-filament::button tag="a" href="{{ \App\Filament\Pages\SqlExportPage::getUrl() }}">Công cụ SQL</x-filament::button>
        @endif
    </x-filament::section>
</x-filament-panels::page>
