@php
    /** @var \App\Data\Group\PrintGroupSheetData $sheet */
    $breakAfter = $breakAfter ?? false;
@endphp

@if (\App\Support\Print\PrintPresentation::isOfficialGroupSheetKind($sheet->sheetKind))
    @include('pdf.groups._official_sheet', ['sheet' => $sheet, 'breakAfter' => $breakAfter])
@else
    @include('pdf.groups._generic_sheet', ['sheet' => $sheet, 'breakAfter' => $breakAfter])
@endif
