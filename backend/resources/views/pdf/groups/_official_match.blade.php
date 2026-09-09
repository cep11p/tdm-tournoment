@php
    /** @var array<string, mixed> $match */
    /** @var list<int> $setColumns */
    $setLabels = $setLabels ?? array_map(
        static fn (int $setNumber): string => 'S'.$setNumber,
        $setColumns,
    );
    $refereeName = trim((string) ($match['referee']['display_name'] ?? ''));
    $paired = $paired ?? false;
    $numWidth = $paired ? '6mm' : '8mm';
    $resultWidth = $paired ? '11mm' : '16mm';
    $setWidth = $paired ? '6mm' : '9mm';
    $refWidth = $paired ? '18mm' : '34mm';
@endphp

<table class="official-match">
    <colgroup>
        <col style="width: {{ $numWidth }}">
        <col>
        <col style="width: {{ $resultWidth }}">
        @foreach ($setColumns as $setNumber)
            <col style="width: {{ $setWidth }}">
        @endforeach
        <col style="width: {{ $refWidth }}">
    </colgroup>
    <thead>
        <tr>
            <th class="official-match-col-num">Nº</th>
            <th class="official-match-col-name">Participante</th>
            <th class="official-match-col-result">Resultado</th>
            @foreach ($setLabels as $label)
                <th class="official-match-col-set">{{ $label }}</th>
            @endforeach
            <th class="official-match-col-ref">Juez</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="official-match-num">{{ $match['side1_number'] ?? '' }}</td>
            <td class="official-match-name">{{ \App\Support\Print\PrintPresentation::displayName($match['side1']['display_name'] ?? null) }}</td>
            <td class="official-result-cell"></td>
            @foreach ($setColumns as $setNumber)
                <td class="official-set-cell"></td>
            @endforeach
            <td class="official-match-ref" rowspan="2">
                @if ($refereeName !== '')
                    {{ \App\Support\Print\PrintPresentation::displayName($refereeName) }}
                @endif
            </td>
        </tr>
        <tr>
            <td class="official-match-num">{{ $match['side2_number'] ?? '' }}</td>
            <td class="official-match-name">{{ \App\Support\Print\PrintPresentation::displayName($match['side2']['display_name'] ?? null) }}</td>
            <td class="official-result-cell"></td>
            @foreach ($setColumns as $setNumber)
                <td class="official-set-cell"></td>
            @endforeach
        </tr>
    </tbody>
</table>
