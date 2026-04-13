<x-filament-panels::page>
    @php
        $record = $this->getRecord();
        $showWarning = $record->isEditable() && $record->items()->doesntExist();
    @endphp

    @if($showWarning)
        <div class="rounded-lg bg-warning-50 p-4 text-sm text-warning-600 ring-1 ring-warning-600/10 dark:bg-warning-400/10 dark:text-warning-500 dark:ring-warning-400/20">
            No items added — this document cannot be confirmed until at least one item is added.
        </div>
    @endif

    {{ $this->content }}
</x-filament-panels::page>
