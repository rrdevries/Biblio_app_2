# DATA-01 — representative V1 build/regression dataset

DATA-01 is a compact, tracked source snapshot of the approved 47 V1
`books[].bookNumber` cases. It retains the selected V1 book records unchanged,
their directly linked V1 copies and wishlist items, and the author identities
referenced by those books.

It is reference data for V2 build and regression work. It is **not** a V1-to-V2
migration fixture, provider fixture, schema seed, or a V2 transformation.

Files:

- `books.json`: selected book records plus their directly linked copies and wishlist items;
- `authors.json`: author records directly referenced by the selected books;
- `manifest.json`: machine-readable case index, including V1 `bookNumber`, internal `id`, title, factual categories and reason.

Build only from the approved V1 archive with:

```sh
bash scripts/build-data-01.sh .local/fixture-source/data.zip
```

Validate the committed snapshot with:

```sh
bash scripts/test-data-01.sh
```
