<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Reel replay</title>
        <style>html,body,#reel-player{width:100%;height:100%;margin:0;overflow:hidden;background:#fff}#reel-diagnostic{box-sizing:border-box;padding:2rem;font:16px/1.5 system-ui;color:#3f3f46}</style>
    </head>
    <body>
        <div id="reel-player"></div>
        <div id="reel-diagnostic" hidden></div>
        <script type="application/json" id="reel-configuration" nonce="{{ $scriptNonce }}">{!! $configuration !!}</script>
        <script type="application/json" id="reel-events" nonce="{{ $scriptNonce }}">{!! $events !!}</script>
        @if ($rrwebRuntime !== '')
            <script nonce="{{ $scriptNonce }}">{!! $rrwebRuntime !!}</script>
        @endif
        <script nonce="{{ $scriptNonce }}">{!! $messageRuntime !!}</script>
        <script nonce="{{ $scriptNonce }}">{!! $playerRuntime !!}</script>
    </body>
</html>
