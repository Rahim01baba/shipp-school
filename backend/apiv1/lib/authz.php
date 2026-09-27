<?php
/**
 * SHIPP School — controle d'acces centralise (roles + scopes).
 *
 * Droits effectifs d'un utilisateur sur un module :
 *   drapeaux = OU logique entre les droits de ses roles (role_permissions)
 *              et ses derogations individuelles (user_permissions, existant) ;
 *   scope    = perimetre des donnees : GLOBAL, SCHOOL, ASSIGNED_ROUTE,
 *              CHILDREN, OWN ou NONE.
 * Un administrateur (users.is_admin = 1) a tous les droits en scope GLOBAL.
 * Un utilisateur sans role et non administrateur est en scope NONE.
 *
 * Toutes les restrictions sont appliquees cote serveur ; le frontend ne fait
 * qu'afficher ou masquer.
 *
 * Compatible PHP 8.1 (version de production).
 */

const AUTHZ_FLAGS = ['can_read', 'can_create', 'can_edit', 'can_delete', 'can_validate', 'can_export'];
const AUTHZ_SCOPE_RANK = ['NONE' => 0, 'OWN' => 1, 'CHILDREN' => 2, 'ASSIGNED_ROUTE' => 3, 'SCHOOL' => 4, 'GLOBAL' => 5];

/**
 * Colonnes utiles au filtrage, par module (cle de module => [table, colonnes]).
 * Les colonnes absentes d'un module rendent ce module inaccessible pour les
 * scopes qui en ont besoin (refus par defaut).
 */
