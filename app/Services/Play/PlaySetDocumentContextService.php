<?php

namespace App\Services\Play;

class PlaySetDocumentContextService
{
    public function storeExcerpt(string $content): string
    {
        $max = (int) config('play_sets.source_store_max_chars', 100000);
        $trimmed = trim($content);

        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed) <= $max) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $max);
    }

    public function excerptForPrompt(string $content): string
    {
        $max = (int) config('play_sets.source_excerpt_max_chars', 12000);
        $trimmed = trim($content);

        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed) <= $max) {
            return $trimmed;
        }

        $headSize = (int) floor($max * 0.4);
        $middleSize = (int) floor($max * 0.2);
        $tailSize = $max - $headSize - $middleSize;
        $middleStart = (int) max(0, floor((mb_strlen($trimmed) - $middleSize) / 2));

        $head = mb_substr($trimmed, 0, $headSize);
        $middle = mb_substr($trimmed, $middleStart, $middleSize);
        $tail = mb_substr($trimmed, -$tailSize);

        return trim($head."\n\n[...]\n\n".$middle."\n\n[...]\n\n".$tail);
    }
}
