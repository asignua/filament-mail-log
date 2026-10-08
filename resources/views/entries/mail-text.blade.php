@php($record = $getRecord())

@if (filled($record->text_body))
    <pre class="overflow-x-auto whitespace-pre-wrap rounded-xl bg-gray-50 p-3 text-sm dark:bg-white/5">{{ $record->text_body }}</pre>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('filament-mail-log::filament-mail-log.notices.no_text') }}</p>
@endif