function authz_module_columns(): array
{
    return [
        'eleves' => ['table' => 'eleves', 'id' => 'id', 'eleve' => 'id', 'circuit' => 'circuit_id', 'ecole' => 'ecole_id'],
        'abonnements' => ['table' => 'abonnements', 'eleve' => 'eleve_id', 'ecole' => 'ecole_id'],
        'scans' => ['table' => 'scans', 'eleve' => 'eleve_id', 'trajet' => 'trajet_id', 'ecole' => 'ecole_id'],
        'finance' => ['table' => 'finance', 'eleve' => 'eleve_id', 'ecole' => 'ecole_id'],
        'notifications' => ['table' => 'notifications', 'cible' => 'cible', 'ecole' => 'ecole_id'],
        'trajets' => ['table' => 'trajets', 'circuit' => 'circuit_id', 'ecole' => 'ecole_id'],
        'etapes' => ['table' => 'etapes', 'circuit' => 'circuit_id', 'ecole' => 'ecole_id'],
        'circuits' => ['table' => 'circuits', 'id' => 'id', 'circuit' => 'id', 'ecole' => 'ecole_id'],
        'menus' => ['table' => 'menus', 'ecole' => 'ecole_id'],
        'parent_liaisons' => ['table' => 'parent_liaisons', 'user' => 'user_id', 'eleve' => 'eleve_id', 'ecole' => 'ecole_id'],
        'vehicules' => ['table' => 'vehicules', 'id' => 'id', 'vehicule' => 'id', 'ecole' => 'ecole_id'],
        'affectations_chauffeur' => ['table' => 'affectations_chauffeur', 'user' => 'user_id', 'circuit' => 'circuit_id', 'ecole' => 'ecole_id'],
        'couvertures_chauffeur' => ['table' => 'couvertures_chauffeur', 'user' => 'chauffeur_remplacant_id', 'circuit' => 'circuit_id', 'eleve' => 'eleve_id', 'ecole' => 'ecole_id'],
        'annees_scolaires' => ['table' => 'annees_scolaires', 'ecole' => 'ecole_id'],
        'ecoles' => ['table' => 'ecoles', 'id' => 'id', 'ecole' => 'id'],
        'utilisateurs' => ['table' => 'users', 'id' => 'id', 'user' => 'id', 'ecole' => 'ecole_id'],
        'transport' => ['table' => 'transport', 'ecole' => 'ecole_id'],
        'cantine' => ['table' => 'cantine', 'ecole' => 'ecole_id'],
        'parents_eleves' => ['table' => 'parents_eleves', 'ecole' => 'ecole_id'],
        'rapports' => ['table' => 'rapports', 'ecole' => 'ecole_id'],
        'ecole_modules' => ['table' => 'ecole_modules', 'ecole' => 'ecole_id'],
        'journal_activite' => ['table' => 'journal_activite', 'ecole' => 'ecole_id'],
        // Modules V5 (migrations 002 et suivantes)
        'chauffeurs' => ['table' => 'chauffeurs', 'id' => 'id', 'user' => 'user_id', 'chauffeur' => 'id', 'ecole' => 'ecole_id'],
        'vehicle_assignments' => ['table' => 'vehicle_assignments', 'chauffeur' => 'chauffeur_id', 'vehicule' => 'vehicle_id', 'ecole' => 'ecole_id'],
        'eleve_affectations' => ['table' => 'eleve_affectations_transport', 'eleve' => 'eleve_id', 'circuit' => 'circuit_id', 'ecole' => 'ecole_id'],
        'transport_events' => ['table' => 'transport_events', 'eleve' => 'eleve_id', 'trajet' => 'trajet_id', 'circuit' => 'circuit_id', 'chauffeur' => 'chauffeur_id', 'ecole' => 'ecole_id'],
        'incidents' => ['table' => 'incidents', 'chauffeur' => 'chauffeur_id', 'circuit' => 'circuit_id', 'vehicule' => 'vehicule_id', 'ecole' => 'ecole_id'],
        'chauffeur_documents' => ['table' => 'chauffeur_documents', 'chauffeur' => 'chauffeur_id', 'ecole' => 'ecole_id'],
        'chauffeur_contracts' => ['table' => 'chauffeur_contracts', 'chauffeur' => 'chauffeur_id', 'ecole' => 'ecole_id'],
        'contract_templates' => ['table' => 'contract_templates', 'ecole' => 'ecole_id'],
        // Modules V5 lot 5 (migration 005)
        'vehicle_documents' => ['table' => 'vehicle_documents', 'vehicule' => 'vehicle_id', 'ecole' => 'ecole_id'],
        'eleve_contacts' => ['table' => 'eleve_contacts', 'eleve' => 'eleve_id', 'ecole' => 'ecole_id'],
        'echeances_transport' => ['table' => 'echeances_transport', 'eleve' => 'eleve_id', 'ecole' => 'ecole_id'],
        'tarifs' => ['table' => 'tarifs', 'ecole' => 'ecole_id'],
        'imports' => ['table' => 'import_lots', 'ecole' => 'ecole_id'],
    ];
}

/**
 * Modules accessibles selon le scope (en plus du droit de module).
 * GLOBAL et SCHOOL : tous les modules.
 */
function authz_modules_for_scope(string $scope): ?array
{
    switch ($scope) {
        case 'GLOBAL':
        case 'SCHOOL':
            return null; // pas de restriction de module
        case 'CHILDREN':
            return ['eleves', 'abonnements', 'scans', 'finance', 'notifications', 'trajets', 'etapes', 'circuits', 'menus', 'parent_liaisons', 'annees_scolaires', 'ecoles', 'transport_events', 'eleve_affectations',
                'eleve_contacts', 'echeances_transport'];
        case 'ASSIGNED_ROUTE':
            return ['eleves', 'abonnements', 'scans', 'trajets', 'etapes', 'circuits', 'vehicules', 'affectations_chauffeur', 'couvertures_chauffeur', 'menus', 'annees_scolaires', 'ecoles',
                'chauffeurs', 'chauffeur_documents', 'chauffeur_contracts', 'vehicle_assignments', 'eleve_affectations', 'transport_events', 'incidents', 'vehicle_documents', 'eleve_contacts'];
        case 'OWN':
            return ['utilisateurs'];
        default:
            return [];
    }
}

function authz_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        try {
            $pdo->query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 0');
            $cache[$table] = true;
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
    }
    return $cache[$table];
}

