@php
    /** @var array $school */
    /** @var array $teacher */
    /** @var array $academic_year */
    /** @var int $semester */
    /** @var array $schedules_by_day */
    /** @var array $time_slots */
    /** @var array $totals */
    /** @var \Carbon\Carbon $generated_at */
    /** @var string $orientation */

    $isLandscape = ($orientation ?? 'landscape') === 'landscape';
    $logoPath = public_path('images/default-school-logo.png');
    $logoDataUri = file_exists($logoPath)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
        : null;
    $generatedLabel = $generated_at->locale('id')->isoFormat('dddd, D MMMM Y [pukul] HH.mm');

    $dayLabels = [
        'monday' => 'Senin', 'tuesday' => 'Selasa', 'wednesday' => 'Rabu',
        'thursday' => 'Kamis', 'friday' => 'Jumat', 'saturday' => 'Sabtu',
        'sunday' => 'Ahad',
    ];

    // Build a quick lookup [day][time_slot_id] => schedule for the landscape grid
    $gridLookup = [];
    foreach ($schedules_by_day as $group) {
        foreach ($group['items'] as $item) {
            $gridLookup[$group['day']][$item->time_slot_id] = $item;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Jadwal Mengajar — {{ $teacher['full_name'] }}</title>
    <style>
        @page {
            size: A4 {{ $isLandscape ? 'landscape' : 'portrait' }};
            margin: 12mm 14mm 14mm 14mm;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 10pt;
            color: #1f2937;
            line-height: 1.45;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        h1, h2, h3, h4 { margin: 0; }

        .header {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 2px solid #0f766e;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }

        .header__logo {
            width: 56px;
            height: 56px;
            flex: 0 0 56px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .header__logo img,
        .header__logo svg { max-width: 100%; max-height: 100%; object-fit: contain; }

        .header__info { flex: 1; }
        .header__school-name {
            font-size: 14pt;
            font-weight: 700;
            color: #0f172a;
        }
        .header__school-meta {
            font-size: 8.5pt;
            color: #64748b;
            margin-top: 2px;
        }
        .header__school-meta span + span::before { content: " · "; }

        .title-block {
            margin-bottom: 14px;
        }
        .title-block h2 {
            font-size: 13pt;
            font-weight: 700;
            color: #0f172a;
        }
        .title-block .meta {
            font-size: 9.5pt;
            color: #475569;
            margin-top: 2px;
        }
        .title-block .meta strong { color: #0f172a; }

        /* Landscape grid */
        .grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            table-layout: fixed;
        }
        .grid th, .grid td {
            border: 1px solid #cbd5e1;
            padding: 6px 5px;
            vertical-align: top;
            font-size: 8.5pt;
        }
        .grid thead th {
            background: #ccfbf1;
            color: #0f172a;
            font-weight: 700;
            text-align: center;
        }
        .grid tbody th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            text-align: left;
            width: 90px;
        }
        .grid .cell {
            background: #eff6ff;
            border-radius: 4px;
            padding: 4px 5px;
            min-height: 30px;
        }
        .grid .cell .book {
            font-weight: 700;
            color: #1e3a8a;
            font-size: 8.5pt;
            line-height: 1.25;
        }
        .grid .cell .klass {
            color: #1e3a8a;
            opacity: 0.85;
            font-size: 8pt;
            margin-top: 2px;
        }
        .grid .empty {
            text-align: center;
            color: #94a3b8;
            font-size: 9pt;
        }

        /* Portrait day list */
        .day-group { margin-bottom: 10px; }
        .day-group__label {
            font-size: 9.5pt;
            font-weight: 700;
            color: #0f766e;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid #ccfbf1;
            padding-bottom: 3px;
            margin-bottom: 5px;
        }
        .day-group__list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .day-group__list li {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 4px 0 4px 8px;
            border-left: 2px solid #ccfbf1;
            margin-bottom: 2px;
        }
        .day-group__slot {
            color: #64748b;
            white-space: nowrap;
            font-size: 9pt;
        }
        .day-group__detail {
            text-align: right;
            color: #0f172a;
            font-weight: 600;
            font-size: 9pt;
        }
        .day-group__detail .klass {
            color: #64748b;
            font-weight: 400;
        }

        /* Totals card */
        .totals {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            margin-bottom: 14px;
        }
        .totals .stat {
            flex: 1;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 10px;
        }
        .totals .stat__label {
            font-size: 8pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .totals .stat__value {
            font-size: 13pt;
            font-weight: 700;
            color: #0f766e;
            margin-top: 2px;
        }

        .footer {
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
            font-size: 8pt;
            color: #64748b;
            display: flex;
            justify-content: space-between;
        }

        .empty-state {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            color: #64748b;
            padding: 20px;
            text-align: center;
            border-radius: 6px;
            font-size: 10pt;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header__logo">
            @if ($logoDataUri)
                <img src="{{ $logoDataUri }}" alt="Logo {{ $school['name'] ?? 'Pesantren' }}">
            @endif
        </div>
        <div class="header__info">
            <div class="header__school-name">{{ $school['name'] ?? 'Pesantren' }}</div>
            <div class="header__school-meta">
                @if (! empty($school['address']))<span>{{ $school['address'] }}</span>@endif
                @if (! empty($school['phone']))<span>Telp. {{ $school['phone'] }}</span>@endif
                @if (! empty($school['email']))<span>{{ $school['email'] }}</span>@endif
            </div>
        </div>
    </div>

    <div class="title-block">
        <h2>Jadwal Mengajar — Ustadz {{ $teacher['full_name'] }}</h2>
        <div class="meta">
            Kode: <strong>{{ $teacher['code'] }}</strong>
            &nbsp;·&nbsp; Semester <strong>{{ $semester }}</strong>
            &nbsp;·&nbsp; TA <strong>{{ $academic_year['name'] ?? '-' }}</strong>
        </div>
    </div>

    @if (empty($schedules_by_day))
        <div class="empty-state">Belum ada jadwal mengajar untuk periode ini.</div>
    @elseif ($isLandscape)
        {{-- Landscape: weekly grid, days as columns, time slots as rows --}}
        <table class="grid">
            <thead>
                <tr>
                    <th>Waktu</th>
                    @foreach ($dayLabels as $dayKey => $dayLabel)
                        <th>{{ $dayLabel }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($time_slots as $slot)
                    <tr>
                        <th>{{ $slot['label'] }}</th>
                        @foreach ($dayLabels as $dayKey => $_dayLabel)
                            @php $cellSchedule = $gridLookup[$dayKey][$slot['id']] ?? null; @endphp
                            <td>
                                @if ($cellSchedule)
                                    <div class="cell">
                                        <div class="book">{{ $cellSchedule->subjectBook?->title }}</div>
                                        <div class="klass">{{ $cellSchedule->classLevel?->label }}</div>
                                    </div>
                                @else
                                    <div class="empty">–</div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        {{-- Portrait: grouped-by-day list --}}
        @foreach ($schedules_by_day as $group)
            <div class="day-group">
                <div class="day-group__label">{{ $group['label'] }}</div>
                <ul class="day-group__list">
                    @foreach ($group['items'] as $item)
                        <li>
                            <span class="day-group__slot">{{ $item->timeSlot?->label }}</span>
                            <span class="day-group__detail">
                                {{ $item->subjectBook?->title }}
                                @if ($item->classLevel?->label)
                                    <span class="klass">· {{ $item->classLevel->label }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @endif

    <div class="totals">
        <div class="stat">
            <div class="stat__label">Total Sesi</div>
            <div class="stat__value">{{ $totals['sesi'] }}</div>
        </div>
        <div class="stat">
            <div class="stat__label">Kitab</div>
            <div class="stat__value">{{ $totals['kitab'] }}</div>
        </div>
        <div class="stat">
            <div class="stat__label">Kelas</div>
            <div class="stat__value">{{ $totals['kelas'] }}</div>
        </div>
    </div>

    <div class="footer">
        <span>Diekspor pada {{ $generatedLabel }} WIB</span>
        <span>Halaman 1</span>
    </div>
</body>
</html>
