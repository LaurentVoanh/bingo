<?php
session_start();

// CONFIGURATION
$DEBUG_MODE = 1; // 1 = Actif (interface de gestion des clés), 0 = Production
$dbFilename = 'APIKEYMISTRAL.sqlite';
$dbPath = __DIR__ . DIRECTORY_SEPARATOR . $dbFilename;
$appsDir = __DIR__ . DIRECTORY_SEPARATOR . 'apps';

// 1. INITIALISATION SÉCURISÉE DE LA BASE DE DONNÉES
try {
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // On vérifie si la table existe, sinon on la crée proprement
    // Cette méthode évite les erreurs "no column named..." sur les anciennes BDD
    $tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='api_keys'")->fetch();
    
    if (!$tableCheck) {
        $db->exec("CREATE TABLE api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key TEXT UNIQUE NOT NULL,
            status TEXT DEFAULT 'active',
            tested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        // Vérification rapide de l'intégrité des colonnes (optionnel mais sûr)
        // Si la colonne last_used manque, on recrée la table proprement (migration brute pour simplifier)
        $cols = $db->query("PRAGMA table_info(api_keys)")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('last_used', $cols)) {
            $db->exec("DROP TABLE api_keys");
            $db->exec("CREATE TABLE api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT UNIQUE NOT NULL,
                status TEXT DEFAULT 'active',
                tested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_used DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        }
    }
} catch (PDOException $e) {
    die("Erreur Critique DB : " . $e->getMessage() . "<br>Vérifiez les permissions d'écriture dans le dossier : " . __DIR__);
}

// 2. GESTION DES REQUÊTES AJAX (BACKEND)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // ACTION: Tester et Sauvegarder une clé
    if ($_POST['action'] === 'test_key') {
        $key = trim($_POST['key'] ?? '');
        if (empty($key)) {
            echo json_encode(['success' => false, 'message' => 'Clé vide']);
            exit;
        }

        $isValid = false;
        $errorMsg = "";

        // Test API Mistral
        $url = "https://api.mistral.ai/v1/chat/completions";
        $data = [
            "model" => "mistral-large-latest",
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $isValid = true;
        } else {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['message'] ?? "Erreur HTTP $httpCode";
        }

        if ($isValid) {
            try {
                // INSERT OR REPLACE pour mettre à jour si existe déjà
                $stmt = $db->prepare("INSERT OR REPLACE INTO api_keys (key, status, tested_at, last_used) VALUES (:key, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $stmt->execute([':key' => $key]);
                echo json_encode(['success' => true, 'message' => 'Clé valide et enregistrée en base SQLite !']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Erreur DB: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => "Échec test: $errorMsg"]);
        }
        exit;
    }

    // ACTION: Récupérer toutes les clés actives
    if ($_POST['action'] === 'get_keys') {
        $stmt = $db->query("SELECT key FROM api_keys WHERE status='active'");
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['keys' => $keys, 'count' => count($keys)]);
        exit;
    }

    // ACTION: Mettre à jour la date d'utilisation (pour le round-robin)
    if ($_POST['action'] === 'touch_key') {
        $key = trim($_POST['key'] ?? '');
        if ($key) {
            $stmt = $db->prepare("UPDATE api_keys SET last_used = CURRENT_TIMESTAMP WHERE key = :key");
            $stmt->execute([':key' => $key]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ACTION: Créer l'application (Écriture des fichiers sur le disque)
    if ($_POST['action'] === 'create_app_files') {
        $appNameRaw = $_POST['appName'] ?? 'mon_app';
        $filesJson = $_POST['files'] ?? '{}';
        
        // Nettoyage du nom de l'app (sécurité)
        $safeAppName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $appNameRaw);
        if (empty($safeAppName)) $safeAppName = 'app_' . time();
        
        $targetDir = $appsDir . DIRECTORY_SEPARATOR . $safeAppName;

        // Création du dossier apps si inexistant
        if (!is_dir($appsDir)) {
            if (!mkdir($appsDir, 0777, true)) {
                echo json_encode(['success' => false, 'message' => "Impossible de créer le dossier racine 'apps'. Vérifiez les permissions (chmod 777)."]);
                exit;
            }
        }

        // Gestion de l'écrasement si le dossier existe déjà
        if (is_dir($targetDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) rmdir($file->getPathname());
                else unlink($file->getPathname());
            }
            rmdir($targetDir);
        }

        if (!mkdir($targetDir, 0777, true)) {
            echo json_encode(['success' => false, 'message' => "Impossible de créer le dossier de l'application."]);
            exit;
        }

        $files = json_decode($filesJson, true);
        $createdFiles = [];
        $errors = [];

        if (!is_array($files)) {
            echo json_encode(['success' => false, 'message' => "Format de fichiers invalide."]);
            exit;
        }

        foreach ($files as $filename => $content) {
            // Sécurité : basename empêche les path traversal (ex: ../../etc/passwd)
            $cleanFilename = basename($filename);
            
            // On ignore les noms vides
            if (empty($cleanFilename)) continue;

            $filePath = $targetDir . DIRECTORY_SEPARATOR . $cleanFilename;
            
            // Décodage HTML entities si nécessaire (l'IA peut encoder les quotes)
            $finalContent = htmlspecialchars_decode($content);

            if (file_put_contents($filePath, $finalContent) !== false) {
                $createdFiles[] = $cleanFilename;
            } else {
                $errors[] = "Échec écriture: $cleanFilename";
            }
        }

        if (!empty($errors)) {
            echo json_encode(['success' => false, 'message' => implode(", ", $errors)]);
        } else {
            // Création d'un point d'entrée principal pour faciliter l'accès
            $launcherPath = $appsDir . DIRECTORY_SEPARATOR . $safeAppName . '.php';
            $launcherContent = "<?php\n// Redirection vers l'index généré\nheader('Location: /apps/$safeAppName/index.html');\nexit;\n?>";
            file_put_contents($launcherPath, $launcherContent);

            echo json_encode([
                'success' => true, 
                'message' => 'Application déployée avec succès !',
                'url' => 'apps/' . $safeAppName . '/index.html',
                'files' => $createdFiles
            ]);
        }
        exit;
    }
    
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Générateur d'App IA - Multi-Keys & Déploiement</title>
    <style>
        :root {
            --primary: #6366f1;
            --secondary: #a855f7;
            --accent: #ec4899;
            --success: #22c55e;
            --dark: #0f172a;
            --light: #f8fafc;
            --glass: rgba(255, 255, 255, 0.05);
            --border: rgba(255, 255, 255, 0.1);
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: radial-gradient(circle at top right, #1e1b4b, #0f172a);
            color: var(--light);
            min-height: 100vh;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 900px;
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
            position: relative;
            overflow: hidden;
        }

        h1 { 
            text-align: center; 
            background: linear-gradient(to right, #818cf8, #d8b4fe, #f472b6); 
            -webkit-background-clip: text; 
            color: transparent; 
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        p.subtitle { text-align: center; color: #94a3b8; margin-bottom: 2rem; }

        .step { display: none; animation: fadeIn 0.5s ease-out; }
        .step.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        /* Inputs & Buttons */
        textarea, input[type="text"] {
            width: 100%;
            padding: 15px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: rgba(0,0,0,0.3);
            color: white;
            font-size: 1rem;
            box-sizing: border-box;
            font-family: inherit;
            margin-bottom: 1rem;
        }
        textarea:focus, input:focus { outline: 2px solid var(--primary); border-color: transparent; }

        button {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        button:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(99, 102, 241, 0.4); }
        button:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        /* Debug Panel */
        .debug-panel {
            margin-top: 2rem;
            padding: 1.5rem;
            border: 1px dashed var(--border);
            border-radius: 16px;
            background: rgba(0,0,0,0.2);
        }
        .debug-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .badge { background: var(--primary); padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; text-transform: uppercase; }
        .key-status { font-size: 0.9rem; margin-top: 0.5rem; color: #cbd5e1; }

        /* Loaders & Logs */
        .loader-container { text-align: center; padding: 3rem 1rem; }
        .spinner {
            width: 50px; height: 50px;
            border: 4px solid rgba(255,255,255,0.1);
            border-top: 4px solid var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
        }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        .terminal {
            background: #020617;
            color: #4ade80;
            font-family: 'Courier New', monospace;
            padding: 1.5rem;
            border-radius: 12px;
            height: 250px;
            overflow-y: auto;
            text-align: left;
            font-size: 0.85rem;
            border: 1px solid #1e293b;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.5);
        }
        .log-entry { margin-bottom: 5px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 2px; }
        .log-error { color: #f87171; }
        .log-warn { color: #fbbf24; }
        .log-info { color: #94a3b8; }

        /* Options Grid */
        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 1.5rem;
        }
        .option-card {
            background: rgba(255,255,255,0.05);
            padding: 1.5rem;
            border-radius: 12px;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.3s;
            text-align: center;
        }
        .option-card:hover { border-color: var(--accent); background: rgba(236, 72, 153, 0.1); transform: translateY(-3px); }

        /* Success Screen */
        .success-box {
            text-align: center;
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid var(--success);
            padding: 2rem;
            border-radius: 16px;
            margin-top: 1rem;
        }
        .btn-launch {
            display: inline-block;
            margin-top: 1.5rem;
            padding: 15px 30px;
            background: var(--success);
            color: #020617;
            text-decoration: none;
            font-weight: bold;
            border-radius: 12px;
            transition: transform 0.2s;
        }
        .btn-launch:hover { transform: scale(1.05); }
        .file-list { text-align: left; background: rgba(0,0,0,0.3); padding: 1rem; border-radius: 8px; margin-top: 1rem; font-family: monospace; font-size: 0.9rem; }
    </style>
</head>
<body>

<div class="container">
    
    <!-- ÉTAPE 1 : Formulaire & Gestion Clés -->
    <div id="step1" class="step active">
        <h1>🚀 Créateur d'Applications IA</h1>
        <p class="subtitle">Décrivez votre projet. L'IA concevra l'architecture, écrira le code et déploiera l'application.</p>
        
        <form id="projectForm">
            <label for="userDemande" style="display:block; margin-bottom:8px; font-weight:600;">Votre Projet :</label>
            <textarea id="userDemande" rows="5" placeholder="Ex: Un réseau social pour échanger des plantes contre des recettes de cuisine, avec géolocalisation et chat intégré..." required></textarea>
            <button type="submit">Lancer la Génération Intelligente ✨</button>
        </form>

        <?php if ($DEBUG_MODE): ?>
        <div class="debug-panel">
            <div class="debug-header">
                <h3>🛠️ Mode Debug : Gestion Multi-Clés</h3>
                <span class="badge">SQLite Active</span>
            </div>
            <p style="font-size:0.9rem; color:#cbd5e1; margin-top:0;">
                Ajoutez plusieurs clés API Mistral. Le système les testera, les stockera en base SQLite, et basculera automatiquement sur une autre clé en cas d'erreur ou de limite atteinte.
            </p>
            <div style="display:flex; gap:10px; margin-top:10px;">
                <input type="text" id="apiKeyInput" placeholder="sk-..." style="margin:0; flex:1;">
                <button onclick="testAndSaveKey()" style="width:auto; margin:0; padding: 0 20px;">Tester & Sauvegarder</button>
            </div>
            <div id="debugStatus" class="key-status"></div>
            <div style="margin-top:10px; font-size:0.85rem; color:#94a3b8;">
                Clés disponibles en base : <strong id="keyCount" style="color:white;">0</strong>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ÉTAPE 2 : Sélection du Concept -->
    <div id="step2" class="step">
        <div id="loader2" class="loader-container">
            <div class="spinner"></div>
            <h2>Analyse & Idéation</h2>
            <div class="terminal" id="log2"></div>
        </div>
        
        <div id="optionsResult" style="display:none;">
            <h2 style="text-align:center;">💡 10 Concepts Innovants</h2>
            <p style="text-align:center; color:#94a3b8;">Choisissez la direction la plus pertinente :</p>
            <div class="options-grid" id="optionsGrid"></div>
        </div>
    </div>

    <!-- ÉTAPE 3 : Génération du Code -->
    <div id="step3" class="step">
        <div class="loader-container">
            <div class="spinner"></div>
            <h2>Architecture & Codage</h2>
            <div class="terminal" id="log3"></div>
        </div>
    </div>

    <!-- ÉTAPE 4 : Déploiement -->
    <div id="step4" class="step">
        <div id="loader4" class="loader-container">
            <div class="spinner"></div>
            <h2>Déploiement des Fichiers</h2>
            <div class="terminal" id="log4"></div>
        </div>

        <div id="finalSuccess" style="display:none;">
            <div class="success-box">
                <h2 style="color:var(--success); margin-top:0;">✅ Application Déployée !</h2>
                <p>Votre application a été générée, compilée et enregistrée sur le serveur.</p>
                
                <div class="file-list" id="fileList"></div>
                
                <a href="#" id="launchLink" class="btn-launch">🚀 Ouvrir mon Application</a>
                <br><br>
                <button onclick="location.reload()" style="background:transparent; border:1px solid var(--border); width:auto; padding:10px 20px; font-size:0.9rem;">Créer un autre projet</button>
            </div>
        </div>
    </div>

</div>

<script>
    // État Global
    let userDemande = "";
    let selectedOption = "";
    let appSlug = "";
    const DEBUG_MODE = <?php echo $DEBUG_MODE ? 'true' : 'false'; ?>;

    // Utilitaires
    const log = (elementId, message, type = 'info') => {
        const el = document.getElementById(elementId);
        const time = new Date().toLocaleTimeString();
        const colorClass = type === 'error' ? 'log-error' : (type === 'warn' ? 'log-warn' : (type === 'success' ? 'log-success' : 'log-info'));
        el.innerHTML += `<div class="log-entry ${colorClass}">[${time}] ${message}</div>`;
        el.scrollTop = el.scrollHeight;
    };

    const showStep = (id) => {
        document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
        document.getElementById(id).classList.add('active');
    };

    const sleep = (ms) => new Promise(r => setTimeout(r, ms));

    // --- GESTION CLÉS API (DEBUG) ---
    async function refreshKeyCount() {
        if (!DEBUG_MODE) return;
        try {
            const fd = new FormData(); fd.append('action', 'get_keys');
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            document.getElementById('keyCount').innerText = data.count || 0;
        } catch(e) {}
    }
    refreshKeyCount();

    async function testAndSaveKey() {
        const key = document.getElementById('apiKeyInput').value.trim();
        const statusEl = document.getElementById('debugStatus');
        if (!key) return alert("Entrez une clé valide");

        statusEl.innerHTML = "<span style='color:#fbbf24'>Test en cours avec mistral-large...</span>";
        
        try {
            const fd = new FormData();
            fd.append('action', 'test_key');
            fd.append('key', key);

            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                statusEl.innerHTML = "<span style='color:#4ade80'>✅ " + data.message + "</span>";
                document.getElementById('apiKeyInput').value = '';
                refreshKeyCount();
            } else {
                statusEl.innerHTML = "<span style='color:#f87171'>❌ " + data.message + "</span>";
            }
        } catch (e) {
            statusEl.innerHTML = "<span style='color:#f87171'>❌ Erreur réseau</span>";
        }
    }

    async function getApiKeys() {
        if (!DEBUG_MODE) return [];
        try {
            const fd = new FormData(); fd.append('action', 'get_keys');
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            return data.keys || [];
        } catch (e) { return []; }
    }

    // --- MOTEUR API AVEC ROTATION AUTOMATIQUE ---
    async function callMistralRotative(prompt, maxTokens = 2000, isJson = false) {
        let keys = await getApiKeys();
        if (keys.length === 0) throw new Error("Aucune clé API trouvée dans la base SQLite. Ajoutez-en en mode Debug.");

        let lastError = null;
        
        // On essaie chaque clé jusqu'à succès
        for (const key of keys) {
            try {
                // Log discret pour le débogage console
                console.log(`Tentative avec clé ...${key.slice(-5)}`);

                const url = "https://api.mistral.ai/v1/chat/completions";
                const payload = {
                    model: "mistral-large-latest",
                    messages: [{ role: "user", content: prompt }],
                    temperature: isJson ? 0.2 : 0.7,
                    max_tokens: maxTokens
                };

                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${key}`
                    },
                    body: JSON.stringify(payload)
                });

                if (!res.ok) {
                    const errData = await res.json();
                    throw new Error(errData.message || `HTTP ${res.status}`);
                }

                const data = await res.json();
                let content = data.choices[0].message.content;

                // Mise à jour last_used (fire and forget)
                const fdTouch = new FormData(); fdTouch.append('action', 'touch_key'); fdTouch.append('key', key);
                fetch('index.php', { method: 'POST', body: fdTouch });

                if (isJson) {
                    // Nettoyage robuste du JSON
                    const start = content.indexOf('{');
                    const end = content.lastIndexOf('}');
                    if (start === -1 || end === -1) throw new Error("Réponse JSON invalide");
                    content = content.substring(start, end + 1);
                    JSON.parse(content); // Validation
                    return content;
                }
                return content;

            } catch (err) {
                lastError = err;
                console.warn(`Clé échouée: ${err.message}. Tentative suivante...`);
                // Continue loop to next key
            }
        }

        throw new Error(`Échec total. Toutes les clés ont échoué. Dernière erreur: ${lastError ? lastError.message : 'Inconnue'}`);
    }

    // --- FLUX PRINCIPAL ---

    // 1. Soumission du projet
    document.getElementById('projectForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        userDemande = document.getElementById('userDemande').value;
        if (!userDemande) return;

        showStep('step2');
        const logEl = 'log2';
        log(logEl, "Initialisation du cerveau IA...", 'info');
        
        try {
            const keys = await getApiKeys();
            log(logEl, `${keys.length} clés API chargées depuis SQLite.`, 'success');
            await sleep(800);
            
            log(logEl, "Analyse de la demande et recherche de concepts viraux...", 'info');
            
            const prompt = `
            Projet: "${userDemande}".
            Génère 10 idées d'applications web innovantes, optimisées mobile, avec potentiel viral et monétisation claire.
            
            RÉPOND UNIQUEMENT EN JSON PUR (pas de markdown, pas de texte autour) :
            {
                "options": [
                    {"title": "Nom court", "desc": "Description en 1 phrase"},
                    ... 10 items
                ]
            }
            `;

            const rawData = await callMistralRotative(prompt, 1500, true);
            const data = JSON.parse(rawData);

            log(logEl, "10 concepts générés avec succès.", 'success');
            await sleep(1000);

            document.getElementById('loader2').style.display = 'none';
            const grid = document.getElementById('optionsGrid');
            grid.innerHTML = '';

            data.options.forEach((opt, i) => {
                const card = document.createElement('div');
                card.className = 'option-card';
                card.innerHTML = `<strong>${i+1}. ${opt.title}</strong><br><small style="color:#94a3b8">${opt.desc}</small>`;
                card.onclick = () => selectOption(opt.title);
                grid.appendChild(card);
            });
            document.getElementById('optionsResult').style.display = 'block';

        } catch (err) {
            log(logEl, `ERREUR: ${err.message}`, 'error');
            log(logEl, "Vérifiez vos clés API ou réessayez.", 'warn');
        }
    });

    function selectOption(title) {
        selectedOption = title;
        appSlug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'mon-app';
        if (appSlug.length < 3) appSlug = 'app-' + Date.now();
        
        showStep('step3');
        generateCode();
    }

    // 2. Génération du code complet
    async function generateCode() {
        const logEl = 'log3';
        log(logEl, "Lancement de l'architecte logiciel...", 'info');
        await sleep(1000);
        log(logEl, `Conception de l'architecture pour : ${selectedOption}`, 'info');
        await sleep(1000);
        log(logEl, "Rédaction du code source (HTML/CSS/JS/PHP)...", 'info');

        const prompt = `
        Contexte: Projet "${userDemande}". Option choisie: "${selectedOption}".
        
        TÂCHE: Tu es un développeur Fullstack Expert. Génère le CODE SOURCE COMPLET d'une application web fonctionnelle.
        L'application doit être moderne, responsive, esthétique et fonctionnelle.
        
        STRUCTURE REQUISE:
        Tu dois répondre UNIQUEMENT par un objet JSON valide contenant les fichiers.
        Format JSON:
        {
            "files": {
                "index.html": "<code html complet avec CSS/JS inclus ou lié>",
                "style.css": "<code css si séparé>",
                "script.js": "<code js si séparé>",
                "backend.php": "<code php si besoin d'un backend simple>",
                "schema.sql": "<structure SQL si besoin>"
            }
        }
        
        CONTRAINTES:
        1. Le code doit être prêt à l'emploi.
        2. Utilise des designs modernes (dégradés, glassmorphism).
        3. Gère les erreurs basiques en JS.
        4. Pas de texte explicatif avant ou après le JSON.
        `;

        try {
            const rawData = await callMistralRotative(prompt, 4000, true); // Beaucoup de tokens pour le code
            const data = JSON.parse(rawData);
            
            log(logEl, "Code source généré et validé.", 'success');
            log(logEl, "Préparation du déploiement...", 'warn');
            
            await sleep(1000);
            showStep('step4');
            deployApp(data.files);

        } catch (err) {
            log(logEl, `ERREUR GÉNÉRATION: ${err.message}`, 'error');
        }
    }

    // 3. Déploiement physique des fichiers
    async function deployApp(files) {
        const logEl = 'log4';
        log(logEl, "Création du dossier de l'application...", 'info');
        await sleep(800);

        try {
            const fd = new FormData();
            fd.append('action', 'create_app_files');
            fd.append('appName', appSlug);
            fd.append('files', JSON.stringify(files));

            const res = await fetch('index.php', { method: 'POST', body: fd });
            const result = await res.json();

            if (result.success) {
                log(logEl, "Fichiers écrits sur le disque avec succès.", 'success');
                await sleep(1000);
                log(logEl, "Configuration finale terminée.", 'success');

                document.getElementById('loader4').style.display = 'none';
                document.getElementById('finalSuccess').style.display = 'block';

                // Affichage liste fichiers
                const listEl = document.getElementById('fileList');
                let html = '<strong>Fichiers créés :</strong><ul style="padding-left:20px; margin:5px 0;">';
                result.files.forEach(f => html += `<li>${f}</li>`);
                html += '</ul>';
                listEl.innerHTML = html;

                // Lien
                document.getElementById('launchLink').href = result.url;
            } else {
                throw new Error(result.message);
            }
        } catch (err) {
            log(logEl, `ERREUR DÉPLOIEMENT: ${err.message}`, 'error');
        }
    }
</script>

</body>
</html>
