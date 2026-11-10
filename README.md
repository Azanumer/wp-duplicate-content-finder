# WP Duplicate Content Finder

Finds near-duplicate posts and pages on your WordPress site — the copies, rewrites and accidental re-posts that split your SEO across two URLs instead of concentrating it on one.

## How it works

1. **Tools → Duplicate Content** → press **Scan for duplicates**.
2. The scan runs in batches of 50 posts (it auto-continues, so large sites are fine) and builds a 5-word shingle fingerprint of every post above the minimum word count.
3. Every pair is compared with Jaccard similarity. Pairs at or above your threshold are grouped into duplicate groups, oldest post first.
4. Each group lists the posts with their similarity to the keeper. Tick the copies and **Move checked to Trash** — or **Dismiss group** if the overlap is intentional.

## Why shingles, not exact matching

Two posts are rarely byte-identical: intros get rewritten, a paragraph gets added, formatting changes. Five-word shingles catch that — a lightly rewritten copy still shares most of its shingles, while two posts that merely share a topic do not.

## Performance notes

- The min/max shingle-count ratio is a hard upper bound on Jaccard similarity, so pairs that cannot possibly reach the threshold are skipped without an expensive set comparison.
- Short posts (below the minimum word count) are skipped — they match each other too easily.
- Best for sites up to a few thousand posts: fingerprints are stored in an option during the scan, so very large sites should raise the minimum word count or narrow the post types.

## Settings

- **Similarity threshold** (50–95%, default 70%) — posts at or above this are reported as duplicates.
- **Minimum words** (default 50) — shorter posts are ignored.
- **Post types** — any public post type, not just posts and pages.

## Filters

```php
// Change the shingle size (default 5 words)
add_filter( 'wdcf_shingle_size', function() { return 4; } );

// Inspect or alter the tokenized words before fingerprinting
add_filter( 'wdcf_tokenized_words', function( $words, $content ) {
    return $words;
}, 10, 2 );
```

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Go to **Tools → Duplicate Content** and scan.

## Notes

- Only published posts are scanned; drafts, trashed and private posts are ignored.
- Dismissed groups stay hidden until the next scan.
- Uninstalling deletes the plugin's options only — trashed posts stay in the Trash, and no content is ever deleted by the plugin itself.

## License

MIT — see [LICENSE](LICENSE).
