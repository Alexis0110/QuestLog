<?php

namespace App\Controller;

use App\Entity\Game;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    #[Route('/steam', name: 'steam_profile', methods: ['GET', 'POST'])]
    public function steamProfile(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $steamUrl = $request->request->get('steam_profile');
    
            $steamId = $this->extractSteamId($steamUrl);
    
            if ($steamId) {
                // Benutzername abrufen
                $profileResponse = $this->client->request(
                    'GET',
                    "http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key={$this->steamApiKey}&steamids={$steamId}"
                );
                $profileData = $profileResponse->toArray();
    
                $personaname = $profileData['response']['players'][0]['personaname'] ?? 'Unbekannt';
    
                return $this->redirectToRoute('fetch_steam_games', [
                    'steamId' => $steamId,
                    'username' => $personaname
                ]);
            }
        }
    
        return $this->render('home.html.twig');
    }
    private function extractSteamId(string $url): ?string
{
    if (preg_match('/\/profiles\/(\d+)/', $url, $matches)) {
        return $matches[1]; // Direkt SteamID64
    }

    if (preg_match('/\/id\/([^\/]+)/', $url, $matches)) {
        $vanityName = $matches[1];

        // Auflösung der Vanity URL
        $response = $this->client->request(
            'GET',
            "http://api.steampowered.com/ISteamUser/ResolveVanityURL/v0001/?key={$this->steamApiKey}&vanityurl={$vanityName}"
        );

        $data = $response->toArray();

        if (isset($data['response']['success']) && $data['response']['success'] == 1) {
            return $data['response']['steamid']; // echte SteamID64
        }
        
        return null; // Fehler bei Auflösung
    }

    return null;
}

#[Route('/fetch/{steamId}/{username}', name: 'fetch_steam_games')]
public function fetchSteamGames(string $steamId, string $username): Response
{
    set_time_limit(300);

    $repository = $this->entityManager->getRepository(Game::class);
    $existingGames = $repository->findAll();

    foreach ($existingGames as $game) {
        $this->entityManager->remove($game);
    }
    $this->entityManager->flush();

    $response = $this->client->request(
        'GET',
        "http://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key={$this->steamApiKey}&steamid={$steamId}&format=json&include_appinfo=true"
    );
    $data = $response->toArray();
    $gamesData = $data['response']['games'] ?? [];

    $newGames = [];

    foreach ($gamesData as $gameData) {
        $game = new Game();
        $game->setSteamId($gameData['appid']);
        $game->setName($gameData['name']);
        $game->setAchievements('...'); // Placeholder
        $game->setAchievementsPercent(0);
        $this->entityManager->persist($game);
        $newGames[] = $game;
    }

    $this->entityManager->flush();

    return $this->render('home.html.twig', [
        'games' => $newGames,
        'username' => $username,
        'steamId' => $steamId,
    ]);
}

#[Route('/fetch-achievements-batch/{steamId}/{gameId}', name: 'fetch_achievements_batch', methods: ['GET'])]
public function fetchAchievementsBatch(string $steamId, int $gameId): Response
{
    $game = $this->entityManager->getRepository(Game::class)->find($gameId);

    if (!$game) {
        return $this->json(['error' => 'Game not found'], 404);
    }

    try {
        $schemaResponse = $this->client->request(
            'GET',
            "http://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/?key={$this->steamApiKey}&appid={$game->getSteamId()}"
        );
        $schemaData = $schemaResponse->toArray();
        $totalAchievements = count($schemaData['game']['availableGameStats']['achievements'] ?? []);

        $playerResponse = $this->client->request(
            'GET',
            "http://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v1/?key={$this->steamApiKey}&steamid={$steamId}&appid={$game->getSteamId()}"
        );
        $playerData = $playerResponse->toArray();
        $achievements = $playerData['playerstats']['achievements'] ?? [];

        $unlocked = 0;
        foreach ($achievements as $achievement) {
            if ($achievement['achieved'] == 1) {
                $unlocked++;
            }
        }

        $achievementProgress = "{$unlocked}/{$totalAchievements}";
        $percent = ($totalAchievements > 0) ? ($unlocked / $totalAchievements * 100) : 0;

        $game->setAchievements($achievementProgress);
        $game->setAchievementsPercent($percent);
        $this->entityManager->flush();

        return $this->json([
            'progress' => $achievementProgress,
            'percent' => $percent,
        ]);

    } catch (\Exception $e) {
        return $this->json(['error' => 'Error fetching achievements'], 500);
    }
}
#[Route('/fetch-all-games/{steamId}', name: 'fetch_all_games', methods: ['GET'])]
public function fetchAllGames(string $steamId): Response
{
    $games = $this->entityManager->getRepository(Game::class)
        ->findBy([], ['name' => 'ASC']);

    $result = [];
    foreach ($games as $game) {
        $result[] = [
            'id' => $game->getId(),
            'name' => $game->getName(),
            'achievements' => $game->getAchievements(),
            'steamId' => $game->getSteamId()
        ];
    }

    return $this->json($result);
}
}