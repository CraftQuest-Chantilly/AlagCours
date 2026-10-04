<?php
// Réponses JSON et erreurs HTTP homogènes.
final class Http
{
    public static function json($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function erreur(int $code, string $message): void
    {
        self::json(['erreur' => $message], $code);
    }

    public static function corps(): array
    {
        $brut = file_get_contents('php://input');
        if ($brut === '' || $brut === false) return [];
        $d = json_decode($brut, true);
        if (!is_array($d)) self::erreur(400, 'Requête invalide.');
        return $d;
    }

    public static function ip(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 50);
    }

    /** Identifiant numérique (bigint 17 chiffres) : toujours manipulé en chaîne,
     *  JavaScript ne sait pas représenter ces entiers sans perte de précision. */
    public static function id(string $valeur): string
    {
        if (!preg_match('/^\d{1,19}$/', $valeur)) self::erreur(400, 'Identifiant invalide.');
        return $valeur;
    }
}
