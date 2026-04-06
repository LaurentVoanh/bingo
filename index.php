<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// --- CONFIGURATION ---
$DEBUG_MODE = true;
$dbFile = __DIR__ . '/data/keys.sqlite';
$appsDir = __DIR__ . '/apps';
$baseUrl = 'https://bingo.voanh.art';

// Créer les dossiers nécessaires
if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0755, true);
if (!is_dir($appsDir)) mkdir($appsDir, 0755, true);

// --- INITIALISATION BDD SQLITE ---
try {
    $db = new PDO("sqlite:$dbFile");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");

    $db->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        api_key TEXT UNIQUE NOT NULL,
        is_valid INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_used DATETIME DEFAULT CURRENT_TIMESTAMP,
        fail_count INTEGER DEFAULT 0
    )");

    // Insérer les clés par défaut si la table est vide
    $count = $db->query("SELECT COUNT(*) FROM api_keys")->fetchColumn();
    if ($count == 0) {
        // Clés par défaut vides - l'utilisateur doit ajouter ses propres clés
        $defaultKeys = [
            'votre_cle_mistral_ici_remplacer_par_vraie_cle',
        ];
        $stmt = $db->prepare("INSERT OR IGNORE INTO api_keys (api_key, is_valid) VALUES (:key, 0)");
        foreach ($defaultKeys as $k) {
            $stmt->execute([':key' => $k]);
        }
    }
} catch (PDOException $e) {
    die(json_encode(['error' => 'Fatal DB Error: ' . $e->getMessage()]));
}

// --- GESTION DES REQUÊTES AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    // 1. Tester et Sauvegarder une clé
    if ($action === 'save_key') {
        $key = trim($input['key'] ?? '');
        if (empty($key)) { echo json_encode(['success' => false, 'message' => 'Clé vide']); exit; }

        $isValid = testMistralKey($key);

        if ($isValid) {
            try {
                $stmt = $db->prepare("INSERT OR REPLACE INTO api_keys (api_key, is_valid, last_used, fail_count) VALUES (:key, 1, CURRENT_TIMESTAMP, 0)");
                $stmt->execute([':key' => $key]);
                echo json_encode(['success' => true, 'message' => 'Clé valide et enregistrée !']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Erreur SQL: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Clé invalide ou erreur réseau.']);
        }
        exit;
    }

    // 2. Récupérer les clés
    if ($action === 'get_keys') {
        $stmt = $db->query("SELECT api_key FROM api_keys WHERE is_valid = 1 ORDER BY last_used ASC");
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['count' => count($keys), 'keys' => $keys]);
        exit;
    }

    // 3. Supprimer une clé
    if ($action === 'delete_key') {
        $key = trim($input['key'] ?? '');
        if (empty($key)) { echo json_encode(['success' => false]); exit; }
        $stmt = $db->prepare("DELETE FROM api_keys WHERE api_key = :key");
        $stmt->execute([':key' => $key]);
        echo json_encode(['success' => true]);
        exit;
    }

    // 4. Déployer l'application
    if ($action === 'deploy_app') {
        $appName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $input['appName'] ?? 'mon_app');
        $appName = substr($appName, 0, 40);
        $files = $input['files'] ?? [];
        $tiktokUser = preg_replace('/[^a-zA-Z0-9_.]/', '', $input['tiktokUser'] ?? 'monprofil');

        if (empty($files)) {
            echo json_encode(['success' => false, 'message' => 'Aucun fichier à créer.']);
            exit;
        }

        $targetDir = "$appsDir/$appName";

        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                echo json_encode(['success' => false, 'message' => "Impossible de créer le dossier."]);
                exit;
            }
        }

        $created = [];
        foreach ($files as $filename => $content) {
            $safeName = basename($filename);
            // Sécurité : seulement extensions web autorisées
            $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['html', 'css', 'js', 'json', 'txt', 'svg'])) continue;

            $filePath = "$targetDir/$safeName";

            // Nettoyage des blocs markdown
            $cleanContent = preg_replace('/^```[\w]*\r?\n/', '', $content);
            $cleanContent = preg_replace('/\r?\n```$/', '', trim($cleanContent));

            if (file_put_contents($filePath, $cleanContent) !== false) {
                $created[] = $safeName;
            } else {
                echo json_encode(['success' => false, 'message' => "Échec écriture: $safeName"]);
                exit;
            }
        }

        if (empty($created)) {
            echo json_encode(['success' => false, 'message' => 'Aucun fichier valide généré.']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Application déployée !',
            'url' => "$baseUrl/apps/$appName/index.html",
            'files' => $created
        ]);
        exit;
    }

    echo json_encode(['error' => 'Action inconnue']);
    exit;
}

