@php
    /** @var \App\Data\TeamTie\PrintTeamTieData $sheet */
    $isBye = (bool) ($sheet->teamTie['is_bye'] ?? false);
    $rubbers = $sheet->rubbers;
    $showScore = \App\Support\Print\PrintPresentation::teamTieShouldShowScore($sheet);
    $isFinished = ($sheet->teamTie['status'] ?? '') === 'finished';
    $winnerName = trim((string) (($sheet->winner ?? [])['display_name'] ?? ''));
    $contextLabel = trim((string) ($sheet->teamTie['context_label'] ?? ''));
    $formatName = trim((string) ($sheet->format['name'] ?? ''));
    $victoriesRequired = (int) ($sheet->teamTie['victories_required'] ?? 0);
    $side1Name = \App\Support\Print\PrintPresentation::teamTieSideName($sheet->side1, 'Equipo A');
    $side2Name = \App\Support\Print\PrintPresentation::teamTieSideName($sheet->side2, 'Equipo B');
@endphp

@extends('pdf.layout')

@section('content')
    <article class="pdf-sheet">
        <p class="print-kicker">Planilla de enfrentamiento</p>
        <h1 class="print-title">{{ \App\Support\Print\PrintPresentation::teamTieMatchupLabel($sheet) }}</h1>

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
                @if ($contextLabel !== '')
                    <td>
                        <div class="print-meta-label">Contexto</div>
                        <div class="print-meta-value">{{ $contextLabel }}</div>
                    </td>
                @endif
            </tr>
        </table>

        @if (! $isBye)
            @if ($formatName !== '')
                <p class="print-format">Formato: {{ $formatName }}</p>
            @endif
            @if ($victoriesRequired > 0)
                <p class="print-format-rule">Gana el primero en llegar a {{ $victoriesRequired }} victorias</p>
            @endif
            @if ($showScore)
                <p class="print-score">
                    {{ $side1Name }}
                    {{ (int) ($sheet->score['side1'] ?? 0) }} — {{ (int) ($sheet->score['side2'] ?? 0) }}
                    {{ $side2Name }}
                </p>
            @endif
            @if ($isFinished && $winnerName !== '')
                <p class="print-winner">Ganador: {{ $winnerName }}</p>
            @endif
        @endif

        @if ($isBye)
            <table class="print-bye">
                <tr>
                    <td class="print-center">
                        <p class="print-bye-team">{{ \App\Support\Print\PrintPresentation::teamTieSideName($sheet->side1, 'Equipo') }}</p>
                        <p class="print-bye-badge">{{ \App\Support\Print\PrintPresentation::teamTieByeLabel() }}</p>
                    </td>
                </tr>
            </table>
        @elseif ($rubbers === [])
            <p class="print-empty">Los partidos internos aún no fueron generados.</p>
        @else
            <table class="print-table print-team-tie-table">
                <thead>
                    <tr>
                        <th class="print-col-order">#</th>
                        <th class="print-col-type">Tipo</th>
                        <th>{{ $side1Name }}</th>
                        <th>{{ $side2Name }}</th>
                        <th class="print-col-status">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rubbers as $rubber)
                        @php
                            $status = (string) ($rubber['status'] ?? '');
                            $official = (bool) ($rubber['official'] ?? false);
                            $winnerSide = $rubber['winner_side'] ?? null;
                            $isSecondary = $status === 'not_needed' || ($status === 'finished' && ! $official);
                        @endphp
                        <tr @class(['print-row--secondary' => $isSecondary])>
                            <td class="print-center">{{ $rubber['slot_order'] }}</td>
                            <td>{{ $rubber['label'] }}</td>
                            <td>
                                <span @class(['print-name', 'print-name--winner' => $official && (int) $winnerSide === 1])>
                                    {{ \App\Support\Print\PrintPresentation::teamTieLineupLabel($rubber['side1'] ?? null) }}
                                </span>
                                @if ((int) $winnerSide === 1)
                                    <span @class(['print-check', 'print-check--faint' => ! $official])>✓</span>
                                @endif
                            </td>
                            <td>
                                <span @class(['print-name', 'print-name--winner' => $official && (int) $winnerSide === 2])>
                                    {{ \App\Support\Print\PrintPresentation::teamTieLineupLabel($rubber['side2'] ?? null) }}
                                </span>
                                @if ((int) $winnerSide === 2)
                                    <span @class(['print-check', 'print-check--faint' => ! $official])>✓</span>
                                @endif
                            </td>
                            <td class="print-center print-status">{{ \App\Support\Print\PrintPresentation::teamTieRubberStatusLabel($rubber) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </article>
@endsection
