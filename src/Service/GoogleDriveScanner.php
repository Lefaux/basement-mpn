<?php

namespace App\Service;

use App\Entity\Track;
use App\Entity\ScanState;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client;
use Google\Service\Drive;

final class GoogleDriveScanner
{
    private const FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';
    public function __construct(
        private readonly string $driveId,
        private readonly string $credentialsFile,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(GOOGLE_SHARED_ARCHIVE_ID)%')] private readonly string $archiveDriveId,
        private readonly EntityManagerInterface $em,
        private readonly TrackClassifier $classifier,
    ) {}

    public function archive(Track $track): void
    {
        if ($this->archiveDriveId === '') throw new \RuntimeException('Set GOOGLE_SHARED_ARCHIVE_ID.');
        $client = new Client(); $client->setAuthConfig($this->credentialsPath()); $client->setScopes([Drive::DRIVE]); $drive = new Drive($client);
        $artist = $track->getArtist() ?? 'Unknown artist';
        $found = $drive->files->listFiles(['q' => "'{$this->archiveDriveId}' in parents and name = '".str_replace("'", "\\'", $artist)."' and mimeType = 'application/vnd.google-apps.folder' and trashed = false", 'corpora' => 'drive', 'driveId' => $this->archiveDriveId, 'includeItemsFromAllDrives' => true, 'supportsAllDrives' => true]);
        $artistFolder = $found->getFiles()[0] ?? null;
        if (!$artistFolder) $artistFolder = $drive->files->create(new \Google\Service\Drive\DriveFile(['name' => $artist, 'mimeType' => self::FOLDER_MIME_TYPE, 'parents' => [$this->archiveDriveId]]), ['supportsAllDrives' => true, 'fields' => 'id']);
        $date = $this->latestModification($drive, $track->getDriveId())->format('Y-m-d');
        $newName = $date.' - '.$track->getFolderName();
        $drive->files->update($track->getDriveId(), new \Google\Service\Drive\DriveFile(['name' => $newName]), ['addParents' => $artistFolder->getId(), 'removeParents' => $track->getParentDriveId(), 'supportsAllDrives' => true]);
        $track->archive(); $this->em->flush();
    }

    private function latestModification(Drive $drive, string $folderId): \DateTimeImmutable
    {
        $latest = new \DateTimeImmutable('@0');
        $queue = [$folderId];
        while ($queue !== []) {
            $parentId = array_pop($queue);
            $pageToken = null;
            do {
                $result = $this->withRetry(fn () => $drive->files->listFiles([
                    'q' => "'{$parentId}' in parents and trashed = false",
                    'includeItemsFromAllDrives' => true,
                    'supportsAllDrives' => true,
                    'pageToken' => $pageToken,
                    'pageSize' => 1000,
                    'fields' => 'nextPageToken,files(id,mimeType,modifiedTime)',
                ]));
                foreach ($result->getFiles() as $file) {
                    if ($file->getModifiedTime()) {
                        $modified = new \DateTimeImmutable((string) $file->getModifiedTime());
                        if ($modified->getTimestamp() > $latest->getTimestamp()) $latest = $modified;
                    }
                    if ($file->getMimeType() === self::FOLDER_MIME_TYPE) $queue[] = $file->getId();
                }
                $pageToken = $result->getNextPageToken();
            } while ($pageToken);
        }
        return $latest->getTimestamp() === 0 ? new \DateTimeImmutable() : $latest;
    }

    /** @return list<array<string,mixed>> */
    public function listAll(): array
    {
        if ($this->driveId === '' || $this->credentialsFile === '' || !is_readable($this->credentialsPath())) {
            throw new \RuntimeException('Set GOOGLE_SHARED_DRIVE_ID and GOOGLE_SERVICE_ACCOUNT_FILE in .env.local.');
        }
        $client = new Client();
        $client->setAuthConfig($this->credentialsPath());
        $client->setScopes([Drive::DRIVE_READONLY]);
        $drive = new Drive($client);
        $items = []; $page = null;
        do {
            $result = $this->withRetry(fn () => $drive->files->listFiles([
                'corpora' => 'drive', 'driveId' => $this->driveId,
                'includeItemsFromAllDrives' => true, 'supportsAllDrives' => true,
                'q' => 'trashed = false', 'pageToken' => $page, 'pageSize' => 1000,
                'fields' => 'nextPageToken,files(id,name,mimeType,size,modifiedTime,parents)',
            ]));
            foreach ($result->getFiles() as $file) $items[] = (array) $file->toSimpleObject();
            $page = $result->getNextPageToken();
        } while ($page);
        return $items;
    }

