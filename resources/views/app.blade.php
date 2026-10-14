<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#07090d">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        <title inertia>{{ config('app.name') }}</title>

        @fonts
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead
    </head>
    <body class="bg-ink-950 font-sans text-slate-200 antialiased">
        @inertia
    </body>
</html>
