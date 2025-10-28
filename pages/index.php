<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <title>CHO Koronadal - Loading...</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #2563eb, #1e40af);
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            overflow: hidden;
        }
        
        .loading-container {
            text-align: center;
            animation: fadeIn 0.5s ease-in-out;
        }
        
        .logo {
            width: 120px;
            height: 120px;
            margin-bottom: 30px;
            animation: pulse 2s infinite;
            filter: drop-shadow(0 10px 20px rgba(0,0,0,0.3));
        }
        
        .loading-text {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
        }
        
        .loading-subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 30px;
        }
        
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top: 3px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        .progress-bar {
            width: 200px;
            height: 4px;
            background: rgba(255,255,255,0.3);
            border-radius: 2px;
            margin: 20px auto;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: white;
            border-radius: 2px;
            width: 0%;
            animation: loadProgress 3s ease-out forwards;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes loadProgress {
            0% { width: 0%; }
            30% { width: 30%; }
            60% { width: 70%; }
            100% { width: 100%; }
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .logo {
                width: 100px;
                height: 100px;
                margin-bottom: 25px;
            }
            
            .loading-text {
                font-size: 20px;
            }
            
            .loading-subtitle {
                font-size: 14px;
            }
            
            .progress-bar {
                width: 150px;
            }
        }
    </style>
</head>
<body>
    <div class="loading-container">
        <img src="../assets/images/Nav_Logo.png" alt="CHO Koronadal Logo" class="logo" 
             onerror="this.src='https://ik.imagekit.io/wbhsmslogo/Nav_Logo.png?updatedAt=1750422462527'">
        
        <div class="loading-text">City Health Office</div>
        <div class="loading-subtitle">of Koronadal</div>
        
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
        
        <div class="loading-spinner"></div>
    </div>

    <script>
        // Dynamic path resolution for production compatibility
        function getRedirectUrl() {
            const protocol = window.location.protocol;
            const host = window.location.host;
            const pathname = window.location.pathname;
            
            // Extract base path - remove '/pages/' from the end
            let basePath = pathname.replace(/\/pages\/?.*$/, '/');
            
            // Ensure basePath ends with /
            if (!basePath.endsWith('/')) {
                basePath += '/';
            }
            
            return protocol + '//' + host + basePath;
        }
        
        // Redirect after 3 seconds with smooth transition
        setTimeout(function() {
            const redirectUrl = getRedirectUrl();
            
            // Add fade out animation before redirect
            document.body.style.transition = 'opacity 0.5s ease-out';
            document.body.style.opacity = '0';
            
            // Redirect after fade out
            setTimeout(function() {
                window.location.href = redirectUrl;
            }, 500);
        }, 3000);
        
        // Preload the target page for faster loading
        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = getRedirectUrl();
        document.head.appendChild(link);
        
        // Add keyboard support (press Enter to skip)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                window.location.href = getRedirectUrl();
            }
        });
        
        // Add click to skip
        document.addEventListener('click', function() {
            window.location.href = getRedirectUrl();
        });
    </script>
</body>
</html>