function authz_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        try {
            $pdo->query('SELECT `' . str_replace('`', '', $column) . '` FROM `' . str_replace('`', '', $table) . '` LIMIT 0');
            $cache[$key] = true;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/**
 * Charge le contexte d'autorisation de l'utilisateur authentifie.
 */
function authz_load(PDO $pdo, int $userId): array
{
    $u = $pdo->prepare('SELECT id, ecole_id, is_admin, status FROM users WHERE id = ? LIMIT 1');
    $u->execute([$userId]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user || $user['status'] !== 'active') {
        http_response_code(401);
        echo json_encode(['message' => 'Compte introuvable ou inactif']);
        exit;
    }

    $ctx = [
        'user_id' => (int) $user['id'],
        'is_admin' => (bool) $user['is_admin'],
        'roles' => [],
        'base_scope' => $user['is_admin'] ? 'GLOBAL' : 'NONE',
        'ecole_ids' => $user['ecole_id'] !== null ? [(int) $user['ecole_id']] : [],
        'modules' => [],
        '_sets' => [],
    ];

    // Roles (table ajoutee par la migration 001 ; absente = aucun role).
    $roleScopes = [];
    if (authz_table_exists($pdo, 'user_roles')) {
        $r = $pdo->prepare(
            'SELECT r.id, r.role_key, ur.ecole_id, ur.scope
             FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ?'
        );
        $r->execute([$userId]);
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ctx['roles'][] = $row['role_key'];
            $roleScopes[(int) $row['id']] = $row['scope'] ?: null;
            if ($row['ecole_id'] !== null) {
                $ctx['ecole_ids'][] = (int) $row['ecole_id'];
            }
        }
    }
    $ctx['ecole_ids'] = array_values(array_unique($ctx['ecole_ids']));

    // Droits des roles.
    $hasScopeCol = authz_column_exists($pdo, 'role_permissions', 'scope');
    $hasRoleExtra = authz_column_exists($pdo, 'role_permissions', 'can_validate');
    if ($roleScopes) {
        $in = implode(',', array_fill(0, count($roleScopes), '?'));
        $cols = 'rp.role_id, p.module_key, rp.can_read, rp.can_create, rp.can_edit, rp.can_delete'
            . ($hasRoleExtra ? ', rp.can_validate, rp.can_export' : '')
            . ($hasScopeCol ? ', rp.scope' : '');
        $rp = $pdo->prepare("SELECT $cols FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id IN ($in)");
        $rp->execute(array_keys($roleScopes));
        foreach ($rp->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scope = $roleScopes[(int) $row['role_id']] ?? ($row['scope'] ?? 'SCHOOL');
            if (!isset(AUTHZ_SCOPE_RANK[$scope])) {
                $scope = 'NONE';
            }
            authz_merge($ctx, $row['module_key'], $row, $scope);
            if (AUTHZ_SCOPE_RANK[$scope] > AUTHZ_SCOPE_RANK[$ctx['base_scope']]) {
                $ctx['base_scope'] = $scope;
            }
        }
    }

    // Derogations individuelles (existant) : heritent du scope de base.
    $up = $pdo->prepare(
        'SELECT p.module_key, up.can_read, up.can_create, up.can_edit, up.can_delete, up.can_validate, up.can_export
         FROM user_permissions up JOIN permissions p ON p.id = up.permission_id
         WHERE up.user_id = ?'
    );
    $up->execute([$userId]);
    foreach ($up->fetchAll(PDO::FETCH_ASSOC) as $row) {
        authz_merge($ctx, $row['module_key'], $row, $ctx['base_scope']);
    }

    return $ctx;
}

