@unless (\ArtisanBuild\ReelClient\CapturePolicy::isHidden(request()->route()))
    <script defer src="{{ route('reel.assets.rrweb') }}"></script>
    <script
        defer
        src="{{ route('reel.assets.recorder') }}"
        data-reel-grant-url="{{ route('reel.session-grants.store') }}"
        data-reel-url="{{ config('reel.url') }}"
        data-reel-csrf-token="{{ csrf_token() }}"
        data-reel-envelope-version="{{ config('reel.recorder.envelope_version') }}"
        data-reel-recorder-version="{{ config('reel.recorder.version') }}"
        data-reel-rrweb-version="{{ config('reel.recorder.rrweb_version') }}"
        data-reel-compression="{{ config('reel.recorder.compression') }}"
        data-reel-batch-interval="{{ config('reel.recorder.batch_interval_ms') }}"
        data-reel-flush-bytes="{{ config('reel.recorder.flush_bytes') }}"
        data-reel-max-buffer-bytes="{{ config('reel.recorder.max_buffer_bytes') }}"
        data-reel-max-buffer-events="{{ config('reel.recorder.max_buffer_events') }}"
        data-reel-max-pending-uploads="{{ config('reel.recorder.max_pending_uploads') }}"
        data-reel-max-retries="{{ config('reel.recorder.max_retries') }}"
        data-reel-circuit-failures="{{ config('reel.recorder.circuit_failures') }}"
        data-reel-circuit-cooldown="{{ config('reel.recorder.circuit_cooldown_ms') }}"
    ></script>
@endunless
