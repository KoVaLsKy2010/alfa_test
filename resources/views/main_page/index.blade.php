@extends('layouts.app', ['class' => 'bg-default', 'og' => 'prefix="og: http://ogp.me/ns# fb: http://ogp.me/ns/fb# product: http://ogp.me/ns/product#"'])
@section('title', 'Main page')
@section('meta')
    <link rel="canonical" href="{{config('app.url')}}">
    <meta name="description" content="Система для для поиска наилучшего курса для покупки/продажи криптовалюты на бирже">
    <meta name="keywords" content="">
@endsection
@section('css')
<link href="//cdn.jsdelivr.net/npm/bootstrap@5.2.1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-iYQeCzEYFbKjA/T2uDLTpkwGzCiq6soy8tYaI1GyVh/UjpbCx/TYkiZhlZB6+fzT" crossorigin="anonymous">
<link href="//cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" type="text/css" href="/css/main.min.css?v={{ time() }}"><!-- Очень очень очень плохая плохая практика. Только для тестового -->
@endsection
@section('content')
<div class="d-flex justify-content-center align-items-center" style="min-height: 40vh">
    <form action="{{ route('calc') }}" method="post" class="container form__calc js-calc">
        @csrf
        <div class="row">

            <div class="col-sm-6 col-lg-2 mb-5">
                <input type="number" required value="1.4" step="0.000001" name="count" placeholder="Количество" class="js-count input-count">
            </div>

            <div class="col-sm-6 col-lg-4 mb-5">
                <select class="js-from w-100 " name="from">
                    @foreach($coins as $coin)
                        <option value="{{$coin}}" {{ $coin == 'ETH' ? 'selected' : '' }}>{{$coin}}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-sm-6 col-lg-4 mb-5">
                <select class="js-to w-100 " name="to">
                    @foreach($coins as $coin)
                        <option value="{{$coin}}" {{ $coin == 'XEM' ? 'selected' : '' }}>{{$coin}}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-sm-6 col-lg-2 mb-5">
                <button type="submit" class="btn btn-primary w-100">Рассчитать</button>
            </div>

        </div>
    </form>
</div>
<div class="container">
    <div class="js-spinner"></div>
    <div class="js-result"></div>
</div>
@endsection
@section('js')
<script src="//code.jquery.com/jquery-3.6.1.min.js" integrity="sha256-o88AwQnZB+VDvE9tvIXrMQaPlFFSUTR+nldQm1LuPXQ=" crossorigin="anonymous"></script>
<script src="//cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="/js/main.min.js?v={{ time() }}"></script><!-- Очень очень очень плохая плохая практика. Только для тестового -->
@endsection

