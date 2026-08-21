# Known limitations

Reel is experimental software intended for private dogfood and consented pilots. It has not completed the
browser, privacy, performance, cost, or full 30-day retention evidence required for active catalog status.

- Reel records sanitized DOM events, not video. Canvas, WebGL, video, audio, cross-origin iframe fidelity, and
  pixel-video export are unsupported.
- Playback is deliberately network-free. External images, media, fonts, stylesheets, and iframe content become
  placeholders, so visual fidelity is lower than a browser loading the original resources.
- The default policy masks input and contenteditable values but ordinary rendered text can be recorded unless
  the host uses `all_text`, mask/block selectors, or `hiddenFromReel()` routes. Every real-user deployment needs
  the application-specific privacy review in the product requirements.
- Conventional top-level navigation cannot carry an exact Reel header. Correlation can therefore be explicitly
  approximate or ambiguous when several tabs are active.
- Reel exports DOM-event data, not a downloadable video. Backup or object-store access is not a supported replay
  export API.
- Storage, queue, database, and compute usage are charged to the customer's Laravel Cloud account. Reel does not
  meter recordings, but it does not provide infinite capacity.

The full experimental graduation criteria are in [the product requirements](product/reel-prd.md#experimental-graduation-criteria).
