<div class="space-y-4">
    @if(!empty($error))
        <div class="p-4 rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800">
            <div class="flex items-center space-x-2 text-red-700 dark:text-red-400 font-semibold mb-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span>Lỗi thực thi truy vấn DuckDB</span>
            </div>
            <pre class="text-xs text-red-600 dark:text-red-300 overflow-x-auto whitespace-pre-wrap font-mono">{{ $error }}</pre>
        </div>
    @else
        @php
            $latency = $latencyMs ?? ($preview['latency_ms'] ?? 0);
            $total = $totalRows ?? ($preview['totalRows'] ?? 0);
            $cols = $columns ?? ($preview['columns'] ?? []);
            $dataRows = $rows ?? ($preview['rows'] ?? []);
            $params = $appliedParams ?? [];
        @endphp

        {{-- Metric Summary Badges --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
            <div class="p-3 bg-gray-50 dark:bg-gray-800/60 rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="text-xs text-gray-500 dark:text-gray-400">Thời gian thực thi</div>
                <div class="text-lg font-bold text-emerald-600 dark:text-emerald-400 font-mono">{{ $latency }} ms</div>
            </div>
            <div class="p-3 bg-gray-50 dark:bg-gray-800/60 rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="text-xs text-gray-500 dark:text-gray-400">Dòng xem trước</div>
                <div class="text-lg font-bold text-primary-600 dark:text-primary-400 font-mono">{{ number_format($total) }}</div>
            </div>
            <div class="p-3 bg-gray-50 dark:bg-gray-800/60 rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="text-xs text-gray-500 dark:text-gray-400">Số cột kết quả</div>
                <div class="text-lg font-bold text-gray-800 dark:text-gray-200 font-mono">{{ count($cols) }}</div>
            </div>
            <div class="p-3 bg-gray-50 dark:bg-gray-800/60 rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="text-xs text-gray-500 dark:text-gray-400">Xem trước hiển thị</div>
                <div class="text-lg font-bold text-indigo-600 dark:text-indigo-400 font-mono">{{ count($dataRows) }} / 10 dòng</div>
            </div>
        </div>

        {{-- Column Badges --}}
        @if(count($cols) > 0)
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-xs text-gray-500 dark:text-gray-400 font-medium mr-1">Danh sách cột:</span>
                @foreach($cols as $col)
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600">
                        {{ $col }}
                    </span>
                @endforeach
            </div>
        @endif

        {{-- Applied Parameters --}}
        @if(!empty($params))
            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-300 bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800 p-2.5 rounded">
                <span class="font-semibold text-blue-700 dark:text-blue-300">Tham số áp dụng:</span>
                @foreach($params as $k => $v)
                    <span class="font-mono break-all">@{{ $k }} = {{ $v === null ? 'NULL' : (is_scalar($v) ? $v : json_encode($v)) }}</span>
                @endforeach
            </div>
        @endif

        {{-- Data Preview Table --}}
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-x-auto max-h-[500px]">
            <table class="w-full text-xs text-left text-gray-700 dark:text-gray-200 border-collapse">
                <thead class="sticky top-0 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 font-semibold border-b border-gray-200 dark:border-gray-700">
                    <tr>
                        <th class="px-3 py-2 border-r border-gray-200 dark:border-gray-700 w-12 text-center font-mono">#</th>
                        @foreach($cols as $col)
                            <th class="px-3 py-2 border-r border-gray-200 dark:border-gray-700 whitespace-nowrap font-mono">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                    @forelse($dataRows as $index => $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40">
                            <td class="px-3 py-2 border-r border-gray-200 dark:border-gray-700 text-center text-gray-400 font-mono">{{ $index + 1 }}</td>
                            @foreach($cols as $col)
                                <td class="px-3 py-2 border-r border-gray-200 dark:border-gray-700 font-mono whitespace-nowrap">
                                    @php $val = $row[$col] ?? null; @endphp
                                    @if($val === null)
                                        <span class="text-gray-400 italic">NULL</span>
                                    @elseif($col === '_source_db')
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300">{{ $val }}</span>
                                    @else
                                        {{ is_scalar($val) ? $val : json_encode($val) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($cols) + 1 }}" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                Không có dữ liệu trả về từ câu truy vấn.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
