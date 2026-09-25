<?php
/**
 * Notifications de l'utilisateur connecte, avec statut de lecture individuel.
 * GET                    : mes notifications (100 dernieres) + nombre de non lues
 * PUT {id}               : marquer une notification comme lue (pour moi seul)
 * PUT {tout: true}       : tout marquer comme lu (pour moi seul)
 */
require __DIR__ . '/lib/db.php';
shipp_headers('GET, PUT, OPTIONS');
require __DIR__ . '/auth-lib.php';
$authUser = require_auth();
$pdo = shipp_db();
require __DIR__ . '/lib/authz.php';
$ctx = authz_load($pdo, (int) $authUser['sub']);
$uid = (int) $authUser['sub'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = $pdo->prepare(
        'SELECT n.id, n.titre, n.message, n.type, n.eleve_id, n.trajet_id, n.created_at, nr.lu_at, nr.envoye_at
         FROM notification_recipients nr JOIN notifications n ON n.id = nr.notification_id
         WHERE nr.user_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT 100'
    );
    $s->execute([$uid]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $c = $pdo->prepare('SELECT COUNT(*) FROM notification_recipients WHERE user_id = ? AND lu_at IS NULL');
    $c->execute([$uid]);
    echo json_encode(['data' => $rows, 'non_lues' => (int) $c->fetchColumn()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = shipp_json_input();
    if (!empty($input['tout'])) {
        $pdo->prepare('UPDATE notification_recipients SET lu_at = NOW() WHERE user_id = ? AND lu_at IS NULL')->execute([$uid]);
    } else {
        $id = (int) ($input['id'] ?? 0);
        $u = $pdo->prepare('UPDATE notification_recipients SET lu_at = COALESCE(lu_at, NOW()) WHERE user_id = ? AND notification_id = ?');
        $u->execute([$uid, $id]);
        if ($u->rowCount() === 0) {
            $chk = $pdo->prepare('SELECT 1 FROM notification_recipients WHERE user_id = ? AND notification_id = ?');
            $chk->execute([$uid, $id]);
            if (!$chk->fetch()) {
                shipp_error(404, 'Notification introuvable');
            }
        }
    }
    echo json_encode(['message' => 'ok']);
    exit;
}

shipp_error(405, 'Methode non autorisee');
