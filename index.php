<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Créez votre application avec l'IA</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 50px;
            max-width: 600px;
            width: 100%;
            text-align: center;
        }

        h1 {
            color: #333;
            margin-bottom: 15px;
            font-size: 2.5em;
            font-weight: 700;
        }

        .subtitle {
            color: #666;
            margin-bottom: 40px;
            font-size: 1.1em;
            line-height: 1.6;
        }

        .icon {
            font-size: 4em;
            margin-bottom: 20px;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        textarea {
            width: 100%;
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1em;
            font-family: inherit;
            resize: vertical;
            min-height: 150px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        textarea::placeholder {
            color: #aaa;
        }

        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 18px 40px;
            font-size: 1.1em;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }

        button:active {
            transform: translateY(0);
        }

        .features {
            margin-top: 40px;
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 20px;
        }

        .feature {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #666;
            font-size: 0.9em;
        }

        .feature-icon {
            font-size: 1.5em;
        }

        @media (max-width: 600px) {
            .container {
                padding: 30px 20px;
            }

            h1 {
                font-size: 2em;
            }

            .features {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🚀</div>
        <h1>Créez votre application</h1>
        <p class="subtitle">Décrivez votre projet en quelques mots et laissez l'IA le construire pour vous. Simple, rapide et puissant.</p>
        
        <form action="generate.php" method="POST">
            <textarea 
                name="project_description" 
                placeholder="Décrivez votre projet ici...&#10;&#10;Exemple: Je veux créer une application de gestion de tâches avec un tableau de bord, des catégories, et des rappels par email."
                required
            ></textarea>
            
            <button type="submit">
                ✨ Générer mon application
            </button>
        </form>

        <div class="features">
            <div class="feature">
                <span class="feature-icon">⚡</span>
                <span>Rapide</span>
            </div>
            <div class="feature">
                <span class="feature-icon">🤖</span>
                <span>Intelligent</span>
            </div>
            <div class="feature">
                <span class="feature-icon">🎨</span>
                <span>Personnalisable</span>
            </div>
        </div>
    </div>
</body>
</html>
