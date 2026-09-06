@extends('pdf.layout')

@section('content')
    @include('pdf.groups._sheet', ['sheet' => $sheet, 'breakAfter' => false])
@endsection
