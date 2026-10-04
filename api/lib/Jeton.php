<?php
// Jetons de session signés par le site (JWT HS256), indépendants de la base.
final class Jeton
{
    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function deb64(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/'));
    }

    private static function secret(): string
    {
        $s = (string)($GLOBALS['config']['jwt_secret'] ?? '');
        if (strlen($s) < 32) {
            error_log('jwt_secret absent ou trop court dans config.local.php');
            Http::erreur(500, 'Configuration du serveur incomplète.');
        }
        return $s;
    }

    public static function emettre(array $charge): string
    {
        $maintenant = time();
        $charge += ['iat' => $maintenant, 'exp' => $maintenant + (int)$GLOBALS['config']['jwt_duree']];
        $entete = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $corps = self::b64(json_encode($charge, JSON_UNESCAPED_UNICODE));
        $sig = self::b64(hash_hmac('sha256', "$entete.$corps", self::secret(), true));
        return "$entete.$corps.$sig";
    }

    public static function verifier(string $jeton): ?array
    {
        $parts = explode('.', $jeton);
        if (count($parts) !== 3) return null;
        [$entete, $corps, $sig] = $parts;
        $h = json_decode(self::deb64($entete), true);
        if (($h['alg'] ?? '') !== 'HS256') return null;
        $attendu = self::b64(hash_hmac('sha256', "$entete.$corps", self::secret(), true));
        if (!hash_equals($attendu, $sig)) return null;
        $c = json_decode(self::deb64($corps), true);
        if (!is_array($c) || (int)($c['exp'] ?? 0) < time()) return null;
        return $c;
    }

    /** Utilisateur connecté, quel que soit son type (stagiaire ou formateur), sinon réponse 401. */
    public static function utilisateur(): array
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/', $h, $m)) Http::erreur(401, 'Session absente.');
        $c = self::verifier($m[1]);
        if (!$c || empty($c['typ']) || empty($c['sub'])) Http::erreur(401, 'Session expirée, reconnectez-vous.');
        return $c;
    }

    /** Stagiaire connecté, sinon 401 (absent/expiré) ou 403 (connecté mais pas stagiaire). */
    public static function stagiaire(): array
    {
        $c = self::utilisateur();
        if ($c['typ'] !== 'stagiaire') Http::erreur(403, 'Accès réservé aux stagiaires.');
        return $c;
    }

    /** Formateur connecté, sinon 401 (absent/expiré) ou 403 (connecté mais pas formateur). */
    public static function formateur(): array
    {
        $c = self::utilisateur();
        if ($c['typ'] !== 'formateur') Http::erreur(403, 'Accès réservé aux formateurs.');
        return $c;
    }
}
