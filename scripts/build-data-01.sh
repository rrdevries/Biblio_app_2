#!/usr/bin/env bash
set -euo pipefail

# DATA-01 is a source snapshot, not a V1-to-V2 migration. This helper only
# selects the approved V1 records and writes their original JSON values.

source_archive="${1:-.local/fixture-source/data.zip}"
expected_sha256="b2ce31c76401ad929fe539259cb0252523f1749218df3985b4991e9f77eb298f"
books_member="Biblio/data/books.json"
authors_member="Biblio/data/authors.json"
output_dir="testdata/data-01-v1"
case_numbers='["BK-000003","BK-000002","BK-000108","BK-000349","BK-000135","BK-000467","BK-000006","BK-000699","BK-000693","BK-000716","BK-000138","BK-000264","BK-000041","BK-000427","BK-000059","BK-000709","BK-000235","BK-000697","BK-000787","BK-000788","BK-000082","BK-000646","BK-000707","BK-000660","BK-000755","BK-001017","BK-000593","BK-000996","BK-000915","BK-000067","BK-001023","BK-001062","BK-000575","BK-000698","BK-000250","BK-000017","BK-000720","BK-000104","BK-000203","BK-000426","BK-000531","BK-000781","BK-000545","BK-000383","BK-000703","BK-000889","BK-000890"]'

if [[ ! -f "$source_archive" ]]; then
    printf 'DATA-01 source archive not found: %s\n' "$source_archive" >&2
    exit 1
fi

actual_sha256="$(shasum -a 256 "$source_archive" | awk '{print $1}')"
if [[ "$actual_sha256" != "$expected_sha256" ]]; then
    printf 'DATA-01 source hash mismatch: expected %s, got %s\n' "$expected_sha256" "$actual_sha256" >&2
    exit 1
fi

for member in "$books_member" "$authors_member"; do
    if ! unzip -Z1 "$source_archive" "$member" >/dev/null; then
        printf 'DATA-01 source archive misses %s\n' "$member" >&2
        exit 1
    fi
done

temporary_dir="$(mktemp -d)"
trap 'rm -rf "$temporary_dir"' EXIT

unzip -p "$source_archive" "$books_member" > "$temporary_dir/source-books.json"
unzip -p "$source_archive" "$authors_member" > "$temporary_dir/source-authors.json"

jq --argjson cases "$case_numbers" '
  .books as $allBooks
  | [.books[] | select(.bookNumber as $number | $cases | index($number))] as $books
  | ($books | map(.id)) as $bookIds
  | {
      source: {
        archive_sha256: "b2ce31c76401ad929fe539259cb0252523f1749218df3985b4991e9f77eb298f",
        archive_member: "Biblio/data/books.json",
        selected_by: "books[].bookNumber",
        selected_book_numbers: $cases
      },
      schemaVersion,
      books: $books,
      copies: [.copies[] | select(.bookId as $id | $bookIds | index($id))],
      wishlistItems: [.wishlistItems[] | select(.bookId as $id | $bookIds | index($id))]
    }
' "$temporary_dir/source-books.json" > "$temporary_dir/books.json"

jq --slurpfile dataset "$temporary_dir/books.json" '
  ($dataset[0].books | map(.authorIds // []) | add | unique) as $authorIds
  | {
      source: {
        archive_sha256: "b2ce31c76401ad929fe539259cb0252523f1749218df3985b4991e9f77eb298f",
        archive_member: "Biblio/data/authors.json",
        selected_by: "books[].authorIds[]"
      },
      schemaVersion,
      authors: [.authors[] | select(.id as $id | $authorIds | index($id))]
    }
' "$temporary_dir/source-authors.json" > "$temporary_dir/authors.json"

jq --slurpfile dataset "$temporary_dir/books.json" '
  def categories:
    ["approved-core-case", "collection-status-" + (.collectionStatus // "missing"), "read-status-" + (.readStatus // "missing")]
    + if .wishlist == true then ["wishlist"] else [] end
    + if .archive == true then ["archived"] else [] end
    + if ((.readingRounds // []) | length) > 0 then ["embedded-reading-history"] else [] end
    + if ((.circulationRounds // []) | length) > 0 then ["embedded-circulation-history"] else [] end
    + if ((.notes // []) | length) > 0 then ["notes"] else [] end
    + if ((.reviews // []) | length) > 0 then ["reviews"] else [] end
    + if ((.quotes // []) | length) > 0 then ["quotes"] else [] end
    + if ((.containedWorks // []) | length) > 0 then ["contained-works"] else [] end
    + if .variantOfBookId != null then ["variant-link"] else [] end
    + if ((.authorIds // []) | length) > 0 then ["author-identities"] else [] end;
  {
    dataset: "DATA-01",
    kind: "representative-v1-build-regression-source-data",
    source: $dataset[0].source,
    cases: [
      $dataset[0].books[]
      | (categories) as $categories
      | {
          case_id: ("DATA-01-" + .bookNumber),
          v1_book_number: .bookNumber,
          v1_internal_id: .id,
          title: .title,
          categories: $categories,
          reason: ("Goedgekeurde DATA-01-case; behoudt V1-eigenschappen: " + ($categories | join(", ")) + ".")
        }
    ]
  }
' "$temporary_dir/source-books.json" > "$temporary_dir/manifest.json"

mkdir -p "$output_dir"
mv "$temporary_dir/books.json" "$output_dir/books.json"
mv "$temporary_dir/authors.json" "$output_dir/authors.json"
mv "$temporary_dir/manifest.json" "$output_dir/manifest.json"

printf 'DATA-01 built: %s\n' "$output_dir"
