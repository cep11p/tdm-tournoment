@php
    /** @var \App\Data\Group\PrintGroupSheetData $sheet */
    $setColumns = \App\Support\Print\PrintPresentation::setColumns($sheet->bestOf);
    $breakAfter = $breakAfter ?? false;
@endphp

<article class="pdf-sheet pdf-sheet--generic{{ $breakAfter ? ' pdf-sheet--break' : '' }}" data-sheet-kind="generic">
    <p class="print-kicker">Planilla de grupo</p>
    <h1 class="print-title">{{ $sheet->group['name'] !== '' ? $sheet->group['name'] : 'Grupo' }}</h1>

    <table class="print-meta">
        <tr>
            <td>
                <div class="print-meta-label">Torneo</div>
                <div class="print-meta-value">{{ $sheet->tournament['name'] !== '' ? $sheet->tournament['name'] : '—' }}</div>
            </td>
            <td>
                <div class="print-meta-label">Competencia</div>
                <div class="print-meta-value">{{ $sheet->competition['name'] !== '' ? $sheet->competition['name'] : '—' }}</div>
            </td>
            <td>
                <div class="print-meta-label">Modalidad</div>
                <div class="print-meta-value">{{ \App\Support\Print\PrintPresentation::competitionTypeLabel($sheet->competition['type'] ?? null) }}</div>
            </td>
            <td>
                <div class="print-meta-label">Formato</div>
                <div class="print-meta-value">Mejor de {{ $sheet->bestOf }}</div>
            </td>
            <td>
                <div class="print-meta-label">Clasifican</div>
                <div class="print-meta-value">{{ $sheet->qualifiedPerGroup }}</div>
            </td>
        </tr>
    </table>

    <table class="print-table">
        <thead>
            <tr>
                <th rowspan="2" class="print-col-order">#</th>
                <th rowspan="2" class="print-col-round">Ronda</th>
                <th rowspan="2">Participante A</th>
                <th rowspan="2">Participante B</th>
                <th rowspan="2">Árbitro</th>
                @foreach ($setColumns as $setNumber)
                    <th colspan="2" class="print-col-set">Set {{ $setNumber }}</th>
                @endforeach
                <th rowspan="2" class="print-col-result">Res.</th>
                <th rowspan="2" class="print-col-notes">Obs.</th>
            </tr>
            <tr>
                @foreach ($setColumns as $setNumber)
                    <th class="print-col-score">A</th>
                    <th class="print-col-score">B</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($sheet->matches as $match)
                <tr>
                    <td class="print-center">{{ $match['order'] }}</td>
                    <td class="print-center">{{ \App\Support\Print\PrintPresentation::roundLabel($match['group_round'] ?? null) }}</td>
                    <td>
                        <span class="print-name">{{ \App\Support\Print\PrintPresentation::displayName($match['side1']['display_name'] ?? null) }}</span>
                    </td>
                    <td>
                        <span class="print-name">{{ \App\Support\Print\PrintPresentation::displayName($match['side2']['display_name'] ?? null) }}</span>
                    </td>
                    <td>
                        @if (! empty($match['referee']['display_name']))
                            <span class="print-name">{{ \App\Support\Print\PrintPresentation::displayName($match['referee']['display_name']) }}</span>
                        @else
                            <span class="print-missing-ref">—</span>
                            <span class="print-ref-line"></span>
                        @endif
                    </td>
                    @foreach ($setColumns as $setNumber)
                        <td class="print-score-cell"></td>
                        <td class="print-score-cell"></td>
                    @endforeach
                    <td class="print-result-cell"></td>
                    <td class="print-notes-cell"></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="print-footer">
        <p><strong>Observaciones</strong></p>
        <div class="print-notes-block"></div>
        <p class="print-sign">Mesa / responsable: ________________________________</p>
    </div>
</article>
