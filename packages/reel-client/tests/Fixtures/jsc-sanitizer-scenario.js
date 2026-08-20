const sanitizer = window.Reel.__testing.sanitizeEvent;
const snapshot = sanitizer({
    type: 2,
    timestamp: Date.now(),
    data: {
        node: {
            type: 0,
            id: 1,
            childNodes: [
                { type: 2, id: 2, tagName: 'input', attributes: { type: 'hidden', value: 'input-secret', src: 'https://host/private' }, childNodes: [] },
                { type: 2, id: 3, tagName: 'textarea', attributes: { value: 'textarea-secret' }, childNodes: [] },
                { type: 2, id: 4, tagName: 'select', attributes: { value: 'select-secret' }, childNodes: [] },
                { type: 2, id: 5, tagName: 'div', attributes: { contenteditable: 'true' }, childNodes: [{ type: 3, id: 6, textContent: 'editable-secret' }] },
                { type: 2, id: 7, tagName: 'div', attributes: { 'data-page': 'inertia-secret', 'wire:snapshot': 'livewire-secret', 'wire:initial-data': 'legacy-secret' }, childNodes: [] },
                { type: 2, id: 8, tagName: 'a', attributes: { href: 'https://host/private?secret=url#fragment', style: 'width: 20px; background: URL( "https://one" ); mask: url(https://two)' }, childNodes: [] },
                { type: 2, id: 9, tagName: 'img', attributes: { src: 'data:image/png;base64,image-secret', width: '20', height: '10' }, childNodes: [] },
                { type: 2, id: 10, tagName: 'canvas', attributes: { width: '20', height: '10' }, childNodes: [] },
                { type: 2, id: 11, tagName: 'video', attributes: {}, childNodes: [] },
                { type: 2, id: 12, tagName: 'audio', attributes: {}, childNodes: [] }
            ]
        }
    }
});

const cssRules = [
    sanitizer({ type: 3, timestamp: Date.now(), data: { source: 8, id: 1, adds: [{ rule: '.a{background:url(https://one);mask: URL( "https://two" )}' }] } }),
    sanitizer({ type: 3, timestamp: Date.now(), data: { source: 13, id: 1, set: { property: 'background', value: 'linear-gradient(red, blue), url( https://three )' } } }),
    sanitizer({ type: 3, timestamp: Date.now(), data: { source: 15, id: 1, styles: [{ rules: [{ rule: '@font-face{src:URL(https://four)}' }] }] } })
];

print(JSON.stringify({
    snapshot: snapshot,
    attributeMutation: sanitizer({
        type: 3,
        timestamp: Date.now(),
        data: {
            source: 0,
            attributes: [{
                id: 7,
                attributes: {
                    'data-page': 'incremental-inertia-secret',
                    'wire:snapshot': 'incremental-livewire-secret',
                    'wire:initial-data': 'incremental-legacy-secret',
                    href: 'https://host/incremental-secret',
                    style: 'display: block; background: url(https://incremental-secret)'
                }
            }]
        }
    }),
    cssRules: cssRules,
    unsafeCss: sanitizer({ type: 3, timestamp: Date.now(), data: { source: 8, adds: [{ rule: '.a{background:url(unclosed-secret}' }] } }),
    meta: sanitizer({ type: 4, timestamp: Date.now(), data: { href: 'https://host.example/account?token=secret#fragment' } }),
    navigation: sanitizer({ type: 3, timestamp: Date.now(), data: { source: 0, href: 'https://host.example/next?token=secret#fragment' } })
}));
