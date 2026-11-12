{{--
    Eugene fork: justified-rows photo wall for client pages (PICSTOME_WEBSITE_STYLE),
    matching eugenebellau.com galleries: every photo in a row shares one height,
    so portraits sit narrower beside landscapes instead of towering over them.
--}}
Alpine.data('photoJustified', () => ({
    gap: 8,
    frame: null,
    resizeObserver: null,
    mutationObserver: null,

    init() {
        this.gap = Number(this.$el.dataset.justifiedGap || 8);
        this.$el.style.position = 'relative';

        this.resizeObserver = new ResizeObserver(() => this.schedule());
        this.resizeObserver.observe(this.$el);

        this.mutationObserver = new MutationObserver(() => this.schedule());
        this.mutationObserver.observe(this.$el, { childList: true });

        this.$el.addEventListener('load', (e) => {
            if (e.target.tagName === 'IMG') this.schedule();
        }, true);

        this.$nextTick(() => this.layout());
    },

    destroy() {
        this.resizeObserver?.disconnect();
        this.mutationObserver?.disconnect();
        if (this.frame) cancelAnimationFrame(this.frame);
    },

    schedule() {
        if (this.frame) return;
        this.frame = requestAnimationFrame(() => {
            this.frame = null;
            this.layout();
        });
    },

    ratio(tile) {
        // Eugene fork: known ratio from the server = final layout before the image loads (no "breathing").
        const known = Number(tile.dataset.ratio);
        if (known > 0) return known;

        const media = tile.querySelector('img, video');
        if (media && media.tagName === 'IMG' && media.naturalWidth > 0 && media.naturalHeight > 0 && ! media.src.endsWith('app-logo-icon.png')) {
            return media.naturalWidth / media.naturalHeight;
        }
        if (media && media.tagName === 'VIDEO' && media.videoWidth > 0) {
            return media.videoWidth / media.videoHeight;
        }
        return 1.5;
    },

    layout() {
        const width = this.$el.clientWidth;
        if (! width) return; // hidden tab; the resize observer lays it out when shown

        const tiles = Array.from(this.$el.querySelectorAll(':scope > [data-photo-card]'));
        if (! tiles.length) {
            this.$el.style.height = '';
            return;
        }

        const gap = this.gap;
        const target = width < 640 ? 200 : (width < 1000 ? 260 : 320);
        const ratios = tiles.map((t) => this.ratio(t));
        let top = 0;

        const place = (idxs, h) => {
            let left = 0;
            idxs.forEach((i, n) => {
                const w = n === idxs.length - 1 && h !== null
                    ? Math.max(0, width - left)
                    : Math.floor(ratios[i] * rowH);
                const tile = tiles[i];
                tile.style.position = 'absolute';
                tile.style.margin = '0';
                tile.style.left = `${left}px`;
                tile.style.top = `${top}px`;
                tile.style.width = `${w}px`;
                tile.style.height = `${Math.round(rowH)}px`;
                left += w + gap;
            });
            top += Math.round(rowH) + gap;
        };

        let row = [];
        let sum = 0;
        let rowH = 0;

        for (let i = 0; i < tiles.length; i++) {
            row.push(i);
            sum += ratios[i];
            rowH = (width - gap * (row.length - 1)) / sum;
            if (rowH <= target) {
                place(row, rowH); // full row: stretch the last tile to the edge
                row = [];
                sum = 0;
            }
        }

        if (row.length) {
            rowH = Math.min(target, (width - gap * (row.length - 1)) / sum);
            place(row, null); // last row: natural widths, left aligned
        }

        this.$el.style.height = `${Math.max(0, top - gap)}px`;
        this.$el.dataset.masonryReady = 'true';
    },
}));
