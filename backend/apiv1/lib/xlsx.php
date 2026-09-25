<?php
/**
 * Lecteur XLSX minimal, en lecture seule (ZipArchive + SimpleXML), sans
 * dependance externe : l'hebergement ne dispose pas de PhpSpreadsheet.
 * Pour les cellules calculees, la valeur mise en cache par le tableur est lue
 * (celle affichee a l'utilisateur) ; la formule est conservee a part.
 */

function xlsx_open(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Extension zip indisponible sur le serveur');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Fichier Excel illisible');
    }
    $read = function (string $name) use ($zip) {
        $x = $zip->getFromName($name);
        if ($x === false) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($x, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        return $xml ?: null;
    };
    $wb = $read('xl/workbook.xml');
    $rels = $read('xl/_rels/workbook.xml.rels');
    if (!$wb || !$rels) {
        throw new RuntimeException('Classeur Excel invalide');
    }
    $targets = [];
    foreach ($rels->Relationship as $r) {
        $t = (string) $r['Target'];
        $targets[(string) $r['Id']] = strpos($t, '/') === 0 ? ltrim($t, '/') : 'xl/' . $t;
    }
    $sheets = [];
    foreach ($wb->sheets->sheet as $s) {
        $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $sheets[] = ['nom' => (string) $s['name'], 'etat' => (string) ($s['state'] ?: 'visible'), 'fichier' => $targets[$rid] ?? null];
    }
    $shared = [];
    $ss = $read('xl/sharedStrings.xml');
    if ($ss) {
        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string) $si->t;
            } else {
                $txt = '';
                foreach ($si->r as $run) {
                    $txt .= (string) $run->t;
                }
                $shared[] = $txt;
            }
        }
    }
    return ['zip' => $zip, 'read' => $read, 'feuilles' => $sheets, 'shared' => $shared];
}

/** Index de colonne (0) depuis une reference « AB12 ». */
function xlsx_col(string $ref): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/**
 * Lignes d'une feuille : [numero_ligne => [col => valeur]] (valeurs calculees),
 * et formules : [numero_ligne => [col => formule]].
 */
function xlsx_rows(array $book, string $sheetName, int $maxRows = 5000): array
{
    $file = null;
    foreach ($book['feuilles'] as $f) {
        if ($f['nom'] === $sheetName) {
            $file = $f['fichier'];
        }
    }
    if (!$file) {
        throw new RuntimeException('Feuille introuvable');
    }
    $xml = ($book['read'])($file);
    if (!$xml || !isset($xml->sheetData)) {
        return ['valeurs' => [], 'formules' => []];
    }
    $rows = [];
    $formules = [];
    foreach ($xml->sheetData->row as $row) {
        $r = (int) $row['r'];
        if ($r > $maxRows) {
            break;
        }
        foreach ($row->c as $c) {
            $col = xlsx_col((string) $c['r']);
            $t = (string) $c['t'];
            if ($t === 'inlineStr') {
                $v = (string) $c->is->t;
            } elseif (!isset($c->v)) {
                $v = null;
            } elseif ($t === 's') {
                $v = $book['shared'][(int) $c->v] ?? null;
            } elseif ($t === 'b') {
                $v = ((string) $c->v) === '1';
            } elseif ($t === 'str' || $t === 'e') {
                $v = (string) $c->v;
            } else {
                $raw = (string) $c->v;
                $v = is_numeric($raw) ? $raw + 0 : $raw;
            }
            if ($v !== null && $v !== '') {
                $rows[$r][$col] = $v;
            }
            if (isset($c->f) && (string) $c->f !== '') {
                $formules[$r][$col] = (string) $c->f;
            }
        }
    }
    return ['valeurs' => $rows, 'formules' => $formules];
}
