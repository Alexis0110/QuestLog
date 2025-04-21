<?php

namespace App\Controller;

use App\Entity\Game;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function PHPSTORM_META\type;

class SteamController extends AbstractController
{
    private HttpClientInterface $client;
    private EntityManagerInterface $entityManager;
    private string $steamApiKey = 'EEA323188248113B93A118FD24DA3ECE';

    public function __construct(HttpClientInterface $client, EntityManagerInterface $entityManager)
    {
        $this->client = $client;
        $this->entityManager = $entityManager;
    }

    #[Route('/', name: 'start_page')]
    public function start(SessionInterface $session): Response
    {
        $this->clearDatabase();
        $session->clear();

        return $this->render('start.html.twig');
    }

    #[Route('/profile', name: 'steam_profile', methods: ['GET', 'POST'])]
    public function steamProfile(Request $request, SessionInterface $session): Response
    {
        if ($request->isMethod('POST')) {
            $steamUrl = $request->request->get('steam_profile');
            $steamId = $this->extractSteamId($steamUrl);
    
            if ($steamId) {
                try {
                    $profileResponse = $this->client->request('GET', "http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key={$this->steamApiKey}&steamids={$steamId}");
                    $profileData = $profileResponse->toArray();
    
                    if (empty($profileData['response']['players'])) {

                        return $this->render('start.html.twig', [
                            'error' => 'Profil nicht gefunden.',
                        ]);
                    }
    
                    $personaname = $profileData['response']['players'][0]['personaname'] ?? 'Unbekannt';
    
                    $gamesResponse = $this->client->request('GET', "http://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key={$this->steamApiKey}&steamid={$steamId}&format=json&include_appinfo=true");
                    $gamesData = $gamesResponse->toArray();
    
                    if (empty($gamesData['response']['games'])) {
                        return $this->render('start.html.twig', [
                            'error' => 'Keine Spiele gefunden.',
                        ]);
                    }
    
                    $session->set('steamId', $steamId);
                    $session->set('username', $personaname);
    
                    return $this->redirectToRoute('fetch_steam_games', [
                        'steamId' => $steamId,
                    ]);
    
                } catch (\Exception $e) {
                    return $this->render('start.html.twig', [
                        'error' => 'Fehler beim Abrufen der Steam-Daten.',
                    ]);
                }
            } else {
                return $this->render('start.html.twig', [
                    'error' => 'Ungültiger Steam-Link.',
                ]);
            }
        }

        $steamId = $session->get('steamId');
        $username = $session->get('username');
    
        return $this->render('home.html.twig', [
            'steamId' => $steamId,
            'username' => $username,
        ]);
    }

    #[Route('/fetch/{steamId}', name: 'fetch_steam_games')]
    public function fetchSteamGames(string $steamId): Response
    {
        $this->clearDatabase();
        $this->importGamesFromSteam($steamId);

        return $this->redirectToRoute('steam_profile');
    }

    #[Route('/fetch-all-games/{steamId}', name: 'fetch_all_games', methods: ['GET'])]
    public function fetchAllGames(string $steamId): Response
    {
        $games = $this->entityManager->getRepository(Game::class)->findBy([], ['name' => 'ASC']);

        return $this->json(array_map(fn(Game $game) => [
            'id' => $game->getId(),
            'name' => $game->getName(),
            'achievements' => $game->getAchievements(),
            'steamId' => $game->getSteamId(),
        ], $games));
    }