function authz_merge(array &$ctx, string $module, array $row, string $scope): void
{
    if (!isset($ctx['modules'][$module])) {
        $ctx['modules'][$module] = ['flags' => array_fill_keys(AUTHZ_FLAGS, false), 'scope' => 'NONE'];
    }
    $granted = false;
    foreach (AUTHZ_FLAGS as $f) {
        if (!empty($row[$f])) {
            $ctx['modules'][$module]['flags'][$f] = true;
            $granted = true;
        }
    }
    if ($granted && AUTHZ_SCOPE_RANK[$scope] > AUTHZ_SCOPE_RANK[$ctx['modules'][$module]['scope']]) {
        $ctx['modules'][$module]['scope'] = $scope;
    }
}

/** Scope applicable a un module pour cet utilisateur. */
function authz_scope(array $ctx, string $module): string
{
    if ($ctx['is_admin']) {
        return 'GLOBAL';
    }
    $scope = $ctx['modules'][$module]['scope'] ?? 'NONE';
    $allowed = authz_modules_for_scope($scope);
    if ($allowed !== null && !in_array($module, $allowed, true)) {
        return 'NONE';
    }
    return $scope;
}

/** L'utilisateur a-t-il ce droit sur ce module (scope compris) ? */
function authz_can(array $ctx, string $module, string $flag = 'can_read'): bool
{
    if ($ctx['is_admin']) {
        return true;
    }
    if (authz_scope($ctx, $module) === 'NONE') {
        return false;
    }
    return !empty($ctx['modules'][$module]['flags'][$flag]);
}

/** Refuse la requete (403) si le droit manque. */
function authz_require(array $ctx, string $module, string $flag = 'can_read'): void
{
    if (!authz_can($ctx, $module, $flag)) {
        http_response_code(403);
        echo json_encode(['message' => 'Acces refuse pour ce module']);
        exit;
    }
}

