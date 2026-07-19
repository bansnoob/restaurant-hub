@props(['type', 'id', 'state'])

{{-- Accept / decline control for one GCash entry.
     Buttons rather than a form per row: three forms on every row of three tables is a lot of
     markup, so they all post through the single shared form in the page's Alpine component. --}}
<div class="rh-gcash-review">
    @if ($state === 'accepted')
        <span class="rh-gcash-state rh-gcash-state--ok" title="Seen on the GCash statement">In wallet</span>
        <button type="button" class="rh-gcash-state-undo" title="Mark as not yet reviewed"
                @click="setEntryStatus('{{ $type }}', {{ $id }}, 'pending')">undo</button>
    @elseif ($state === 'declined')
        <span class="rh-gcash-state rh-gcash-state--bad" title="Never arrived — excluded from the balance and totals">Not received</span>
        <button type="button" class="rh-gcash-state-undo" title="Mark as not yet reviewed"
                @click="setEntryStatus('{{ $type }}', {{ $id }}, 'pending')">undo</button>
    @else
        <button type="button" class="rh-gcash-state-btn rh-gcash-state-btn--ok" title="I can see this on the GCash statement"
                @click="setEntryStatus('{{ $type }}', {{ $id }}, 'accepted')">Accept</button>
        <button type="button" class="rh-gcash-state-btn rh-gcash-state-btn--bad" title="This never showed up in the wallet"
                @click="setEntryStatus('{{ $type }}', {{ $id }}, 'declined')">Decline</button>
    @endif
</div>