    #[Route('/fetch-achievements-batch/{steamId}/{gameId}', name: 'fetch_achievements_batch', methods: ['GET'])]
    public function fetchAchievementsBatch(string $steamId, int $gameId): Response
    {
        $game = $this->entityManager->getRepository(Game::class)->find($gameId);
        if (!$game) {
            return $this->json(['error' => 'Game not found'], 404);
        }

        try {
            $schema = $this->client->request('GET', "http://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/?key={$this->steamApiKey}&appid={$game->getSteamId()}")->toArray();
            $achievements = $schema['game']['availableGameStats']['achievements'] ?? [];
            $totalAchievements = count($achievements);

            $playerAchievements = $this->client->request('GET', "http://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v1/?key={$this->steamApiKey}&steamid={$steamId}&appid={$game->getSteamId()}")->toArray();
            $unlocked = count(array_filter($playerAchievements['playerstats']['achievements'] ?? [], fn($a) => $a['achieved'] == 1));

            $percent = $totalAchievements > 0 ? ($unlocked / $totalAchievements) * 100 : 0;
            $progress = "{$unlocked}/{$totalAchievements}";

            $game->setAchievements($progress);
            $game->setAchievementsPercent($percent);
            $this->entityManager->flush();

            return $this->json(['progress' => $progress, 'percent' => $percent]);
        } catch (\Exception) {
            return $this->json(['error' => 'Error fetching achievements'], 500);
        }
    }

    #[Route('/game/{steamId}', name: 'game_details')]
    public function gameDetails(string $steamId, SessionInterface $session): Response
    {
        try {
            //app data
            $response = $this->client->request('GET', "https://store.steampowered.com/api/appdetails?appids={$steamId}");
            $data = $response->toArray();
            $appData = $data[$steamId]['data'] ?? null;
    
            $gameName = $appData['name'] ?? 'Unbekanntes Spiel';
            $imageUrl = $appData['header_image'] ?? null;
            $achievementsCount = $appData['achievements']['total'] ?? 0;
    
            // Player achievements
            $unlocked = 0;
            $steamIdUser = $session->get('steamId');
    
            try {
                $playerResponse = $this->client->request('GET', "http://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v1/?key={$this->steamApiKey}&steamid={$steamIdUser}&appid={$steamId}");
                $playerData = $playerResponse->toArray();
    
                foreach ($playerData['playerstats']['achievements'] ?? [] as $achievement) {
                    if ($achievement['achieved'] == 1) {
                        $unlocked++;
                    }
                }
            } catch (\Exception $e) {
                $unlocked = 0;
            }
    
            return $this->render('game_details.html.twig', [
                'steamId' => $steamId,
                'gameName' => $gameName,
                'imageUrl' => $imageUrl,
                'achievementsCount' => $achievementsCount,
                'achievementsUnlocked' => $unlocked,
                'username' => $session->get('username'),
            ]);
    
        } catch (\Exception $e) {
            return $this->render('game_details.html.twig', [
                'steamId' => $steamId,
                'gameName' => 'Fehler beim Laden',
                'imageUrl' => null,
                'achievementsCount' => 0,
                'achievementsUnlocked' => 0,
                'username' => $session->get('username'),
            ]);
        }
    }

    private function extractSteamId(string $url): ?string
    {
        if (preg_match('/\/profiles\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }
        if (preg_match('/\/id\/([^\/]+)/', $url, $matches)) {
            $response = $this->client->request('GET', "http://api.steampowered.com/ISteamUser/ResolveVanityURL/v0001/?key={$this->steamApiKey}&vanityurl={$matches[1]}")->toArray();
            return ($response['response']['success'] ?? 0) == 1 ? $response['response']['steamid'] : null;
        }
        return null;
    }

    private function fetchSteamProfile(string $steamId): array
    {
        $response = $this->client->request('GET', "http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key={$this->steamApiKey}&steamids={$steamId}");
        return $response->toArray()['response']['players'][0] ?? [];
    }

    private function importGamesFromSteam(string $steamId): void
    {
        $response = $this->client->request('GET', "http://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key={$this->steamApiKey}&steamid={$steamId}&format=json&include_appinfo=true");
        $gamesData = $response->toArray()['response']['games'] ?? [];

        foreach ($gamesData as $data) {
            $game = new Game();
            $game->setSteamId($data['appid']);
            $game->setName($data['name']);
            $game->setAchievements('...');
            $game->setAchievementsPercent(0);
            $this->entityManager->persist($game);
        }
        $this->entityManager->flush();
    }

    private function clearDatabase(): void
    {
        foreach ($this->entityManager->getRepository(Game::class)->findAll() as $game) {
            $this->entityManager->remove($game);
        }
        $this->entityManager->flush();
    }
}
