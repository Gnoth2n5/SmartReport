<x-filament-panels::page>
    @php
        $records = $this->cardRecords;
    @endphp

    <style>
        .sit-card-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        .sit-search-input {
            width: 100%;
            max-width: 360px;
            border-radius: 0.5rem;
            border: 1px solid var(--gray-300);
            background: #fff;
            padding: 0.5rem 0.875rem;
            font-size: 0.875rem;
            color: var(--gray-900);
        }
        .dark .sit-search-input {
            border-color: var(--gray-700);
            background: rgba(255, 255, 255, 0.04);
            color: #f8fafc;
        }
        .sit-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr));
            gap: 1rem;
        }
        .sit-min-card {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 0.875rem;
            padding: 1.125rem;
            border-radius: 0.75rem;
            border: 1px solid var(--gray-200);
            background: #fff;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .sit-min-card:hover {
            border-color: var(--primary-400);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        .dark .sit-min-card {
            border-color: var(--gray-800);
            background: rgba(255, 255, 255, 0.025);
        }
        .dark .sit-min-card:hover {
            border-color: var(--primary-500);
        }
        .sit-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .sit-card-title {
            font-size: 0.975rem;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1.35;
            margin: 0;
        }
        .dark .sit-card-title { color: #f8fafc; }
        .sit-card-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 0.15rem;
        }
        .sit-pill {
            display: inline-flex;
            align-items: center;
            padding: 0.15rem 0.55rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .sit-pill-active { background: #dcfce7; color: #15803d; }
        .sit-pill-inactive { background: #f1f5f9; color: #64748b; }
        .sit-card-desc {
            font-size: 0.8125rem;
            color: var(--gray-600);
            line-height: 1.45;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin: 0;
        }
        .dark .sit-card-desc { color: var(--gray-400); }
        .sit-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: var(--gray-500);
        }
        .sit-meta-chip {
            padding: 0.15rem 0.5rem;
            border-radius: 0.375rem;
            background: var(--gray-100);
            color: var(--gray-700);
            font-weight: 500;
        }
        .dark .sit-meta-chip {
            background: rgba(255, 255, 255, 0.06);
            color: var(--gray-300);
        }
        .sit-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--gray-100);
        }
        .dark .sit-card-footer {
            border-color: rgba(255, 255, 255, 0.06);
        }
        .sit-empty-box {
            text-align: center;
            padding: 3rem 1.5rem;
            border: 1px dashed var(--gray-300);
            border-radius: 0.75rem;
            color: var(--gray-500);
        }
        .dark .sit-empty-box { border-color: var(--gray-700); }
    </style>

    <div>
        <div class="sit-card-toolbar">
            <input
                type="search"
                class="sit-search-input"
                wire:model.live.debounce.250ms="cardSearch"
                placeholder="Tìm nhanh theo tên, mã kịch bản hoặc phân hệ..."
            />
            <span style="font-size: 0.8125rem; color: var(--gray-500);">
                Hiển thị {{ $records->count() }} kịch bản SIT
            </span>
        </div>

        @if ($records->isEmpty())
            <div class="sit-empty-box">
                Chưa có kịch bản SIT nào phù hợp. Hãy bấm <strong>Tạo kịch bản SIT</strong> ở góc trên để bắt đầu.
            </div>
        @else
            <div class="sit-cards-grid">
                @foreach ($records as $record)
                    <div class="sit-min-card" wire:key="sit-card-{{ $record->id }}">
                        <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                            <div class="sit-card-top">
                                <div>
                                    <h3 class="sit-card-title">{{ $record->template_name }}</h3>
                                    <div class="sit-card-code">{{ $record->template_code }} • v{{ $record->version }}</div>
                                </div>
                                <span class="sit-pill {{ $record->is_active ? 'sit-pill-active' : 'sit-pill-inactive' }}">
                                    {{ $record->is_active ? 'Sẵn sàng' : 'Tạm tắt' }}
                                </span>
                            </div>

                            @if ($record->description)
                                <p class="sit-card-desc">{{ $record->description }}</p>
                            @endif

                            <div class="sit-card-meta">
                                @if ($record->module_name)
                                    <span class="sit-meta-chip">{{ $record->module_name }}</span>
                                @endif
                                <span class="sit-meta-chip">{{ count($record->default_steps ?? []) }} bước mẫu</span>
                                <span class="sit-meta-chip">{{ $record->test_runs_count }} đợt test</span>
                            </div>
                        </div>

                        <div class="sit-card-footer">
                            <x-filament::button
                                tag="a"
                                href="{{ url('/admin/sit-tests/' . $record->template_code) }}"
                                size="xs"
                                color="primary"
                                icon="heroicon-o-play"
                            >
                                Chạy SIT
                            </x-filament::button>

                            <div style="display: flex; align-items: center; gap: 0.35rem;">
                                <x-filament::button
                                    tag="a"
                                    href="{{ \App\Filament\Resources\SitTemplateResource::getUrl('edit', ['record' => $record]) }}"
                                    size="xs"
                                    color="gray"
                                    icon="heroicon-o-pencil-square"
                                >
                                    Sửa
                                </x-filament::button>

                                <x-filament::button
                                    size="xs"
                                    color="danger"
                                    icon="heroicon-o-trash"
                                    wire:click="deleteTemplate({{ $record->id }})"
                                    wire:confirm="Bạn có chắc chắn muốn xóa kịch bản '{{ $record->template_name }}'?"
                                />
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
