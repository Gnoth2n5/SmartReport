<x-filament-panels::page>
    <style>
        .sr-stack {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            min-width: 0;
            max-width: 100%;
        }

        .sr-stack > * {
            min-width: 0;
            max-width: 100%;
        }

        .sr-tabs-wrap {
            overflow-x: auto;
            max-width: 100%;
            -webkit-overflow-scrolling: touch;
        }

        .sr-source-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr));
            gap: 0.75rem;
        }

        .sr-source-option {
            display: flex;
            align-items: flex-start;
            gap: 0.625rem;
            padding: 0.625rem 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: 0.5rem;
            background: var(--gray-50);
            cursor: pointer;
            font-size: 0.875rem;
            min-width: 0;
        }

        .sr-source-option input[type="checkbox"] {
            margin-top: 0.2rem;
            flex-shrink: 0;
        }

        .sr-source-option-body {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.375rem 0.5rem;
            flex: 1 1 0%;
            min-width: 0;
            word-break: break-word;
        }

        .dark .sr-source-option {
            border-color: var(--gray-700);
            background: rgba(255, 255, 255, 0.03);
        }

        .sr-filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 200px), 1fr));
            gap: 1rem;
        }

        .sr-field {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
            min-width: 0;
        }

        .sr-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--gray-700);
        }

        .dark .sr-label {
            color: var(--gray-200);
        }

        .sr-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--gray-200);
        }

        .dark .sr-toolbar {
            border-top-color: var(--gray-700);
        }

        .sr-toolbar-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        @media (max-width: 640px) {
            .sr-toolbar-group {
                width: 100%;
            }

            .sr-toolbar-group > * {
                flex: 1 1 auto;
                justify-content: center;
            }
        }

        .sr-kv-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 200px), 1fr));
            gap: 1rem;
        }

        .sr-kv-card {
            padding: 0.875rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 0.5rem;
            background: var(--gray-50);
            min-width: 0;
        }

        .dark .sr-kv-card {
            border-color: var(--gray-700);
            background: rgba(255, 255, 255, 0.03);
        }

        .sr-kv-key {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--gray-500);
            margin-bottom: 0.25rem;
        }

        .sr-kv-val {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--gray-900);
            word-break: break-word;
        }

        .dark .sr-kv-val {
            color: var(--gray-100);
        }

        .sr-code-block {
            margin: 0;
            padding: 1rem;
            border-radius: 0.5rem;
            background: #0f172a;
            color: #e2e8f0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.8125rem;
            line-height: 1.55;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .sr-alert-danger {
            padding: 0.875rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid #fca5a5;
            background: #fef2f2;
            color: #991b1b;
            font-size: 0.875rem;
        }

        .dark .sr-alert-danger {
            border-color: rgba(248, 113, 113, 0.4);
            background: rgba(127, 29, 29, 0.25);
            color: #fecaca;
        }

        .sr-table-card {
            display: flex !important;
            flex-direction: column !important;
            width: 100%;
            min-width: 0;
            overflow: hidden;
        }

        .sr-table-scroll {
            width: 100%;
            overflow-x: auto;
        }

        .sr-pagination-bar {
            width: 100%;
            padding: 0.75rem 1rem !important;
            display: flex !important;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            border-top: 1px solid var(--gray-200);
        }

        .sr-pagination-bar .fi-pagination-overview {
            display: inline-block !important;
        }

        .sr-pagination-bar .fi-pagination-previous-btn,
        .sr-pagination-bar .fi-pagination-next-btn {
            display: inline-flex !important;
        }

        .dark .sr-pagination-bar {
            border-top-color: var(--gray-700);
        }

        .sr-cell-wrap {
            padding: 0.625rem 0.75rem;
            font-size: 0.8125rem;
            white-space: nowrap;
        }

        .sr-null {
            color: var(--gray-400);
        }

        .sr-section-subhead {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
    </style>

    @php
        $customView = $this->getCustomView();
        $normalizedFilters = $this->getNormalizedFilters($customView);
        $allowedSources = $this->getAllowedSourcesList();
        $connectedSources = $this->getConnectedDataSources();
        $health = $this->getSnapshotHealth();
    @endphp

    <div class="sr-stack">
        <div class="sr-tabs-wrap">
            <x-filament::tabs :contained="true">
                <x-filament::tabs.item
                    :active="$activeTab === 'overview'"
                    wire:click="setActiveTab('overview')"
                    icon="heroicon-o-table-cells"
                >
                    Tổng quan
                </x-filament::tabs.item>

                <x-filament::tabs.item
                    :active="$activeTab === 'app_info'"
                    wire:click="setActiveTab('app_info')"
                    icon="heroicon-o-information-circle"
                >
                    Application Information
                </x-filament::tabs.item>

                <x-filament::tabs.item
                    :active="$activeTab === 'sql_inspector'"
                    wire:click="setActiveTab('sql_inspector')"
                    icon="heroicon-o-code-bracket"
                >
                    SQL Query
                </x-filament::tabs.item>
            </x-filament::tabs>
        </div>

        {{-- TAB 1: TỔNG QUAN --}}
        @if ($activeTab === 'overview')
            <x-filament::section
                heading="Tiêu chí tra cứu & Nguồn dữ liệu"
                icon="heroicon-o-funnel"
            >
                <div class="sr-stack">
                    @if ($customView->isSingleOrUnion() && $allowedSources->isNotEmpty())
                        <div class="sr-field">
                            <span class="sr-label">Nguồn dữ liệu (Multi-DB Selection / UNION ALL)</span>
                            <div class="sr-source-grid">
                                @foreach ($allowedSources as $source)
                                    <label class="sr-source-option">
                                        <input
                                            type="checkbox"
                                            value="{{ $source->code }}"
                                            wire:model="selectedSources"
                                        />
                                        <span class="sr-source-option-body">
                                            <span style="font-weight: 600;">{{ $source->name }}</span>
                                            <x-filament::badge color="info" size="sm">
                                                {{ $source->code }} · {{ $source->driver }}
                                            </x-filament::badge>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (! empty($normalizedFilters))
                        <div class="sr-filter-grid">
                            @foreach ($normalizedFilters as $filter)
                                <div class="sr-field">
                                    <label class="sr-label" for="sr-filter-{{ $filter['name'] }}">
                                        {{ $filter['label'] }}
                                        @if ($filter['is_required'])
                                            <span style="color: #dc2626;">*</span>
                                        @endif
                                    </label>

                                    @if ($filter['type'] === 'select')
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select
                                                id="sr-filter-{{ $filter['name'] }}"
                                                wire:model="filterValues.{{ $filter['name'] }}"
                                            >
                                                <option value="">-- Tất cả --</option>
                                                @foreach ($filter['options'] as $optVal => $optLabel)
                                                    <option value="{{ $optVal }}">{{ $optLabel }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    @elseif ($filter['type'] === 'date')
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                id="sr-filter-{{ $filter['name'] }}"
                                                type="date"
                                                wire:model="filterValues.{{ $filter['name'] }}"
                                            />
                                        </x-filament::input.wrapper>
                                    @elseif ($filter['type'] === 'number')
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                id="sr-filter-{{ $filter['name'] }}"
                                                type="number"
                                                step="any"
                                                wire:model="filterValues.{{ $filter['name'] }}"
                                            />
                                        </x-filament::input.wrapper>
                                    @else
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                id="sr-filter-{{ $filter['name'] }}"
                                                type="text"
                                                wire:model="filterValues.{{ $filter['name'] }}"
                                            />
                                        </x-filament::input.wrapper>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="sr-toolbar">
                        <div class="sr-toolbar-group">
                            <x-filament::button
                                wire:click="runReport"
                                wire:loading.attr="disabled"
                                wire:target="runReport,resetFilters"
                                icon="heroicon-o-magnifying-glass"
                                color="primary"
                            >
                                Tra cứu
                            </x-filament::button>

                            <x-filament::button
                                wire:click="resetFilters"
                                wire:loading.attr="disabled"
                                wire:target="runReport,resetFilters"
                                icon="heroicon-o-arrow-path"
                                color="gray"
                                outlined
                            >
                                Làm mới
                            </x-filament::button>
                        </div>

                        <div class="sr-toolbar-group">
                            <x-filament::button
                                wire:click="exportExcel"
                                wire:loading.attr="disabled"
                                wire:target="exportExcel,exportCsv"
                                icon="heroicon-o-document-arrow-down"
                                color="success"
                                :disabled="blank($snapshotId)"
                            >
                                Xuất Excel (.xlsx)
                            </x-filament::button>

                            <x-filament::button
                                wire:click="exportCsv"
                                wire:loading.attr="disabled"
                                wire:target="exportExcel,exportCsv"
                                icon="heroicon-o-document-text"
                                color="info"
                                :disabled="blank($snapshotId)"
                            >
                                Xuất CSV (.csv)
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            @if ($executionError !== null)
                <div class="sr-alert-danger" role="alert">
                    <strong>Lỗi thực thi:</strong> {{ $executionError }}
                </div>
            @endif

            <div class="fi-ta">
                <div class="fi-ta-ctn sr-table-card">
                    <div class="fi-ta-content-ctn sr-table-scroll">
                        @if (count($rows) > 0)
                            <table class="fi-ta-table">
                                <thead>
                                    <tr>
                                        <th class="fi-ta-header-cell sr-cell-wrap">#</th>
                                        @foreach ($columns as $col)
                                            <th class="fi-ta-header-cell sr-cell-wrap">{{ $col }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        <tr class="fi-ta-row">
                                            <td class="fi-ta-cell sr-cell-wrap">
                                                {{ ($currentPage - 1) * $perPage + $loop->iteration }}
                                            </td>
                                            @foreach ($columns as $col)
                                                @php($val = $row[$col] ?? null)
                                                <td class="fi-ta-cell sr-cell-wrap">
                                                    @if ($val === null)
                                                        <span class="sr-null">—</span>
                                                    @elseif ($col === '_source_db')
                                                        <x-filament::badge color="warning" size="sm">
                                                            {{ $val }}
                                                        </x-filament::badge>
                                                    @elseif (is_bool($val))
                                                        {{ $val ? 'true' : 'false' }}
                                                    @elseif (is_scalar($val))
                                                        {{ $val }}
                                                    @else
                                                        {{ json_encode($val, JSON_UNESCAPED_UNICODE) }}
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <x-filament::empty-state
                                heading="Không có dữ liệu"
                                description="Không tìm thấy dòng dữ liệu nào phù hợp với điều kiện lọc hiện tại."
                                icon="heroicon-o-inbox"
                            />
                        @endif
                    </div>

                    <div class="fi-pagination fi-simple sr-pagination-bar">
                        <span class="fi-pagination-overview">
                            Trang {{ $currentPage }} / {{ $totalPages }} (Tổng số: {{ number_format($totalRows) }} dòng)
                        </span>

                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <x-filament::button
                                class="fi-pagination-previous-btn"
                                color="gray"
                                size="sm"
                                wire:click="loadPage({{ $currentPage - 1 }})"
                                wire:loading.attr="disabled"
                                :disabled="$currentPage <= 1 || blank($snapshotId)"
                            >
                                Trang trước
                            </x-filament::button>

                            <x-filament::button
                                class="fi-pagination-next-btn"
                                color="gray"
                                size="sm"
                                wire:click="loadPage({{ $currentPage + 1 }})"
                                wire:loading.attr="disabled"
                                :disabled="! $hasNextPage || blank($snapshotId)"
                            >
                                Trang sau
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- TAB 2: APPLICATION INFORMATION --}}
        @if ($activeTab === 'app_info')
            <x-filament::section
                heading="Thông tin Báo cáo (Application Metadata)"
                icon="heroicon-o-identification"
            >
                <div class="sr-kv-grid">
                    <div class="sr-kv-card">
                        <div class="sr-kv-key">UI Name</div>
                        <div class="sr-kv-val">{{ $customView->view_name }}</div>
                    </div>

                    <div class="sr-kv-card">
                        <div class="sr-kv-key">UI ID</div>
                        <div class="sr-kv-val">{{ $customView->view_code }}</div>
                    </div>

                    <div class="sr-kv-card">
                        <div class="sr-kv-key">Version</div>
                        <div class="sr-kv-val">{{ $customView->version ?? '1.0.0' }}</div>
                    </div>

                    <div class="sr-kv-card">
                        <div class="sr-kv-key">Execution Mode</div>
                        <div class="sr-kv-val">
                            <x-filament::badge color="primary" size="sm">
                                {{ $customView->execution_mode }}
                            </x-filament::badge>
                        </div>
                    </div>

                    <div class="sr-kv-card">
                        <div class="sr-kv-key">Menu Group</div>
                        <div class="sr-kv-val">
                            {{ $customView->menuGroup?->title ?? '—' }}
                            ({{ $customView->menu_group_code ?? '—' }})
                        </div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section
                heading="Data Sources Connected"
                icon="heroicon-o-server-stack"
            >
                @if ($connectedSources->isNotEmpty())
                    <div class="fi-ta">
                        <div class="fi-ta-ctn sr-table-card">
                            <div class="fi-ta-content-ctn sr-table-scroll">
                                <table class="fi-ta-table">
                                    <thead>
                                        <tr>
                                            <th class="fi-ta-header-cell sr-cell-wrap">Code</th>
                                            <th class="fi-ta-header-cell sr-cell-wrap">Name</th>
                                            <th class="fi-ta-header-cell sr-cell-wrap">Driver</th>
                                            <th class="fi-ta-header-cell sr-cell-wrap">Host</th>
                                            <th class="fi-ta-header-cell sr-cell-wrap">Port</th>
                                            <th class="fi-ta-header-cell sr-cell-wrap">Database / Service</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($connectedSources as $source)
                                            <tr class="fi-ta-row">
                                                <td class="fi-ta-cell sr-cell-wrap">
                                                    <x-filament::badge color="info" size="sm">
                                                        {{ $source->code }}
                                                    </x-filament::badge>
                                                </td>
                                                <td class="fi-ta-cell sr-cell-wrap">{{ $source->name }}</td>
                                                <td class="fi-ta-cell sr-cell-wrap">{{ $source->driver }}</td>
                                                <td class="fi-ta-cell sr-cell-wrap">{{ $source->host }}</td>
                                                <td class="fi-ta-cell sr-cell-wrap">{{ $source->port }}</td>
                                                <td class="fi-ta-cell sr-cell-wrap">
                                                    {{ $source->database_name ?: ($source->service_name ?: '—') }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @else
                    <x-filament::empty-state
                        heading="Chưa kết nối nguồn dữ liệu"
                        description="Không có nguồn dữ liệu đang hoạt động."
                        icon="heroicon-o-server"
                    />
                @endif
            </x-filament::section>

            <x-filament::section
                heading="Snapshot Health"
                icon="heroicon-o-heart"
            >
                <div class="sr-kv-grid">
                    <div class="sr-kv-card">
                        <div class="sr-kv-key">Snapshot ID</div>
                        <div class="sr-kv-val">{{ $snapshotId ?? 'Chưa có snapshot' }}</div>
                    </div>

                    <div class="sr-kv-card">
                        <div class="sr-kv-key">Creation Timestamp</div>
                        <div class="sr-kv-val">{{ $health['created_at'] }}</div>
                    </div>

                    <div class="sr-kv-card">
                        <div class="sr-kv-key">TTL Countdown ({{ $health['ttl_minutes'] }} mins)</div>
                        <div
                            class="sr-kv-val"
                            x-data="{
                                expiresAt: {{ (int) $health['expires_at_unix'] }},
                                label: '',
                                update() {
                                    if (!this.expiresAt) { this.label = '—'; return; }
                                    const rem = Math.max(0, this.expiresAt - Math.floor(Date.now() / 1000));
                                    if (rem === 0) { this.label = 'Đã hết hạn'; return; }
                                    const mins = String(Math.floor(rem / 60)).padStart(2, '0');
                                    const secs = String(rem % 60).padStart(2, '0');
                                    this.label = mins + ':' + secs;
                                }
                            }"
                            x-init="update(); setInterval(() => update(), 1000)"
                            x-text="label"
                        >
                            {{ $health['expires_at_unix'] > 0 ? gmdate('i:s', $health['remaining_seconds']) : '—' }}
                        </div>
                    </div>

                    <div class="sr-kv-card">
                        <div class="sr-kv-key">Execution Latency</div>
                        <div class="sr-kv-val">{{ number_format($executionLatencyMs, 2) }} ms</div>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- TAB 3: SQL QUERY INSPECTOR --}}
        @if ($activeTab === 'sql_inspector')
            <x-filament::section
                heading="Applied Criteria (@Parameters)"
                icon="heroicon-o-adjustments-horizontal"
            >
                <pre class="sr-code-block">{{ json_encode($filterValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </x-filament::section>

            <x-filament::section
                heading="Individual Source Queries"
                icon="heroicon-o-circle-stack"
            >
                <div class="sr-stack">
                    @forelse ($individualSourceQueries as $sq)
                        <div>
                            <div class="sr-section-subhead">
                                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; min-width: 0;">
                                    <x-filament::badge color="info" size="sm">
                                        {{ $sq['source_code'] }}
                                    </x-filament::badge>
                                    <x-filament::badge color="gray" size="sm">
                                        {{ $sq['driver'] }}
                                    </x-filament::badge>
                                    <span style="font-size: 0.875rem; font-weight: 600; word-break: break-word;">
                                        Alias: {{ $sq['alias'] }} ({{ $sq['source_name'] }})
                                    </span>
                                </div>
                            </div>
                            <pre class="sr-code-block">{{ $sq['sql'] }}</pre>
                        </div>
                    @empty
                        <p style="font-size: 0.875rem; color: var(--gray-500);">Chưa có truy vấn nguồn nào được biên dịch.</p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section
                heading="DuckDB Federation SQL"
                icon="heroicon-o-command-line"
            >
                <div
                    x-data="{
                        copied: false,
                        copySql() {
                            const text = this.$refs.federatedSql?.innerText || '';
                            if (text && navigator.clipboard) {
                                navigator.clipboard.writeText(text).then(() => {
                                    this.copied = true;
                                    setTimeout(() => { this.copied = false; }, 2000);
                                });
                            }
                        }
                    }"
                    class="sr-stack"
                >
                    <div style="display: flex; justify-content: flex-end;">
                        <x-filament::button
                            type="button"
                            color="gray"
                            size="sm"
                            icon="heroicon-o-clipboard-document"
                            x-on:click="copySql()"
                        >
                            <span x-show="!copied">Copy SQL</span>
                            <span x-show="copied" x-cloak>Đã sao chép!</span>
                        </x-filament::button>
                    </div>
                    <pre class="sr-code-block" x-ref="federatedSql">{{ $compiledFederatedSql }}</pre>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