    private function withRetry(callable $request): mixed
    {
        for ($attempt = 0; ; ++$attempt) {
            try { return $request(); }
            catch (\Google\Service\Exception $exception) {
                if ($exception->getCode() !== 403 || $attempt >= 4) throw $exception;
                usleep((1 << $attempt) * 1_000_000);
            }
        }
    }

    public function syncFull(): int
    {
        $items = $this->listAll();
        $byId = []; $children = [];
        foreach ($items as $item) {
            $byId[$item['id']] = $item;
            foreach ($item['parents'] ?? [] as $parentId) $children[$parentId][] = $item;
        }
        $seen = [];
        foreach ($items as $project) {
            if (($project['mimeType'] ?? '') !== self::FOLDER_MIME_TYPE) continue;
            $direct = $children[$project['id']] ?? [];
            $hasProjectInfo = (bool) array_filter($direct, static fn(array $item): bool => ($item['mimeType'] ?? '') === self::FOLDER_MIME_TYPE && strtolower($item['name'] ?? '') === 'ableton project info');
            if (!$hasProjectInfo) continue;
            $parent = $byId[($project['parents'] ?? [])[0] ?? ''] ?? null;
            $releaseFolder = current(array_filter($direct, static fn(array $item): bool => ($item['mimeType'] ?? '') === self::FOLDER_MIME_TYPE && strtolower($item['name'] ?? '') === '_release data')) ?: null;
            $releaseFiles = $releaseFolder ? ($children[$releaseFolder['id']] ?? []) : [];
            $project['releaseComplete'] = $releaseFolder && $this->hasReleaseData($releaseFiles);
            $path = $this->path($project, $byId);
            $clean = $parent !== null && (($parent['parents'] ?? [])[0] ?? null) === $this->driveId;
            // Only a direct child of the Shared Drive is an authoritative artist folder.
            // Legacy nested paths must remain unknown rather than mislabelled with an
            // intermediate workflow folder such as "999 - moved to Studio".
            $title = preg_replace('/\s+project$/i', '', (string) $project['name']);
            $inferredArtist = null;
            if (!$clean && str_contains($title, ' - ')) {
                [$inferredArtist, $title] = explode(' - ', $title, 2);
            }
            $data = $this->classifier->classify($project, $direct, $clean ? $parent : ($inferredArtist ? ['name' => $inferredArtist] : null), $path);
            if (!$clean) $data['issues'][] = 'Invalid Location';
            if ($clean && $parent && str_starts_with(strtolower($title), strtolower($parent['name'].' - '))) $title = substr($title, strlen($parent['name']) + 3);
            $track = $this->em->getRepository(Track::class)->findOneBy(['driveId' => $project['id']]) ?? new Track($project['id'], $project['name'], $path);
            $track->refresh([...$data, 'parentDriveId' => $parent['id'] ?? null, 'folderName' => $project['name'], 'title' => $title, 'cleanLayout' => $clean, 'driveModifiedAt' => isset($project['modifiedTime']) ? new \DateTimeImmutable($project['modifiedTime']) : null]);
            $this->em->persist($track); $seen[] = $project['id'];
        }
        foreach ($this->em->getRepository(Track::class)->findBy(['archived' => false]) as $track) if (!in_array($track->getDriveId(), $seen, true)) $track->archive();
        $client = new Client(); $client->setAuthConfig($this->credentialsPath()); $client->setScopes([Drive::DRIVE_READONLY]);
        $drive = new Drive($client);
        $state = $this->em->getRepository(ScanState::class)->find($this->driveId) ?? new ScanState($this->driveId);
        $state->finish($drive->changes->getStartPageToken(['driveId' => $this->driveId, 'supportsAllDrives' => true])->getStartPageToken());
        $this->em->persist($state);
        $this->em->flush();
        return count($seen);
    }

