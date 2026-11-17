<x-filament-panels::page>
    <style>
        .sr-export-stack {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .sr-export-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .sr-hint-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 0.75rem;
        }

        .sr-hint-card {
            padding: 0.75rem 0.875rem;
            border: 1px solid var(--gray-200);
            border-radius: 0.5rem;
            background: var(--gray-50);
            font-size: 0.8125rem;
        }

        .dark .sr-hint-card {
            border-color: var(--gray-700);
            background: rgba(255, 255, 255, 0.03);
        }

        .sr-hint-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.75rem;
            color: var(--primary-600);
        }

        .dark .sr-hint-code {
            color: var(--primary-400);
        }
    </style>

    <div class="sr-export-stack">
        <form wire:submit="export" class="sr-export-stack">
            {{ $this->form }}

            <div class="sr-export-actions">
                <x-filament::button
                    type="submit"
                    color="primary"
                    icon="heroicon-o-arrow-down-tray"
                    wire:loading.attr="disabled"
                    wire:target="export"
                >
                    Thực thi & Tải về
                </x-filament::button>
            </div>
        </form>

        <x-filament::section
            heading="Danh sách Catalog cho truy vấn Federated"
            description="Khi chọn Federated (All Sources), sử dụng tiền tố alias bên dưới để JOIN hoặc UNION nhiều nguồn dữ liệu."
            icon="heroicon-o-circle-stack"
            collapsible
            collapsed
        >
            <div class="sr-hint-grid">
                @foreach (\App\Models\DataSource::query()->where('is_active', true)->orderBy('id')->get() as $src)
                    <div class="sr-hint-card">
                        <div style="font-weight: 600; margin-bottom: 0.25rem;">{{ $src->name }}</div>
                        <div class="sr-hint-code">
                            {{ $src->code }}.{{ $src->default_schema ?: '*' }}.&lt;table&gt; ({{ $src->driver }})
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
