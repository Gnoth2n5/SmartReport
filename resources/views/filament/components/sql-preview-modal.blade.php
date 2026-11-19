<div>
    @if (!empty($error))
        <p role="alert" style="color: #dc2626; white-space: pre-wrap;">{{ $error }}</p>
    @else
        @php
            $latency = $latencyMs ?? ($preview['latency_ms'] ?? 0);
            $cols = $columns ?? ($preview['columns'] ?? []);
            $dataRows = $rows ?? ($preview['rows'] ?? []);
            $params = $appliedParams ?? [];
        @endphp

        <p style="margin-bottom: 0.75rem;">
            {{ count($dataRows) }} dòng · {{ count($cols) }} cột · {{ number_format($latency, 2) }} ms
        </p>
        @if ($params !== [])
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
                @foreach ($params as $name => $value)
                    <x-filament::badge color="gray">{{ '@'.$name }} = {{ $value === null ? 'NULL' : (is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)) }}</x-filament::badge>
                @endforeach
            </div>
        @endif

        <div class="fi-ta">
            <div class="fi-ta-ctn">
                <div class="fi-ta-content-ctn" style="overflow: auto; max-height: 28rem;">
                    <table class="fi-ta-table" aria-label="Kết quả chạy thử SQL" style="width: 100%;">
                        <thead>
                            <tr>
                                <th class="fi-ta-header-cell" style="padding: 0.75rem;">#</th>
                                @foreach ($cols as $col)
                                    <th class="fi-ta-header-cell" style="padding: 0.75rem; white-space: nowrap;">{{ $col }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($dataRows as $row)
                                <tr class="fi-ta-row">
                                    <td class="fi-ta-cell" style="padding: 0.75rem;">{{ $loop->iteration }}</td>
                                    @foreach ($cols as $col)
                                        @php($value = $row[$col] ?? null)
                                        <td class="fi-ta-cell" style="padding: 0.75rem; white-space: nowrap;">
                                            @if ($col === '_source_db' && $value !== null)
                                                <x-filament::badge color="info">{{ $value }}</x-filament::badge>
                                            @else
                                                {{ $value === null ? '—' : (is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE))) }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td class="fi-ta-cell" colspan="{{ count($cols) + 1 }}" style="padding: 1rem;">Không có dữ liệu phù hợp.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
