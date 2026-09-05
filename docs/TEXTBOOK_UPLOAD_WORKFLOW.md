# Textbook Upload & Processing Workflow

## Architecture

Four separate stages (never mixed in one HTTP request):

1. **Chunked upload** — resumable 10 MB chunks streamed to disk
2. **Assembly** — concatenate chunks, verify integrity, create textbook record
3. **Process** — extract text + detect units (queue jobs read stored file)
4. **Generate** — AI question generation after admin approves units

Heavy work (OCR, Poppler, PDF parsing, AI) runs **only** in queue workers — never in upload HTTP requests.

## Storage

- Disk: `local` (`config/filesystems.php` → `storage/app/private`)
- Chunk temp path: `textbook-uploads/{upload_session_id}/chunk-{index}.part`
- Final path: `textbooks/{uuid}/original.pdf`
- DB fields: `storage_bucket = 'local'`, `storage_path`, `file_size_bytes`

## Limits

| Layer | Value |
|-------|-------|
| Application max PDF size | **1 GB** (`TEXTBOOK_PDF_MAX_BYTES`) |
| Chunk size | **10 MB** (`TEXTBOOK_UPLOAD_CHUNK_SIZE`, range 8–16 MB) |
| Per-chunk HTTP body | ≤ **17 MB** |
| PHP `upload_max_filesize` | **1100M** (supports legacy single-shot + chunk overhead) |
| PHP `post_max_size` | **1200M** |

Chunked uploads do **not** load the full PDF into PHP memory. Each chunk is streamed to disk; assembly uses `stream_copy_to_stream`.

## API Endpoints

### Chunked upload (recommended)

| Method | Route | Purpose |
|--------|-------|---------|
| POST | `/api/admin/textbooks/uploads/init` | Start upload session (metadata only — no textbook record yet) |
| GET | `/api/admin/textbooks/uploads/{uploadId}` | Resume: list received/missing chunks |
| POST | `/api/admin/textbooks/uploads/{uploadId}/chunks/{chunkIndex}` | Upload one chunk (0-based index) |
| POST | `/api/admin/textbooks/uploads/{uploadId}/complete` | Assemble PDF, create textbook, dispatch `extract_text` |
| DELETE | `/api/admin/textbooks/uploads/{uploadId}` | Cancel and delete temp chunks |

### Legacy single-request upload (deprecated)

| Method | Route | Purpose |
|--------|-------|---------|
| POST | `/api/admin/textbooks` | Create textbook record + upload URL |
| POST | `/api/admin/textbooks/{id}/upload` | Store entire PDF in one request |

### Processing

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/api/admin/textbooks/{id}/status` | Textbook + job status |
| POST | `/api/admin/textbooks/{id}/process` | Start/restart processing from stored file |
| POST | `/api/admin/textbooks/{id}/approve-structure` | Approve units → build chunks |

## Chunked upload flow

```
Client                          Server
  | POST /uploads/init  --------> Create session, return chunk_size + total_chunks
  | POST /chunks/0      --------> Stream chunk 0 to disk (idempotent)
  | POST /chunks/1      --------> Stream chunk 1 to disk
  | ... (retry failed chunks only)
  | POST /complete      --------> Assemble → verify size + PDF header (+ optional SHA-256)
  |                               Create textbook record
  |                               Dispatch extract_text job (async)
  |<--------------------         Return textbook summary
```

### Resumability

- `GET /uploads/{uploadId}` returns `received_chunks` and `missing_chunks`
- Re-upload only missing indices; already-stored chunks with matching size are skipped (idempotent)
- Sessions expire after 24 hours (`textbook-uploads:cleanup` artisan command)

### Integrity checks on complete

1. All chunk indices present
2. Assembled file size equals declared `file_size`
3. PDF magic bytes (`%PDF-`)
4. Optional SHA-256 if client provided `file_hash` at init (files ≤ 256 MB on client)

## Processing Statuses

```
uploaded
  → extracting
  → analyzing_contents
  → awaiting_unit_review
  → units_approved
  → ready
```

## Queue

- Connection: `database`
- Job class: `App\Jobs\RunTextbookProcessingJob`
- Queues: `textbook-extraction`, `textbook-analysis`, `question-generation`

### Local development

```bash
cd backend
npm run dev
```

Verify PHP limits:

```bash
curl http://127.0.0.1:8000/api/health
```

Expect `upload_max_filesize = 1100M` and `post_max_size = 1200M` when using `scripts/serve-dev.ps1`.

### Cleanup incomplete uploads

```bash
php artisan textbook-uploads:cleanup
```

Removes expired sessions and their chunk directories from `storage/app/private/textbook-uploads/`.