// --- FONCTION PHP POUR TESTER UNE CLÉ ---
function testMistralKey($key) {
    $url = "https://api.mistral.ai/v1/chat/completions";
    $data = [
 problème-création-application-d8bc8
        "model" => "pixtral-12b-2409", // Modèle pour le test
=======
        "model" => "pixtral-12b-2409", // Modèle rapide pour le test
 main
        "messages" => [["role" => "user", "content" => "OK"]],
        "max_tokens" => 5
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $key"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 200);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ViralForge — Créateur d'Apps</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* ===== RESET & BASE ===== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg: #05050a;
            --surface: #0d0d1a;
            --surface2: #13131f;
            --border: rgba(255,255,255,0.08);
            --border-glow: rgba(99, 102, 241, 0.4);
            --primary: #6366f1;
            --primary-glow: rgba(99, 102, 241, 0.25);
            --accent: #f0abfc;
            --accent2: #34d399;
            --text: #f4f4ff;
            --text-muted: #6b7280;
            --success: #34d399;
            --error: #f87171;
            --warn: #fbbf24;
            --tiktok: #fe2c55;
            --font-display: 'Syne', sans-serif;
            --font-mono: 'Space Mono', monospace;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: var(--font-display);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ===== BACKGROUND ===== */
        .bg-grid {
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(99,102,241,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(99,102,241,0.03) 1px, transparent 1px);
            background-size: 60px 60px;
            pointer-events: none;
            z-index: 0;
        }

        .bg-orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(120px);
            pointer-events: none;
            z-index: 0;
        }
        .bg-orb-1 { width: 500px; height: 500px; background: rgba(99,102,241,0.12); top: -200px; right: -100px; }
        .bg-orb-2 { width: 400px; height: 400px; background: rgba(240,171,252,0.07); bottom: -100px; left: -100px; }

        /* ===== LAYOUT ===== */
        .wrapper {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px 80px;
        }

        .container {
            width: 100%;
            max-width: 860px;
        }

        /* ===== HEADER ===== */
        .header {
            text-align: center;
            margin-bottom: 48px;
            padding-top: 20px;
        }

        .header-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(99,102,241,0.12);
            border: 1px solid rgba(99,102,241,0.3);
            padding: 6px 16px;
            border-radius: 100px;
            font-size: 12px;
            font-family: var(--font-mono);
            color: var(--primary);
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: clamp(36px, 6vw, 64px);
            font-weight: 800;
            line-height: 1.05;
            letter-spacing: -0.03em;
            background: linear-gradient(135deg, #fff 0%, var(--accent) 50%, var(--primary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header p {
            margin-top: 14px;
            color: var(--text-muted);
            font-size: 16px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }

        /* ===== PROGRESS BAR ===== */
        .progress-bar {
            display: flex;
            align-items: center;
            gap: 0;
            margin-bottom: 40px;
            position: relative;
        }

        .progress-bar::before {
            content: '';
            position: absolute;
            top: 18px;
            left: 18px;
            right: 18px;
            height: 1px;
            background: var(--border);
            z-index: 0;
        }

        .progress-line {
            position: absolute;
            top: 18px;
            left: 18px;
            height: 1px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1;
        }

        .step-dot {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }

        .step-dot-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid var(--border);
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-family: var(--font-mono);
            color: var(--text-muted);
            transition: all 0.3s ease;
        }

        .step-dot.active .step-dot-circle {
            border-color: var(--primary);
            background: var(--primary-glow);
            color: var(--primary);
            box-shadow: 0 0 20px var(--primary-glow);
        }

        .step-dot.done .step-dot-circle {
            border-color: var(--accent2);
            background: rgba(52,211,153,0.15);
            color: var(--accent2);
        }

        .step-dot-label {
            margin-top: 8px;
            font-size: 11px;
            color: var(--text-muted);
            font-family: var(--font-mono);
            letter-spacing: 0.05em;
            text-align: center;
        }

        .step-dot.active .step-dot-label { color: var(--text); }

        /* ===== CARD ===== */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 40px;
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(99,102,241,0.5), transparent);
        }

        /* ===== FORM ELEMENTS ===== */
        label {
            display: block;
            font-size: 13px;
            font-family: var(--font-mono);
            color: var(--text-muted);
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        textarea, input[type="text"] {
            width: 100%;
            padding: 16px 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--surface2);
            color: var(--text);
            font-family: var(--font-display);
            font-size: 15px;
            line-height: 1.6;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            resize: vertical;
        }

        textarea:focus, input[type="text"]:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        textarea { min-height: 110px; }

        .input-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .form-group { margin-bottom: 24px; }

        /* ===== BUTTONS ===== */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 16px 28px;
            border: none;
            border-radius: 12px;
            font-family: var(--font-display);
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, #8b5cf6 100%);
            color: white;
            width: 100%;
            font-size: 16px;
            padding: 18px;
            box-shadow: 0 4px 20px rgba(99,102,241,0.3);
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(99,102,241,0.4);
        }

        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        .btn-ghost {
            background: var(--surface2);
            color: var(--text);
            border: 1px solid var(--border);
            white-space: nowrap;
        }

        .btn-ghost:hover { border-color: var(--primary); color: var(--primary); }

        .btn-success {
            background: linear-gradient(135deg, var(--accent2) 0%, #059669 100%);
            color: #000;
            box-shadow: 0 4px 20px rgba(52,211,153,0.3);
        }

        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(52,211,153,0.4); }

        .btn-sm { padding: 10px 18px; font-size: 13px; border-radius: 8px; }

        /* ===== STEPS ===== */
        .step { display: none; }
        .step.active { display: block; animation: slideUp 0.4s cubic-bezier(0.4, 0, 0.2, 1); }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ===== LOG BOX ===== */
        .log-box {
            background: #020207;
            color: #a3e635;
            font-family: var(--font-mono);
            font-size: 12px;
            line-height: 1.8;
            padding: 20px;
            border-radius: 12px;
            height: 220px;
            overflow-y: auto;
            border: 1px solid rgba(163,230,53,0.1);
            scrollbar-width: thin;
            scrollbar-color: rgba(163,230,53,0.2) transparent;
        }

        .log-box::-webkit-scrollbar { width: 4px; }
        .log-box::-webkit-scrollbar-track { background: transparent; }
        .log-box::-webkit-scrollbar-thumb { background: rgba(163,230,53,0.2); border-radius: 4px; }

        .log-error { color: var(--error) !important; }
        .log-warn { color: var(--warn) !important; }
        .log-success { color: var(--accent2) !important; }
        .log-info { color: #60a5fa !important; }

        /* ===== CONCEPT CARDS ===== */
        .concepts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 24px;
        }

        .concept-card {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .concept-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(99,102,241,0.1), transparent);
            opacity: 0;
            transition: opacity 0.25s;
        }

        .concept-card:hover {
            border-color: var(--primary);
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(99,102,241,0.2);
        }

        .concept-card:hover::before { opacity: 1; }

        .concept-emoji {
            font-size: 28px;
            margin-bottom: 10px;
            display: block;
        }

        .concept-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--text);
        }

        .concept-desc {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* ===== PROGRESS RING ===== */
        .progress-center {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
            padding: 20px 0;
        }

        .progress-ring-wrap {
            position: relative;
            width: 100px;
            height: 100px;
        }

        .progress-ring-wrap svg {
            transform: rotate(-90deg);
        }

        .progress-ring-bg { stroke: rgba(255,255,255,0.05); }
        .progress-ring-fill {
            stroke: var(--primary);
            stroke-linecap: round;
            transition: stroke-dashoffset 0.5s ease;
            filter: drop-shadow(0 0 8px var(--primary));
        }

        .progress-ring-text {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-mono);
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
        }

        .progress-label {
            font-family: var(--font-mono);
            font-size: 13px;
            color: var(--text-muted);
            text-align: center;
            max-width: 300px;
        }

        .progress-label strong { color: var(--text); }

        /* ===== SUCCESS ===== */
        .success-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .success-icon {
            width: 72px;
            height: 72px;
            background: rgba(52,211,153,0.1);
            border: 1px solid rgba(52,211,153,0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 20px;
            animation: pop 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes pop {
            from { transform: scale(0); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .success-header h2 {
            font-size: 28px;
            font-weight: 800;
        }

        .success-header p {
            color: var(--text-muted);
            margin-top: 8px;
        }

        .files-list {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .file-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
            font-family: var(--font-mono);
            font-size: 13px;
            color: var(--accent2);
        }

        .file-item:last-child { border-bottom: none; }

        .url-copy-box {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: border-color 0.2s;
        }

        .url-copy-box:hover { border-color: var(--primary); }

        .url-copy-box span {
            flex: 1;
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--primary);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .copy-btn {
            padding: 6px 14px;
            background: rgba(99,102,241,0.15);
            border: 1px solid rgba(99,102,241,0.3);
            border-radius: 6px;
            color: var(--primary);
            font-size: 12px;
            font-family: var(--font-mono);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }

        .copy-btn:hover { background: rgba(99,102,241,0.25); }

        .tiktok-link-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(254,44,85,0.08);
            border: 1px solid rgba(254,44,85,0.2);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 28px;
            text-decoration: none;
            color: var(--text);
            transition: all 0.2s;
        }

        .tiktok-link-box:hover { background: rgba(254,44,85,0.14); border-color: rgba(254,44,85,0.4); }

        .tiktok-icon-wrap {
            width: 40px;
            height: 40px;
            background: var(--tiktok);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .success-actions {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
        }

        /* ===== DEBUG PANEL ===== */
        .debug-panel {
            margin-top: 32px;
            border-top: 1px solid var(--border);
            padding-top: 24px;
        }

        .debug-title {
            font-family: var(--font-mono);
            font-size: 12px;
            letter-spacing: 0.1em;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        .keys-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 12px;
            max-height: 160px;
            overflow-y: auto;
        }

        .key-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: var(--surface2);
            border-radius: 8px;
            border: 1px solid var(--border);
            font-family: var(--font-mono);
            font-size: 12px;
        }

        .key-dot { width: 8px; height: 8px; background: var(--accent2); border-radius: 50%; flex-shrink: 0; }
        .key-value { flex: 1; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .key-del {
            color: var(--error);
            cursor: pointer;
            background: none;
            border: none;
            font-size: 14px;
            padding: 2px 6px;
            border-radius: 4px;
            opacity: 0.6;
            transition: opacity 0.2s;
        }
        .key-del:hover { opacity: 1; background: rgba(248,113,113,0.1); }

        .status-msg {
            font-size: 13px;
            padding: 8px 14px;
            border-radius: 8px;
            margin-top: 10px;
            font-family: var(--font-mono);
        }

        .status-msg.success { background: rgba(52,211,153,0.1); color: var(--accent2); border: 1px solid rgba(52,211,153,0.2); }
        .status-msg.error { background: rgba(248,113,113,0.1); color: var(--error); border: 1px solid rgba(248,113,113,0.2); }

        /* ===== SECTION TITLE ===== */
        .section-title {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 6px;
        }
        .section-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 28px;
        }

        /* ===== RETRY BANNER ===== */
        .retry-banner {
            display: none;
            align-items: center;
            gap: 12px;
            background: rgba(251,191,36,0.08);
            border: 1px solid rgba(251,191,36,0.2);
            border-radius: 10px;
            padding: 12px 16px;
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--warn);
            margin-top: 16px;
        }

        .retry-banner.show { display: flex; }

        .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(251,191,36,0.2);
            border-top-color: var(--warn);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            flex-shrink: 0;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 600px) {
            .card { padding: 24px 20px; }
            .concepts-grid { grid-template-columns: 1fr 1fr; }
            .success-actions { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="bg-grid"></div>
<div class="bg-orb bg-orb-1"></div>
<div class="bg-orb bg-orb-2"></div>

<div class="wrapper">
    <div class="container">

        <!-- HEADER -->
        <div class="header">
            <div class="header-badge">
                <span>⚡</span>
                <span>ViralForge AI</span>
            </div>
            <h1>Créateur d'Apps<br>Virales</h1>
            <p>Décrivez une idée, l'IA génère et déploie l'application complète sur bingo.voanh.art</p>
        </div>

        <!-- PROGRESS BAR -->
        <div class="progress-bar" id="progressBar">
            <div class="progress-line" id="progressLine" style="width: 0%"></div>
            <div class="step-dot active" id="dot1">
                <div class="step-dot-circle">1</div>
                <div class="step-dot-label">Idée</div>
            </div>
            <div class="step-dot" id="dot2">
                <div class="step-dot-circle">2</div>
                <div class="step-dot-label">Concept</div>
            </div>
            <div class="step-dot" id="dot3">
                <div class="step-dot-circle">3</div>
                <div class="step-dot-label">Génération</div>
            </div>
            <div class="step-dot" id="dot4">
                <div class="step-dot-circle">✓</div>
                <div class="step-dot-label">Déployé</div>
            </div>
        </div>

        <!-- ÉTAPE 1 : Idée -->
        <div id="step1" class="step active">
            <div class="card">
                <div class="section-title">Votre idée 💡</div>
                <div class="section-sub">Décrivez l'application que vous voulez créer — plus c'est précis, mieux c'est.</div>

                <div class="form-group">
                    <label>Description du projet</label>
                    <textarea id="userIdea" placeholder="Ex: Un jeu de quiz musical où les joueurs devinent des chansons en 5 notes, avec classement mondial et partage automatique sur TikTok..."></textarea>
                </div>

                <div class="form-group">
                    <label>Votre pseudo TikTok</label>
                    <input type="text" id="tiktokUser" placeholder="@votre_pseudo" value="@monprofil">
                </div>

                <button class="btn btn-primary" onclick="startGeneration()">
                    <span>⚡</span>
                    <span>Générer les concepts</span>
                </button>

                <?php if ($DEBUG_MODE): ?>
                <div class="debug-panel">
                    <div class="debug-title">🔑 Gestion des clés API Mistral</div>
                    <div class="input-row">
                        <input type="text" id="apiKeyInput" placeholder="Coller une clé API Mistral...">
                        <button class="btn btn-ghost btn-sm" onclick="addKey()">Ajouter</button>
                    </div>
                    <div id="keyStatus"></div>
                    <div class="keys-list" id="keysList"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ÉTAPE 2 : Concepts -->
        <div id="step2" class="step">
            <div class="card">
                <div id="loader2">
                    <div class="section-title">Analyse du marché 🧠</div>
                    <div class="section-sub">L'IA génère 5 concepts viraux adaptés à votre idée…</div>
                    <div class="log-box" id="log2"></div>
                </div>
                <div id="conceptsList" style="display:none;">
                    <div class="section-title">Choisissez un concept</div>
                    <div class="section-sub">Cliquez sur le concept que vous souhaitez développer en application complète.</div>
                    <div class="concepts-grid" id="optionsGrid"></div>
                </div>
            </div>
        </div>

        <!-- ÉTAPE 3 : Génération Code -->
        <div id="step3" class="step">
            <div class="card">
                <div class="section-title">Construction de l'app 🛠️</div>
                <div class="section-sub">Modèle <strong style="color:var(--primary)">pixtral-12b-2409</strong> — génération du code complet…</div>

                <div class="progress-center">
                    <div class="progress-ring-wrap">
                        <svg width="100" height="100" viewBox="0 0 100 100">
                            <circle class="progress-ring-bg" cx="50" cy="50" r="42" stroke-width="6" fill="none"/>
                            <circle class="progress-ring-fill" id="ringFill" cx="50" cy="50" r="42"
                                stroke-width="6" fill="none"
                                stroke-dasharray="263.9"
                                stroke-dashoffset="263.9"/>
                        </svg>
                        <div class="progress-ring-text" id="ringPercent">0%</div>
                    </div>
                    <div class="progress-label" id="progressLabel">Initialisation…</div>
                </div>

                <div class="log-box" id="log3"></div>

                <div class="retry-banner" id="retryBanner">
                    <div class="spinner"></div>
                    <span id="retryMsg">Nouvelle tentative en cours…</span>
                </div>
            </div>
        </div>

        <!-- ÉTAPE 4 : Succès -->
        <div id="step4" class="step">
            <div class="card">
                <div class="success-header">
                    <div class="success-icon">✅</div>
                    <h2>Application déployée !</h2>
                    <p>Votre app est en ligne sur <strong>bingo.voanh.art</strong></p>
                </div>

                <div class="files-list" id="fileList"></div>

                <div class="url-copy-box" id="urlCopyBox" onclick="copyUrl()">
                    <span>🔗</span>
                    <span id="appUrl">—</span>
                    <div class="copy-btn" id="copyBtnLabel">Copier</div>
                </div>

                <a id="tiktokLink" href="#" target="_blank" class="tiktok-link-box">
                    <div class="tiktok-icon-wrap">🎵</div>
                    <div>
                        <div style="font-weight:700; font-size:14px;">Partager sur TikTok</div>
                        <div style="font-size:12px; color:var(--text-muted);" id="tiktokHandle">@profil</div>
                    </div>
                    <span style="margin-left:auto; font-size:20px; opacity:0.5">↗</span>
                </a>

                <div class="success-actions">
                    <a id="launchBtn" href="#" target="_blank" class="btn btn-success btn-primary" style="text-align:center;">
                        🚀 Lancer l'application
                    </a>
                    <button class="btn btn-ghost" onclick="location.reload()" style="width:auto">
                        + Nouveau
                    </button>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    // ===== ÉTAT GLOBAL =====
    const State = {
        idea: '',
        concept: '',
        appName: '',
        tiktokUser: '',
        currentStep: 1
    };

    // ===== UTILITAIRES =====
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    const $ = id => document.getElementById(id);

    function log(id, msg, type = 'default') {
        const el = $(id);
        if (!el) return;
        const t = new Date().toLocaleTimeString('fr', { hour12: false });
        const cls = { error: 'log-error', warn: 'log-warn', success: 'log-success', info: 'log-info' }[type] || '';
        el.innerHTML += `<div class="${cls}">[${t}] ${msg}</div>`;
        el.scrollTop = el.scrollHeight;
    }

    function showStep(n) {
        document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
        $(`step${n}`).classList.add('active');
        State.currentStep = n;
        updateProgress(n);
    }

    function updateProgress(n) {
        const dots = [1,2,3,4];
        const pct = [(0), (33), (66), (100)][n-1];
        $('progressLine').style.width = pct + '%';
        dots.forEach(i => {
            const dot = $(`dot${i}`);
            dot.classList.remove('active', 'done');
            if (i < n) dot.classList.add('done');
            else if (i === n) dot.classList.add('active');
        });
    }

    function updateRing(pct, label) {
        const circumference = 263.9;
        const offset = circumference - (pct / 100) * circumference;
        $('ringFill').style.strokeDashoffset = offset;
        $('ringPercent').textContent = pct + '%';
        if (label) $('progressLabel').innerHTML = label;
    }

    // ===== GESTION DES CLÉS API =====
    async function apiCall(action, body = {}) {
        const res = await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...body })
        });
        return res.json();
    }

    async function loadKeys() {
        try {
            const data = await apiCall('get_keys');
            renderKeys(data.keys || []);
            return data.keys || [];
        } catch (e) { return []; }
    }

    function renderKeys(keys) {
        const list = $('keysList');
        if (!list) return;
        list.innerHTML = keys.length === 0
            ? `<div style="font-size:12px; color:var(--text-muted); font-family:var(--font-mono); padding:8px">Aucune clé. Ajoutez-en une ci-dessus.</div>`
            : keys.map(k => `
                <div class="key-item">
                    <div class="key-dot"></div>
                    <div class="key-value">${k.slice(0, 8)}••••${k.slice(-6)}</div>
                    <button class="key-del" onclick="deleteKey('${k}')" title="Supprimer">✕</button>
                </div>`).join('');
    }

    async function addKey() {
        const key = $('apiKeyInput').value.trim();
        const statusEl = $('keyStatus');
        if (!key) return;

        statusEl.innerHTML = `<div class="status-msg">⏳ Test en cours…</div>`;
        const data = await apiCall('save_key', { key });

        if (data.success) {
            statusEl.innerHTML = `<div class="status-msg success">✓ ${data.message}</div>`;
            $('apiKeyInput').value = '';
            loadKeys();
        } else {
            statusEl.innerHTML = `<div class="status-msg error">✕ ${data.message}</div>`;
        }
    }

    async function deleteKey(key) {
        await apiCall('delete_key', { key });
        loadKeys();
    }

    // Charger les clés au démarrage
    loadKeys();

    // ===== MOTEUR IA - RETRY INFINI =====
    async function callMistral(prompt, isJson = false, maxTokens = 70000, timeoutMs = 240000) {
        let keys = await loadKeys();
        // Filtrer les clés invalides ou placeholder
        keys = keys.filter(k => k && k.length > 20 && !k.includes('votre_cle') && !k.includes('api key'));
        if (keys.length === 0) throw new Error("Aucune clé API valide. Ajoutez une vraie clé Mistral dans le panneau de configuration (en bas de page).");

        let attempt = 0;
        let keyIndex = 0;

        while (true) {
            attempt++;
            const key = keys[keyIndex % keys.length];

            if (attempt > 1) {
                $('retryBanner').classList.add('show');
                $('retryMsg').textContent = `Tentative ${attempt} (clé …${key.slice(-6)})`;
                await sleep(1500);
                // Recharger les clés à chaque nouveau tour complet
                if (keyIndex % keys.length === 0) keys = await loadKeys();
            }

            try {
                const body = {
 problème-création-application-d8bc8
                    model: 'pixtral-12b-2409', // Modèle unique - jamais d'autre
=======
                    model: 'pixtral-12b-2409', // Modèle optimisé pour le code
main
                    messages: [{ role: 'user', content: prompt }],
                    max_tokens: maxTokens,
                    temperature: isJson ? 0.2 : 0.7
                };

                // Création d'un controller pour le timeout
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

                log('log3', `Appel API avec clé …${key.slice(-6)} (timeout ${timeoutMs/1000}s, ${maxTokens} tokens)…`, 'info');

                const res = await fetch('https://api.mistral.ai/v1/chat/completions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${key}`
                    },
                    body: JSON.stringify(body),
                    signal: controller.signal
                });

                clearTimeout(timeoutId);

                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    const errorMsg = err.message || `HTTP ${res.status}`;
                    log('log3', `Erreur API: ${errorMsg}`, 'error');
                    throw new Error(errorMsg);
                }

                const data = await res.json();
                let content = data.choices?.[0]?.message?.content;
                if (!content) throw new Error("Réponse vide de l'API");

                if (isJson) {
                    // Nettoyage robuste du JSON
                    content = content
                        .replace(/^```json\s*/i, '')
                        .replace(/^```\s*/i, '')
                        .replace(/\s*```$/i, '')
                        .trim();

                    // Tentative de réparation si JSON tronqué
                    if (!content.endsWith('}') && !content.endsWith(']')) {
                        const lastBrace = Math.max(content.lastIndexOf('}'), content.lastIndexOf(']'));
                        if (lastBrace !== -1) content = content.substring(0, lastBrace + 1);
                    }
                    JSON.parse(content); // Valider le JSON
                }

                $('retryBanner') && $('retryBanner').classList.remove('show');
                return content;

            } catch (e) {
                console.warn(`[Attempt ${attempt}] Key …${key.slice(-6)}: ${e.message}`);
                // Gestion explicite du timeout
                if (e.name === 'AbortError' || e.message.includes('timeout')) {
                    log('log3', `⏱️ Timeout après ${timeoutMs/1000}s pour la clé …${key.slice(-6)}`, 'warn');
                }
                keyIndex++;
                // Si on a fait le tour de toutes les clés, afficher un message clair
                if (keyIndex >= keys.length && attempt > 1) {
                    log('log3', `Toutes les clés ont échoué. Vérifiez vos clés API ou ajoutez-en une nouvelle.`, 'error');
                    throw new Error("Échec après plusieurs tentatives avec toutes les clés disponibles.");
                }
            }
        }
    }

    // ===== ÉTAPE 1 → 2 : Génération des concepts =====
    async function startGeneration() {
        State.idea = $('userIdea').value.trim();
        State.tiktokUser = $('tiktokUser').value.trim().replace('@', '');

        if (!State.idea) {
            $('userIdea').focus();
            $('userIdea').style.borderColor = 'var(--error)';
            setTimeout(() => $('userIdea').style.borderColor = '', 2000);
            return;
        }

        showStep(2);
        log('log2', 'Chargement des clés API…', 'info');

        const keys = await loadKeys();
        log('log2', `${keys.length} clé(s) chargée(s).`, 'success');
        log('log2', `Analyse de votre idée : "${State.idea.substring(0, 60)}…"`, 'info');
        await sleep(600);
        log('log2', 'Génération de 5 concepts viraux en cours…');

        const prompt = `Tu es un expert en applications virales pour les réseaux sociaux.
Idée utilisateur : "${State.idea}"

Génère EXACTEMENT 5 concepts d'applications web uniques, amusantes et virales basées sur cette idée.
Réponds UNIQUEMENT avec un JSON valide, sans texte avant ni après, sans backticks.
Format : {"concepts": [{"emoji": "🎮", "title": "Titre court", "desc": "Une phrase d'accroche."}, ...]}`;

        try {
            const raw = await callMistral(prompt, true, 1000);
            const data = JSON.parse(raw);
            const concepts = data.concepts;

            if (!Array.isArray(concepts) || concepts.length === 0) throw new Error("Aucun concept retourné");

            log('log2', `✓ ${concepts.length} concepts générés.`, 'success');
            await sleep(400);

            $('loader2').style.display = 'none';
            $('conceptsList').style.display = 'block';

            const grid = $('optionsGrid');
            grid.innerHTML = '';
            concepts.forEach(c => {
                const card = document.createElement('div');
                card.className = 'concept-card';
                card.innerHTML = `
                    <span class="concept-emoji">${c.emoji || '✨'}</span>
                    <div class="concept-title">${c.title}</div>
                    <div class="concept-desc">${c.desc}</div>`;
                card.onclick = () => selectConcept(c.title, c.emoji || '✨');
                grid.appendChild(card);
            });

        } catch (e) {
            log('log2', `Erreur : ${e.message}`, 'error');
        }
    }

    // ===== ÉTAPE 2 → 3 : Génération du code =====
    function selectConcept(title, emoji) {
        State.concept = title;
        State.appName = title.toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '')
            .substring(0, 35);
        showStep(3);
        generateCode();
    }

    async function generateCode() {
        const maxTokens = 70000; // Tokens maximum pour code complet
        updateRing(0, 'Initialisation…');
        await sleep(300);
        log('log3', `Concept sélectionné : "${State.concept}"`, 'info');
        updateRing(10, 'Préparation du prompt…');
        await sleep(500);
        log('log3', 'Construction du prompt de génération…');
        updateRing(20, 'Appel API Mistral…');

        const prompt = `Tu es un développeur web expert. Génère une application web COMPLÈTE et FONCTIONNELLE.

Projet : "${State.idea}"
Concept : "${State.concept}"

EXIGENCES ABSOLUES :
1. Application mobile-first, moderne et esthétique (dark theme recommandé)
2. HTML5 + CSS3 + JavaScript vanilla — tout dans un seul fichier index.html
3. Interactivité réelle : si c'est un jeu, il doit être jouable. Si c'est un quiz, il doit fonctionner.
4. Footer fixe en bas : "Créé avec ❤️ par @${State.tiktokUser}" avec lien vers "https://www.tiktok.com/@${State.tiktokUser}"
5. Police Google Fonts (pas de Arial/system font)
6. Animations CSS au minimum pour les interactions principales

RÉPONDS UNIQUEMENT avec un JSON valide, sans texte avant ni après, sans backticks markdown.
Format strict :
{"files": {"index.html": "CODE HTML COMPLET ICI"}}

Le fichier index.html doit être COMPLET (doctype, head avec styles, body avec contenu, scripts).`;

        try {
            log('log3', `Génération du code source (peut prendre 120-240s)…`, 'warn');
            updateRing(30, `<strong>Génération en cours…</strong><br>pixtral-12b-2409 (timeout 240s, ${maxTokens} tokens)`);

            // Simuler progression pendant le chargement
            let fakeProgress = 30;
            const progressInterval = setInterval(() => {
                if (fakeProgress < 80) {
                    fakeProgress += Math.random() * 2;
                    updateRing(Math.round(fakeProgress), `<strong>Génération en cours…</strong><br>${Math.round(fakeProgress)}%`);
                }
            }, 4000);

            const raw = await callMistral(prompt, true, maxTokens, 240000);
            clearInterval(progressInterval);

            updateRing(85, 'Validation du code…');
            const data = JSON.parse(raw);

            if (!data.files || !data.files['index.html']) {
                throw new Error("Le modèle n'a pas retourné de fichier index.html valide.");
            }

            log('log3', '✓ Code généré et validé.', 'success');
            updateRing(90, 'Déploiement sur bingo.voanh.art…');
            log('log3', 'Déploiement en cours…', 'warn');

            const deployRes = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'deploy_app',
                    appName: State.appName,
                    files: data.files,
                    tiktokUser: State.tiktokUser
                })
            });
            const result = await deployRes.json();

            if (result.success) {
                updateRing(100, '✓ Déployé avec succès !');
                log('log3', `✓ App déployée : ${result.url}`, 'success');
                await sleep(1200);
                finishDeployment(result);
            } else {
                throw new Error(result.message || 'Erreur de déploiement');
            }

        } catch (e) {
            updateRing(0, `<span style="color:var(--error)">Erreur : ${e.message}</span>`);
            log('log3', `Erreur : ${e.message}`, 'error');
            log('log3', 'Nouvelle tentative automatique dans 2s…', 'warn');
            await sleep(2000);
            generateCode(); // Retry automatique
        }
    }

    // ===== ÉTAPE 4 : Succès =====
    function finishDeployment(result) {
        showStep(4);

        // Liste des fichiers
        $('fileList').innerHTML = result.files
            .map(f => `<div class="file-item"><span>📄</span><span>${f}</span><span style="margin-left:auto;color:var(--text-muted);font-size:11px">déployé</span></div>`)
            .join('');

        // URL
        $('appUrl').textContent = result.url;
        $('launchBtn').href = result.url;

        // TikTok
        const tiktokUrl = `https://www.tiktok.com/@${State.tiktokUser}`;
        $('tiktokLink').href = tiktokUrl;
        $('tiktokHandle').textContent = `@${State.tiktokUser}`;
    }

    function copyUrl() {
        const url = $('appUrl').textContent;
        navigator.clipboard.writeText(url).then(() => {
            $('copyBtnLabel').textContent = '✓ Copié !';
            setTimeout(() => $('copyBtnLabel').textContent = 'Copier', 2000);
        });
    }

    // Permettre d'ajouter la clé avec Entrée
    $('apiKeyInput')?.addEventListener('keydown', e => { if (e.key === 'Enter') addKey(); });
</script>

</body>
</html>
