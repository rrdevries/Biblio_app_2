#!/usr/bin/env python3
"""Reproducible, read-only metadata-provider benchmark for Biblio V1 data."""

from __future__ import annotations

import argparse
import bz2
import csv
import hashlib
import json
import os
import random
import re
import statistics
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from collections import Counter, defaultdict
from datetime import datetime, timezone
from difflib import SequenceMatcher
from pathlib import Path
from typing import Any, Iterable

try:
    import isbnlib
except ImportError:  # Only required for the Wikidata P212 storage format.
    isbnlib = None


ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "tools" / "metadata-benchmark" / "output"
CACHE = OUT / "cache"
SAMPLE_CSV = OUT / "metadata-benchmark-sample.csv"
RESULTS_CSV = OUT / "metadata-benchmark-results.csv"
FIELD_CSV = OUT / "metadata-benchmark-field-results.csv"
SUMMARY_JSON = OUT / "metadata-benchmark-summary.json"
SEED = 20260905
PROVIDERS = ("open_library", "google_books", "wikidata", "bookbrainz")
LANG_MAP = {
    "nl": "nl", "nld": "nl", "dut": "nl", "dutch": "nl", "nederlands": "nl",
    "en": "en", "eng": "en", "english": "en", "engels": "en",
}


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def clean_isbn(value: Any) -> str:
    return re.sub(r"[^0-9Xx]", "", str(value or "")).upper()


def valid_isbn10(value: str) -> bool:
    if not re.fullmatch(r"\d{9}[\dX]", value):
        return False
    return sum((10 - i) * (10 if c == "X" else int(c)) for i, c in enumerate(value)) % 11 == 0


def valid_isbn13(value: str) -> bool:
    if not re.fullmatch(r"\d{13}", value):
        return False
    total = sum(int(c) * (3 if i % 2 else 1) for i, c in enumerate(value[:12]))
    return (10 - total % 10) % 10 == int(value[-1])


def isbn10_to_13(value: str) -> str:
    body = "978" + value[:9]
    total = sum(int(c) * (3 if i % 2 else 1) for i, c in enumerate(body))
    return body + str((10 - total % 10) % 10)


def isbn13_to_10(value: str) -> str:
    if not value.startswith("978"):
        return ""
    body = value[3:12]
    total = sum((10 - i) * int(c) for i, c in enumerate(body))
    check = (11 - total % 11) % 11
    return body + ("X" if check == 10 else str(check))


def norm_text(value: Any) -> str:
    value = unicodedata.normalize("NFKD", str(value or "").casefold())
    value = "".join(c for c in value if not unicodedata.combining(c))
    return " ".join(re.findall(r"[a-z0-9]+", value))


def norm_language(value: Any) -> str:
    return LANG_MAP.get(norm_text(value), "other" if value else "unknown")


def split_pipe(value: Any) -> list[str]:
    return [x.strip() for x in str(value or "").split("|") if x.strip()]


def similarity(a: Any, b: Any) -> float:
    na, nb = norm_text(a), norm_text(b)
    return SequenceMatcher(None, na, nb).ratio() if na and nb else 0.0


def first_year(value: Any) -> str:
    match = re.search(r"(?:1[5-9]|20)\d{2}", str(value or ""))
    return match.group(0) if match else ""


def canonical_isbns(book: dict[str, Any]) -> tuple[str, str, str, str]:
    candidates = [clean_isbn(book.get(k)) for k in ("isbn13", "isbn10", "isbn")]
    isbn13 = next((x for x in candidates if valid_isbn13(x)), "")
    isbn10 = next((x for x in candidates if valid_isbn10(x)), "")
    if not isbn13 and isbn10:
        isbn13 = isbn10_to_13(isbn10)
    if not isbn10 and isbn13:
        isbn10 = isbn13_to_10(isbn13)
    raw = next((x for x in candidates if x), "")
    state = "valid" if isbn13 else ("invalid" if raw else "missing")
    return isbn13, isbn10, raw, state


def book_family(book: dict[str, Any]) -> str:
    categories = set(book.get("categories") or [])
    book_type = book.get("bookType") or ""
    if categories & {"Kind & Jeugd", "Beeldverhaal"} or book_type in {"Jeugdboek", "Kinderboek", "Stripboek"}:
        return "juvenile"
    if categories & {"Koken & Voeding", "Reizen & Cultuur", "Naslag & Kennis"} or book_type in {"Kookboek", "Studieboek"}:
        return "reference"
    if "Non-fictie" in categories or book_type == "Kennisboek":
        return "nonfiction"
    if "Fictie" in categories or book_type == "Leesboek":
        return "fiction"
    return "other"


def richness(book: dict[str, Any]) -> int:
    fields = ("title", "authors", "language", "publisher", "publishDate", "pages", "seriesName", "coverUrl")
    return sum(bool(book.get(f)) for f in fields)