/** Ensembles d'identifiants du perimetre (calcules une fois par requete). */
function authz_sets(PDO $pdo, array &$ctx): array
{
    if ($ctx['_sets']) {
        return $ctx['_sets'];
    }
    $uid = $ctx['user_id'];
    $sets = ['children' => [], 'children_circuits' => [], 'children_ecoles' => [], 'route_circuits' => [], 'route_eleves' => [], 'route_vehicules' => [], 'route_trajets' => [], 'my_chauffeurs' => []];
    if (authz_table_exists($pdo, 'chauffeurs')) {
        $s = $pdo->prepare('SELECT id FROM chauffeurs WHERE user_id = ?');
        $s->execute([$uid]);
        $sets['my_chauffeurs'] = array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }

    $s = $pdo->prepare('SELECT pl.eleve_id, e.circuit_id, e.ecole_id FROM parent_liaisons pl LEFT JOIN eleves e ON e.id = pl.eleve_id WHERE pl.user_id = ?');
    $s->execute([$uid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sets['children'][] = (int) $r['eleve_id'];
        if ($r['circuit_id'] !== null) {
            $sets['children_circuits'][] = (int) $r['circuit_id'];
        }
        if ($r['ecole_id'] !== null) {
            $sets['children_ecoles'][] = (int) $r['ecole_id'];
        }
    }

    $s = $pdo->prepare('SELECT circuit_id FROM affectations_chauffeur WHERE user_id = ?');
    $s->execute([$uid]);
    $sets['route_circuits'] = array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'circuit_id'));
    $s = $pdo->prepare('SELECT circuit_id, eleve_id FROM couvertures_chauffeur WHERE chauffeur_remplacant_id = ? AND date_debut <= CURDATE() AND (date_fin IS NULL OR date_fin >= CURDATE())');
    $s->execute([$uid]);
    $covEleves = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['eleve_id'] === null) {
            $sets['route_circuits'][] = (int) $r['circuit_id'];
        } else {
            $covEleves[] = (int) $r['eleve_id'];
        }
    }
    $sets['route_circuits'] = array_values(array_unique($sets['route_circuits']));
    $routeEleves = $covEleves;
    if ($sets['route_circuits']) {
        $in = implode(',', array_fill(0, count($sets['route_circuits']), '?'));
        $s = $pdo->prepare("SELECT id FROM eleves WHERE circuit_id IN ($in)");
        $s->execute($sets['route_circuits']);
        $routeEleves = array_merge($routeEleves, array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'id')));
        if (authz_table_exists($pdo, 'eleve_affectations_transport')) {
            // Affectations actives (un eleve peut suivre un circuit domicile et des navettes d'activite).
            $s = $pdo->prepare("SELECT eleve_id FROM eleve_affectations_transport WHERE circuit_id IN ($in) AND statut = 'active'");
            $s->execute($sets['route_circuits']);
            $routeEleves = array_merge($routeEleves, array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'eleve_id')));
        }
        $s = $pdo->prepare("SELECT vehicule_id FROM circuits WHERE id IN ($in) AND vehicule_id IS NOT NULL");
        $s->execute($sets['route_circuits']);
        $sets['route_vehicules'] = array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'vehicule_id'));
        $s = $pdo->prepare("SELECT id FROM trajets WHERE circuit_id IN ($in)");
        $s->execute($sets['route_circuits']);
        $sets['route_trajets'] = array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }
    // Trajets explicitement attribues a la fiche chauffeur de l'utilisateur.
    if ($sets['my_chauffeurs'] && authz_column_exists($pdo, 'trajets', 'chauffeur_id')) {
        $in = implode(',', array_fill(0, count($sets['my_chauffeurs']), '?'));
        $s = $pdo->prepare("SELECT id, vehicle_id FROM trajets WHERE chauffeur_id IN ($in)");
        $s->execute($sets['my_chauffeurs']);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sets['route_trajets'][] = (int) $r['id'];
        }
        $sets['route_trajets'] = array_values(array_unique($sets['route_trajets']));
    }
    // Vehicules affectes a la fiche chauffeur (affectations en cours).
    if ($sets['my_chauffeurs'] && authz_table_exists($pdo, 'vehicle_assignments')) {
        $in = implode(',', array_fill(0, count($sets['my_chauffeurs']), '?'));
        $s = $pdo->prepare("SELECT vehicle_id FROM vehicle_assignments WHERE chauffeur_id IN ($in) AND statut = 'active' AND date_debut <= CURDATE() AND (date_fin IS NULL OR date_fin >= CURDATE())");
        $s->execute($sets['my_chauffeurs']);
        $sets['route_vehicules'] = array_values(array_unique(array_merge($sets['route_vehicules'], array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'vehicle_id')))));
    }
    if ($sets['children'] && authz_table_exists($pdo, 'eleve_affectations_transport')) {
        $in = implode(',', array_fill(0, count($sets['children']), '?'));
        $s = $pdo->prepare("SELECT circuit_id FROM eleve_affectations_transport WHERE eleve_id IN ($in) AND statut = 'active'");
        $s->execute($sets['children']);
        $sets['children_circuits'] = array_merge($sets['children_circuits'], array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'circuit_id')));
    }
    $sets['route_eleves'] = array_values(array_unique($routeEleves));
    $sets['children'] = array_values(array_unique($sets['children']));
    $sets['children_circuits'] = array_values(array_unique($sets['children_circuits']));
    $sets['children_ecoles'] = array_values(array_unique($sets['children_ecoles']));
    $ctx['_sets'] = $sets;
    return $sets;
}

/** Fragment SQL "col IN (...)" ; ensemble vide => condition toujours fausse. */
function authz_in(string $col, array $ids, array &$params): string
{
    if (!$ids) {
        return '1 = 0';
    }
    foreach ($ids as $id) {
        $params[] = $id;
    }
    return $col . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
}

/**
 * Condition SQL limitant les lignes d'un module au perimetre de l'utilisateur.
 * Retourne [sql, params] ; sql = '1 = 1' si aucune restriction ; null si refus.
 * $alias : prefixe de table (ex. 's.'), vide par defaut.
 */
