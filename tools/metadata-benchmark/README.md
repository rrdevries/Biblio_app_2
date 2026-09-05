# Biblio metadata provider benchmark

Isolated research tooling for the Biblio v2.001 metadata-provider benchmark.
It does not load WordPress, Biblio Core, or any Biblio runtime code.

The committed sample contains bibliographic reference fields only. Source data
is read directly from the explicitly selected V1 ZIP and is never modified.
Raw provider responses are cached under `output/cache/`; request metadata never
contains credentials.

Run from the repository root:

```sh
python3 -m pip install -r tools/metadata-benchmark/requirements.txt

python3 tools/metadata-benchmark/benchmark.py prepare \
  --zip "/path/to/Biblio_v507m_actuele-data.zip"

GOOGLE_BOOKS_API_KEY=... python3 tools/metadata-benchmark/benchmark.py fetch \
  --bookbrainz-dump /path/to/latest.sql.bz2

python3 tools/metadata-benchmark/benchmark.py analyse
python3 tools/metadata-benchmark/benchmark.py verify
```

The API key is read only from `GOOGLE_BOOKS_API_KEY`. It is never printed,
cached, or included in request metadata.
