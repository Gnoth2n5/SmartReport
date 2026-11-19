<x-filament-panels::page>
    @if ($errors->any())
        <div role="alert" style="color: #dc2626;">
            @foreach ($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
            @error('runRevision')
                <x-filament::button color="gray" wire:click="selectRun({{ $loadedRunId }})">Tải lại đợt test</x-filament::button>
            @enderror
        </div>
    @endif
    @php
        $template = $this->getTemplate();
        $fieldDefs = $template->resolvedStepFields();
        $allRuns = $this->availableRuns;
        $metrics = $this->metrics;
    @endphp

    <style>
        .sit-stack { display: flex; flex-direction: column; gap: 1.25rem; }
        .sit-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; }
        .sit-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
        .sit-grid-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 0.875rem; }
        .sit-grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 0.875rem; }
        .sit-field label { display: block; font-size: 0.8125rem; font-weight: 600; margin-bottom: 0.25rem; color: var(--gray-700); }
        .dark .sit-field label { color: var(--gray-200); }
        .sit-input, .sit-select, .sit-textarea {
            width: 100%;
            border-radius: 0.5rem;
            border: 1px solid var(--gray-300);
            background: #fff;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            color: var(--gray-900);
        }
        .dark .sit-input, .dark .sit-select, .dark .sit-textarea {
            border-color: var(--gray-700);
            background: rgba(255, 255, 255, 0.04);
            color: #f8fafc;
        }
        .sit-metrics { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .sit-badge {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.25rem 0.75rem; border-radius: 9999px;
            font-size: 0.75rem; font-weight: 700;
        }
        .sit-badge-total { background: #e0e7ff; color: #1e40af; }
        .sit-badge-pass { background: #dcfce7; color: #15803d; }
        .sit-badge-fail { background: #fee2e2; color: #b91c1c; }
        .sit-badge-blocked { background: #fef3c7; color: #b45309; }
        .sit-badge-pending { background: #f1f5f9; color: #475569; }
        .sit-step-card {
            border: 1px solid var(--gray-200);
            border-radius: 0.75rem;
            background: #fff;
            padding: 1.125rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
        }
        .dark .sit-step-card {
            border-color: var(--gray-700);
            background: rgba(255, 255, 255, 0.02);
        }
        .sit-step-header {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 0.75rem; padding-bottom: 0.75rem; margin-bottom: 0.875rem;
            border-bottom: 1px solid var(--gray-200);
        }
        .dark .sit-step-header { border-color: var(--gray-700); }
        .sit-status-group { display: inline-flex; border-radius: 0.5rem; overflow: hidden; border: 1px solid var(--gray-300); }
        .dark .sit-status-group { border-color: var(--gray-700); }
        .sit-status-btn {
            padding: 0.3rem 0.65rem; font-size: 0.75rem; font-weight: 700; cursor: pointer;
            background: transparent; border: none; color: var(--gray-600);
        }
        .dark .sit-status-btn { color: var(--gray-300); }
        .sit-status-btn.active-PASS { background: #16a34a; color: #fff; }
        .sit-status-btn.active-FAIL { background: #dc2626; color: #fff; }
        .sit-status-btn.active-BLOCKED { background: #d97706; color: #fff; }
        .sit-status-btn.active-PENDING { background: #64748b; color: #fff; }
        .sit-paste-zone {
            border: 2px dashed var(--primary-400);
            border-radius: 0.625rem;
            padding: 0.875rem;
            text-align: center;
            background: rgba(245, 158, 11, 0.04);
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .sit-paste-zone:hover, .sit-paste-zone:focus {
            background: rgba(245, 158, 11, 0.1);
            outline: none;
        }
        .sit-img-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 0.875rem;
            margin-top: 0.75rem;
        }
        .sit-img-card {
            border: 1px solid var(--gray-200);
            border-radius: 0.5rem;
            padding: 0.5rem;
            background: var(--gray-50);
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .dark .sit-img-card {
            border-color: var(--gray-700);
            background: rgba(255, 255, 255, 0.03);
        }
        .sit-img-preview {
            width: 100%;
            height: 150px;
            object-fit: contain;
            border-radius: 0.375rem;
            background: #0f172a;
        }
    </style>

    <div class="sit-stack">
        {{-- 1. Thanh điều khiển Đợt kiểm thử & Nút Export --}}
        <x-filament::section
            heading="Thông tin Đợt kiểm thử SIT (Test Run)"
            description="Chọn đợt chạy, nhân bản để Retest các bước lỗi, hoặc xuất biên bản nghiệm thu kèm hình ảnh (.xlsx / .docx / .html)"
            icon="heroicon-o-clipboard-document-check"
        >
            <div class="sit-stack">
                <div class="sit-toolbar">
                    <div class="sit-metrics">
                        <span class="sit-badge sit-badge-total">Tổng: {{ $metrics['total'] }} bước</span>
                        <span class="sit-badge sit-badge-pass">PASS: {{ $metrics['pass'] }}</span>
                        <span class="sit-badge sit-badge-fail">FAIL: {{ $metrics['fail'] }}</span>
                        <span class="sit-badge sit-badge-blocked">BLOCKED: {{ $metrics['blocked'] }}</span>
                        <span class="sit-badge sit-badge-pending">PENDING: {{ $metrics['pending'] }}</span>
                    </div>

                    <div class="sit-actions">
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-plus" wire:click="createNewRun">
                            Tạo đợt mới
                        </x-filament::button>
                        <x-filament::button size="sm" color="warning" icon="heroicon-o-document-duplicate" wire:click="cloneCurrentRun">
                            Nhân bản / Retest
                        </x-filament::button>
                        <x-filament::button size="sm" color="primary" icon="heroicon-o-check" wire:click="saveRun">
                            Lưu kết quả
                        </x-filament::button>
                        <x-filament::button size="sm" color="success" icon="heroicon-o-table-cells" wire:click="exportExcel">
                            Xuất Excel (.xlsx)
                        </x-filament::button>
                        <x-filament::button size="sm" color="info" icon="heroicon-o-document-text" wire:click="exportWord">
                            Xuất Word (.docx)
                        </x-filament::button>
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-printer" wire:click="exportHtml">
                            Tải HTML / In
                        </x-filament::button>
                    </div>
                </div>

                <div class="sit-grid-4">
                    <div class="sit-field">
                        <label for="sit-run-select">Đợt chạy SIT (Test Run)</label>
                        <select id="sit-run-select" class="sit-select" wire:model.live="selectedRunId">
                            @foreach ($allRuns as $r)
                                <option value="{{ $r->id }}">{{ $r->run_code }} — {{ $r->run_title }} [{{ $r->status }}]</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="sit-field">
                        <label for="sit-run-title">Tiêu đề đợt kiểm thử</label>
                        <input id="sit-run-title" type="text" class="sit-input" wire:model.blur="runTitle" placeholder="vd: Đợt SIT Lần 1" />
                    </div>

                    <div class="sit-field">
                        <label for="sit-run-env">Môi trường</label>
                        <select id="sit-run-env" class="sit-select" wire:model.blur="runEnvironment">
                            <option value="SIT">SIT</option>
                            <option value="UAT">UAT</option>
                            <option value="STAGING">STAGING</option>
                            <option value="PROD">PROD</option>
                        </select>
                    </div>

                    <div class="sit-field">
                        <label for="sit-tester-name">Người thực hiện (Tester)</label>
                        <input id="sit-tester-name" type="text" class="sit-input" wire:model.blur="testerName" placeholder="Tên người test" />
                    </div>
                </div>

                <div class="sit-field">
                    <label for="sit-summary-notes">Ghi chú tổng kết đợt SIT</label>
                    <textarea id="sit-summary-notes" rows="2" class="sit-textarea" wire:model.blur="summaryNotes" placeholder="Ghi chú chung về môi trường, dữ liệu mẫu hoặc kết luận nghiệm thu..."></textarea>
                </div>
            </div>
        </x-filament::section>

        {{-- 2. Danh sách các Bước kiểm thử (Steps) --}}
        @foreach ($this->steps as $sIdx => $step)
            @php
                $curStatus = strtoupper((string) ($step['status'] ?? 'PENDING'));
            @endphp
            <div
                class="sit-step-card"
                wire:key="sit-step-{{ $step['uid'] ?? $sIdx }}"
                x-data="{
                    handlePaste(e) {
                        const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
                        if (!items) return;
                        for (const item of items) {
                            if (item.type.indexOf('image') !== -1) {
                                e.preventDefault();
                                const blob = item.getAsFile();
                                const reader = new FileReader();
                                reader.onload = (ev) => {
                                    $wire.pasteStepImage({{ $sIdx }}, ev.target.result, 'Ảnh dán từ Clipboard');
                                };
                                reader.readAsDataURL(blob);
                            }
                        }
                    },
                    handleFileSelect(e) {
                        const files = e.target.files;
                        if (!files || !files.length) return;
                        Array.from(files).forEach((file) => {
                            const reader = new FileReader();
                            reader.onload = (ev) => {
                                $wire.pasteStepImage({{ $sIdx }}, ev.target.result, file.name.replace(/\.[^/.]+$/, ''));
                            };
                            reader.readAsDataURL(file);
                        });
                        e.target.value = '';
                    }
                }"
                x-on:paste="handlePaste($event)"
            >
                <div class="sit-step-header">
                    <div style="display: flex; align-items: center; gap: 0.75rem; flex: 1; min-width: 240px;">
                        <span class="sit-badge sit-badge-total" style="white-space: nowrap;">Bước #{{ $sIdx + 1 }}</span>
                        <input
                            type="text"
                            class="sit-input"
                            style="font-weight: 600;"
                            wire:model.blur="steps.{{ $sIdx }}.title"
                            placeholder="Nhập tiêu đề bước kiểm thử..."
                        />
                    </div>

                    <div class="sit-actions">
                        <div class="sit-status-group" role="group" aria-label="Step Status">
                            @foreach (['PASS', 'FAIL', 'BLOCKED', 'PENDING'] as $stOpt)
                                <button
                                    type="button"
                                    class="sit-status-btn {{ $curStatus === $stOpt ? 'active-'.$stOpt : '' }}"
                                    wire:click="setStepStatus({{ $sIdx }}, '{{ $stOpt }}')"
                                >
                                    {{ $stOpt }}
                                </button>
                            @endforeach
                        </div>

                        <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-up" wire:click="moveStepUp({{ $sIdx }})" tooltip="Chuyển lên" />
                        <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-down" wire:click="moveStepDown({{ $sIdx }})" tooltip="Chuyển xuống" />
                        <x-filament::button size="xs" color="danger" icon="heroicon-o-trash" wire:click="removeStep({{ $sIdx }})" tooltip="Xóa bước" />
                    </div>
                </div>

                {{-- Các trường chuẩn từ Template --}}
                <div class="sit-grid-2">
                    @foreach ($fieldDefs as $fd)
                        @php
                            $fKey = $fd['key'];
                            $fType = $fd['type'] ?? 'textarea';
                            $fOptions = is_array($fd['options'] ?? null) ? $fd['options'] : [];
                        @endphp
                        <div class="sit-field">
                            <label>
                                {{ $fd['label'] }}
                                @if (!empty($fd['required']))
                                    <span style="color: #dc2626;">*</span>
                                @endif
                            </label>

                            @if ($fType === 'text')
                                <input
                                    type="text"
                                    class="sit-input"
                                    wire:model.blur="steps.{{ $sIdx }}.fields.{{ $fKey }}"
                                />
                            @elseif ($fType === 'select')
                                <select class="sit-select" wire:model.blur="steps.{{ $sIdx }}.fields.{{ $fKey }}">
                                    <option value="">-- Chọn --</option>
                                    @foreach ($fOptions as $optKey => $optLabel)
                                        <option value="{{ $optKey }}">{{ $optLabel }}</option>
                                    @endforeach
                                </select>
                            @else
                                <textarea
                                    rows="3"
                                    class="sit-textarea"
                                    wire:model.blur="steps.{{ $sIdx }}.fields.{{ $fKey }}"
                                ></textarea>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Các trường thông tin tùy chọn bổ sung cho riêng bước này (extra_fields) --}}
                <div style="margin-top: 0.875rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span style="font-size: 0.8125rem; font-weight: 600; color: var(--gray-600);">
                            Thông tin tùy chọn bổ sung (Extra Fields)
                        </span>
                        <x-filament::button size="xs" color="gray" icon="heroicon-o-plus" wire:click="addExtraField({{ $sIdx }})">
                            Thêm thông tin phụ
                        </x-filament::button>
                    </div>

                    @if (!empty($step['extra_fields']))
                        <div class="sit-stack" style="gap: 0.5rem;">
                            @foreach ($step['extra_fields'] as $eIdx => $ef)
                                <div style="display: flex; gap: 0.5rem; align-items: center;" wire:key="extra-{{ $sIdx }}-{{ $eIdx }}">
                                    <input
                                        type="text"
                                        class="sit-input"
                                        style="max-width: 220px;"
                                        placeholder="Tên trường (vd: Mã chứng từ)"
                                        wire:model.blur="steps.{{ $sIdx }}.extra_fields.{{ $eIdx }}.label"
                                    />
                                    <input
                                        type="text"
                                        class="sit-input"
                                        placeholder="Giá trị"
                                        wire:model.blur="steps.{{ $sIdx }}.extra_fields.{{ $eIdx }}.value"
                                    />
                                    <x-filament::button
                                        size="xs"
                                        color="danger"
                                        icon="heroicon-o-x-mark"
                                        wire:click="removeExtraField({{ $sIdx }}, {{ $eIdx }})"
                                    />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Khu vực Bằng chứng Hình ảnh (Ctrl+V Paste & Multi-Upload) --}}
                <div style="margin-top: 1rem;">
                    <div
                        class="sit-paste-zone"
                        tabindex="0"
                        title="Click vào đây rồi nhấn Ctrl+V để dán ảnh từ Clipboard, hoặc bấm nút Chọn file ảnh"
                    >
                        <div style="font-size: 0.85rem; font-weight: 600; color: var(--primary-600);">
                            📸 Nhấn <kbd style="padding: 2px 6px; border: 1px solid currentColor; border-radius: 4px;">Ctrl + V</kbd> ở bất kỳ đâu trong Bước #{{ $sIdx + 1 }} để dán ảnh chụp màn hình
                        </div>
                        <div style="margin-top: 0.4rem; font-size: 0.78rem; color: var(--gray-500);">
                            Hoặc chọn một / nhiều file ảnh từ máy tính:
                            <label style="color: var(--primary-600); text-decoration: underline; cursor: pointer; font-weight: 600;">
                                Chọn ảnh tải lên
                                <input type="file" accept="image/*" multiple style="display: none;" x-on:change="handleFileSelect($event)" />
                            </label>
                        </div>
                    </div>

                    @if (!empty($step['images']))
                        <div class="sit-img-grid">
                            @foreach ($step['images'] as $imgIdx => $img)
                                <div class="sit-img-card" wire:key="img-{{ $sIdx }}-{{ $imgIdx }}">
                                    <a href="{{ route('sit.evidence.image', ['run' => $loadedRunId, 'path' => $img['path']]) }}" target="_blank" rel="noopener">
                                        <img
                                            src="{{ route('sit.evidence.image', ['run' => $loadedRunId, 'path' => $img['path']]) }}"
                                            alt="{{ $img['caption'] ?? 'Evidence' }}"
                                            class="sit-img-preview"
                                        />
                                    </a>
                                    <div style="display: flex; gap: 0.35rem; align-items: center;">
                                        <input
                                            type="text"
                                            class="sit-input"
                                            style="font-size: 0.75rem; padding: 0.3rem 0.5rem;"
                                            placeholder="Chú thích ảnh..."
                                            wire:model.blur="steps.{{ $sIdx }}.images.{{ $imgIdx }}.caption"
                                        />
                                        <x-filament::button
                                            size="xs"
                                            color="danger"
                                            icon="heroicon-o-trash"
                                            wire:click="removeStepImage({{ $sIdx }}, {{ $imgIdx }})"
                                        />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endforeach

        {{-- 3. Nút thêm bước mới ở cuối trang --}}
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <x-filament::button color="gray" icon="heroicon-o-plus-circle" wire:click="addStep">
                Thêm bước kiểm thử mới
            </x-filament::button>

            <x-filament::button color="primary" icon="heroicon-o-check" wire:click="saveRun">
                Lưu toàn bộ kết quả SIT
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
