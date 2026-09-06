@extends('pdf.layout')

@section('content')
    @foreach ($payload->sheets as $index => $sheet)
        @include('pdf.groups._sheet', [
            'sheet' => $sheet,
            'breakAfter' => $index < count($payload->sheets) - 1,
        ])
    @endforeach
@endsection
