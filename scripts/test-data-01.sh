#!/usr/bin/env bash
set -euo pipefail

dataset_dir="testdata/data-01-v1"
expected_case_numbers='["BK-000003","BK-000002","BK-000108","BK-000349","BK-000135","BK-000467","BK-000006","BK-000699","BK-000693","BK-000716","BK-000138","BK-000264","BK-000041","BK-000427","BK-000059","BK-000709","BK-000235","BK-000697","BK-000787","BK-000788","BK-000082","BK-000646","BK-000707","BK-000660","BK-000755","BK-001017","BK-000593","BK-000996","BK-000915","BK-000067","BK-001023","BK-001062","BK-000575","BK-000698","BK-000250","BK-000017","BK-000720","BK-000104","BK-000203","BK-000426","BK-000531","BK-000781","BK-000545","BK-000383","BK-000703","BK-000889","BK-000890"]'

for file in books.json authors.json manifest.json; do
    jq -e . "$dataset_dir/$file" >/dev/null
done

jq -e --argjson expected "$expected_case_numbers" '
  (.books | map(.bookNumber)) as $actual
  | ($actual | length) == 47
  and ($actual | unique | length) == 47
  and (($actual | sort) == ($expected | sort))
' "$dataset_dir/books.json" >/dev/null

jq -e '
  (.books | map(.id)) as $bookIds
  | (.copies | all(.bookId as $id | $bookIds | index($id)))
  and (.wishlistItems | all(.bookId as $id | $bookIds | index($id)))
  and (.books | all(.variantOfBookId as $id | $id == null or ($bookIds | index($id))))
' "$dataset_dir/books.json" >/dev/null

jq -e --slurpfile books "$dataset_dir/books.json" '
  ($books[0].books | map(.authorIds // []) | add | unique) as $referencedAuthorIds
  | (.authors | map(.id)) as $authorIds
  | ($referencedAuthorIds - $authorIds | length) == 0
' "$dataset_dir/authors.json" >/dev/null

jq -e --slurpfile books "$dataset_dir/books.json" '
  ($books[0].books | map({(.bookNumber): .}) | add) as $byNumber
  | (.cases | length) == 47
  and (.cases | map(.v1_book_number) | unique | length) == 47
  and (.cases | all(
      .case_id == ("DATA-01-" + .v1_book_number)
      and $byNumber[.v1_book_number].id == .v1_internal_id
      and $byNumber[.v1_book_number].title == .title
      and (.categories | length) > 0
      and (.reason | length) > 0
  ))
' "$dataset_dir/manifest.json" >/dev/null

printf 'PASS: DATA-01 has 47 exact V1 bookNumber cases and no included dangling references.\n'
