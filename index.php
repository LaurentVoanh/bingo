<?php
session_start();

// Configuration
$DEBUG_MODE = 1; 
$dbFile = __DIR__ . '/APIKEYMISTRAL.sqlite';
$appsDir = __DIR__ . '/apps';

// 1. Initialisation ROBUSTE de la SQLite (Sans effacer les données !)
try {
    $db = new PDO("sqlite:$dbFile");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // On vérifie si la table existe, sinon on la crée. ON NE LA SUPPRIME JAMAIS.
    $query = "CREATE TABLE IF NOT EXISTS api_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key TEXT UNIQUE NOT NULL,
        status TEXT DEFAULT 'active',
        tested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_used DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    $db->exec($query);
    
    // Création du dossier apps s'il n'existe pas
    if (!file_exists($appsDir)) {
        mkdir($appsDir, 0777, true);
    }

} catch (PDOException $e) {
    die("Erreur Critique Base de données : " . $e->getMessage());
}

// --- BACKEND AJAX HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Action: Tester et Sauvegarder une clé
    if ($_POST['action'] === 'test_key') {
        $key = trim($_POST['key'] ?? '');
        if (empty($key)) {
            echo json_encode(['success' => false, 'message' => 'Clé vide']);
            exit;
        }

        $isValid = false;
        $errorMsg = "";

        // Test rapide avec mistral-large-latest
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
                // INSERT OR REPLACE permet de mettre à jour la date si la clé existe déjà
                $stmt = $db->prepare("INSERT OR REPLACE INTO api_keys (key, status, tested_at, last_used) VALUES (:key, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $stmt->execute([':key' => $key]);
                echo json_encode(['success' => true, 'message' => 'Clé valide et enregistrée durablement !']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Erreur DB: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => "Échec test: $errorMsg"]);
        }
        exit;
    }

    // Action: Récupérer les clés actives
    if ($_POST['action'] === 'get_keys') {
        // Vérification explicite que la table existe (cas rare de corruption)
        try {
            $stmt = $db->query("SELECT key FROM api_keys WHERE status='active' ORDER BY last_used ASC");
            $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['keys' => $keys, 'count' => count($keys)]);
        } catch (Exception $e) {
            echo json_encode(['keys' => [], 'count' => 0, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // Action: Mettre à jour la date d'utilisation (pour le round-robin)
    if ($_POST['action'] === 'touch_key') {
        $key = trim($_POST['key'] ?? '');
        if(!empty($key)){
            $stmt = $db->prepare("UPDATE api_keys SET last_used = CURRENT_TIMESTAMP WHERE key = :key");
            $stmt->execute([':key' => $key]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // Action: Générer l'application (Création des fichiers)
    if ($_POST['action'] === 'create_app_files') {
        $appName = $_POST['appName'] ?? 'mon_app';
        $codeFiles = $_POST['files'] ?? []; 
        
        $safeAppName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $appName);
        $appDir = $appsDir . '/' . $safeAppName;

        // Gestion du dossier existant
        if (file_exists($appDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($appDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) rmdir($file->getPathname());
                else unlink($file->getPathname());
            }
        } else {
            if (!mkdir($appDir, 0777, true)) {
                echo json_encode(['success' => false, 'message' => "Impossible de créer le dossier"]);
                exit;
            }
        }

        $createdFiles = [];
        try {
            foreach ($codeFiles as $filename => $content) {
                $cleanFilename = basename($filename);
                $filePath = $appDir . '/' . $cleanFilename;
                
                if (file_put_contents($filePath, $content) !== false) {
                    $createdFiles[] = $cleanFilename;
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Application déployée !',
                'url' => 'apps/' . $safeAppName . '/index.html',
                'files' => $createdFiles
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
    <title>Générateur d'App IA - Persistent DB</title>
    <style>
        :root { --primary: #6366f1; --secondary: #a855f7; --accent: #ec4899; --dark: #0f172a; --light: #f8fafc; --glass: rgba(255, 255, 255, 0.08); --border: rgba(255, 255, 255, 0.15); }
        body { font-family: 'Inter', system-ui, sans-serif; background: radial-gradient(circle at top left, #1e1b4b, #0f172a); color: var(--light); min-height: 100vh; margin: 0; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .container { width: 100%; max-width: 900px; background: var(--glass); backdrop-filter: blur(16px); border: 1px solid var(--border); border-radius: 24px; padding: 2.5rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6); position: relative; overflow: hidden; }
        h1 { font-size: 2.5rem; text-align: center; background: linear-gradient(to right, #818cf8, #d8b4fe, #f472b6); -webkit-background-clip: text; color: transparent; margin-bottom: 10px; }
        p.subtitle { text-align: center; opacity: 0.7; margin-bottom: 30px; }
        .step { display: none; animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
        .step.active { display: block; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #cbd5e1; }
        input[type="text"], textarea { width: 100%; padding: 16px; border-radius: 12px; border: 1px solid var(--border); background: rgba(0,0,0,0.4); color: white; font-size: 1rem; box-sizing: border-box; transition: border-color 0.3s; font-family: inherit; }
        input:focus, textarea:focus { outline: none; border-color: var(--secondary); }
        button { width: 100%; padding: 16px; border: none; border-radius: 12px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; font-weight: 700; font-size: 1.1rem; cursor: pointer; transition: all 0.3s; margin-top: 20px; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4); }
        button:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(168, 85, 247, 0.5); }
        button:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .options-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px; margin-top: 20px; }
        .option-card { background: rgba(255,255,255,0.05); padding: 20px; border-radius: 16px; cursor: pointer; border: 1px solid transparent; transition: all 0.3s; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 120px; }
        .option-card:hover { border-color: var(--accent); background: rgba(236, 72, 153, 0.1); transform: translateY(-3px); }
        .option-card strong { display: block; margin-bottom: 8px; color: var(--light); }
        .option-card small { color: #94a3b8; font-size: 0.9em; }
        .loader-container { text-align: center; padding: 40px 20px; }
        .spinner { width: 60px; height: 60px; border: 4px solid rgba(255,255,255,0.1); border-top: 4px solid var(--accent); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 25px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .log-terminal { background: #020617; color: #4ade80; font-family: 'Fira Code', monospace; padding: 20px; border-radius: 12px; height: 200px; overflow-y: auto; text-align: left; font-size: 0.85rem; margin-bottom: 20px; border: 1px solid #1e293b; box-shadow: inset 0 0 20px rgba(0,0,0,0.5); }
        .log-line { margin: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .log-error { color: #f87171; }
        .log-warn { color: #fbbf24; }
        .log-success { color: #4ade80; font-weight: bold; }
        .debug-panel { margin-top: 40px; padding: 20px; border: 1px dashed var(--border); border-radius: 16px; background: rgba(0,0,0,0.2); }
        .debug-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .badge { background: var(--primary); padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; }
        .success-box { text-align: center; background: rgba(74, 222, 128, 0.1); border: 1px solid #4ade80; padding: 30px; border-radius: 16px; margin-top: 20px; }
        .success-box h2 { color: #4ade80; }
        .btn-launch { background: linear-gradient(135deg, #4ade80, #22c55e); color: #020617; text-decoration: none; display: inline-block; margin-top: 15px; box-shadow: 0 4px 15px rgba(74, 222, 128, 0.4); padding: 15px 30px; border-radius: 12px; font-weight:bold; font-size:1.1rem; }
        .btn-launch:hover { transform: scale(1.05); }
        .file-tree { text-align: left; background: rgba(0,0,0,0.3); padding: 15px; border-radius: 8px; margin-top: 20px; font-family: monospace; font-size: 0.9rem; color: #cbd5e1; }
    </style>
</head>
<body>

<div class="container">
    
    <!-- ÉTAPE 1 : Formulaire & Debug -->
    <div id="step1" class="step active">
        <h1>🚀 Architecte d'Applications IA</h1>
        <p class="subtitle">Décrivez votre idée. Nous générons le concept, l'architecture et le code source complet.</p>
        
        <form id="projectForm">
            <label for="userDemande">Quel est votre projet ?</label>
            <textarea id="userDemande" rows="5" placeholder="Ex: Une plateforme sociale de trading collaboratif avec IA prédictive intégrée..." required></textarea>
            <button type="submit">Lancer la génération ✨</button>
        </form>

        <?php if ($DEBUG_MODE): ?>
        <div class="debug-panel">
            <div class="debug-header">
                <h3>🛠️ Mode Debug : Gestion Clés API (Persistant)</h3>
                <span class="badge">SQLite Active</span>
            </div>
            <p style="font-size:0.9rem; opacity:0.8;">Ajoutez plusieurs clés Mistral. Elles sont sauvegardées définitivement dans <code>APIKEYMISTRAL.sqlite</code>.</p>
            <div style="display:flex; gap:10px;">
                <input type="text" id="apiKeyInput" placeholder="sk-..." style="margin:0;">
                <button onclick="testAndSaveKey()" style="width:auto; margin-top:0; padding: 0 25px;">Tester & Sauver</button>
            </div>
            <div id="debugStatus" style="margin-top:10px; font-style:italic; font-size:0.9rem;"></div>
            
            <div style="margin-top:15px; font-size:0.8rem; color:#94a3b8; display:flex; justify-content:space-between;">
                <span>Clés enregistrées en base : <strong id="keyCount" style="color:white; font-size:1.1rem;">0</strong></span>
                <button onclick="loadKeys()" style="width:auto; padding:5px 10px; font-size:0.7rem; margin:0; background:#333;">Rafraîchir</button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ÉTAPE 2 : Choix des Concepts -->
    <div id="step2" class="step">
        <div id="loader2" class="loader-container">
            <div class="spinner"></div>
            <h2>Analyse du marché & Concepts</h2>
            <div class="log-terminal" id="log2"></div>
        </div>
        
        <div id="optionsResult" style="display:none;">
            <h2>💡 10 Concepts Innovants</h2>
            <p style="text-align:center; opacity:0.8;">Sélectionnez la direction stratégique :</p>
            <div class="options-grid" id="optionsGrid"></div>
        </div>
    </div>

    <!-- ÉTAPE 3 : Architecture Détaillée -->
    <div id="step3" class="step">
        <div id="loader3" class="loader-container">
            <div class="spinner"></div>
            <h2>Ingénierie Système & Code</h2>
            <div class="log-terminal" id="log3"></div>
        </div>
    </div>

    <!-- ÉTAPE 4 : Déploiement & Succès -->
    <div id="step4" class="step">
        <div id="loader4" class="loader-container">
            <div class="spinner"></div>
            <h2>Déploiement des fichiers</h2>
            <div class="log-terminal" id="log4"></div>
        </div>

        <div id="finalSuccess" style="display:none;">
            <div class="success-box">
                <h2>✅ Application Créée avec Succès !</h2>
                <p>Votre application a été générée, compilée et déposée sur le serveur.</p>
                <div class="file-tree" id="fileTree"></div>
                <a href="#" id="launchLink" class="btn-launch">🚀 Lancer mon Application</a>
                <br><br>
                <button onclick="location.reload()" style="background: transparent; border: 1px solid var(--border); width:auto; padding: 10px 20px; font-size:0.9rem;">Créer un autre projet</button>
            </div>
        </div>
    </div>

</div>

<script>
    let userDemande = "";
    let selectedOption = "";
    let appTitle = "";
    const DEBUG_MODE = <?php echo $DEBUG_MODE ? 'true' : 'false'; ?>;

    const log = (elementId, message, type = 'info') => {
        const el = document.getElementById(elementId);
        const time = new Date().toLocaleTimeString();
        const className = type === 'error' ? 'log-error' : (type === 'warn' ? 'log-warn' : (type === 'success' ? 'log-success' : ''));
        el.innerHTML += `<div class="log-line ${className}">[${time}] ${message}</div>`;
        el.scrollTop = el.scrollHeight;
    };

    const showStep = (stepId) => {
        document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
        document.getElementById(stepId).classList.add('active');
    };

    const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    // --- GESTION MODE DEBUG (API KEYS) ---
    async function loadKeys() {
        if(!DEBUG_MODE) return;
        try {
            const formData = new FormData();
            formData.append('action', 'get_keys');
            const res = await fetch('index.php', { method: 'POST', body: formData });
            const data = await res.json();
            document.getElementById('keyCount').innerText = data.count || 0;
            if(data.count > 0) {
                document.getElementById('debugStatus').innerHTML = `<span class="log-success">✅ ${data.count} clés prêtes à l'emploi.</span>`;
            }
        } catch(e) { console.error(e); }
    }
    
    // Chargement initial des clés au démarrage de la page
    window.addEventListener('DOMContentLoaded', loadKeys);

    async function testAndSaveKey() {
        const key = document.getElementById('apiKeyInput').value.trim();
        const statusDiv = document.getElementById('debugStatus');
        if(!key) return alert("Veuillez entrer une clé");

        statusDiv.innerHTML = "<span style='color:#fbbf24'>Test en cours avec mistral-large...</span>";
        
        try {
            const formData = new FormData();
            formData.append('action', 'test_key');
            formData.append('key', key);

            const res = await fetch('index.php', { method: 'POST', body: formData });
            const data = await res.json();

            if(data.success) {
                statusDiv.innerHTML = "<span class='log-success'>✅ " + data.message + "</span>";
                document.getElementById('apiKeyInput').value = '';
                loadKeys(); // Rafraichir le compteur
            } else {
                statusDiv.innerHTML = "<span class='log-error'>❌ " + data.message + "</span>";
            }
        } catch (e) {
            statusDiv.innerHTML = "<span class='log-error'>❌ Erreur réseau</span>";
        }
    }

    async function getValidApiKeys() {
        if (!DEBUG_MODE) return []; 
        try {
            const formData = new FormData();
            formData.append('action', 'get_keys');
            const res = await fetch('index.php', { method: 'POST', body: formData });
            const data = await res.json();
            return data.keys || [];
        } catch (e) { console.error(e); return []; }
    }

    // --- MOTEUR D'APPEL API AVEC RETRY MULTI-CLES ---
    async function callMistralWithRetry(prompt, maxTokens = 2000, isJson = false) {
        let keys = await getValidApiKeys();
        if (keys.length === 0) throw new Error("Aucune clé API trouvée dans la base SQLite. Ajoutez-en une dans le panel Debug ci-dessus.");

        let lastError = null;
        let attemptedKeys = [];

        // Boucle de tentative sur chaque clé disponible
        for (let i = 0; i < keys.length; i++) {
            const key = keys[i];
            if (attemptedKeys.includes(key)) continue;
            
            attemptedKeys.push(key);
            log('log2', `Tentative avec clé ...${key.slice(-5)}...`, 'warn'); // Log temporaire pour debug

            try {
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
                const content = data.choices[0].message.content;

                // Mise à jour last_used
                const fd = new FormData(); fd.append('action', 'touch_key'); fd.append('key', key); 
                fetch('index.php', { method: 'POST', body: fd }); // Fire and forget
                
                if (isJson) {
                    const cleanJson = extractJson(content);
                    JSON.parse(cleanJson); // Validation syntaxe
                    return cleanJson;
                }
                return content;

            } catch (err) {
                lastError = err;
                console.warn(`Clé ${key.slice(-5)} échouée: ${err.message}`);
                log('log2', `Clé échouée (${err.message}), tentative suivante...`, 'error');
                continue; // Passe à la clé suivante
            }
        }

        throw new Error(`Échec total : Toutes les ${keys.length} clés ont échoué. Dernière erreur: ${lastError ? lastError.message : 'Inconnue'}`);
    }

    function extractJson(text) {
        const start = text.indexOf('{');
        const end = text.lastIndexOf('}');
        if (start !== -1 && end !== -1) {
            return text.substring(start, end + 1);
        }
        return text;
    }

    // --- FLUX PRINCIPAL ---

    document.getElementById('projectForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        userDemande = document.getElementById('userDemande').value;
        if(!userDemande) return;

        showStep('step2');
        const logEl = 'log2';
        
        log(logEl, "Initialisation du cerveau IA...");
        await sleep(800);
        
        try {
            const keys = await getValidApiKeys();
            log(logEl, `${keys.length} clés API chargées depuis SQLite.`, 'success');
            
            if(keys.length === 0) {
                throw new Error("Aucune clé API trouvée dans la base SQLite. Ajoutez-en en mode Debug.");
            }

            log(logEl, "Analyse de la demande et recherche de concepts viraux...");
            await sleep(1000);

            const prompt = `
            Tu es un expert produit viral. Demande : "${userDemande}".
            Propose 10 idées d'applications concrètes, innovantes, stack technique moderne, fort potentiel viral.
            
            RÉPOND UNIQUEMENT EN JSON PUR (pas de markdown, pas de texte autour) :
            {
                "options": [
                    {"title": "Nom court accrocheur", "desc": "Description en 1 phrase"},
                    ... 10 items
                ]
            }
            `;

            const rawData = await callMistralWithRetry(prompt, 1500, true);
            const data = JSON.parse(rawData);

            log(logEl, "Concepts générés avec succès !", 'success');
            await sleep(1000);

            document.getElementById('loader2').style.display = 'none';
            const grid = document.getElementById('optionsGrid');
            grid.innerHTML = '';

            data.options.forEach((opt, index) => {
                const card = document.createElement('div');
                card.className = 'option-card';
                card.innerHTML = `<strong>${index + 1}. ${opt.title}</strong><small>${opt.desc}</small>`;
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
        appTitle = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
        if(appTitle.length < 3) appTitle = "mon-projet-ia";
        
        showStep('step3');
        generateFullProject();
    }

    async function generateFullProject() {
        const logEl = 'log3';
        
        log(logEl, "Lancement de l'architecte logiciel IA...");
        await sleep(1000);
        log(logEl, `Conception de l'architecture pour : ${selectedOption}`);
        await sleep(1000);
        log(logEl, "Rédaction du code source (HTML/CSS/JS/PHP/SQL)...");
        await sleep(1500);

        const prompt = `
        Contexte : Projet "${userDemande}". Option choisie : "${selectedOption}".
        
        TÂCHE : Tu es un développeur Senior Fullstack. Tu dois générer le CODE SOURCE COMPLET d'une application web fonctionnelle.
        L'application doit être contenue dans un seul fichier index.html (avec CSS/JS inclus) OU une structure simple.
        
        IMPORTANT : Tu dois répondre UNIQUEMENT avec un objet JSON valide. Pas de texte avant/après.
        Structure du JSON :
        {
            "files": {
                "index.html": "<code html complet ici>",
                "style.css": "<code css>",
                "script.js": "<code js>",
                "backend.php": "<code php si besoin, sinon vide>",
                "schema.sql": "<structure sqlite si besoin>"
            }
        }
        
        Le code doit être moderne, responsive et esthétique.
        `;

        try {
            const rawData = await callMistralWithRetry(prompt, 4000, true);
            const data = JSON.parse(rawData);
            
            log(logEl, "Code source généré et validé.", 'success');
            log(logEl, "Début du déploiement sur le serveur...", 'warn');
            
            document.getElementById('loader3').style.display = 'none';
            showStep('step4');
            
            await deployApplication(data.files);

        } catch (err) {
            log(logEl, `ERREUR GÉNÉNÉRATION CODE: ${err.message}`, 'error');
        }
    }

    async function deployApplication(files) {
        const logEl = 'log4';
        log(logEl, "Création de l'arborescence de fichiers...");
        await sleep(800);

        try {
            const formData = new FormData();
            formData.append('action', 'create_app_files');
            formData.append('appName', appTitle);
            formData.append('files', JSON.stringify(files));

            const res = await fetch('index.php', { method: 'POST', body: formData });
            const result = await res.json();

            if (result.success) {
                log(logEl, "Fichiers écrits avec succès.", 'success');
                await sleep(1000);
                log(logEl, "Configuration finale...", 'success');
                await sleep(800);

                document.getElementById('loader4').style.display = 'none';
                document.getElementById('finalSuccess').style.display = 'block';

                const treeDiv = document.getElementById('fileTree');
                let html = '<strong>Fichiers créés :</strong><ul>';
                result.files.forEach(f => html += `<li>📄 ${f}</li>`);
                html += '</ul>';
                treeDiv.innerHTML = html;

                const link = document.getElementById('launchLink');
                link.href = result.url;
                
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
