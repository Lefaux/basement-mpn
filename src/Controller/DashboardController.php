<?php

namespace App\Controller;

use App\Entity\ScanState;
use App\Entity\Track;
use App\Service\GoogleDriveScanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(#[Autowire('%env(APP_PASSWORD)%')] private readonly string $password) {}

    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($request->isMethod('POST') && $this->password !== '' && hash_equals($this->password, (string) $request->request->get('password'))) {
            $request->getSession()->set('music_authenticated', true);
            return $this->redirectToRoute('board');
        }
        return $this->render('login.html.twig', ['invalid' => $request->isMethod('POST')]);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $request->getSession()->remove('music_authenticated');
        return $this->redirectToRoute('login');
    }

    #[Route('/', name: 'board')]
    public function board(Request $request, EntityManagerInterface $em): Response
    {
        if (!$request->getSession()->get('music_authenticated')) return $this->redirectToRoute('login');
        $artist = trim((string) $request->query->get('artist', ''));
        $allowedFileFilters = ['preview', 'render', 'landr', 'missing-preview', 'missing-render', 'missing-landr'];
        $fileFilters = array_values(array_intersect(
            $allowedFileFilters,
            array_map('strval', $request->query->all('file_filter')),
        ));
        $all = $em->getRepository(Track::class)->findBy(['archived' => false], ['driveModifiedAt' => 'DESC']);
        $boardTracks = array_values(array_filter($all, static fn(Track $t): bool => $t->isCleanLayout() && $t->getIssues() === []));
        $clean = array_values(array_filter($boardTracks, static fn(Track $t): bool => $artist === '' || $t->getArtist() === $artist));
        $mainFilterCounts = [
            'preview' => 0,
            'render' => 0,
            'landr' => 0,
            'missing-preview' => 0,
            'missing-render' => 0,
            'missing-landr' => 0,
        ];
        foreach ($clean as $track) {
            $files = $track->getFiles();
            $hasPreview = ($files['previews'] ?? []) !== [];
            $hasRender = ($files['renders'] ?? []) !== [];
            $hasLandr = ($files['landr'] ?? []) !== [];
            $mainFilterCounts['preview'] += (int) $hasPreview;
            $mainFilterCounts['render'] += (int) $hasRender;
            $mainFilterCounts['landr'] += (int) $hasLandr;
            $mainFilterCounts['missing-preview'] += (int) !$hasPreview;
            $mainFilterCounts['missing-render'] += (int) !$hasRender;
            $mainFilterCounts['missing-landr'] += (int) !$hasLandr;
        }
        $visibleTracks = array_values(array_filter($clean, static function (Track $track) use ($fileFilters): bool {
            $files = $track->getFiles();
            $available = [
                'preview' => ($files['previews'] ?? []) !== [],
                'render' => ($files['renders'] ?? []) !== [],
                'landr' => ($files['landr'] ?? []) !== [],
            ];
            foreach ($fileFilters as $filter) {
                $name = str_starts_with($filter, 'missing-') ? substr($filter, 8) : $filter;
                if ($available[$name] !== !str_starts_with($filter, 'missing-')) return false;
            }
            return true;
        }));
        $columns = array_fill_keys(['idea', 'arrangement', 'mastered', 'final', 'release'], []);
        foreach ($visibleTracks as $track) $columns[$track->getStage()][] = $track;
        $artistCounts = [];
        foreach ($boardTracks as $track) $artistCounts[$track->getArtist()] = ($artistCounts[$track->getArtist()] ?? 0) + 1;
        $artists = array_keys($artistCounts); sort($artists);
        $inconsistentCount = count(array_filter($all, static fn(Track $t): bool => !$t->isCleanLayout() || $t->getIssues() !== []));
        $lastRun = $em->getRepository(ScanState::class)->findOneBy([])?->getLastAttemptAt();
        return $this->render('board.html.twig', compact('columns', 'artists', 'artistCounts', 'artist', 'fileFilters', 'mainFilterCounts', 'inconsistentCount', 'lastRun'));
    }

    #[Route('/inconsistencies', name: 'inconsistencies')]
    public function inconsistencies(Request $request, EntityManagerInterface $em): Response
    {
        if (!$request->getSession()->get('music_authenticated')) return $this->redirectToRoute('login');
        $tracks = array_values(array_filter($em->getRepository(Track::class)->findBy(['archived' => false], ['driveModifiedAt' => 'DESC']), static fn(Track $t): bool => !$t->isCleanLayout() || $t->getIssues() !== []));
        $issueTypes = [];
        foreach ($tracks as $track) $issueTypes = [...$issueTypes, ...$track->getIssues()];
        $issueTypes = array_values(array_unique($issueTypes)); sort($issueTypes);
        $issueCounts = [];
        foreach ($tracks as $track) foreach ($track->getIssues() as $issue) $issueCounts[$issue] = ($issueCounts[$issue] ?? 0) + 1;
        $filterMeta = []; $filterArtists = []; $filterStages = []; $filterRoots = [];
        foreach ($tracks as $track) {
            $parts = explode(' / ', $track->getPath());
            $root = $parts[1] ?? 'Unknown';
            $artist = $track->getArtist() ?? 'Unknown artist';
            $filterMeta[$track->getId()] = ['artist' => $artist, 'stage' => $track->getStage(), 'root' => $root];
            $filterArtists[$artist] = ($filterArtists[$artist] ?? 0) + 1;
            $filterStages[$track->getStage()] = ($filterStages[$track->getStage()] ?? 0) + 1;
            $filterRoots[$root] = ($filterRoots[$root] ?? 0) + 1;
        }
        ksort($filterArtists); ksort($filterRoots);
        return $this->render('inconsistencies.html.twig', compact('tracks', 'issueTypes', 'issueCounts', 'filterMeta', 'filterArtists', 'filterStages', 'filterRoots'));
    }

    #[Route('/missing-previews', name: 'missing_previews')]
    public function missingPreviews(Request $request, EntityManagerInterface $em): Response
    {
        if (!$request->getSession()->get('music_authenticated')) return $this->redirectToRoute('login');

        $artist = trim((string) $request->query->get('artist', ''));
        $all = $em->getRepository(Track::class)->findBy(['archived' => false], ['artist' => 'ASC', 'title' => 'ASC']);
        $tracks = array_values(array_filter($all, static function (Track $track): bool {
            if (!$track->isCleanLayout() || $track->getIssues() !== []) return false;
            $files = $track->getFiles();
            return ($files['previews'] ?? []) === []
                && ($files['renders'] ?? []) === []
                && ($files['landr'] ?? []) === []
                && ($files['mstr'] ?? []) === [];
        }));

        $artistCounts = [];
        foreach ($tracks as $track) {
            $name = $track->getArtist() ?? 'Unknown artist';
            $artistCounts[$name] = ($artistCounts[$name] ?? 0) + 1;
        }
        $artists = array_keys($artistCounts);
        sort($artists);

        $columns = [];
        foreach ($tracks as $track) {
            if ($artist !== '' && $track->getArtist() !== $artist) continue;
            $name = $track->getArtist() ?? 'Unknown artist';
            $columns[$name][] = $track;
        }
        ksort($columns);

        $inconsistentCount = count(array_filter($all, static fn(Track $track): bool => !$track->isCleanLayout() || $track->getIssues() !== []));
        $lastRun = $em->getRepository(ScanState::class)->findOneBy([])?->getLastAttemptAt();

        return $this->render('missing_previews.html.twig', compact('columns', 'artists', 'artistCounts', 'artist', 'inconsistentCount', 'lastRun'));
    }

    #[Route('/missing-landr', name: 'missing_landr')]
    public function missingLandr(Request $request, EntityManagerInterface $em): Response
    {
        if (!$request->getSession()->get('music_authenticated')) return $this->redirectToRoute('login');

        $artist = trim((string) $request->query->get('artist', ''));
        $all = $em->getRepository(Track::class)->findBy(['archived' => false], ['artist' => 'ASC', 'title' => 'ASC']);
        $tracks = array_values(array_filter($all, static function (Track $track): bool {
            if (!$track->isCleanLayout() || $track->getIssues() !== []) return false;
            $files = $track->getFiles();
            return ($files['renders'] ?? []) !== []
                && ($files['landr'] ?? []) === []
                && ($files['mstr'] ?? []) === [];
        }));

        $artistCounts = [];
        foreach ($tracks as $track) {
            $name = $track->getArtist() ?? 'Unknown artist';
            $artistCounts[$name] = ($artistCounts[$name] ?? 0) + 1;
        }
        $artists = array_keys($artistCounts);
        sort($artists);

        $columns = [];
        foreach ($tracks as $track) {
            if ($artist !== '' && $track->getArtist() !== $artist) continue;
            $name = $track->getArtist() ?? 'Unknown artist';
            $columns[$name][] = $track;
        }
        ksort($columns);

        $inconsistentCount = count(array_filter($all, static fn(Track $track): bool => !$track->isCleanLayout() || $track->getIssues() !== []));
        $lastRun = $em->getRepository(ScanState::class)->findOneBy([])?->getLastAttemptAt();

        return $this->render('missing_landr.html.twig', compact('columns', 'artists', 'artistCounts', 'artist', 'inconsistentCount', 'lastRun'));
    }

    #[Route('/missing-master', name: 'missing_master')]
    public function missingMaster(Request $request, EntityManagerInterface $em): Response
    {
        if (!$request->getSession()->get('music_authenticated')) return $this->redirectToRoute('login');

        $artist = trim((string) $request->query->get('artist', ''));
        $all = $em->getRepository(Track::class)->findBy(['archived' => false], ['artist' => 'ASC', 'title' => 'ASC']);
        $tracks = array_values(array_filter($all, static function (Track $track): bool {
            if (!$track->isCleanLayout() || $track->getIssues() !== []) return false;
            $files = $track->getFiles();
            return ($files['landr'] ?? []) !== [] && ($files['mstr'] ?? []) === [];
        }));

        $artistCounts = [];
        foreach ($tracks as $track) {
            $name = $track->getArtist() ?? 'Unknown artist';
            $artistCounts[$name] = ($artistCounts[$name] ?? 0) + 1;
        }
        $artists = array_keys($artistCounts);
        sort($artists);

        $columns = [];
        foreach ($tracks as $track) {
            if ($artist !== '' && $track->getArtist() !== $artist) continue;
            $name = $track->getArtist() ?? 'Unknown artist';
            $columns[$name][] = $track;
        }
        ksort($columns);

        $inconsistentCount = count(array_filter($all, static fn(Track $track): bool => !$track->isCleanLayout() || $track->getIssues() !== []));
        $lastRun = $em->getRepository(ScanState::class)->findOneBy([])?->getLastAttemptAt();

        return $this->render('missing_master.html.twig', compact('columns', 'artists', 'artistCounts', 'artist', 'inconsistentCount', 'lastRun'));
    }

    #[Route('/archive', name: 'archive')]
    public function archive(Request $request, EntityManagerInterface $em): Response
    {
        if (!$request->getSession()->get('music_authenticated')) return $this->redirectToRoute('login');
        return $this->render('archive.html.twig', ['tracks' => $em->getRepository(Track::class)->findBy(['archived' => true], ['updatedAt' => 'DESC'])]);
    }

    #[Route('/track/{id}/archive', name: 'track_archive', methods: ['POST'])]
    public function archiveTrack(Request $request, Track $track, GoogleDriveScanner $scanner): Response
    {
        if (!$request->getSession()->get('music_authenticated')) return $this->redirectToRoute('login');
        $scanner->archive($track);
        return $this->redirectToRoute('board');
    }
}
