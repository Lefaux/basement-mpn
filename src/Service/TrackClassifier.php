<?php

namespace App\Service;

final class TrackClassifier
{
    private const AUDIO_EXTENSIONS = ['wav', 'mp3'];

    /** @param list<array{id:string,name:string,mimeType:string,size?:string,modifiedTime?:string,parents?:array}> $children */
    public function classify(array $project, array $children, ?array $artistFolder, string $path, array $nestedRelevantFiles = []): array
    {
        $artist = $artistFolder['name'] ?? null;
        $als = [];
        $previews = [];
        $renders = [];
        $landr = [];
        $mstr = [];
        $issues = [];
        $files = [];

        foreach ($children as $file) {
            if (($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                continue;
            }
            $name = (string) ($file['name'] ?? '');
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $entry = ['id' => $file['id'], 'name' => $name, 'type' => $extension, 'size' => (int) ($file['size'] ?? 0), 'modifiedTime' => $file['modifiedTime'] ?? null];
            if ($extension === 'als') {
                $als[strtolower($stem)] = $entry;
                $files['als'][] = $entry;
                continue;
            }
            if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                continue;
            }

            $normalStem = strtolower($stem);
            $isLandr = str_starts_with($normalStem, 'landr');
            $isMstr = str_ends_with($normalStem, '_mstr');
            if ($isLandr) {
                $landr[] = $entry;
                $files['landr'][] = $entry;
            } elseif ($isMstr) {
                $mstr[] = $entry;
                $files['mstr'][] = $entry;
            } else {
                $isPreview = $extension === 'wav' ? $entry['size'] < 20_000_000 : $entry['size'] < 5_000_000;
                if ($isPreview) {
                    $previews[] = $entry;
                } else {
                    $renders[] = $entry;
                }
                $files[$isPreview ? 'previews' : 'renders'][] = $entry;
            }
        }

        foreach (array_merge($previews, $renders) as $render) {
            if (!isset($als[strtolower(pathinfo($render['name'], PATHINFO_FILENAME))])) {
                $issues[] = 'Unmatched Render';
                break;
            }
        }
        if ($nestedRelevantFiles) $issues[] = 'Misplaced Project File';

        $release = array_values(array_filter($children, static fn (array $item): bool => ($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder' && strtolower($item['name'] ?? '') === '_release data'));
        $releaseComplete = false;
        if ($release !== []) {
            // The scanner enriches this synthetic marker with direct child information.
            $releaseComplete = (bool) ($project['releaseComplete'] ?? false);
        }

        if ($artist !== null && !$this->hasCleanName($project['name'], $artist, true)) $issues[] = 'Name Inconsistent';

        $stage = $releaseComplete ? 'release' : ($mstr ? 'final' : ($landr ? 'mastered' : ($renders ? 'arrangement' : 'idea')));
        $tags = ['ALS ×'.count($als)];
        foreach ([['previews', 'Preview'], ['renders', 'Render']] as [$key, $label]) {
            foreach (['wav', 'mp3'] as $extension) {
                if (array_filter($files[$key] ?? [], static fn(array $f): bool => $f['type'] === $extension)) $tags[] = "$label ".strtoupper($extension);
            }
        }
        if ($landr) $tags[] = 'LANDR ×'.count($landr);
        if ($mstr) $tags[] = 'MSTR ×'.count($mstr);

        $issues = array_values(array_unique($issues));
        return compact('artist', 'path', 'stage', 'tags', 'issues', 'files');
    }

    private function hasCleanName(string $name, string $artist, bool $project): bool
    {
        // Project folders can legitimately contain dots (for example "vs.").
        // Only file names need extension stripping before validation.
        $stem = $project ? $name : pathinfo($name, PATHINFO_FILENAME);
        if (str_starts_with(strtolower($stem), 'landr-')) $stem = substr($stem, 6);
        $validPrefix = str_starts_with(strtolower($stem), strtolower($artist.' - '));
        if (!$project) return $validPrefix;
        if (!str_ends_with(strtolower($stem), ' project')) return false;
        if ($validPrefix) return true;
        return (bool) preg_match('/\(?'.preg_quote($artist, '/').'\s+(?:edit|remix)\)?/i', $stem);
    }
}