def csv_write(path: Path, rows: list[dict[str, Any]], fields: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def csv_read(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def json_write(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as handle:
        json.dump(value, handle, ensure_ascii=False, indent=2, sort_keys=True)
        handle.write("\n")


def prepare(args: argparse.Namespace) -> None:
    zip_path = Path(args.zip).expanduser().resolve()
    with zipfile.ZipFile(zip_path) as archive:
        package = json.loads(archive.read("Biblio/package.json"))
        dataset = json.loads(archive.read("Biblio/data/books.json"))

    books = dataset["books"]
    grouped: dict[str, list[dict[str, Any]]] = defaultdict(list)
    invalid: list[dict[str, str]] = []
    raw_counts = Counter()
    for book in books:
        isbn13, isbn10, raw, state = canonical_isbns(book)
        if state == "valid":
            grouped[isbn13].append(book)
            if any(valid_isbn13(clean_isbn(book.get(k))) for k in ("isbn13", "isbn")):
                raw_counts["with_isbn13"] += 1
            else:
                raw_counts["only_isbn10"] += 1
        elif state == "invalid":
            raw_counts["invalid"] += 1
            invalid.append({"title": book.get("title", ""), "raw_isbn": raw})
        else:
            raw_counts["without_isbn"] += 1

    candidates = []
    for isbn13, records in grouped.items():
        book = sorted(records, key=lambda b: (-richness(b), str(b.get("title", ""))))[0]
        _, isbn10, raw, _ = canonical_isbns(book)
        language = norm_language(book.get("language"))
        candidates.append({
            "isbn13": isbn13,
            "isbn10": isbn10,
            "source_isbn": raw,
            "title": str(book.get("title") or "").strip(),
            "subtitle": str(book.get("subtitle") or "").strip(),
            "authors": " | ".join(str(x).strip() for x in (book.get("authors") or []) if str(x).strip()),
            "language": language,
            "language_local_raw": str(book.get("language") or ""),
            "publisher": str(book.get("publisher") or "").strip(),
            "publication_date": str(book.get("publishDate") or "").strip(),
            "pages": str(book.get("pages") or ""),
            "series_name": str(book.get("seriesName") or "").strip(),
            "series_position": str(book.get("seriesNumber") or "").strip(),
            "cover_present": "yes" if book.get("coverUrl") else "no",
            "category": " | ".join(book.get("categories") or []),
            "book_type": str(book.get("bookType") or ""),
            "family": book_family(book),
            "local_record_count": str(len(records)),
            "only_isbn10_source": not any(valid_isbn13(clean_isbn(r.get(k))) for r in records for k in ("isbn13", "isbn")),
            "edge": bool(book.get("hasMultipleWorks") or book.get("containedWorks") or len(records) > 1 or richness(book) <= 3 or language not in {"nl", "en"}),
        })

    rng = random.Random(SEED)
    selected: list[dict[str, Any]] = []
    used: set[str] = set()
    targets = [
        ("nl_fiction", 60, lambda x: x["language"] == "nl" and x["family"] == "fiction"),
        ("nl_knowledge_nonfiction", 40, lambda x: x["language"] == "nl" and x["family"] == "nonfiction"),
        ("nl_juvenile_picture_comic", 30, lambda x: x["language"] == "nl" and x["family"] == "juvenile"),
        ("nl_cook_travel_reference", 30, lambda x: x["language"] == "nl" and x["family"] == "reference"),
        ("en_fiction", 60, lambda x: x["language"] == "en" and x["family"] == "fiction"),
        ("en_nonfiction_knowledge", 30, lambda x: x["language"] == "en" and x["family"] in {"nonfiction", "reference"}),
        ("older_isbn10_or_edition", 30, lambda x: x["only_isbn10_source"] or (first_year(x["publication_date"]) and int(first_year(x["publication_date"])) <= 1990)),
        ("difficult_edge", 20, lambda x: x["edge"]),
    ]

    def take(name: str, count: int, predicate: Any) -> int:
        pool = [x for x in candidates if x["isbn13"] not in used and predicate(x)]
        pool.sort(key=lambda x: x["isbn13"])
        rng.shuffle(pool)
        chosen = pool[:count]
        for row in chosen:
            row["stratum"] = name
            selected.append(row)
            used.add(row["isbn13"])
        return count - len(chosen)

    shortages = []
    for name, count, predicate in targets:
        shortage = take(name, count, predicate)
        if shortage:
            shortages.append((name, shortage))

    # Keep N=300 while documenting proportional target shortfalls. Fill only
    # from still-unused NL/EN records, then other edge records.
    if len(selected) < 300:
        pool = [x for x in candidates if x["isbn13"] not in used and x["language"] in {"nl", "en"}]
        pool.sort(key=lambda x: x["isbn13"])
        rng.shuffle(pool)
        for row in pool[: 300 - len(selected)]:
            row["stratum"] = "proportional_shortfall_fill"
            selected.append(row)
            used.add(row["isbn13"])

    selected.sort(key=lambda x: (x["stratum"], x["isbn13"]))
    for index, row in enumerate(selected, 1):
        row["sample_id"] = f"MB-{index:03d}"
        row["ground_truth_limitations"] = "local bibliographic reference; provider agreement does not by itself prove truth"
        row.pop("only_isbn10_source", None)
        row.pop("edge", None)
        row.pop("family", None)

    fields = [
        "sample_id", "isbn13", "isbn10", "source_isbn", "title", "subtitle", "authors",
        "language", "language_local_raw", "publisher", "publication_date", "pages",
        "series_name", "series_position", "cover_present", "category", "book_type",
        "stratum", "local_record_count", "ground_truth_limitations",
    ]
    csv_write(SAMPLE_CSV, selected, fields)
    source_sha = hashlib.sha256(zip_path.read_bytes()).hexdigest()
    report = {
        "generated_at": utc_now(), "seed": SEED, "source_file": zip_path.name,
        "source_sha256": source_sha, "package_version": package.get("version"),
        "schema_version": dataset.get("schemaVersion"), "book_records": len(books),
        "copy_records": len(dataset.get("copies") or []), "wishlist_items": len(dataset.get("wishlistItems") or []),
        "records_with_isbn13": raw_counts["with_isbn13"], "records_only_isbn10": raw_counts["only_isbn10"],
        "records_without_isbn": raw_counts["without_isbn"], "records_invalid_isbn": raw_counts["invalid"],
        "valid_isbn_records": sum(len(v) for v in grouped.values()), "unique_valid_isbns": len(grouped),
        "duplicate_isbn_groups": sum(1 for v in grouped.values() if len(v) > 1),
        "sample_n": len(selected), "sample_strata": Counter(x["stratum"] for x in selected),
        "sample_languages": Counter(x["language"] for x in selected),
        "target_shortfalls_before_fill": dict(shortages), "invalid_isbn_records": invalid,
    }
    json_write(OUT / "dataset-and-sample-report.json", report)
    print(json.dumps({k: report[k] for k in ("source_file", "package_version", "schema_version", "book_records", "copy_records", "wishlist_items", "records_with_isbn13", "records_only_isbn10", "records_without_isbn", "records_invalid_isbn", "unique_valid_isbns", "sample_n", "sample_strata", "sample_languages")}, indent=2, default=dict))


def request_json(url: str, *, provider: str, safe_query: str, timeout: int = 45, method: str = "GET", data: bytes | None = None) -> tuple[dict[str, Any], dict[str, Any]]:
    headers = {
        "Accept": "application/json",
        "User-Agent": "BiblioMetadataBenchmark/1.0 (research; contact via repository owner)",
        "Accept-Encoding": "identity",
    }
    start = time.perf_counter()
    status = 0
    try:
        req = urllib.request.Request(url, data=data, method=method, headers=headers)
        with urllib.request.urlopen(req, timeout=timeout) as response:
            status = response.status
            payload = json.loads(response.read().decode("utf-8"))
        error = ""
    except urllib.error.HTTPError as exc:
        status = exc.code
        raw = exc.read().decode("utf-8", "replace")
        try:
            payload = json.loads(raw)
        except json.JSONDecodeError:
            payload = {"error": {"message": raw[:500]}}
        error = str(payload.get("error", {}).get("message") or f"HTTP {status}")
    except Exception as exc:  # URL deliberately omitted to prevent credential leakage.
        payload = {"error": {"message": type(exc).__name__}}
        error = type(exc).__name__
    elapsed = round((time.perf_counter() - start) * 1000, 2)
    meta = {"provider": provider, "timestamp": utc_now(), "query": safe_query, "http_status": status, "response_ms": elapsed, "error": error}
    return payload, meta


def cache_write(provider: str, key: str, payload: Any, meta: dict[str, Any]) -> None:
    json_write(CACHE / provider / f"{key}.json", {"request": meta, "response": payload})


def fetch_open_library(sample: list[dict[str, str]]) -> None:
    provider = "open_library"
    cache_dir = CACHE / provider
    if len(list(cache_dir.glob("batch-*.json"))) and all((cache_dir / f"record-{r['sample_id']}.json").exists() for r in sample):
        print("Open Library: complete cache reused")
        return
    batch_size = 40
    for offset in range(0, len(sample), batch_size):
        batch = sample[offset: offset + batch_size]
        key = f"batch-{offset // batch_size + 1:02d}"
        bibkeys = ",".join("ISBN:" + x["isbn13"] for x in batch)
        query = urllib.parse.urlencode({"bibkeys": bibkeys, "jscmd": "details", "format": "json"})
        url = "https://openlibrary.org/api/books?" + query
        payload, meta = request_json(url, provider=provider, safe_query=f"batch exact ISBN; N={len(batch)}")
        cache_write(provider, key, payload, meta)
        for row in batch:
            response = payload.get("ISBN:" + row["isbn13"])
            cache_write(provider, f"record-{row['sample_id']}", response, {**meta, "query": f"isbn:{row['isbn13']}"})
        time.sleep(1.05)
    print("Open Library: fetched")


def fetch_google(sample: list[dict[str, str]]) -> None:
    provider = "google_books"
    key = os.environ.get("GOOGLE_BOOKS_API_KEY", "")
    print("Google Books API key available: " + ("yes" if key else "no"))
    if not key:
        raise SystemExit("GOOGLE_BOOKS_API_KEY missing")
    cache_dir = CACHE / provider
    complete = all((cache_dir / f"record-{r['sample_id']}.json").exists() for r in sample)
    if complete:
        # Keyless 429 and transient error caches are intentionally regenerated.
        statuses = [json.loads((cache_dir / f"record-{r['sample_id']}.json").read_text())["request"]["http_status"] for r in sample]
        if all(s == 200 for s in statuses):
            print("Google Books: complete keyed cache reused")
            return
    for index, row in enumerate(sample, 1):
        path = cache_dir / f"record-{row['sample_id']}.json"
        if path.exists():
            old = json.loads(path.read_text())
            if old.get("request", {}).get("http_status") == 200:
                continue
        public_query = {"q": "isbn:" + row["isbn13"], "maxResults": "10", "projection": "full", "printType": "books"}
        actual_query = {**public_query, "key": key}
        url = "https://www.googleapis.com/books/v1/volumes?" + urllib.parse.urlencode(actual_query)
        payload, meta = request_json(url, provider=provider, safe_query=urllib.parse.urlencode(public_query))
        cache_write(provider, f"record-{row['sample_id']}", payload, meta)
        if meta["http_status"] in {429, 500, 502, 503, 504}:
            for delay in (1.5, 3.0, 6.0):
                time.sleep(delay)
                payload2, meta2 = request_json(url, provider=provider, safe_query=urllib.parse.urlencode(public_query))
                cache_write(provider, f"record-{row['sample_id']}", payload2, meta2)
                if meta2["http_status"] == 200:
                    break
        time.sleep(0.12)
        if index % 25 == 0:
            print(f"Google Books: {index}/{len(sample)}")
    print("Google Books: fetched")


def fetch_wikidata(sample: list[dict[str, str]]) -> None:
    provider = "wikidata"
    cache_dir = CACHE / provider
    version = "wikidata_p212_masked_v3_timing"
    batch_files = sorted(cache_dir.glob("batch-*.json"))
    version_ok = len(batch_files) == 6 and all(json.loads(p.read_text()).get("request", {}).get("benchmark_query_version") == version for p in batch_files)
    if version_ok and all((cache_dir / f"record-{r['sample_id']}.json").exists() for r in sample):
        print("Wikidata: complete cache reused")
        return
    if isbnlib is None:
        raise SystemExit("isbnlib is required for Wikidata P212 masking; install requirements.txt")
    by_isbn = {x["isbn13"]: x for x in sample}
    aggregated: dict[str, list[dict[str, str]]] = defaultdict(list)
    timing_by_isbn: dict[str, dict[str, Any]] = {}
    batch_size = 50
    for offset in range(0, len(sample), batch_size):
        batch = sample[offset: offset + batch_size]
        values = " ".join(json.dumps(isbnlib.mask(x["isbn13"])) for x in batch)
        query = f"""
SELECT ?storedIsbn ?edition ?editionLabel ?title ?author ?authorLabel ?publisher ?publisherLabel
       ?date ?language ?languageLabel ?pages ?image ?work ?workLabel ?series ?seriesLabel ?ordinal
WHERE {{
  VALUES ?storedIsbn {{ {values} }}
  ?edition wdt:P212 ?storedIsbn .
  OPTIONAL {{ ?edition wdt:P1476 ?title . }}
  OPTIONAL {{ ?edition wdt:P50 ?author . }}
  OPTIONAL {{ ?edition wdt:P123 ?publisher . }}
  OPTIONAL {{ ?edition wdt:P577 ?date . }}
  OPTIONAL {{ ?edition wdt:P407 ?language . }}
  OPTIONAL {{ ?edition wdt:P1104 ?pages . }}
  OPTIONAL {{ ?edition wdt:P18 ?image . }}
  OPTIONAL {{ ?edition wdt:P629 ?work . }}
  OPTIONAL {{ ?edition p:P179 ?seriesStatement . ?seriesStatement ps:P179 ?series . OPTIONAL {{ ?seriesStatement pq:P1545 ?ordinal . }} }}
  SERVICE wikibase:label {{ bd:serviceParam wikibase:language "nl,en". }}
}}"""
        data = urllib.parse.urlencode({"query": query, "format": "json"}).encode()
        payload, meta = request_json("https://query.wikidata.org/sparql", provider=provider, safe_query=f"exact masked P212 batch; N={len(batch)}", timeout=60, method="POST", data=data)
        meta["benchmark_query_version"] = version
        cache_write(provider, f"batch-{offset // batch_size + 1:02d}", payload, meta)
        for row in batch:
            timing_by_isbn[row["isbn13"]] = meta
        for binding in payload.get("results", {}).get("bindings", []):
            isbn = clean_isbn(binding.get("storedIsbn", {}).get("value", ""))
            aggregated[isbn].append({k: v.get("value", "") for k, v in binding.items()})
        time.sleep(0.5)
    for isbn, row in by_isbn.items():
        batch_meta = timing_by_isbn[isbn]
        cache_write(provider, f"record-{row['sample_id']}", aggregated.get(isbn, []), {**batch_meta, "query": f"masked P212:{isbn}"})
    print("Wikidata: fetched")


def scan_copy_table(dump: Path, wanted: set[str]) -> dict[str, list[list[str]]]:
    found = {name: [] for name in wanted}
    current = ""
    copy_re = re.compile(r"^COPY (?:bookbrainz|musicbrainz)\.([a-z0-9_]+) ")
    with bz2.open(dump, "rt", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            if not current:
                match = copy_re.match(line)
                if match and match.group(1) in wanted:
                    current = match.group(1)
            elif line == "\\.\n":
                current = ""
            else:
                found[current].append(line.rstrip("\n").split("\t"))
    return found


def extract_bookbrainz(sample: list[dict[str, str]], dump_path: Path) -> None:
    provider = "bookbrainz"
    cache_dir = CACHE / provider
    version = "bookbrainz_dump_v2_author_credits"
    complete = all((cache_dir / f"record-{r['sample_id']}.json").exists() for r in sample)
    version_ok = complete and all(json.loads((cache_dir / f"record-{r['sample_id']}.json").read_text()).get("request", {}).get("benchmark_query_version") == version for r in sample)
    if version_ok:
        print("BookBrainz: complete derived dump cache reused")
        return
    wanted_isbns = {x["isbn13"] for x in sample} | {x["isbn10"] for x in sample if x["isbn10"]}
    pass1 = scan_copy_table(dump_path, {"identifier", "identifier_set__identifier"})
    matched_identifier_ids: dict[str, str] = {}
    for row in pass1["identifier"]:
        if len(row) >= 3 and row[1] in {"9", "10"} and clean_isbn(row[2]) in wanted_isbns:
            matched_identifier_ids[row[0]] = clean_isbn(row[2])
    matched_sets: dict[str, set[str]] = defaultdict(set)
    for row in pass1["identifier_set__identifier"]:
        if len(row) >= 2 and row[1] in matched_identifier_ids:
            matched_sets[row[0]].add(matched_identifier_ids[row[1]])
    del pass1

    tables = scan_copy_table(dump_path, {
        "alias", "alias_set", "edition_data", "edition_revision", "edition_header",
        "author_credit_name",
        "language", "language_set__language", "publisher_set__publisher", "publisher_header",
        "publisher_revision", "publisher_data", "release_event_set__release_event", "release_event",
        "relationship_set__relationship", "relationship", "relationship_type", "entity",
        "work_header", "work_revision", "work_data", "series_header", "series_revision", "series_data",
    })
    alias_name = {r[0]: r[1] for r in tables["alias"] if len(r) >= 2}
    alias_default = {r[0]: r[1] for r in tables["alias_set"] if len(r) >= 2}
    language = {r[0]: (r[3] if len(r) > 3 and r[3] != "\\N" else r[1]) for r in tables["language"]}
    language_sets: dict[str, list[str]] = defaultdict(list)
    for r in tables["language_set__language"]:
        if len(r) >= 2:
            language_sets[r[0]].append(language.get(r[1], r[1]))

    edition_data = {r[0]: r for r in tables["edition_data"] if len(r) >= 18 and r[2] in matched_sets}
    revision_to_edition = {r[0]: (r[1], r[2]) for r in tables["edition_revision"] if len(r) >= 3 and r[2] in edition_data}
    editions = {r[0]: revision_to_edition[r[1]][1] for r in tables["edition_header"] if len(r) >= 2 and r[1] in revision_to_edition}
    matched_bbids = set(editions)

    publisher_revision = {r[0]: (r[1], r[2]) for r in tables["publisher_revision"] if len(r) >= 3}
    publisher_data = {r[0]: r for r in tables["publisher_data"] if len(r) >= 3}
    publisher_headers = {r[0]: publisher_revision.get(r[1], ("", ""))[1] for r in tables["publisher_header"] if len(r) >= 2}
    publisher_names = {}
    for bbid, data_id in publisher_headers.items():
        data = publisher_data.get(data_id)
        if data:
            publisher_names[bbid] = alias_name.get(alias_default.get(data[1], ""), "")
    publisher_sets: dict[str, list[str]] = defaultdict(list)
    for r in tables["publisher_set__publisher"]:
        if len(r) >= 2:
            publisher_sets[r[0]].append(publisher_names.get(r[1], ""))
    author_credits: dict[str, list[str]] = defaultdict(list)
    for r in tables["author_credit_name"]:
        if len(r) >= 4:
            author_credits[r[0]].append(r[3])

    release_events = {r[0]: r[1:4] for r in tables["release_event"] if len(r) >= 4}
    release_sets: dict[str, list[list[str]]] = defaultdict(list)
    for r in tables["release_event_set__release_event"]:
        if len(r) >= 2 and r[1] in release_events:
            release_sets[r[0]].append(release_events[r[1]])

    rel_ids_by_set: dict[str, list[str]] = defaultdict(list)
    for r in tables["relationship_set__relationship"]:
        if len(r) >= 2:
            rel_ids_by_set[r[0]].append(r[1])
    relationships = {r[0]: r for r in tables["relationship"] if len(r) >= 4 and (r[2] in matched_bbids or r[3] in matched_bbids)}
    related_bbids = {b for r in relationships.values() for b in r[2:4] if b not in matched_bbids}
    entity_types = {r[0]: r[1] for r in tables["entity"] if len(r) >= 2 and r[0] in related_bbids}

    def names_for(entity: str) -> dict[str, str]:
        revisions = {r[0]: (r[1], r[2]) for r in tables[f"{entity}_revision"] if len(r) >= 3 and r[1] in related_bbids}
        headers = {r[0]: revisions.get(r[1], ("", ""))[1] for r in tables[f"{entity}_header"] if len(r) >= 2 and r[1] in revisions}
        data_rows = {r[0]: r for r in tables[f"{entity}_data"] if len(r) >= 2}
        return {bbid: alias_name.get(alias_default.get(data_rows.get(data_id, ["", ""])[1], ""), "") for bbid, data_id in headers.items()}

    work_names = names_for("work")
    series_names = names_for("series")
    type_labels = {r[0]: r[1] for r in tables["relationship_type"] if len(r) >= 2}
    isbn_to_sample = {x["isbn13"]: x for x in sample}
    for isbn13, sample_row in isbn_to_sample.items():
        candidates = []
        for bbid, data_id in editions.items():
            data = edition_data[data_id]
            identifiers = matched_sets.get(data[2], set())
            if isbn13 not in identifiers and sample_row["isbn10"] not in identifiers:
                continue
            related = []
            rel_set = data[3]
            for rel_id in rel_ids_by_set.get(rel_set, []):
                rel = relationships.get(rel_id)
                if not rel:
                    continue
                other = rel[3] if rel[2] == bbid else rel[2]
                related.append({"bbid": other, "entity_type": entity_types.get(other, ""), "name": work_names.get(other) or series_names.get(other) or "", "relationship_type": type_labels.get(rel[1], rel[1])})
            dates = release_sets.get(data[10], [])
            candidates.append({
                "bbid": bbid, "title": alias_name.get(alias_default.get(data[1], ""), ""),
                "isbns": sorted(identifiers), "edition_group_bbid": "" if data[6] == "\\N" else data[6],
                "publishers": [x for x in publisher_sets.get(data[8], []) if x],
                "authors": author_credits.get(data[7], []),
                "languages": language_sets.get(data[9], []), "release_dates": dates,
                "pages": "" if data[15] == "\\N" else data[15], "relationships": related,
            })
        meta = {"provider": provider, "timestamp": utc_now(), "query": f"official dump ISBN:{isbn13}", "http_status": 200, "response_ms": None, "error": "", "dump_last_modified": "2026-08-31", "benchmark_query_version": version}
        cache_write(provider, f"record-{sample_row['sample_id']}", candidates, meta)
    print(f"BookBrainz: official dump extracted; matched identifiers={len(matched_identifier_ids)} editions={len(matched_bbids)}")


def fetch(args: argparse.Namespace) -> None:
    sample = csv_read(SAMPLE_CSV)
    if len(sample) != 300:
        raise SystemExit("sample must contain exactly 300 rows")
    fetch_open_library(sample)
    fetch_google(sample)
    fetch_wikidata(sample)
    extract_bookbrainz(sample, Path(args.bookbrainz_dump).resolve())


def unique(values: Iterable[Any]) -> list[str]:
    return sorted({str(x).strip() for x in values if str(x).strip()})


def normalize_provider(provider: str, cache: dict[str, Any], sample: dict[str, str]) -> dict[str, Any]:
    raw, meta = cache.get("response"), cache.get("request", {})
    base = {"provider": provider, "http_status": meta.get("http_status", 0), "response_ms": meta.get("response_ms"), "error": meta.get("error", ""), "candidates": []}
    if provider == "open_library":
        if not raw:
            return base
        d = raw.get("details", {})
        base["candidates"] = [{
            "id": d.get("key", ""), "isbns": unique((d.get("isbn_13") or []) + (d.get("isbn_10") or [])),
            "title": d.get("title", ""), "subtitle": d.get("subtitle", ""),
            "authors": unique(x.get("name", "") for x in d.get("authors", []) if isinstance(x, dict)),
            "publisher": " | ".join(d.get("publishers") or []), "date": d.get("publish_date", ""),
            "language": " | ".join(x.get("key", "").rsplit("/", 1)[-1] for x in d.get("languages", []) if isinstance(x, dict)),
            "pages": d.get("number_of_pages", ""), "cover": bool(d.get("covers")),
            "work_links": unique(x.get("key", "") for x in d.get("works", []) if isinstance(x, dict)),
            "series": " | ".join(d.get("series") or []), "series_position": "", "subjects": unique(d.get("subjects") or []),
        }]
    elif provider == "google_books":
        if not isinstance(raw, dict):
            return base
        for item in raw.get("items", []) or []:
            d = item.get("volumeInfo", {})
            ids = [x.get("identifier", "") for x in d.get("industryIdentifiers", []) if isinstance(x, dict)]
            series = d.get("seriesInfo") or item.get("seriesInfo") or {}
            base["candidates"].append({
                "id": item.get("id", ""), "isbns": unique(ids), "title": d.get("title", ""), "subtitle": d.get("subtitle", ""),
                "authors": unique(d.get("authors") or []), "publisher": d.get("publisher", ""), "date": d.get("publishedDate", ""),
                "language": d.get("language", ""), "pages": d.get("pageCount", ""), "cover": bool(d.get("imageLinks")), "work_links": [],
                "series": series.get("bookDisplayNumber", "") if isinstance(series, dict) else "", "series_position": "",
                "subjects": unique(d.get("categories") or []),
            })
    elif provider == "wikidata":
        grouped: dict[str, list[dict[str, str]]] = defaultdict(list)
        for row in raw or []:
            grouped[row.get("edition", "")].append(row)
        for edition, rows in grouped.items():
            base["candidates"].append({
                "id": edition.rsplit("/", 1)[-1], "isbns": [sample["isbn13"]],
                "title": next((r.get("title") or r.get("editionLabel") for r in rows if r.get("title") or r.get("editionLabel")), ""), "subtitle": "",
                "authors": unique(r.get("authorLabel", "") for r in rows), "publisher": " | ".join(unique(r.get("publisherLabel", "") for r in rows)),
                "date": next((r.get("date", "") for r in rows if r.get("date")), ""), "language": " | ".join(unique(r.get("languageLabel", "") for r in rows)),
                "pages": next((r.get("pages", "") for r in rows if r.get("pages")), ""), "cover": any(r.get("image") for r in rows),
                "work_links": unique(r.get("work", "").rsplit("/", 1)[-1] for r in rows), "series": " | ".join(unique(r.get("seriesLabel", "") for r in rows)),
                "series_position": " | ".join(unique(r.get("ordinal", "") for r in rows)), "subjects": [],
            })
    elif provider == "bookbrainz":
        for d in raw or []:
            rels = d.get("relationships") or []
            works = [x["bbid"] for x in rels if x.get("entity_type") == "Work"]
            series = [x.get("name", "") for x in rels if x.get("entity_type") == "Series"]
            dates = d.get("release_dates") or []
            date = "-".join(x for x in dates[0] if x != "\\N") if dates else ""
            base["candidates"].append({
                "id": d.get("bbid", ""), "isbns": d.get("isbns") or [], "title": d.get("title", ""), "subtitle": "", "authors": d.get("authors") or [],
                "publisher": " | ".join(d.get("publishers") or []), "date": date, "language": " | ".join(d.get("languages") or []),
                "pages": d.get("pages", ""), "cover": False, "work_links": works or ([d.get("edition_group_bbid")] if d.get("edition_group_bbid") else []),
                "series": " | ".join(unique(series)), "series_position": "", "subjects": [],
            })
    return base


def author_match(local: str, providers: list[str]) -> bool:
    locals_ = split_pipe(local)
    return bool(locals_ and providers and any(similarity(a, b) >= 0.72 for a in locals_ for b in providers))


def choose_candidate(normalized: dict[str, Any], sample: dict[str, str]) -> tuple[dict[str, Any] | None, str, str]:
    candidates = normalized["candidates"]
    if not candidates:
        if normalized["http_status"] != 200:
            return None, "ERROR", "provider request failed"
        return None, "MISS", "no provider record"
    exact = [c for c in candidates if sample["isbn13"] in {clean_isbn(x) for x in c["isbns"]} or sample["isbn10"] in {clean_isbn(x) for x in c["isbns"]}]
    pool = exact or candidates
    scored = sorted(pool, key=lambda c: (similarity(sample["title"], c["title"]) + (1 if author_match(sample["authors"], c["authors"]) else 0)), reverse=True)
    best = scored[0]
    title_score = similarity(sample["title"], best["title"])
    authors_ok = author_match(sample["authors"], best["authors"])
    if exact:
        if title_score >= 0.72 and (authors_ok or not best["authors"] or not sample["authors"]):
            return best, "EXACT", "exact ISBN with compatible title/author"
        if title_score >= 0.50 or authors_ok:
            return best, "PROBABLE", "exact ISBN but incomplete or divergent local/provider metadata"
        return best, "WRONG_WORK", "exact identifier assertion conflicts strongly with title/author"
    if len(candidates) > 1:
        return best, "AMBIGUOUS", "multiple candidates without returned exact ISBN"
    if title_score >= 0.72 or authors_ok:
        return best, "WRONG_EDITION", "work-like match without returned queried ISBN"
    return best, "WRONG_WORK", "candidate conflicts with local work reference"


FIELDS = ("isbn_exact", "title", "subtitle", "authors", "language", "publisher", "publication_date", "page_count", "cover", "work_link", "series", "series_position", "subjects")


def assess_field(field: str, sample: dict[str, str], candidate: dict[str, Any] | None) -> str:
    if candidate is None:
        return "MISSING"
    if field == "isbn_exact":
        return "CORRECT" if sample["isbn13"] in {clean_isbn(x) for x in candidate["isbns"]} or sample["isbn10"] in {clean_isbn(x) for x in candidate["isbns"]} else "INCORRECT"
    if field == "cover":
        return "CORRECT" if candidate["cover"] else "MISSING"
    if field == "work_link":
        return "CORRECT" if candidate["work_links"] else "MISSING"
    if field == "subjects":
        return "CORRECT" if candidate["subjects"] else "MISSING"
    mapping = {"title": (sample["title"], candidate["title"]), "subtitle": (sample["subtitle"], candidate["subtitle"]),
               "authors": (sample["authors"], " | ".join(candidate["authors"])), "language": (sample["language"], candidate["language"]),
               "publisher": (sample["publisher"], candidate["publisher"]), "publication_date": (sample["publication_date"], candidate["date"]),
               "page_count": (sample["pages"], candidate["pages"]), "series": (sample["series_name"], candidate["series"]),
               "series_position": (sample["series_position"], candidate["series_position"])}
    local, value = mapping[field]
    if not value:
        return "MISSING"
    if not local or (field == "language" and local not in {"nl", "en"}):
        return "UNVERIFIABLE"
    if field == "language":
        values = [norm_language(x) for x in re.split(r"\s*\|\s*", str(value))]
        return "CORRECT" if local in values else "INCORRECT"
    if field == "publication_date":
        return "CORRECT" if first_year(local) and first_year(local) == first_year(value) else "INCORRECT"
    if field == "page_count":
        try:
            return "CORRECT" if int(float(local)) == int(float(value)) else "INCORRECT"
        except ValueError:
            return "UNVERIFIABLE"
    if field == "authors":
        return "CORRECT" if author_match(local, split_pipe(value)) else "INCORRECT"
    if field == "series_position":
        return "CORRECT" if norm_text(local) == norm_text(value) else "INCORRECT"
    threshold = 0.72 if field in {"title", "series"} else 0.62
    return "CORRECT" if similarity(local, value) >= threshold else "INCORRECT"


def pct(n: int, d: int) -> float | None:
    return round(100 * n / d, 2) if d else None


def metrics_for(rows: list[dict[str, Any]], fields: list[dict[str, Any]]) -> dict[str, Any]:
    n = len(rows)
    cls = Counter(r["classification"] for r in rows)
    out: dict[str, Any] = {"n": n, "valid_isbn_count": n, "hit_rate": pct(sum(cls[x] for x in ("EXACT", "PROBABLE", "WRONG_EDITION", "WRONG_WORK", "AMBIGUOUS")), n)}
    for name in ("EXACT", "PROBABLE", "WRONG_EDITION", "WRONG_WORK", "AMBIGUOUS", "MISS", "ERROR"):
        out[name.lower() + "_rate"] = pct(cls[name], n)
    times = [float(r["response_ms"]) for r in rows if r.get("response_ms") not in (None, "", "None")]
    out["mean_response_ms"] = round(statistics.mean(times), 2) if times else None
    out["median_response_ms"] = round(statistics.median(times), 2) if times else None
    out["api_error_rate"] = pct(cls["ERROR"], n)
    for field in FIELDS:
        subset = [f for f in fields if f["field"] == field]
        counts = Counter(f["assessment"] for f in subset)
        present = counts["CORRECT"] + counts["INCORRECT"] + counts["UNVERIFIABLE"]
        verifiable = counts["CORRECT"] + counts["INCORRECT"]
        out[field + "_coverage"] = pct(present, len(subset))
        out[field + "_accuracy"] = pct(counts["CORRECT"], verifiable)
        out[field + "_verifiable_n"] = verifiable
    sample_ids_with_series = {f["sample_id"] for f in fields if f["field"] == "series" and f.get("local_ground_truth") == "yes"}
    series_rows = [f for f in fields if f["field"] == "series" and f["sample_id"] in sample_ids_with_series]
    series_counts = Counter(f["assessment"] for f in series_rows)
    out["series_subset_n"] = len(series_rows)
    out["series_subset_coverage"] = pct(series_counts["CORRECT"] + series_counts["INCORRECT"] + series_counts["UNVERIFIABLE"], len(series_rows))
    out["correct_series_rate"] = pct(series_counts["CORRECT"], len(series_rows))
    sample_ids_with_position = {f["sample_id"] for f in fields if f["field"] == "series_position" and f.get("local_ground_truth") == "yes"}
    position_rows = [f for f in fields if f["field"] == "series_position" and f["sample_id"] in sample_ids_with_position]
    position_counts = Counter(f["assessment"] for f in position_rows)
    out["series_position_subset_n"] = len(position_rows)
    out["series_position_subset_coverage"] = pct(position_counts["CORRECT"] + position_counts["INCORRECT"] + position_counts["UNVERIFIABLE"], len(position_rows))
    out["correct_series_position_rate"] = pct(position_counts["CORRECT"], len(position_rows))
    return out


def provider_values_conflict(field: str, values: list[str]) -> bool:
    if len(values) < 2:
        return False
    if field == "publication_date":
        years = {first_year(v) for v in values if first_year(v)}
        return len(years) > 1
    if field == "page_count":
        numbers = set()
        for value in values:
            try:
                numbers.add(int(float(value)))
            except ValueError:
                pass
        return len(numbers) > 1
    if field == "language":
        languages = [{norm_language(x) for x in re.split(r"\s*\|\s*", value)} for value in values]
        languages = [x for x in languages if x - {"unknown", "other"}]
        return len(languages) > 1 and not set.intersection(*languages)
    if field == "authors":
        lists = [split_pipe(v) for v in values]
        return any(not any(similarity(a, b) >= 0.72 for a in lists[0] for b in other) for other in lists[1:])
    if field == "series_position":
        return len({norm_text(v) for v in values}) > 1
    threshold = 0.72 if field in {"title", "series"} else 0.62
    return any(similarity(values[0], other) < threshold for other in values[1:])


def combination_metrics(result_rows: list[dict[str, Any]], field_rows: list[dict[str, Any]], sample_rows: list[dict[str, str]]) -> dict[str, Any]:
    by_key = {(r["sample_id"], r["provider"]): r for r in result_rows}
    field_by = {(r["sample_id"], r["provider"], r["field"]): r["assessment"] for r in field_rows}
    combos = {
        "open_library_only": ["open_library"],
        "google_books_only": ["google_books"],
        "open_library_google_fallback": ["open_library", "google_books"],
        "open_library_wikidata_enrichment": ["open_library", "wikidata"],
        "open_library_google_wikidata": ["open_library", "google_books", "wikidata"],
        "full_free_stack": ["open_library", "google_books", "wikidata", "bookbrainz"],
    }
    output: dict[str, Any] = {}
    for combo, providers in combos.items():
        output[combo] = {}
        for language in ("total", "nl", "en"):
            samples = [s for s in sample_rows if language == "total" or s["language"] == language]
            n = len(samples)
            series_samples = [s for s in samples if s["series_name"]]
            hit = correct = work = cover = series = conflicts = 0
            per_field_conflicts = Counter()
            per_field_comparable = Counter()
            for s in samples:
                rs = [by_key[(s["sample_id"], p)] for p in providers]
                hit += any(r["classification"] in {"EXACT", "PROBABLE", "WRONG_EDITION", "WRONG_WORK", "AMBIGUOUS"} for r in rs)
                correct += any(r["classification"] == "EXACT" for r in rs)
                work += any(field_by[(s["sample_id"], p, "work_link")] == "CORRECT" for p in providers)
                cover += any(field_by[(s["sample_id"], p, "cover")] == "CORRECT" for p in providers)
                series += any(field_by[(s["sample_id"], p, "series")] == "CORRECT" for p in providers)
                sample_conflict = False
                for field in ("title", "authors", "publisher", "publication_date", "page_count", "language", "series", "series_position"):
                    vals = [by_key[(s["sample_id"], p)].get("normalized_" + field, "") for p in providers if by_key[(s["sample_id"], p)]["classification"] in {"EXACT", "PROBABLE"}]
                    vals = [v for v in vals if v]
                    if len(vals) >= 2:
                        per_field_comparable[field] += 1
                        if provider_values_conflict(field, vals):
                            per_field_conflicts[field] += 1
                            sample_conflict = True
                conflicts += sample_conflict
            output[combo][language] = {
                "n": n, "combined_hit_rate": pct(hit, n), "combined_correct_edition_rate": pct(correct, n),
                "work_link_coverage": pct(work, n), "cover_coverage": pct(cover, n),
                "series_subset_n": len(series_samples), "series_evidence_coverage": pct(series, len(series_samples)),
                "provider_conflict_rate": pct(conflicts, n),
                "conflicts_by_field": {field: {"comparable_n": per_field_comparable[field], "conflict_n": per_field_conflicts[field], "conflict_rate_of_comparable": pct(per_field_conflicts[field], per_field_comparable[field])} for field in ("title", "authors", "publisher", "publication_date", "page_count", "language", "series", "series_position")},
            }
    ol = output["open_library_only"]
    og = output["open_library_google_fallback"]
    full = output["full_free_stack"]
    for language in ("total", "nl", "en"):
        og[language]["incremental_hit_gain_over_open_library"] = round((og[language]["combined_hit_rate"] or 0) - (ol[language]["combined_hit_rate"] or 0), 2)
        full[language]["incremental_hit_gain_over_open_library_google"] = round((full[language]["combined_hit_rate"] or 0) - (og[language]["combined_hit_rate"] or 0), 2)
    return output


def analyse(_: argparse.Namespace) -> None:
    sample_rows = csv_read(SAMPLE_CSV)
    result_rows: list[dict[str, Any]] = []
    field_rows: list[dict[str, Any]] = []
    for sample in sample_rows:
        for provider in PROVIDERS:
            cache_path = CACHE / provider / f"record-{sample['sample_id']}.json"
            cache = json.loads(cache_path.read_text(encoding="utf-8"))
            normalized = normalize_provider(provider, cache, sample)
            candidate, classification, rationale = choose_candidate(normalized, sample)
            result = {
                "sample_id": sample["sample_id"], "isbn13": sample["isbn13"], "language": sample["language"], "stratum": sample["stratum"],
                "provider": provider, "classification": classification, "rationale": rationale,
                "provider_record_id": candidate.get("id", "") if candidate else "", "http_status": normalized["http_status"],
                "response_ms": normalized["response_ms"], "error_category": "provider_error" if classification == "ERROR" else "",
                "normalized_title": candidate.get("title", "") if candidate else "", "normalized_authors": " | ".join(candidate.get("authors", [])) if candidate else "",
                "normalized_publisher": candidate.get("publisher", "") if candidate else "", "normalized_publication_date": candidate.get("date", "") if candidate else "",
                "normalized_page_count": candidate.get("pages", "") if candidate else "", "normalized_language": candidate.get("language", "") if candidate else "",
                "normalized_series": candidate.get("series", "") if candidate else "", "normalized_series_position": candidate.get("series_position", "") if candidate else "",
            }
            result_rows.append(result)
            for field in FIELDS:
                local_gt = "yes" if ((field == "series" and sample["series_name"]) or (field == "series_position" and sample["series_position"])) else "no"
                field_rows.append({"sample_id": sample["sample_id"], "isbn13": sample["isbn13"], "language": sample["language"], "provider": provider, "field": field, "assessment": assess_field(field, sample, candidate), "local_ground_truth": local_gt})
    csv_write(RESULTS_CSV, result_rows, list(result_rows[0]))
    csv_write(FIELD_CSV, field_rows, list(field_rows[0]))
    provider_metrics: dict[str, Any] = {}
    for provider in PROVIDERS:
        provider_metrics[provider] = {}
        for language in ("total", "nl", "en"):
            rs = [r for r in result_rows if r["provider"] == provider and (language == "total" or r["language"] == language)]
            ids = {r["sample_id"] for r in rs}
            fs = [f for f in field_rows if f["provider"] == provider and f["sample_id"] in ids]
            provider_metrics[provider][language] = metrics_for(rs, fs)
    summary = {
        "benchmark_version": 1, "generated_at": utc_now(), "sample_n": len(sample_rows), "seed": SEED,
        "provider_metrics": provider_metrics, "combination_metrics": combination_metrics(result_rows, field_rows, sample_rows),
        "series_subset_n": sum(bool(s["series_name"]) for s in sample_rows),
        "method_notes": ["EXACT requires exact returned ISBN plus compatible title/author evidence.", "Field accuracy denominators exclude MISSING and UNVERIFIABLE local ground truth.", "Local V1 metadata is a reference, not absolute truth; divergent cases require manual review."],
    }
    json_write(SUMMARY_JSON, summary)
    print(json.dumps({"sample_n": summary["sample_n"], "series_subset_n": summary["series_subset_n"], "provider_metrics": {p: {l: {k: v for k, v in m.items() if k in {"n", "hit_rate", "exact_rate", "probable_rate", "miss_rate", "api_error_rate"}} for l, m in langs.items()} for p, langs in provider_metrics.items()}, "combination_metrics": summary["combination_metrics"]}, indent=2))


def verify(_: argparse.Namespace) -> None:
    sample = csv_read(SAMPLE_CSV)
    results = csv_read(RESULTS_CSV)
    fields = csv_read(FIELD_CSV)
    summary = json.loads(SUMMARY_JSON.read_text(encoding="utf-8"))
    assert len(sample) == 300
    assert len({r["sample_id"] for r in sample}) == 300
    assert len({r["isbn13"] for r in sample}) == 300
    assert all(valid_isbn13(r["isbn13"]) for r in sample)
    assert len(results) == 300 * len(PROVIDERS)
    assert len({(r["sample_id"], r["provider"]) for r in results}) == len(results)
    assert len(fields) == 300 * len(PROVIDERS) * len(FIELDS)
    assert len({(r["sample_id"], r["provider"], r["field"]) for r in fields}) == len(fields)
    assert {r["sample_id"] for r in results} == {r["sample_id"] for r in sample}
    assert all(r["provider"] in PROVIDERS for r in results)
    assert all(r["classification"] in {"EXACT", "PROBABLE", "WRONG_EDITION", "WRONG_WORK", "AMBIGUOUS", "MISS", "ERROR"} for r in results)
    assert all(r["assessment"] in {"CORRECT", "INCORRECT", "MISSING", "UNVERIFIABLE"} for r in fields)
    forbidden_sample_columns = {"id", "notes", "rating", "reviews", "reading_history", "acquisition", "lending", "wishlist"}
    assert not forbidden_sample_columns.intersection(sample[0])
    google = [r for r in results if r["provider"] == "google_books"]
    assert all(r["http_status"] != "429" for r in google), "keyless/quota-failed Google rows remain"
    assert all(r["http_status"] == "200" for r in results)
    assert summary["sample_n"] == 300 and summary["series_subset_n"] == 31
    language_counts = Counter(r["language"] for r in sample)
    for provider in PROVIDERS:
        for language, expected_n in (("total", 300), ("nl", language_counts["nl"]), ("en", language_counts["en"])):
            metrics = summary["provider_metrics"][provider][language]
            assert metrics["n"] == expected_n
            total_rate = sum(metrics[k] or 0 for k in ("exact_rate", "probable_rate", "wrong_edition_rate", "wrong_work_rate", "ambiguous_rate", "miss_rate", "error_rate"))
            assert abs(total_rate - 100) <= 0.08, (provider, language, total_rate)
    assert all("key=" not in p.read_text(encoding="utf-8", errors="ignore") for p in CACHE.rglob("*.json"))
    print(json.dumps({"sample_rows": len(sample), "provider_rows": len(results), "field_rows": len(fields), "sample_provider_unique": True, "isbn_unique_valid": True, "all_provider_http_200": True, "metric_denominators_and_sums": True, "privacy_columns_absent": True, "google_429_rows": 0, "credential_material_in_cache": False}, indent=2))


def main() -> None:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)
    p = sub.add_parser("prepare")
    p.add_argument("--zip", required=True)
    p.set_defaults(func=prepare)
    p = sub.add_parser("fetch")
    p.add_argument("--bookbrainz-dump", required=True)
    p.set_defaults(func=fetch)
    p = sub.add_parser("analyse")
    p.set_defaults(func=analyse)
    p = sub.add_parser("verify")
    p.set_defaults(func=verify)
    args = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
