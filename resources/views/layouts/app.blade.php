<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', config('app.lang_html')) }}">
<head {!!   $og ?? '' !!}>
<meta charset="UTF-8">
<meta http-equiv="X-UA-Compatible" content="ie=edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=2.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<base href="{{  config('app.url') }}">
<title>@yield('title')</title>
<!-- Только CSS -->
@yield('css')
<script>
</script>
</head>
<body class="{{ $class ?? '' }}  page-{{ str_replace('.', '_', Route::currentRouteName()) }}">
<header id="header" class="top-header">
@yield('header')
@yield('nav')
</header>
@yield('content')
@yield('footer')
<!-- JS-->
@yield('js')
<!-- JS-->
</body>
</html>