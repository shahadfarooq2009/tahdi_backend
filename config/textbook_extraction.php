<?php

return [

  /*
  |--------------------------------------------------------------------------
  | External PDF / OCR binaries (Poppler + Tesseract)
  |--------------------------------------------------------------------------
  |
  | OCR is only used for front-matter / TOC pages when native extraction quality
  | is poor. Leave paths null to auto-discover on PATH or common install dirs.
  |
  */
    'tesseract_path' => env('TESSERACT_PATH'),
    'pdftoppm_path' => env('PDFTOPPM_PATH'),
    'pdftotext_path' => env('PDFTOTEXT_PATH'),
    'pdfinfo_path' => env('PDFINFO_PATH'),

    'ocr_enabled' => env('TEXTBOOK_OCR_ENABLED', true),
    'ocr_dpi' => (int) env('TEXTBOOK_OCR_DPI', 200),
    'ocr_language' => env('TEXTBOOK_OCR_LANGUAGE', 'ara'),

    /** PDF pages (1-based) scanned for TOC / front matter. */
    'front_matter_pages' => (int) env('TEXTBOOK_FRONT_MATTER_PAGES', 30),

    /** Minimum quality score (0–1) before trusting native text on a page. */
    'min_page_quality' => (float) env('TEXTBOOK_MIN_PAGE_QUALITY', 0.42),

    /** Average quality across first N pages below this triggers OCR fallback. */
    'front_matter_quality_threshold' => (float) env('TEXTBOOK_FRONT_MATTER_QUALITY_THRESHOLD', 0.45),

    'front_matter_quality_sample_pages' => (int) env('TEXTBOOK_FRONT_MATTER_QUALITY_SAMPLE', 20),

    /** Max pages to OCR per textbook (TOC/front matter only). */
    'max_ocr_pages' => (int) env('TEXTBOOK_MAX_OCR_PAGES', 25),

    /** Persist extraction progress every N pages (and/or every few seconds). */
    'progress_page_interval' => (int) env('TEXTBOOK_PROGRESS_PAGE_INTERVAL', 10),
    'progress_seconds_interval' => (int) env('TEXTBOOK_PROGRESS_SECONDS_INTERVAL', 2),

    /** Native text extraction batch size (Poppler page ranges). */
    'native_extract_batch_pages' => (int) env('TEXTBOOK_NATIVE_EXTRACT_BATCH_PAGES', 10),

    /** smalot/pdfparser is not used above this file size (bytes). */
    'smalot_max_file_bytes' => (int) env('TEXTBOOK_SMALOT_MAX_FILE_BYTES', 50 * 1024 * 1024),

    /** OCR parallelism during a single extraction run (Windows-safe small batches). */
    'ocr_parallel_pages' => (int) env('TEXTBOOK_OCR_PARALLEL_PAGES', 2),

];
