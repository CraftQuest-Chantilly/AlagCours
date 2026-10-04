<?php
// Accès base de données SANS pdo_pgsql (extension absente et interdite sur le serveur OVH
// mutualisé — « interdit de charger pdo_sql tu dois faire sans »). curl est également absent :
// seuls allow_url_fopen + openssl sont disponibles (constaté via diag.php), donc les requêtes
// passent par file_get_contents() en HTTPS vers l'API REST de Supabase (PostgREST), qui appelle
// deux fonctions SQL côté Supabase (exec_sql / exec_write, réservées au rôle service_role —
// migration rpc_exec_sql_pour_api_php_sans_pdo_pgsql) exécutant le texte SQL fourni.
//
// Les signatures publiques (lignes/ligne/exec) et le SQL des fichiers routes/*.php restent
// inchangés : c'est cette classe seule qui convertit les placeholders nommés ":nom" (syntaxe
// PDO habituelle) en "%L" positionnels (syntaxe attendue par format() côté SQL), dans l'ordre
// d'apparition, avec un tableau de valeurs assorti (une entrée par occurrence, y compris les
// répétitions d'un même :nom).
final class Db
{
    /** Convertit les placeholders ":nom" de $sql en "%L" (dans l'ordre) et renvoie
     *  [sql_converti, valeurs_dans_le_même_ordre]. Les "%" déjà présents dans le SQL (rares,
     *  ex. LIKE '%…%') sont doublés d'abord pour ne pas être interprétés par format().
     *
     *  Un simple regex sur toute la chaîne est insuffisant : un ":nom" peut apparaître à
     *  l'intérieur même d'un littéral SQL sans être un paramètre — ex. to_char(..., 'HH24:MI')
     *  (bug constaté en production sur route_agenda : "Paramètre manquant : :MI", la regex
     *  naïve confondait :MI du format de date avec un placeholder). On scanne donc caractère
     *  par caractère en suivant l'état "dans une chaîne '...'" / "dans un identifiant \"...\""
     *  (avec gestion de '' comme apostrophe échappée) pour ignorer tout ":nom" qui s'y trouve.
     *  Le "::" (cast Postgres, ex. valeur::text) est également préservé : un ":" n'amorce un
     *  placeholder que s'il n'est ni précédé ni suivi d'un autre ":". */
    private static function preparer(string $sql, array $params): array
    {
        $sql = str_replace('%', '%%', $sql);
        $valeurs = [];
        $sortie = '';
        $len = strlen($sql);
        $i = 0;
        $dansChaine = false; // à l'intérieur d'un littéral '...'
        $dansIdent = false;  // à l'intérieur d'un identifiant "..."
        while ($i < $len) {
            $car = $sql[$i];

            if ($dansChaine) {
                if ($car === "'" && $i + 1 < $len && $sql[$i + 1] === "'") {
                    $sortie .= "''";
                    $i += 2;
                    continue;
                }
                if ($car === "'") $dansChaine = false;
                $sortie .= $car;
                $i++;
                continue;
            }
            if ($dansIdent) {
                if ($car === '"') $dansIdent = false;
                $sortie .= $car;
                $i++;
                continue;
            }
            if ($car === "'") { $dansChaine = true; $sortie .= $car; $i++; continue; }
            if ($car === '"') { $dansIdent = true; $sortie .= $car; $i++; continue; }

            $precedentEstDeuxPoints = $i > 0 && $sql[$i - 1] === ':';
            $suivantEstDeuxPoints = $i + 1 < $len && $sql[$i + 1] === ':';
            if ($car === ':' && !$precedentEstDeuxPoints && !$suivantEstDeuxPoints
                && preg_match('/\G:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $m, 0, $i)) {
                // Les appelants (routes/*.php) utilisent la convention PDO historique, clé avec
                // le ":" (ex. [':l' => $login]) — on accepte aussi la forme sans ":" par souplesse.
                $nom = $m[1];
                if (array_key_exists(':' . $nom, $params)) {
                    $valeurs[] = $params[':' . $nom];
                } elseif (array_key_exists($nom, $params)) {
                    $valeurs[] = $params[$nom];
                } else {
                    throw new RuntimeException("Paramètre manquant : :$nom");
                }
                $sortie .= '%L';
                $i += strlen($m[0]);
                continue;
            }

            $sortie .= $car;
            $i++;
        }
        return [$sortie, $valeurs];
    }

    /** Cœur de l'appel HTTP à une fonction RPC Supabase (exec_sql/exec_write/autre), SANS
     *  décider quoi faire en cas d'erreur — ce choix appartient aux deux méthodes publiques qui
     *  l'utilisent : appelerRpc() (comportement historique : quitte avec une 503 générique) et
     *  tenter() (renvoie l'erreur à l'appelant, pour les cas où une erreur SQL est un résultat
     *  normal à afficher au stagiaire — ex. atelier SQL — pas une panne serveur). Renvoie
     *  ['statut' => int, 'donnees' => mixed, 'brut' => string, 'ok' => bool]. 'statut' reste 0
     *  et 'ok' false si l'appel HTTP lui-même a échoué (réseau/config), pour ne pas confondre
     *  avec un vrai code HTTP de Supabase. */
    private static function appelerRpcBrut(string $fonction, string $sql, array $params): array
    {
        [$sqlConverti, $valeurs] = self::preparer($sql, $params);

        $config = $GLOBALS['config'];
        $base = rtrim((string)($config['supabase_url'] ?? ''), '/');
        $cle = (string)($config['supabase_service_role_key'] ?? '');
        if ($base === '' || $cle === '') {
            error_log('[alcours api] supabase_url / supabase_service_role_key non configurés.');
            return ['statut' => 0, 'donnees' => null, 'brut' => '', 'ok' => false];
        }

        $corps = json_encode(['requete' => $sqlConverti, 'params' => $valeurs], JSON_UNESCAPED_UNICODE);
        if ($corps === false) {
            error_log('[alcours api] Échec json_encode des paramètres SQL.');
            return ['statut' => 0, 'donnees' => null, 'brut' => '', 'ok' => false];
        }

        $entetes = implode("\r\n", [
            'Content-Type: application/json',
            'apikey: ' . $cle,
            'Authorization: Bearer ' . $cle,
        ]);
        $contexte = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => $entetes,
                'content'       => $corps,
                'timeout'       => 15,
                'ignore_errors' => true, // sinon file_get_contents renvoie false sur 4xx/5xx
                                          // sans laisser lire le corps d'erreur JSON de PostgREST
            ],
        ]);

        $url = $base . '/rest/v1/rpc/' . $fonction;
        $reponse = @file_get_contents($url, false, $contexte);

        if ($reponse === false) {
            error_log('[alcours api] Appel HTTPS Supabase impossible (' . $fonction . ').');
            return ['statut' => 0, 'donnees' => null, 'brut' => '', 'ok' => false];
        }

        $statut = 0;
        foreach ($http_response_header ?? [] as $ligneEntete) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $ligneEntete, $m)) {
                $statut = (int)$m[1];
                break;
            }
        }

        $donnees = json_decode($reponse, true);
        $ok = $statut >= 200 && $statut < 300;
        if (!$ok) {
            error_log('[alcours api] Supabase RPC ' . $fonction . ' → HTTP ' . $statut . ' : ' . $reponse);
        }
        return ['statut' => $statut, 'donnees' => $donnees, 'brut' => $reponse, 'ok' => $ok];
    }

    /** Appelle la fonction RPC Supabase $fonction (exec_sql ou exec_write) avec le SQL et les
     *  paramètres donnés, et renvoie la valeur JSON décodée du résultat. Comportement historique
     *  inchangé : quitte avec une 503 générique en cas d'erreur (le détail ne doit jamais remonter
     *  au client). Pour un appel où une erreur SQL est un résultat normal à traiter (pas une panne
     *  serveur), utiliser tenter() à la place. */
    private static function appelerRpc(string $fonction, string $sql, array $params)
    {
        $r = self::appelerRpcBrut($fonction, $sql, $params);
        if (!$r['ok']) {
            // Le détail (schéma, requête) ne doit jamais remonter au client : message générique.
            Http::erreur(503, 'Erreur base de données.');
        }
        return $r['donnees'];
    }

    /** Comme lignes(), mais ne quitte jamais : renvoie ['ok' => bool, 'ligne' => ?array,
     *  'lignes' => array, 'erreur' => ?string]. À utiliser quand une erreur SQL du texte fourni
     *  (ex. requête tapée par un stagiaire dans l'atelier SQL) est un résultat normal à renvoyer
     *  au client (400), pas une panne serveur (503) — appelerRpc()/lignes() ne conviennent pas
     *  ici car ils terminent le script immédiatement sur toute erreur. */
    public static function tenter(string $sql, array $params = []): array
    {
        $r = self::appelerRpcBrut('exec_sql', $sql, $params);
        if ($r['ok']) {
            $lignes = is_array($r['donnees']) ? $r['donnees'] : [];
            return ['ok' => true, 'ligne' => $lignes[0] ?? null, 'lignes' => $lignes, 'erreur' => null];
        }
        $donnees = $r['donnees'];
        $message = is_array($donnees) ? ($donnees['message'] ?? $donnees['hint'] ?? '') : '';
        return ['ok' => false, 'ligne' => null, 'lignes' => [], 'erreur' => $message !== '' ? $message : 'Erreur base de données.'];
    }

    public static function lignes(string $sql, array $params = []): array
    {
        $r = self::appelerRpc('exec_sql', $sql, $params);
        return is_array($r) ? $r : [];
    }

    public static function ligne(string $sql, array $params = []): ?array
    {
        $lignes = self::lignes($sql, $params);
        return $lignes[0] ?? null;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $r = self::appelerRpc('exec_write', $sql, $params);
        return is_int($r) ? $r : (int)$r;
    }
}
