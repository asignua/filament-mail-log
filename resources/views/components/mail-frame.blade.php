@props(['html', 'title' => '', 'height' => '65vh'])

{{--
    A mail body is untrusted (it can carry text typed by an anonymous visitor), so it never goes into the
    panel DOM. `sandbox=""` (EMPTY: no allow-scripts, no allow-same-origin, no allow-forms, no
    allow-top-navigation) plus the CSP meta tag that SafeFrame prepends. Blade escapes the attribute value.
--}}
<iframe
    title="{{ $title }}"
    srcdoc="{{ \Asignua\FilamentMailLog\Support\SafeFrame::document($html) }}"
    sandbox=""
    referrerpolicy="no-referrer"
    loading="lazy"
    style="height: {{ $height }}"
    {{ $attributes->merge(['class' => 'w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:ring-white/10']) }}
></iframe>
