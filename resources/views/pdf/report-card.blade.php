@php
    /** @var string $title */
    /** @var array{name: ?string, address: ?string} $school */
    /** @var ?string $logo_data_uri */
    /** @var string $student_name */
    /** @var string $class_label */
    /** @var string $academic_year_name */
    /** @var int $semester */
    /** @var array $subjects */
    /** @var array $summary */
    /** @var string $finalized_label */
    /** @var string $finalized_by_name */
    /** @var \Carbon\Carbon $generated_at */

    $generatedLabel = $generated_at->locale('id')->isoFormat('dddd, D MMMM Y [pukul] HH.mm');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rapor — {{ $student_name }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 14mm 14mm 16mm 14mm;
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
        .header__logo img { max-width: 100%; max-height: 100%; object-fit: contain; }

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

        .title-block {
            text-align: center;
            margin-bottom: 14px;
        }
        .title-block h2 {
            font-size: 13pt;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: 0.04em;
        }

        .student-info {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 9.5pt;
        }
        .student-info td {
            padding: 2px 0;
            vertical-align: top;
        }
        .student-info td.label { width: 110px; color: #475569; }
        .student-info td.sep { width: 12px; color: #475569; }
        .student-info td.value { font-weight: 600; color: #0f172a; }

        .subject-card {
            margin-bottom: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            break-inside: avoid;
        }
        .subject-card__header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            background: #ccfbf1;
            padding: 6px 10px;
        }
        .subject-card__title { font-size: 10.5pt; font-weight: 700; color: #0f172a; }
        .subject-card__template { font-size: 8pt; color: #0f766e; margin-left: 6px; }
        .subject-card__final { font-size: 11pt; font-weight: 700; color: #0f766e; }

        .factor-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }
        .factor-table th, .factor-table td {
            border-top: 1px solid #e2e8f0;
            padding: 4px 10px;
            text-align: left;
        }
        .factor-table thead th {
            background: #f8fafc;
            color: #64748b;
            font-weight: 600;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            border-top: none;
        }
        .factor-table td.numeric, .factor-table th.numeric { text-align: right; }

        .summary {
            margin-top: 16px;
            margin-bottom: 20px;
        }
        .summary h3 {
            font-size: 10pt;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5pt;
        }
        .summary-table th, .summary-table td {
            border: 1px solid #cbd5e1;
            padding: 5px 10px;
        }
        .summary-table thead th {
            background: #f8fafc;
            color: #475569;
            text-align: left;
        }
        .summary-table td.numeric, .summary-table th.numeric { text-align: right; }

        .finalize-info {
            font-size: 9pt;
            color: #475569;
            margin-bottom: 24px;
        }

        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
        }
        .signature {
            width: 45%;
            text-align: center;
            font-size: 9.5pt;
        }
        .signature .role { color: #0f172a; }
        .signature .space { height: 60px; }
        .signature .name-line {
            border-top: 1px solid #94a3b8;
            padding-top: 4px;
            color: #94a3b8;
        }

        .footer {
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
            font-size: 8pt;
            color: #64748b;
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
            @if (! empty($logo_data_uri))
                <img src="{{ $logo_data_uri }}" alt="Logo {{ $school['name'] ?? 'Pesantren' }}">
            @endif
        </div>
        <div class="header__info">
            <div class="header__school-name">{{ $school['name'] ?? 'Pesantren' }}</div>
            @if (! empty($school['address']))
                <div class="header__school-meta">{{ $school['address'] }}</div>
            @endif
        </div>
    </div>

    <div class="title-block">
        <h2>{{ $title }}</h2>
    </div>

    <table class="student-info">
        <tr>
            <td class="label">Nama Santri</td><td class="sep">:</td><td class="value">{{ $student_name }}</td>
        </tr>
        <tr>
            <td class="label">Kelas</td><td class="sep">:</td><td class="value">{{ $class_label }}</td>
        </tr>
        <tr>
            <td class="label">Tahun Ajaran</td><td class="sep">:</td><td class="value">{{ $academic_year_name }} — Semester {{ $semester }}</td>
        </tr>
    </table>

    @if (empty($subjects))
        <div class="empty-state">Rapor ini tidak memiliki kitab.</div>
    @else
        @foreach ($subjects as $subject)
            <div class="subject-card">
                <div class="subject-card__header">
                    <div>
                        <span class="subject-card__title">{{ $subject['title'] }}</span>
                        @if (! empty($subject['template_name']))
                            <span class="subject-card__template">({{ $subject['template_name'] }})</span>
                        @endif
                    </div>
                    <div class="subject-card__final">{{ $subject['final_score_label'] }}</div>
                </div>
                <table class="factor-table">
                    <thead>
                        <tr>
                            <th>Faktor</th>
                            <th class="numeric">Nilai</th>
                            <th class="numeric">Bobot Ternormalisasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($subject['factors'] as $factor)
                            <tr>
                                <td>{{ $factor['name'] }}</td>
                                <td class="numeric">{{ $factor['score_label'] }}</td>
                                <td class="numeric">{{ $factor['normalized_weight_label'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach

        <div class="summary">
            <h3>Ringkasan Nilai Akhir</h3>
            <table class="summary-table">
                <thead>
                    <tr>
                        <th>Kitab</th>
                        <th class="numeric">Nilai Akhir</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary as $row)
                        <tr>
                            <td>{{ $row['title'] }}</td>
                            <td class="numeric">{{ $row['final_score_label'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="finalize-info">
        Rapor ini difinalkan pada {{ $finalized_label }} oleh {{ $finalized_by_name }}.
    </div>

    <div class="signatures">
        <div class="signature">
            <div class="space"></div>
            <div class="name-line">Wali Kelas</div>
        </div>
        <div class="signature">
            <div class="space"></div>
            <div class="name-line">Kepala Pesantren</div>
        </div>
    </div>

    <div class="footer">
        <span>Diekspor pada {{ $generatedLabel }} WIB</span>
    </div>
</body>
</html>