    public function syncIncremental(): int
    {
        $state = $this->em->getRepository(ScanState::class)->find($this->driveId);
        if (!$state?->getChangeToken()) throw new \RuntimeException('No change token exists. Run app:scan-drive --full once.');
        $client = new Client(); $client->setAuthConfig($this->credentialsPath()); $client->setScopes([Drive::DRIVE_READONLY]);
        $drive = new Drive($client); $token = $state->getChangeToken(); $changed = 0;
        do {
            $page = $this->withRetry(fn () => $drive->changes->listChanges($token, ['driveId' => $this->driveId, 'includeItemsFromAllDrives' => true, 'supportsAllDrives' => true, 'fields' => 'nextPageToken,newStartPageToken,changes(fileId,removed,file(id,name,parents,mimeType,modifiedTime))']));
            foreach ($page->getChanges() as $change) {
                ++$changed;
                // A cross-Shared-Drive move can omit the file payload even when
                // the item has not been deleted globally; from this source Drive's
                // perspective it must be archived.
                if ($change->getRemoved() || !$change->getFile()) {
                    $track = $this->em->getRepository(Track::class)->findOneBy(['driveId' => $change->getFileId()]);
                    if ($track) $track->archive();
                } elseif ($track = $this->em->getRepository(Track::class)->findOneBy(['driveId' => $change->getFileId()])) {
                    $file = (array) $change->getFile()->toSimpleObject();
                    $folderName = $file['name'] ?? $track->getFolderName();
                    $title = preg_replace('/\s+project$/i', '', $folderName);
                    $artist = $track->getArtist();
                    if ($artist && str_starts_with(strtolower($title), strtolower($artist.' - '))) $title = substr($title, strlen($artist) + 3);
                    $issues = array_values(array_filter($track->getIssues(), static fn(string $issue): bool => $issue !== 'Name Inconsistent'));
                    if ($artist && !str_starts_with(strtolower($folderName), strtolower($artist.' - ')) && !preg_match('/\(?'.preg_quote($artist, '/').'\s+(?:edit|remix)\)?/i', $folderName)) $issues[] = 'Name Inconsistent';
                    $track->refresh(['folderName' => $folderName, 'title' => $title, 'issues' => $issues, 'driveModifiedAt' => isset($file['modifiedTime']) ? new \DateTimeImmutable($file['modifiedTime']) : $track->getDriveModifiedAt()]);
                } else {
                    $folder = (array) $change->getFile()->toSimpleObject();
                    foreach ($this->em->getRepository(Track::class)->findBy(['parentDriveId' => $change->getFileId(), 'archived' => false]) as $childTrack) {
                        $folderName = $childTrack->getFolderName();
                        $title = preg_replace('/\s+project$/i', '', $folderName);
                        if (str_starts_with(strtolower($title), strtolower($folder['name'].' - '))) $title = substr($title, strlen($folder['name']) + 3);
                        $issues = array_values(array_filter($childTrack->getIssues(), static fn(string $issue): bool => $issue !== 'Name Inconsistent'));
                        if (!str_starts_with(strtolower($folderName), strtolower($folder['name'].' - ')) && !preg_match('/\(?'.preg_quote($folder['name'], '/').'\s+(?:edit|remix)\)?/i', $folderName)) $issues[] = 'Name Inconsistent';
                        $childTrack->refresh(['artist' => $folder['name'], 'title' => $title, 'issues' => $issues]);
                    }
                }
            }
            $token = $page->getNextPageToken() ?: $page->getNewStartPageToken() ?: $token;
        } while ($page->getNextPageToken());
        $state->finish($token); $this->em->flush();
        return $changed;
    }

    private function hasReleaseData(array $files): bool
    {
        $pitch = false; $artwork = false;
        foreach ($files as $file) {
            $pitch = $pitch || strtolower($file['name'] ?? '') === 'pitch.txt';
            $artwork = $artwork || in_array(strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
        }
        return $pitch && $artwork;
    }

    private function path(array $item, array $byId): string
    {
        $parts = [$item['name']]; $parent = ($item['parents'] ?? [])[0] ?? null;
        while ($parent && isset($byId[$parent])) { $parts[] = $byId[$parent]['name']; $parent = ($byId[$parent]['parents'] ?? [])[0] ?? null; }
        return implode(' / ', array_reverse($parts));
    }

    private function credentialsPath(): string
    {
        return str_starts_with($this->credentialsFile, '/') ? $this->credentialsFile : $this->projectDir.'/'.$this->credentialsFile;
    }
}
