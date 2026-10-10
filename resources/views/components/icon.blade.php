@props(['name' => 'grid'])
@php
$paths = [
 'grid'=>'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
 'calendar'=>'M8 2v4 M16 2v4 M3 10h18 M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2 M7 14h3 M14 14h3 M7 18h3',
 'search'=>'M21 21l-5-5 M19 10a9 9 0 1 1-18 0 9 9 0 0 1 18 0',
 'heart'=>'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8',
 'ticket'=>'M3 5h18v5a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4z M15 5v3 M15 11v2 M15 16v3',
 'star'=>'m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z',
 'clock'=>'M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0 M12 6v6l4 2',
 'repeat'=>'m17 2 4 4-4 4 M3 11V8a2 2 0 0 1 2-2h16 M7 22l-4-4 4-4 M21 13v3a2 2 0 0 1-2 2H3',
 'wallet'=>'M20 7V4H5a3 3 0 0 0 0 6h17v10H5a3 3 0 0 1-3-3V7 M17 14h5 M17 17h1',
 'receipt'=>'M4 3l2 2 2-2 2 2 2-2 2 2 2-2 2 2 2-2v18l-2-2-2 2-2-2-2 2-2-2-2 2-2-2-2 2z M8 9h8 M8 13h8 M8 17h4',
 'message'=>'M21 11a8 8 0 0 1-8 8H3l3-4a8 8 0 1 1 15-4 M8 9h8 M8 13h5',
 'user'=>'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M4 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2',
 'users'=>'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M3 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2 M18 3a4 4 0 0 1 0 8 M22 21v-2a6 6 0 0 0-4-5.5',
 'shield'=>'M12 2 3 6v6c0 5 9 10 9 10s9-5 9-10V6z m-4 10 3 3 5-6',
 'building'=>'M4 22V3h13v19 M17 8h4v14 M8 7h5 M8 11h5 M8 15h5 M9 22v-3h4v3',
 'sparkles'=>'m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5z M20 2v4 M18 4h4',
 'layers'=>'m12 2 10 6-10 6L2 8z M2 12l10 6 10-6 M2 16l10 6 10-6',
 'chart'=>'M3 3v18h18 M7 17v-5 M12 17V7 M17 17v-8',
 'alert'=>'m12 3 10 18H2z M12 9v5 M12 17h.01',
 'history'=>'M3 11a9 9 0 1 1 2 7 M3 4v7h7 M12 7v5l4 2',
 'check'=>'m5 12 4 4L19 6',
 'settings'=>'M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8 M12 2v3 M12 19v3 M2 12h3 M19 12h3 M5 5l2 2 M17 17l2 2 M5 19l2-2 M17 7l2-2',
 'bell'=>'M18 8a6 6 0 0 0-12 0v8l-2 2h16l-2-2z M10 21h4',
 'exchange'=>'M4 7h16l-4-4 M20 17H4l4 4',
 'lock'=>'M7 10V6a5 5 0 0 1 10 0v4 M4 10h16v12H4z M12 15v3',
 'menu'=>'M4 6h16 M4 12h16 M4 18h16',
 'close'=>'m6 6 12 12 M18 6 6 18',
 'arrow'=>'M5 12h14 m-5-5 5 5-5 5',
];
@endphp
<svg {{ $attributes->merge(['class'=>'bb-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['grid'] }}"/></svg>