function authz_scope_condition(PDO $pdo, array &$ctx, string $module, string $alias = ''): ?array
{
    $scope = authz_scope($ctx, $module);
    $cols = authz_module_columns()[$module] ?? null;
    if ($scope === 'GLOBAL') {
        return ['1 = 1', []];
    }
    if ($scope === 'NONE' || !$cols) {
        return null;
    }
    $params = [];
    $a = $alias;

    if ($scope === 'SCHOOL') {
        if (empty($cols['ecole'])) {
            return null;
        }
        if (!$ctx['ecole_ids']) {
            return ['1 = 0', []];
        }
        $sql = '(' . authz_in($a . $cols['ecole'], $ctx['ecole_ids'], $params);
        if ($module !== 'ecoles') {
            $sql .= ' OR ' . $a . $cols['ecole'] . ' IS NULL';
        }
        return [$sql . ')', $params];
    }

    $sets = authz_sets($pdo, $ctx);

    if ($scope === 'CHILDREN') {
        switch ($module) {
            case 'eleves':
            case 'abonnements':
            case 'scans':
            case 'finance':
            case 'transport_events':
            case 'eleve_affectations':
            case 'eleve_contacts':
            case 'echeances_transport':
                return [authz_in($a . $cols['eleve'], $sets['children'], $params), $params];
            case 'notifications':
                $cibles = array_map(function ($id) { return 'eleve:' . $id; }, $sets['children']);
                return [authz_in($a . 'cible', $cibles, $params), $params];
            case 'trajets':
            case 'etapes':
            case 'circuits':
                return [authz_in($a . $cols['circuit'], $sets['children_circuits'], $params), $params];
            case 'parent_liaisons':
                $params[] = $ctx['user_id'];
                return [$a . 'user_id = ?', $params];
            case 'menus':
            case 'annees_scolaires':
            case 'ecoles':
                return [authz_in($a . $cols['ecole'], $sets['children_ecoles'], $params), $params];
        }
        return null;
    }

    if ($scope === 'ASSIGNED_ROUTE') {
        switch ($module) {
            case 'eleves':
            case 'abonnements':
                return [authz_in($a . $cols['eleve'], $sets['route_eleves'], $params), $params];
            case 'scans':
                $p1 = authz_in($a . 'trajet_id', $sets['route_trajets'], $params);
                $p2 = authz_in($a . 'eleve_id', $sets['route_eleves'], $params);
                return ['(' . $p1 . ' OR ' . $p2 . ')', $params];
            case 'trajets':
            case 'etapes':
            case 'circuits':
                return [authz_in($a . $cols['circuit'], $sets['route_circuits'], $params), $params];
            case 'vehicules':
                return [authz_in($a . 'id', $sets['route_vehicules'], $params), $params];
            case 'vehicle_documents':
                return [authz_in($a . 'vehicle_id', $sets['route_vehicules'], $params), $params];
            case 'eleve_contacts':
                return [authz_in($a . 'eleve_id', $sets['route_eleves'], $params), $params];
            case 'affectations_chauffeur':
                $params[] = $ctx['user_id'];
                return [$a . 'user_id = ?', $params];
            case 'couvertures_chauffeur':
                $params[] = $ctx['user_id'];
                return [$a . 'chauffeur_remplacant_id = ?', $params];
            case 'chauffeurs':
                $params[] = $ctx['user_id'];
                return [$a . 'user_id = ?', $params];
            case 'chauffeur_documents':
            case 'chauffeur_contracts':
            case 'vehicle_assignments':
                return [authz_in($a . 'chauffeur_id', $sets['my_chauffeurs'], $params), $params];
            case 'eleve_affectations':
                return [authz_in($a . 'circuit_id', $sets['route_circuits'], $params), $params];
            case 'transport_events':
                $p1 = authz_in($a . 'trajet_id', $sets['route_trajets'], $params);
                $p2 = authz_in($a . 'chauffeur_id', $sets['my_chauffeurs'], $params);
                return ['(' . $p1 . ' OR ' . $p2 . ')', $params];
            case 'incidents':
                $p1 = authz_in($a . 'chauffeur_id', $sets['my_chauffeurs'], $params);
                $p2 = authz_in($a . 'circuit_id', $sets['route_circuits'], $params);
                return ['(' . $p1 . ' OR ' . $p2 . ')', $params];
            case 'menus':
            case 'annees_scolaires':
            case 'ecoles':
                if (!$ctx['ecole_ids']) {
                    return ['1 = 0', []];
                }
                return [authz_in($a . $cols['ecole'], $ctx['ecole_ids'], $params), $params];
        }
        return null;
    }

    if ($scope === 'OWN') {
        if (!empty($cols['user'])) {
            $params[] = $ctx['user_id'];
            return [$a . $cols['user'] . ' = ?', $params];
        }
        return null;
    }

    return null;
}

