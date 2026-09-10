@php
    /** @var \App\Data\Group\PrintGroupSheetData $sheet */
    $setColumns = \App\Support\Print\PrintPresentation::setColumns($sheet->bestOf);
    $setLabels = \App\Support\Print\PrintPresentation::setColumnLabels($sheet->bestOf);
    $matchRounds = \App\Support\Print\PrintPresentation::groupConsecutiveMatchesByRound($sheet->matches);
    $breakAfter = $breakAfter ?? false;
    $matrixRows = $sheet->matrix;
    $firstCells = is_array($matrixRows[0]['cells'] ?? null) ? $matrixRows[0]['cells'] : [];
    $matrixNumbers = $firstCells !== []
        ? array_column($firstCells, 'opponent_number')
        : array_column($matrixRows, 'sheet_number');
    $notesLineCount = match ($sheet->sheetKind) {
        'g5' => 3,
        'g4' => 4,
        default => 5,
    };
@endphp

<article
    class="pdf-sheet pdf-sheet--official pdf-sheet--{{ $sheet->sheetKind }} pdf-sheet--bo{{ $sheet->bestOf }}{{ $breakAfter ? ' pdf-sheet--break' : '' }}"
    data-sheet-kind="{{ $sheet->sheetKind }}"
>
    <div class="official-layout official-layout--{{ $sheet->sheetKind }} official-layout--bo{{ $sheet->bestOf }}">
        <table class="official-header">
            <tr>
                <th>Torneo</th>
                <td>{{ $sheet->tournament['name'] !== '' ? $sheet->tournament['name'] : '—' }}</td>
                <th>Fecha</th>
                <td class="official-header-blank"></td>
            </tr>
            <tr>
                <th>Competencia</th>
                <td>{{ $sheet->competition['name'] !== '' ? $sheet->competition['name'] : '—' }}</td>
                <th>Mesa</th>
                <td class="official-header-blank"></td>
            </tr>
            <tr>
                <th>Modalidad</th>
                <td>{{ \App\Support\Print\PrintPresentation::competitionTypeLabel($sheet->competition['type'] ?? null) }}</td>
                <th>Día</th>
                <td class="official-header-blank"></td>
            </tr>
            <tr>
                <th>Grupo</th>
                <td>{{ $sheet->group['name'] !== '' ? $sheet->group['name'] : '—' }}</td>
                <th>Hora</th>
                <td class="official-header-blank"></td>
            </tr>
            <tr>
                <th>Formato</th>
                <td>{{ \App\Support\Print\PrintPresentation::bestOfLabel($sheet->bestOf) }}</td>
                <th>Clasifican</th>
                <td>{{ $sheet->qualifiedPerGroup }}</td>
            </tr>
        </table>

        <table class="official-matrix">
            <thead>
                <tr>
                    <th class="official-matrix-num">Nº</th>
                    <th class="official-matrix-name">Jugador / Pareja</th>
                    <th class="official-matrix-assoc">Asociación</th>
                    @foreach ($matrixNumbers as $number)
                        <th class="official-matrix-cell">{{ $number }}</th>
                    @endforeach
                    <th class="official-matrix-pts">Puntos</th>
                    <th class="official-matrix-pos">Posición</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($matrixRows as $row)
                    <tr class="official-matrix-row">
                        <td class="official-matrix-num">{{ $row['sheet_number'] }}</td>
                        <td class="official-matrix-name-cell">{{ \App\Support\Print\PrintPresentation::displayName($row['entry']['display_name'] ?? null) }}</td>
                        <td class="official-matrix-assoc"></td>
                        @foreach (($row['cells'] ?? []) as $cell)
                            <td class="official-matrix-cell{{ ($cell['type'] ?? '') === 'self' ? ' matrix-cell--self' : '' }}">
                                @if (($cell['type'] ?? '') === 'self')
                                    X
                                @endif
                            </td>
                        @endforeach
                        <td class="official-matrix-empty"></td>
                        <td class="official-matrix-empty"></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @php
            $stackMatches = \App\Support\Print\PrintPresentation::orientation($sheet->bestOf) === 'portrait';
        @endphp
        @foreach ($matchRounds as $round)
            @php
                $roundMatches = $round['matches'];
                $roundCount = count($roundMatches);
                $stackRound = $stackMatches || $roundCount === 1;
            @endphp
            @if ($stackRound)
                @foreach ($roundMatches as $match)
                    <table class="official-match-row">
                        <tr>
                            <td class="official-match-wrap official-match-wrap--single">
                                @include('pdf.groups._official_match', [
                                    'match' => $match,
                                    'setColumns' => $setColumns,
                                    'setLabels' => $setLabels,
                                    'paired' => false,
                                ])
                            </td>
                        </tr>
                    </table>
                @endforeach
            @else
                <table class="official-match-row official-match-row--pair">
                    <tr>
                        @foreach ($roundMatches as $match)
                            <td class="official-match-wrap">
                                @include('pdf.groups._official_match', [
                                    'match' => $match,
                                    'setColumns' => $setColumns,
                                    'setLabels' => $setLabels,
                                    'paired' => true,
                                ])
                            </td>
                        @endforeach
                    </tr>
                </table>
            @endif
        @endforeach

        <div class="official-notes">
            <p class="official-notes-title">Observaciones</p>
            <div class="official-notes-block">
                @for ($line = 1; $line <= $notesLineCount; $line++)
                    <div class="official-notes-line"></div>
                @endfor
            </div>
        </div>

        <table class="official-sign">
            <tr>
                <td>Firma: <span class="official-sign-line"></span></td>
                <td>Aclaración: <span class="official-sign-line"></span></td>
            </tr>
        </table>
        <p class="official-sign-role-label">Rol:</p>
        <p class="official-sign-role">Árbitro general / Responsable</p>
    </div>
</article>
