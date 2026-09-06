<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>{{ $title ?? 'Planilla' }}</title>
    <style>
        {!! file_get_contents(resource_path('css/pdf.css')) !!}
    </style>
</head>
<body>
    <!-- á é í ó ú ñ 3.º -->
    @yield('content')
</body>
</html>