/** La ligne $id du module est-elle dans le perimetre de l'utilisateur ? */
function authz_row_allowed(PDO $pdo, array &$ctx, string $module, int $id): bool
{
    $cond = authz_scope_condition($pdo, $ctx, $module);
    if ($cond === null) {
        return false;
    }
    $table = authz_module_columns()[$module]['table'] ?? null;
    if (!$table) {
        return false;
    }
    $s = $pdo->prepare('SELECT id FROM `' . $table . '` WHERE id = ? AND (' . $cond[0] . ') LIMIT 1');
    $s->execute(array_merge([$id], $cond[1]));
    return (bool) $s->fetch();
}

/**
 * Les valeurs fournies a la creation restent-elles dans le perimetre ?
 * (ex. un chauffeur ne peut creer un scan que pour un eleve de ses circuits)
 */
function authz_values_allowed(PDO $pdo, array &$ctx, string $module, array $values): bool
{
    $scope = authz_scope($ctx, $module);
    if ($scope === 'GLOBAL' || $scope === 'SCHOOL') {
        // ecole_id est injecte cote serveur a la creation.
        return true;
    }
    if ($scope === 'NONE') {
        return false;
    }
    $sets = authz_sets($pdo, $ctx);
    $cols = authz_module_columns()[$module] ?? [];
    if ($scope === 'CHILDREN') {
        // Un parent ne cree rien via le CRUD generique.
        return false;
    }
    if ($scope === 'ASSIGNED_ROUTE') {
        $ok = false;
        if (!empty($cols['circuit']) && array_key_exists($cols['circuit'], $values)) {
            if (!in_array((int) $values[$cols['circuit']], $sets['route_circuits'], true)) {
                return false;
            }
            $ok = true;
        }
        if (!empty($cols['eleve']) && array_key_exists($cols['eleve'], $values) && $values[$cols['eleve']] !== null) {
            if (!in_array((int) $values[$cols['eleve']], $sets['route_eleves'], true)) {
                return false;
            }
            $ok = true;
        }
        if (!empty($cols['chauffeur']) && array_key_exists($cols['chauffeur'], $values) && $values[$cols['chauffeur']] !== null) {
            if (!in_array((int) $values[$cols['chauffeur']], $sets['my_chauffeurs'], true)) {
                return false;
            }
            $ok = true;
        }
        if (!empty($cols['trajet']) && array_key_exists($cols['trajet'], $values) && $values[$cols['trajet']] !== null) {
            if (!in_array((int) $values[$cols['trajet']], $sets['route_trajets'], true)) {
                return false;
            }
            $ok = true;
        }
        return $ok;
    }
    return false;
}

/** Droits effectifs exposes au frontend (permissions-me). */
function authz_effective_permissions(PDO $pdo, array $ctx): array
{
    $out = [];
    $keys = $pdo->query('SELECT module_key FROM permissions ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($keys as $k) {
        $row = [];
        foreach (AUTHZ_FLAGS as $f) {
            $row[$f] = authz_can($ctx, $k, $f);
        }
        $row['scope'] = authz_scope($ctx, $k);
        $out[$k] = $row;
    }
    return $out;
}

/** Identifiant de la fiche chauffeur liee a l'utilisateur (ou null). */
function authz_my_chauffeur_id(PDO $pdo, array &$ctx): ?int
{
    $sets = authz_sets($pdo, $ctx);
    return $sets['my_chauffeurs'][0] ?? null;
}
